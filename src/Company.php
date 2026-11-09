<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup;

use DateTimeImmutable;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\IdentifierType;

/**
 * A company as returned by one source. Fields the source does not provide are null.
 */
final readonly class Company
{
    /**
     * @param  string  $country  ISO 3166-1 alpha-2 country code of the registration.
     * @param  string  $source  Key of the driver that produced this record.
     * @param  list<Identifier>  $identifiers
     * @param  list<ActivityCode>  $activities
     * @param  array<array-key, mixed>  $raw  The decoded source response, for fields not mapped here.
     */
    public function __construct(
        public ?string $name,
        public string $country,
        public string $source,
        public array $identifiers = [],
        public ?Address $address = null,
        public CompanyStatus $status = CompanyStatus::Unknown,
        public ?string $legalForm = null,
        public ?string $legalFormCode = null,
        public array $activities = [],
        public ?DateTimeImmutable $foundedAt = null,
        public ?bool $vatValid = null,
        public array $raw = [],
    ) {}

    /**
     * The first identifier of the given type, optionally restricted to one scheme.
     */
    public function identifier(IdentifierType $type, ?string $scheme = null): ?string
    {
        foreach ($this->identifiers as $identifier) {
            if ($identifier->type === $type && ($scheme === null || $identifier->scheme === $scheme)) {
                return $identifier->value;
            }
        }

        return null;
    }

    /**
     * A copy with every null field filled from $other. Lists are merged without duplicates.
     */
    public function fillFrom(self $other): self
    {
        $identifiers = $this->identifiers;
        foreach ($other->identifiers as $candidate) {
            if ($this->identifier($candidate->type, $candidate->scheme) === null) {
                $identifiers[] = $candidate;
            }
        }

        return new self(
            name: $this->name ?? $other->name,
            country: $this->country,
            source: $this->source,
            identifiers: $identifiers,
            address: $this->address ?? $other->address,
            status: $this->status === CompanyStatus::Unknown ? $other->status : $this->status,
            legalForm: $this->legalForm ?? $other->legalForm,
            legalFormCode: $this->legalFormCode ?? $other->legalFormCode,
            activities: $this->activities === [] ? $other->activities : $this->activities,
            foundedAt: $this->foundedAt ?? $other->foundedAt,
            vatValid: $this->vatValid ?? $other->vatValid,
            raw: $this->raw,
        );
    }

    /**
     * @return array{name: string|null, country: string, source: string, identifiers: list<array{type: string, value: string, scheme: string|null}>, address: array<string, string|null>|null, status: string, legal_form: string|null, legal_form_code: string|null, activities: list<array{scheme: string, code: string, description: string|null}>, founded_at: string|null, vat_valid: bool|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'country' => $this->country,
            'source' => $this->source,
            'identifiers' => array_map(fn (Identifier $identifier): array => $identifier->toArray(), $this->identifiers),
            'address' => $this->address?->toArray(),
            'status' => $this->status->value,
            'legal_form' => $this->legalForm,
            'legal_form_code' => $this->legalFormCode,
            'activities' => array_map(fn (ActivityCode $activity): array => $activity->toArray(), $this->activities),
            'founded_at' => $this->foundedAt?->format('Y-m-d'),
            'vat_valid' => $this->vatValid,
        ];
    }
}
