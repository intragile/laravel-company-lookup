<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Laravel;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use IntrAgile\CompanyLookup\BuiltInDrivers;
use IntrAgile\CompanyLookup\Bulk\BulkDriver;
use IntrAgile\CompanyLookup\Bulk\Importer;
use IntrAgile\CompanyLookup\CompanyLookup;
use IntrAgile\CompanyLookup\Contracts\Driver;
use IntrAgile\CompanyLookup\Drivers\AbstractHttpDriver;
use IntrAgile\CompanyLookup\Laravel\Console\DoctorCommand;
use IntrAgile\CompanyLookup\Laravel\Console\ImportCommand;
use IntrAgile\CompanyLookup\Laravel\Console\LookupCommand;

final class CompanyLookupServiceProvider extends ServiceProvider
{
    private const MIGRATION = __DIR__.'/../../database/migrations/create_company_lookup_records_table.php';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/company-lookup.php', 'company-lookup');

        $this->app->singleton(CompanyLookup::class, function (Application $app): CompanyLookup {
            /** @var array<string, mixed> $config */
            $config = (array) $app->make('config')->get('company-lookup', []);

            $cacheStore = data_get($config, 'cache.store');
            $cache = $app->make(CacheFactory::class)->store(is_string($cacheStore) && $cacheStore !== '' ? $cacheStore : null);

            $lookup = new CompanyLookup($config, $cache, $app->make(RateLimiter::class));

            $options = [
                'timeout' => is_numeric($config['timeout'] ?? null) ? (float) $config['timeout'] : 8.0,
                'user_agent' => is_string($config['user_agent'] ?? null) ? $config['user_agent'] : AbstractHttpDriver::DEFAULT_USER_AGENT,
            ];

            foreach (BuiltInDrivers::HTTP as $key => $class) {
                if (! is_a($class, AbstractHttpDriver::class, true)) {
                    continue;
                }

                $lookup->extend($key, function () use ($app, $class, $key, $config, $cache, $options): Driver {
                    $section = data_get($config, 'drivers.'.$key);

                    return new $class($app->make(HttpFactory::class), self::section($section), $cache, $options);
                });
            }

            foreach (BuiltInDrivers::BULK as $key => $class) {
                $lookup->extend($key, fn (): Driver => new BulkDriver(
                    new $class(self::section(data_get($config, 'drivers.'.$key))),
                    $app->make(ConnectionResolverInterface::class),
                    $config,
                ));
            }

            return $lookup;
        });

        $this->app->bind(Importer::class, fn (Application $app): Importer => new Importer(
            $app->make(HttpFactory::class),
            $app->make(ConnectionResolverInterface::class),
            self::section($app->make('config')->get('company-lookup', [])),
        ));

        $this->app->alias(CompanyLookup::class, 'company-lookup');
    }

    /**
     * @return array<string, mixed>
     */
    private static function section(mixed $section): array
    {
        if (! is_array($section)) {
            return [];
        }

        $clean = [];
        foreach ($section as $field => $value) {
            $clean[(string) $field] = $value;
        }

        return $clean;
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../../config/company-lookup.php' => $this->app->configPath('company-lookup.php'),
        ], 'company-lookup-config');

        $this->publishes([
            self::MIGRATION => $this->app->databasePath('migrations/'.date('Y_m_d_His').'_create_company_lookup_records_table.php'),
        ], 'company-lookup-migrations');

        if (filter_var($this->app->make('config')->get('company-lookup.bulk.enabled', false), FILTER_VALIDATE_BOOL)) {
            $this->loadMigrationsFrom(self::MIGRATION);
        }

        $this->commands([LookupCommand::class, DoctorCommand::class, ImportCommand::class]);
    }
}
