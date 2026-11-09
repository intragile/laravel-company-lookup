<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\SgAcraDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures recorded from the live data.gov.sg datastore API on 2026-11-09.
 * error.json is the live answer to a query against a non-existent resource id.
 */
final class SgAcraDriverTest extends TestCase
{
    private function driver(): SgAcraDriver
    {
        return new SgAcraDriver($this->container()->make(Factory::class));
    }

    public function test_found_company_with_address_status_and_type(): void
    {
        Http::fake(['data.gov.sg/*' => Http::response(Fixtures::get('sg-acra/found.json'))]);

        $company = $this->driver()->lookup('SG', Identifier::registration('196800306E'))->first();

        self::assertNotNull($company);
        self::assertSame('DBS BANK LTD.', $company->name);
        self::assertSame('SG', $company->country);
        self::assertSame('sg-acra', $company->source);
        self::assertSame('196800306E', $company->identifier(IdentifierType::Registration, 'SG-UEN'));
        self::assertSame('MARINA BOULEVARD', $company->address?->street);
        self::assertSame('018982', $company->address->postalCode);
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('Local Company', $company->legalForm);
        self::assertSame('1968-07-16', $company->foundedAt?->format('Y-m-d'));
        Http::assertSent(fn (Request $request): bool => $request['filters'] === '{"uen":"196800306E"}'
            && $request['resource_id'] === SgAcraDriver::RESOURCE_ID);
    }

    public function test_deregistered_entity_is_dissolved(): void
    {
        Http::fake(['data.gov.sg/*' => Http::response(Fixtures::get('sg-acra/deregistered.json'))]);

        $company = $this->driver()->lookup('SG', Identifier::registration('53250767C'))->first();

        self::assertSame(CompanyStatus::Dissolved, $company?->status);
        self::assertSame('PARTY ART ENTERTAINMENT', $company->name);
    }

    public function test_unknown_uen_is_not_found(): void
    {
        Http::fake(['data.gov.sg/*' => Http::response(Fixtures::get('sg-acra/not-found.json'))]);

        self::assertTrue($this->driver()->lookup('SG', Identifier::registration('198700727W'))->isNotFound());
    }

    public function test_record_with_a_different_uen_is_not_accepted(): void
    {
        Http::fake(['data.gov.sg/*' => Http::response(Fixtures::get('sg-acra/found.json'))]);

        self::assertTrue($this->driver()->lookup('SG', Identifier::registration('196800307E'))->isNotFound());
    }

    public function test_dirty_input_is_normalized(): void
    {
        Http::fake(['data.gov.sg/*' => Http::response(Fixtures::get('sg-acra/found.json'))]);

        self::assertTrue($this->driver()->lookup('sg', Identifier::registration(' sg 1968-00306e '))->isFound());
        Http::assertSent(fn (Request $request): bool => $request['filters'] === '{"uen":"196800306E"}');
    }

    public function test_malformed_uen_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('SG', Identifier::registration('12345'))->isNotFound());
        self::assertTrue($this->driver()->lookup('MY', Identifier::registration('196800306E'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_api_error_is_an_invalid_response(): void
    {
        Http::fake(['data.gov.sg/*' => Http::response(Fixtures::get('sg-acra/error.json'), 409)]);

        $result = $this->driver()->lookup('SG', Identifier::registration('196800306E'));

        self::assertSame(FailureReason::InvalidResponse, $result->reason);
        self::assertFalse($result->retryable);
        self::assertStringContainsString('Validation Error', (string) $result->message);
    }

    public function test_throttling_is_retryable(): void
    {
        Http::fake(['data.gov.sg/*' => Http::response('{"name":"TOO_MANY_REQUESTS"}', 429)]);

        $result = $this->driver()->lookup('SG', Identifier::registration('196800306E'));

        self::assertSame(FailureReason::RateLimited, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_name_search_queries_the_entity_name_field(): void
    {
        Http::fake(['data.gov.sg/*' => Http::response(Fixtures::get('sg-acra/search.json'))]);

        $result = $this->driver()->search('SG', 'DBS BANK', 10);

        self::assertSame(['DBS BANK LTD.', 'DBS BANK'], array_map(fn ($company): ?string => $company->name, $result->companies));
        self::assertSame('49413400C', $result->companies[1]->identifier(IdentifierType::Registration));
        self::assertSame(CompanyStatus::Dissolved, $result->companies[1]->status);
        Http::assertSent(fn (Request $request): bool => $request['q'] === '{"entity_name":"DBS BANK"}' && str_contains($request->url(), 'limit=10'));
    }

    public function test_capabilities(): void
    {
        $capabilities = $this->driver()->capabilities();

        self::assertSame(['SG'], $capabilities->countries);
        self::assertTrue($capabilities->nameSearch);
        self::assertSame('196800306E', $capabilities->sample);
        self::assertSame('SG', $capabilities->sampleCountry());
        self::assertStringContainsString('Singapore Open Data Licence', (string) $capabilities->attribution);
        self::assertTrue($this->driver()->isConfigured());
    }
}
