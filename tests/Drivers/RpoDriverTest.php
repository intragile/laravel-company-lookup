<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\RpoDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures recorded from the live RPO API (api.statistics.sk/rpo/v1) on 2026-11-09.
 */
final class RpoDriverTest extends TestCase
{
    private function driver(): RpoDriver
    {
        return new RpoDriver($this->container()->make(Factory::class));
    }

    public function test_found_company_uses_the_current_name_and_address(): void
    {
        Http::fake(['api.statistics.sk/*' => Http::response(Fixtures::get('rpo/found.json'))]);

        $company = $this->driver()->lookup('SK', Identifier::registration('35763469'))->first();

        self::assertNotNull($company);
        self::assertSame('Slovak Telekom, a.s.', $company->name);
        self::assertSame('SK', $company->country);
        self::assertSame('35763469', $company->identifier(IdentifierType::Registration, 'SK-ICO'));
        self::assertSame('Bajkalská 28, 81762 Bratislava, SK', $company->address?->format());
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('1999-04-01', $company->foundedAt?->format('Y-m-d'));
        Http::assertSent(fn (Request $request): bool => $request['identifier'] === '35763469');
    }

    public function test_terminated_company_is_dissolved_with_its_last_name(): void
    {
        Http::fake(['api.statistics.sk/*' => Http::response(Fixtures::get('rpo/dissolved.json'))]);

        $company = $this->driver()->lookup('SK', Identifier::registration('45429812'))->first();

        self::assertSame('GrandShop spol. s r. o. „v likvidácii”', $company?->name);
        self::assertSame(CompanyStatus::Dissolved, $company->status);
        self::assertSame('Ktp. Jána Rašu 13', $company->address?->street);
    }

    public function test_unknown_ico_is_not_found(): void
    {
        Http::fake(['api.statistics.sk/*' => Http::response(Fixtures::get('rpo/not-found.json'))]);

        self::assertTrue($this->driver()->lookup('SK', Identifier::registration('12000019'))->isNotFound());
    }

    public function test_dirty_and_short_ico_is_normalised(): void
    {
        Http::fake(['api.statistics.sk/*' => Http::response(Fixtures::get('rpo/not-found.json'))]);

        $this->driver()->lookup('sk', Identifier::registration('SK 35 763 469'));
        $this->driver()->lookup('SK', Identifier::registration('151653'));

        Http::assertSent(fn (Request $request): bool => $request['identifier'] === '35763469');
        Http::assertSent(fn (Request $request): bool => $request['identifier'] === '00151653');
    }

    public function test_throttling_is_a_retryable_rate_limit(): void
    {
        Http::fake(['api.statistics.sk/*' => Http::response('', 429)]);

        $result = $this->driver()->lookup('SK', Identifier::registration('35763469'));

        self::assertSame(FailureReason::RateLimited, $result->reason);
        self::assertTrue($result->retryable);
        self::assertFalse($result->isCacheable());
        Http::assertSentCount(1);
    }

    public function test_name_search_respects_the_limit(): void
    {
        Http::fake(['api.statistics.sk/*' => Http::response(Fixtures::get('rpo/search.json'))]);

        $result = $this->driver()->search('SK', 'Slovak Telekom', 1);

        self::assertCount(1, $result->companies);
        self::assertSame('Slovak Telekom, a.s.', $result->first()?->name);
        Http::assertSent(fn (Request $request): bool => $request['fullName'] === 'Slovak Telekom');
    }

    public function test_attribution_is_declared(): void
    {
        self::assertStringContainsString('CC BY 4.0', (string) $this->driver()->capabilities()->attribution);
    }
}
