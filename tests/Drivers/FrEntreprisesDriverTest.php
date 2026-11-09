<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\FrEntreprisesDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures recorded from the live recherche-entreprises.api.gouv.fr API on 2026-11-09.
 */
final class FrEntreprisesDriverTest extends TestCase
{
    private function driver(): FrEntreprisesDriver
    {
        return new FrEntreprisesDriver($this->container()->make(Factory::class));
    }

    public function test_found_company_by_siren(): void
    {
        Http::fake(['recherche-entreprises.api.gouv.fr/*' => Http::response(Fixtures::get('fr-entreprises/found-siren.json'))]);

        $company = $this->driver()->lookup('FR', Identifier::registration('542 051 180'))->first();

        self::assertNotNull($company);
        self::assertSame('TOTALENERGIES SE (TOTALENERGIE SE)', $company->name);
        self::assertSame('FR', $company->country);
        self::assertSame('fr-entreprises', $company->source);
        self::assertSame('542051180', $company->identifier(IdentifierType::Registration, 'FR-SIREN'));
        self::assertSame('54205118000066', $company->identifier(IdentifierType::Registration, 'FR-SIRET'));
        self::assertSame('FR59542051180', $company->identifier(IdentifierType::Vat));
        self::assertSame('LA DEFENSE 6 2 PLACE JEAN MILLIER', $company->address?->street);
        self::assertSame('92400', $company->address->postalCode);
        self::assertSame('COURBEVOIE', $company->address->city);
        self::assertSame('FR', $company->address->country);
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('5800', $company->legalFormCode);
        self::assertSame('NAF', $company->activities[0]->scheme);
        self::assertSame('70.10Z', $company->activities[0]->code);
        self::assertSame('1954-01-01', $company->foundedAt?->format('Y-m-d'));
        Http::assertSent(fn (Request $request): bool => $request['q'] === '542051180');
    }

    public function test_found_company_by_siret_uses_the_matching_establishment(): void
    {
        Http::fake(['recherche-entreprises.api.gouv.fr/*' => Http::response(Fixtures::get('fr-entreprises/found-siret.json'))]);

        $company = $this->driver()->lookup('FR', Identifier::registration('542 051 180 00066'))->first();

        self::assertSame('542051180', $company?->identifier(IdentifierType::Registration, 'FR-SIREN'));
        self::assertSame('54205118000066', $company->identifier(IdentifierType::Registration, 'FR-SIRET'));
        self::assertSame('LA DEFENSE 6 2 PLACE JEAN MILLIER', $company->address?->street);
        Http::assertSent(fn (Request $request): bool => $request['q'] === '54205118000066');
    }

    public function test_siret_lookup_prefers_the_establishment_over_the_head_office(): void
    {
        // Derived from found-siret.json: the head office is given another SIRET and address.
        $data = Fixtures::json('fr-entreprises/found-siret.json');
        self::assertIsArray($data['results']);
        self::assertIsArray($data['results'][0]);
        $data['results'][0]['siege'] = ['siret' => '54205118000999', 'adresse' => '1 RUE AILLEURS 75001 PARIS', 'code_postal' => '75001', 'libelle_commune' => 'PARIS'];
        Http::fake(['recherche-entreprises.api.gouv.fr/*' => Http::response($data)]);

        $company = $this->driver()->lookup('FR', Identifier::registration('54205118000066'))->first();

        self::assertSame('54205118000066', $company?->identifier(IdentifierType::Registration, 'FR-SIRET'));
        self::assertSame('COURBEVOIE', $company->address?->city);
        self::assertSame('92400', $company->address->postalCode);
    }

    public function test_siren_lookup_uses_the_head_office(): void
    {
        $data = Fixtures::json('fr-entreprises/found-siret.json');
        self::assertIsArray($data['results']);
        self::assertIsArray($data['results'][0]);
        $data['results'][0]['siege'] = ['siret' => '54205118000999', 'adresse' => '1 RUE AILLEURS 75001 PARIS', 'code_postal' => '75001', 'libelle_commune' => 'PARIS'];
        Http::fake(['recherche-entreprises.api.gouv.fr/*' => Http::response($data)]);

        $company = $this->driver()->lookup('FR', Identifier::registration('542051180'))->first();

        self::assertSame('54205118000999', $company?->identifier(IdentifierType::Registration, 'FR-SIRET'));
        self::assertSame('PARIS', $company->address?->city);
    }

    public function test_vat_lookup_is_not_supported(): void
    {
        Http::fake();

        self::assertNotContains(IdentifierType::Vat, $this->driver()->capabilities()->identifierTypes);
        self::assertTrue($this->driver()->lookup('FR', Identifier::vat('FR59542051180'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_derived_vat_number_is_not_marked_valid(): void
    {
        Http::fake(['recherche-entreprises.api.gouv.fr/*' => Http::response(Fixtures::get('fr-entreprises/found-siren.json'))]);

        $company = $this->driver()->lookup('FR', Identifier::registration('542051180'))->first();

        self::assertSame('FR59542051180', $company?->identifier(IdentifierType::Vat));
        self::assertNull($company->vatValid);
    }

    public function test_vat_key_is_computed_from_the_siren(): void
    {
        self::assertSame('FR59542051180', FrEntreprisesDriver::vatNumber('542051180'));
        self::assertSame('FR39808883300', FrEntreprisesDriver::vatNumber('808883300'));
    }

    public function test_closed_company_is_dissolved(): void
    {
        Http::fake(['recherche-entreprises.api.gouv.fr/*' => Http::response(Fixtures::get('fr-entreprises/dissolved.json'))]);

        $company = $this->driver()->lookup('FR', Identifier::registration('808883300'))->first();

        self::assertSame('BOULANGERIE DUPONT', $company?->name);
        self::assertSame(CompanyStatus::Dissolved, $company->status);
        self::assertSame('26 AV DU 2EME BCP', $company->address?->street);
        self::assertSame('LUNEVILLE', $company->address->city);
        self::assertNull($company->identifier(IdentifierType::Vat));
    }

    public function test_unknown_siren_is_not_found(): void
    {
        Http::fake(['recherche-entreprises.api.gouv.fr/*' => Http::response(Fixtures::get('fr-entreprises/not-found.json'))]);

        self::assertTrue($this->driver()->lookup('FR', Identifier::registration('000000000'))->isNotFound());
    }

    public function test_result_with_another_siren_is_not_found(): void
    {
        Http::fake(['recherche-entreprises.api.gouv.fr/*' => Http::response(Fixtures::get('fr-entreprises/dissolved.json'))]);

        self::assertTrue($this->driver()->lookup('FR', Identifier::registration('542051180'))->isNotFound());
    }

    public function test_malformed_identifier_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('FR', Identifier::registration('12345'))->isNotFound());
        self::assertTrue($this->driver()->lookup('BE', Identifier::registration('542051180'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_rejected_query_is_a_failure(): void
    {
        Http::fake(['recherche-entreprises.api.gouv.fr/*' => Http::response(['erreur' => '3 caractères minimum pour les termes de la requête (ou utilisez au moins un filtre)'], 400)]);

        $result = $this->driver()->lookup('FR', Identifier::registration('542051180'));

        self::assertTrue($result->isFailed());
        self::assertSame(FailureReason::Rejected, $result->reason);
        self::assertFalse($result->retryable);
    }

    public function test_rate_limit_is_retryable(): void
    {
        Http::fake(['recherche-entreprises.api.gouv.fr/*' => Http::response('', 429)]);

        $result = $this->driver()->lookup('FR', Identifier::registration('542051180'));

        self::assertSame(FailureReason::RateLimited, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_name_search(): void
    {
        Http::fake(['recherche-entreprises.api.gouv.fr/*' => Http::response(Fixtures::get('fr-entreprises/search.json'))]);

        $result = $this->driver()->search('FR', 'TotalEnergies', 2);

        self::assertCount(2, $result->companies);
        self::assertSame('TOTALENERGIES MARKETING FRANCE', $result->companies[0]->name);
        self::assertSame('531680445', $result->companies[0]->identifier(IdentifierType::Registration, 'FR-SIREN'));
        self::assertSame('TOTALENERGIES CHARGING SERVICES', $result->companies[1]->name);
        Http::assertSent(fn (Request $request): bool => $request['q'] === 'TotalEnergies' && $request['per_page'] === 2);
    }

    public function test_too_short_name_is_not_searched(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->search('FR', 'ab', 5)->isNotFound());
        Http::assertNothingSent();
    }
}
