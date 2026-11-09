<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\GemiDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures built from the official documentation (Swagger 2.0 spec,
 * https://opendata-api.businessportal.gr/api-docs — Company, CompanyStatus, LegalType,
 * CompanyActivity and ErrorEntry definitions), not a live response.
 */
final class GemiDriverTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $config
     */
    private function driver(array $config = ['key' => 'gemi-key']): GemiDriver
    {
        return new GemiDriver($this->container()->make(Factory::class), $config);
    }

    public function test_found_company_is_mapped(): void
    {
        Http::fake(['opendata-api.businessportal.gr/*' => Http::response(Fixtures::get('gemi/company.json'))]);

        $company = $this->driver()->lookup('GR', Identifier::registration('000828201000'))->first();

        self::assertNotNull($company);
        self::assertSame('VODAFONE-ΠΑΝΑΦΟΝ ΑΝΩΝΥΜΗ ΕΛΛΗΝΙΚΗ ΕΤΑΙΡΙΑ ΤΗΛΕΠΙΚΟΙΝΩΝΙΩΝ', $company->name);
        self::assertSame('GR', $company->country);
        self::assertSame('000828201000', $company->identifier(IdentifierType::Registration));
        self::assertSame('094349850', $company->identifier(IdentifierType::Tax));
        self::assertSame('EL094349850', $company->identifier(IdentifierType::Vat));
        self::assertSame('ΤΖΑΒΕΛΛΑ 1-3, 15231 ΧΑΛΑΝΔΡΙ, ΑΤΤΙΚΗΣ, GR', $company->address?->format());
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('ΑΕ', $company->legalForm);
        self::assertSame('2', $company->legalFormCode);
        self::assertCount(1, $company->activities);
        self::assertSame('61.20', $company->activities[0]->code);
        self::assertSame('1992-07-21', $company->foundedAt?->format('Y-m-d'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://opendata-api.businessportal.gr/api/opendata/v1/companies/828201000'
            && $request->header('api_key') === ['gemi-key']);
    }

    public function test_afm_lookup_prefers_the_head_office(): void
    {
        Http::fake(['opendata-api.businessportal.gr/*' => Http::response(Fixtures::get('gemi/afm.json'))]);

        $result = $this->driver()->lookup('GR', Identifier::tax('094349850'));

        self::assertCount(1, $result->companies);
        self::assertSame('000828201000', $result->first()?->identifier(IdentifierType::Registration));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/companies?') && $request['afm'] === '094349850');
    }

    public function test_unknown_number_is_not_found(): void
    {
        Http::fake(['opendata-api.businessportal.gr/*' => Http::response('', 404)]);

        self::assertTrue($this->driver()->lookup('GR', Identifier::registration('999999999000'))->isNotFound());
        self::assertTrue($this->driver()->lookup('GR', Identifier::tax('094019245'))->isNotFound());
    }

    public function test_invalid_afm_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('GR', Identifier::tax('094349851'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_bad_request_is_rejected(): void
    {
        Http::fake(['opendata-api.businessportal.gr/*' => Http::response(Fixtures::get('gemi/bad-request.json'), 400)]);

        $result = $this->driver()->lookup('GR', Identifier::tax('094349850'));

        self::assertSame(FailureReason::Rejected, $result->reason);
        self::assertSame('Πρέπει να εισαχθεί τουλάχιστον ένα κριτήριο', $result->message);
    }

    public function test_overloaded_service_is_retryable(): void
    {
        Http::fake(['opendata-api.businessportal.gr/*' => Http::response('', 503)]);

        $result = $this->driver()->lookup('GR', Identifier::registration('828201000'));

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_dirty_input_is_normalised(): void
    {
        Http::fake(['opendata-api.businessportal.gr/*' => Http::response(Fixtures::get('gemi/afm.json'))]);

        self::assertTrue($this->driver()->lookup('gr', Identifier::tax('EL 094 349 850'))->isFound());
        Http::assertSent(fn (Request $request): bool => $request['afm'] === '094349850');
    }

    public function test_missing_key_is_unconfigured_without_a_call(): void
    {
        Http::fake();
        $driver = $this->driver([]);

        self::assertFalse($driver->isConfigured());
        self::assertTrue($driver->lookup('GR', Identifier::registration('000828201000'))->isUnconfigured());
        self::assertTrue($driver->search('GR', 'vodafone', 5)->isUnconfigured());
        Http::assertNothingSent();
    }

    public function test_name_search(): void
    {
        Http::fake(['opendata-api.businessportal.gr/*' => Http::response(Fixtures::get('gemi/search.json'))]);

        $result = $this->driver()->search('GR', 'vodafone', 5);

        self::assertCount(2, $result->companies);
        self::assertSame(CompanyStatus::Dissolved, $result->companies[1]->status);
        Http::assertSent(fn (Request $request): bool => $request['name'] === 'vodafone' && $request['resultsSize'] === 5);
    }
}
