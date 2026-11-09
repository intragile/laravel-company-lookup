<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\BolagsverketDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures built from the official documentation (the VärdefullaDatamängder v1 OpenAPI spec on
 * https://portal.api.bolagsverket.se/devportal/apis and its "Connection establishment guide"
 * v1.01), not a live response. Each file is a documented example verbatim, except active.json,
 * which is the documented limited-company example with the deregistration and proceedings removed.
 */
final class BolagsverketDriverTest extends TestCase
{
    private const CONFIG = ['client_id' => 'myClientID', 'client_secret' => 'myClientSecret'];

    /**
     * @param  array<string, mixed>  $config
     */
    private function driver(array $config = self::CONFIG, ?Repository $cache = null): BolagsverketDriver
    {
        return new BolagsverketDriver($this->container()->make(Factory::class), $config, $cache ?? new Repository(new ArrayStore));
    }

    private function fakeApi(string $fixture = 'active.json', int $status = 200): void
    {
        Http::fake([
            'portal.api.bolagsverket.se/oauth2/token' => Http::response(Fixtures::get('bolagsverket/token.json')),
            'gw.api.bolagsverket.se/*' => Http::response(Fixtures::get('bolagsverket/'.$fixture), $status),
        ]);
    }

    public function test_found_company_is_mapped(): void
    {
        $this->fakeApi();

        $company = $this->driver()->lookup('SE', Identifier::registration('5299999994'))->first();

        self::assertNotNull($company);
        self::assertSame('Cykelbolaget AB', $company->name);
        self::assertSame('SE', $company->country);
        self::assertSame('5299999994', $company->identifier(IdentifierType::Registration));
        self::assertSame('SE529999999401', $company->identifier(IdentifierType::Vat));
        self::assertSame('Jobbstigen 2, 12345 Grönköping, SE', $company->address?->format());
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('Aktiebolag', $company->legalForm);
        self::assertSame('AB', $company->legalFormCode);
        self::assertSame('47642', $company->activities[0]->code ?? null);
        self::assertSame('2000-01-23', $company->foundedAt?->format('Y-m-d'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gw.api.bolagsverket.se/vardefulla-datamangder/v1/organisationer'
            && $request->method() === 'POST'
            && $request['identitetsbeteckning'] === '5299999994'
            && $request->header('Authorization') === ['Bearer valueOnGeneratedAccessToken']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://portal.api.bolagsverket.se/oauth2/token'
            && $request['grant_type'] === 'client_credentials'
            && $request['client_id'] === 'myClientID'
            && $request['scope'] === 'vardefulla-datamangder:read');
    }

    public function test_deregistered_company_is_dissolved(): void
    {
        $this->fakeApi('aktiebolag.json');

        $company = $this->driver()->lookup('SE', Identifier::registration('5299999994'))->first();

        self::assertNotNull($company);
        self::assertSame(CompanyStatus::Dissolved, $company->status);
        self::assertNull($company->identifier(IdentifierType::Vat));
        self::assertNull($company->vatValid);
    }

    public function test_sole_trader_with_several_registrations_returns_each(): void
    {
        $this->fakeApi('enskild.json');

        $result = $this->driver()->lookup('SE', Identifier::registration('194009272719'));

        self::assertCount(2, $result->companies);
        self::assertSame('CITY SKOR THOMAS CARLSON', $result->companies[0]->name);
        self::assertNull($result->companies[0]->identifier(IdentifierType::Vat));
    }

    public function test_vat_number_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertNotContains(IdentifierType::Vat, $this->driver()->capabilities()->identifierTypes);
        self::assertTrue($this->driver()->lookup('SE', Identifier::vat('SE529999999401'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_stale_token_is_replaced_once(): void
    {
        Http::fake([
            'portal.api.bolagsverket.se/oauth2/token' => Http::sequence()
                ->push(['access_token' => 'stale-token', 'expires_in' => 3600])
                ->push(Fixtures::get('bolagsverket/token.json')),
            'gw.api.bolagsverket.se/*' => fn (Request $request) => $request->header('Authorization') === ['Bearer stale-token']
                ? Http::response(Fixtures::get('bolagsverket/unauthorized.json'), 401)
                : Http::response(Fixtures::get('bolagsverket/active.json')),
        ]);

        self::assertTrue($this->driver()->lookup('SE', Identifier::registration('5299999994'))->isFound());
        Http::assertSentCount(4);
    }

    public function test_own_not_found_error_is_not_found(): void
    {
        Http::fake([
            'portal.api.bolagsverket.se/oauth2/token' => Http::response(Fixtures::get('bolagsverket/token.json')),
            'gw.api.bolagsverket.se/*' => Http::response(['type' => 'about:blank', 'instance' => 'not.found', 'status' => 404, 'title' => 'Not Found'], 404),
        ]);

        self::assertTrue($this->driver()->lookup('SE', Identifier::registration('5560125790'))->isNotFound());
    }

    public function test_bare_404_is_a_failure(): void
    {
        Http::fake([
            'portal.api.bolagsverket.se/oauth2/token' => Http::response(Fixtures::get('bolagsverket/token.json')),
            'gw.api.bolagsverket.se/*' => Http::response('<html>Not Found</html>', 404),
        ]);

        $result = $this->driver()->lookup('SE', Identifier::registration('5560125790'));

        self::assertTrue($result->isFailed());
        self::assertSame(FailureReason::InvalidResponse, $result->reason);
    }

    public function test_empty_answer_is_not_found(): void
    {
        Http::fake([
            'portal.api.bolagsverket.se/oauth2/token' => Http::response(Fixtures::get('bolagsverket/token.json')),
            'gw.api.bolagsverket.se/*' => Http::response(['organisationer' => []]),
        ]);

        self::assertTrue($this->driver()->lookup('SE', Identifier::registration('5560125790'))->isNotFound());
    }

    public function test_invalid_check_digit_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('SE', Identifier::registration('5299999995'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_unreachable_data_producer_is_retryable(): void
    {
        $this->fakeApi('source-unavailable.json');

        $result = $this->driver()->lookup('SE', Identifier::registration('194009272719'));

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_bad_request_is_rejected_with_the_detail(): void
    {
        $this->fakeApi('bad-request.json', 400);

        $result = $this->driver()->lookup('SE', Identifier::registration('5299999994'));

        self::assertSame(FailureReason::Rejected, $result->reason);
        self::assertSame('Identitetsbeteckning har ogiltig kontrollsiffra.', $result->message);
    }

    public function test_dirty_input_is_normalised(): void
    {
        $this->fakeApi();

        self::assertTrue($this->driver()->lookup('se', Identifier::registration('SE 529999-9994'))->isFound());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/organisationer') && $request['identitetsbeteckning'] === '5299999994');
    }

    public function test_access_token_is_reused(): void
    {
        $this->fakeApi();
        $cache = new Repository(new ArrayStore);

        $this->driver(cache: $cache)->lookup('SE', Identifier::registration('5299999994'));
        $this->driver(cache: $cache)->lookup('SE', Identifier::registration('5560125790'));

        Http::assertSentCount(3);
    }

    public function test_missing_credentials_are_unconfigured_without_a_call(): void
    {
        Http::fake();
        $driver = $this->driver(['client_secret' => 'secret']);

        self::assertFalse($driver->isConfigured());
        self::assertTrue($driver->lookup('SE', Identifier::registration('5299999994'))->isUnconfigured());
        Http::assertNothingSent();
    }

    public function test_name_search_is_not_offered(): void
    {
        Http::fake();

        self::assertFalse($this->driver()->capabilities()->nameSearch);
        self::assertTrue($this->driver()->search('SE', 'cykel', 5)->isNotFound());
        Http::assertNothingSent();
    }
}
