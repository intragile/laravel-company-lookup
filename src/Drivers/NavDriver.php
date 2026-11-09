<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use Illuminate\Container\Container;
use IntrAgile\CompanyLookup\Address;
use IntrAgile\CompanyLookup\Capabilities;
use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\Drivers\Support\Normalize;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupResult;
use IntrAgile\NavOnlineSzamla\Exceptions\ConfigurationException;
use IntrAgile\NavOnlineSzamla\Exceptions\NavApiException;
use IntrAgile\NavOnlineSzamla\Exceptions\TransportException;
use IntrAgile\NavOnlineSzamla\NavManager;
use IntrAgile\NavOnlineSzamla\Responses\Taxpayer;
use IntrAgile\NavOnlineSzamla\Responses\TaxpayerAddress;
use IntrAgile\NavOnlineSzamla\Testing\NavFake;
use Throwable;

/**
 * Hungarian tax authority (NAV) taxpayer query, through the optional
 * intragile/laravel-nav-online-szamla package and the user's own technical user.
 *
 * The NAV package is not a dependency. Its classes are only referenced through ::class,
 * instanceof and catch, none of which autoload, and are used only after class_exists().
 */
final class NavDriver extends AbstractHttpDriver
{
    public const string KEY = 'nav';

    private const PACKAGE = 'intragile/laravel-nav-online-szamla';

    /** Environment variables read by the NAV package's default configuration. */
    private const NAV_ENV = [
        'nav-online-szamla.credentials.login' => 'NAV_USERNAME',
        'nav-online-szamla.credentials.password' => 'NAV_PASSWORD',
        'nav-online-szamla.credentials.tax_number' => 'NAV_TAX_NUMBER',
        'nav-online-szamla.credentials.sign_key' => 'NAV_SIGN_KEY',
        'nav-online-szamla.credentials.exchange_key' => 'NAV_EXCHANGE_KEY',
        'nav-online-szamla.software.id' => 'NAV_SOFTWARE_ID',
        'nav-online-szamla.software.dev_name' => 'NAV_SOFTWARE_DEV_NAME',
        'nav-online-szamla.software.dev_contact' => 'NAV_SOFTWARE_DEV_CONTACT',
    ];

    private const LEGAL_FORMS = [
        'ORGANIZATION' => 'Organisation',
        'SELF_EMPLOYED' => 'Self-employed individual',
        'TAXABLE_PERSON' => 'Other taxable person',
    ];

    public function capabilities(): Capabilities
    {
        $credentials = self::NAV_ENV;
        if (! $this->packageInstalled()) {
            $credentials = ['package' => 'composer require '.self::PACKAGE] + $credentials;
        }

        return new Capabilities(
            key: self::KEY,
            label: 'NAV Online Számla taxpayer query',
            countries: ['HU'],
            identifierTypes: [IdentifierType::Tax, IdentifierType::Vat],
            nameSearch: false,
            credentials: $credentials,
            registryUrl: 'https://onlineszamla.nav.gov.hu/',
            sample: '10773381',
            sampleCountry: 'HU',
        );
    }

    /**
     * True when the NAV package is installed and a client can be built for the selected
     * connection, which validates its credentials and the software settings without a request.
     * A faked NAV (Nav::fake()) needs no credentials.
     */
    public function isConfigured(): bool
    {
        if (! $this->packageInstalled()) {
            return false;
        }

        $container = Container::getInstance();
        if (! $container->bound(NavManager::class)) {
            return false;
        }

        try {
            $manager = $container->make(NavManager::class);
            if ($manager instanceof NavFake) {
                return true;
            }

            $manager->for($this->setting('connection'));
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $stem = $this->taxpayerId(strtoupper($country), $identifier);

        if ($stem === null) {
            return LookupResult::notFound(self::KEY);
        }

        if (! $this->packageInstalled()) {
            return LookupResult::unconfigured(self::KEY, 'composer require '.self::PACKAGE);
        }

        try {
            $taxpayer = Container::getInstance()->make(NavManager::class)->for($this->setting('connection'))->queryTaxpayer($stem);
        } catch (Throwable $exception) {
            return $this->navFailure($exception);
        }

        return $taxpayer === null
            ? LookupResult::notFound(self::KEY)
            : LookupResult::found(self::KEY, $this->company($taxpayer));
    }

    private function packageInstalled(): bool
    {
        return class_exists(NavManager::class);
    }

    /**
     * The 8-digit taxpayer ID from an 8- or 11-digit tax number or a HU VAT number.
     */
    private function taxpayerId(string $country, Identifier $identifier): ?string
    {
        if ($country !== 'HU' || ! in_array($identifier->type, [IdentifierType::Tax, IdentifierType::Vat], true)) {
            return null;
        }

        $digits = Normalize::digits(Normalize::withoutPrefix('HU', $identifier->value));

        return in_array(strlen($digits), [8, 11], true) ? substr($digits, 0, 8) : null;
    }

    private function navFailure(Throwable $exception): LookupResult
    {
        $message = AbstractHttpDriver::redact($exception->getMessage());

        return match (true) {
            $exception instanceof TransportException => LookupResult::failed(self::KEY, FailureReason::Timeout, true, $message),
            $exception instanceof NavApiException && $exception->isAuthenticationError() => LookupResult::failed(self::KEY, FailureReason::Rejected, false, $message),
            $exception instanceof NavApiException && $exception->isRetryable() => LookupResult::failed(self::KEY, FailureReason::Unavailable, true, $message),
            $exception instanceof NavApiException => LookupResult::failed(self::KEY, FailureReason::Rejected, false, $message),
            $exception instanceof ConfigurationException => LookupResult::failed(self::KEY, FailureReason::Rejected, false, $message),
            default => LookupResult::failed(self::KEY, FailureReason::InvalidResponse, false, $message),
        };
    }

    private function company(Taxpayer $taxpayer): Company
    {
        $incorporation = $taxpayer->incorporation->value;

        return new Company(
            name: $taxpayer->name,
            country: 'HU',
            source: self::KEY,
            identifiers: [
                Identifier::tax($taxpayer->taxNumber(), 'HU-ADOSZAM'),
                Identifier::vat('HU'.$taxpayer->taxpayerId),
            ],
            address: $this->address($taxpayer->headquarters()),
            status: $taxpayer->valid ? CompanyStatus::Active : CompanyStatus::Inactive,
            legalForm: self::LEGAL_FORMS[$incorporation],
            legalFormCode: $incorporation,
            vatValid: $this->vatValid($taxpayer),
            raw: [
                'valid' => $taxpayer->valid,
                'info_date' => $taxpayer->infoDate?->format('Y-m-d'),
                'name' => $taxpayer->name,
                'short_name' => $taxpayer->shortName,
                'tax_number' => $taxpayer->taxNumber(),
                'vat_code' => $taxpayer->vatCode,
                'county_code' => $taxpayer->countyCode,
                'incorporation' => $incorporation,
                'vat_group_membership' => $taxpayer->vatGroupMembership,
            ],
        );
    }

    /**
     * VAT code 1 marks a subjectively exempt taxpayer that charges no VAT. Code 4 marks a
     * VAT group member, which uses the group's VAT number rather than its own, so the
     * taxpayer's own number is neither confirmed nor ruled out.
     */
    private function vatValid(Taxpayer $taxpayer): ?bool
    {
        if (! $taxpayer->valid || $taxpayer->vatCode === '1') {
            return false;
        }

        return $taxpayer->vatCode === '4' ? null : true;
    }

    private function address(?TaxpayerAddress $address): ?Address
    {
        if ($address === null) {
            return null;
        }

        $parts = [
            $address->streetName,
            $address->publicPlaceCategory,
            $address->number,
            $address->building,
            $address->staircase,
            $address->floor,
            $address->door,
        ];
        $street = implode(' ', array_filter(array_map(fn (?string $part): string => trim((string) $part), $parts), fn (string $part): bool => $part !== ''));

        if ($street === '' && $address->lotNumber !== null) {
            $street = 'hrsz. '.$address->lotNumber;
        }

        return new Address(
            street: $street === '' ? null : $street,
            postalCode: $address->postalCode,
            city: $address->city,
            region: $address->region,
            country: $address->countryCode,
        );
    }
}
