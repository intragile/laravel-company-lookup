<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\CompanyLookup;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Exceptions\UnknownDriverException;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupResult;
use IntrAgile\CompanyLookup\Tests\Support\InMemoryDriver;
use PHPUnit\Framework\TestCase;

final class CompanyLookupTest extends TestCase
{
    private Repository $cache;

    protected function setUp(): void
    {
        $this->cache = new Repository(new ArrayStore);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function manager(array $config = []): CompanyLookup
    {
        return new CompanyLookup($config + ['registries' => ['HU' => 'https://e-cegjegyzek.hu/', '*' => 'https://e-justice.example']], $this->cache, new RateLimiter($this->cache));
    }

    private static function found(string $source, string $name = 'Acme'): LookupResult
    {
        return LookupResult::found($source, new Company($name, 'HU', $source));
    }

    public function test_it_asks_only_drivers_that_cover_the_country_and_type(): void
    {
        $hu = new InMemoryDriver('hu', ['HU'], fn () => self::found('hu'));
        $cz = new InMemoryDriver('cz', ['CZ'], fn () => self::found('cz'));
        $lei = new InMemoryDriver('lei', ['*'], fn () => self::found('lei'), [IdentifierType::Lei]);

        $manager = $this->manager();
        $manager->extend('hu', fn () => $hu);
        $manager->extend('cz', fn () => $cz);
        $manager->extend('lei', fn () => $lei);

        $outcome = $manager->lookup('hu', '10773381', IdentifierType::Registration);

        self::assertSame('Acme', $outcome->first()?->name);
        self::assertSame([1, 0, 0], [$hu->calls, $cz->calls, $lei->calls]);
    }

    public function test_first_strategy_stops_at_the_first_find_and_all_asks_everyone(): void
    {
        $a = new InMemoryDriver('a', ['HU'], fn () => self::found('a'));
        $b = new InMemoryDriver('b', ['HU'], fn () => self::found('b'));
        $manager = $this->manager(['cache' => ['enabled' => false]]);
        $manager->extend('a', fn () => $a);
        $manager->extend('b', fn () => $b);

        self::assertCount(1, $manager->lookup('HU', 'HU10773381')->results);
        self::assertCount(2, $manager->all()->lookup('HU', 'HU10773381')->results);
        self::assertSame([2, 1], [$a->calls, $b->calls]);
    }

    public function test_unconfigured_driver_is_reported_without_a_call_and_others_still_run(): void
    {
        $keyed = new InMemoryDriver('keyed', ['GB'], fn () => self::found('keyed'), credentials: ['key' => 'COMPANY_LOOKUP_KEYED_KEY'], configured: false);
        $free = new InMemoryDriver('free', ['GB'], fn () => self::found('free'));
        $manager = $this->manager();
        $manager->extend('keyed', fn () => $keyed);
        $manager->extend('free', fn () => $free);

        $outcome = $manager->lookup('GB', '00445790', IdentifierType::Registration);

        self::assertSame(0, $keyed->calls);
        self::assertSame(['keyed'], $outcome->unconfiguredSources());
        self::assertSame('COMPANY_LOOKUP_KEYED_KEY', $outcome->results[0]->message);
        self::assertTrue($outcome->found());
    }

    public function test_definitive_answers_are_cached_and_failures_are_not(): void
    {
        $answers = [
            LookupResult::failed('d', FailureReason::Unavailable, true),
            LookupResult::notFound('d'),
            self::found('d'),
        ];
        $driver = new InMemoryDriver('d', ['HU'], function () use (&$answers): LookupResult {
            return array_shift($answers) ?? LookupResult::notFound('d');
        });
        $manager = $this->manager();
        $manager->extend('d', fn () => $driver);

        self::assertTrue($manager->lookup('HU', '1', IdentifierType::Registration)->results[0]->isFailed());
        self::assertTrue($manager->lookup('HU', '1', IdentifierType::Registration)->results[0]->isNotFound());
        self::assertTrue($manager->lookup('HU', '1', IdentifierType::Registration)->results[0]->isNotFound(), 'served from cache');
        self::assertSame(2, $driver->calls);
    }

    public function test_cache_can_be_disabled(): void
    {
        $driver = new InMemoryDriver('d', ['HU'], fn () => self::found('d'));
        $manager = $this->manager(['cache' => ['enabled' => false]]);
        $manager->extend('d', fn () => $driver);

        $manager->lookup('HU', '1');
        $manager->lookup('HU', '1');

        self::assertSame(2, $driver->calls);
    }

    public function test_local_rate_limit_skips_the_driver_with_a_retryable_failure(): void
    {
        $driver = new InMemoryDriver('slow', ['SK'], fn () => LookupResult::notFound('slow'));
        $manager = $this->manager(['rate_limits' => ['slow' => [1, 60]], 'cache' => ['enabled' => false]]);
        $manager->extend('slow', fn () => $driver);

        $manager->lookup('SK', '31333532');
        $second = $manager->lookup('SK', '31333532')->results[0];

        self::assertSame(1, $driver->calls);
        self::assertSame(FailureReason::RateLimited, $second->reason);
        self::assertTrue($second->retryable);
    }

    public function test_unsupported_country_returns_registry_link(): void
    {
        $manager = $this->manager();
        $manager->extend('cz', fn () => new InMemoryDriver('cz', ['CZ'], fn () => self::found('cz')));

        self::assertSame('https://e-justice.example', $manager->lookup('DE', 'HRB 1')->registryUrl);
        self::assertSame([], $manager->lookup('DE', 'HRB 1')->results);
        self::assertSame('https://e-cegjegyzek.hu/', $manager->search('HU', 'acme')->registryUrl);
    }

    public function test_disabled_driver_is_skipped_unless_named_explicitly(): void
    {
        $driver = new InMemoryDriver('mirror', ['BR'], fn () => self::found('mirror'));
        $manager = $this->manager(['drivers' => ['mirror' => ['enabled' => false]]]);
        $manager->extend('mirror', fn () => $driver);

        self::assertFalse($manager->lookup('BR', '00000000000191')->found());
        self::assertTrue($manager->using('mirror')->lookup('BR', '00000000000191')->found());
        self::assertFalse($manager->isEnabled('mirror'));
    }

    public function test_vat_parses_the_prefix(): void
    {
        $seen = [];
        $driver = new InMemoryDriver('vies', ['GR', 'HU', 'CH'], function (string $country, Identifier|string $identifier) use (&$seen): LookupResult {
            $seen[] = $country.'|'.($identifier instanceof Identifier ? $identifier->type->value.':'.$identifier->value : $identifier);

            return LookupResult::notFound('vies');
        });
        $manager = $this->manager();
        $manager->extend('vies', fn () => $driver);

        $manager->vat('EL 094014201');
        $manager->vat('hu10773381');
        $manager->vat('CHE-105.909.036');

        self::assertSame(['GR|vat:EL094014201', 'HU|vat:HU10773381', 'CH|vat:CHE105909036'], $seen);
    }

    public function test_search_asks_all_name_search_drivers(): void
    {
        $a = new InMemoryDriver('a', ['CZ'], fn () => self::found('a', 'One'), nameSearch: true);
        $b = new InMemoryDriver('b', ['*'], fn () => self::found('b', 'Two'), nameSearch: true);
        $c = new InMemoryDriver('c', ['CZ'], fn () => self::found('c', 'Three'));
        $manager = $this->manager();
        $manager->extend('a', fn () => $a);
        $manager->extend('b', fn () => $b);
        $manager->extend('c', fn () => $c);

        $names = array_map(fn (Company $company): ?string => $company->name, $manager->search('CZ', 'ČEZ')->companies());

        self::assertSame(['One', 'Two'], $names);
        self::assertSame(0, $c->calls);
    }

    public function test_a_bare_number_also_reaches_tax_number_drivers(): void
    {
        $seen = [];
        $tax = new InMemoryDriver('tax', ['RO'], function (string $country, Identifier|string $identifier) use (&$seen): LookupResult {
            $seen[] = $identifier instanceof Identifier ? $identifier->type : null;

            return self::found('tax');
        }, [IdentifierType::Tax]);
        $manager = $this->manager(['cache' => ['enabled' => false]]);
        $manager->extend('tax', fn () => $tax);

        self::assertTrue($manager->lookup('RO', '1590082')->found());
        self::assertSame([IdentifierType::Tax], $seen);
        self::assertFalse($manager->lookup('RO', '1590082', IdentifierType::Registration)->found(), 'an explicit type is not widened');
    }

    public function test_unknown_driver_throws(): void
    {
        $this->expectException(UnknownDriverException::class);

        $this->manager()->driver('nope');
    }

    public function test_extend_replaces_a_resolved_driver(): void
    {
        $manager = $this->manager();
        $manager->extend('x', fn () => new InMemoryDriver('x', ['HU'], fn () => self::found('x', 'Old')));
        $manager->driver('x');
        $manager->extend('x', fn () => new InMemoryDriver('x', ['HU'], fn () => self::found('x', 'New')));

        self::assertSame('New', $manager->lookup('HU', '1')->first()?->name);
        self::assertArrayHasKey('x', $manager->drivers());
    }

    public function test_searches_in_non_latin_scripts_do_not_share_a_cache_entry(): void
    {
        $tw = new InMemoryDriver('tw', ['TW'], fn (string $country, Identifier|string $name) => LookupResult::found('tw', new Company(is_string($name) ? $name : $name->value, 'TW', 'tw')), nameSearch: true);
        $manager = $this->manager();
        $manager->extend('tw', fn () => $tw);

        self::assertSame('台積電', $manager->search('TW', '台積電')->first()?->name);
        self::assertSame('鴻海', $manager->search('TW', '鴻海')->first()?->name);
        self::assertSame('台積電', $manager->search('TW', ' 台積電 ')->first()?->name);
        self::assertSame(2, $tw->calls);
    }

    public function test_the_identifier_scheme_is_part_of_the_cache_key(): void
    {
        $gleif = new InMemoryDriver('gleif', ['*'], fn (string $country, mixed $identifier) => self::found('gleif', $identifier instanceof Identifier ? (string) $identifier->scheme : ''));
        $manager = $this->manager();
        $manager->extend('gleif', fn () => $gleif);

        self::assertSame('RA000001', $manager->lookup('DE', new Identifier(IdentifierType::Registration, 'HRB 1', 'RA000001'))->first()?->name);
        self::assertSame('RA000999', $manager->lookup('DE', new Identifier(IdentifierType::Registration, 'HRB 1', 'RA000999'))->first()?->name);
        self::assertSame(2, $gleif->calls);
    }

    public function test_changing_a_driver_setting_starts_a_fresh_cache(): void
    {
        $driver = new InMemoryDriver('gus', ['PL'], fn () => self::found('gus'));
        $sandbox = $this->manager(['drivers' => ['gus' => ['sandbox' => true]]]);
        $sandbox->extend('gus', fn () => $driver);
        $production = $this->manager(['drivers' => ['gus' => ['sandbox' => false]]]);
        $production->extend('gus', fn () => $driver);

        $sandbox->lookup('PL', '1', IdentifierType::Registration);
        $sandbox->lookup('PL', '1', IdentifierType::Registration);
        $production->lookup('PL', '1', IdentifierType::Registration);

        self::assertSame(2, $driver->calls);
    }

    public function test_results_are_cached_as_strings_that_survive_stores_refusing_objects(): void
    {
        $driver = new InMemoryDriver('hu', ['HU'], fn () => self::found('hu'));
        $manager = $this->manager();
        $manager->extend('hu', fn () => $driver);

        $manager->lookup('HU', '10773381', IdentifierType::Registration);

        $store = $this->cache->getStore();
        self::assertInstanceOf(ArrayStore::class, $store);
        $values = array_map(fn (array $item): mixed => $item['value'], (fn (): array => $this->storage)->call($store));
        $payloads = array_filter($values, fn (mixed $value): bool => is_string($value) && str_contains($value, 'LookupResult'));
        self::assertCount(1, $payloads);

        self::assertSame('Acme', $manager->lookup('HU', '10773381', IdentifierType::Registration)->first()?->name);
        self::assertSame(1, $driver->calls);
    }

    public function test_a_broken_cache_store_does_not_throw(): void
    {
        $broken = new Repository(new class extends ArrayStore
        {
            public function get($key): mixed
            {
                throw new \RuntimeException('store down');
            }

            public function put($key, $value, $seconds): bool
            {
                throw new \RuntimeException('store down');
            }
        });
        $driver = new InMemoryDriver('hu', ['HU'], fn () => self::found('hu'));
        $manager = new CompanyLookup(['rate_limits' => ['hu' => [5, 60]]], $broken, new RateLimiter($broken));
        $manager->extend('hu', fn () => $driver);

        self::assertSame('Acme', $manager->lookup('HU', '10773381', IdentifierType::Registration)->first()?->name);
    }

    public function test_registry_url_is_given_for_listed_countries_even_when_a_worldwide_driver_answers(): void
    {
        $gleif = new InMemoryDriver('gleif', ['*'], fn () => LookupResult::notFound('gleif'), nameSearch: true);
        $manager = $this->manager();
        $manager->extend('gleif', fn () => $gleif);

        self::assertSame('https://e-cegjegyzek.hu/', $manager->search('HU', 'acme')->registryUrl);
        self::assertSame('https://e-justice.example', $manager->search('DE', 'acme')->registryUrl);
    }

    public function test_registry_url_fallback_is_left_out_when_something_was_found(): void
    {
        $cz = new InMemoryDriver('cz', ['CZ'], fn () => self::found('cz'));
        $manager = $this->manager();
        $manager->extend('cz', fn () => $cz);

        self::assertNull($manager->lookup('CZ', '45274649')->registryUrl);
    }
}
