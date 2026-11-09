<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Bulk;

use DateTimeImmutable;
use FilesystemIterator;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use IntrAgile\CompanyLookup\Contracts\BulkDataset;
use IntrAgile\CompanyLookup\Drivers\AbstractHttpDriver;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

/**
 * Downloads a bulk dataset and replaces its rows in the local records table.
 *
 * The file is streamed to a temporary directory, unpacked when it is a zip archive, and its rows are
 * upserted in small committed chunks, each row stamped with this run's id (imported_at, microseconds).
 * Only after every row is stored does one short transaction delete the dataset's rows with an older
 * stamp, so a run that fails midway never deletes anything. A run that yields far fewer rows than are
 * stored (a truncated download) is refused before that delete unless forced.
 */
final class Importer
{
    public const CHUNK = 1000;

    public const DEFAULT_MIN_RATIO = 0.9;

    private const COLUMNS = ['country', 'name', 'normalized_name', 'address', 'status', 'legal_form', 'founded_at', 'payload', 'imported_at'];

    /**
     * @param  array<string, mixed>  $config  The company-lookup config array.
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ConnectionResolverInterface $db,
        private readonly array $config = [],
    ) {}

    /**
     * Environment variable names of the dataset credentials that are not configured.
     *
     * @return list<string>
     */
    public function missingCredentials(BulkDataset $dataset): array
    {
        $missing = [];
        foreach ($dataset->credentials() as $field => $env) {
            $value = data_get($this->config, 'drivers.'.$dataset->key().'.'.$field);
            if (! is_scalar($value) || trim((string) $value) === '') {
                $missing[] = $env;
            }
        }

        return $missing;
    }

    /**
     * @param  bool  $force  Delete unseen rows even when the run yielded fewer rows than bulk.min_ratio of the stored ones.
     *
     * @throws RuntimeException When the table is missing, credentials are absent, or the download or file is unusable.
     */
    public function import(BulkDataset $dataset, bool $force = false): ImportReport
    {
        $started = hrtime(true);

        if (($missing = $this->missingCredentials($dataset)) !== []) {
            throw new RuntimeException("Dataset [{$dataset->key()}] needs ".implode(', ', $missing).'.');
        }

        $connection = $this->connection();
        $table = $this->table();
        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            throw new RuntimeException("The [{$table}] table does not exist. Run `php artisan migrate` first.");
        }

        $workDir = $this->makeWorkDir();

        try {
            $download = $workDir.'/download';
            $this->download($dataset, $download);
            [$imported, $deleted, $refused] = $this->store($connection, $table, $dataset, $this->unpack($dataset, $download, $workDir.'/files'), $force);
        } finally {
            $this->remove($workDir);
        }

        return new ImportReport($dataset->key(), $imported, $deleted, round((hrtime(true) - $started) / 1e9, 2), $refused);
    }

    private function download(BulkDataset $dataset, string $target): void
    {
        $request = $this->request();

        if ($dataset instanceof DownloadsSource) {
            $dataset->download($request, $target);
        } else {
            $response = $request->sink($target)->get($dataset->sourceUrl());
            if (! $response->successful()) {
                throw new RuntimeException("Download of [{$dataset->key()}] failed with HTTP {$response->status()}.");
            }
        }

        if (! is_file($target) || filesize($target) === 0) {
            throw new RuntimeException("Download of [{$dataset->key()}] produced an empty file.");
        }
    }

    private function request(): PendingRequest
    {
        $userAgent = data_get($this->config, 'user_agent');
        $timeout = data_get($this->config, 'bulk.timeout');

        return $this->http
            ->withUserAgent(is_string($userAgent) && $userAgent !== '' ? $userAgent : AbstractHttpDriver::DEFAULT_USER_AGENT)
            ->connectTimeout(30)
            ->timeout(is_numeric($timeout) ? (int) $timeout : 1800);
    }

    /**
     * The file to hand to the dataset: the download itself, or what the zip archive holds.
     */
    private function unpack(BulkDataset $dataset, string $download, string $directory): string
    {
        $handle = fopen($download, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, 4);
        if ($handle !== false) {
            fclose($handle);
        }

        if ($magic !== "PK\x03\x04") {
            return $download;
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException("Dataset [{$dataset->key()}] is a zip archive; install the PHP zip extension (ext-zip) to import it.");
        }

        $zip = new ZipArchive;
        if ($zip->open($download) !== true) {
            throw new RuntimeException("Dataset [{$dataset->key()}] is not a readable zip archive.");
        }

        $wanted = $dataset instanceof SelectsArchiveMembers ? array_map('strtolower', $dataset->archiveMembers()) : null;
        $extracted = [];
        mkdir($directory, 0700, true);

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                // Only the base name is used, so archive paths can never point outside the directory.
                $base = basename(str_replace('\\', '/', $name));

                if ($base === '' || str_ends_with($name, '/') || ($wanted !== null && ! in_array(strtolower($base), $wanted, true))) {
                    continue;
                }

                $source = $zip->getStream($name);
                $target = fopen($directory.'/'.$base, 'wb');
                if ($source === false || $target === false) {
                    throw new RuntimeException("Cannot extract [{$name}] from the [{$dataset->key()}] archive.");
                }

                $copied = stream_copy_to_stream($source, $target);
                fclose($source);
                fclose($target);

                $stat = $zip->statIndex($index);
                if ($copied === false || ($stat !== false && $copied !== $stat['size'])) {
                    throw new RuntimeException("Extracting [{$name}] from the [{$dataset->key()}] archive was incomplete; the archive is probably truncated.");
                }
                $extracted[] = $directory.'/'.$base;
            }
        } finally {
            $zip->close();
        }

        unlink($download);

        return match (count($extracted)) {
            0 => throw new RuntimeException("The [{$dataset->key()}] archive holds none of the expected files."),
            1 => $extracted[0],
            default => $directory,
        };
    }

    /**
     * @return array{int, int, string|null} Rows imported, rows deleted, and the refusal reason if the delete was skipped.
     */
    private function store(Connection $connection, string $table, BulkDataset $dataset, string $file, bool $force): array
    {
        $key = $dataset->key();
        $country = strtoupper($dataset->country());
        // The run id: microsecond precision tells this run's rows apart from older ones, even within one second.
        $stamp = (new DateTimeImmutable)->format('Y-m-d H:i:s.u');
        $existing = $connection->table($table)->where('dataset', $key)->count();
        $chunk = $this->chunkSize($connection);
        $imported = 0;
        $buffer = [];

        foreach ($dataset->rows($file) as $row) {
            // Keyed by identifier: a chunk must not upsert the same key twice.
            $buffer[$row['identifier']] = [
                'dataset' => $key,
                'country' => $country,
                'identifier' => $row['identifier'],
                'name' => $row['name'],
                'normalized_name' => $row['normalized_name'],
                'address' => $row['address'],
                'status' => $row['status'],
                'legal_form' => $row['legal_form'],
                'founded_at' => $row['founded_at'],
                'payload' => json_encode($row['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
                'imported_at' => $stamp,
            ];

            if (count($buffer) >= $chunk) {
                $imported += $this->flush($connection, $table, $buffer);
                $buffer = [];
            }
        }

        $imported += $this->flush($connection, $table, $buffer);

        if ($imported === 0) {
            throw new RuntimeException("The [{$key}] file contained no records; the stored data was kept.");
        }

        $minimum = (int) ceil($existing * $this->minRatio());
        if (! $force && $imported < $minimum) {
            return [$imported, 0, sprintf(
                'Only %d rows against %d stored (minimum %d); unseen rows were kept. Use --force if the source really shrank.',
                $imported, $existing, $minimum,
            )];
        }

        $deleted = $connection->transaction(
            fn (): int => $connection->table($table)->where('dataset', $key)->where('imported_at', '<>', $stamp)->delete()
        );

        return [$imported, $deleted, null];
    }

    /**
     * @param  array<string, array<string, string|null>>  $buffer
     */
    private function flush(Connection $connection, string $table, array $buffer): int
    {
        if ($buffer === []) {
            return 0;
        }

        $connection->transaction(fn () => $connection->table($table)->upsert(array_values($buffer), ['dataset', 'identifier'], self::COLUMNS));

        return count($buffer);
    }

    /**
     * Rows per upsert, kept under the driver's bound-parameter limit (SQL Server 2100, older SQLite 999).
     */
    private function chunkSize(Connection $connection): int
    {
        $columns = count(self::COLUMNS) + 2;

        return match ($connection->getDriverName()) {
            'sqlsrv' => intdiv(2000, $columns),
            'sqlite' => intdiv(999, $columns),
            default => self::CHUNK,
        };
    }

    private function minRatio(): float
    {
        $ratio = data_get($this->config, 'bulk.min_ratio');

        return is_numeric($ratio) ? max(0.0, min(1.0, (float) $ratio)) : self::DEFAULT_MIN_RATIO;
    }

    private function connection(): Connection
    {
        $name = data_get($this->config, 'bulk.connection');
        $connection = $this->db->connection(is_string($name) && $name !== '' ? $name : null);

        if (! $connection instanceof Connection) {
            throw new RuntimeException('The bulk connection must be an Illuminate database connection.');
        }

        return $connection;
    }

    private function table(): string
    {
        $table = data_get($this->config, 'bulk.table');

        return is_string($table) && $table !== '' ? $table : 'company_lookup_records';
    }

    private function makeWorkDir(): string
    {
        $base = data_get($this->config, 'bulk.temp_path');
        $directory = rtrim(is_string($base) && $base !== '' ? $base : sys_get_temp_dir(), '/').'/company-lookup-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700, true)) {
            throw new RuntimeException("Cannot create the temporary directory [{$directory}].");
        }

        return $directory;
    }

    private function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry instanceof SplFileInfo) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
        }

        rmdir($directory);
    }
}
