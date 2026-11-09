<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use Illuminate\Http\Client\PendingRequest;
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
 * New Zealand Business Number register (NZBN API v5). Free; needs a subscription key
 * from the MBIE API portal (portal.api.business.govt.nz).
 */
final class NzbnDriver extends AbstractHttpDriver
{
    public const string KEY = 'nzbn';

    private const BASE_URL = 'https://api.business.govt.nz/gateway/nzbn/v5';

    /** Entity types held on the Companies Register, whose source id is the company number. */
    private const COMPANY_TYPES = ['LTD', 'ULTD', 'COOP', 'ASIC', 'NON_ASIC'];

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'New Zealand Business Number register',
            countries: ['NZ'],
            identifierTypes: [IdentifierType::Registration],
            nameSearch: true,
            credentials: ['key' => 'COMPANY_LOOKUP_NZBN_KEY'],
            registryUrl: 'https://www.nzbn.govt.nz/mynzbn/search/',
            sample: '9429000001885',
            sampleCountry: 'NZ',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $nzbn = Normalize::digits(Normalize::withoutPrefix('NZ', $identifier->value));

        if (strtoupper($country) !== 'NZ' || $identifier->type !== IdentifierType::Registration || ! self::validNzbn($nzbn)) {
            return LookupResult::notFound(self::KEY);
        }

        $key = $this->setting('key');
        if ($key === null) {
            return LookupResult::unconfigured(self::KEY, 'COMPANY_LOOKUP_NZBN_KEY');
        }

        return $this->guard(function () use ($key, $nzbn): LookupResult {
            $response = $this->api($key)->get($this->baseUrl(self::BASE_URL).'/entities/'.$nzbn);

            if ($response->status() === 404) {
                return LookupResult::notFound(self::KEY);
            }

            if (! $response->successful()) {
                return $this->failure($response, Data::string(Data::json($response->body()), 'errorDescription'));
            }

            $data = $this->json($response);

            return Data::string($data, 'nzbn') === null
                ? LookupResult::notFound(self::KEY)
                : LookupResult::found(self::KEY, $this->company($data));
        });
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        $name = trim($name);

        if (strtoupper($country) !== 'NZ' || $name === '') {
            return LookupResult::notFound(self::KEY);
        }

        $key = $this->setting('key');
        if ($key === null) {
            return LookupResult::unconfigured(self::KEY, 'COMPANY_LOOKUP_NZBN_KEY');
        }

        return $this->guard(function () use ($key, $name, $limit): LookupResult {
            $response = $this->api($key)->get($this->baseUrl(self::BASE_URL).'/entities', [
                'search-term' => $name,
                'page' => 0,
                'page-size' => max(1, min(200, $limit)),
            ]);

            if ($response->status() === 404) {
                return LookupResult::notFound(self::KEY);
            }

            if (! $response->successful()) {
                return $this->failure($response, Data::string(Data::json($response->body()), 'errorDescription'));
            }

            $companies = [];
            foreach (Data::list($this->json($response), 'items') as $item) {
                if (Data::string($item, 'nzbn') !== null) {
                    $companies[] = $this->company($item);
                }
            }

            return $companies === [] ? LookupResult::notFound(self::KEY) : LookupResult::found(self::KEY, ...$companies);
        });
    }

    /**
     * NZBNs are GS1 global location numbers: 13 digits with a mod-10 check digit.
     */
    public static function validNzbn(string $nzbn): bool
    {
        if (preg_match('/^\d{13}$/', $nzbn) !== 1) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $nzbn[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10 === (int) $nzbn[12];
    }

    private function api(string $key): PendingRequest
    {
        return $this->request()->acceptJson()->withHeaders(['Ocp-Apim-Subscription-Key' => $key]);
    }

    /**
     * Maps a full entity or a search item; both share the core fields.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function company(array $data): Company
    {
        $identifiers = [Identifier::registration((string) Data::string($data, 'nzbn'), 'NZ-NZBN')];
        $registerNumber = Data::string($data, 'sourceRegisterUniqueIdentifier') ?? Data::string($data, 'sourceRegisterUniqueId');
        // Search items carry no sourceRegister, so companies are recognised by their entity type there.
        $register = Data::string($data, 'sourceRegister')
            ?? (in_array(Data::string($data, 'entityTypeCode'), self::COMPANY_TYPES, true) ? 'COMPANY' : null);
        if ($registerNumber !== null && $register === 'COMPANY') {
            $identifiers[] = Identifier::registration($registerNumber, 'NZ-COMPANY');
        }

        $activities = [];
        foreach ([...Data::list($data, 'industryClassifications'), ...Data::list($data, 'classifications')] as $classification) {
            $code = Data::string($classification, 'classificationCode');
            if ($code !== null) {
                $activities[] = new ActivityCode('NZ-BIC', $code, Data::string($classification, 'classificationDescription'));
            }
        }

        return new Company(
            name: Data::string($data, 'entityName'),
            country: 'NZ',
            source: self::KEY,
            identifiers: $identifiers,
            address: $this->address(Data::list($data, 'addresses.addressList')),
            status: $this->status(Data::string($data, 'entityStatusDescription')),
            legalForm: Data::string($data, 'entityTypeDescription'),
            legalFormCode: Data::string($data, 'entityTypeCode'),
            activities: $activities,
            foundedAt: Data::date(Data::string($data, 'registrationDate')),
            raw: $data,
        );
    }

    /**
     * The current registered address, else the first current address of any type.
     *
     * @param  list<array<array-key, mixed>>  $addresses
     */
    private function address(array $addresses): ?Address
    {
        $current = array_values(array_filter($addresses, fn (array $address): bool => Data::string($address, 'endDate') === null));
        $registered = array_values(array_filter($current, fn (array $address): bool => Data::string($address, 'addressType') === 'REGISTERED'));
        $address = $registered[0] ?? $current[0] ?? null;

        if ($address === null) {
            return null;
        }

        $lines = array_values(array_filter(array_map(
            fn (string $field): ?string => Data::string($address, $field),
            ['address1', 'address2', 'address3', 'address4'],
        )));
        $postcode = Data::string($address, 'postCode');

        if ($lines === [] && $postcode === null) {
            return null;
        }

        return new Address(
            street: $lines[0] ?? null,
            postalCode: $postcode,
            city: count($lines) > 1 ? $lines[count($lines) - 1] : null,
            country: Data::string($address, 'countryCode') ?? 'NZ',
            freeText: implode(', ', array_filter([...$lines, $postcode])),
        );
    }

    private function status(?string $description): CompanyStatus
    {
        $description = strtolower(str_replace(' ', '', $description ?? ''));

        return match (true) {
            $description === '' => CompanyStatus::Unknown,
            $description === 'registered' => CompanyStatus::Active,
            str_contains($description, 'removed'), str_contains($description, 'closed'), str_contains($description, 'struckoff') => CompanyStatus::Dissolved,
            default => CompanyStatus::Inactive,
        };
    }
}
