<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\SudregDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures built from the official documentation (OpenAPI spec v3.0.4,
 * https://sudreg-data.gov.hr/api/javni/dokumentacija/open_api — detalji_subjekta_samo_aktivni_ex
 * and error schemas with their examples), not a live response.
 */
final class SudregDriverTest extends TestCase
{
    private const CONFIG = ['client_id' => 'id', 'client_secret' => 'secret'];

    /**
     * @param  array<string, mixed>  $config
     */
    private function driver(array $config = self::CONFIG, ?Repository $cache = null): SudregDriver
    {
        return new SudregDriver($this->container()->make(Factory::class), $config, $cache ?? new Repository(new ArrayStore));
    }

    private function fakeApi(string $fixture = 'found.json', int $status = 200): void
    {
        Http::fake([
            'sudreg-data.gov.hr/api/oauth/token' => Http::response(Fixtures::get('sudreg/token.json')),
            'sudreg-data.gov.hr/api/javni/*' => Http::response(Fixtures::get('sudreg/'.$fixture), $status),
        ]);
    }

    public function test_found_subject_is_mapped(): void
    {
        $this->fakeApi();

        $company = $this->driver()->lookup('HR', Identifier::registration('080266256'))->first();

        self::assertNotNull($company);
        self::assertSame('HRVATSKI TELEKOM d.d. za obavljanje telekomunikacijskih usluga', $company->name);
        self::assertSame('HR', $company->country);
        self::assertSame('080266256', $company->identifier(IdentifierType::Registration));
        self::assertSame('81793146560', $company->identifier(IdentifierType::Tax));
        self::assertSame('Radnička cesta 21, 10000 Zagreb, Grad Zagreb, HR', $company->address?->format());
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('dioničko društvo', $company->legalForm);
        self::assertSame('d.d.', $company->legalFormCode);
        self::assertSame('61.10', $company->activities[0]->code ?? null);
        self::assertSame('1999-01-01', $company->foundedAt?->format('Y-m-d'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/javni/detalji_subjekta')
            && $request['tip_identifikatora'] === 'mbs'
            && $request['identifikator'] === '080266256'
            && $request->header('Authorization') === ['Bearer sudreg-access-token']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sudreg-data.gov.hr/api/oauth/token'
            && $request['grant_type'] === 'client_credentials'
            && $request->header('Authorization') === ['Basic '.base64_encode('id:secret')]);
    }

    public function test_oib_lookup_uses_the_oib_identifier_type(): void
    {
        $this->fakeApi();

        self::assertTrue($this->driver()->lookup('HR', Identifier::tax('81793146560'))->isFound());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'detalji_subjekta')
            && $request['tip_identifikatora'] === 'oib'
            && $request['identifikator'] === '81793146560');
    }

    public function test_no_rows_is_not_found(): void
    {
        $this->fakeApi('no-rows.json', 400);

        self::assertTrue($this->driver()->lookup('HR', Identifier::registration('080000000'))->isNotFound());
    }

    public function test_empty_object_is_not_found(): void
    {
        Http::fake([
            'sudreg-data.gov.hr/api/oauth/token' => Http::response(Fixtures::get('sudreg/token.json')),
            'sudreg-data.gov.hr/api/javni/*' => Http::response('{}'),
        ]);

        self::assertTrue($this->driver()->lookup('HR', Identifier::registration('080000000'))->isNotFound());
    }

    public function test_invalid_oib_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('HR', Identifier::tax('81793146561'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_server_error_is_retryable(): void
    {
        $this->fakeApi('server-error.json', 500);

        $result = $this->driver()->lookup('HR', Identifier::registration('080266256'));

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_rejected_credentials_are_a_rejected_failure(): void
    {
        Http::fake(['sudreg-data.gov.hr/api/oauth/token' => Http::response(['error' => 'invalid_client'], 401)]);

        self::assertSame(FailureReason::Rejected, $this->driver()->lookup('HR', Identifier::registration('080266256'))->reason);
    }

    public function test_dirty_input_is_normalised(): void
    {
        $this->fakeApi();

        self::assertTrue($this->driver()->lookup('hr', Identifier::tax('HR 817-931-465-60'))->isFound());
        self::assertTrue($this->driver()->lookup('HR', Identifier::registration('80266256'))->isFound());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'identifikator=81793146560'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'identifikator=080266256'));
    }

    public function test_stale_token_is_replaced_once(): void
    {
        Http::fake([
            'sudreg-data.gov.hr/api/oauth/token' => Http::sequence()
                ->push(['access_token' => 'stale-token', 'expires_in' => 3600])
                ->push(Fixtures::get('sudreg/token.json')),
            'sudreg-data.gov.hr/api/javni/*' => fn (Request $request) => $request->header('Authorization') === ['Bearer stale-token']
                ? Http::response('', 401)
                : Http::response(Fixtures::get('sudreg/found.json')),
        ]);

        self::assertTrue($this->driver()->lookup('HR', Identifier::registration('080266256'))->isFound());
        Http::assertSentCount(4);
    }

    public function test_rate_limit_is_not_retried(): void
    {
        Http::fake([
            'sudreg-data.gov.hr/api/oauth/token' => Http::response(Fixtures::get('sudreg/token.json')),
            'sudreg-data.gov.hr/api/javni/*' => Http::response('', 429),
        ]);

        self::assertSame(FailureReason::RateLimited, $this->driver()->lookup('HR', Identifier::registration('080266256'))->reason);
        Http::assertSentCount(2);
    }

    public function test_vat_identifier_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('HR', Identifier::vat('HR81793146560'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_access_token_is_reused(): void
    {
        $this->fakeApi();
        $cache = new Repository(new ArrayStore);

        $this->driver(cache: $cache)->lookup('HR', Identifier::registration('080266256'));
        $this->driver(cache: $cache)->lookup('HR', Identifier::tax('81793146560'));

        Http::assertSentCount(3);
    }

    public function test_missing_credentials_are_unconfigured_without_a_call(): void
    {
        Http::fake();
        $driver = $this->driver(['client_id' => 'id']);

        self::assertFalse($driver->isConfigured());
        self::assertTrue($driver->lookup('HR', Identifier::registration('080266256'))->isUnconfigured());
        Http::assertNothingSent();
    }

    public function test_name_search_is_not_offered(): void
    {
        Http::fake();

        self::assertFalse($this->driver()->capabilities()->nameSearch);
        self::assertTrue($this->driver()->search('HR', 'telekom', 5)->isNotFound());
        Http::assertNothingSent();
    }
}
