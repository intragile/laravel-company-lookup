<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use DateTimeImmutable;
use Illuminate\Http\Client\PendingRequest;
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
 * Irish Companies Registration Office "Open Services" REST API. Free; needs an API key
 * granted by the CRO, sent with the account e-mail address as HTTP Basic credentials.
 */
final class CroDriver extends AbstractHttpDriver
{
    public const string KEY = 'cro';

    private const BASE_URL = 'https://services.cro.ie/cws';

    /** Company search: 2 = name starts with the phrase (the fastest mode). */
    private const STARTS_WITH = 2;

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'Companies Registration Office (IE)',
            countries: ['IE'],
            identifierTypes: [IdentifierType::Registration],
            nameSearch: true,
            credentials: [
                'email' => 'COMPANY_LOOKUP_CRO_EMAIL',
                'key' => 'COMPANY_LOOKUP_CRO_KEY',
            ],
            registryUrl: 'https://core.cro.ie/',
            sample: '104547',
            sampleCountry: 'IE',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $number = ltrim(Normalize::digits(Normalize::withoutPrefix('IE', $identifier->value)), '0');

        if (strtoupper($country) !== 'IE' || $identifier->type !== IdentifierType::Registration || $number === '' || strlen($number) > 7) {
            return LookupResult::notFound(self::KEY);
        }

        $api = $this->api();
        if ($api === null) {
            return LookupResult::unconfigured(self::KEY, implode(', ', $this->capabilities()->credentials));
        }

        return $this->guard(function () use ($api, $number): LookupResult {
            // "c" selects companies; business names share the number space under "b".
            $response = $api->get($this->baseUrl(self::BASE_URL).'/company/'.$number.'/c', ['format' => 'json']);

            if ($response->status() === 404) {
                return LookupResult::notFound(self::KEY);
            }

            if (! $response->successful()) {
                return $this->failure($response, Data::clean(trim($response->body(), "\" \n")));
            }

            $data = $this->json($response);

            return Data::string($data, 'company_num') === null || Data::string($data, 'company_name') === null
                ? LookupResult::notFound(self::KEY)
                : LookupResult::found(self::KEY, $this->company($data));
        });
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        $name = trim($name);

        if (strtoupper($country) !== 'IE' || $name === '') {
            return LookupResult::notFound(self::KEY);
        }

        $api = $this->api();
        if ($api === null) {
            return LookupResult::unconfigured(self::KEY, implode(', ', $this->capabilities()->credentials));
        }

        return $this->guard(function () use ($api, $name, $limit): LookupResult {
            $response = $api->get($this->baseUrl(self::BASE_URL).'/companies', [
                'company_name' => $name,
                'company_bus_ind' => 'c',
                'searchType' => self::STARTS_WITH,
                'skip' => 0,
                'max' => max(1, min(100, $limit)),
                'format' => 'json',
            ]);

            if ($response->status() === 404) {
                return LookupResult::notFound(self::KEY);
            }

            if (! $response->successful()) {
                return $this->failure($response, Data::clean(trim($response->body(), "\" \n")));
            }

            $companies = [];
            foreach (Data::arrays($this->json($response)) as $item) {
                if (Data::string($item, 'company_num') !== null) {
                    $companies[] = $this->company($item);
                }
            }

            return $companies === [] ? LookupResult::notFound(self::KEY) : LookupResult::found(self::KEY, ...$companies);
        });
    }

    /**
     * A request authenticated with the e-mail address and API key, or null when either is missing.
     */
    private function api(): ?PendingRequest
    {
        $email = $this->setting('email');
        $key = $this->setting('key');

        if ($email === null || $key === null) {
            return null;
        }

        return $this->request()->acceptJson()->withBasicAuth($email, $key);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function company(array $data): Company
    {
        return new Company(
            name: Data::string($data, 'company_name'),
            country: 'IE',
            source: self::KEY,
            identifiers: [Identifier::registration((string) Data::string($data, 'company_num'), 'IE-CRO')],
            address: $this->address($data),
            status: $this->status(Data::string($data, 'company_status_desc')),
            legalForm: Data::string($data, 'comp_type_desc'),
            legalFormCode: Data::string($data, 'company_type_code'),
            foundedAt: $this->date(Data::string($data, 'company_reg_date')),
            raw: $data,
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function address(array $data): ?Address
    {
        $lines = array_values(array_filter(array_map(
            fn (string $field): ?string => Data::string($data, $field),
            ['company_addr_1', 'company_addr_2', 'company_addr_3', 'company_addr_4'],
        )));
        $eircode = Data::string($data, 'eircode');

        if ($lines === [] && $eircode === null) {
            return null;
        }

        return new Address(
            street: $lines[0] ?? null,
            postalCode: $eircode,
            country: 'IE',
            freeText: implode(', ', array_filter([...$lines, $eircode])),
        );
    }

    private function status(?string $description): CompanyStatus
    {
        $description = strtolower($description ?? '');

        return match (true) {
            $description === '' => CompanyStatus::Unknown,
            $description === 'normal' => CompanyStatus::Active,
            str_contains($description, 'dissolved'), str_contains($description, 'deregistered') => CompanyStatus::Dissolved,
            default => CompanyStatus::Inactive,
        };
    }

    /**
     * Dates are UTC timestamps; 0001-01-01 means "not provided".
     */
    private function date(?string $value): ?DateTimeImmutable
    {
        return $value === null || str_starts_with($value, '0001-01-01') ? null : Data::date($value);
    }
}
