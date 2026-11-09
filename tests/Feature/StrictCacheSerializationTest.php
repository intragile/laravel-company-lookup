<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Feature;

use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\CompanyLookup;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\LookupResult;
use IntrAgile\CompanyLookup\Tests\Support\InMemoryDriver;
use IntrAgile\CompanyLookup\Tests\TestCase;

/**
 * Laravel 13 application skeletons refuse to unserialize objects from the cache by default.
 */
final class StrictCacheSerializationTest extends TestCase
{
    private string $cachePath = '';

    protected function defineEnvironment($app): void
    {
        $this->cachePath = sys_get_temp_dir().'/company-lookup-cache-'.bin2hex(random_bytes(4));

        $app['config']->set('cache.default', 'file');
        $app['config']->set('cache.stores.file.path', $this->cachePath);
        $app['config']->set('cache.serializable_classes', false);
    }

    protected function tearDown(): void
    {
        $this->container()->make('files')->deleteDirectory($this->cachePath);

        parent::tearDown();
    }

    public function test_cached_results_are_read_back_when_the_store_refuses_objects(): void
    {
        $driver = new InMemoryDriver('test', ['HU'], fn () => LookupResult::found('test', new Company('Acme Kft.', 'HU', 'test', foundedAt: new \DateTimeImmutable('2001-02-03'))));
        $lookup = $this->container()->make(CompanyLookup::class);
        $lookup->extend('test', fn () => $driver);

        $lookup->using('test')->lookup('HU', '12345678', IdentifierType::Registration);
        $again = $lookup->using('test')->lookup('HU', '12345678', IdentifierType::Registration);

        $company = $again->first();
        self::assertNotNull($company);
        self::assertSame('Acme Kft.', $company->name);
        self::assertSame('2001-02-03', $company->foundedAt?->format('Y-m-d'));
        self::assertSame(1, $driver->calls);
    }
}
