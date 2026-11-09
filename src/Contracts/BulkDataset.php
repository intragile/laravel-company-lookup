<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Contracts;

use Generator;

/**
 * A register that is published only as a downloadable file.
 *
 * The importer downloads sourceUrl() (or lets the dataset download itself, see
 * Bulk\DownloadsSource), unpacks zip archives and stores every yielded row in
 * the local records table, where a Bulk\BulkDriver answers lookups from it.
 */
interface BulkDataset
{
    /**
     * Unique key, also used as the driver key (for example "lv-ur").
     */
    public function key(): string;

    /**
     * ISO 3166-1 alpha-2 code of the register's country.
     */
    public function country(): string;

    public function sourceUrl(): string;

    /**
     * Required credentials as config field => environment variable name. Empty when the file is public.
     *
     * @return array<string, string>
     */
    public function credentials(): array;

    /**
     * Streams the records of a downloaded file without loading it into memory.
     *
     * @param  string  $localFile  The downloaded file; for a zip archive the single extracted file,
     *                             or the extraction directory when the archive holds several files.
     * @return Generator<int, array{identifier: string, name: string, normalized_name: string, address: string|null, status: string, legal_form: string|null, founded_at: string|null, payload: array<string, mixed>}>
     */
    public function rows(string $localFile): Generator;
}
