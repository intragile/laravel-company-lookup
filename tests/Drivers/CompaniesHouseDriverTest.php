<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\CompaniesHouseDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures follow the companyProfile and companySearch resources and the error format of the
 * Companies House Public Data API specification (developer-specs.company-information.service.gov.uk):
 * from the documentation, not a live response. Field values for 00445790 are Tesco PLC's public
 * register entry; SC012345 is fictional.
 */
final class CompaniesHouseDriverTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $config
     */
    private function driver(array $config = ['key' => 'api-key']): CompaniesHouseDriver
    {
        return new CompaniesHouseDriver($this->container()->make(Factory::class), $config);
    }

    public function test_found_company_is_mapped(): void
    {
        Http::fake(['api.company-information.service.gov.uk/company/*' => Http::response(Fixtures::get('companies-house/profile.json'))]);

        $company = $this->driver()->lookup('GB', Identifier::registration('00445790'))->first();

        self::assertNotNull($company);
        self::assertSame('TESCO PLC', $company->name);
        self::assertSame('GB', $company->country);
        self::assertSame('companies-house', $company->source);
        self::assertSame('00445790', $company->identifier(IdentifierType::Registration, 'GB-COH'));
        self::assertSame('Tesco House Shire Park, Kestrel Way', $company->address?->street);
        self::assertSame('AL7 1GA', $company->address->postalCode);
        self::assertSame('Welwyn Garden City', $company->address->city);
        self::assertSame('GB', $company->address->country);
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('plc', $company->legalFormCode);
        self::assertSame('Public limited company', $company->legalForm);
        self::assertSame('1947-11-27', $company->foundedAt?->format('Y-m-d'));
        self::assertCount(1, $company->activities);
        self::assertSame('SIC2007', $company->activities[0]->scheme);
        self::assertSame('47110', $company->activities[0]->code);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.company-information.service.gov.uk/company/00445790'
            && $request->header('Authorization') === ['Basic '.base64_encode('api-key:')]);
    }

    public function test_dissolved_company(): void
    {
        Http::fake(['*' => Http::response(Fixtures::get('companies-house/profile-dissolved.json'))]);

        $company = $this->driver()->lookup('GB', Identifier::registration('SC012345'))->first();

        self::assertSame(CompanyStatus::Dissolved, $company?->status);
        self::assertSame('GB', $company->address?->country);
        self::assertCount(2, $company->activities);
    }

    public function test_overseas_address_keeps_unmapped_country_as_text(): void
    {
        $profile = Fixtures::json('companies-house/profile-dissolved.json');
        $profile['registered_office_address'] = ['address_line_1' => '1 Rue de Rivoli', 'locality' => 'Paris', 'postal_code' => '75001', 'country' => 'France'];
        $profile['service_address'] = null;
        $overseas = $profile;
        $overseas['registered_office_address'] = ['address_line_1' => 'Avenida Atlântica 1', 'locality' => 'Rio de Janeiro', 'country' => 'Brazil'];

        Http::fake(['*' => Http::sequence()->push($profile)->push($overseas)]);

        $france = $this->driver()->lookup('GB', Identifier::registration('SC012345'))->first();
        $brazil = $this->driver()->lookup('GB', Identifier::registration('SC012345'))->first();

        self::assertNotNull($france);
        self::assertSame('FR', $france->address?->country);
        self::assertNotNull($brazil);
        self::assertNotNull($brazil->address);
        self::assertNull($brazil->address->country);
        self::assertSame('Avenida Atlântica 1, Brazil', $brazil->address->street);
        self::assertSame('Rio de Janeiro', $brazil->address->city);
    }

    public function test_unknown_company_is_not_found(): void
    {
        Http::fake(['*' => Http::response(Fixtures::get('companies-house/not-found.json'), 404)]);

        self::assertTrue($this->driver()->lookup('GB', Identifier::registration('99999999'))->isNotFound());
    }

    public function test_rate_limit_is_retryable(): void
    {
        Http::fake(['*' => Http::response('', 429)]);

        $result = $this->driver()->lookup('GB', Identifier::registration('00445790'));

        self::assertSame(FailureReason::RateLimited, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_invalid_key_is_rejected(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Invalid Authorization', 'type' => 'ch:service'], 401)]);

        self::assertSame(FailureReason::Rejected, $this->driver()->lookup('GB', Identifier::registration('00445790'))->reason);
    }

    public function test_company_numbers_are_padded_and_prefixes_kept(): void
    {
        Http::fake(['*' => Http::response(Fixtures::get('companies-house/profile.json'))]);

        $this->driver()->lookup('GB', Identifier::registration(' 445 790 '));
        $this->driver()->lookup('GB', Identifier::registration('sc-12345'));
        $this->driver()->lookup('GB', Identifier::registration('NI000123'));
        $this->driver()->lookup('GB', Identifier::registration('ip 28746r'));
        $this->driver()->lookup('GB', Identifier::registration('R0000123'));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/company/00445790'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/company/SC012345'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/company/NI000123'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/company/IP28746R'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/company/R0000123'));
    }

    public function test_malformed_or_foreign_number_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('GB', Identifier::registration('123456789'))->isNotFound());
        self::assertTrue($this->driver()->lookup('GB', Identifier::registration('ABC'))->isNotFound());
        self::assertTrue($this->driver()->lookup('GB', Identifier::vat('GB220430231'))->isNotFound());
        self::assertTrue($this->driver()->lookup('IE', Identifier::registration('123456'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_name_search(): void
    {
        Http::fake(['api.company-information.service.gov.uk/search/companies*' => Http::response(Fixtures::get('companies-house/search.json'))]);

        $result = $this->driver()->search('GB', 'tesco', 2);

        self::assertCount(2, $result->companies);
        self::assertSame('TESCO PLC', $result->companies[0]->name);
        self::assertSame('00445790', $result->companies[0]->identifier(IdentifierType::Registration));
        self::assertSame(CompanyStatus::Active, $result->companies[0]->status);
        self::assertSame('Welwyn Garden City', $result->companies[0]->address?->city);
        self::assertSame('plc', $result->companies[0]->legalFormCode);
        self::assertSame(CompanyStatus::Dissolved, $result->companies[1]->status);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/search/companies?')
            && $request['q'] === 'tesco' && $request['items_per_page'] === 2);
    }

    public function test_name_search_without_results_is_not_found(): void
    {
        Http::fake(['*' => Http::response(Fixtures::get('companies-house/search-empty.json'))]);

        self::assertTrue($this->driver()->search('GB', 'zzzz no such company', 10)->isNotFound());
    }

    public function test_missing_key_is_unconfigured(): void
    {
        Http::fake();
        $driver = $this->driver([]);

        self::assertFalse($driver->isConfigured());
        self::assertSame(['key' => 'COMPANY_LOOKUP_COMPANIES_HOUSE_KEY'], $driver->capabilities()->credentials);
        self::assertTrue($driver->lookup('GB', Identifier::registration('00445790'))->isUnconfigured());
        self::assertTrue($driver->search('GB', 'tesco', 5)->isUnconfigured());
        self::assertTrue($this->driver()->isConfigured());
        Http::assertNothingSent();
    }
}
