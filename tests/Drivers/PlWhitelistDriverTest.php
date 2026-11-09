<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\PlWhitelistDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures recorded from the live white list API (wl-api.mf.gov.pl) on 2026-11-09.
 */
final class PlWhitelistDriverTest extends TestCase
{
    private function driver(): PlWhitelistDriver
    {
        return new PlWhitelistDriver($this->container()->make(Factory::class));
    }

    public function test_found_taxpayer_is_mapped(): void
    {
        Http::fake(['wl-api.mf.gov.pl/*' => Http::response(Fixtures::get('pl-whitelist/found.json'))]);

        $company = $this->driver()->lookup('PL', Identifier::tax('7740001454'))->first();

        self::assertNotNull($company);
        self::assertSame('ORLEN SPÓŁKA AKCYJNA', $company->name);
        self::assertSame('PL', $company->country);
        self::assertSame('7740001454', $company->identifier(IdentifierType::Tax, 'PL-NIP'));
        self::assertSame('PL7740001454', $company->identifier(IdentifierType::Vat));
        self::assertSame('610188201', $company->identifier(IdentifierType::Registration, 'PL-REGON'));
        self::assertSame('0000028860', $company->identifier(IdentifierType::Registration, 'PL-KRS'));
        self::assertSame('CHEMIKÓW 7, 09-411 PŁOCK', $company->address?->format());
        self::assertTrue($company->vatValid);
        self::assertSame(CompanyStatus::Unknown, $company->status);
        self::assertStringNotContainsString('30102010260000140203550589', (string) json_encode([$company->name, $company->identifiers, $company->address]));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/search/nip/7740001454?date='.now('Europe/Warsaw')->format('Y-m-d')));
    }

    public function test_unknown_nip_is_not_found(): void
    {
        Http::fake(['wl-api.mf.gov.pl/*' => Http::response(Fixtures::get('pl-whitelist/not-found.json'))]);

        self::assertTrue($this->driver()->lookup('PL', Identifier::tax('1111111111'))->isNotFound());
    }

    public function test_nip_rejected_by_the_service_is_not_found(): void
    {
        Http::fake(['wl-api.mf.gov.pl/*' => Http::response(Fixtures::get('pl-whitelist/invalid.json'), 400)]);

        self::assertTrue($this->driver()->lookup('PL', Identifier::tax('1111111111'))->isNotFound());
    }

    public function test_bad_checksum_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('PL', Identifier::tax('1234567890'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_prefixed_and_formatted_vat_number_is_normalised(): void
    {
        Http::fake(['wl-api.mf.gov.pl/*' => Http::response(Fixtures::get('pl-whitelist/found.json'))]);

        self::assertTrue($this->driver()->lookup('PL', Identifier::vat('PL 774-000-14-54'))->isFound());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/search/nip/7740001454?'));
    }

    public function test_exempt_taxpayer_is_not_vat_valid(): void
    {
        // The recorded active payer with the status of an exempt one.
        $body = str_replace('"statusVat":"Czynny"', '"statusVat":"Zwolniony"', Fixtures::get('pl-whitelist/found.json'));
        Http::fake(['wl-api.mf.gov.pl/*' => Http::response($body)]);

        $company = $this->driver()->lookup('PL', Identifier::tax('7740001454'))->first();

        self::assertFalse($company?->vatValid);
        self::assertNull($company->identifier(IdentifierType::Vat));
    }

    public function test_vat_lookup_of_an_exempt_taxpayer_is_not_found(): void
    {
        $body = str_replace('"statusVat":"Czynny"', '"statusVat":"Zwolniony"', Fixtures::get('pl-whitelist/found.json'));
        Http::fake(['wl-api.mf.gov.pl/*' => Http::response($body)]);

        self::assertTrue($this->driver()->lookup('PL', Identifier::vat('PL7740001454'))->isNotFound());
    }

    public function test_daily_quota_exhaustion_is_a_retryable_rate_limit(): void
    {
        Http::fake(['wl-api.mf.gov.pl/*' => Http::response('', 429)]);

        $result = $this->driver()->lookup('PL', Identifier::tax('7740001454'));

        self::assertSame(FailureReason::RateLimited, $result->reason);
        self::assertTrue($result->retryable);
        Http::assertSentCount(1);
    }

    public function test_name_search_is_not_supported(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->search('PL', 'Orlen', 5)->isNotFound());
        Http::assertNothingSent();
    }
}
