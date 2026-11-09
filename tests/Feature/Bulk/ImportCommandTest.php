<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Feature\Bulk;

use IntrAgile\CompanyLookup\Tests\Fixtures;

final class ImportCommandTest extends BulkTestCase
{
    public function test_imports_the_requested_datasets_and_prints_the_report(): void
    {
        $this->fakeDownloads();

        $this->command('company-lookup:import', ['dataset' => ['lv-ur', 'ee-rik']])
            ->expectsOutputToContain('Importing lv-ur')
            ->expectsTable(['Dataset', 'Imported', 'Deleted', 'Duration', 'Result'], [
                ['lv-ur', '20', '0', '0.0 s', 'ok'],
                ['ee-rik', '17', '0', '0.0 s', 'ok'],
            ])
            ->assertExitCode(0);

        self::assertSame(20, $this->records('lv-ur'));
        self::assertSame(17, $this->records('ee-rik'));
    }

    public function test_imports_every_enabled_dataset_by_default(): void
    {
        $this->fakeDownloads();
        config()->set('company-lookup.drivers.ee-rik.enabled', false);

        $this->command('company-lookup:import')->assertExitCode(0);

        self::assertSame(20, $this->records('lv-ur'));
        self::assertSame(0, $this->records('ee-rik'));
        self::assertSame(4, $this->records('be-kbo'));
    }

    public function test_a_truncated_file_is_refused_unless_forced(): void
    {
        $this->fakeDownloads();
        $this->command('company-lookup:import', ['dataset' => ['lv-ur']])->assertExitCode(0);

        $this->fakeDownloads($this->firstLines(10));

        $this->command('company-lookup:import', ['dataset' => ['lv-ur']])
            ->expectsOutputToContain('lv-ur: refused to delete unseen rows. Only 9 rows against 20 stored (minimum 18)')
            ->assertExitCode(1);
        self::assertSame(20, $this->records('lv-ur'));

        $this->command('company-lookup:import', ['dataset' => ['lv-ur'], '--force' => true])->assertExitCode(0);
        self::assertSame(9, $this->records('lv-ur'));
    }

    public function test_rejects_unknown_datasets(): void
    {
        $this->command('company-lookup:import', ['dataset' => ['xx']])
            ->expectsOutputToContain('Unknown dataset: xx')
            ->assertExitCode(1);
    }

    public function test_reports_failures_with_a_non_zero_exit_code(): void
    {
        $this->fakeDownloads("regcode;name\n");

        $this->command('company-lookup:import', ['dataset' => ['lv-ur']])
            ->expectsOutputToContain('lv-ur: The [lv-ur] file contained no records')
            ->assertExitCode(1);
    }

    /**
     * The header plus the first records of the Latvian fixture.
     */
    private function firstLines(int $lines): string
    {
        return implode("\n", array_slice(explode("\n", Fixtures::get('bulk/lv-ur/register.csv')), 0, $lines))."\n";
    }
}
