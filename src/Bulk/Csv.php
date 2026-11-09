<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Bulk;

use Generator;
use RuntimeException;

/**
 * Streaming CSV reader for the bulk datasets.
 */
final class Csv
{
    /**
     * Yields each data row keyed by the header names. Rows whose column count differs from the header are skipped.
     *
     * @return Generator<int, array<string, string>>
     */
    public static function read(string $file, string $delimiter = ','): Generator
    {
        $handle = is_file($file) ? fopen($file, 'rb') : false;
        if ($handle === false) {
            throw new RuntimeException("Cannot open the CSV file [{$file}].");
        }

        try {
            // Skip a UTF-8 byte order mark so that a quoted first header is still parsed as quoted.
            if (fread($handle, 3) !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            $header = fgetcsv($handle, null, $delimiter, '"', '');
            if ($header === false) {
                return;
            }

            $header = array_map(fn (?string $name): string => trim((string) $name), $header);
            $width = count($header);

            while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                if (count($row) !== $width) {
                    continue;
                }

                yield array_combine($header, array_map(fn (?string $value): string => (string) $value, $row));
            }
        } finally {
            fclose($handle);
        }
    }
}
