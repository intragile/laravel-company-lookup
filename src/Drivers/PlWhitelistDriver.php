<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use DateTimeImmutable;
use DateTimeZone;
use IntrAgile\CompanyLookup\Address;
use IntrAgile\CompanyLookup\Capabilities;
use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\Drivers\Support\Data;
use IntrAgile\CompanyLookup\Drivers\Support\Normalize;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupResult;

/**
 * Polish VAT taxpayer white list (Biała lista, Ministry of Finance). Free, no key.
 *
 * Looks up by NIP. The service allows about 100 searches per day per IP address.
 * Bank account numbers are left in the raw payload only.
 */
final class PlWhitelistDriver extends AbstractHttpDriver
{
    public const string KEY = 'pl-whitelist';

    private const DEFAULT_BASE_URL = 'https://wl-api.mf.gov.pl/api';

    /** NIP check digit weights. */
    private const WEIGHTS = [6, 5, 7, 2, 3, 4, 5, 6, 7];

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'Biała lista VAT (PL)',
            countries: ['PL'],
            identifierTypes: [IdentifierType::Tax, IdentifierType::Vat],
            nameSearch: false,
            registryUrl: 'https://www.podatki.gov.pl/wykaz-podatnikow-vat-wyszukiwarka',
            sample: '7740001454',
            sampleCountry: 'PL',
        );
    }

    /**
     * The quota is per day, so an immediate retry after HTTP 429 would only be refused again.
     */
    protected function retriesOnRateLimit(): bool
    {
        return false;
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $nip = Normalize::withoutPrefix('PL', $identifier->value);

        if (strtoupper($country) !== 'PL' || ! in_array($identifier->type, [IdentifierType::Tax, IdentifierType::Vat], true) || ! $this->isValidNip($nip)) {
            return LookupResult::notFound(self::KEY);
        }

        // The service rejects future dates, so "today" is taken in Polish time.
        $date = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d');

        $vatLookup = $identifier->type === IdentifierType::Vat;

        return $this->guard(function () use ($nip, $date, $vatLookup): LookupResult {
            $response = $this->request()
                ->acceptJson()
                ->get($this->baseUrl(self::DEFAULT_BASE_URL).'/search/nip/'.$nip, ['date' => $date]);

            if ($response->status() === 400) {
                $body = Data::json($response->body());

                // WL-11x codes are input errors about the NIP itself.
                return str_starts_with(Data::string($body, 'code') ?? '', 'WL-11')
                    ? LookupResult::notFound(self::KEY)
                    : $this->failure($response, Data::string($body, 'message'));
            }

            if (! $response->successful()) {
                return $this->failure($response);
            }

            $result = Data::map($this->json($response), 'result');
            $subject = Data::map($result, 'subject');

            // A VAT number only counts as found while its holder is an active VAT payer.
            if ($subject === [] || ($vatLookup && Data::string($subject, 'statusVat') !== 'Czynny')) {
                return LookupResult::notFound(self::KEY);
            }

            return LookupResult::found(self::KEY, $this->company($subject, $result));
        });
    }

    private function isValidNip(string $nip): bool
    {
        if (preg_match('/^\d{10}$/', $nip) !== 1) {
            return false;
        }

        $sum = 0;
        foreach (self::WEIGHTS as $position => $weight) {
            $sum += (int) $nip[$position] * $weight;
        }

        return $sum % 11 === (int) $nip[9];
    }

    /**
     * @param  array<array-key, mixed>  $subject
     * @param  array<array-key, mixed>  $result  The whole result, kept as raw data.
     */
    private function company(array $subject, array $result): Company
    {
        $nip = Data::string($subject, 'nip');
        $status = Data::string($subject, 'statusVat');

        $identifiers = [];
        if ($nip !== null) {
            $identifiers[] = Identifier::tax($nip, 'PL-NIP');
            if ($status === 'Czynny') {
                $identifiers[] = Identifier::vat('PL'.$nip);
            }
        }
        foreach (['regon' => 'PL-REGON', 'krs' => 'PL-KRS'] as $field => $scheme) {
            $value = Data::string($subject, $field);
            if ($value !== null) {
                $identifiers[] = Identifier::registration($value, $scheme);
            }
        }

        $address = Data::string($subject, 'residenceAddress') ?? Data::string($subject, 'workingAddress');

        return new Company(
            name: Data::string($subject, 'name'),
            country: 'PL',
            source: self::KEY,
            identifiers: $identifiers,
            address: $address === null ? null : new Address(country: 'PL', freeText: $address),
            // "Czynny" is an active VAT payer; "Zwolniony" (exempt) and "Niezarejestrowany" are not.
            vatValid: $status === null ? null : $status === 'Czynny',
            raw: $result,
        );
    }
}
