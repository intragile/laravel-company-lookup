<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\AbnDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures built from the official documentation (https://abr.business.gov.au/json/ and its
 * sample scripts abnlookup-sample.js / namelookup-sample.js), not a live response. The
 * invalid-GUID answer reproduces the body the service returned to a keyless call on 2026-11-09.
 */
final class AbnDriverTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $config
     */
    private function driver(array $config = ['guid' => '00000000-0000-0000-0000-000000000000']): AbnDriver
    {
        return new AbnDriver($this->container()->make(Factory::class), $config);
    }

    public function test_found_abn_is_mapped(): void
    {
        Http::fake(['abr.business.gov.au/*' => Http::response(Fixtures::get('abn/found.jsonp'))]);

        $company = $this->driver()->lookup('AU', Identifier::registration('33051775556'))->first();

        self::assertNotNull($company);
        self::assertSame('TELSTRA LIMITED', $company->name);
        self::assertSame('AU', $company->country);
        self::assertSame('33051775556', $company->identifier(IdentifierType::Registration, 'AU-ABN'));
        self::assertSame('051775556', $company->identifier(IdentifierType::Registration, 'AU-ACN'));
        self::assertSame('VIC', $company->address?->region);
        self::assertSame('3000', $company->address->postalCode);
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('Australian Public Company', $company->legalForm);
        self::assertSame('PUB', $company->legalFormCode);
        self::assertTrue($company->vatValid);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/json/AbnDetails.aspx')
            && $request['abn'] === '33051775556'
            && $request['guid'] === '00000000-0000-0000-0000-000000000000');
    }

    public function test_acn_uses_the_acn_endpoint(): void
    {
        Http::fake(['abr.business.gov.au/*' => Http::response(Fixtures::get('abn/found.jsonp'))]);

        self::assertTrue($this->driver()->lookup('AU', Identifier::registration('051 775 556'))->isFound());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/json/AcnDetails.aspx') && $request['acn'] === '051775556');
    }

    public function test_unknown_abn_is_not_found(): void
    {
        Http::fake(['abr.business.gov.au/*' => Http::response(Fixtures::get('abn/not-found.jsonp'))]);

        self::assertTrue($this->driver()->lookup('AU', Identifier::registration('56650620303'))->isNotFound());
    }

    public function test_invalid_checksum_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('AU', Identifier::registration('33051775557'))->isNotFound());
        self::assertTrue($this->driver()->lookup('AU', Identifier::registration('051775557'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_unrecognised_guid_is_rejected(): void
    {
        Http::fake(['abr.business.gov.au/*' => Http::response(Fixtures::get('abn/invalid-guid.jsonp'))]);

        $result = $this->driver()->lookup('AU', Identifier::registration('33051775556'));

        self::assertSame(FailureReason::Rejected, $result->reason);
        self::assertFalse($result->retryable);
    }

    public function test_unexpected_message_is_an_invalid_response(): void
    {
        Http::fake(['abr.business.gov.au/*' => Http::response('callback({"Abn":"","Message":"System problems have prevented this request from completing"})')]);

        $result = $this->driver()->lookup('AU', Identifier::registration('33051775556'));

        self::assertSame(FailureReason::InvalidResponse, $result->reason);
        self::assertFalse($result->isCacheable());
    }

    public function test_connection_error_does_not_leak_the_guid(): void
    {
        Http::fake(fn (Request $request) => throw new ConnectionException('cURL error 28: timed out for '.$request->url()));

        $result = $this->driver(['guid' => 'secret-guid-value'])->lookup('AU', Identifier::registration('33051775556'));

        self::assertSame(FailureReason::Timeout, $result->reason);
        self::assertStringNotContainsString('secret-guid-value', (string) $result->message);
    }

    public function test_server_error_is_retryable(): void
    {
        Http::fake(['abr.business.gov.au/*' => Http::response('', 503)]);

        $result = $this->driver()->lookup('AU', Identifier::registration('33051775556'));

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_dirty_input_is_normalised(): void
    {
        Http::fake(['abr.business.gov.au/*' => Http::response(Fixtures::get('abn/found.jsonp'))]);

        self::assertTrue($this->driver()->lookup('au', Identifier::registration('AU 33 051 775 556'))->isFound());
        Http::assertSent(fn (Request $request): bool => $request['abn'] === '33051775556');
    }

    public function test_missing_guid_is_unconfigured_without_a_call(): void
    {
        Http::fake();
        $driver = $this->driver([]);

        self::assertFalse($driver->isConfigured());
        self::assertTrue($driver->lookup('AU', Identifier::registration('33051775556'))->isUnconfigured());
        self::assertTrue($driver->search('AU', 'telstra', 5)->isUnconfigured());
        Http::assertNothingSent();
    }

    public function test_name_search(): void
    {
        Http::fake(['abr.business.gov.au/*' => Http::response(Fixtures::get('abn/names.jsonp'))]);

        $result = $this->driver()->search('AU', 'telstra', 5);

        self::assertCount(2, $result->companies);
        self::assertSame('TELSTRA LIMITED', $result->companies[0]->name);
        self::assertSame('33051775556', $result->companies[0]->identifier(IdentifierType::Registration));
        self::assertSame('VIC', $result->companies[1]->address?->region);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/json/MatchingNames.aspx')
            && $request['name'] === 'telstra'
            && $request['maxResults'] === 5);
    }

    public function test_empty_name_search_is_not_found(): void
    {
        Http::fake(['abr.business.gov.au/*' => Http::response('callback({"Message":"","Names":[]})')]);

        self::assertTrue($this->driver()->search('AU', 'zzzz', 5)->isNotFound());
    }
}
