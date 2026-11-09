<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

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
 * Czech ARES register of economic entities (REST). Free, no key.
 *
 * Looks up by IČO, or by a CZ VAT number (DIČ) of a legal entity, whose digits are the IČO.
 */
final class AresDriver extends AbstractHttpDriver
{
    public const string KEY = 'ares';

    private const DEFAULT_BASE_URL = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest';

    /** ARES refuses searches that match more than 1000 entities. */
    private const MAX_PAGE_SIZE = 100;

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'ARES (CZ)',
            countries: ['CZ'],
            identifierTypes: [IdentifierType::Registration, IdentifierType::Vat],
            nameSearch: true,
            registryUrl: 'https://ares.gov.cz/',
            sample: '45274649',
            sampleCountry: 'CZ',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $ico = $this->ico($identifier);

        if (strtoupper($country) !== 'CZ' || $ico === null) {
            return LookupResult::notFound(self::KEY);
        }

        $vatLookup = $identifier->type === IdentifierType::Vat;

        return $this->guard(function () use ($ico, $vatLookup): LookupResult {
            $response = $this->request()
                ->acceptJson()
                ->get($this->baseUrl(self::DEFAULT_BASE_URL).'/ekonomicke-subjekty/'.$ico);

            if ($response->status() === 404) {
                return LookupResult::notFound(self::KEY);
            }

            if (! $response->successful()) {
                return $this->failure($response, Data::string(Data::json($response->body()), 'popis'));
            }

            $company = $this->company($this->json($response));

            // A VAT number only counts as found while its holder is an active VAT payer.
            if ($vatLookup && $company->vatValid !== true) {
                return LookupResult::notFound(self::KEY);
            }

            return LookupResult::found(self::KEY, $company);
        });
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        $name = trim($name);

        if (strtoupper($country) !== 'CZ' || $name === '' || $limit < 1) {
            return LookupResult::notFound(self::KEY);
        }

        return $this->guard(function () use ($name, $limit): LookupResult {
            $response = $this->request()
                ->acceptJson()
                ->post($this->baseUrl(self::DEFAULT_BASE_URL).'/ekonomicke-subjekty/vyhledat', [
                    'obchodniJmeno' => $name,
                    'start' => 0,
                    'pocet' => min($limit, self::MAX_PAGE_SIZE),
                ]);

            if (! $response->successful()) {
                return $this->failure($response, Data::string(Data::json($response->body()), 'popis'));
            }

            $companies = array_map(fn (array $entity): Company => $this->company($entity), Data::list($this->json($response), 'ekonomickeSubjekty'));

            return LookupResult::found(self::KEY, ...$companies);
        });
    }

    /**
     * The 8-digit IČO, or null when the identifier cannot be one.
     * A DIČ of a natural person carries a birth number instead and has no IČO.
     */
    private function ico(Identifier $identifier): ?string
    {
        $digits = Normalize::digits(Normalize::withoutPrefix('CZ', $identifier->value));

        if ($digits === '') {
            return null;
        }

        if ($identifier->type === IdentifierType::Vat) {
            return strlen($digits) === 8 ? $digits : null;
        }

        if ($identifier->type !== IdentifierType::Registration || strlen($digits) > 8) {
            return null;
        }

        return str_pad($digits, 8, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<array-key, mixed>  $entity  One ekonomicky-subjekt record.
     */
    private function company(array $entity): Company
    {
        $identifiers = [];
        $ico = Data::string($entity, 'ico');
        if ($ico !== null) {
            $identifiers[] = Identifier::registration($ico, 'CZ-ICO');
        }
        $dic = Data::string($entity, 'dic');
        if ($dic !== null) {
            $identifiers[] = Identifier::vat($dic);
        }

        $activities = [];
        foreach (Data::map($entity, 'czNace2008') as $code) {
            $code = Data::clean($code);
            // "00" is ARES's placeholder for an unspecified activity.
            if ($code !== null && $code !== '00') {
                $activities[] = new ActivityCode('NACE', $code);
            }
        }

        return new Company(
            name: Data::string($entity, 'obchodniJmeno'),
            country: 'CZ',
            source: self::KEY,
            identifiers: $identifiers,
            address: $this->address(Data::map($entity, 'sidlo')),
            status: $this->status($entity),
            legalFormCode: Data::string($entity, 'pravniForma'),
            activities: $activities,
            foundedAt: Data::date(Data::string($entity, 'datumVzniku')),
            vatValid: $dic !== null && Data::string($entity, 'seznamRegistraci.stavZdrojeDph') === 'AKTIVNI',
            raw: $entity,
        );
    }

    /**
     * @param  array<array-key, mixed>  $seat
     */
    private function address(array $seat): ?Address
    {
        if ($seat === []) {
            return null;
        }

        $number = Data::string($seat, 'cisloDomovni');
        $orientation = Data::string($seat, 'cisloOrientacni');
        if ($orientation !== null) {
            $orientation .= Data::string($seat, 'cisloOrientacniPismeno') ?? '';
            $number = $number === null ? $orientation : $number.'/'.$orientation;
        }

        $streetName = Data::string($seat, 'nazevUlice') ?? Data::string($seat, 'nazevCastiObce');
        $street = trim(implode(' ', array_filter([$streetName, $number], fn (?string $part): bool => $part !== null)));

        $address = new Address(
            street: $street === '' ? null : $street,
            postalCode: Data::string($seat, 'psc'),
            city: Data::string($seat, 'nazevObce'),
            region: Data::string($seat, 'nazevKraje'),
            country: Data::string($seat, 'kodStatu'),
            freeText: Data::string($seat, 'textovaAdresa'),
        );

        return $address->format() === null ? null : $address;
    }

    /**
     * Status in the commercial register, or in the basic register (ROS) for entities outside it.
     *
     * @param  array<array-key, mixed>  $entity
     */
    private function status(array $entity): CompanyStatus
    {
        if (Data::string($entity, 'datumZaniku') !== null) {
            return CompanyStatus::Dissolved;
        }

        $state = Data::string($entity, 'seznamRegistraci.stavZdrojeVr');
        if ($state === null || $state === 'NEEXISTUJICI') {
            $state = Data::string($entity, 'seznamRegistraci.stavZdrojeRos');
        }

        return match ($state) {
            'AKTIVNI' => CompanyStatus::Active,
            'ZANIKLY', 'HISTORICKY' => CompanyStatus::Dissolved,
            default => CompanyStatus::Unknown,
        };
    }
}
