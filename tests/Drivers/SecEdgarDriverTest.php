<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\SecEdgarDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;
use RuntimeException;

/**
 * Fixtures recorded from the live SEC EDGAR APIs on 2026-11-09.
 * found-apple.json and found-foreign.json have their filing history cut to two entries; company-tickers.json is an extract
 * of the live ticker list (the first 15 entries plus every title containing "APPLE").
 */
final class SecEdgarDriverTest extends TestCase
{
    private const UA = 'IntrAgile Kft. info@intragile.eu';

    private function driver(?string $userAgent = self::UA): SecEdgarDriver
    {
        return new SecEdgarDriver(
            $this->container()->make(Factory::class),
            ['user_agent' => $userAgent],
            Cache::store('array'),
        );
    }

    public function test_found_company_with_identifiers_address_and_sic(): void
    {
        Http::fake(['data.sec.gov/*' => Http::response(Fixtures::get('sec-edgar/found-apple.json'))]);

        $company = $this->driver()->lookup('US', Identifier::registration('320193'))->first();

        self::assertNotNull($company);
        self::assertSame('Apple Inc.', $company->name);
        self::assertSame('US', $company->country);
        self::assertSame('sec-edgar', $company->source);
        self::assertSame('0000320193', $company->identifier(IdentifierType::Registration, 'US-CIK'));
        self::assertSame('942404110', $company->identifier(IdentifierType::Tax, 'US-EIN'));
        self::assertNull($company->identifier(IdentifierType::Lei));
        self::assertSame('ONE APPLE PARK WAY, 95014 CUPERTINO, CA, US', $company->address?->format());
        self::assertSame('CA', $company->address->region);
        self::assertSame('SIC', $company->activities[0]->scheme);
        self::assertSame('3571', $company->activities[0]->code);
        self::assertSame('Electronic Computers', $company->activities[0]->description);
        self::assertSame(CompanyStatus::Unknown, $company->status);
        self::assertSame('CA', $company->raw['stateOfIncorporation']);
        self::assertArrayNotHasKey('filings', $company->raw);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://data.sec.gov/submissions/CIK0000320193.json'
            && $request->header('User-Agent') === [self::UA]);
    }

    public function test_missing_user_agent_is_unconfigured_without_a_call(): void
    {
        Http::fake();

        $driver = $this->driver(null);

        self::assertFalse($driver->isConfigured());
        self::assertTrue($driver->lookup('US', Identifier::registration('320193'))->isUnconfigured());
        self::assertTrue($driver->search('US', 'Apple', 5)->isUnconfigured());
        self::assertSame(['user_agent' => 'COMPANY_LOOKUP_SEC_EDGAR_USER_AGENT'], $driver->capabilities()->credentials);
        Http::assertNothingSent();
    }

    public function test_unknown_cik_is_not_found(): void
    {
        Http::fake(['data.sec.gov/*' => Http::response(Fixtures::get('sec-edgar/not-found.xml'), 404, ['Content-Type' => 'application/xml'])]);

        self::assertTrue($this->driver()->lookup('US', Identifier::registration('0000000001'))->isNotFound());
    }

    public function test_dirty_input_is_normalized(): void
    {
        Http::fake(['data.sec.gov/*' => Http::response(Fixtures::get('sec-edgar/found-apple.json'))]);

        self::assertTrue($this->driver()->lookup('us', Identifier::registration(' CIK 0000-320193 '))->isFound());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/submissions/CIK0000320193.json'));
    }

    public function test_non_numeric_or_too_long_cik_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('US', Identifier::registration('AAPL'))->isNotFound());
        self::assertTrue($this->driver()->lookup('US', Identifier::registration('12345678901'))->isNotFound());
        self::assertTrue($this->driver()->lookup('US', Identifier::registration('0000000000'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_foreign_filer_gets_its_own_country(): void
    {
        Http::fake(['data.sec.gov/*' => Http::response(Fixtures::get('sec-edgar/found-foreign.json'))]);

        $company = $this->driver()->lookup('US', Identifier::registration('1467373'))->first();

        self::assertSame('Accenture plc', $company?->name);
        self::assertSame('IE', $company->address?->country);
        self::assertNull($company->address->region);
        self::assertSame('1 GRAND CANAL SQUARE, GRAND CANAL HARBOUR', $company->address->street);
        self::assertSame('DUBLIN', $company->address->city);
    }

    public function test_foreign_location_flag_with_an_edgar_province_code(): void
    {
        $fixture = str_replace(
            '"stateOrCountry": "L2",
      "zipCode": "D2",
      "stateOrCountryDescription": "Ireland",
      "isForeignLocation": null,
      "foreignStateTerritory": null,
      "country": null,
      "countryCode": null',
            '"stateOrCountry": null,
      "zipCode": "K2P 1L4",
      "stateOrCountryDescription": null,
      "isForeignLocation": 1,
      "foreignStateTerritory": "ONTARIO",
      "country": null,
      "countryCode": "A6"',
            Fixtures::get('sec-edgar/found-foreign.json'),
            $replaced,
        );
        self::assertSame(1, $replaced);
        Http::fake(['data.sec.gov/*' => Http::response($fixture)]);

        $address = $this->driver()->lookup('US', Identifier::registration('1467373'))->first()?->address;

        self::assertSame('CA', $address?->country);
        self::assertSame('ONTARIO', $address->region);
    }

    public function test_rate_threshold_403_is_retryable(): void
    {
        Http::fake(['data.sec.gov/*' => Http::response('<h1>Request Rate Threshold Exceeded</h1>', 403)]);

        $result = $this->driver()->lookup('US', Identifier::registration('320193'));

        self::assertSame(FailureReason::RateLimited, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_forbidden_is_rejected(): void
    {
        Http::fake(['data.sec.gov/*' => Http::response('Your Request Originates from an Undeclared Automated Tool', 403)]);

        $result = $this->driver()->lookup('US', Identifier::registration('320193'));

        self::assertSame(FailureReason::Rejected, $result->reason);
        self::assertFalse($result->retryable);
    }

    public function test_throttling_is_retryable(): void
    {
        Http::fake(['data.sec.gov/*' => Http::response('', 429)]);

        $result = $this->driver()->lookup('US', Identifier::registration('320193'));

        self::assertSame(FailureReason::RateLimited, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_name_search_ranks_exact_and_prefix_matches_first(): void
    {
        Http::fake(['www.sec.gov/*' => Http::response(Fixtures::get('sec-edgar/company-tickers.json'))]);

        $result = $this->driver()->search('US', 'apple', 3);

        self::assertSame(['Apple Inc.', 'Apple Hospitality REIT, Inc.'], array_map(fn ($company): ?string => $company->name, $result->companies));
        self::assertSame('0000320193', $result->first()?->identifier(IdentifierType::Registration, 'US-CIK'));
        self::assertSame(['AAPL'], $result->first()->raw['tickers']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.sec.gov/files/company_tickers.json'
            && $request->header('User-Agent') === [self::UA]);
    }

    public function test_ticker_list_is_cached(): void
    {
        Http::fake(['www.sec.gov/*' => Http::response(Fixtures::get('sec-edgar/company-tickers.json'))]);

        $this->driver()->search('US', 'apple', 3);
        $result = $this->driver()->search('US', 'microsoft', 3);

        self::assertSame('MICROSOFT CORP', $result->first()?->name);
        self::assertSame('0000789019', $result->first()->identifier(IdentifierType::Registration));
        Http::assertSentCount(1);
    }

    public function test_ticker_cache_entry_is_compact_and_keyed_by_url(): void
    {
        Http::fake(['www.sec.gov/*' => Http::response(Fixtures::get('sec-edgar/company-tickers.json'))]);

        $this->driver()->search('US', 'apple', 3);

        $entry = Cache::store('array')->get('company-lookup:sec-edgar:tickers:'.sha1('https://www.sec.gov/files/company_tickers.json'));
        self::assertIsString($entry);
        self::assertLessThan(strlen(Fixtures::get('sec-edgar/company-tickers.json')), strlen($entry));
    }

    public function test_cache_write_failure_is_ignored(): void
    {
        Http::fake(['www.sec.gov/*' => Http::response(Fixtures::get('sec-edgar/company-tickers.json'))]);
        $cache = $this->createStub(Repository::class);
        $cache->method('get')->willReturn(null);
        $cache->method('put')->willThrowException(new RuntimeException('Item too large'));

        $driver = new SecEdgarDriver($this->container()->make(Factory::class), ['user_agent' => self::UA], $cache);

        self::assertSame('Apple Inc.', $driver->search('US', 'apple', 1)->first()?->name);
    }

    public function test_name_without_a_match_is_not_found(): void
    {
        Http::fake(['www.sec.gov/*' => Http::response(Fixtures::get('sec-edgar/company-tickers.json'))]);

        self::assertTrue($this->driver()->search('US', 'Nonexistent Widgets', 3)->isNotFound());
    }

    public function test_ticker_list_failure_is_reported(): void
    {
        Http::fake(['www.sec.gov/*' => Http::response('', 503)]);

        $result = $this->driver()->search('US', 'apple', 3);

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_capabilities(): void
    {
        $capabilities = $this->driver()->capabilities();

        self::assertSame('0000320193', $capabilities->sample);
        self::assertSame('US', $capabilities->sampleCountry());
        self::assertTrue($capabilities->nameSearch);
    }
}
