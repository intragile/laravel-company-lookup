<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\IlRegistrarDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Fixtures recorded from the live data.gov.il datastore API on 2026-11-09.
 * The status values in the data provider are the ones observed live the same day.
 */
final class IlRegistrarDriverTest extends TestCase
{
    private function driver(): IlRegistrarDriver
    {
        return new IlRegistrarDriver($this->container()->make(Factory::class));
    }

    public function test_found_company_prefers_the_english_name(): void
    {
        Http::fake(['data.gov.il/*' => Http::response(Fixtures::get('il-registrar/found.json'))]);

        $company = $this->driver()->lookup('IL', Identifier::registration('520013954'))->first();

        self::assertNotNull($company);
        self::assertSame('TEVA PHARMACEUTICAL INDUSTRIES LIMITED', $company->name);
        self::assertSame('IL', $company->country);
        self::assertSame('il-registrar', $company->source);
        self::assertSame('520013954', $company->identifier(IdentifierType::Registration, 'IL-COMPANY'));
        self::assertSame('דבורה הנביאה 124', $company->address?->street);
        self::assertSame('תל אביב - יפו', $company->address->city);
        self::assertSame('6944020', $company->address->postalCode);
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('ישראלית חברה ציבורית', $company->legalForm);
        self::assertSame('1944-02-13', $company->foundedAt?->format('Y-m-d'));
        self::assertSame('טבע תעשיות פרמצבטיות בע~מ', $company->raw['שם חברה']);
        Http::assertSent(fn (Request $request): bool => $request['filters'] === '{"מספר חברה":520013954}'
            && $request['resource_id'] === IlRegistrarDriver::RESOURCE_ID);
    }

    public function test_hebrew_name_is_used_when_there_is_no_english_name(): void
    {
        Http::fake(['data.gov.il/*' => Http::response(Fixtures::get('il-registrar/struck-off.json'))]);

        $company = $this->driver()->lookup('IL', Identifier::registration('510000045'))->first();

        self::assertSame('פלנת טרוסט אנד אגנסו בעמ', $company?->name);
        self::assertSame(CompanyStatus::Dissolved, $company->status);
        self::assertNull($company->address);
        self::assertSame('1935-05-01', $company->foundedAt?->format('Y-m-d'));
    }

    public function test_unknown_number_is_not_found(): void
    {
        Http::fake(['data.gov.il/*' => Http::response(Fixtures::get('il-registrar/not-found.json'))]);

        self::assertTrue($this->driver()->lookup('IL', Identifier::registration('519999999'))->isNotFound());
    }

    public function test_dirty_input_is_normalized(): void
    {
        Http::fake(['data.gov.il/*' => Http::response(Fixtures::get('il-registrar/found.json'))]);

        self::assertTrue($this->driver()->lookup('il', Identifier::registration('IL 52-001395-4'))->isFound());
        Http::assertSent(fn (Request $request): bool => $request['filters'] === '{"מספר חברה":520013954}');
    }

    public function test_wrong_length_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('IL', Identifier::registration('52001395'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_empty_body_from_throttling_is_retryable(): void
    {
        Http::fake(['data.gov.il/*' => Http::response('')]);

        $result = $this->driver()->lookup('IL', Identifier::registration('520013954'));

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_html_error_page_is_an_invalid_response(): void
    {
        Http::fake(['data.gov.il/*' => Http::response('<!DOCTYPE html><title>שגיאה</title>', 404)]);

        $result = $this->driver()->lookup('IL', Identifier::registration('520013954'));

        self::assertSame(FailureReason::InvalidResponse, $result->reason);
        self::assertFalse($result->retryable);
    }

    public function test_english_name_search(): void
    {
        Http::fake(['data.gov.il/*' => Http::response(Fixtures::get('il-registrar/search-en.json'))]);

        $result = $this->driver()->search('IL', 'TEVA PHARMACEUTICAL', 5);

        self::assertSame('TEVA PHARMACEUTICAL INDUSTRIES LIMITED', $result->first()?->name);
        Http::assertSent(fn (Request $request): bool => $request['q'] === '{"שם באנגלית":"TEVA PHARMACEUTICAL"}' && str_contains($request->url(), 'limit=5'));
    }

    public function test_hebrew_name_search(): void
    {
        Http::fake(['data.gov.il/*' => Http::response(Fixtures::get('il-registrar/search-he.json'))]);

        $result = $this->driver()->search('IL', 'טבע תעשיות', 5);

        self::assertCount(5, $result->companies);
        self::assertSame('עונג טבע - תעשיות מזרונים בע"מ', $result->companies[0]->name);
        self::assertSame('COSTECH NATURAL TECHNOLOGIES INDUSTRIES LTD', $result->companies[1]->name);
        self::assertSame(CompanyStatus::Dissolved, $result->companies[2]->status);
        Http::assertSent(fn (Request $request): bool => $request['q'] === '{"שם חברה":"טבע תעשיות"}');
    }

    /**
     * @return iterable<string, array{string, CompanyStatus}>
     */
    public static function statuses(): iterable
    {
        yield 'active' => ['פעילה', CompanyStatus::Active];
        yield 'active, in receivership' => ['פעילה/בפירוק - בכינוס נכסים', CompanyStatus::Active];
        yield 'voluntary winding up' => ['בפרוק מרצון', CompanyStatus::Inactive];
        yield 'court winding up' => ['בפרוק ע~י בימ~ש', CompanyStatus::Inactive];
        yield 'struck off' => ['מחוקה', CompanyStatus::Dissolved];
        yield 'voluntarily liquidated' => ['מחוסלת מרצון', CompanyStatus::Dissolved];
        yield 'liquidated by court' => ['חיסול ע~י בימ~ש', CompanyStatus::Dissolved];
        yield 'removed from register' => ['נגרעה מהמרשם', CompanyStatus::Dissolved];
        yield 'unknown' => ['משהו אחר', CompanyStatus::Unknown];
    }

    #[DataProvider('statuses')]
    public function test_status_mapping(string $status, CompanyStatus $expected): void
    {
        $fixture = str_replace('"סטטוס חברה":"פעילה"', '"סטטוס חברה":'.json_encode($status, JSON_UNESCAPED_UNICODE), Fixtures::get('il-registrar/found.json'), $replaced);
        self::assertSame(1, $replaced);
        Http::fake(['data.gov.il/*' => Http::response($fixture)]);

        self::assertSame($expected, $this->driver()->lookup('IL', Identifier::registration('520013954'))->first()?->status);
    }

    public function test_capabilities(): void
    {
        $capabilities = $this->driver()->capabilities();

        self::assertSame('520013954', $capabilities->sample);
        self::assertSame('IL', $capabilities->sampleCountry());
        self::assertTrue($capabilities->nameSearch);
    }
}
