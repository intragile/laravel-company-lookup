<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\GleifDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures recorded from the live GLEIF API (api.gleif.org/api/v1) on 2026-11-09.
 */
final class GleifDriverTest extends TestCase
{
    private function driver(): GleifDriver
    {
        return new GleifDriver($this->container()->make(Factory::class));
    }

    public function test_lei_lookup_maps_the_record(): void
    {
        Http::fake(['api.gleif.org/*' => Http::response(Fixtures::get('gleif/lei-found.json'))]);

        $company = $this->driver()->lookup('DE', Identifier::lei('529900D6BF99LW9R2E68'))->first();

        self::assertNotNull($company);
        self::assertSame('SAP SE', $company->name);
        self::assertSame('DE', $company->country);
        self::assertSame('gleif', $company->source);
        self::assertSame('529900D6BF99LW9R2E68', $company->identifier(IdentifierType::Lei));
        self::assertSame('HRB 719915', $company->identifier(IdentifierType::Registration, 'RA000296'));
        self::assertSame('Dietmar-Hopp-Allee 16', $company->address?->street);
        self::assertSame('69190', $company->address->postalCode);
        self::assertSame('Walldorf', $company->address->city);
        self::assertSame('DE-BW', $company->address->region);
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('SGST', $company->legalFormCode);
        self::assertNull($company->legalForm);
        self::assertSame('2014-07-07', $company->foundedAt?->format('Y-m-d'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/lei-records/529900D6BF99LW9R2E68'));
    }

    public function test_unknown_lei_is_not_found(): void
    {
        Http::fake(['api.gleif.org/*' => Http::response(Fixtures::get('gleif/lei-not-found.json'), 404)]);

        self::assertTrue($this->driver()->lookup('DE', Identifier::lei('529900D6BF99LW9R2E00'))->isNotFound());
    }

    public function test_dirty_lei_is_normalised(): void
    {
        Http::fake(['api.gleif.org/*' => Http::response(Fixtures::get('gleif/lei-found.json'))]);

        $result = $this->driver()->lookup('DE', Identifier::lei(' 5299 00d6-bf99-lw9r-2e68 '));

        self::assertTrue($result->isFound());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/lei-records/529900D6BF99LW9R2E68'));
    }

    public function test_malformed_lei_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('DE', Identifier::lei('NOT-A-LEI'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_registration_lookup_filters_by_register_number_and_country(): void
    {
        Http::fake(['api.gleif.org/*' => Http::response(Fixtures::get('gleif/registration-found.json'))]);

        $company = $this->driver()->lookup('de', Identifier::registration('  HRB   719915 '))->first();

        self::assertSame('SAP SE', $company?->name);
        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'filter[entity.registeredAs]=HRB 719915')
            && str_contains(urldecode($request->url()), 'filter[entity.legalAddress.country]=DE'));
    }

    public function test_register_number_shared_by_several_courts_is_ambiguous(): void
    {
        Http::fake(['api.gleif.org/*' => Http::response(Fixtures::get('gleif/registration-ambiguous.json'))]);

        $result = $this->driver()->lookup('DE', Identifier::registration('HRB 2000'));

        self::assertSame(FailureReason::Rejected, $result->reason);
        self::assertFalse($result->retryable);
        self::assertStringContainsString('ambiguous register number', (string) $result->message);
    }

    public function test_registration_authority_in_the_scheme_narrows_the_match(): void
    {
        Http::fake(['api.gleif.org/*' => Http::response(Fixtures::get('gleif/registration-with-authority.json'))]);

        $company = $this->driver()->lookup('DE', Identifier::registration('HRB 2000', 'ra000368'))->first();

        self::assertSame('E.M.BA. GmbH', $company?->name);
        self::assertSame('HRB 2000', $company->identifier(IdentifierType::Registration, 'RA000368'));
        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'filter[entity.registeredAt]=RA000368'));
    }

    public function test_exact_register_number_wins_over_longer_partial_matches(): void
    {
        Http::fake(['api.gleif.org/*' => Http::response(Fixtures::get('gleif/registration-prefix.json'))]);

        $result = $this->driver()->lookup('DE', Identifier::registration('HRB 1000'));

        self::assertCount(1, $result->companies);
        self::assertSame('GERICKE & Co. GmbH', $result->first()?->name);
    }

    public function test_unknown_registration_is_not_found(): void
    {
        Http::fake(['api.gleif.org/*' => Http::response(Fixtures::get('gleif/registration-empty.json'))]);

        self::assertTrue($this->driver()->lookup('DE', Identifier::registration('HRB 000000099'))->isNotFound());
    }

    public function test_vat_is_not_supported(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('DE', Identifier::vat('DE143593636'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_name_search_returns_every_match(): void
    {
        Http::fake(['api.gleif.org/*' => Http::response(Fixtures::get('gleif/search.json'))]);

        $result = $this->driver()->search('DE', 'SAP SE', 5);

        self::assertSame(['SAP SE', 'SAP SE ADRHEDGED', 'SAP Deutschland SE & Co. KG'], array_map(fn ($company): ?string => $company->name, $result->companies));
        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'filter[entity.legalName]=SAP SE')
            && str_contains(urldecode($request->url()), 'page[size]=5'));
    }

    public function test_rate_limit_is_a_retryable_failure(): void
    {
        Http::fake(['api.gleif.org/*' => Http::response('', 429)]);

        $result = $this->driver()->lookup('DE', Identifier::lei('529900D6BF99LW9R2E68'));

        self::assertSame(FailureReason::RateLimited, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_inactive_dissolved_entity_maps_to_dissolved(): void
    {
        // The recorded active record with the status fields of a dissolved entity.
        $record = str_replace(
            ['"status":"ACTIVE"', '"expiration":{"date":null,"reason":null}'],
            ['"status":"INACTIVE"', '"expiration":{"date":"2026-01-01T00:00:00Z","reason":"DISSOLVED"}'],
            Fixtures::get('gleif/lei-found.json'),
        );
        Http::fake(['api.gleif.org/*' => Http::response($record)]);

        self::assertSame(CompanyStatus::Dissolved, $this->driver()->lookup('DE', Identifier::lei('529900D6BF99LW9R2E68'))->first()?->status);
    }
}
