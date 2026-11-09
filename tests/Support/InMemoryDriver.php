<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Support;

use Closure;
use IntrAgile\CompanyLookup\Capabilities;
use IntrAgile\CompanyLookup\Contracts\Driver;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupResult;

/**
 * A scriptable driver for manager tests.
 */
final class InMemoryDriver implements Driver
{
    public int $calls = 0;

    /**
     * @param  list<string>  $countries
     * @param  list<IdentifierType>  $types
     * @param  Closure(string, Identifier|string): LookupResult  $answer
     * @param  array<string, string>  $credentials
     */
    public function __construct(
        private string $key,
        private array $countries,
        private Closure $answer,
        private array $types = [IdentifierType::Vat, IdentifierType::Registration],
        private bool $nameSearch = false,
        private array $credentials = [],
        private bool $configured = true,
    ) {}

    public function capabilities(): Capabilities
    {
        return new Capabilities($this->key, ucfirst($this->key), $this->countries, $this->types, $this->nameSearch, $this->credentials, sample: '123');
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $this->calls++;

        return ($this->answer)($country, $identifier);
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        $this->calls++;

        return ($this->answer)($country, $name);
    }
}
