<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Drivers\SwissUidDriver;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Fixtures recorded from the live UID-WSE V5.0 public service on 2026-11-09.
 * fault-invalid-uid.xml is the live HTTP 500 SOAP fault for a UID with a wrong check digit.
 * The dissolved, server-fault and rate-limit cases are derived from the recorded fixtures.
 */
final class SwissUidDriverTest extends TestCase
{
    private function driver(): SwissUidDriver
    {
        return new SwissUidDriver($this->container()->make(Factory::class));
    }

    public function test_found_company_by_uid(): void
    {
        Http::fake(['www.uid-wse.admin.ch/*' => Http::response(Fixtures::get('swiss-uid/found.xml'))]);

        $result = $this->driver()->lookup('CH', Identifier::registration('CHE-105.909.036'));

        // Nestlé has a double seat, registered in Cham and in Vevey.
        self::assertCount(2, $result->companies);
        $company = $result->companies[0];
        self::assertSame('Nestlé AG', $company->name);
        self::assertSame('CH', $company->country);
        self::assertSame('swiss-uid', $company->source);
        self::assertSame('CHE-105.909.036', $company->identifier(IdentifierType::Registration, 'CH-UID'));
        self::assertSame('CH17030010013', $company->identifier(IdentifierType::Registration, 'CH-HR'));
        self::assertSame('CHE116281710MWST', $company->identifier(IdentifierType::Vat));
        self::assertSame('Zugerstrasse 8', $company->address?->street);
        self::assertSame('6330', $company->address->postalCode);
        self::assertSame('Cham', $company->address->city);
        self::assertSame('ZG', $company->address->region);
        self::assertSame('CH', $company->address->country);
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('0106', $company->legalFormCode);
        self::assertSame('Company limited by shares', $company->legalForm);
        self::assertTrue($company->vatValid);
        self::assertSame('Nestlé S.A.', $result->companies[1]->name);
        self::assertSame('Vevey', $result->companies[1]->address?->city);
        self::assertSame('CH55000672935', $result->companies[1]->identifier(IdentifierType::Registration, 'CH-HR'));

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('SOAPAction', '"http://www.uid.admin.ch/xmlns/uid-wse/IPublicServices/GetByUID"')
                && str_contains($request->body(), 'xmlns:uid="http://www.uid.admin.ch/xmlns/uid-wse"')
                && str_contains($request->body(), 'xmlns:ech97="http://www.ech.ch/xmlns/eCH-0097/5"')
                && str_contains($request->body(), '<ech97:uidOrganisationIdCategorie>CHE</ech97:uidOrganisationIdCategorie><ech97:uidOrganisationId>105909036</ech97:uidOrganisationId>');
        });
    }

    public function test_vat_number_with_suffix_finds_the_vat_registered_entity(): void
    {
        Http::fake(['www.uid-wse.admin.ch/*' => Http::response(Fixtures::get('swiss-uid/vat-group.xml'))]);

        $company = $this->driver()->lookup('CH', Identifier::vat('che 116 281 710 tva'))->first();

        self::assertSame('Groupe TVA, Nestlé S.A.', $company?->name);
        self::assertSame('CHE116281710MWST', $company->identifier(IdentifierType::Vat));
        self::assertSame('0302', $company->legalFormCode);
        self::assertNull($company->legalForm);
        self::assertSame('Avenue Nestlé 55', $company->address?->street);
        self::assertTrue($company->vatValid);
        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '<ech97:uidOrganisationId>116281710</ech97:uidOrganisationId>'));
    }

    public function test_vat_lookup_of_a_vat_group_member_is_not_found(): void
    {
        Http::fake(['www.uid-wse.admin.ch/*' => Http::response(Fixtures::get('swiss-uid/found.xml'))]);

        // Nestlé AG is VAT registered through its group, CHE-116.281.710, not under its own UID.
        self::assertTrue($this->driver()->lookup('CH', Identifier::vat('CHE-105.909.036 MWST'))->isNotFound());
    }

    public function test_uid_check_digit(): void
    {
        self::assertSame('116281710', SwissUidDriver::uid('CHE-116.281.710 MWST'));
        self::assertSame('105909036', SwissUidDriver::uid('che105909036'));
        self::assertSame('123456788', SwissUidDriver::uid('CHE-123.456.788'));
        self::assertNull(SwissUidDriver::uid('CHE-123.456.789'));
        self::assertNull(SwissUidDriver::uid('CHE-123.456'));
        self::assertSame('CHE-105.909.036', SwissUidDriver::format('105909036'));
    }

    public function test_invalid_check_digit_is_not_found_without_a_call(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->lookup('CH', Identifier::registration('CHE-123.456.789'))->isNotFound());
        self::assertTrue($this->driver()->lookup('LI', Identifier::registration('CHE-105.909.036'))->isNotFound());
        Http::assertNothingSent();
    }

    public function test_unknown_uid_is_not_found(): void
    {
        Http::fake(['www.uid-wse.admin.ch/*' => Http::response(Fixtures::get('swiss-uid/not-found.xml'))]);

        self::assertTrue($this->driver()->lookup('CH', Identifier::registration('CHE-123.456.788'))->isNotFound());
    }

    public function test_deleted_entity_is_dissolved(): void
    {
        $xml = str_replace('<uidregStatusEnterpriseDetail>3<', '<uidregStatusEnterpriseDetail>6<', Fixtures::get('swiss-uid/found.xml'));
        Http::fake(['www.uid-wse.admin.ch/*' => Http::response($xml)]);

        $company = $this->driver()->lookup('CH', Identifier::registration('CHE-105.909.036'))->first();

        self::assertSame(CompanyStatus::Dissolved, $company?->status);
    }

    public function test_client_fault_is_rejected_with_the_fault_text_and_not_retried(): void
    {
        Http::fake(['www.uid-wse.admin.ch/*' => Http::response(Fixtures::get('swiss-uid/fault-invalid-uid.xml'), 500)]);

        $result = $this->driver()->lookup('CH', Identifier::registration('CHE-105.909.036'));

        self::assertTrue($result->isFailed());
        self::assertSame(FailureReason::Rejected, $result->reason);
        self::assertFalse($result->retryable);
        self::assertSame('Data_validation_failed: Invalid UID. The checksum is invalid', $result->message);
        Http::assertSentCount(1);
    }

    public function test_server_fault_is_unavailable_and_retryable(): void
    {
        $xml = str_replace('s:Client', 's:Server', Fixtures::get('swiss-uid/fault-invalid-uid.xml'));
        Http::fake(['www.uid-wse.admin.ch/*' => Http::response($xml, 500)]);

        $result = $this->driver()->lookup('CH', Identifier::registration('CHE-105.909.036'));

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_http_error_without_envelope_is_unavailable(): void
    {
        Http::fake(['www.uid-wse.admin.ch/*' => Http::response('<html>Service Unavailable</html>', 503)]);

        $result = $this->driver()->lookup('CH', Identifier::registration('CHE-105.909.036'));

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_name_search(): void
    {
        Http::fake(['www.uid-wse.admin.ch/*' => Http::response(Fixtures::get('swiss-uid/search.xml'))]);

        $result = $this->driver()->search('CH', 'Nestlé Suisse', 3);

        self::assertCount(3, $result->companies);
        self::assertSame('Nestlé Waters (Suisse) SA', $result->companies[0]->name);
        self::assertSame('CHE-105.971.874', $result->companies[0]->identifier(IdentifierType::Registration, 'CH-UID'));
        self::assertSame('Henniez', $result->companies[0]->address?->city);
        self::assertSame('Nestlé AG', $result->companies[1]->name);

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('SOAPAction', '"http://www.uid.admin.ch/xmlns/uid-wse/IPublicServices/Search"')
                && str_contains($request->body(), 'xmlns:uid5="http://www.uid.admin.ch/xmlns/uid-wse/5"')
                && str_contains($request->body(), 'xmlns:shared="http://www.uid.admin.ch/xmlns/uid-wse-shared/2"')
                && str_contains($request->body(), '<uid:searchParameters><uid5:uidEntitySearchParameters><uid5:organisationName>Nestlé Suisse</uid5:organisationName>')
                && str_contains($request->body(), '<shared:maxNumberOfRecords>3</shared:maxNumberOfRecords>');
        });
    }

    public function test_search_escapes_the_name(): void
    {
        Http::fake(['www.uid-wse.admin.ch/*' => Http::response(Fixtures::get('swiss-uid/search-empty.xml'))]);

        self::assertTrue($this->driver()->search('CH', 'A & B <AG>', 5)->isNotFound());
        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '<uid5:organisationName>A &amp; B &lt;AG&gt;</uid5:organisationName>'));
    }

    public function test_rate_limit_fault_is_retryable(): void
    {
        // Derived from fault-invalid-uid.xml with the documented "Request_limit_exceeded" business fault error.
        $xml = str_replace(
            ['Data_validation_failed', 'Invalid UID. The checksum is invalid'],
            ['Request_limit_exceeded', 'Too many requests'],
            Fixtures::get('swiss-uid/fault-invalid-uid.xml'),
        );
        Http::fake(['www.uid-wse.admin.ch/*' => Http::response($xml, 500)]);

        $result = $this->driver()->lookup('CH', Identifier::registration('CHE-105.909.036'));

        self::assertSame(FailureReason::RateLimited, $result->reason);
        self::assertTrue($result->retryable);
        self::assertSame('Request_limit_exceeded: Too many requests', $result->message);
        Http::assertSentCount(1);
    }

    public function test_too_short_name_is_not_searched(): void
    {
        Http::fake();

        self::assertTrue($this->driver()->search('CH', ' a b ', 5)->isNotFound());
        Http::assertNothingSent();
    }
}
