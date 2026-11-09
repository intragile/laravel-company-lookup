<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Laravel\Console;

use Illuminate\Console\Command;
use IntrAgile\CompanyLookup\Bulk\BulkDriver;
use IntrAgile\CompanyLookup\Bulk\Importer;
use IntrAgile\CompanyLookup\CompanyLookup;
use IntrAgile\CompanyLookup\Drivers\AbstractHttpDriver;
use Throwable;

final class ImportCommand extends Command
{
    protected $signature = 'company-lookup:import
        {dataset?* : Dataset keys (lv-ur, ee-rik, be-kbo); all enabled datasets when omitted}
        {--force : Also import disabled datasets, and delete unseen rows even when the file has far fewer rows than stored}';

    protected $description = 'Download the bulk company registers (Latvia, Estonia, Belgium) into the local table';

    public function handle(CompanyLookup $lookup, Importer $importer): int
    {
        if (! filter_var(config('company-lookup.bulk.enabled', false), FILTER_VALIDATE_BOOL)) {
            $this->error('Bulk datasets are disabled. Set COMPANY_LOOKUP_BULK_ENABLED=true, run `php artisan migrate`, then import again.');

            return self::FAILURE;
        }

        $available = [];
        foreach ($lookup->drivers() as $key => $driver) {
            if ($driver instanceof BulkDriver) {
                $available[$key] = $driver;
            }
        }

        $requested = array_values(array_filter((array) $this->argument('dataset'), 'is_string'));
        $force = (bool) $this->option('force');

        if (($unknown = array_diff($requested, array_keys($available))) !== []) {
            $this->error('Unknown dataset: '.implode(', ', $unknown).'. Available: '.implode(', ', array_keys($available)).'.');

            return self::FAILURE;
        }

        $explicit = $requested !== [];
        $rows = [];
        $failed = 0;

        foreach ($explicit ? $requested : array_keys($available) as $key) {
            $dataset = $available[$key]->dataset();

            if (! $force && ! $lookup->isEnabled($key)) {
                if ($explicit) {
                    $this->warn("Skipping {$key}: the driver is disabled (use --force to import anyway).");
                }

                continue;
            }

            if (($missing = $importer->missingCredentials($dataset)) !== []) {
                $this->warn("Skipping {$key}: set ".implode(', ', $missing).'.');
                $failed += $explicit ? 1 : 0;

                continue;
            }

            $this->line("Importing {$key} from {$dataset->sourceUrl()} ...");

            try {
                $report = $importer->import($dataset, $force);
            } catch (Throwable $exception) {
                $this->error($key.': '.AbstractHttpDriver::redact($exception->getMessage()));
                $failed++;

                continue;
            }

            if ($report->refused !== null) {
                $this->warn("{$key}: refused to delete unseen rows. {$report->refused}");
                $failed++;
            }

            $rows[] = [$report->dataset, number_format($report->imported), number_format($report->deleted), number_format($report->durationSeconds, 1).' s', $report->refused === null ? 'ok' : 'refused'];
        }

        if ($rows !== []) {
            $this->table(['Dataset', 'Imported', 'Deleted', 'Duration', 'Result'], $rows);
        } elseif ($failed === 0) {
            $this->warn('Nothing to import.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
