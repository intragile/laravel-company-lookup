<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\CompanyLookup;
use IntrAgile\CompanyLookup\Drivers\NavDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\TestCase;
use IntrAgile\NavOnlineSzamla\Enums\Incorporation;
use IntrAgile\NavOnlineSzamla\Enums\TaxpayerAddressType;
use IntrAgile\NavOnlineSzamla\Facades\Nav;
use IntrAgile\NavOnlineSzamla\NavOnlineSzamlaServiceProvider;
use IntrAgile\NavOnlineSzamla\Responses\Taxpayer;
use IntrAgile\NavOnlineSzamla\Responses\TaxpayerAddress;
use IntrAgile\NavOnlineSzamla\Testing\NavFake;
use IntrAgile\NavOnlineSzamla\Testing\RecordedCall;

/**
 * Uses the NAV package's own in-memory fake; nothing is sent over HTTP.
 * The taxpayer data follows the QueryTaxpayerResponse example of the NAV Online Számla
 * interface specification (from the documentation, not a live response).
 */
final class NavDriverTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), NavOnlineSzamlaServiceProvider::class];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function driver(array $config = []): NavDriver
    {
        return new NavDriver($this->container()->make(Factory::class), $config);
    }

    private function configureCredentials(string $key = 'nav-online-szamla.credentials'): void
    {
        $this->container()->make('config')->set($key, [
            'login' => 'techuser01',
            'password' => 'secret',
            'tax_number' => '12345678',
            'sign_key' => 'sign-key',
            'exchange_key' => '0123456789abcdef',
        ]);
    }

    private function configureSoftware(): void
    {
        $config = $this->container()->make('config');
        $config->set('nav-online-szamla.software.id', 'HU12345678-0000001');
        $config->set('nav-online-szamla.software.dev_name', 'Example Kft.');
        $config->set('nav-online-szamla.software.dev_contact', 'dev@example.com');
    }

    private function taxpayer(bool $valid = true, string $vatCode = '2'): Taxpayer
    {
        return new Taxpayer(
            valid: $valid,
            infoDate: new DateTimeImmutable('2026-11-09'),
            name: 'MAGYAR TELEKOM TÁVKÖZLÉSI NYILVÁNOSAN MŰKÖDŐ RÉSZVÉNYTÁRSASÁG',
            shortName: 'MAGYAR TELEKOM NYRT.',
            taxpayerId: '10773381',
            vatCode: $vatCode,
            countyCode: '44',
            incorporation: Incorporation::Organization,
            vatGroupMembership: null,
            addresses: [
                new TaxpayerAddress(TaxpayerAddressType::Site, 'HU', null, '1097', 'BUDAPEST', 'KÖNYVES KÁLMÁN', 'KÖRÚT', '36.'),
                new TaxpayerAddress(TaxpayerAddressType::Hq, 'HU', 'BUDAPEST', '1097', 'BUDAPEST', 'KÖNYVES KÁLMÁN', 'KÖRÚT', '36.'),
            ],
        );
    }

    public function test_found_taxpayer_is_mapped(): void
    {
        Http::fake();
        Nav::fake()->taxpayer('10773381', $this->taxpayer());

        $company = $this->driver()->lookup('HU', Identifier::tax('10773381-2-44'))->first();

        self::assertNotNull($company);
        self::assertSame('MAGYAR TELEKOM TÁVKÖZLÉSI NYILVÁNOSAN MŰKÖDŐ RÉSZVÉNYTÁRSASÁG', $company->name);
        self::assertSame('HU', $company->country);
        self::assertSame('nav', $company->source);
        self::assertSame('10773381-2-44', $company->identifier(IdentifierType::Tax, 'HU-ADOSZAM'));
        self::assertSame('HU10773381', $company->identifier(IdentifierType::Vat));
        self::assertSame('KÖNYVES KÁLMÁN KÖRÚT 36.', $company->address?->street);
        self::assertSame('1097', $company->address->postalCode);
        self::assertSame('BUDAPEST', $company->address->city);
        self::assertSame('BUDAPEST', $company->address->region);
        self::assertSame('HU', $company->address->country);
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('ORGANIZATION', $company->legalFormCode);
        self::assertSame('Organisation', $company->legalForm);
        self::assertTrue($company->vatValid);
        self::assertSame('MAGYAR TELEKOM NYRT.', $company->raw['short_name']);
        Nav::assertTaxpayerQueried('10773381');
        Http::assertNothingSent();
    }

    public function test_invalid_tax_number_is_inactive_and_not_vat_valid(): void
    {
        Nav::fake()->taxpayer('10773381', $this->taxpayer(valid: false));

        $company = $this->driver()->lookup('HU', Identifier::tax('10773381'))->first();

        self::assertSame(CompanyStatus::Inactive, $company?->status);
        self::assertFalse($company->vatValid);
    }

    public function test_subjectively_exempt_taxpayer_is_not_vat_valid(): void
    {
        Nav::fake()->taxpayer('10773381', $this->taxpayer(vatCode: '1'));

        $company = $this->driver()->lookup('HU', Identifier::tax('10773381-1-44'))->first();

        self::assertSame(CompanyStatus::Active, $company?->status);
        self::assertFalse($company->vatValid);
    }

    public function test_unknown_taxpayer_is_not_found(): void
    {
        Nav::fake()->taxpayer('99999999', null);

        self::assertTrue($this->driver()->lookup('HU', Identifier::tax('99999999'))->isNotFound());
    }

    public function test_authentication_error_is_a_rejected_failure(): void
    {
        Nav::fake()->failNext('queryTaxpayer', NavFake::apiError('INVALID_SECURITY_USER', 'queryTaxpayer'));

        $result = $this->driver()->lookup('HU', Identifier::tax('10773381'));

        self::assertSame(FailureReason::Rejected, $result->reason);
        self::assertFalse($result->retryable);
    }

    public function test_connection_failure_is_retryable(): void
    {
        Nav::fake()->failNext('queryTaxpayer', NavFake::connectionFailure('queryTaxpayer'));

        $result = $this->driver()->lookup('HU', Identifier::tax('10773381'));

        self::assertSame(FailureReason::Timeout, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_maintenance_is_retryable(): void
    {
        Nav::fake()->failNext('queryTaxpayer', NavFake::apiError('MAINTENANCE_MODE', 'queryTaxpayer', httpStatus: 503));

        $result = $this->driver()->lookup('HU', Identifier::tax('10773381'));

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_dirty_vat_number_is_normalised_to_the_taxpayer_id(): void
    {
        Nav::fake()->taxpayer('10773381', $this->taxpayer());

        $result = $this->driver()->lookup('HU', Identifier::vat(' hu 1077-3381 '));

        self::assertTrue($result->isFound());
        Nav::assertTaxpayerQueried('10773381');
    }

    public function test_malformed_or_foreign_identifier_is_not_found_without_a_call(): void
    {
        $fake = Nav::fake();

        self::assertTrue($this->driver()->lookup('HU', Identifier::tax('1234'))->isNotFound());
        self::assertTrue($this->driver()->lookup('HU', Identifier::registration('01-09-000000'))->isNotFound());
        self::assertTrue($this->driver()->lookup('AT', Identifier::vat('ATU12345678'))->isNotFound());
        $fake->assertNotSent('queryTaxpayer');
    }

    public function test_named_connection_is_used(): void
    {
        $fake = Nav::fake()->taxpayer('10773381', $this->taxpayer());

        $this->driver(['connection' => 'acme'])->lookup('HU', Identifier::tax('10773381'));

        $fake->assertSent('queryTaxpayer', fn (RecordedCall $call): bool => $call->tenant === 'acme');
    }

    public function test_missing_credentials_is_unconfigured(): void
    {
        $this->container()->make('config')->set('nav-online-szamla.credentials', ['login' => null]);

        self::assertFalse($this->driver()->isConfigured());
        self::assertSame('NAV_USERNAME', $this->driver()->capabilities()->credentials['nav-online-szamla.credentials.login']);

        Http::fake();
        $outcome = $this->container()->make(CompanyLookup::class)->lookup('HU', Identifier::tax('10773381'));
        self::assertContains('nav', $outcome->unconfiguredSources());
    }

    public function test_configured_credentials_and_named_connections(): void
    {
        $this->configureSoftware();
        $this->configureCredentials();
        self::assertTrue($this->driver()->isConfigured());

        self::assertFalse($this->driver(['connection' => 'acme'])->isConfigured());
        $this->configureCredentials('nav-online-szamla.tenants.acme');
        self::assertTrue($this->driver(['connection' => 'acme'])->isConfigured());
    }

    public function test_missing_software_settings_is_unconfigured(): void
    {
        $this->configureCredentials();

        self::assertFalse($this->driver()->isConfigured());
        self::assertSame('NAV_SOFTWARE_ID', $this->driver()->capabilities()->credentials['nav-online-szamla.software.id']);
    }

    public function test_vat_group_member_has_unknown_vat_validity(): void
    {
        Nav::fake()->taxpayer('10773381', $this->taxpayer(vatCode: '4'));

        $company = $this->driver()->lookup('HU', Identifier::tax('10773381-4-44'))->first();

        self::assertNotNull($company);
        self::assertNull($company->vatValid);
        self::assertSame(CompanyStatus::Active, $company->status);
    }

    public function test_faked_nav_counts_as_configured(): void
    {
        Nav::fake();

        self::assertTrue($this->driver()->isConfigured());
    }
}
