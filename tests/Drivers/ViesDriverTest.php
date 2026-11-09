<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\ViesDriver;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures recorded from the live VIES REST API on 2026-11-09, except unavailable.json,
 * which reproduces the MS_UNAVAILABLE answer observed for Lithuania the same day.
 */
final class ViesDriverTest extends TestCase
{
    private function driver(): ViesDriver
    {
        return new ViesDriver($this->container()->make(Factory::class));
    }

    public function test_found_company_with_name_and_address(): void
    {
        Http::fake(['ec.europa.eu/*' => Http::response(Fixtures::get('vies/found-cz.json'))]);

        $company = $this->driver()->lookup('CZ', Identifier::vat('CZ 452 746 49'))->first();

        self::assertNotNull($company);
        self::assertSame('ČEZ, a. s.', $company->name);
        self::assertSame('CZ', $company->country);
        self::assertSame('CZ45274649', $company->identifier(IdentifierType::Vat));
        self::assertSame('Duhová 1444/2, PRAHA 4 - MICHLE, 140 00 PRAHA 4', $company->address?->format());
        self::assertTrue($company->vatValid);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/ms/CZ/vat/45274649'));
    }

    public function test_hidden_name_and_address_become_null(): void
    {
        Http::fake(['ec.europa.eu/*' => Http::response(Fixtures::get('vies/hidden-de.json'))]);

        $company = $this->driver()->lookup('DE', Identifier::vat('DE812526315'))->first();

        self::assertNotNull($company);
        self::assertNull($company->name);
        self::assertNull($company->address);
        self::assertTrue($company->vatValid);
    }

    public function test_invalid_number_is_not_found(): void
    {
        Http::fake(['ec.europa.eu/*' => Http::response(Fixtures::get('vies/invalid-hu.json'))]);

        self::assertTrue($this->driver()->lookup('HU', Identifier::vat('10625790'))->isNotFound());
    }

    public function test_member_state_unavailable_is_a_retryable_failure(): void
    {
        Http::fake(['ec.europa.eu/*' => Http::response(Fixtures::get('vies/unavailable.json'))]);

        $result = $this->driver()->lookup('LT', Identifier::vat('LT100000000'));

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_greece_uses_the_el_prefix(): void
    {
        Http::fake(['ec.europa.eu/*' => Http::response(Fixtures::get('vies/invalid-hu.json'))]);

        $this->driver()->lookup('GR', Identifier::vat('GR094014201'));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/ms/EL/vat/094014201'));
    }

    public function test_country_outside_vies_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('NO', Identifier::vat('NO923609016MVA'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_server_error_is_retryable(): void
    {
        Http::fake(['ec.europa.eu/*' => Http::response('', 503)]);

        self::assertTrue($this->driver()->lookup('CZ', Identifier::vat('CZ45274649'))->retryable);
    }
}
