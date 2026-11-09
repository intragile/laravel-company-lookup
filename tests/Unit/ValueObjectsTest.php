<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Unit;

use DateTimeImmutable;
use IntrAgile\CompanyLookup\ActivityCode;
use IntrAgile\CompanyLookup\Address;
use IntrAgile\CompanyLookup\Capabilities;
use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupOutcome;
use IntrAgile\CompanyLookup\LookupResult;
use PHPUnit\Framework\TestCase;

final class ValueObjectsTest extends TestCase
{
    public function test_address_formats_parts_or_free_text(): void
    {
        self::assertSame('Duhová 1444/2, 140 00 Praha 4, CZ', (new Address('Duhová 1444/2', '140 00', 'Praha 4', country: 'CZ'))->format());
        self::assertSame('Line 1, Line 2', (new Address(street: 'ignored', freeText: 'Line 1, Line 2'))->format());
        self::assertNull((new Address)->format());
    }

    public function test_company_to_array(): void
    {
        $company = new Company(
            name: 'ČEZ, a. s.',
            country: 'CZ',
            source: 'ares',
            identifiers: [Identifier::registration('45274649', 'CZ-ICO'), Identifier::vat('CZ45274649')],
            address: new Address('Duhová 1444/2', '14000', 'Praha 4', country: 'CZ'),
            status: CompanyStatus::Active,
            legalForm: 'Akciová společnost',
            legalFormCode: '121',
            activities: [new ActivityCode('NACE', '35110', 'Výroba elektřiny')],
            foundedAt: new DateTimeImmutable('1992-05-06'),
            vatValid: true,
        );

        $array = $company->toArray();

        self::assertSame('ČEZ, a. s.', $array['name']);
        self::assertSame('active', $array['status']);
        self::assertSame('1992-05-06', $array['founded_at']);
        self::assertSame(['type' => 'registration', 'value' => '45274649', 'scheme' => 'CZ-ICO'], $array['identifiers'][0]);
        self::assertSame('Duhová 1444/2, 14000 Praha 4, CZ', $array['address']['formatted'] ?? null);
        self::assertSame('35110', $array['activities'][0]['code']);
        self::assertSame('CZ45274649', $company->identifier(IdentifierType::Vat));
        self::assertSame('45274649', $company->identifier(IdentifierType::Registration, 'CZ-ICO'));
        self::assertNull($company->identifier(IdentifierType::Registration, 'PL-KRS'));
    }

    public function test_fill_from_only_fills_gaps(): void
    {
        $vies = new Company('ČEZ, a. s.', 'CZ', 'vies', [Identifier::vat('CZ45274649')], new Address(freeText: 'Duhová 1444/2'), vatValid: true);
        $ares = new Company('CEZ AS', 'CZ', 'ares', [Identifier::registration('45274649', 'CZ-ICO'), Identifier::vat('CZ45274649')],
            new Address('Duhová'), CompanyStatus::Active, legalFormCode: '121', foundedAt: new DateTimeImmutable('1992-05-06'));

        $merged = $vies->fillFrom($ares);

        self::assertSame('ČEZ, a. s.', $merged->name);
        self::assertSame('vies', $merged->source);
        self::assertSame('Duhová 1444/2', $merged->address?->format());
        self::assertSame(CompanyStatus::Active, $merged->status);
        self::assertSame('121', $merged->legalFormCode);
        self::assertTrue($merged->vatValid);
        self::assertCount(2, $merged->identifiers);
    }

    public function test_lookup_result_states(): void
    {
        $company = new Company('X', 'HU', 'test');

        self::assertTrue(LookupResult::found('t', $company)->isCacheable());
        self::assertTrue(LookupResult::found('t')->isNotFound(), 'found() without companies is notFound');
        self::assertTrue(LookupResult::notFound('t')->isCacheable());
        $failed = LookupResult::failed('t', FailureReason::Unavailable, true, 'down');
        self::assertFalse($failed->isCacheable());
        self::assertTrue($failed->retryable);
        self::assertSame(FailureReason::Unavailable, $failed->reason);
        $unconfigured = LookupResult::unconfigured('t', 'COMPANY_LOOKUP_X_KEY');
        self::assertFalse($unconfigured->isCacheable());
        self::assertSame('COMPANY_LOOKUP_X_KEY', $unconfigured->message);
    }

    public function test_capabilities_country_matching(): void
    {
        $eu = new Capabilities('vies', 'VIES', ['HU', 'CZ'], [IdentifierType::Vat], false);
        $world = new Capabilities('gleif', 'GLEIF', ['*'], [IdentifierType::Lei], true);

        self::assertTrue($eu->supportsCountry(' hu '));
        self::assertFalse($eu->supportsCountry('US'));
        self::assertFalse($eu->supportsCountry(''));
        self::assertTrue($world->supportsCountry('JP'));
        self::assertFalse($world->supportsCountry(''));
        self::assertTrue($eu->supportsType(IdentifierType::Vat));
        self::assertFalse($eu->supportsType(IdentifierType::Lei));
        self::assertFalse($eu->requiresCredentials());
    }

    public function test_outcome_merges_same_country_only(): void
    {
        $outcome = new LookupOutcome([
            LookupResult::found('vies', new Company('A', 'HU', 'vies')),
            LookupResult::failed('nav', FailureReason::Timeout, true),
            LookupResult::found('gleif', new Company(null, 'DE', 'gleif', legalForm: 'GmbH')),
            LookupResult::found('other', new Company(null, 'HU', 'other', legalForm: 'Kft.')),
        ]);

        self::assertSame('Kft.', $outcome->merged()?->legalForm);
        self::assertSame(['nav'], $outcome->failedSources());
        self::assertFalse($outcome->isComplete());
        self::assertCount(3, $outcome->companies());
        self::assertNull((new LookupOutcome([]))->merged());
    }
}
