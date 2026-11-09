<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Feature\Bulk;

use Illuminate\Support\Facades\Schema;
use IntrAgile\CompanyLookup\CompanyLookup;

final class ImportCommandDisabledTest extends BulkTestCase
{
    protected bool $bulkEnabled = false;

    protected bool $migrate = false;

    public function test_refuses_when_bulk_is_disabled(): void
    {
        $this->command('company-lookup:import')
            ->expectsOutputToContain('Bulk datasets are disabled')
            ->assertExitCode(1);
    }

    public function test_migration_is_not_loaded_and_drivers_report_the_switch(): void
    {
        $this->command('migrate')->assertExitCode(0);

        self::assertFalse(Schema::hasTable('company_lookup_records'));

        $driver = $this->container()->make(CompanyLookup::class)->driver('lv-ur');
        self::assertFalse($driver->isConfigured());
    }
}
