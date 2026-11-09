<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Feature\Bulk;

use Generator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use IntrAgile\CompanyLookup\Bulk\Datasets\BelgiumKbo;
use IntrAgile\CompanyLookup\Bulk\Datasets\EstoniaRik;
use IntrAgile\CompanyLookup\Bulk\Datasets\LatviaUr;
use IntrAgile\CompanyLookup\Bulk\Importer;
use IntrAgile\CompanyLookup\Bulk\Record;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use RuntimeException;
use ZipArchive;

final class ImporterTest extends BulkTestCase
{
    public function test_latvia_import_maps_the_csv(): void
    {
        $this->fakeDownloads();

        $report = $this->importer()->import(new LatviaUr);

        self::assertSame('lv-ur', $report->dataset);
        self::assertSame(20, $report->imported);
        self::assertSame(0, $report->deleted);
        self::assertSame(20, $this->records('lv-ur'));

        $row = DB::table('company_lookup_records')->where('identifier', '41502039692')->first();
        self::assertNotNull($row);
        self::assertSame('LV', $row->country);
        self::assertSame('IK "D.Luxe"', $row->name);
        self::assertSame('ik d luxe', $row->normalized_name);
        self::assertSame('Daugavpils, Aroniju iela 66, LV-5414', $row->address);
        self::assertSame('active', $row->status);
        self::assertSame('Individuālais komersants', $row->legal_form);
        self::assertSame('2020-03-05', $row->founded_at);

        $closed = DB::table('company_lookup_records')->where('identifier', '41202013815')->first();
        self::assertSame('dissolved', $closed?->status);

        Http::assertSent(fn (Request $request): bool => $request->url() === LatviaUr::URL
            && str_starts_with($request->toPsrRequest()->getHeaderLine('User-Agent'), 'intragile-laravel-company-lookup/1.x'));
    }

    public function test_import_is_idempotent(): void
    {
        $this->fakeDownloads();

        $this->importer()->import(new LatviaUr);
        $second = $this->importer()->import(new LatviaUr);

        self::assertSame(20, $second->imported);
        self::assertSame(0, $second->deleted);
        self::assertSame(20, $this->records('lv-ur'));
    }

    public function test_a_record_removed_from_the_source_disappears(): void
    {
        $this->fakeDownloads();
        $this->importer()->import(new LatviaUr);

        $lines = explode("\n", trim(Fixtures::get('bulk/lv-ur/register.csv')));
        $shorter = implode("\n", array_filter($lines, fn (string $line): bool => ! str_starts_with($line, '41502039692;')))."\n";
        $this->fakeDownloads($shorter);

        $report = $this->importer()->import(new LatviaUr);

        self::assertSame(19, $report->imported);
        self::assertSame(1, $report->deleted);
        self::assertSame(19, $this->records('lv-ur'));
        self::assertFalse(DB::table('company_lookup_records')->where('identifier', '41502039692')->exists());
    }

    public function test_an_empty_file_keeps_the_stored_data(): void
    {
        $this->fakeDownloads();
        $this->importer()->import(new LatviaUr);

        $this->fakeDownloads("regcode;sepa;name\n");

        try {
            $this->importer()->import(new LatviaUr);
            self::fail('An empty file must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('no records', $exception->getMessage());
        }

        self::assertSame(20, $this->records('lv-ur'));
    }

    public function test_estonia_zip_import_maps_status_and_vat(): void
    {
        $this->fakeDownloads();

        $report = $this->importer()->import(new EstoniaRik);

        self::assertSame(17, $report->imported);

        $first = DB::table('company_lookup_records')->where('identifier', '16752073')->first();
        self::assertNotNull($first);
        self::assertSame('EE', $first->country);
        self::assertSame('007 Agent & Partners OÜ', $first->name);
        self::assertSame('Osaühing', $first->legal_form);
        self::assertSame('active', $first->status);
        self::assertSame('2023-06-05', $first->founded_at);
        self::assertSame('Regati pst 12, 11911 Pirita linnaosa, Tallinn, Harju maakond', $first->address);

        self::assertSame('inactive', DB::table('company_lookup_records')->where('identifier', '11979116')->value('status'));
        self::assertSame('inactive', DB::table('company_lookup_records')->where('identifier', '11876557')->value('status'));

        $json = DB::table('company_lookup_records')->where('identifier', '11694365')->value('payload');
        self::assertIsString($json);
        $payload = json_decode($json, true);
        self::assertIsArray($payload);
        self::assertSame('EE101335276', $payload['vat'] ?? null);
    }

    public function test_belgium_import_logs_in_and_joins_the_files(): void
    {
        $this->fakeDownloads();

        $report = $this->importer()->import(new BelgiumKbo(['username' => 'user@example.com', 'password' => 'secret']));

        self::assertSame(4, $report->imported);

        $acme = DB::table('company_lookup_records')->where('identifier', '0123456749')->first();
        self::assertNotNull($acme);
        self::assertSame('Acme Belgium', $acme->name);
        self::assertSame('Rue de la Loi 16, 1000 Bruxelles', $acme->address);
        self::assertSame('Société anonyme', $acme->legal_form);
        self::assertSame('active', $acme->status);
        self::assertSame('2003-02-01', $acme->founded_at);

        $liquidation = DB::table('company_lookup_records')->where('identifier', '0987654394')->first();
        self::assertNotNull($liquidation);
        self::assertSame('inactive', $liquidation->status);
        self::assertSame('Rue Léopold 5 bte 2, 4000 Liège', $liquidation->address);

        $person = DB::table('company_lookup_records')->where('identifier', '0555555523')->first();
        self::assertNotNull($person);
        self::assertSame('Bakkerij Janssens', $person->name);
        self::assertNull($person->address);

        $holding = DB::table('company_lookup_records')->where('identifier', '0444444496')->first();
        self::assertNotNull($holding);
        self::assertSame('Société à responsabilité limitée', $holding->legal_form);
        self::assertSame('Damrak 1, City Atrium, 1012 Amsterdam, Pays-Bas', $holding->address);

        self::assertFalse(DB::table('company_lookup_records')->where('identifier', '2123456789')->exists());

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/static/j_spring_security_check')
            && $request['j_username'] === 'user@example.com' && $request['j_password'] === 'secret');
    }

    public function test_belgium_login_failure_is_reported(): void
    {
        Http::fake(['*' => Http::response('<input name="j_username">')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('KBO Open Data login failed');

        $this->importer()->import(new BelgiumKbo);
    }

    public function test_belgium_needs_credentials(): void
    {
        config()->set('company-lookup.drivers.be-kbo.username', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('COMPANY_LOOKUP_KBO_USERNAME');

        $this->importer()->import(new BelgiumKbo);
    }

    public function test_missing_table_is_reported(): void
    {
        Schema::drop('company_lookup_records');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('php artisan migrate');

        $this->importer()->import(new LatviaUr);
    }

    public function test_failed_download_is_reported(): void
    {
        Http::fake(['*' => Http::response('gone', 503)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 503');

        $this->importer()->import(new LatviaUr);
    }

    public function test_a_truncated_file_is_refused_and_keeps_unseen_rows(): void
    {
        $this->fakeDownloads();
        $this->importer()->import(new LatviaUr);

        $lines = explode("\n", Fixtures::get('bulk/lv-ur/register.csv'));
        $this->fakeDownloads(implode("\n", array_slice($lines, 0, 11))."\n");

        $report = $this->importer()->import(new LatviaUr);

        self::assertTrue($report->wasRefused());
        self::assertSame(10, $report->imported);
        self::assertSame(0, $report->deleted);
        self::assertStringContainsString('Only 10 rows against 20 stored (minimum 18)', (string) $report->refused);
        self::assertSame(20, $this->records('lv-ur'));

        $forced = $this->importer()->import(new LatviaUr, force: true);

        self::assertFalse($forced->wasRefused());
        self::assertSame(10, $forced->deleted);
        self::assertSame(10, $this->records('lv-ur'));
    }

    public function test_min_ratio_is_configurable(): void
    {
        config()->set('company-lookup.bulk.min_ratio', 0.4);
        $this->fakeDownloads();
        $this->importer()->import(new LatviaUr);

        $lines = explode("\n", Fixtures::get('bulk/lv-ur/register.csv'));
        $this->fakeDownloads(implode("\n", array_slice($lines, 0, 11))."\n");

        self::assertSame(10, $this->importer()->import(new LatviaUr)->deleted);
    }

    public function test_rows_failing_midway_leave_the_stored_data_untouched(): void
    {
        $this->fakeDownloads();
        $this->importer()->import(new LatviaUr);
        $before = DB::table('company_lookup_records')->orderBy('identifier')->get(['identifier', 'name', 'imported_at'])->all();

        $failing = $this->inlineDataset('lv-ur', function (string $file): Generator {
            $count = 0;
            foreach ((new LatviaUr)->rows($file) as $row) {
                if (++$count === 5) {
                    throw new RuntimeException('parse error at row 5');
                }

                yield $row;
            }
        }, LatviaUr::URL);

        try {
            $this->importer()->import($failing);
            self::fail('The failing dataset must throw.');
        } catch (RuntimeException $exception) {
            self::assertSame('parse error at row 5', $exception->getMessage());
        }

        self::assertEquals($before, DB::table('company_lookup_records')->orderBy('identifier')->get(['identifier', 'name', 'imported_at'])->all());
    }

    public function test_many_rows_span_several_chunks(): void
    {
        Http::fake(['https://example.test/*' => fn () => Http::response('x')]);
        $dataset = $this->inlineDataset('test-many', function (): Generator {
            for ($i = 1; $i <= 250; $i++) {
                yield Record::make((string) $i, 'Company '.$i, null, CompanyStatus::Active);
            }
        });

        $first = $this->importer()->import($dataset);
        $second = $this->importer()->import($dataset);

        self::assertSame([250, 250, 0], [$first->imported, $second->imported, $second->deleted]);
        self::assertSame(250, $this->records('test-many'));
    }

    public function test_the_work_directory_is_removed_after_success_and_failure(): void
    {
        $base = $this->tempBase();
        $this->fakeDownloads();

        $this->importer()->import(new LatviaUr);
        self::assertSame([], $this->entries($base));

        $this->fakeDownloads("regcode;name\n");
        try {
            $this->importer()->import(new LatviaUr);
        } catch (RuntimeException) {
        }
        self::assertSame([], $this->entries($base));

        rmdir($base);
    }

    public function test_zip_members_cannot_escape_the_work_directory(): void
    {
        $base = $this->tempBase();
        $zipFile = $base.'/../slip.zip';
        $zip = new ZipArchive;
        $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('../../x.csv', "regcode;name\n1;Slip\n");
        $zip->close();
        $body = (string) file_get_contents($zipFile);
        unlink($zipFile);

        Http::fake(['https://example.test/*' => Http::response($body)]);
        $seen = null;
        $dataset = $this->inlineDataset('test-slip', function (string $file) use (&$seen): Generator {
            $seen = realpath($file);
            yield Record::make('1', 'Slip', null, CompanyStatus::Active);
        });

        $this->importer()->import($dataset);

        $real = (string) realpath($base);
        self::assertIsString($seen);
        self::assertStringStartsWith($real.'/company-lookup-', $seen);
        self::assertSame('x.csv', basename($seen));
        self::assertFileDoesNotExist($base.'/x.csv');
        self::assertFileDoesNotExist(dirname($base).'/x.csv');
        self::assertSame([], $this->entries($base));

        rmdir($base);
    }

    public function test_kbo_credentials_never_appear_in_errors(): void
    {
        Http::fake(['*' => Http::response('<input name="j_username">')]);

        try {
            $this->importer()->import(new BelgiumKbo(['username' => 'user@example.com', 'password' => 'secret']));
            self::fail('The login must fail.');
        } catch (RuntimeException $exception) {
            self::assertStringNotContainsString('secret', $exception->getMessage());
            self::assertStringNotContainsString('user@example.com', $exception->getMessage());
        }
    }

    public function test_kbo_login_sends_the_csrf_token_when_present(): void
    {
        Http::fake([
            BelgiumKbo::BASE_URL.'/login' => Http::response('<form><input type="hidden" name="_csrf" value="tok-123"/></form>'),
        ]);
        $this->fakeDownloads();

        $this->importer()->import(new BelgiumKbo(['username' => 'user@example.com', 'password' => 'secret']));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/static/j_spring_security_check')
            && $request['_csrf'] === 'tok-123');
    }

    private function importer(): Importer
    {
        return $this->container()->make(Importer::class);
    }

    private function tempBase(): string
    {
        $base = sys_get_temp_dir().'/company-lookup-test-'.bin2hex(random_bytes(4)).'/work';
        mkdir($base, 0700, true);
        config()->set('company-lookup.bulk.temp_path', $base);

        return $base;
    }

    /**
     * @return list<string>
     */
    private function entries(string $directory): array
    {
        $entries = scandir($directory);

        return $entries === false ? [] : array_values(array_diff($entries, ['.', '..']));
    }
}
