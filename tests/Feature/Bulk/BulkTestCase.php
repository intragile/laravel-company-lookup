<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Feature\Bulk;

use Closure;
use Generator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Bulk\Datasets\BelgiumKbo;
use IntrAgile\CompanyLookup\Bulk\Datasets\EstoniaRik;
use IntrAgile\CompanyLookup\Bulk\Datasets\LatviaUr;
use IntrAgile\CompanyLookup\Contracts\BulkDataset;
use IntrAgile\CompanyLookup\Tests\Fixtures;
use IntrAgile\CompanyLookup\Tests\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Bulk tests run against SQLite in memory with bulk enabled and the package migration applied.
 *
 * Fixtures under tests/fixtures/bulk/:
 *  - lv-ur/register.csv: the header and first 20 records of the live Latvian file, recorded 2026-11-09.
 *  - ee-rik/*.csv.zip: header and 17 records of the live Estonian file (UTF-8 with BOM, CRLF), recorded
 *    2026-11-09, re-zipped; includes one "L" (in liquidation) and one "N" (bankrupt) record.
 *  - be-kbo/*.csv: built from the documented layout of the KBO Open Data cookbook (R018); the companies
 *    are invented, because the real files require a login.
 */
abstract class BulkTestCase extends TestCase
{
    protected bool $bulkEnabled = true;

    protected bool $migrate = true;

    private ?string $latvia = null;

    private bool $faked = false;

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('company-lookup.bulk.enabled', $this->bulkEnabled);
        $app['config']->set('company-lookup.drivers.be-kbo.username', 'user@example.com');
        $app['config']->set('company-lookup.drivers.be-kbo.password', 'secret');
        $app['config']->set('company-lookup.cache.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        if ($this->migrate) {
            $this->command('migrate')->assertExitCode(0);
        }
    }

    protected function records(string $dataset): int
    {
        return DB::table('company_lookup_records')->where('dataset', $dataset)->count();
    }

    /**
     * Fakes the three download sources. $latvia replaces the Latvian CSV body; calling again only swaps it.
     */
    protected function fakeDownloads(?string $latvia = null): void
    {
        $this->latvia = $latvia ?? Fixtures::get('bulk/lv-ur/register.csv');
        if ($this->faked) {
            return;
        }

        $this->faked = true;
        $estonia = Fixtures::get('bulk/ee-rik/ettevotja_rekvisiidid__lihtandmed.csv.zip');
        $belgium = $this->belgiumZip();

        Http::fake(function (Request $request) use ($estonia, $belgium) {
            $url = $request->url();

            return match (true) {
                $url === LatviaUr::URL => Http::response($this->latvia),
                $url === EstoniaRik::URL => Http::response($estonia, 200, ['Content-Type' => 'application/zip']),
                $url === BelgiumKbo::BASE_URL.'/login' => Http::response('<form name="f"></form>'),
                $url === BelgiumKbo::BASE_URL.'/static/j_spring_security_check' => Http::response(''),
                $url === BelgiumKbo::BASE_URL.'/affiliation/xml/?files' => Http::response(
                    '<a href="files/KboOpenData_0140_2026_11_08_Full.zip">full</a>'
                    .'<a href="files/KboOpenData_0141_2026_11_09_Update.zip">update</a>'
                    .'<a href="files/KboOpenData_0141_2026_11_09_Full.zip">full</a>'
                ),
                $url === BelgiumKbo::BASE_URL.'/affiliation/xml/files/KboOpenData_0141_2026_11_09_Full.zip' => Http::response($belgium),
                default => Http::response('unexpected '.$url, 404),
            };
        });
    }

    /**
     * A dataset defined inline, downloaded from $url.
     *
     * @param  Closure(string): Generator<int, array{identifier: string, name: string, normalized_name: string, address: string|null, status: string, legal_form: string|null, founded_at: string|null, payload: array<string, mixed>}>  $rows
     */
    protected function inlineDataset(string $key, Closure $rows, string $url = 'https://example.test/data.csv'): BulkDataset
    {
        return new class($key, $rows, $url) implements BulkDataset
        {
            /**
             * @param  Closure(string): Generator<int, array{identifier: string, name: string, normalized_name: string, address: string|null, status: string, legal_form: string|null, founded_at: string|null, payload: array<string, mixed>}>  $rows
             */
            public function __construct(private string $key, private Closure $rows, private string $url) {}

            public function key(): string
            {
                return $this->key;
            }

            public function country(): string
            {
                return 'LV';
            }

            public function sourceUrl(): string
            {
                return $this->url;
            }

            public function credentials(): array
            {
                return [];
            }

            public function rows(string $localFile): Generator
            {
                return ($this->rows)($localFile);
            }
        };
    }

    protected function belgiumZip(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'kbo');
        if ($file === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }

        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::OVERWRITE);
        foreach (['meta', 'code', 'enterprise', 'denomination', 'address', 'activity'] as $member) {
            $zip->addFromString($member.'.csv', Fixtures::get('bulk/be-kbo/'.$member.'.csv'));
        }
        $zip->close();

        $contents = (string) file_get_contents($file);
        unlink($file);

        return $contents;
    }
}
