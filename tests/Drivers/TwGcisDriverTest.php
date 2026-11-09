<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\TwGcisDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Fixtures recorded from the live GCIS open data API on 2026-11-09.
 * not-found.json is intentionally empty: the API answers HTTP 200 with an empty body when nothing matches.
 */
final class TwGcisDriverTest extends TestCase
{
    private function driver(): TwGcisDriver
    {
        return new TwGcisDriver($this->container()->make(Factory::class));
    }

    public function test_found_company_with_chinese_name_and_roc_date(): void
    {
        Http::fake(['data.gcis.nat.gov.tw/*' => Http::response(Fixtures::get('tw-gcis/found.json'))]);

        $company = $this->driver()->lookup('TW', Identifier::registration('22099131'))->first();

        self::assertNotNull($company);
        self::assertSame('台灣積體電路製造股份有限公司', $company->name);
        self::assertSame('TW', $company->country);
        self::assertSame('tw-gcis', $company->source);
        self::assertSame('22099131', $company->identifier(IdentifierType::Registration, 'TW-UBN'));
        self::assertSame('新竹科學園區新竹市力行六路8號', $company->address?->format());
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('1987-02-21', $company->foundedAt?->format('Y-m-d'));
        self::assertSame('魏哲家', $company->raw['Responsible_Name']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/5F64D864-61CB-4D0D-8AD9-492047CC1EA6?')
            && $request['$filter'] === 'Business_Accounting_NO eq 22099131');
    }

    public function test_revoked_company_is_dissolved(): void
    {
        Http::fake(['data.gcis.nat.gov.tw/*' => Http::response(Fixtures::get('tw-gcis/revoked.json'))]);

        $company = $this->driver()->lookup('TW', Identifier::registration('07727175'))->first();

        self::assertSame(CompanyStatus::Dissolved, $company?->status);
        self::assertSame('1984-09-11', $company->foundedAt?->format('Y-m-d'));
    }

    public function test_empty_body_is_not_found(): void
    {
        Http::fake(['data.gcis.nat.gov.tw/*' => Http::response(Fixtures::get('tw-gcis/not-found.json'))]);

        self::assertTrue($this->driver()->lookup('TW', Identifier::registration('99999999'))->isNotFound());
    }

    public function test_dirty_input_is_normalized(): void
    {
        Http::fake(['data.gcis.nat.gov.tw/*' => Http::response(Fixtures::get('tw-gcis/found.json'))]);

        self::assertTrue($this->driver()->lookup('tw', Identifier::registration('TW 2209-9131'))->isFound());
        Http::assertSent(fn (Request $request): bool => $request['$filter'] === 'Business_Accounting_NO eq 22099131');
    }

    public function test_wrong_length_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('TW', Identifier::registration('2209913'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_server_error_is_retryable(): void
    {
        Http::fake(['data.gcis.nat.gov.tw/*' => Http::response('', 503)]);

        $result = $this->driver()->lookup('TW', Identifier::registration('22099131'));

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_html_answer_is_an_invalid_response(): void
    {
        Http::fake(['data.gcis.nat.gov.tw/*' => Http::response('<html>maintenance</html>')]);

        self::assertSame(FailureReason::InvalidResponse, $this->driver()->lookup('TW', Identifier::registration('22099131'))->reason);
    }

    public function test_name_search_covers_approved_companies(): void
    {
        Http::fake(['data.gcis.nat.gov.tw/*' => Http::response(Fixtures::get('tw-gcis/search.json'))]);

        $result = $this->driver()->search('TW', ' 宏碁 ', 3);

        self::assertSame(['16097917', '16168877', '20828393'], array_map(fn ($company): ?string => $company->identifier(IdentifierType::Registration), $result->companies));
        self::assertSame('宏碁股份有限公司', $result->companies[2]->name);
        self::assertSame('1979-07-18', $result->companies[2]->foundedAt?->format('Y-m-d'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/6BBA2268-1367-4B42-9CCA-BC17499EBE8C?')
            && $request['$filter'] === 'Company_Name like 宏碁 and Company_Status eq 01'
            && str_contains($request->url(), '%24top=3'));
    }

    public function test_multi_word_name_sends_the_longest_token_and_filters_the_rest(): void
    {
        Http::fake(['data.gcis.nat.gov.tw/*' => Http::response(Fixtures::get('tw-gcis/search.json'))]);

        $result = $this->driver()->search('TW', '宏碁 股份有限公司', 3);

        self::assertSame(['16097917', '20828393'], array_map(fn ($company): ?string => $company->identifier(IdentifierType::Registration), $result->companies));
        Http::assertSent(fn (Request $request): bool => $request['$filter'] === 'Company_Name like 股份有限公司 and Company_Status eq 01'
            && str_contains($request->url(), '%24top=100'));
    }

    public function test_ideographic_space_splits_tokens(): void
    {
        Http::fake(['data.gcis.nat.gov.tw/*' => Http::response(Fixtures::get('tw-gcis/search.json'))]);

        $result = $this->driver()->search('TW', "宏碁\u{3000}汽車用品", 3);

        self::assertSame(['16168877'], array_map(fn ($company): ?string => $company->identifier(IdentifierType::Registration), $result->companies));
        Http::assertSent(fn (Request $request): bool => $request['$filter'] === 'Company_Name like 汽車用品 and Company_Status eq 01');
    }

    public function test_filter_operators_in_the_name_never_reach_the_filter(): void
    {
        Http::fake(['data.gcis.nat.gov.tw/*' => Http::response(Fixtures::get('tw-gcis/search.json'))]);

        self::assertTrue($this->driver()->search('TW', '宏碁 or Company_Status eq 09', 3)->isNotFound());
        Http::assertSent(fn (Request $request): bool => $request['$filter'] === 'Company_Name like Company_Status and Company_Status eq 01');
    }

    public function test_whitespace_only_name_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->search('TW', " \u{3000} ", 3)->isNotFound());
        Http::assertNothingSent();
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function rocDates(): iterable
    {
        yield 'three-digit year' => ['0760221', '1987-02-21'];
        yield 'year above 100' => ['1150821', '2026-08-21'];
        yield 'empty' => ['', null];
        yield 'impossible date' => ['0760231', null];
    }

    #[DataProvider('rocDates')]
    public function test_roc_dates(string $roc, ?string $expected): void
    {
        self::assertSame($expected, TwGcisDriver::rocDate($roc)?->format('Y-m-d'));
    }

    public function test_capabilities(): void
    {
        $capabilities = $this->driver()->capabilities();

        self::assertSame('22099131', $capabilities->sample);
        self::assertSame('TW', $capabilities->sampleCountry());
        self::assertTrue($capabilities->nameSearch);
    }
}
