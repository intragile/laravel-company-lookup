<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Feature\Bulk;

use IntrAgile\CompanyLookup\CompanyLookup;
use IntrAgile\CompanyLookup\Identifier;

final class BulkDriverWithoutTableTest extends BulkTestCase
{
    protected bool $migrate = false;

    public function test_unconfigured_when_the_table_is_missing(): void
    {
        $result = $this->container()->make(CompanyLookup::class)->driver('ee-rik')->lookup('EE', Identifier::registration('10060701'));

        self::assertTrue($result->isUnconfigured());
        self::assertSame('php artisan company-lookup:import ee-rik', $result->message);
    }
}
