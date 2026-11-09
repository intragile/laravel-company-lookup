<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Unit;

use IntrAgile\CompanyLookup\Drivers\Support\Normalize;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NormalizeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function dirtyIdentifiers(): iterable
    {
        yield 'hungarian vat with space' => ['HU', 'HU 10773381', '10773381'];
        yield 'czech lower-case prefix' => ['CZ', 'cz45274649', '45274649'];
        yield 'finnish business id with dash' => ['FI', '0112038-9', '01120389'];
        yield 'swiss uid with dots' => ['CH', 'CHE-105.909.036', '105909036'];
        yield 'greek vat uses EL' => ['GR', 'EL 094014201', '094014201'];
        yield 'greek vat with GR' => ['GR', 'GR094014201', '094014201'];
        yield 'polish nip with dashes' => ['PL', '526-025-09-95', '5260250995'];
        yield 'no prefix' => ['NO', ' 923 609 016 ', '923609016'];
    }

    #[DataProvider('dirtyIdentifiers')]
    public function test_it_strips_formatting_and_prefixes(string $country, string $input, string $expected): void
    {
        self::assertSame($expected, Normalize::withoutPrefix($country, $input));
    }

    public function test_it_maps_vat_prefixes_both_ways(): void
    {
        self::assertSame('EL', Normalize::vatPrefix('gr'));
        self::assertSame('GR', Normalize::countryFromVatPrefix('EL'));
        self::assertSame('CH', Normalize::countryFromVatPrefix('CHE'));
        self::assertSame('XI', Normalize::countryFromVatPrefix('xi'));
        self::assertSame('HU', Normalize::vatPrefix('HU'));
    }

    public function test_it_guesses_identifier_types(): void
    {
        self::assertSame(IdentifierType::Lei, Normalize::guessType('DE', '529900D6BF99LW9R2E68'));
        self::assertSame(IdentifierType::Vat, Normalize::guessType('HU', 'HU10773381'));
        self::assertSame(IdentifierType::Vat, Normalize::guessType('GR', 'EL094014201'));
        self::assertSame(IdentifierType::Vat, Normalize::guessType('CH', 'CHE-105.909.036'));
        self::assertSame(IdentifierType::Registration, Normalize::guessType('CZ', '45274649'));
        self::assertSame(IdentifierType::Registration, Normalize::guessType('NO', 'NO')); // prefix without digits
    }

    public function test_it_normalises_names(): void
    {
        self::assertSame('cez a s', Normalize::name('ČEZ, a. s.'));
        self::assertSame('magyar telekom nyrt', Normalize::name('  Magyar   Telekom Nyrt. '));
    }

    public function test_digits_and_compact(): void
    {
        self::assertSame('10773381', Normalize::digits('HU-1077 3381'));
        self::assertSame('CHE105909036', Normalize::compact('che-105.909.036'));
    }

    public function test_search_key_ignores_accents_in_latin_names_but_keeps_other_scripts(): void
    {
        self::assertSame('cez a s', Normalize::searchKey('ČEZ, a. s.'));
        self::assertSame('cafe', Normalize::name('café'));
        self::assertSame('台積電', Normalize::searchKey(' 台積電 '));
        self::assertNotSame(Normalize::searchKey('トヨタ'), Normalize::searchKey('ソニー'));
        self::assertSame('οτε', Normalize::searchKey('ΟΤΕ'));
    }
}
