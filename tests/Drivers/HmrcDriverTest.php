<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\HmrcDriver;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures are the examples of the official OpenAPI specification of "Check a UK VAT number" v2
 * (github.com/hmrc/vat-registered-companies-api, public/api/conf/2.0/application.yaml) and of the
 * HMRC Developer Hub OAuth guide: from the documentation, not a live response.
 */
final class HmrcDriverTest extends TestCase
{
    private const CONFIG = ['client_id' => 'client-id', 'client_secret' => 'client-secret'];

    /**
     * @param  array<string, mixed>  $config
     */
    private function driver(array $config = self::CONFIG, ?Repository $cache = null): HmrcDriver
    {
        return new HmrcDriver($this->container()->make(Factory::class), $config, $cache ?? new Repository(new ArrayStore));
    }

    private function fakeApi(string $fixture = 'found.json', int $status = 200): void
    {
        Http::fake([
            '*/oauth/token' => Http::response(Fixtures::get('hmrc/token.json')),
            '*/organisations/vat/check-vat-number/lookup/*' => Http::response(Fixtures::get('hmrc/'.$fixture), $status),
        ]);
    }

    public function test_found_vat_number_is_mapped(): void
    {
        $this->fakeApi();

        $company = $this->driver()->lookup('GB', Identifier::vat('GB553557881'))->first();

        self::assertNotNull($company);
        self::assertSame('Credite Sberger Donal Inc.', $company->name);
        self::assertSame('GB', $company->country);
        self::assertSame('hmrc', $company->source);
        self::assertSame('GB553557881', $company->identifier(IdentifierType::Vat));
        self::assertSame('131B Barton Hamlet', $company->address?->street);
        self::assertSame('SW97 5CK', $company->address->postalCode);
        self::assertSame('GB', $company->address->country);
        self::assertTrue($company->vatValid);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.service.hmrc.gov.uk/oauth/token'
            && $request['grant_type'] === 'client_credentials'
            && $request['client_id'] === 'client-id'
            && $request['client_secret'] === 'client-secret'
            && $request['scope'] === 'read:vat'
            && ! $request->hasHeader('Authorization'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.service.hmrc.gov.uk/organisations/vat/check-vat-number/lookup/553557881'
            && $request->header('Accept') === ['application/vnd.hmrc.2.0+json']
            && $request->header('Authorization') === ['Bearer bb7fed3fe10dd235a2ccda3d50fb']);
    }

    public function test_unknown_vat_number_is_not_found(): void
    {
        $this->fakeApi('not-found.json', 404);

        self::assertTrue($this->driver()->lookup('GB', Identifier::vat('GB123456789'))->isNotFound());
    }

    public function test_other_404_is_a_failure_not_a_miss(): void
    {
        Http::fake([
            '*/oauth/token' => Http::response(Fixtures::get('hmrc/token.json')),
            '*' => Http::response(['code' => 'MATCHING_RESOURCE_NOT_FOUND', 'message' => 'A resource with the name in the request can not be found in the API'], 404),
        ]);

        $result = $this->driver(cache: new Repository(new ArrayStore))->lookup('GB', Identifier::vat('GB553557881'));

        self::assertTrue($result->isFailed());
        self::assertFalse($result->isCacheable());
    }

    public function test_forbidden_is_rejected(): void
    {
        Http::fake([
            '*/oauth/token' => Http::response(Fixtures::get('hmrc/token.json')),
            '*' => Http::response(['code' => 'FORBIDDEN', 'message' => 'The application is not subscribed'], 403),
        ]);

        $result = $this->driver()->lookup('GB', Identifier::vat('GB553557881'));

        self::assertSame(FailureReason::Rejected, $result->reason);
        self::assertSame('The application is not subscribed', $result->message);
    }

    public function test_revoked_token_is_replaced_once(): void
    {
        Http::fake([
            '*/oauth/token' => Http::sequence()
                ->push(['access_token' => 'stale-token', 'expires_in' => 14400])
                ->push(Fixtures::json('hmrc/token.json')),
            '*/lookup/*' => Http::sequence()
                ->push(['code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid Authentication information provided'], 401)
                ->push(Fixtures::json('hmrc/found.json')),
        ]);
        $cache = new Repository(new ArrayStore);

        $result = $this->driver(cache: $cache)->lookup('GB', Identifier::vat('GB553557881'));

        self::assertTrue($result->isFound());
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer stale-token']);
        Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer bb7fed3fe10dd235a2ccda3d50fb']);
    }

    public function test_server_error_is_retryable(): void
    {
        $this->fakeApi('server-error.json', 500);

        $result = $this->driver()->lookup('GB', Identifier::vat('GB553557881'));

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
        self::assertSame('Unknown error', $result->message);
    }

    public function test_rejected_credentials_are_a_rejected_failure(): void
    {
        Http::fake(['*/oauth/token' => Http::response(['error' => 'invalid_client'], 401)]);

        $result = $this->driver()->lookup('GB', Identifier::vat('GB553557881'));

        self::assertSame(FailureReason::Rejected, $result->reason);
        self::assertFalse($result->retryable);
    }

    public function test_dirty_input_is_normalised(): void
    {
        $this->fakeApi();

        $this->driver()->lookup('GB', Identifier::vat(' gb 553 5578 81 '));
        $this->driver()->lookup('GB', Identifier::vat('146-295-999-727'));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/lookup/553557881'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/lookup/146295999727'));
    }

    public function test_malformed_or_foreign_number_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('GB', Identifier::vat('GB12345'))->isNotFound());
        self::assertTrue($this->driver()->lookup('GB', Identifier::registration('00445790'))->isNotFound());
        self::assertTrue($this->driver()->lookup('IE', Identifier::vat('IE6388047V'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_access_token_is_cached(): void
    {
        $this->fakeApi();
        $cache = new Repository(new ArrayStore);

        $this->driver(cache: $cache)->lookup('GB', Identifier::vat('GB553557881'));
        $this->driver(cache: $cache)->lookup('GB', Identifier::vat('GB146295999727'));

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/lookup/146295999727')
            && $request->header('Authorization') === ['Bearer bb7fed3fe10dd235a2ccda3d50fb']);
    }

    public function test_sandbox_switches_both_base_urls(): void
    {
        $this->fakeApi();

        $driver = $this->driver([...self::CONFIG, 'sandbox' => true]);
        $driver->lookup('GB', Identifier::vat('GB553557881'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://test-api.service.hmrc.gov.uk/oauth/token');
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://test-api.service.hmrc.gov.uk/organisations/'));
        self::assertSame('GB553557881', $driver->capabilities()->sample);
    }

    public function test_missing_credentials_is_unconfigured(): void
    {
        Http::fake();

        $driver = $this->driver(['client_id' => 'client-id']);

        self::assertFalse($driver->isConfigured());
        self::assertSame(['client_id' => 'COMPANY_LOOKUP_HMRC_CLIENT_ID', 'client_secret' => 'COMPANY_LOOKUP_HMRC_CLIENT_SECRET'], $driver->capabilities()->credentials);
        self::assertTrue($driver->lookup('GB', Identifier::vat('GB553557881'))->isUnconfigured());
        self::assertTrue($this->driver()->isConfigured());
        Http::assertNothingSent();
    }
}
