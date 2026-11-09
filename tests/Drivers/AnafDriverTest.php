<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\ActivityCode;
use IntrAgile\CompanyLookup\Drivers\AnafDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures recorded from the live ANAF PlatitorTvaRest v9 API (webservicesp.anaf.ro) on 2026-11-09.
 */
final class AnafDriverTest extends TestCase
{
    private function driver(): AnafDriver
    {
        return new AnafDriver($this->container()->make(Factory::class));
    }

    public function test_found_taxpayer_is_mapped(): void
    {
        Http::fake(['webservicesp.anaf.ro/*' => Http::response(Fixtures::get('anaf/found.json'))]);

        $company = $this->driver()->lookup('RO', Identifier::vat('RO1590082'))->first();

        self::assertNotNull($company);
        self::assertSame('OMV PETROM SA', $company->name);
        self::assertSame('RO', $company->country);
        self::assertSame('1590082', $company->identifier(IdentifierType::Tax, 'RO-CUI'));
        self::assertSame('RO1590082', $company->identifier(IdentifierType::Vat));
        self::assertSame('J1997008302407', $company->identifier(IdentifierType::Registration, 'RO-ONRC'));
        self::assertSame('Str. Coralilor 22 (PETROM CITY)', $company->address?->street);
        self::assertSame('13329', $company->address->postalCode);
        self::assertSame('Sector 1 Mun. Bucureşti', $company->address->city);
        self::assertSame('MUNICIPIUL BUCUREŞTI', $company->address->region);
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('SOCIETATE COMERCIALĂ PE ACŢIUNI', $company->legalForm);
        self::assertEquals([new ActivityCode('CAEN', '0610')], $company->activities);
        self::assertSame('1992-12-09', $company->foundedAt?->format('Y-m-d'));
        self::assertTrue($company->vatValid);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/v9/tva')
            && $request->data() === [['cui' => 1590082, 'data' => now('Europe/Bucharest')->format('Y-m-d')]]);
    }

    public function test_unknown_cui_is_not_found(): void
    {
        Http::fake(['webservicesp.anaf.ro/*' => Http::response(Fixtures::get('anaf/not-found.json'), 404)]);

        self::assertTrue($this->driver()->lookup('RO', Identifier::tax('12'))->isNotFound());
    }

    public function test_deregistered_company_is_dissolved(): void
    {
        Http::fake(['webservicesp.anaf.ro/*' => Http::response(Fixtures::get('anaf/dissolved.json'))]);

        $company = $this->driver()->lookup('RO', Identifier::tax('1590074'))->first();

        self::assertSame('SOCIETATEA NATIONALA "CAI DE RASA" SA', $company?->name);
        self::assertSame(CompanyStatus::Dissolved, $company->status);
        self::assertFalse($company->vatValid);
        self::assertNull($company->identifier(IdentifierType::Vat));
    }

    public function test_vat_lookup_of_a_non_payer_is_not_found(): void
    {
        Http::fake(['webservicesp.anaf.ro/*' => Http::response(Fixtures::get('anaf/dissolved.json'))]);

        self::assertTrue($this->driver()->lookup('RO', Identifier::vat('RO1590074'))->isNotFound());
    }

    public function test_inactive_taxpayer_is_inactive(): void
    {
        Http::fake(['webservicesp.anaf.ro/*' => Http::response(Fixtures::get('anaf/inactive.json'))]);

        self::assertSame(CompanyStatus::Inactive, $this->driver()->lookup('RO', Identifier::tax('1590147'))->first()?->status);
    }

    public function test_dirty_input_is_normalised(): void
    {
        Http::fake(['webservicesp.anaf.ro/*' => Http::response(Fixtures::get('anaf/found.json'))]);

        self::assertTrue($this->driver()->lookup('ro', Identifier::vat(' ro 1590 082 '))->isFound());
        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"cui":1590082'));
    }

    public function test_non_numeric_cui_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('RO', Identifier::vat('ROABC'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_server_error_is_retryable(): void
    {
        Http::fake(['webservicesp.anaf.ro/*' => Http::response('', 503)]);

        $result = $this->driver()->lookup('RO', Identifier::tax('1590082'));

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_bare_404_without_a_not_found_list_is_a_failure(): void
    {
        Http::fake(['webservicesp.anaf.ro/*' => Http::response('<html>Not Found</html>', 404)]);

        self::assertSame(FailureReason::InvalidResponse, $this->driver()->lookup('RO', Identifier::tax('1590082'))->reason);
    }
}
