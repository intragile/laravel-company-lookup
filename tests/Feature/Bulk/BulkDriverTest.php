<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Feature\Bulk;

use Illuminate\Database\ConnectionResolverInterface;
use IntrAgile\CompanyLookup\Bulk\BulkDriver;
use IntrAgile\CompanyLookup\Bulk\Datasets\BelgiumKbo;
use IntrAgile\CompanyLookup\Bulk\Datasets\LatviaUr;
use IntrAgile\CompanyLookup\Bulk\Importer;
use IntrAgile\CompanyLookup\CompanyLookup;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;

final class BulkDriverTest extends BulkTestCase
{
    public function test_registered_in_the_manager(): void
    {
        $drivers = $this->container()->make(CompanyLookup::class)->drivers();

        foreach (['lv-ur', 'ee-rik', 'be-kbo'] as $key) {
            self::assertInstanceOf(BulkDriver::class, $drivers[$key] ?? null);
            self::assertTrue($drivers[$key]->isConfigured());
        }
    }

    public function test_unconfigured_until_imported(): void
    {
        $result = $this->driver('lv-ur')->lookup('LV', Identifier::registration('40003032949'));

        self::assertTrue($result->isUnconfigured());
        self::assertSame('php artisan company-lookup:import lv-ur', $result->message);
        self::assertTrue($this->driver('lv-ur')->search('LV', 'Latvenergo', 5)->isUnconfigured());
    }

    public function test_lookup_by_dirty_identifier(): void
    {
        $this->import();

        $result = $this->driver('lv-ur')->lookup('lv', Identifier::vat('LV 4150-2039692'));
        $company = $result->first();

        self::assertTrue($result->isFound());
        self::assertNotNull($company);
        self::assertSame('IK "D.Luxe"', $company->name);
        self::assertSame('LV', $company->country);
        self::assertSame('lv-ur', $company->source);
        self::assertSame('41502039692', $company->identifier(IdentifierType::Registration, 'LV-UR'));
        self::assertSame(CompanyStatus::Active, $company->status);
        self::assertSame('Individuālais komersants', $company->legalForm);
        self::assertSame('IK', $company->legalFormCode);
        self::assertSame('2020-03-05', $company->foundedAt?->format('Y-m-d'));
        self::assertSame('Daugavpils, Aroniju iela 66, LV-5414', $company->address?->format());
    }

    public function test_unknown_identifier_is_not_found_once_imported(): void
    {
        $this->import();

        self::assertTrue($this->driver('lv-ur')->lookup('LV', Identifier::registration('40000000000'))->isNotFound());
        self::assertTrue($this->driver('lv-ur')->lookup('EE', Identifier::registration('41502039692'))->isNotFound());
    }

    public function test_search_by_normalized_name(): void
    {
        $this->import();

        $result = $this->driver('lv-ur')->search('LV', 'baltimā', 10);
        self::assertSame(['SIA "Baltima"'], array_map(fn ($company) => $company->name, $result->companies));

        $many = $this->driver('lv-ur')->search('LV', 'Biedrība', 2);
        self::assertCount(2, $many->companies);

        self::assertTrue($this->driver('lv-ur')->search('LV', 'no such company zzz', 5)->isNotFound());
    }

    public function test_belgium_lookup_accepts_vat_and_short_numbers(): void
    {
        $this->import('be-kbo');

        $company = $this->driver('be-kbo')->lookup('BE', Identifier::vat('BE 123.456.749'))->first();

        self::assertNotNull($company);
        self::assertSame('Acme Belgium', $company->name);
        self::assertSame('BE0123456749', $company->identifier(IdentifierType::Vat));
        self::assertSame('014', $company->legalFormCode);
        self::assertSame('Bruxelles', $company->address?->city);
    }

    public function test_a_failing_query_is_a_retryable_failure(): void
    {
        $this->import();
        $driver = new BulkDriver(new LatviaUr, $this->container()->make(ConnectionResolverInterface::class), [
            'bulk' => ['enabled' => true, 'connection' => 'not-configured'],
        ]);

        $lookup = $driver->lookup('LV', Identifier::registration('41502039692'));
        $search = $driver->search('LV', 'baltima', 5);

        foreach ([$lookup, $search] as $result) {
            self::assertTrue($result->isFailed());
            self::assertSame(FailureReason::Unavailable, $result->reason);
            self::assertTrue($result->retryable);
        }
    }

    public function test_manager_answers_from_the_local_table(): void
    {
        $this->import();

        $outcome = $this->container()->make(CompanyLookup::class)->using('lv-ur')->lookup('LV', '41502039692');

        self::assertSame('IK "D.Luxe"', $outcome->first()?->name);
    }

    public function test_not_configured_when_bulk_is_disabled_or_credentials_missing(): void
    {
        $db = $this->container()->make(ConnectionResolverInterface::class);

        $disabled = new BulkDriver(new LatviaUr, $db, ['bulk' => ['enabled' => false]]);
        self::assertFalse($disabled->isConfigured());
        self::assertSame(['bulk.enabled' => 'COMPANY_LOOKUP_BULK_ENABLED'], $disabled->capabilities()->credentials);

        $noCredentials = new BulkDriver(new BelgiumKbo, $db, ['bulk' => ['enabled' => true]]);
        self::assertFalse($noCredentials->isConfigured());
        self::assertSame(['username' => 'COMPANY_LOOKUP_KBO_USERNAME', 'password' => 'COMPANY_LOOKUP_KBO_PASSWORD'], $noCredentials->capabilities()->credentials);
    }

    private function driver(string $key): BulkDriver
    {
        $driver = $this->container()->make(CompanyLookup::class)->driver($key);
        self::assertInstanceOf(BulkDriver::class, $driver);

        return $driver;
    }

    private function import(string $key = 'lv-ur'): void
    {
        $this->fakeDownloads();
        $this->container()->make(Importer::class)->import($this->driver($key)->dataset());
    }
}
