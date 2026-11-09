<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use DateTimeImmutable;
use DateTimeZone;
use IntrAgile\CompanyLookup\Address;
use IntrAgile\CompanyLookup\Capabilities;
use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\Drivers\Support\Data;
use IntrAgile\CompanyLookup\Drivers\Support\Normalize;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupResult;

/**
 * GLEIF Legal Entity Identifier records (JSON:API). Free, no key, CC0, worldwide.
 *
 * Looks up by LEI, or by the local register number (entity.registeredAs) within a country.
 */
final class GleifDriver extends AbstractHttpDriver
{
    public const string KEY = 'gleif';

    private const DEFAULT_BASE_URL = 'https://api.gleif.org/api/v1';

    /** GLEIF caps page[size] at 200. */
    private const MAX_PAGE_SIZE = 200;

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'GLEIF LEI',
            countries: ['*'],
            identifierTypes: [IdentifierType::Lei, IdentifierType::Registration],
            nameSearch: true,
            registryUrl: 'https://search.gleif.org/',
            sample: '529900D6BF99LW9R2E68',
            sampleCountry: 'DE',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        return match ($identifier->type) {
            IdentifierType::Lei => $this->byLei($country, $identifier->value),
            IdentifierType::Registration => $this->byRegistration($country, $identifier),
            default => LookupResult::notFound(self::KEY),
        };
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        $name = trim($name);

        if ($name === '' || $limit < 1) {
            return LookupResult::notFound(self::KEY);
        }

        return $this->list(['filter[entity.legalName]' => $name], $country, min($limit, self::MAX_PAGE_SIZE));
    }

    private function byLei(string $country, string $value): LookupResult
    {
        $lei = Normalize::compact($value);

        if (preg_match('/^[A-Z0-9]{18}\d{2}$/', $lei) !== 1) {
            return LookupResult::notFound(self::KEY);
        }

        return $this->guard(function () use ($country, $lei): LookupResult {
            $response = $this->request()
                ->accept('application/vnd.api+json')
                ->get($this->baseUrl(self::DEFAULT_BASE_URL).'/lei-records/'.$lei);

            if ($response->status() === 404) {
                return LookupResult::notFound(self::KEY);
            }

            if (! $response->successful()) {
                return $this->failure($response);
            }

            $record = Data::map($this->json($response), 'data');

            return $record === [] ? LookupResult::notFound(self::KEY) : LookupResult::found(self::KEY, $this->company($record, $country));
        });
    }

    /**
     * Register numbers are only unique per registration authority (e.g. "HRB 1000" exists at many
     * German courts). The authority can be given as a GLEIF RA code in the identifier scheme;
     * without it, more than one match is reported as ambiguous instead of guessing.
     */
    private function byRegistration(string $country, Identifier $identifier): LookupResult
    {
        // Register numbers are matched literally, so only surplus whitespace is removed.
        $number = trim((string) preg_replace('/\s+/u', ' ', $identifier->value));

        if ($number === '') {
            return LookupResult::notFound(self::KEY);
        }

        $filters = ['filter[entity.registeredAs]' => $number];
        $authority = strtoupper(trim((string) $identifier->scheme));
        if (preg_match('/^RA\d{6}$/', $authority) === 1) {
            $filters['filter[entity.registeredAt]'] = $authority;
        }

        $result = $this->list($filters, $country, 10);
        if (! $result->isFound()) {
            return $result;
        }

        // The registeredAs filter also matches longer numbers ("HRB 1000" finds "HRB 1000 SL"),
        // so exact matches win when there are any.
        $wanted = Normalize::compact($number);
        $exact = array_values(array_filter(
            $result->companies,
            fn (Company $company): bool => Normalize::compact((string) $company->identifier(IdentifierType::Registration)) === $wanted,
        ));
        $companies = $exact === [] ? $result->companies : $exact;

        if (count($companies) > 1) {
            return LookupResult::failed(self::KEY, FailureReason::Rejected, false, 'ambiguous register number; pass the registration authority (RA code) as the identifier scheme');
        }

        return LookupResult::found(self::KEY, ...$companies);
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function list(array $filters, string $country, int $size): LookupResult
    {
        $country = strtoupper(trim($country));

        if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
            $filters['filter[entity.legalAddress.country]'] = $country;
        }

        $filters['page[size]'] = (string) $size;

        return $this->guard(function () use ($country, $filters): LookupResult {
            $response = $this->request()
                ->accept('application/vnd.api+json')
                ->get($this->baseUrl(self::DEFAULT_BASE_URL).'/lei-records', $filters);

            if (! $response->successful()) {
                return $this->failure($response);
            }

            $companies = array_map(fn (array $record): Company => $this->company($record, $country), Data::list($this->json($response), 'data'));

            return LookupResult::found(self::KEY, ...$companies);
        });
    }

    /**
     * @param  array<array-key, mixed>  $record  One JSON:API lei-records resource.
     * @param  string  $fallbackCountry  Used when the record carries no country at all.
     */
    private function company(array $record, string $fallbackCountry): Company
    {
        $entity = Data::map($record, 'attributes.entity');
        $lei = Data::string($record, 'attributes.lei') ?? Data::string($record, 'id');
        $address = Data::map($entity, 'legalAddress');
        $country = Data::string($address, 'country') ?? Data::string($entity, 'jurisdiction') ?? $fallbackCountry;

        $identifiers = [];
        if ($lei !== null) {
            $identifiers[] = Identifier::lei($lei);
        }
        $registeredAs = Data::string($entity, 'registeredAs');
        if ($registeredAs !== null) {
            $identifiers[] = Identifier::registration($registeredAs, Data::string($entity, 'registeredAt.id'));
        }

        $legalFormCode = Data::string($entity, 'legalForm.id');

        return new Company(
            name: Data::string($entity, 'legalName.name'),
            country: strtoupper($country),
            source: self::KEY,
            identifiers: $identifiers,
            address: $this->address($address),
            status: $this->status($entity),
            legalForm: Data::string($entity, 'legalForm.other'),
            // GLEIF uses 8888 for "no ELF code assigned"; the text is then in legalForm.other.
            legalFormCode: $legalFormCode === '8888' ? null : $legalFormCode,
            foundedAt: $this->day(Data::string($entity, 'creationDate')),
            raw: $record,
        );
    }

    /**
     * GLEIF stores local midnight as a UTC timestamp (e.g. 2014-07-06T22:00:00Z for 7 July in
     * Germany), so the timestamp is rounded to the nearest day instead of being truncated.
     */
    private function day(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.vP'] as $format) {
            $timestamp = DateTimeImmutable::createFromFormat($format, $value);
            if ($timestamp !== false) {
                return Data::date($timestamp->setTimezone(new DateTimeZone('UTC'))->modify('+12 hours')->format('Y-m-d'));
            }
        }

        return Data::date($value);
    }

    /**
     * @param  array<array-key, mixed>  $address
     */
    private function address(array $address): ?Address
    {
        $lines = array_values(array_filter(array_map(Data::clean(...), Data::map($address, 'addressLines')), fn (?string $line): bool => $line !== null));

        $result = new Address(
            street: $lines === [] ? null : implode(', ', $lines),
            postalCode: Data::string($address, 'postalCode'),
            city: Data::string($address, 'city'),
            region: Data::string($address, 'region'),
            country: Data::string($address, 'country'),
        );

        return $result->format() === null ? null : $result;
    }

    /**
     * @param  array<array-key, mixed>  $entity
     */
    private function status(array $entity): CompanyStatus
    {
        return match (Data::string($entity, 'status')) {
            'ACTIVE' => CompanyStatus::Active,
            'INACTIVE' => in_array(Data::string($entity, 'expiration.reason'), ['DISSOLVED', 'CORPORATE_ACTION'], true)
                ? CompanyStatus::Dissolved
                : CompanyStatus::Inactive,
            default => CompanyStatus::Unknown,
        };
    }
}
