<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use DateTimeImmutable;
use DateTimeZone;
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
 * Romanian ANAF taxpayer register (PlatitorTvaRest v9). Free, no key, about one request per second.
 *
 * Looks up by CUI (fiscal code), with or without the RO prefix.
 */
final class AnafDriver extends AbstractHttpDriver
{
    public const string KEY = 'anaf';

    private const DEFAULT_BASE_URL = 'https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9';

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'ANAF (RO)',
            countries: ['RO'],
            identifierTypes: [IdentifierType::Vat, IdentifierType::Tax],
            nameSearch: false,
            registryUrl: 'https://www.anaf.ro/RegistruTVA/',
            sample: '1590082',
            sampleCountry: 'RO',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $cui = Normalize::withoutPrefix('RO', $identifier->value);

        if (strtoupper($country) !== 'RO' || ! in_array($identifier->type, [IdentifierType::Vat, IdentifierType::Tax], true) || preg_match('/^\d{2,10}$/', $cui) !== 1) {
            return LookupResult::notFound(self::KEY);
        }

        $date = (new DateTimeImmutable('now', new DateTimeZone('Europe/Bucharest')))->format('Y-m-d');

        $vatLookup = $identifier->type === IdentifierType::Vat;

        return $this->guard(function () use ($cui, $date, $vatLookup): LookupResult {
            $response = $this->request()
                ->acceptJson()
                ->post($this->baseUrl(self::DEFAULT_BASE_URL).'/tva', [['cui' => (int) $cui, 'data' => $date]]);

            // An unknown CUI comes back as HTTP 404 with the code in the "notFound" list.
            if ($response->status() === 404 && Data::map(Data::json($response->body()), 'notFound') !== []) {
                return LookupResult::notFound(self::KEY);
            }

            if (! $response->successful()) {
                return $this->failure($response);
            }

            $record = Data::list($this->json($response), 'found')[0] ?? null;

            // A VAT number only counts as found while its holder is registered for VAT.
            if ($record === null || ($vatLookup && Data::bool($record, 'inregistrare_scop_Tva.scpTVA') !== true)) {
                return LookupResult::notFound(self::KEY);
            }

            return LookupResult::found(self::KEY, $this->company($record));
        });
    }

    /**
     * @param  array<array-key, mixed>  $record  One entry of the "found" list.
     */
    private function company(array $record): Company
    {
        $general = Data::map($record, 'date_generale');
        $cui = Data::string($general, 'cui');
        $vatPayer = Data::bool($record, 'inregistrare_scop_Tva.scpTVA');

        $identifiers = [];
        if ($cui !== null) {
            $identifiers[] = Identifier::tax($cui, 'RO-CUI');
            if ($vatPayer === true) {
                $identifiers[] = Identifier::vat('RO'.$cui);
            }
        }
        $tradeRegister = Data::string($general, 'nrRegCom');
        if ($tradeRegister !== null) {
            $identifiers[] = Identifier::registration($tradeRegister, 'RO-ONRC');
        }

        // CAEN codes are four digits; the service drops the leading zero.
        $caen = Data::string($general, 'cod_CAEN');

        return new Company(
            name: Data::string($general, 'denumire'),
            country: 'RO',
            source: self::KEY,
            identifiers: $identifiers,
            address: $this->address(Data::map($record, 'adresa_sediu_social'), Data::string($general, 'adresa')),
            status: $this->status($record),
            legalForm: Data::string($general, 'forma_juridica'),
            activities: $caen === null ? [] : [new ActivityCode('CAEN', str_pad($caen, 4, '0', STR_PAD_LEFT))],
            foundedAt: Data::date(Data::string($general, 'data_inregistrare')),
            vatValid: $vatPayer,
            raw: $record,
        );
    }

    /**
     * @param  array<array-key, mixed>  $seat  The registered office (adresa_sediu_social).
     */
    private function address(array $seat, ?string $fallback): ?Address
    {
        $street = trim(implode(' ', array_filter([Data::string($seat, 'sdenumire_Strada'), Data::string($seat, 'snumar_Strada'), Data::string($seat, 'sdetalii_Adresa')], fn (?string $part): bool => $part !== null)));

        $address = new Address(
            street: $street === '' ? null : $street,
            postalCode: Data::string($seat, 'scod_Postal'),
            city: Data::string($seat, 'sdenumire_Localitate'),
            region: Data::string($seat, 'sdenumire_Judet'),
            country: 'RO',
        );

        if ($street === '' && $address->city === null) {
            return $fallback === null ? null : new Address(country: 'RO', freeText: $fallback);
        }

        return $address;
    }

    /**
     * @param  array<array-key, mixed>  $record
     */
    private function status(array $record): CompanyStatus
    {
        $registration = strtoupper(Data::string($record, 'date_generale.stare_inregistrare') ?? '');

        if (Data::string($record, 'stare_inactiv.dataRadiere') !== null || str_starts_with($registration, 'RADIERE')) {
            return CompanyStatus::Dissolved;
        }

        if (Data::bool($record, 'stare_inactiv.statusInactivi') === true) {
            return CompanyStatus::Inactive;
        }

        return $registration === '' ? CompanyStatus::Unknown : CompanyStatus::Active;
    }
}
