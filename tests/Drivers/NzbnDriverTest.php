<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\NzbnDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures built from the official documentation (the NZBN v5 API definition on
 * https://portal.api.business.govt.nz/api/nzbn — FullEntity, SearchEntityResponse and
 * ErrorResponse schemas), not a live response.
 */
final class NzbnDriverTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $config
     */
    private function driver(array $config = ['key' => 'subscription-key']): NzbnDriver
    {
        return new NzbnDriver($this->container()->make(Factory::class), $config);
    }

    public function test_found_entity_is_mapped(): void
    {
        Http::fake(['api.business.govt.nz/*' => Http::response(Fixtures::get('nzbn/entity.json'))]);

        $company = $this->driver()->lookup('NZ', Identifier::registration('9429000001885'))->first();

        self::assertNotNull($company);
        self::assertSame('JACOBS DOUWE EGBERTS NZ', $company->name);
        self::assertSame('NZ', $company->country);
        self::assertSame('9429000001885', $company->identifier(IdentifierType::Registration, 'NZ-NZBN'));
        self::assertSame('1002', $company->identifier(IdentifierType::Registration, 'NZ-COMPANY'));
        self::assertSame('Level 2, 55 Shortland Street, Auckland Central, Auckland, 1010', $company->address?->format());
        self::assertSame('1010', $company->address->postalCode);
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('NZ Limited Company', $company->legalForm);
        self::assertSame('LTD', $company->legalFormCode);
        self::assertSame('C119920', $company->activities[0]->code ?? null);
        self::assertSame('1929-03-18', $company->foundedAt?->format('Y-m-d'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.business.govt.nz/gateway/nzbn/v5/entities/9429000001885'
            && $request->header('Ocp-Apim-Subscription-Key') === ['subscription-key']);
    }

    public function test_unknown_nzbn_is_not_found(): void
    {
        Http::fake(['api.business.govt.nz/*' => Http::response(['status' => '404', 'errorDescription' => 'Entity not found'], 404)]);

        self::assertTrue($this->driver()->lookup('NZ', Identifier::registration('9429031127981'))->isNotFound());
    }

    public function test_invalid_check_digit_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('NZ', Identifier::registration('9429000001886'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_rejected_key_is_a_rejected_failure(): void
    {
        Http::fake(['api.business.govt.nz/*' => Http::response(Fixtures::get('nzbn/error.json'), 401)]);

        $result = $this->driver()->lookup('NZ', Identifier::registration('9429000001885'));

        self::assertSame(FailureReason::Rejected, $result->reason);
        self::assertSame('Access denied due to invalid subscription key.', $result->message);
    }

    public function test_server_error_is_retryable(): void
    {
        Http::fake(['api.business.govt.nz/*' => Http::response('', 500)]);

        self::assertTrue($this->driver()->lookup('NZ', Identifier::registration('9429000001885'))->retryable);
    }

    public function test_dirty_input_is_normalised(): void
    {
        Http::fake(['api.business.govt.nz/*' => Http::response(Fixtures::get('nzbn/entity.json'))]);

        self::assertTrue($this->driver()->lookup('nz', Identifier::registration(' 942 9000 001 885 '))->isFound());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/entities/9429000001885'));
    }

    public function test_missing_key_is_unconfigured_without_a_call(): void
    {
        Http::fake();
        $driver = $this->driver([]);

        self::assertFalse($driver->isConfigured());
        self::assertTrue($driver->lookup('NZ', Identifier::registration('9429000001885'))->isUnconfigured());
        self::assertTrue($driver->search('NZ', 'jacobs', 5)->isUnconfigured());
        Http::assertNothingSent();
    }

    public function test_name_search(): void
    {
        Http::fake(['api.business.govt.nz/*' => Http::response(Fixtures::get('nzbn/search.json'))]);

        $result = $this->driver()->search('NZ', 'jacobs', 2);

        self::assertCount(2, $result->companies);
        self::assertSame('JACOBS DOUWE EGBERTS NZ', $result->companies[0]->name);
        self::assertSame('1002', $result->companies[0]->identifier(IdentifierType::Registration, 'NZ-COMPANY'));
        self::assertSame(CompanyStatus::Dissolved, $result->companies[1]->status);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/entities?')
            && $request['search-term'] === 'jacobs'
            && $request['page-size'] === 2);
    }
}
