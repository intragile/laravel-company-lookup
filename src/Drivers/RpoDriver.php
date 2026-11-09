<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use IntrAgile\CompanyLookup\Address;
use IntrAgile\CompanyLookup\Capabilities;
use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\Drivers\Support\Data;
use IntrAgile\CompanyLookup\Drivers\Support\Normalize;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupResult;

/**
 * Slovak Register of Legal Entities (RPO) of the Statistical Office. Free, no key, CC BY 4.0.
 *
 * The API throttles hard; HTTP 429 becomes a retryable RateLimited failure.
 * Only the search endpoint is used, so one lookup costs one request.
 */
final class RpoDriver extends AbstractHttpDriver
{
    public const string KEY = 'rpo';

    private const DEFAULT_BASE_URL = 'https://api.statistics.sk/rpo/v1';

    /** ISO 3166-1 numeric codes (codelist CL000086) of Slovakia and its usual neighbours. */
    private const COUNTRIES = ['703' => 'SK', '203' => 'CZ', '348' => 'HU', '616' => 'PL', '040' => 'AT', '804' => 'UA', '276' => 'DE'];

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'RPO (SK)',
            countries: ['SK'],
            identifierTypes: [IdentifierType::Registration],
            nameSearch: true,
            attribution: 'Register právnických osôb (RPO), Štatistický úrad Slovenskej republiky, licensed under CC BY 4.0',
            registryUrl: 'https://rpo.statistics.sk/',
            sample: '35763469',
            sampleCountry: 'SK',
        );
    }

    /**
     * The quota is strict, so an immediate retry after HTTP 429 would only be refused again.
     */
    protected function retriesOnRateLimit(): bool
    {
        return false;
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $digits = Normalize::digits(Normalize::withoutPrefix('SK', $identifier->value));

        if (strtoupper($country) !== 'SK' || $identifier->type !== IdentifierType::Registration || $digits === '' || strlen($digits) > 8) {
            return LookupResult::notFound(self::KEY);
        }

        $ico = str_pad($digits, 8, '0', STR_PAD_LEFT);

        return $this->find(['identifier' => $ico], fn (array $entity): bool => in_array($ico, $this->icos($entity), true));
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        $name = trim($name);

        if (strtoupper($country) !== 'SK' || $name === '' || $limit < 1) {
            return LookupResult::notFound(self::KEY);
        }

        return $this->find(['fullName' => $name], null, $limit);
    }

    /**
     * @param  array<string, string>  $query
     * @param  (callable(array<array-key, mixed>): bool)|null  $filter
     */
    private function find(array $query, ?callable $filter, ?int $limit = null): LookupResult
    {
        return $this->guard(function () use ($query, $filter, $limit): LookupResult {
            $response = $this->request()
                ->acceptJson()
                ->get($this->baseUrl(self::DEFAULT_BASE_URL).'/search', $query);

            if (! $response->successful()) {
                return $this->failure($response);
            }

            $entities = Data::list($this->json($response), 'results');
            if ($filter !== null) {
                $entities = array_values(array_filter($entities, $filter));
            }
            if ($limit !== null) {
                $entities = array_slice($entities, 0, $limit);
            }

            return LookupResult::found(self::KEY, ...array_map(fn (array $entity): Company => $this->company($entity), $entities));
        });
    }

    /**
     * @param  array<array-key, mixed>  $entity
     * @return list<string>
     */
    private function icos(array $entity): array
    {
        $values = array_map(fn (array $item): ?string => Data::string($item, 'value'), Data::list($entity, 'identifiers'));

        return array_values(array_filter($values, fn (?string $value): bool => $value !== null));
    }

    /**
     * @param  array<array-key, mixed>  $entity
     */
    private function company(array $entity): Company
    {
        $current = $this->current(Data::list($entity, 'identifiers'));
        $ico = $current === null ? null : Data::string($current, 'value');
        $name = $this->current(Data::list($entity, 'fullNames'));

        return new Company(
            name: $name === null ? null : Data::string($name, 'value'),
            country: 'SK',
            source: self::KEY,
            identifiers: $ico === null ? [] : [Identifier::registration($ico, 'SK-ICO')],
            address: $this->address($this->current(Data::list($entity, 'addresses'))),
            status: Data::string($entity, 'termination') === null ? CompanyStatus::Active : CompanyStatus::Dissolved,
            foundedAt: Data::date(Data::string($entity, 'establishment')),
            raw: $entity,
        );
    }

    /**
     * The entry still valid today, or else the most recent one.
     *
     * @param  list<array<array-key, mixed>>  $entries  Historised entries with validFrom / validTo.
     * @return array<array-key, mixed>|null
     */
    private function current(array $entries): ?array
    {
        $open = array_values(array_filter($entries, fn (array $entry): bool => Data::string($entry, 'validTo') === null));
        $candidates = $open === [] ? $entries : $open;

        usort($candidates, fn (array $a, array $b): int => strcmp(Data::string($b, 'validFrom') ?? '', Data::string($a, 'validFrom') ?? ''));

        return $candidates[0] ?? null;
    }

    /**
     * @param  array<array-key, mixed>|null  $entry
     */
    private function address(?array $entry): ?Address
    {
        if ($entry === null) {
            return null;
        }

        // regNumber is the land-registry number (súpisné číslo); 0 means not given.
        $registryNumber = Data::int($entry, 'regNumber');
        $building = Data::string($entry, 'buildingNumber');
        $number = $registryNumber !== null && $registryNumber > 0
            ? $registryNumber.($building === null ? '' : '/'.$building)
            : $building;

        $street = trim(implode(' ', array_filter([Data::string($entry, 'street'), $number], fn (?string $part): bool => $part !== null && $part !== '')));
        $postalCodes = Data::map($entry, 'postalCodes');

        $address = new Address(
            street: $street === '' ? null : $street,
            postalCode: Data::clean($postalCodes[0] ?? null),
            city: Data::string($entry, 'municipality.value'),
            country: self::COUNTRIES[Data::string($entry, 'country.code') ?? ''] ?? null,
        );

        return $address->format() === null ? null : $address;
    }
}
