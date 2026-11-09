<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers\Support;

use Illuminate\Support\Str;
use IntrAgile\CompanyLookup\Enums\IdentifierType;

/**
 * Identifier clean-up shared by the drivers and the manager.
 */
final class Normalize
{
    /**
     * VAT prefixes that differ from the ISO country code.
     */
    private const VAT_PREFIXES = ['GR' => 'EL', 'CH' => 'CHE'];

    /**
     * Uppercase, without whitespace, dots, dashes, slashes and underscores.
     */
    public static function compact(string $value): string
    {
        return strtoupper((string) preg_replace('/[\s.\-\/_]+/u', '', $value));
    }

    /**
     * Digits only.
     */
    public static function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }

    /**
     * The VAT number prefix used for a country (GR → EL).
     */
    public static function vatPrefix(string $country): string
    {
        $country = strtoupper($country);

        return self::VAT_PREFIXES[$country] ?? $country;
    }

    /**
     * Country code from a VAT prefix (EL → GR, XI stays XI).
     */
    public static function countryFromVatPrefix(string $prefix): string
    {
        $prefix = strtoupper($prefix);
        $flipped = array_flip(self::VAT_PREFIXES);

        return $flipped[$prefix] ?? $prefix;
    }

    /**
     * Compacts the value and removes a leading country or VAT prefix.
     */
    public static function withoutPrefix(string $country, string $value): string
    {
        $value = self::compact($value);

        foreach (array_unique([self::vatPrefix($country), strtoupper($country)]) as $prefix) {
            if ($prefix !== '' && str_starts_with($value, $prefix)) {
                return substr($value, strlen($prefix));
            }
        }

        return $value;
    }

    /**
     * Best guess of the identifier type when the caller did not say.
     */
    public static function guessType(string $country, string $value): IdentifierType
    {
        $compact = self::compact($value);

        if (preg_match('/^[A-Z0-9]{18}\d{2}$/', $compact) === 1) {
            return IdentifierType::Lei;
        }

        $prefix = self::vatPrefix($country);
        if (str_starts_with($compact, $prefix) && preg_match('/\d/', substr($compact, strlen($prefix))) === 1) {
            return IdentifierType::Vat;
        }

        return IdentifierType::Registration;
    }

    /**
     * Lowercase ASCII form for name comparison: accents removed, punctuation collapsed.
     * Transliteration is platform-independent, so stored and searched names always agree.
     */
    public static function name(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($name))));
    }

    /**
     * A name in a form that identifies the search. Latin-script names use name(), so case, accents
     * and punctuation are ignored; names with letters of other scripts keep them, so two Japanese
     * or Greek names never collapse into the same key.
     */
    public static function searchKey(string $name): string
    {
        if (preg_match('/(?=\p{L})\P{Latin}/u', $name) !== 1) {
            return self::name($name);
        }

        return mb_strtolower(trim((string) preg_replace('/[\s\p{P}]+/u', ' ', $name)));
    }
}
