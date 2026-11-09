<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup;

use IntrAgile\CompanyLookup\Enums\IdentifierType;

/**
 * An identifier of a company, such as a VAT number or a register number.
 */
final readonly class Identifier
{
    /**
     * @param  string|null  $scheme  National scheme name, for example "CZ-ICO", "PL-KRS", "FR-SIREN" or "US-CIK".
     */
    public function __construct(
        public IdentifierType $type,
        public string $value,
        public ?string $scheme = null,
    ) {}

    public static function vat(string $value): self
    {
        return new self(IdentifierType::Vat, $value);
    }

    public static function registration(string $value, ?string $scheme = null): self
    {
        return new self(IdentifierType::Registration, $value, $scheme);
    }

    public static function tax(string $value, ?string $scheme = null): self
    {
        return new self(IdentifierType::Tax, $value, $scheme);
    }

    public static function lei(string $value): self
    {
        return new self(IdentifierType::Lei, $value);
    }

    /**
     * @return array{type: string, value: string, scheme: string|null}
     */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'value' => $this->value, 'scheme' => $this->scheme];
    }
}
