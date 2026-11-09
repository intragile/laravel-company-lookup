<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use IntrAgile\CompanyLookup\ActivityCode;
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
 * Greek General Commercial Registry (GEMI) open data API. Free; needs an API key
 * from opendata.businessportal.gr. Looks up by GEMI number or AFM, and searches names.
 */
final class GemiDriver extends AbstractHttpDriver
{
    public const string KEY = 'gemi';

    private const BASE_URL = 'https://opendata-api.businessportal.gr/api/opendata/v1';

    /** The API returns at most 200 results per request. */
    private const MAX_RESULTS = 200;

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'GEMI (Greek General Commercial Registry)',
            countries: ['GR'],
            identifierTypes: [IdentifierType::Registration, IdentifierType::Tax],
            nameSearch: true,
            credentials: ['key' => 'COMPANY_LOOKUP_GEMI_KEY'],
            registryUrl: 'https://publicity.businessportal.gr/',
            sample: '000828201000',
            sampleCountry: 'GR',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $digits = Normalize::digits(Normalize::withoutPrefix('GR', $identifier->value));

        if (strtoupper($country) !== 'GR') {
            return LookupResult::notFound(self::KEY);
        }

        $isTax = $identifier->type === IdentifierType::Tax;
        $valid = match ($identifier->type) {
            IdentifierType::Tax => self::validAfm($digits),
            IdentifierType::Registration => preg_match('/^\d{1,12}$/', $digits) === 1 && ltrim($digits, '0') !== '',
            default => false,
        };

        if (! $valid) {
            return LookupResult::notFound(self::KEY);
        }

        $key = $this->setting('key');
        if ($key === null) {
            return LookupResult::unconfigured(self::KEY, 'COMPANY_LOOKUP_GEMI_KEY');
        }

        return $this->guard(function () use ($key, $digits, $isTax): LookupResult {
            if ($isTax) {
                return $this->companies($this->api($key)->get($this->baseUrl(self::BASE_URL).'/companies', ['afm' => $digits]), self::MAX_RESULTS, true);
            }

            // The GEMI number is an integer path parameter, so the customary zero padding goes.
            $response = $this->api($key)->get($this->baseUrl(self::BASE_URL).'/companies/'.ltrim($digits, '0'));

            if ($response->status() === 404) {
                return LookupResult::notFound(self::KEY);
            }

            if (! $response->successful()) {
                return $this->failure($response, $this->errorMessage($response));
            }

            $data = $this->json($response);

            return Data::string($data, 'arGemi') === null
                ? LookupResult::notFound(self::KEY)
                : LookupResult::found(self::KEY, $this->company($data));
        });
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        $name = trim($name);

        if (strtoupper($country) !== 'GR' || $name === '') {
            return LookupResult::notFound(self::KEY);
        }

        $key = $this->setting('key');
        if ($key === null) {
            return LookupResult::unconfigured(self::KEY, 'COMPANY_LOOKUP_GEMI_KEY');
        }

        return $this->guard(function () use ($key, $name, $limit): LookupResult {
            $size = max(1, min(self::MAX_RESULTS, $limit));
            $response = $this->api($key)->get($this->baseUrl(self::BASE_URL).'/companies', [
                'name' => $name,
                'resultsOffset' => 0,
                'resultsSize' => $size,
            ]);

            return $this->companies($response, $size, false);
        });
    }

    /**
     * AFM check digit: weighted sum (powers of two) modulo 11, modulo 10.
     */
    public static function validAfm(string $afm): bool
    {
        if (preg_match('/^\d{9}$/', $afm) !== 1 || $afm === '000000000') {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += (int) $afm[$i] * (2 ** (8 - $i));
        }

        return $sum % 11 % 10 === (int) $afm[8];
    }

    private function api(string $key): PendingRequest
    {
        return $this->request()->acceptJson()->withHeaders(['api_key' => $key]);
    }

    private function companies(Response $response, int $limit, bool $preferMain): LookupResult
    {
        if ($response->status() === 404) {
            return LookupResult::notFound(self::KEY);
        }

        if (! $response->successful()) {
            return $this->failure($response, $this->errorMessage($response));
        }

        $results = array_values(array_filter(
            Data::list($this->json($response), 'searchResults'),
            fn (array $item): bool => Data::string($item, 'arGemi') !== null,
        ));

        // An AFM lookup also matches the branches of the company; keep the head office when listed.
        if ($preferMain) {
            $main = array_values(array_filter($results, fn (array $item): bool => Data::bool($item, 'isBranch') !== true));
            $results = $main === [] ? $results : $main;
        }

        $companies = array_map(fn (array $item): Company => $this->company($item), array_slice($results, 0, $limit));

        return $companies === [] ? LookupResult::notFound(self::KEY) : LookupResult::found(self::KEY, ...$companies);
    }

    private function errorMessage(Response $response): ?string
    {
        $body = Data::json($response->body());

        return Data::string($body, '0.message') ?? Data::string($body, 'message');
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function company(array $data): Company
    {
        $identifiers = [Identifier::registration(str_pad((string) Data::string($data, 'arGemi'), 12, '0', STR_PAD_LEFT), 'GR-GEMI')];
        $afm = Data::string($data, 'afm');
        if ($afm !== null) {
            $afm = str_pad($afm, 9, '0', STR_PAD_LEFT);
            $identifiers[] = Identifier::tax($afm, 'GR-AFM');
            $identifiers[] = Identifier::vat(Normalize::vatPrefix('GR').$afm);
        }

        $activities = [];
        foreach (Data::list($data, 'activities') as $activity) {
            $code = Data::string($activity, 'activity.id');
            if ($code !== null && Data::string($activity, 'dtTo') === null) {
                $activities[] = new ActivityCode('GR-KAD', $code, Data::string($activity, 'activity.descr'));
            }
        }

        return new Company(
            name: Data::string($data, 'coNameEl') ?? Data::string($data, 'coNamesEn.0'),
            country: 'GR',
            source: self::KEY,
            identifiers: $identifiers,
            address: $this->address($data),
            status: $this->status(Data::map($data, 'status')),
            legalForm: Data::string($data, 'legalType.descr'),
            legalFormCode: Data::string($data, 'legalType.id'),
            activities: $activities,
            foundedAt: Data::date(Data::string($data, 'incorporationDate')),
            raw: $data,
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function address(array $data): ?Address
    {
        $street = trim((Data::string($data, 'street') ?? '').' '.(Data::string($data, 'streetNumber') ?? ''));
        $postcode = Data::string($data, 'zipCode');
        $city = Data::string($data, 'city') ?? Data::string($data, 'municipality.descr');

        if ($street === '' && $postcode === null && $city === null) {
            return null;
        }

        return new Address(
            street: $street === '' ? null : $street,
            postalCode: $postcode,
            city: $city,
            region: Data::string($data, 'prefecture.descr'),
            country: 'GR',
        );
    }

    /**
     * @param  array<array-key, mixed>  $status
     */
    private function status(array $status): CompanyStatus
    {
        $active = Data::bool($status, 'isActive');
        $description = mb_strtolower((Data::string($status, 'descrEn') ?? '').' '.(Data::string($status, 'descr') ?? ''));

        return match (true) {
            $active === true => CompanyStatus::Active,
            str_contains($description, 'deleted'), str_contains($description, 'dissolved'), str_contains($description, 'διαγραφ') => CompanyStatus::Dissolved,
            $active === false => CompanyStatus::Inactive,
            default => CompanyStatus::Unknown,
        };
    }
}
