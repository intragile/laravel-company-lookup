<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\GusRegonDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;
use RuntimeException;

/**
 * Fixtures recorded on 2026-11-09 from the GUS BIR 1.1 test environment with the public test key,
 * as raw MTOM multipart bodies. The test environment serves anonymised data (for example the street
 * "ul. Test-Krucza"). dissolved.txt is found.txt with DataZakonczeniaDzialalnosci and NrLokalu filled in;
 * nip-revoked.txt is found.txt with StatusNip set to "Uchylony".
 */
final class GusRegonDriverTest extends TestCase
{
    private const CONTENT_TYPE = 'multipart/related; type="application/xop+xml";start="<http://tempuri.org/0>";boundary="uuid:18a9c26f-fa18-42ed-97a5-6b91b2cb2c64+id=478382";start-info="application/soap+xml"';

    /**
     * @param  array<string, mixed>  $config
     */
    private function driver(array $config = ['key' => 'user-key'], ?Repository $cache = null): GusRegonDriver
    {
        return new GusRegonDriver($this->container()->make(Factory::class), $config, $cache ?? new Repository(new ArrayStore));
    }

    /**
     * Answers Zaloguj with the login fixture and each search with the next given fixture.
     */
    private function fakeService(string ...$searches): void
    {
        Http::fake(function (Request $request) use (&$searches) {
            $fixture = str_contains($request->body(), '<ns:Zaloguj>') ? 'login.txt' : array_shift($searches);

            if ($fixture === null) {
                throw new RuntimeException('Unexpected search request.');
            }

            return Http::response(Fixtures::get('gus-regon/'.$fixture), 200, ['Content-Type' => self::CONTENT_TYPE]);
        });
    }

    private static function isSearch(Request $request): bool
    {
        return str_contains($request->body(), '<ns:DaneSzukajPodmioty>');
    }

    public function test_found_company_is_mapped(): void
    {
        $this->fakeService('found.txt');

        $company = $this->driver()->lookup('PL', Identifier::registration('012100784'))->first();

        self::assertNotNull($company);
        self::assertSame('ORANGE POLSKA SPÓŁKA AKCYJNA', $company->name);
        self::assertSame('PL', $company->country);
        self::assertSame('gus-regon', $company->source);
        self::assertSame('012100784', $company->identifier(IdentifierType::Registration, 'PL-REGON'));
        self::assertSame('5260250995', $company->identifier(IdentifierType::Tax, 'PL-NIP'));
        self::assertSame('PL5260250995', $company->identifier(IdentifierType::Vat));
        self::assertSame('ul. Test-Krucza 160', $company->address?->street);
        self::assertSame('02-326', $company->address->postalCode);
        self::assertSame('Warszawa', $company->address->city);
        self::assertSame('MAZOWIECKIE', $company->address->region);
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('P', $company->legalFormCode);

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '<ns:pKluczUzytkownika>user-key</ns:pKluczUzytkownika>')
            && str_contains($request->body(), '<wsa:Action>http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/Zaloguj</wsa:Action>')
            && $request->url() === 'https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc'
            && str_starts_with($request->toPsrRequest()->getHeaderLine('Content-Type'), 'application/soap+xml'));
        Http::assertSent(fn (Request $request): bool => self::isSearch($request)
            && $request->header('sid') === ['6xf6znx9k3w2b7v8k6sn']
            && str_contains($request->body(), '<dat:Regon>012100784</dat:Regon>')
            && str_contains($request->body(), '<wsa:Action>http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</wsa:Action>'));
    }

    public function test_end_date_means_dissolved(): void
    {
        $this->fakeService('dissolved.txt');

        $company = $this->driver()->lookup('PL', Identifier::registration('012100784'))->first();

        self::assertSame(CompanyStatus::Dissolved, $company?->status);
        self::assertSame('ul. Test-Krucza 160/4', $company->address?->street);
    }

    public function test_revoked_nip_is_not_offered_as_vat_number(): void
    {
        $this->fakeService('nip-revoked.txt');

        $company = $this->driver()->lookup('PL', Identifier::registration('012100784'))->first();

        self::assertNotNull($company);
        self::assertSame('5260250995', $company->identifier(IdentifierType::Tax));
        self::assertNull($company->identifier(IdentifierType::Vat));
        self::assertNull($company->vatValid);
        self::assertSame('Uchylony', $company->raw['StatusNip']);
    }

    public function test_error_code_4_is_not_found(): void
    {
        $this->fakeService('not-found.txt');

        self::assertTrue($this->driver()->lookup('PL', Identifier::registration('000000000'))->isNotFound());
    }

    public function test_rejected_key_is_a_rejected_failure(): void
    {
        Http::fake(['*' => Http::response(Fixtures::get('gus-regon/login-rejected.txt'), 200, ['Content-Type' => self::CONTENT_TYPE])]);

        $result = $this->driver()->lookup('PL', Identifier::registration('012100784'));

        self::assertSame(FailureReason::Rejected, $result->reason);
        self::assertFalse($result->retryable);
        Http::assertSentCount(1);
    }

    public function test_server_error_is_retryable(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $result = $this->driver()->lookup('PL', Identifier::registration('012100784'));

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_session_is_cached(): void
    {
        $this->fakeService('found.txt', 'found.txt');
        $cache = new Repository(new ArrayStore);

        $this->driver(cache: $cache)->lookup('PL', Identifier::registration('012100784'));
        $this->driver(cache: $cache)->lookup('PL', Identifier::tax('5260250995'));

        Http::assertSentCount(3);
    }

    public function test_expired_session_logs_in_again_once(): void
    {
        $this->fakeService('no-session.txt', 'found.txt');
        $cache = new Repository(new ArrayStore);
        $cache->put('company-lookup:session:gus-regon:'.sha1('https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc|user-key'), 'expired-session', 60);

        $result = $this->driver(cache: $cache)->lookup('PL', Identifier::registration('012100784'));

        self::assertTrue($result->isFound());
        Http::assertSent(fn (Request $request): bool => self::isSearch($request) && $request->header('sid') === ['expired-session']);
        Http::assertSent(fn (Request $request): bool => self::isSearch($request) && $request->header('sid') === ['6xf6znx9k3w2b7v8k6sn']);
        Http::assertSentCount(3);
    }

    public function test_session_that_is_never_accepted_fails(): void
    {
        $this->fakeService('no-session.txt', 'no-session.txt');

        $result = $this->driver()->lookup('PL', Identifier::registration('012100784'));

        self::assertSame(FailureReason::Rejected, $result->reason);
        Http::assertSentCount(4);
    }

    public function test_dirty_nip_and_vat_numbers_are_normalised(): void
    {
        $cases = [
            [Identifier::vat('PL 526-025-09-95'), '<dat:Nip>5260250995</dat:Nip>'],
            [Identifier::tax('526 025 09 95'), '<dat:Nip>5260250995</dat:Nip>'],
            [Identifier::registration('01210078400000'), '<dat:Regon>01210078400000</dat:Regon>'],
        ];

        $this->fakeService('found.txt', 'found.txt', 'found.txt');
        $cache = new Repository(new ArrayStore);

        foreach ($cases as $index => [$identifier, $expected]) {
            self::assertTrue($this->driver(cache: $cache)->lookup('PL', $identifier)->isFound(), $identifier->value);

            $searches = Http::recorded(fn (Request $request): bool => self::isSearch($request));
            self::assertCount($index + 1, $searches);
            $last = $searches->last();
            self::assertNotNull($last);
            self::assertStringContainsString($expected, $last[0]->body(), $identifier->value);
        }
    }

    public function test_malformed_or_foreign_identifier_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('PL', Identifier::registration('1234'))->isNotFound());
        self::assertTrue($this->driver()->lookup('PL', Identifier::tax('123'))->isNotFound());
        self::assertTrue($this->driver()->lookup('CZ', Identifier::registration('012100784'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_sandbox_uses_the_test_endpoint_and_public_key(): void
    {
        $this->fakeService('found.txt');

        $driver = $this->driver(['sandbox' => true]);

        self::assertTrue($driver->isConfigured());
        self::assertTrue($driver->lookup('PL', Identifier::registration('012100784'))->isFound());
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://wyszukiwarkaregontest.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc'
            && str_contains($request->body(), '<ns:pKluczUzytkownika>abcde12345abcde12345</ns:pKluczUzytkownika>'));
    }

    public function test_missing_key_is_unconfigured(): void
    {
        Http::fake();
        $driver = $this->driver([]);

        self::assertFalse($driver->isConfigured());
        self::assertSame(['key' => 'COMPANY_LOOKUP_GUS_REGON_KEY'], $driver->capabilities()->credentials);
        self::assertTrue($driver->lookup('PL', Identifier::registration('012100784'))->isUnconfigured());
        Http::assertNothingSent();
    }
}
