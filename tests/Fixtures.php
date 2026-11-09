<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests;

use RuntimeException;

/**
 * Recorded registry responses under tests/fixtures/<driver>/.
 */
final class Fixtures
{
    public static function get(string $path): string
    {
        $file = __DIR__.'/fixtures/'.$path;
        $contents = is_file($file) ? file_get_contents($file) : false;

        if ($contents === false) {
            throw new RuntimeException("Missing fixture [{$path}].");
        }

        return $contents;
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function json(string $path): array
    {
        $decoded = json_decode(self::get($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Fixture [{$path}] is not a JSON array or object.");
        }

        return $decoded;
    }
}
