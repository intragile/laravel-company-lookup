<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\BrasilApiDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Fixtures recorded from the live BrasilAPI CNPJ endpoint on 2026-11-09.
 * The status data provider varies the situation field of the recorded company.
 */
final class BrasilApiDriverTest extends TestCase
{
    private function driver(): BrasilApiDriver
    {
        return new BrasilApiDriver($this->container()->make(Factory::class));
    }

    public function test_found_company_with_address_activities_and_legal_form(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response(Fixtures::get('brasilapi/found.json'))]);

        $company = $this->driver()->lookup('BR', Identifier::registration('33000167000101'))->first();

        self::assertNotNull($company);
        self::assertSame('PETROLEO BRASILEIRO S A PETROBRAS', $company->name);
        self::assertSame('BR', $company->country);
        self::assertSame('brasilapi', $company->source);
        self::assertSame('33000167000101', $company->identifier(IdentifierType::Registration, 'BR-CNPJ'));
        self::assertSame('AVENIDA REPUBLICA DO CHILE, 65, CENTRO', $company->address?->street);
        self::assertSame('20031-170', $company->address->postalCode);
        self::assertSame('RIO DE JANEIRO', $company->address->city);
        self::assertSame('RJ', $company->address->region);
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('Sociedade de Economia Mista', $company->legalForm);
        self::assertSame('2038', $company->legalFormCode);
        self::assertSame('1966-09-28', $company->foundedAt?->format('Y-m-d'));
        self::assertSame(['0600001', '1921700', '3520401', '3520402', '4681801', '8630599'], array_map(fn ($activity): string => $activity->code, $company->activities));
        self::assertSame('CNAE', $company->activities[0]->scheme);
        self::assertSame('Extração de petróleo e gás natural', $company->activities[0]->description);
        self::assertSame('PETROBRAS - EDISE', $company->raw['nome_fantasia']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://brasilapi.com.br/api/cnpj/v1/33000167000101');
    }

    public function test_unknown_cnpj_is_not_found(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response(Fixtures::get('brasilapi/not-found.json'), 404)]);

        self::assertTrue($this->driver()->lookup('BR', Identifier::registration('99999999000191'))->isNotFound());
    }

    public function test_dirty_input_is_normalized(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response(Fixtures::get('brasilapi/found.json'))]);

        self::assertTrue($this->driver()->lookup('br', Identifier::registration('BR 33.000.167/0001-01'))->isFound());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/cnpj/v1/33000167000101'));
    }

    public function test_invalid_check_digits_are_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('BR', Identifier::registration('33000167000102'))->isNotFound());
        self::assertTrue($this->driver()->lookup('BR', Identifier::registration('11111111111111'))->isNotFound());
        self::assertTrue($this->driver()->lookup('BR', Identifier::registration('3300016700010'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_alphanumeric_cnpj_is_accepted(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response(Fixtures::get('brasilapi/not-found.json'), 404)]);

        self::assertTrue(BrasilApiDriver::validCnpj('12ABC34501DE35'));
        self::assertFalse(BrasilApiDriver::validCnpj('12ABC34501DE36'));
        self::assertTrue($this->driver()->lookup('BR', Identifier::registration('12.ABC.345/01DE-35'))->isNotFound());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/cnpj/v1/12ABC34501DE35'));
    }

    public function test_alphanumeric_cnpj_starting_with_br_keeps_its_letters(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response(Fixtures::get('brasilapi/not-found.json'), 404)]);

        self::assertTrue(BrasilApiDriver::validCnpj('BR123456000197'));
        $this->driver()->lookup('BR', Identifier::registration('BR.123.456/0001-97'));
        $this->driver()->lookup('BR', Identifier::registration('BR BR123456000197'));

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/cnpj/v1/BR123456000197'));
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/cnpj/v1/123456000197'));
    }

    public function test_server_error_is_retryable(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response('', 502)]);

        $result = $this->driver()->lookup('BR', Identifier::registration('33000167000101'));

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_bad_request_is_rejected(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response('{"message":"CNPJ 33.000.167/0001-02 inválido.","type":"bad_request","name":"BadRequestError"}', 400)]);

        $result = $this->driver()->lookup('BR', Identifier::registration('33000167000101'));

        self::assertSame(FailureReason::Rejected, $result->reason);
        self::assertStringContainsString('inválido', (string) $result->message);
    }

    /**
     * @return iterable<string, array{string, CompanyStatus}>
     */
    public static function statuses(): iterable
    {
        yield 'active' => ['ATIVA', CompanyStatus::Active];
        yield 'closed' => ['BAIXADA', CompanyStatus::Dissolved];
        yield 'suspended' => ['SUSPENSA', CompanyStatus::Inactive];
        yield 'unfit' => ['INAPTA', CompanyStatus::Inactive];
        yield 'null and void' => ['NULA', CompanyStatus::Dissolved];
    }

    #[DataProvider('statuses')]
    public function test_status_mapping(string $situation, CompanyStatus $expected): void
    {
        $fixture = Fixtures::json('brasilapi/found.json');
        $fixture['descricao_situacao_cadastral'] = $situation;
        Http::fake(['brasilapi.com.br/*' => Http::response($fixture)]);

        self::assertSame($expected, $this->driver()->lookup('BR', Identifier::registration('33000167000101'))->first()?->status);
    }

    public function test_capabilities_mark_the_community_mirror(): void
    {
        $capabilities = $this->driver()->capabilities();

        self::assertFalse($capabilities->official);
        self::assertStringContainsString('community mirror', $capabilities->label);
        self::assertFalse($capabilities->nameSearch);
        self::assertSame('33000167000101', $capabilities->sample);
        self::assertSame('BR', $capabilities->sampleCountry());
        self::assertTrue($this->driver()->search('BR', 'Petrobras', 5)->isNotFound());
    }
}
