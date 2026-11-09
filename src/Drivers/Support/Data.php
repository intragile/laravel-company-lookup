<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers\Support;

use DateTimeImmutable;
use Throwable;

/**
 * Typed access to decoded JSON and arrays, with dot paths.
 */
final class Data
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function get(array $data, string $path): mixed
    {
        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * A trimmed string, or null when missing, empty or not scalar.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function string(array $data, string $path): ?string
    {
        return self::clean(self::get($data, $path));
    }

    public static function clean(mixed $value): ?string
    {
        if (is_bool($value) || ! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function bool(array $data, string $path): ?bool
    {
        $value = self::get($data, $path);

        return is_bool($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function int(array $data, string $path): ?int
    {
        $value = self::get($data, $path);

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    /**
     * An associative array, or an empty one.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function map(array $data, string $path): array
    {
        $value = self::get($data, $path);

        return is_array($value) ? $value : [];
    }

    /**
     * The array items that are themselves arrays.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<array<array-key, mixed>>
     */
    public static function list(array $data, string $path): array
    {
        return self::arrays(self::get($data, $path));
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public static function arrays(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * Decodes a JSON string into an array, or returns an empty array.
     *
     * @return array<array-key, mixed>
     */
    public static function json(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Parses a date with the given formats (default Y-m-d and ISO 8601), at midnight.
     *
     * @param  list<string>  $formats
     */
    public static function date(?string $value, array $formats = ['Y-m-d', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:s', 'd.m.Y']): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, trim($value));
            if ($date !== false) {
                return $date->setTime(0, 0);
            }
        }

        $prefix = substr(trim($value), 0, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $prefix);

        return $date === false ? null : $date;
    }
}
