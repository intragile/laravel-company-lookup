<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Bulk\Datasets;

use IntrAgile\CompanyLookup\Contracts\BulkDataset;
use IntrAgile\CompanyLookup\Drivers\Support\Normalize;
use IntrAgile\CompanyLookup\Enums\IdentifierType;

/**
 * Shared base of the built-in datasets: config access and the metadata the bulk driver shows.
 */
abstract class Dataset implements BulkDataset
{
    /**
     * @param  array<string, mixed>  $config  This dataset's driver config section (company-lookup.drivers.<key>).
     */
    public function __construct(protected array $config = []) {}

    public function credentials(): array
    {
        return [];
    }

    /**
     * Human-readable name of the register.
     */
    abstract public function label(): string;

    /**
     * Public web page of the register.
     */
    public function registryUrl(): ?string
    {
        return null;
    }

    /**
     * Licence attribution the data owner requires, if any.
     */
    public function attribution(): ?string
    {
        return null;
    }

    /**
     * A known identifier for the live self-test.
     */
    public function sample(): ?string
    {
        return null;
    }

    /**
     * Identifier types a lookup may use. The stored identifier is matched by its digits.
     *
     * @return list<IdentifierType>
     */
    public function identifierTypes(): array
    {
        return [IdentifierType::Registration];
    }

    /**
     * The stored form of an identifier given by a caller (digits only by default).
     */
    public function normalizeIdentifier(string $value): string
    {
        return Normalize::digits($value);
    }

    /**
     * A string config value, or null when unset or empty.
     */
    public function setting(string $field): ?string
    {
        $value = $this->config[$field] ?? null;

        return is_scalar($value) && ! is_bool($value) && trim((string) $value) !== '' ? (string) $value : null;
    }
}
