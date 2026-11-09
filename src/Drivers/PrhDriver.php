<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

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
 * Finnish Business Information System (YTJ) open data API v3 of the Finnish Patent and Registration Office (PRH). Free, no key.
 *
 * A VAT lookup is found only when the company is currently in the VAT register.
 */
final class PrhDriver extends AbstractHttpDriver
{
    public const string KEY = 'prh';

    /** YTJ language codes. */
    private const FINNISH = '1';

    private const ENGLISH = '3';

    /** The register number of the VAT register in registeredEntries. */
    private const VAT_REGISTER = '6';

    /** Business ID status "invalidated", and trade register status "ceased". */
    private const BUSINESS_ID_INVALIDATED = '5';

    private const TRADE_REGISTER_CEASED = '4';

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'Finland – PRH / YTJ',
            countries: ['FI'],
            identifierTypes: [IdentifierType::Registration, IdentifierType::Vat],
            nameSearch: true,
            attribution: 'Source: Finnish Patent and Registration Office and Tax Administration (YTJ), CC BY 4.0',
            registryUrl: 'https://tietopalvelu.ytj.fi/',
            sample: '0112038-9',
            sampleCountry: 'FI',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $businessId = self::businessId($identifier->value);

        if (strtoupper($country) !== 'FI' || $businessId === null) {
            return LookupResult::notFound(self::KEY);
        }

        $vat = $identifier->type === IdentifierType::Vat;

        return $this->guard(function () use ($businessId, $vat): LookupResult {
            $response = $this->call(['businessId' => $businessId]);

            if ($response->status() === 404) {
                return LookupResult::notFound(self::KEY);
            }

            if (! $response->successful()) {
                return $this->failure($response);
            }

            foreach (Data::list($this->json($response), 'companies') as $entry) {
                if (Data::string($entry, 'businessId.value') !== $businessId) {
                    continue;
                }

                $company = $this->company($entry);

                return $vat && $company->vatValid !== true
                    ? LookupResult::notFound(self::KEY)
                    : LookupResult::found(self::KEY, $company);
            }

            return LookupResult::notFound(self::KEY);
        });
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        $name = trim($name);

        if (strtoupper($country) !== 'FI' || $name === '') {
            return LookupResult::notFound(self::KEY);
        }

        return $this->guard(function () use ($name, $limit): LookupResult {
            $response = $this->call(['name' => $name]);

            if (! $response->successful()) {
                return $this->failure($response);
            }

            $companies = array_map(fn (array $entry): Company => $this->company($entry), Data::list($this->json($response), 'companies'));

            return LookupResult::found(self::KEY, ...array_slice($this->rank($companies, $name), 0, max(1, $limit)));
        });
    }

    /**
     * The Business ID (Y-tunnus) in its 1234567-8 form, or null when malformed or the check digit is wrong.
     * Accepts "1234567-8", "12345678", the VAT form "FI12345678" and the old six-digit form "123456-7".
     */
    public static function businessId(string $value): ?string
    {
        $digits = Normalize::withoutPrefix('FI', $value);

        // Business IDs issued before 1991 may be written with six digits; only accept that with the dash.
        if (preg_match('/^\d{7}$/', $digits) === 1 && preg_match('/^\D*\d{6}\s*-\s*\d\s*$/', $value) === 1) {
            $digits = '0'.$digits;
        }

        if (preg_match('/^\d{8}$/', $digits) !== 1) {
            return null;
        }

        $sum = 0;
        foreach ([7, 9, 10, 5, 8, 4, 2] as $position => $weight) {
            $sum += (int) $digits[$position] * $weight;
        }
        $remainder = $sum % 11;

        if ($remainder === 1 || (int) $digits[7] !== ($remainder === 0 ? 0 : 11 - $remainder)) {
            return null;
        }

        return substr($digits, 0, 7).'-'.$digits[7];
    }

    /**
     * The API returns matches in Business ID order. Rank them by name: exact match, whole-word prefix,
     * prefix, contains, then the rest; within each tier non-dissolved companies come first.
     *
     * @param  list<Company>  $companies
     * @return list<Company>
     */
    private function rank(array $companies, string $query): array
    {
        $needle = Normalize::name($query);

        $tier = function (Company $company) use ($needle): int {
            $name = Normalize::name($company->name ?? '');

            return match (true) {
                $needle === '' => 4,
                $name === $needle => 0,
                str_starts_with($name, $needle.' ') => 1,
                str_starts_with($name, $needle) => 2,
                str_contains($name, $needle) => 3,
                default => 4,
            };
        };

        // usort is stable, so the source order is kept within equal keys.
        usort($companies, fn (Company $a, Company $b): int => [$tier($a), $a->status === CompanyStatus::Dissolved]
            <=> [$tier($b), $b->status === CompanyStatus::Dissolved]);

        return $companies;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function call(array $query): Response
    {
        return $this->request()
            ->acceptJson()
            ->get($this->baseUrl('https://avoindata.prh.fi/opendata-ytj-api/v3').'/companies', $query);
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function company(array $entry): Company
    {
        $businessId = Data::string($entry, 'businessId.value') ?? '';
        $vatValid = $this->inVatRegister($entry);

        $identifiers = [Identifier::registration($businessId, 'FI-YTUNNUS')];
        if ($vatValid) {
            $identifiers[] = Identifier::vat('FI'.str_replace('-', '', $businessId));
        }

        $form = $this->current(Data::list($entry, 'companyForms'));
        $activityCode = Data::string($entry, 'mainBusinessLine.type');

        return new Company(
            name: $this->name(Data::list($entry, 'names')),
            country: 'FI',
            source: self::KEY,
            identifiers: $identifiers,
            address: $this->address(Data::list($entry, 'addresses')),
            status: $this->status($entry),
            legalForm: $form === null ? null : $this->description($form),
            legalFormCode: $form === null ? null : Data::string($form, 'type'),
            activities: $activityCode === null ? [] : [new ActivityCode('TOL', $activityCode, $this->description(Data::map($entry, 'mainBusinessLine')))],
            foundedAt: Data::date(Data::string($entry, 'registrationDate') ?? Data::string($entry, 'businessId.registrationDate')),
            vatValid: $vatValid,
            raw: $entry,
        );
    }

    /**
     * The current official name (type 1). A dissolved company keeps its last name.
     *
     * @param  list<array<array-key, mixed>>  $names
     */
    private function name(array $names): ?string
    {
        $official = array_values(array_filter($names, fn (array $name): bool => Data::string($name, 'type') === '1'));
        $current = $this->current($official);

        return $current === null ? null : Data::string($current, 'name');
    }

    /**
     * The entry without an end date, else the one with the lowest version (version 1 is the latest).
     *
     * @param  list<array<array-key, mixed>>  $entries
     * @return array<array-key, mixed>|null
     */
    private function current(array $entries): ?array
    {
        if ($entries === []) {
            return null;
        }

        usort($entries, fn (array $a, array $b): int => [Data::string($a, 'endDate') !== null, Data::int($a, 'version') ?? PHP_INT_MAX]
            <=> [Data::string($b, 'endDate') !== null, Data::int($b, 'version') ?? PHP_INT_MAX]);

        return $entries[0];
    }

    /**
     * The English description, else the Finnish one, else the first.
     *
     * @param  array<array-key, mixed>  $coded
     */
    private function description(array $coded): ?string
    {
        $descriptions = Data::list($coded, 'descriptions');

        foreach ([self::ENGLISH, self::FINNISH] as $language) {
            foreach ($descriptions as $description) {
                if (Data::string($description, 'languageCode') === $language) {
                    return Data::string($description, 'description');
                }
            }
        }

        return isset($descriptions[0]) ? Data::string($descriptions[0], 'description') : null;
    }

    /**
     * @param  list<array<array-key, mixed>>  $addresses
     */
    private function address(array $addresses): ?Address
    {
        $chosen = null;
        foreach ($addresses as $address) {
            // Type 1 is the street address, type 2 the postal address.
            if (Data::int($address, 'type') === 1) {
                $chosen = $address;
                break;
            }
            $chosen ??= $address;
        }

        if ($chosen === null) {
            return null;
        }

        $street = Data::string($chosen, 'street');
        $number = trim(implode(' ', array_filter([
            Data::string($chosen, 'buildingNumber'),
            Data::string($chosen, 'entrance'),
            trim((Data::string($chosen, 'apartmentNumber') ?? '').(Data::string($chosen, 'apartmentIdSuffix') ?? '')),
        ], fn (?string $part): bool => $part !== null && $part !== '')));
        $box = Data::string($chosen, 'postOfficeBox');

        $streetLine = match (true) {
            $street !== null => trim($street.' '.$number),
            $box !== null => 'PL '.$box,
            default => null,
        };

        $offices = Data::list($chosen, 'postOffices');
        $city = null;
        foreach ($offices as $office) {
            if (Data::string($office, 'languageCode') === self::FINNISH) {
                $city = Data::string($office, 'city');
                break;
            }
        }
        $city ??= isset($offices[0]) ? Data::string($offices[0], 'city') : null;

        return new Address(
            street: $streetLine,
            postalCode: Data::string($chosen, 'postCode'),
            city: $city,
            country: 'FI',
        );
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function status(array $entry): CompanyStatus
    {
        if (Data::string($entry, 'endDate') !== null
            || Data::string($entry, 'status') === self::BUSINESS_ID_INVALIDATED
            || Data::string($entry, 'tradeRegisterStatus') === self::TRADE_REGISTER_CEASED) {
            return CompanyStatus::Dissolved;
        }

        // Bankruptcy, liquidation or restructuring.
        foreach (Data::list($entry, 'companySituations') as $situation) {
            if (Data::string($situation, 'endDate') === null) {
                return CompanyStatus::Inactive;
            }
        }

        return Data::string($entry, 'status') === null ? CompanyStatus::Unknown : CompanyStatus::Active;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function inVatRegister(array $entry): bool
    {
        foreach (Data::list($entry, 'registeredEntries') as $registration) {
            if (Data::string($registration, 'register') === self::VAT_REGISTER && Data::string($registration, 'endDate') === null) {
                return true;
            }
        }

        return false;
    }
}
