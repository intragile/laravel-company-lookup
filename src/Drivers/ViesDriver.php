<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use IntrAgile\CompanyLookup\Address;
use IntrAgile\CompanyLookup\Capabilities;
use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\Drivers\Support\Data;
use IntrAgile\CompanyLookup\Drivers\Support\Normalize;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupResult;

/**
 * EU VIES VAT number validation (REST API). Free, no key.
 *
 * Germany and Spain confirm validity but never disclose name or address ("---").
 */
final class ViesDriver extends AbstractHttpDriver
{
    public const string KEY = 'vies';

    private const COUNTRIES = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'GR', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI'];

    /** userError values that mean "try again later", not "invalid". */
    private const RETRYABLE = ['MS_UNAVAILABLE', 'MS_MAX_CONCURRENT_REQ', 'GLOBAL_MAX_CONCURRENT_REQ', 'SERVICE_UNAVAILABLE', 'TIMEOUT'];

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'EU VIES',
            countries: self::COUNTRIES,
            identifierTypes: [IdentifierType::Vat],
            nameSearch: false,
            registryUrl: 'https://ec.europa.eu/taxation_customs/vies/',
            sample: 'CZ45274649',
            sampleCountry: 'CZ',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $country = strtoupper($country);
        $number = Normalize::withoutPrefix($country, $identifier->value);

        if ($number === '' || ! in_array($country, self::COUNTRIES, true)) {
            return LookupResult::notFound(self::KEY);
        }

        $prefix = Normalize::vatPrefix($country);

        return $this->guard(function () use ($country, $prefix, $number): LookupResult {
            $response = $this->request()
                ->acceptJson()
                ->get($this->baseUrl('https://ec.europa.eu/taxation_customs/vies/rest-api').'/ms/'.$prefix.'/vat/'.rawurlencode($number));

            if (! $response->successful()) {
                return $this->failure($response);
            }

            $data = $this->json($response);
            $error = Data::string($data, 'userError') ?? 'VALID';

            if (in_array($error, self::RETRYABLE, true)) {
                return LookupResult::failed(self::KEY, $error === 'MS_UNAVAILABLE' ? FailureReason::Unavailable : FailureReason::RateLimited, true, $error);
            }

            if ($error === 'INVALID_INPUT' || Data::bool($data, 'isValid') !== true) {
                return LookupResult::notFound(self::KEY);
            }

            $address = $this->disclosed(Data::string($data, 'address'));

            return LookupResult::found(self::KEY, new Company(
                name: $this->disclosed(Data::string($data, 'name')),
                country: $country,
                source: self::KEY,
                identifiers: [Identifier::vat($prefix.$number)],
                address: $address === null ? null : new Address(country: $country, freeText: $this->oneLine($address)),
                vatValid: true,
                raw: $data,
            ));
        });
    }

    /**
     * VIES uses "---" for data a member state does not disclose.
     */
    private function disclosed(?string $value): ?string
    {
        return $value === null || $value === '---' ? null : $value;
    }

    private function oneLine(string $address): string
    {
        $lines = array_filter(array_map(fn (string $line): string => trim((string) preg_replace('/\s+/', ' ', $line)), preg_split('/\R/', $address) ?: []));

        return implode(', ', $lines);
    }
}
