<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use Illuminate\Http\Client\Response;
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
 * Singapore entities with a UEN, from the ACRA dataset on data.gov.sg (CKAN datastore API). Free, no key.
 *
 * The dataset is thin: name, status, entity type, issue date, street name and postal code.
 */
final class SgAcraDriver extends AbstractHttpDriver
{
    public const string KEY = 'sg-acra';

    /** The "Entities with Unique Entity Number" resource, override with the resource_id setting. */
    public const string RESOURCE_ID = 'd_3f960c10fed6145404ca7b821f263b87';

    /** Business (8 digits + letter), local company (9 digits + letter) and other entity UEN formats. */
    private const UEN_PATTERN = '/^(\d{8}[A-Z]|\d{9}[A-Z]|[STR]\d{2}[A-Z]{2}\d{4}[A-Z])$/';

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'Singapore ACRA (data.gov.sg)',
            countries: ['SG'],
            identifierTypes: [IdentifierType::Registration],
            nameSearch: true,
            attribution: 'Contains information from the ACRA entities dataset accessed from data.gov.sg, made available under the Singapore Open Data Licence version 1.0.',
            registryUrl: 'https://www.bizfile.gov.sg/',
            sample: '196800306E',
            sampleCountry: 'SG',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $uen = Normalize::compact($identifier->value);
        if (str_starts_with($uen, 'SG') && preg_match(self::UEN_PATTERN, substr($uen, 2)) === 1) {
            $uen = substr($uen, 2);
        }

        if (strtoupper($country) !== 'SG' || preg_match(self::UEN_PATTERN, $uen) !== 1) {
            return LookupResult::notFound(self::KEY);
        }

        return $this->guard(function () use ($uen): LookupResult {
            $records = $this->records(['filters' => $this->encode(['uen' => $uen]), 'limit' => 5]);
            if ($records instanceof LookupResult) {
                return $records;
            }

            foreach ($records as $record) {
                if (Data::string($record, 'uen') === $uen) {
                    return LookupResult::found(self::KEY, $this->company($record));
                }
            }

            return LookupResult::notFound(self::KEY);
        });
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        $name = trim($name);

        if (strtoupper($country) !== 'SG' || $name === '' || $limit < 1) {
            return LookupResult::notFound(self::KEY);
        }

        return $this->guard(function () use ($name, $limit): LookupResult {
            $records = $this->records(['q' => $this->encode(['entity_name' => $name]), 'limit' => $limit]);
            if ($records instanceof LookupResult) {
                return $records;
            }

            $companies = array_map(fn (array $record): Company => $this->company($record), $records);

            return $companies === [] ? LookupResult::notFound(self::KEY) : LookupResult::found(self::KEY, ...$companies);
        });
    }

    /**
     * Runs a datastore_search query and returns its records, or a failed result.
     *
     * @param  array<string, string|int>  $query
     * @return list<array<array-key, mixed>>|LookupResult
     */
    private function records(array $query): array|LookupResult
    {
        $response = $this->request()
            ->acceptJson()
            ->get($this->baseUrl('https://data.gov.sg/api/action').'/datastore_search', [
                'resource_id' => $this->setting('resource_id', self::RESOURCE_ID),
                ...$query,
            ]);

        if (! $response->successful()) {
            return $this->failure($response, $this->errorMessage($response));
        }

        $data = $this->json($response);
        if (Data::bool($data, 'success') !== true) {
            return LookupResult::failed(self::KEY, FailureReason::InvalidResponse, false, $this->errorMessage($response));
        }

        return Data::list($data, 'result.records');
    }

    /**
     * @param  array<array-key, mixed>  $record
     */
    private function company(array $record): Company
    {
        $street = Data::string($record, 'reg_street_name');
        $postalCode = Data::string($record, 'reg_postal_code');

        return new Company(
            name: Data::string($record, 'entity_name'),
            country: 'SG',
            source: self::KEY,
            identifiers: [Identifier::registration((string) Data::string($record, 'uen'), 'SG-UEN')],
            address: $street === null && $postalCode === null ? null : new Address(street: $street, postalCode: $postalCode, country: 'SG'),
            status: $this->status(Data::string($record, 'uen_status_desc')),
            legalForm: Data::string($record, 'entity_type_desc'),
            foundedAt: Data::date(Data::string($record, 'uen_issue_date')),
            raw: $record,
        );
    }

    private function status(?string $description): CompanyStatus
    {
        $description = strtolower($description ?? '');

        foreach (['deregist', 'ceased', 'struck', 'cancel', 'dissolv', 'terminat', 'expired', 'wound', 'amalgamat'] as $needle) {
            if (str_contains($description, $needle)) {
                return CompanyStatus::Dissolved;
            }
        }

        return str_contains($description, 'registered') || str_contains($description, 'live')
            ? CompanyStatus::Active
            : CompanyStatus::Unknown;
    }

    /**
     * @param  array<string, string>  $value
     */
    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function errorMessage(Response $response): string
    {
        $error = Data::get(Data::json($response->body()), 'error');

        return 'HTTP '.$response->status().(is_array($error) ? ' '.json_encode($error, JSON_UNESCAPED_UNICODE) : '');
    }
}
