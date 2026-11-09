<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\HoujinBangouDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures built from the official documentation (Web-API function overview and resource
 * definition, https://www.houjin-bangou.nta.go.jp/pc/webapi/images/k-web-api-kinou-gaiyo.pdf,
 * XML sample of section 2.3.1), not a live response.
 */
final class HoujinBangouDriverTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $config
     */
    private function driver(array $config = ['app_id' => 'APPID12345678']): HoujinBangouDriver
    {
        return new HoujinBangouDriver($this->container()->make(Factory::class), $config);
    }

    public function test_found_corporation_is_mapped(): void
    {
        Http::fake(['api.houjin-bangou.nta.go.jp/*' => Http::response(Fixtures::get('houjin-bangou/found.xml'))]);

        $company = $this->driver()->lookup('JP', Identifier::registration('1180301018771'))->first();

        self::assertNotNull($company);
        self::assertSame('TOYOTA MOTOR CORPORATION', $company->name);
        self::assertSame('JP', $company->country);
        self::assertSame('1180301018771', $company->identifier(IdentifierType::Registration));
        self::assertSame('愛知県豊田市トヨタ町１番地', $company->address?->format());
        self::assertSame('471-8571', $company->address->postalCode);
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('株式会社', $company->legalForm);
        self::assertSame('301', $company->legalFormCode);
        self::assertSame('トヨタ自動車株式会社', $company->raw['name'] ?? null);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.houjin-bangou.nta.go.jp/4/num?')
            && $request['id'] === 'APPID12345678'
            && $request['number'] === '1180301018771'
            && $request['type'] === '12');
    }

    public function test_unknown_number_is_not_found(): void
    {
        Http::fake(['api.houjin-bangou.nta.go.jp/*' => Http::response(Fixtures::get('houjin-bangou/not-found.xml'))]);

        self::assertTrue($this->driver()->lookup('JP', Identifier::registration('8040001999013'))->isNotFound());
    }

    public function test_invalid_check_digit_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('JP', Identifier::registration('2180301018771'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_unknown_application_id_is_rejected(): void
    {
        Http::fake(['api.houjin-bangou.nta.go.jp/*' => Http::response('', 404)]);

        $result = $this->driver()->lookup('JP', Identifier::registration('1180301018771'));

        self::assertSame(FailureReason::Rejected, $result->reason);
        self::assertFalse($result->retryable);
    }

    public function test_throttled_application_id_is_retryable(): void
    {
        Http::fake(['api.houjin-bangou.nta.go.jp/*' => Http::response('', 403)]);

        $result = $this->driver()->lookup('JP', Identifier::registration('1180301018771'));

        self::assertSame(FailureReason::RateLimited, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_connection_error_does_not_leak_the_application_id(): void
    {
        Http::fake(fn (Request $request) => throw new ConnectionException('cURL error 28: timed out for '.$request->url()));

        $result = $this->driver(['app_id' => 'SECRETAPPID01'])->lookup('JP', Identifier::registration('1180301018771'));

        self::assertSame(FailureReason::Timeout, $result->reason);
        self::assertStringNotContainsString('SECRETAPPID01', (string) $result->message);
    }

    public function test_server_error_is_retryable(): void
    {
        Http::fake(['api.houjin-bangou.nta.go.jp/*' => Http::response('', 500)]);

        self::assertSame(FailureReason::Unavailable, $this->driver()->lookup('JP', Identifier::registration('1180301018771'))->reason);
    }

    public function test_dirty_input_is_normalised(): void
    {
        Http::fake(['api.houjin-bangou.nta.go.jp/*' => Http::response(Fixtures::get('houjin-bangou/found.xml'))]);

        self::assertTrue($this->driver()->lookup('jp', Identifier::registration('1-1803-0101-8771'))->isFound());
        Http::assertSent(fn (Request $request): bool => $request['number'] === '1180301018771');
    }

    public function test_missing_application_id_is_unconfigured_without_a_call(): void
    {
        Http::fake();
        $driver = $this->driver([]);

        self::assertFalse($driver->isConfigured());
        self::assertTrue($driver->lookup('JP', Identifier::registration('1180301018771'))->isUnconfigured());
        self::assertTrue($driver->search('JP', 'トヨタ', 5)->isUnconfigured());
        Http::assertNothingSent();
    }

    public function test_name_search(): void
    {
        Http::fake(['api.houjin-bangou.nta.go.jp/*' => Http::response(Fixtures::get('houjin-bangou/search.xml'))]);

        $result = $this->driver()->search('JP', 'トヨタ', 5);

        self::assertCount(2, $result->companies);
        self::assertSame('トヨタ自動車株式会社', $result->companies[0]->name);
        self::assertSame(CompanyStatus::Dissolved, $result->companies[1]->status);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.houjin-bangou.nta.go.jp/4/name?')
            && $request['name'] === 'トヨタ'
            && $request['target'] === 1);
    }

    public function test_latin_name_searches_english_names_and_respects_the_limit(): void
    {
        Http::fake(['api.houjin-bangou.nta.go.jp/*' => Http::response(Fixtures::get('houjin-bangou/search.xml'))]);

        $result = $this->driver()->search('JP', 'TOYOTA', 1);

        self::assertCount(1, $result->companies);
        Http::assertSent(fn (Request $request): bool => $request['target'] === 3);
    }
}
