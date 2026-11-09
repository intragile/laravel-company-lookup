<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Bulk;

use DateTimeImmutable;
use IntrAgile\CompanyLookup\Drivers\Support\Normalize;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;

/**
 * Builds the row shape that BulkDataset::rows() yields.
 */
final class Record
{
    /**
     * Longest normalized name kept; the column is indexed.
     */
    public const NORMALIZED_NAME_LENGTH = 255;

    /**
     * @param  array<string, mixed>  $payload  Source fields not mapped to a column. Optional keys read by the
     *                                         driver: legal_form_code, vat, street, postal_code, city.
     * @return array{identifier: string, name: string, normalized_name: string, address: string|null, status: string, legal_form: string|null, founded_at: string|null, payload: array<string, mixed>}
     */
    public static function make(
        string $identifier,
        string $name,
        ?string $address,
        CompanyStatus $status,
        ?string $legalForm = null,
        ?DateTimeImmutable $foundedAt = null,
        array $payload = [],
    ): array {
        return [
            'identifier' => $identifier,
            'name' => $name,
            'normalized_name' => substr(Normalize::name($name), 0, self::NORMALIZED_NAME_LENGTH),
            'address' => $address === null || trim($address) === '' ? null : trim($address),
            'status' => $status->value,
            'legal_form' => $legalForm === null || trim($legalForm) === '' ? null : trim($legalForm),
            'founded_at' => $foundedAt?->format('Y-m-d'),
            'payload' => array_filter($payload, fn (mixed $value): bool => $value !== null && $value !== ''),
        ];
    }

    /**
     * Joins non-empty address parts with ", ".
     */
    public static function join(?string ...$parts): ?string
    {
        $parts = array_filter(array_map(fn (?string $part): string => trim((string) $part), $parts), fn (string $part): bool => $part !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }
}
