<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\BrregDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures recorded from the live data.brreg.no Enhetsregisteret API on 2026-11-09.
 * The 404 for an unknown organisation number has an empty body, as observed live.
 */
final class BrregDriverTest extends TestCase
{
    private function driver(): BrregDriver
    {
        return new BrregDriver($this->container()->make(Factory::class));
    }

    public function test_found_company(): void
    {
        Http::fake(['data.brreg.no/*' => Http::response(Fixtures::get('brreg/found.json'))]);

        $company = $this->driver()->lookup('NO', Identifier::registration('923 609 016'))->first();

        self::assertNotNull($company);
        self::assertSame('EQUINOR ASA', $company->name);
        self::assertSame('NO', $company->country);
        self::assertSame('brreg', $company->source);
        self::assertSame('923609016', $company->identifier(IdentifierType::Registration, 'NO-ORGNR'));
        self::assertSame('NO923609016MVA', $company->identifier(IdentifierType::Vat));
        self::assertSame('Forusbeen 50, 4035 STAVANGER, NO', $company->address?->format());
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('Allmennaksjeselskap', $company->legalForm);
        self::assertSame('ASA', $company->legalFormCode);
        self::assertSame('NACE', $company->activities[0]->scheme);
        self::assertSame('06.100', $company->activities[0]->code);
        self::assertSame('Utvinning av råolje', $company->activities[0]->description);
        self::assertCount(3, $company->activities);
        self::assertSame('1972-09-18', $company->foundedAt?->format('Y-m-d'));
        self::assertTrue($company->vatValid);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/enheter/923609016'));
    }

    public function test_vat_number_is_normalised(): void
    {
        Http::fake(['data.brreg.no/*' => Http::response(Fixtures::get('brreg/found.json'))]);

        $company = $this->driver()->lookup('NO', Identifier::vat('no 923.609.016 mva'))->first();

        self::assertSame('EQUINOR ASA', $company?->name);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/enheter/923609016'));
    }

    public function test_vat_lookup_of_an_entity_outside_the_vat_register_is_not_found(): void
    {
        Http::fake(['data.brreg.no/*' => Http::response(Fixtures::get('brreg/bankrupt.json'))]);

        self::assertTrue($this->driver()->lookup('NO', Identifier::vat('NO935567645MVA'))->isNotFound());
    }

    public function test_bankrupt_company_is_inactive(): void
    {
        Http::fake(['data.brreg.no/*' => Http::response(Fixtures::get('brreg/bankrupt.json'))]);

        $company = $this->driver()->lookup('NO', Identifier::registration('935567645'))->first();

        self::assertSame('1SB JERNBANEKAFEEN AS', $company?->name);
        self::assertSame(CompanyStatus::Inactive, $company->status);
        self::assertFalse($company->vatValid);
        self::assertNull($company->identifier(IdentifierType::Vat));
    }

    public function test_deleted_entity_is_dissolved(): void
    {
        Http::fake(['data.brreg.no/*' => Http::response(Fixtures::get('brreg/deleted.json'))]);

        $company = $this->driver()->lookup('NO', Identifier::registration('921270607'))->first();

        self::assertSame('LAIV I BERGEN', $company?->name);
        self::assertSame(CompanyStatus::Dissolved, $company->status);
        self::assertSame('FLI', $company->legalFormCode);
        self::assertNull($company->address);
    }

    public function test_unknown_number_is_not_found(): void
    {
        Http::fake(['data.brreg.no/*' => Http::response('', 404)]);

        self::assertTrue($this->driver()->lookup('NO', Identifier::registration('999999999'))->isNotFound());
    }

    public function test_entity_removed_for_legal_reasons_is_not_found(): void
    {
        Http::fake(['data.brreg.no/*' => Http::response('', 410)]);

        self::assertTrue($this->driver()->lookup('NO', Identifier::registration('999999999'))->isNotFound());
    }

    public function test_malformed_number_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('NO', Identifier::registration('12345'))->isNotFound());
        self::assertTrue($this->driver()->lookup('SE', Identifier::registration('923609016'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_server_error_is_retryable(): void
    {
        Http::fake(['data.brreg.no/*' => Http::response('', 503)]);

        $result = $this->driver()->lookup('NO', Identifier::registration('923609016'));

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_invalid_json_is_an_invalid_response(): void
    {
        Http::fake(['data.brreg.no/*' => Http::response('<html>maintenance</html>')]);

        self::assertSame(FailureReason::InvalidResponse, $this->driver()->lookup('NO', Identifier::registration('923609016'))->reason);
    }

    public function test_name_search(): void
    {
        Http::fake(['data.brreg.no/*' => Http::response(Fixtures::get('brreg/search.json'))]);

        $result = $this->driver()->search('NO', 'equinor', 3);

        self::assertCount(3, $result->companies);
        self::assertSame('EQUINOR ASA', $result->companies[0]->name);
        self::assertSame('959733600', $result->companies[1]->identifier(IdentifierType::Registration));
        self::assertSame('EQUINOR PENSJON', $result->companies[1]->name);
        Http::assertSent(fn (Request $request): bool => $request['navn'] === 'equinor' && $request['size'] === 3);
    }

    public function test_search_without_hits_is_not_found(): void
    {
        Http::fake(['data.brreg.no/*' => Http::response(Fixtures::get('brreg/search-empty.json'))]);

        self::assertTrue($this->driver()->search('NO', 'qxzqvwyk', 3)->isNotFound());
    }
}
