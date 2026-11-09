<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\ActivityCode;
use IntrAgile\CompanyLookup\Drivers\AresDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures recorded from the live ARES REST API (ares.gov.cz) on 2026-11-09.
 */
final class AresDriverTest extends TestCase
{
    private function driver(): AresDriver
    {
        return new AresDriver($this->container()->make(Factory::class));
    }

    public function test_found_company_is_mapped(): void
    {
        Http::fake(['ares.gov.cz/*' => Http::response(Fixtures::get('ares/found.json'))]);

        $company = $this->driver()->lookup('CZ', Identifier::registration('45274649'))->first();

        self::assertNotNull($company);
        self::assertSame('ČEZ, a. s.', $company->name);
        self::assertSame('CZ', $company->country);
        self::assertSame('45274649', $company->identifier(IdentifierType::Registration, 'CZ-ICO'));
        self::assertSame('CZ45274649', $company->identifier(IdentifierType::Vat));
        self::assertSame('Duhová 1444/2', $company->address?->street);
        self::assertSame('14000', $company->address->postalCode);
        self::assertSame('Praha', $company->address->city);
        self::assertSame('Duhová 1444/2, Michle, 14000 Praha 4', $company->address->format());
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('121', $company->legalFormCode);
        self::assertSame('1992-05-06', $company->foundedAt?->format('Y-m-d'));
        self::assertTrue($company->vatValid);
        self::assertEquals(new ActivityCode('NACE', '821'), $company->activities[0]);
        self::assertNotContains('00', array_map(fn (ActivityCode $activity): string => $activity->code, $company->activities));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/ekonomicke-subjekty/45274649'));
    }

    public function test_unknown_ico_is_not_found(): void
    {
        Http::fake(['ares.gov.cz/*' => Http::response(Fixtures::get('ares/not-found.json'), 404)]);

        self::assertTrue($this->driver()->lookup('CZ', Identifier::registration('00000019'))->isNotFound());
    }

    public function test_vat_number_and_short_ico_are_normalised(): void
    {
        Http::fake(['ares.gov.cz/*' => Http::response(Fixtures::get('ares/found.json'))]);

        $this->driver()->lookup('CZ', Identifier::vat('cz 452 746 49'));
        $this->driver()->lookup('CZ', Identifier::registration('1350'));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/ekonomicke-subjekty/45274649'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/ekonomicke-subjekty/00001350'));
    }

    public function test_natural_person_vat_number_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('CZ', Identifier::vat('CZ7103192745'))->isNotFound());
        self::assertTrue($this->driver()->lookup('SK', Identifier::registration('45274649'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_vat_lookup_of_a_non_payer_is_not_found(): void
    {
        Http::fake(['ares.gov.cz/*' => Http::response(Fixtures::get('ares/no-vat.json'))]);

        self::assertTrue($this->driver()->lookup('CZ', Identifier::vat('CZ66610460'))->isNotFound());

        $company = $this->driver()->lookup('CZ', Identifier::registration('66610460'))->first();
        self::assertSame('SPOLEK PŘÁTEL HUDBY při Filharmonii Brno', $company?->name);
        self::assertFalse($company->vatValid);
        self::assertNull($company->identifier(IdentifierType::Vat));
    }

    public function test_server_error_is_retryable(): void
    {
        Http::fake(['ares.gov.cz/*' => Http::response('', 503)]);

        $result = $this->driver()->lookup('CZ', Identifier::registration('45274649'));

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_dissolved_status_is_mapped(): void
    {
        // The recorded active entity with the register states of a dissolved one.
        $body = str_replace('"stavZdrojeVr":"AKTIVNI"', '"stavZdrojeVr":"ZANIKLY"', Fixtures::get('ares/found.json'));
        Http::fake(['ares.gov.cz/*' => Http::response($body)]);

        self::assertSame(CompanyStatus::Dissolved, $this->driver()->lookup('CZ', Identifier::registration('45274649'))->first()?->status);
    }

    public function test_name_search(): void
    {
        Http::fake(['ares.gov.cz/*' => Http::response(Fixtures::get('ares/search.json'))]);

        $result = $this->driver()->search('CZ', 'ČEZ', 3);

        self::assertSame(['ČEZ Recyklace, s.r.o.', 'ČEZ ESCO, a.s.', 'Elevion Group B.V., o.z.'], array_map(fn ($company): ?string => $company->name, $result->companies));
        self::assertSame('03479919', $result->first()?->identifier(IdentifierType::Registration));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/ekonomicke-subjekty/vyhledat')
            && $request['obchodniJmeno'] === 'ČEZ' && $request['pocet'] === 3);
    }

    public function test_name_search_without_match_is_not_found(): void
    {
        Http::fake(['ares.gov.cz/*' => Http::response(Fixtures::get('ares/search-empty.json'))]);

        self::assertTrue($this->driver()->search('CZ', 'Zzqxw neexistujici', 3)->isNotFound());
    }

    public function test_too_broad_name_search_is_rejected_with_the_registry_message(): void
    {
        Http::fake(['ares.gov.cz/*' => Http::response(Fixtures::get('ares/search-too-many.json'), 400)]);

        $result = $this->driver()->search('CZ', 'v likvidaci', 10);

        self::assertSame(FailureReason::Rejected, $result->reason);
        self::assertFalse($result->retryable);
        self::assertStringContainsString('příliš mnoho výsledků', (string) $result->message);
    }
}
