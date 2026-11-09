<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Contracts;

use IntrAgile\CompanyLookup\Capabilities;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupResult;

/**
 * One company data source.
 *
 * Rules:
 *  - Never throw for an outcome: return found, notFound, failed or unconfigured.
 *  - capabilities() must work without configuration.
 *  - Do not cache results; the manager does.
 *  - Normalise the identifier yourself (spaces, dashes, country prefixes).
 */
interface Driver
{
    public function capabilities(): Capabilities;

    public function isConfigured(): bool;

    public function lookup(string $country, Identifier $identifier): LookupResult;

    /**
     * Search by company name. Drivers without name search return notFound.
     */
    public function search(string $country, string $name, int $limit): LookupResult;
}
