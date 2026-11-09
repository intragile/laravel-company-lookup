<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\PrhDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures recorded from the live PRH YTJ open data API v3 on 2026-11-09.
 * search.json is the live answer for name=tikkurila, trimmed to companies 1-6 and 14 of the first page
 * (Tikkurila Oyj is the 14th, the source orders by Business ID).
 */
final class PrhDriverTest extends TestCase
{
    private function driver(): PrhDriver
    {
        return new PrhDriver($this->container()->make(Factory::class));
    }

    public function test_found_company(): void
    {
        Http::fake(['avoindata.prh.fi/*' => Http::response(Fixtures::get('prh/found.json'))]);

        $company = $this->driver()->lookup('FI', Identifier::registration('0112038-9'))->first();

        self::assertNotNull($company);
        self::assertSame('Nokia Oyj', $company->name);
        self::assertSame('FI', $company->country);
        self::assertSame('prh', $company->source);
        self::assertSame('0112038-9', $company->identifier(IdentifierType::Registration, 'FI-YTUNNUS'));
        self::assertSame('FI01120389', $company->identifier(IdentifierType::Vat));
        self::assertSame('Karakaari 7', $company->address?->street);
        self::assertSame('02610', $company->address->postalCode);
        self::assertSame('ESPOO', $company->address->city);
        self::assertSame('FI', $company->address->country);
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('Public limited company', $company->legalForm);
        self::assertSame('17', $company->legalFormCode);
        self::assertSame('TOL', $company->activities[0]->scheme);
        self::assertSame('70100', $company->activities[0]->code);
        self::assertSame('Activities of head offices', $company->activities[0]->description);
        self::assertSame('1896-12-19', $company->foundedAt?->format('Y-m-d'));
        self::assertTrue($company->vatValid);
        Http::assertSent(fn (Request $request): bool => $request['businessId'] === '0112038-9');
    }

    public function test_business_id_without_dash_and_vat_form_are_normalised(): void
    {
        Http::fake(['avoindata.prh.fi/*' => Http::response(Fixtures::get('prh/found.json'))]);

        self::assertSame('Nokia Oyj', $this->driver()->lookup('FI', Identifier::registration(' 01120389 '))->first()?->name);
        self::assertSame('Nokia Oyj', $this->driver()->lookup('FI', Identifier::vat('fi 0112 0389'))->first()?->name);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request['businessId'] === '0112038-9');
    }

    public function test_business_id_check_digit(): void
    {
        self::assertSame('0112038-9', PrhDriver::businessId('0112038-9'));
        self::assertSame('0112038-9', PrhDriver::businessId('112038-9'));
        self::assertNull(PrhDriver::businessId('0112038-8'));
        self::assertNull(PrhDriver::businessId('abc'));
        self::assertNull(PrhDriver::businessId('1120389'));
    }

    public function test_invalid_check_digit_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('FI', Identifier::registration('0112038-8'))->isNotFound());
        self::assertTrue($this->driver()->lookup('SE', Identifier::registration('0112038-9'))->isNotFound());
        self::assertTrue($this->driver()->lookup('FI', Identifier::registration('1120389'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_ceased_company_is_dissolved_and_keeps_its_last_name(): void
    {
        Http::fake(['avoindata.prh.fi/*' => Http::response(Fixtures::get('prh/dissolved.json'))]);

        $company = $this->driver()->lookup('FI', Identifier::registration('0194099-3'))->first();

        self::assertSame('Fysios Mehiläinen Oy', $company?->name);
        self::assertSame(CompanyStatus::Dissolved, $company->status);
        self::assertSame('Limited company', $company->legalForm);
        self::assertSame('Arkadiankatu 6', $company->address?->street);
        self::assertSame('HELSINKI', $company->address->city);
        self::assertFalse($company->vatValid);
        self::assertNull($company->identifier(IdentifierType::Vat));
    }

    public function test_bankrupt_company_is_inactive(): void
    {
        Http::fake(['avoindata.prh.fi/*' => Http::response(Fixtures::get('prh/bankrupt.json'))]);

        $company = $this->driver()->lookup('FI', Identifier::registration('0211270-0'))->first();

        self::assertSame('Primera Holidays Oy', $company?->name);
        self::assertSame(CompanyStatus::Inactive, $company->status);
    }

    public function test_vat_lookup_of_a_company_outside_the_vat_register_is_not_found(): void
    {
        Http::fake(['avoindata.prh.fi/*' => Http::response(Fixtures::get('prh/bankrupt.json'))]);

        self::assertTrue($this->driver()->lookup('FI', Identifier::vat('FI02112700'))->isNotFound());
    }

    public function test_unknown_business_id_is_not_found(): void
    {
        Http::fake(['avoindata.prh.fi/*' => Http::response(Fixtures::get('prh/not-found.json'))]);

        self::assertTrue($this->driver()->lookup('FI', Identifier::registration('1234567-1'))->isNotFound());
    }

    public function test_server_error_is_retryable(): void
    {
        Http::fake(['avoindata.prh.fi/*' => Http::response('', 502)]);

        $result = $this->driver()->lookup('FI', Identifier::registration('0112038-9'));

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_bad_request_is_rejected(): void
    {
        Http::fake(['avoindata.prh.fi/*' => Http::response(['message' => 'Bad request'], 400)]);

        $result = $this->driver()->search('FI', 'nokia', 5);

        self::assertSame(FailureReason::Rejected, $result->reason);
        self::assertFalse($result->retryable);
    }

    public function test_name_search_ranks_by_name_match_then_activity(): void
    {
        Http::fake(['avoindata.prh.fi/*' => Http::response(Fixtures::get('prh/search.json'))]);

        $result = $this->driver()->search('FI', 'tikkurila', 10);

        self::assertSame([
            'Tikkurila Oyj',
            'Tikkurilan Kauppatalo Oy',
            'Tikkurilan Maanrakennus Oy',
            'Tikkurilan Polttomaalaamo Aarre Helin & Co',
            'Tikkurilan Autotarvike ja Varaosat Oy',
            'Kiinteistö-Oy Tikkurilan Autotalo',
            'Fysios Mehiläinen Oy',
        ], array_map(fn ($company): ?string => $company->name, $result->companies));
        self::assertSame('0197067-4', $result->companies[0]->identifier(IdentifierType::Registration));
        self::assertSame(CompanyStatus::Active, $result->companies[0]->status);
        Http::assertSent(fn (Request $request): bool => $request['name'] === 'tikkurila');
    }

    public function test_name_search_slices_after_ranking(): void
    {
        Http::fake(['avoindata.prh.fi/*' => Http::response(Fixtures::get('prh/search.json'))]);

        $result = $this->driver()->search('FI', 'Tikkurila Oyj', 1);

        self::assertCount(1, $result->companies);
        self::assertSame('Tikkurila Oyj', $result->companies[0]->name);
    }
}
