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
 * Polish National Court Register (KRS) open API, current extract. Free, no key.
 *
 * Entrepreneurs are in register P, associations and foundations in register S; both are tried.
 * Deleted entities have no current extract and are reported as not found.
 */
final class KrsDriver extends AbstractHttpDriver
{
    public const string KEY = 'krs';

    private const DEFAULT_BASE_URL = 'https://api-krs.ms.gov.pl/api/krs';

    private const REGISTERS = ['P', 'S'];

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'KRS (PL)',
            countries: ['PL'],
            identifierTypes: [IdentifierType::Registration],
            nameSearch: false,
            registryUrl: 'https://wyszukiwarka-krs.ms.gov.pl/',
            sample: '0000028860',
            sampleCountry: 'PL',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $digits = Normalize::digits(Normalize::withoutPrefix('PL', $identifier->value));

        if (strtoupper($country) !== 'PL' || $identifier->type !== IdentifierType::Registration || $digits === '' || strlen($digits) > 10) {
            return LookupResult::notFound(self::KEY);
        }

        $number = str_pad($digits, 10, '0', STR_PAD_LEFT);

        return $this->guard(function () use ($number): LookupResult {
            foreach (self::REGISTERS as $register) {
                $response = $this->request()
                    ->acceptJson()
                    ->get($this->baseUrl(self::DEFAULT_BASE_URL).'/OdpisAktualny/'.$number, ['rejestr' => $register, 'format' => 'json']);

                if ($response->status() === 204 || $response->status() === 404) {
                    continue;
                }

                if (! $response->successful()) {
                    return $this->failure($response);
                }

                $extract = Data::map($this->json($response), 'odpis');

                return $extract === [] ? LookupResult::notFound(self::KEY) : LookupResult::found(self::KEY, $this->company($number, $extract));
            }

            return LookupResult::notFound(self::KEY);
        });
    }

    /**
     * @param  array<array-key, mixed>  $extract  The "odpis" object.
     */
    private function company(string $number, array $extract): Company
    {
        $subject = Data::map($extract, 'dane.dzial1.danePodmiotu');

        $identifiers = [Identifier::registration($number, 'PL-KRS')];
        $nip = Data::string($subject, 'identyfikatory.nip');
        if ($nip !== null) {
            $identifiers[] = Identifier::tax($nip, 'PL-NIP');
        }
        $regon = Data::string($subject, 'identyfikatory.regon');
        if ($regon !== null) {
            // KRS stores the 9-digit REGON padded to the 14-digit local-unit form.
            $identifiers[] = Identifier::registration(strlen($regon) === 14 && str_ends_with($regon, '00000') ? substr($regon, 0, 9) : $regon, 'PL-REGON');
        }

        return new Company(
            name: Data::string($subject, 'nazwa'),
            country: 'PL',
            source: self::KEY,
            identifiers: $identifiers,
            address: $this->address(Data::map($extract, 'dane.dzial1.siedzibaIAdres')),
            status: $this->status(Data::map($extract, 'dane.dzial6')),
            legalForm: Data::string($subject, 'formaPrawna'),
            activities: $this->activities(Data::map($extract, 'dane.dzial3.przedmiotDzialalnosci')),
            raw: $extract,
        );
    }

    /**
     * @param  array<array-key, mixed>  $seat
     */
    private function address(array $seat): ?Address
    {
        $street = Data::string($seat, 'adres.ulica');
        $number = Data::string($seat, 'adres.nrDomu');
        $unit = Data::string($seat, 'adres.nrLokalu');
        if ($number !== null && $unit !== null) {
            $number .= '/'.$unit;
        }
        $line = trim(implode(' ', array_filter([$street, $number], fn (?string $part): bool => $part !== null)));

        $address = new Address(
            street: $line === '' ? null : $line,
            postalCode: Data::string($seat, 'adres.kodPocztowy'),
            city: Data::string($seat, 'adres.miejscowosc') ?? Data::string($seat, 'siedziba.miejscowosc'),
            region: Data::string($seat, 'siedziba.wojewodztwo'),
            country: 'PL',
        );

        return $line === '' && $address->city === null ? null : $address;
    }

    /**
     * PKD codes: the main activity first, then the others.
     *
     * @param  array<array-key, mixed>  $activities
     * @return list<ActivityCode>
     */
    private function activities(array $activities): array
    {
        $codes = [];
        foreach (['przedmiotPrzewazajacejDzialalnosci', 'przedmiotPozostalejDzialalnosci'] as $group) {
            foreach (Data::list($activities, $group) as $item) {
                $parts = array_filter([Data::string($item, 'kodDzial'), Data::string($item, 'kodKlasa'), Data::string($item, 'kodPodklasa')], fn (?string $part): bool => $part !== null);
                if ($parts !== []) {
                    $codes[] = new ActivityCode('PKD', implode('.', $parts), Data::string($item, 'opis'));
                }
            }
        }

        return $codes;
    }

    /**
     * An entity with a current extract is registered; an entry under "likwidacja" in section 6
     * means it is being wound up.
     *
     * @param  array<array-key, mixed>  $section6
     */
    private function status(array $section6): CompanyStatus
    {
        return Data::list($section6, 'likwidacja') === [] ? CompanyStatus::Active : CompanyStatus::Inactive;
    }
}
