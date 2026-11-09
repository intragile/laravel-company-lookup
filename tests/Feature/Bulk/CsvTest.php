<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Feature\Bulk;

use IntrAgile\CompanyLookup\Bulk\Csv;
use PHPUnit\Framework\TestCase;

final class CsvTest extends TestCase
{
    public function test_bom_before_a_quoted_header_is_skipped(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($file, "\xEF\xBB\xBF\"code\";\"name\"\r\n\"1\";\"Acme; Ltd\"\r\n2;Short\r\n3\r\n");

        $rows = iterator_to_array(Csv::read($file, ';'), false);
        unlink($file);

        self::assertSame([['code' => '1', 'name' => 'Acme; Ltd'], ['code' => '2', 'name' => 'Short']], $rows);
    }

    public function test_file_without_bom_keeps_its_first_bytes(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($file, "abc,def\n1,2\n");

        $rows = iterator_to_array(Csv::read($file), false);
        unlink($file);

        self::assertSame([['abc' => '1', 'def' => '2']], $rows);
    }
}
