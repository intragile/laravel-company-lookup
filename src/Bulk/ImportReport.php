<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Bulk;

/**
 * The outcome of one dataset import.
 */
final readonly class ImportReport
{
    /**
     * @param  int  $imported  Rows inserted or updated in this run.
     * @param  int  $deleted  Rows of the dataset removed because the source no longer lists them.
     * @param  string|null  $refused  Why unseen rows were not deleted (the file looked truncated); null when the import completed.
     */
    public function __construct(
        public string $dataset,
        public int $imported,
        public int $deleted,
        public float $durationSeconds,
        public ?string $refused = null,
    ) {}

    public function wasRefused(): bool
    {
        return $this->refused !== null;
    }
}
