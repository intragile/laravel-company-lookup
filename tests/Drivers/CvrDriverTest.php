<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\CvrDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures built from the official documentation (Erhvervsstyrelsen, "Kom godt i gang med
 * Elasticsearch", https://erhvervsstyrelsen.dk/kom-godt-igang-med-elasticSearch — its search,
 * empty-result and scroll examples), not a live response.
 */
final class CvrDriverTest extends TestCase
{
    private const CONFIG = ['username' => 'BrugerId', 'password' => 'Password'];

    /**
     * @param  array<string, mixed>  $config
     */
    private function driver(array $config = self::CONFIG): CvrDriver
    {
        return new CvrDriver($this->container()->make(Factory::class), $config);
    }

    public function test_found_company_is_mapped(): void
    {
        Http::fake(['distribution.virk.dk/*' => Http::response(Fixtures::get('cvr/found.json'))]);

        $company = $this->driver()->lookup('DK', Identifier::registration('24256790'))->first();

        self::assertNotNull($company);
        self::assertSame('NOVO NORDISK A/S', $company->name);
        self::assertSame('DK', $company->country);
        self::assertSame('24256790', $company->identifier(IdentifierType::Registration));
        self::assertSame('DK24256790', $company->identifier(IdentifierType::Vat));
        self::assertSame('Novo Allé 1, 2880 Bagsværd, DK', $company->address?->format());
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('Aktieselskab', $company->legalForm);
        self::assertSame('60', $company->legalFormCode);
        self::assertSame('DB07', $company->activities[0]->scheme ?? null);
        self::assertSame('212000', $company->activities[0]->code ?? null);
        self::assertSame('1989-11-28', $company->foundedAt?->format('Y-m-d'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://distribution.virk.dk/cvr-permanent/virksomhed/_search'
            && $request->method() === 'POST'
            && $request['query'] === ['term' => ['Vrvirksomhed.cvrNummer' => 24256790]]
            && $request->header('Authorization') === ['Basic '.base64_encode('BrugerId:Password')]);
    }

    public function test_vat_number_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertNotContains(IdentifierType::Vat, $this->driver()->capabilities()->identifierTypes);
        self::assertTrue($this->driver()->lookup('DK', Identifier::vat('DK24256790'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_no_hits_is_not_found(): void
    {
        Http::fake(['distribution.virk.dk/*' => Http::response(Fixtures::get('cvr/not-found.json'))]);

        self::assertTrue($this->driver()->lookup('DK', Identifier::registration('12345678'))->isNotFound());
    }

    public function test_malformed_number_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('DK', Identifier::registration('1234567'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_rejected_credentials_are_a_rejected_failure(): void
    {
        Http::fake(['distribution.virk.dk/*' => Http::response('<html>401 Authorization Required</html>', 401)]);

        self::assertSame(FailureReason::Rejected, $this->driver()->lookup('DK', Identifier::registration('24256790'))->reason);
    }

    public function test_server_error_is_retryable(): void
    {
        Http::fake(['distribution.virk.dk/*' => Http::response('', 502)]);

        self::assertTrue($this->driver()->lookup('DK', Identifier::registration('24256790'))->retryable);
    }

    public function test_dirty_input_is_normalised(): void
    {
        Http::fake(['distribution.virk.dk/*' => Http::response(Fixtures::get('cvr/found.json'))]);

        self::assertTrue($this->driver()->lookup('dk', Identifier::registration('DK 24 25 67 90'))->isFound());
        Http::assertSent(fn (Request $request): bool => $request['query'] === ['term' => ['Vrvirksomhed.cvrNummer' => 24256790]]);
    }

    public function test_missing_credentials_are_unconfigured_without_a_call(): void
    {
        Http::fake();
        $driver = $this->driver(['username' => 'BrugerId']);

        self::assertFalse($driver->isConfigured());
        self::assertTrue($driver->lookup('DK', Identifier::registration('24256790'))->isUnconfigured());
        self::assertTrue($driver->search('DK', 'parken sport', 5)->isUnconfigured());
        Http::assertNothingSent();
    }

    public function test_name_search(): void
    {
        Http::fake(['distribution.virk.dk/*' => Http::response(Fixtures::get('cvr/search.json'))]);

        $result = $this->driver()->search('DK', 'parken sport', 5);

        self::assertCount(2, $result->companies);
        self::assertSame('PARKEN SPORT & ENTERTAINMENT A/S', $result->companies[0]->name);
        self::assertSame('15107707', $result->companies[0]->identifier(IdentifierType::Registration));
        self::assertSame('Nørrebrogade 20, 1, 2200 København N, DK', $result->companies[1]->address?->format());
        self::assertSame(CompanyStatus::Dissolved, $result->companies[1]->status);
        self::assertNull($result->companies[1]->identifier(IdentifierType::Vat));
        Http::assertSent(fn (Request $request): bool => $request['size'] === 5
            && $request['query'] === ['match' => ['Vrvirksomhed.virksomhedMetadata.nyesteNavn.navn' => ['query' => 'parken sport', 'operator' => 'and']]]);
    }
}
