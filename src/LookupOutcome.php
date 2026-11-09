<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup;

/**
 * The combined answer of every source that was asked.
 */
final readonly class LookupOutcome
{
    /**
     * @param  list<LookupResult>  $results
     * @param  string|null  $registryUrl  A web page to search manually, set when no source covers the country.
     */
    public function __construct(
        public array $results,
        public ?string $registryUrl = null,
    ) {}

    /**
     * @return list<Company>
     */
    public function companies(): array
    {
        $companies = [];
        foreach ($this->results as $result) {
            array_push($companies, ...$result->companies);
        }

        return $companies;
    }

    public function first(): ?Company
    {
        return $this->companies()[0] ?? null;
    }

    public function found(): bool
    {
        return $this->first() !== null;
    }

    /**
     * The first company, with missing fields filled from the other found records of the same country.
     */
    public function merged(): ?Company
    {
        $companies = $this->companies();
        $merged = array_shift($companies);

        if ($merged === null) {
            return null;
        }

        foreach ($companies as $company) {
            if ($company->country === $merged->country) {
                $merged = $merged->fillFrom($company);
            }
        }

        return $merged;
    }

    /**
     * @return list<LookupResult>
     */
    public function failures(): array
    {
        return array_values(array_filter($this->results, fn (LookupResult $result): bool => $result->isFailed()));
    }

    /**
     * @return list<string>
     */
    public function failedSources(): array
    {
        return array_map(fn (LookupResult $result): string => $result->source, $this->failures());
    }

    /**
     * @return list<string>
     */
    public function unconfiguredSources(): array
    {
        return array_values(array_map(
            fn (LookupResult $result): string => $result->source,
            array_filter($this->results, fn (LookupResult $result): bool => $result->isUnconfigured()),
        ));
    }

    /**
     * True when every source gave a definitive answer.
     */
    public function isComplete(): bool
    {
        foreach ($this->results as $result) {
            if (! $result->isCacheable()) {
                return false;
            }
        }

        return true;
    }
}
