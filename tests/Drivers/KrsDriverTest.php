<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\ActivityCode;
use IntrAgile\CompanyLookup\Drivers\KrsDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures recorded from the live KRS open API (api-krs.ms.gov.pl) on 2026-11-09.
 * A deleted entity (KRS 0000106150) answered 204 with an empty body the same day.
 */
final class KrsDriverTest extends TestCase
{
    private function driver(): KrsDriver
    {
        return new KrsDriver($this->container()->make(Factory::class));
    }

    public function test_entrepreneur_is_found_in_register_p(): void
    {
        Http::fake(['api-krs.ms.gov.pl/*' => Http::response(Fixtures::get('krs/found.json'))]);

        $company = $this->driver()->lookup('PL', Identifier::registration('0000028860'))->first();

        self::assertNotNull($company);
        self::assertSame('ORLEN SPÓŁKA AKCYJNA', $company->name);
        self::assertSame('PL', $company->country);
        self::assertSame('0000028860', $company->identifier(IdentifierType::Registration, 'PL-KRS'));
        self::assertSame('610188201', $company->identifier(IdentifierType::Registration, 'PL-REGON'));
        self::assertSame('7740001454', $company->identifier(IdentifierType::Tax, 'PL-NIP'));
        self::assertSame('CHEMIKÓW 7, 09-411 PŁOCK, MAZOWIECKIE, PL', $company->address?->format());
        self::assertSame('SPÓŁKA AKCYJNA', $company->legalForm);
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertEquals(new ActivityCode('PKD', '19.20.Z', 'WYTWARZANIE I PRZETWARZANIE PRODUKTÓW RAFINACJI ROPY NAFTOWEJ ORAZ PRODUKTÓW Z PALIW KOPALNYCH'), $company->activities[0]);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/OdpisAktualny/0000028860?') && $request['rejestr'] === 'P');
    }

    public function test_foundation_is_found_in_register_s_after_p_misses(): void
    {
        Http::fake(fn (Request $request) => $request['rejestr'] === 'S'
            ? Http::response(Fixtures::get('krs/found-s.json'))
            : Http::response(Fixtures::get('krs/not-found.json'), 404));

        $company = $this->driver()->lookup('PL', Identifier::registration('30897'))->first();

        self::assertSame('FUNDACJA WIELKA ORKIESTRA ŚWIĄTECZNEJ POMOCY', $company?->name);
        self::assertSame('FUNDACJA', $company->legalForm);
        self::assertSame('DOMINIKAŃSKA 19 C', $company->address?->street);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/OdpisAktualny/0000030897?'));
    }

    public function test_missing_or_deleted_entity_is_not_found(): void
    {
        Http::fake(fn (Request $request) => $request['rejestr'] === 'S'
            ? Http::response('', 204)
            : Http::response(Fixtures::get('krs/not-found.json'), 404));

        self::assertTrue($this->driver()->lookup('PL', Identifier::registration('9999999999'))->isNotFound());
        Http::assertSentCount(2);
    }

    public function test_dirty_input_is_normalised(): void
    {
        Http::fake(['api-krs.ms.gov.pl/*' => Http::response(Fixtures::get('krs/found.json'))]);

        $this->driver()->lookup('pl', Identifier::registration('KRS 0000 028 860'));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/OdpisAktualny/0000028860?'));
    }

    public function test_too_long_number_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('PL', Identifier::registration('00000288601'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_server_error_is_retryable(): void
    {
        Http::fake(['api-krs.ms.gov.pl/*' => Http::response('', 502)]);

        $result = $this->driver()->lookup('PL', Identifier::registration('0000028860'));

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_entity_in_liquidation_is_inactive(): void
    {
        Http::fake(['api-krs.ms.gov.pl/*' => Http::response(Fixtures::get('krs/in-liquidation.json'))]);

        $company = $this->driver()->lookup('PL', Identifier::registration('0000558545'))->first();

        self::assertSame('TOTAL-HANDEL SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ W LIKWIDACJI', $company?->name);
        self::assertSame(CompanyStatus::Inactive, $company->status);
    }
}
