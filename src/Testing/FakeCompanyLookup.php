<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Testing;

use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\CompanyLookup;
use IntrAgile\CompanyLookup\Drivers\Support\Normalize;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupOutcome;
use IntrAgile\CompanyLookup\LookupResult;
use PHPUnit\Framework\Assert;

/**
 * A lookup that answers from a fixed map and records what was asked.
 *
 * Keys: "{COUNTRY}:{identifier}" for lookups, "search:{COUNTRY}:{name}" for searches,
 * and "*" as a fallback. Identifiers and names are compared in normalised form.
 */
class FakeCompanyLookup extends CompanyLookup
{
    /** @var array<string, Company|LookupResult|list<Company>|null> */
    private array $responses = [];

    /** @var list<array{operation: string, country: string, subject: string}> */
    private array $recorded = [];

    /**
     * @param  array<string, Company|LookupResult|list<Company>|null>  $responses
     */
    public function __construct(array $responses = [])
    {
        parent::__construct();

        foreach ($responses as $key => $response) {
            $this->responses[$this->normaliseKey($key)] = $response;
        }
    }

    public function lookup(string $country, string|Identifier $identifier, ?IdentifierType $type = null): LookupOutcome
    {
        $country = strtoupper(trim($country));
        $value = Normalize::withoutPrefix($country, is_string($identifier) ? $identifier : $identifier->value);
        $this->recorded[] = ['operation' => 'lookup', 'country' => $country, 'subject' => $value];

        return $this->answer($country.':'.$value);
    }

    public function vat(string $vatNumber): LookupOutcome
    {
        $compact = Normalize::compact($vatNumber);
        $prefix = str_starts_with($compact, 'CHE') ? 'CHE' : substr($compact, 0, 2);

        return $this->lookup(Normalize::countryFromVatPrefix($prefix), $compact, IdentifierType::Vat);
    }

    public function search(string $country, string $name, ?int $limit = null): LookupOutcome
    {
        $country = strtoupper(trim($country));
        $normalised = Normalize::searchKey($name);
        $this->recorded[] = ['operation' => 'search', 'country' => $country, 'subject' => $normalised];

        return $this->answer('search:'.$country.':'.$normalised);
    }

    public function using(string ...$keys): static
    {
        return $this;
    }

    public function all(): static
    {
        return $this;
    }

    public function assertLookedUp(string $country, string $identifier): void
    {
        $country = strtoupper($country);
        $value = Normalize::withoutPrefix($country, $identifier);

        Assert::assertTrue(
            $this->wasAsked('lookup', $country, $value),
            "Expected a company lookup for [{$country}:{$value}], but none was made.",
        );
    }

    public function assertSearched(string $country, string $name): void
    {
        $country = strtoupper($country);

        Assert::assertTrue(
            $this->wasAsked('search', $country, Normalize::searchKey($name)),
            "Expected a company search for [{$name}] in [{$country}], but none was made.",
        );
    }

    public function assertNothingLookedUp(): void
    {
        Assert::assertSame([], $this->recorded, 'Expected no company lookups, but some were made.');
    }

    /**
     * @return list<array{operation: string, country: string, subject: string}>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    private function wasAsked(string $operation, string $country, string $subject): bool
    {
        foreach ($this->recorded as $entry) {
            if ($entry === ['operation' => $operation, 'country' => $country, 'subject' => $subject]) {
                return true;
            }
        }

        return false;
    }

    private function answer(string $key): LookupOutcome
    {
        $response = array_key_exists($key, $this->responses) ? $this->responses[$key] : ($this->responses['*'] ?? null);

        $result = match (true) {
            $response instanceof LookupResult => $response,
            $response instanceof Company => LookupResult::found('fake', $response),
            is_array($response) => LookupResult::found('fake', ...$response),
            default => LookupResult::notFound('fake'),
        };

        return new LookupOutcome([$result]);
    }

    private function normaliseKey(string $key): string
    {
        if ($key === '*') {
            return $key;
        }

        if (str_starts_with(strtolower($key), 'search:')) {
            [, $country, $name] = array_pad(explode(':', $key, 3), 3, '');

            return 'search:'.strtoupper($country).':'.Normalize::searchKey($name);
        }

        [$country, $value] = array_pad(explode(':', $key, 2), 2, '');
        $country = strtoupper($country);

        return $country.':'.Normalize::withoutPrefix($country, $value);
    }
}
