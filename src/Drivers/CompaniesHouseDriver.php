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
 * Companies House Public Data API (England & Wales, Scotland, Northern Ireland).
 * Free; needs a REST API key, sent as the HTTP Basic user name with an empty password.
 */
final class CompaniesHouseDriver extends AbstractHttpDriver
{
    public const string KEY = 'companies-house';

    private const BASE_URL = 'https://api.company-information.service.gov.uk';

    private const STATUSES = [
        'active' => CompanyStatus::Active,
        'open' => CompanyStatus::Active,
        'registered' => CompanyStatus::Active,
        'voluntary-arrangement' => CompanyStatus::Active,
        'dissolved' => CompanyStatus::Dissolved,
        'converted-closed' => CompanyStatus::Dissolved,
        'removed' => CompanyStatus::Dissolved,
        'closed' => CompanyStatus::Dissolved,
        'liquidation' => CompanyStatus::Inactive,
        'administration' => CompanyStatus::Inactive,
        'receivership' => CompanyStatus::Inactive,
        'insolvency-proceedings' => CompanyStatus::Inactive,
    ];

    /** Country names used in Companies House addresses, lowercased. */
    private const COUNTRIES = [
        'england' => 'GB',
        'wales' => 'GB',
        'scotland' => 'GB',
        'northern ireland' => 'GB',
        'england and wales' => 'GB',
        'united kingdom' => 'GB',
        'great britain' => 'GB',
        'uk' => 'GB',
        'ireland' => 'IE',
        'republic of ireland' => 'IE',
        'jersey' => 'JE',
        'guernsey' => 'GG',
        'isle of man' => 'IM',
        'france' => 'FR',
        'germany' => 'DE',
        'netherlands' => 'NL',
        'spain' => 'ES',
        'italy' => 'IT',
        'united states' => 'US',
        'usa' => 'US',
        'united states of america' => 'US',
    ];

    /** Labels of the most common company types; others keep only the code. */
    private const TYPES = [
        'ltd' => 'Private limited company',
        'plc' => 'Public limited company',
        'llp' => 'Limited liability partnership',
        'private-unlimited' => 'Private unlimited company',
        'private-limited-guarant-nsc' => 'Private company limited by guarantee without share capital',
        'private-limited-guarant-nsc-limited-exemption' => 'Private company limited by guarantee without share capital, use of "Limited" exemption',
        'private-unlimited-nsc' => 'Private unlimited company without share capital',
        'limited-partnership' => 'Limited partnership',
        'scottish-partnership' => 'Scottish qualifying partnership',
        'charitable-incorporated-organisation' => 'Charitable incorporated organisation',
        'scottish-charitable-incorporated-organisation' => 'Scottish charitable incorporated organisation',
        'royal-charter' => 'Royal charter company',
        'uk-establishment' => 'UK establishment of an overseas company',
        'oversea-company' => 'Overseas company',
        'registered-overseas-entity' => 'Registered overseas entity',
        'industrial-and-provident-society' => 'Industrial and provident society',
        'registered-society-non-jurisdictional' => 'Registered society',
    ];

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'Companies House',
            countries: ['GB'],
            identifierTypes: [IdentifierType::Registration],
            nameSearch: true,
            credentials: ['key' => 'COMPANY_LOOKUP_COMPANIES_HOUSE_KEY'],
            attribution: 'Contains public sector information licensed under the Open Government Licence v3.0.',
            registryUrl: 'https://find-and-update.company-information.service.gov.uk/',
            sample: '00445790',
            sampleCountry: 'GB',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $number = strtoupper($country) === 'GB' && $identifier->type === IdentifierType::Registration
            ? $this->companyNumber($identifier->value)
            : null;

        if ($number === null) {
            return LookupResult::notFound(self::KEY);
        }

        if ($this->setting('key') === null) {
            return LookupResult::unconfigured(self::KEY, 'COMPANY_LOOKUP_COMPANIES_HOUSE_KEY');
        }

        return $this->guard(function () use ($number): LookupResult {
            $response = $this->api()->get($this->baseUrl(self::BASE_URL).'/company/'.$number);

            if ($response->status() === 404) {
                return LookupResult::notFound(self::KEY);
            }

            if (! $response->successful()) {
                return $this->failure($response);
            }

            return LookupResult::found(self::KEY, $this->profile($this->json($response)));
        });
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        $name = trim($name);

        if (strtoupper($country) !== 'GB' || $name === '') {
            return LookupResult::notFound(self::KEY);
        }

        if ($this->setting('key') === null) {
            return LookupResult::unconfigured(self::KEY, 'COMPANY_LOOKUP_COMPANIES_HOUSE_KEY');
        }

        return $this->guard(function () use ($name, $limit): LookupResult {
            $response = $this->api()->get($this->baseUrl(self::BASE_URL).'/search/companies', [
                'q' => $name,
                'items_per_page' => max(1, min(100, $limit)),
            ]);

            if ($response->status() === 404) {
                return LookupResult::notFound(self::KEY);
            }

            if (! $response->successful()) {
                return $this->failure($response);
            }

            $companies = array_map(fn (array $item): Company => $this->searchItem($item), Data::list($this->json($response), 'items'));

            return $companies === [] ? LookupResult::notFound(self::KEY) : LookupResult::found(self::KEY, ...$companies);
        });
    }

    /**
     * Company numbers are 8 characters: digits are left-padded with zeros, and a
     * two-letter prefix (SC, NI, OC, SO, ...) is kept with its digits padded to 6.
     * Other 8-character forms, such as registered societies (IP12345R) or old
     * Northern Ireland numbers (R0000123), are passed through unchanged.
     */
    private function companyNumber(string $value): ?string
    {
        $compact = Normalize::compact($value);

        if (str_starts_with($compact, 'GB') && strlen($compact) > 8) {
            $compact = substr($compact, 2);
        }

        if (preg_match('/^\d{1,8}$/', $compact) === 1) {
            return str_pad($compact, 8, '0', STR_PAD_LEFT);
        }

        if (preg_match('/^([A-Z]{2})(\d{1,6})$/', $compact, $match) === 1) {
            return $match[1].str_pad($match[2], 6, '0', STR_PAD_LEFT);
        }

        return preg_match('/^[A-Z0-9]{8}$/', $compact) === 1 ? $compact : null;
    }

    private function api(): PendingRequest
    {
        return $this->request()->acceptJson()->withBasicAuth((string) $this->setting('key'), '');
    }

    /**
     * @param  array<array-key, mixed>  $data  A companyProfile resource.
     */
    private function profile(array $data): Company
    {
        $type = Data::string($data, 'type');

        return new Company(
            name: Data::string($data, 'company_name'),
            country: 'GB',
            source: self::KEY,
            identifiers: $this->identifiers(Data::string($data, 'company_number')),
            address: $this->address(Data::map($data, 'registered_office_address')),
            status: $this->status(Data::string($data, 'company_status')),
            legalForm: $type === null ? null : (self::TYPES[$type] ?? null),
            legalFormCode: $type,
            activities: array_values(array_map(
                fn (string $code): ActivityCode => new ActivityCode('SIC2007', $code),
                array_filter(array_map(Data::clean(...), Data::map($data, 'sic_codes')), fn (?string $code): bool => $code !== null),
            )),
            foundedAt: Data::date(Data::string($data, 'date_of_creation')),
            raw: $data,
        );
    }

    /**
     * @param  array<array-key, mixed>  $item  A companySearch item.
     */
    private function searchItem(array $item): Company
    {
        $type = Data::string($item, 'company_type');

        return new Company(
            name: Data::string($item, 'title'),
            country: 'GB',
            source: self::KEY,
            identifiers: $this->identifiers(Data::string($item, 'company_number')),
            address: $this->address(Data::map($item, 'address')),
            status: $this->status(Data::string($item, 'company_status')),
            legalForm: $type === null ? null : (self::TYPES[$type] ?? null),
            legalFormCode: $type,
            foundedAt: Data::date(Data::string($item, 'date_of_creation')),
            raw: $item,
        );
    }

    /**
     * @return list<Identifier>
     */
    private function identifiers(?string $number): array
    {
        return $number === null ? [] : [Identifier::registration($number, 'GB-COH')];
    }

    private function status(?string $status): CompanyStatus
    {
        return $status === null ? CompanyStatus::Unknown : (self::STATUSES[$status] ?? CompanyStatus::Unknown);
    }

    /**
     * Companies House gives the country as free text; known names become ISO codes.
     */
    private function country(?string $country): ?string
    {
        return $country === null ? null : (self::COUNTRIES[strtolower($country)] ?? null);
    }

    /**
     * @param  array<array-key, mixed>  $address
     */
    private function address(array $address): ?Address
    {
        $premises = Data::string($address, 'premises');
        $line1 = Data::string($address, 'address_line_1');
        $first = trim(implode(' ', array_filter([$premises, $line1], fn (?string $part): bool => $part !== null)));
        $street = implode(', ', array_filter([
            Data::string($address, 'care_of') === null ? null : 'c/o '.Data::string($address, 'care_of'),
            Data::string($address, 'po_box') === null ? null : 'PO Box '.Data::string($address, 'po_box'),
            $first === '' ? null : $first,
            Data::string($address, 'address_line_2'),
        ], fn (?string $part): bool => $part !== null));

        // A country name without a known ISO code stays readable as part of the street.
        $countryName = Data::string($address, 'country');
        $country = $this->country($countryName);
        if ($country === null && $countryName !== null) {
            $street = $street === '' ? $countryName : $street.', '.$countryName;
        }

        $result = new Address(
            street: $street === '' ? null : $street,
            postalCode: Data::string($address, 'postal_code'),
            city: Data::string($address, 'locality'),
            region: Data::string($address, 'region'),
            country: $country,
        );

        return $result->format() === null ? null : $result;
    }
}
