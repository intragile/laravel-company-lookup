<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup;

/**
 * A postal address. Sources that only return one line of text fill in $freeText.
 */
final readonly class Address
{
    public function __construct(
        public ?string $street = null,
        public ?string $postalCode = null,
        public ?string $city = null,
        public ?string $region = null,
        public ?string $country = null,
        public ?string $freeText = null,
    ) {}

    /**
     * The address on one line, or null when nothing is known.
     */
    public function format(): ?string
    {
        if ($this->freeText !== null && $this->freeText !== '') {
            return $this->freeText;
        }

        $locality = trim(implode(' ', array_filter([$this->postalCode, $this->city], fn (?string $part): bool => $part !== null && $part !== '')));
        $parts = array_filter([$this->street, $locality, $this->region, $this->country], fn (?string $part): bool => $part !== null && $part !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @return array{street: string|null, postal_code: string|null, city: string|null, region: string|null, country: string|null, free_text: string|null, formatted: string|null}
     */
    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'postal_code' => $this->postalCode,
            'city' => $this->city,
            'region' => $this->region,
            'country' => $this->country,
            'free_text' => $this->freeText,
            'formatted' => $this->format(),
        ];
    }
}
