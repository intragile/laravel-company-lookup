<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use DateTimeImmutable;
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
 * Corporations Canada Federal Corporation API (ISED). Free; needs an API key from the
 * ISED API Store. Covers federal corporations only, by corporation number or 9-digit
 * business number; the API has no name search.
 */
final class IsedDriver extends AbstractHttpDriver
{
    public const string KEY = 'ised';

    private const BASE_URL = 'https://apigateway-passerelledapi.ised-isde.canada.ca/corporations/api';

    /** Activities that mark the start of the corporation's existence. */
    private const FOUNDING_ACTIVITIES = ['incorporation', 'amalgamation', 'continuance (import)', 'continuance'];

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'Corporations Canada (federal corporations)',
            countries: ['CA'],
            identifierTypes: [IdentifierType::Registration, IdentifierType::Tax],
            nameSearch: false,
            credentials: ['key' => 'COMPANY_LOOKUP_ISED_KEY'],
            registryUrl: 'https://ised-isde.canada.ca/cc/lgcy/fdrlCrpSrch.html',
            sample: '4261607',
            sampleCountry: 'CA',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $digits = Normalize::digits(Normalize::withoutPrefix('CA', $identifier->value));
        $number = match ($identifier->type) {
            // Corporation numbers are up to 7 digits ("426160-7"); the API also takes a 9-digit business number.
            IdentifierType::Registration => ltrim($digits, '0'),
            // A 15-character program account ("123456789RC0001") starts with the 9-digit business number.
            IdentifierType::Tax => strlen($digits) >= 9 ? substr($digits, 0, 9) : '',
            default => '',
        };
        $valid = $number !== '' && strlen($number) <= 9;

        if (strtoupper($country) !== 'CA' || ! $valid) {
            return LookupResult::notFound(self::KEY);
        }

        $key = $this->setting('key');
        if ($key === null) {
            return LookupResult::unconfigured(self::KEY, 'COMPANY_LOOKUP_ISED_KEY');
        }

        return $this->guard(function () use ($key, $number): LookupResult {
            $response = $this->request()
                ->acceptJson()
                ->withHeaders(['user-key' => $key])
                ->get($this->baseUrl(self::BASE_URL).'/v1/corporations/'.$number.'.json', ['lang' => 'eng']);

            if (! $response->successful()) {
                return $this->failure($response, Data::clean($response->body()));
            }

            // An unknown number answers 200 with two message strings instead of objects; a 404
            // therefore means a wrong path and is reported as a failure above.
            $corporations = array_values(array_filter(
                Data::arrays($this->json($response)),
                fn (array $item): bool => Data::string($item, 'corporationId') !== null,
            ));

            return $corporations === []
                ? LookupResult::notFound(self::KEY)
                : LookupResult::found(self::KEY, $this->company($corporations[0]));
        });
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function company(array $data): Company
    {
        $identifiers = [Identifier::registration((string) Data::string($data, 'corporationId'), 'CA-CORP')];
        $businessNumber = Data::string($data, 'businessNumbers.businessNumber');
        if ($businessNumber !== null) {
            $identifiers[] = Identifier::tax($businessNumber, 'CA-BN');
        }

        return new Company(
            name: $this->name(Data::list($data, 'corporationNames')),
            country: 'CA',
            source: self::KEY,
            identifiers: $identifiers,
            address: $this->address(Data::list($data, 'adresses')),
            status: $this->status(Data::string($data, 'status')),
            legalForm: Data::string($data, 'act'),
            foundedAt: $this->founded(Data::list($data, 'activities')),
            raw: $data,
        );
    }

    /**
     * The current primary name, else the current name of any type.
     *
     * @param  list<array<array-key, mixed>>  $names
     */
    private function name(array $names): ?string
    {
        $current = null;
        foreach ($names as $entry) {
            $name = Data::map($entry, 'CorporationName');
            if (Data::bool($name, 'current') !== true) {
                continue;
            }

            if (Data::string($name, 'nameType') === 'Primary') {
                return Data::string($name, 'name');
            }

            $current ??= Data::string($name, 'name');
        }

        return $current;
    }

    /**
     * @param  list<array<array-key, mixed>>  $addresses
     */
    private function address(array $addresses): ?Address
    {
        $address = isset($addresses[0]) ? Data::map($addresses[0], 'address') : [];
        $lines = array_values(array_filter(array_map(fn (mixed $line): ?string => Data::clean($line), Data::map($address, 'addressLine'))));
        $city = Data::string($address, 'city');
        $postcode = Data::string($address, 'postalCode');

        if ($lines === [] && $city === null && $postcode === null) {
            return null;
        }

        return new Address(
            street: $lines === [] ? null : implode(', ', $lines),
            postalCode: $postcode,
            city: $city,
            region: Data::string($address, 'provinceCode'),
            country: Data::string($address, 'countryCode') ?? 'CA',
        );
    }

    /**
     * @param  list<array<array-key, mixed>>  $activities
     */
    private function founded(array $activities): ?DateTimeImmutable
    {
        foreach ($activities as $entry) {
            $name = strtolower(Data::string($entry, 'activity.activity') ?? '');
            if (in_array($name, self::FOUNDING_ACTIVITIES, true)) {
                return Data::date(Data::string($entry, 'activity.date'));
            }
        }

        return null;
    }

    private function status(?string $status): CompanyStatus
    {
        $status = strtolower($status ?? '');

        return match (true) {
            $status === '' => CompanyStatus::Unknown,
            $status === 'active' => CompanyStatus::Active,
            str_contains($status, 'dissolved') => CompanyStatus::Dissolved,
            default => CompanyStatus::Inactive,
        };
    }
}
