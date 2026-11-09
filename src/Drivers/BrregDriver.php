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
 * Norwegian Central Coordinating Register for Legal Entities (Enhetsregisteret, Brønnøysundregistrene). Free, no key.
 *
 * Only main entities (enheter) are covered, not sub-units (underenheter).
 * A VAT lookup is found only when the entity is registered in the VAT register (Merverdiavgiftsregisteret).
 */
final class BrregDriver extends AbstractHttpDriver
{
    public const string KEY = 'brreg';

    /** The API pages at most 10,000 rows; one page of search results is plenty. */
    private const MAX_PAGE_SIZE = 100;

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'Norway – Brønnøysund Register Centre',
            countries: ['NO'],
            identifierTypes: [IdentifierType::Registration, IdentifierType::Vat],
            nameSearch: true,
            attribution: 'Contains data under the Norwegian licence for Open Government data (NLOD) made available by Brønnøysundregistrene',
            registryUrl: 'https://www.brreg.no/',
            sample: '923609016',
            sampleCountry: 'NO',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $number = $this->organisationNumber($identifier->value);

        if (strtoupper($country) !== 'NO' || $number === null) {
            return LookupResult::notFound(self::KEY);
        }

        $vat = $identifier->type === IdentifierType::Vat;

        return $this->guard(function () use ($number, $vat): LookupResult {
            $response = $this->request()
                ->acceptJson()
                ->get($this->baseUrl('https://data.brreg.no/enhetsregisteret/api').'/enheter/'.$number);

            // 404: never existed; 410: removed from the public register for legal reasons.
            if (in_array($response->status(), [404, 410], true)) {
                return LookupResult::notFound(self::KEY);
            }

            if (! $response->successful()) {
                return $this->failure($response);
            }

            $company = $this->company($this->json($response));

            if ($vat && $company->vatValid !== true) {
                return LookupResult::notFound(self::KEY);
            }

            return LookupResult::found(self::KEY, $company);
        });
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        $name = trim($name);

        if (strtoupper($country) !== 'NO' || $name === '') {
            return LookupResult::notFound(self::KEY);
        }

        return $this->guard(function () use ($name, $limit): LookupResult {
            $response = $this->request()
                ->acceptJson()
                ->get($this->baseUrl('https://data.brreg.no/enhetsregisteret/api').'/enheter', [
                    'navn' => $name,
                    'size' => max(1, min(self::MAX_PAGE_SIZE, $limit)),
                ]);

            if (! $response->successful()) {
                return $this->failure($response);
            }

            $companies = array_map(fn (array $entity): Company => $this->company($entity), Data::list($this->json($response), '_embedded.enheter'));

            return LookupResult::found(self::KEY, ...array_slice($companies, 0, max(1, $limit)));
        });
    }

    /**
     * The nine-digit organisation number from "923 609 016", "NO923609016MVA" and similar, or null.
     */
    private function organisationNumber(string $value): ?string
    {
        $number = Normalize::withoutPrefix('NO', $value);
        if (str_ends_with($number, 'MVA')) {
            $number = substr($number, 0, -3);
        }

        return preg_match('/^\d{9}$/', $number) === 1 ? $number : null;
    }

    /**
     * @param  array<array-key, mixed>  $entity
     */
    private function company(array $entity): Company
    {
        $number = Data::string($entity, 'organisasjonsnummer') ?? '';
        $vatValid = Data::bool($entity, 'registrertIMvaregisteret');

        $identifiers = [Identifier::registration($number, 'NO-ORGNR')];
        if ($vatValid === true) {
            $identifiers[] = Identifier::vat('NO'.$number.'MVA');
        }

        $activities = [];
        foreach (['naeringskode1', 'naeringskode2', 'naeringskode3'] as $field) {
            $code = Data::string($entity, $field.'.kode');
            if ($code !== null) {
                $activities[] = new ActivityCode('NACE', $code, Data::string($entity, $field.'.beskrivelse'));
            }
        }

        return new Company(
            name: Data::string($entity, 'navn'),
            country: 'NO',
            source: self::KEY,
            identifiers: $identifiers,
            address: $this->address(Data::map($entity, 'forretningsadresse')) ?? $this->address(Data::map($entity, 'postadresse')),
            status: $this->status($entity),
            legalForm: Data::string($entity, 'organisasjonsform.beskrivelse'),
            legalFormCode: Data::string($entity, 'organisasjonsform.kode'),
            activities: $activities,
            foundedAt: Data::date(Data::string($entity, 'stiftelsesdato') ?? Data::string($entity, 'registreringsdatoEnhetsregisteret')),
            vatValid: $vatValid,
            raw: $entity,
        );
    }

    /**
     * @param  array<array-key, mixed>  $entity
     */
    private function status(array $entity): CompanyStatus
    {
        if (Data::string($entity, 'slettedato') !== null) {
            return CompanyStatus::Dissolved;
        }

        if (Data::bool($entity, 'konkurs') === true
            || Data::bool($entity, 'underAvvikling') === true
            || Data::bool($entity, 'underTvangsavviklingEllerTvangsopplosning') === true) {
            return CompanyStatus::Inactive;
        }

        return Data::bool($entity, 'konkurs') === null ? CompanyStatus::Unknown : CompanyStatus::Active;
    }

    /**
     * @param  array<array-key, mixed>  $address
     */
    private function address(array $address): ?Address
    {
        if ($address === []) {
            return null;
        }

        $lines = array_values(array_filter(array_map(Data::clean(...), Data::map($address, 'adresse')), fn (?string $line): bool => $line !== null));

        return new Address(
            street: $lines === [] ? null : implode(', ', $lines),
            postalCode: Data::string($address, 'postnummer'),
            city: Data::string($address, 'poststed'),
            country: Data::string($address, 'landkode'),
        );
    }
}
