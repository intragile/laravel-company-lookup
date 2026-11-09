<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\IsedDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures built from the official documentation (Federal Corporation API OpenAPI spec,
 * https://api.ised-isde.canada.ca/swagger/spec/corporations-en.json — the documented example
 * for corporation 1007 with lang=eng, and the documented "could not find" body), not a live response.
 */
final class IsedDriverTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $config
     */
    private function driver(array $config = ['key' => 'ised-key']): IsedDriver
    {
        return new IsedDriver($this->container()->make(Factory::class), $config);
    }

    public function test_found_corporation_is_mapped(): void
    {
        Http::fake(['apigateway-passerelledapi.ised-isde.canada.ca/*' => Http::response(Fixtures::get('ised/found-eng.json'))]);

        $company = $this->driver()->lookup('CA', Identifier::registration('000100-7'))->first();

        self::assertNotNull($company);
        self::assertSame('Abbotsford Chamber of Commerce', $company->name);
        self::assertSame('CA', $company->country);
        self::assertSame('1007', $company->identifier(IdentifierType::Registration));
        self::assertSame('106679285', $company->identifier(IdentifierType::Tax));
        self::assertSame('207 - 32900 SOUTH FRASER WAY, V2S 5A1 ABBOTSFORD, BC, CA', $company->address?->format());
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('Boards of Trade Act - Part II', $company->legalForm);
        self::assertSame('1947-01-10', $company->foundedAt?->format('Y-m-d'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://apigateway-passerelledapi.ised-isde.canada.ca/corporations/api/v1/corporations/1007.json?lang=eng'
            && $request->header('user-key') === ['ised-key']);
    }

    public function test_business_number_lookup(): void
    {
        Http::fake(['apigateway-passerelledapi.ised-isde.canada.ca/*' => Http::response(Fixtures::get('ised/found-eng.json'))]);

        self::assertTrue($this->driver()->lookup('CA', Identifier::tax('106679285RC0001'))->isFound());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v1/corporations/106679285.json'));
    }

    public function test_unknown_number_is_not_found_despite_http_200(): void
    {
        Http::fake(['apigateway-passerelledapi.ised-isde.canada.ca/*' => Http::response(Fixtures::get('ised/not-found.json'))]);

        self::assertTrue($this->driver()->lookup('CA', Identifier::registration('9999999'))->isNotFound());
    }

    public function test_bare_404_is_a_failure_not_a_cacheable_not_found(): void
    {
        Http::fake(['apigateway-passerelledapi.ised-isde.canada.ca/*' => Http::response('Not Found', 404)]);

        $result = $this->driver()->lookup('CA', Identifier::registration('1007'));

        self::assertTrue($result->isFailed());
        self::assertFalse($result->isCacheable());
    }

    public function test_empty_input_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('CA', Identifier::registration('000-0'))->isNotFound());
        self::assertTrue($this->driver()->lookup('CA', Identifier::tax('12345'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_missing_or_rejected_key_is_rejected(): void
    {
        Http::fake(['apigateway-passerelledapi.ised-isde.canada.ca/*' => Http::response('Authentication parameters missing', 403)]);

        $result = $this->driver()->lookup('CA', Identifier::registration('1007'));

        self::assertSame(FailureReason::Rejected, $result->reason);
        self::assertSame('Authentication parameters missing', $result->message);
    }

    public function test_server_error_is_retryable(): void
    {
        Http::fake(['apigateway-passerelledapi.ised-isde.canada.ca/*' => Http::response('', 503)]);

        self::assertTrue($this->driver()->lookup('CA', Identifier::registration('1007'))->retryable);
    }

    public function test_dirty_input_is_normalised(): void
    {
        Http::fake(['apigateway-passerelledapi.ised-isde.canada.ca/*' => Http::response(Fixtures::get('ised/found-eng.json'))]);

        self::assertTrue($this->driver()->lookup('ca', Identifier::registration(' 426160-7 '))->isFound());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v1/corporations/4261607.json'));
    }

    public function test_missing_key_is_unconfigured_without_a_call(): void
    {
        Http::fake();
        $driver = $this->driver([]);

        self::assertFalse($driver->isConfigured());
        self::assertTrue($driver->lookup('CA', Identifier::registration('1007'))->isUnconfigured());
        Http::assertNothingSent();
    }

    public function test_name_search_is_not_offered(): void
    {
        Http::fake();

        self::assertFalse($this->driver()->capabilities()->nameSearch);
        self::assertTrue($this->driver()->search('CA', 'abbotsford', 5)->isNotFound());
        Http::assertNothingSent();
    }
}
