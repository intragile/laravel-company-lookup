<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\CroDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures built from the official documentation (CRO Open Services data dictionary and
 * help pages, https://services.cro.ie/datadict.aspx, helpcompany.aspx, helpcompanysearch.aspx),
 * not a live response. The 401 body reproduces the answer to a keyless call on 2026-11-09.
 */
final class CroDriverTest extends TestCase
{
    private const CONFIG = ['email' => 'test@cro.ie', 'key' => 'da093a04-c9d7-46d7-9c83-9c9f8630d5e0'];

    /**
     * @param  array<string, mixed>  $config
     */
    private function driver(array $config = self::CONFIG): CroDriver
    {
        return new CroDriver($this->container()->make(Factory::class), $config);
    }

    public function test_found_company_is_mapped(): void
    {
        Http::fake(['services.cro.ie/*' => Http::response(Fixtures::get('cro/company.json'))]);

        $company = $this->driver()->lookup('IE', Identifier::registration('104547'))->first();

        self::assertNotNull($company);
        self::assertSame('RYANAIR DESIGNATED ACTIVITY COMPANY', $company->name);
        self::assertSame('IE', $company->country);
        self::assertSame('104547', $company->identifier(IdentifierType::Registration));
        self::assertSame('RYANAIR DAC, AIRSIDE BUSINESS PARK, SWORDS, CO. DUBLIN, K67NY94', $company->address?->format());
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('DAC - Designated Activity Company limited by shares', $company->legalForm);
        self::assertSame('1153', $company->legalFormCode);
        self::assertSame('1984-11-28', $company->foundedAt?->format('Y-m-d'));
        // The documented example credentials produce the documented Basic header.
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://services.cro.ie/cws/company/104547/c?')
            && $request['format'] === 'json'
            && $request->header('Authorization') === ['Basic dGVzdEBjcm8uaWU6ZGEwOTNhMDQtYzlkNy00NmQ3LTljODMtOWM5Zjg2MzBkNWUw']);
    }

    public function test_unknown_number_is_not_found(): void
    {
        Http::fake(['services.cro.ie/*' => Http::response('', 404)]);

        self::assertTrue($this->driver()->lookup('IE', Identifier::registration('999999'))->isNotFound());
    }

    public function test_non_numeric_input_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('IE', Identifier::registration('ABC'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        Http::fake(['services.cro.ie/*' => Http::response('"Access denied.  Invalid API credentials in Header."', 401)]);

        $result = $this->driver()->lookup('IE', Identifier::registration('104547'));

        self::assertSame(FailureReason::Rejected, $result->reason);
        self::assertSame('Access denied.  Invalid API credentials in Header.', $result->message);
    }

    public function test_server_error_is_retryable(): void
    {
        Http::fake(['services.cro.ie/*' => Http::response('', 503)]);

        self::assertTrue($this->driver()->lookup('IE', Identifier::registration('104547'))->retryable);
    }

    public function test_dirty_input_is_normalised(): void
    {
        Http::fake(['services.cro.ie/*' => Http::response(Fixtures::get('cro/company.json'))]);

        self::assertTrue($this->driver()->lookup('ie', Identifier::registration(' IE 0104547 '))->isFound());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/cws/company/104547/c'));
    }

    public function test_missing_credentials_are_unconfigured_without_a_call(): void
    {
        Http::fake();
        $driver = $this->driver(['key' => 'only-a-key']);

        self::assertFalse($driver->isConfigured());
        self::assertTrue($driver->lookup('IE', Identifier::registration('104547'))->isUnconfigured());
        self::assertTrue($driver->search('IE', 'ryanair', 5)->isUnconfigured());
        Http::assertNothingSent();
    }

    public function test_name_search(): void
    {
        Http::fake(['services.cro.ie/*' => Http::response(Fixtures::get('cro/search.json'))]);

        $result = $this->driver()->search('IE', 'ryanair', 5);

        self::assertCount(2, $result->companies);
        self::assertSame('RYANAIR DESIGNATED ACTIVITY COMPANY', $result->companies[0]->name);
        self::assertSame(CompanyStatus::Dissolved, $result->companies[1]->status);
        self::assertSame('DUBLIN AIRPORT, CO. DUBLIN', $result->companies[1]->address?->format());
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://services.cro.ie/cws/companies?')
            && $request['company_name'] === 'ryanair'
            && $request['max'] === 5);
    }
}
