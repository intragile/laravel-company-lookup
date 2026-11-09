<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup;

use Closure;
use DateTimeImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use IntrAgile\CompanyLookup\Contracts\Driver;
use IntrAgile\CompanyLookup\Drivers\Support\Normalize;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Exceptions\UnknownDriverException;
use Throwable;

/**
 * Picks the drivers that cover a country and an identifier type, and adds caching and rate limiting around them.
 */
class CompanyLookup
{
    /** Bumped whenever the cached payload shape changes, so older entries are ignored. */
    private const CACHE_VERSION = 'v1';

    /** Classes a cached result may contain; anything else is rejected on read. */
    private const CACHED_CLASSES = [
        LookupResult::class, Company::class, Identifier::class, Address::class, ActivityCode::class,
        DateTimeImmutable::class, CompanyStatus::class, IdentifierType::class, FailureReason::class,
    ];

    /** @var array<string, Closure(): Driver> */
    private array $factories = [];

    /** @var array<string, Driver> */
    private array $resolved = [];

    /** @var list<string>|null */
    private ?array $only = null;

    private ?string $strategy = null;

    /**
     * @param  array<string, mixed>  $config  The company-lookup config array.
     */
    public function __construct(
        private readonly array $config = [],
        private readonly ?CacheRepository $cache = null,
        private readonly ?RateLimiter $limiter = null,
    ) {}

    /**
     * Registers a driver factory under a key. Replaces an existing driver with the same key.
     *
     * @param  Closure(): Driver  $factory
     */
    public function extend(string $key, Closure $factory): void
    {
        $this->factories[$key] = $factory;
        unset($this->resolved[$key]);
    }

    /**
     * @throws UnknownDriverException
     */
    public function driver(string $key): Driver
    {
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $factory = $this->factories[$key] ?? throw UnknownDriverException::named($key);

        return $this->resolved[$key] = $factory();
    }

    /**
     * Every registered driver, enabled or not, keyed by driver key.
     *
     * @return array<string, Driver>
     */
    public function drivers(): array
    {
        $drivers = [];
        foreach (array_keys($this->factories) as $key) {
            $drivers[$key] = $this->driver($key);
        }

        return $drivers;
    }

    public function isEnabled(string $key): bool
    {
        $drivers = $this->config['drivers'] ?? [];
        $section = is_array($drivers) ? ($drivers[$key] ?? []) : [];
        $enabled = is_array($section) ? ($section['enabled'] ?? true) : true;

        return filter_var($enabled, FILTER_VALIDATE_BOOL);
    }

    /**
     * A copy that only asks the given drivers.
     */
    public function using(string ...$keys): static
    {
        $clone = clone $this;
        $clone->only = array_values($keys);

        return $clone;
    }

    /**
     * A copy that asks every matching driver instead of stopping at the first one that finds the company.
     */
    public function all(): static
    {
        $clone = clone $this;
        $clone->strategy = 'all';

        return $clone;
    }

    /**
     * Looks up a company by identifier in every driver that covers the country.
     */
    public function lookup(string $country, string|Identifier $identifier, ?IdentifierType $type = null): LookupOutcome
    {
        $country = strtoupper(trim($country));
        $guessed = false;

        if (is_string($identifier)) {
            $guessed = $type === null;
            $identifier = new Identifier($type ?? Normalize::guessType($country, $identifier), trim($identifier));
        }

        // A bare number without a stated type may be a register number or a tax number:
        // ask the drivers of both kinds and hand each the type it understands.
        $widen = $guessed && $identifier->type === IdentifierType::Registration;
        $asTax = new Identifier(IdentifierType::Tax, $identifier->value, $identifier->scheme);

        $drivers = $this->select($country, fn (Capabilities $capabilities): bool => $capabilities->supportsType($identifier->type)
            || ($widen && $capabilities->supportsType(IdentifierType::Tax)));

        $subject = $identifier->type->value.':'.($identifier->scheme ?? '').':'.Normalize::compact($identifier->value);

        return $this->run($drivers, $country, 'lookup', $subject,
            fn (Driver $driver): LookupResult => $driver->lookup($country, $driver->capabilities()->supportsType($identifier->type) ? $identifier : $asTax));
    }

    /**
     * Looks up a VAT number given with its country prefix, such as "HU10773381" or "EL094014201".
     */
    public function vat(string $vatNumber): LookupOutcome
    {
        $compact = Normalize::compact($vatNumber);
        $prefix = str_starts_with($compact, 'CHE') ? 'CHE' : substr($compact, 0, 2);

        return $this->lookup(Normalize::countryFromVatPrefix($prefix), Identifier::vat($compact));
    }

    /**
     * Searches companies by name in every driver that offers name search for the country.
     */
    public function search(string $country, string $name, ?int $limit = null): LookupOutcome
    {
        $country = strtoupper(trim($country));
        $limit ??= $this->intConfig('max_results', 10);
        $name = trim($name);

        $drivers = $this->select($country, fn (Capabilities $capabilities): bool => $capabilities->nameSearch);

        return $this->run($drivers, $country, 'search', $limit.':'.Normalize::searchKey($name),
            fn (Driver $driver): LookupResult => $driver->search($country, $name, $limit), stopOnFound: false);
    }

    /**
     * @param  Closure(Capabilities): bool  $filter
     * @return array<string, Driver>
     */
    private function select(string $country, Closure $filter): array
    {
        $selected = [];
        $keys = $this->only ?? array_keys($this->factories);

        foreach ($keys as $key) {
            if ($this->only === null && ! $this->isEnabled($key)) {
                continue;
            }

            $driver = $this->driver($key);
            $capabilities = $driver->capabilities();

            if ($capabilities->supportsCountry($country) && $filter($capabilities)) {
                $selected[$key] = $driver;
            }
        }

        return $selected;
    }

    /**
     * @param  array<string, Driver>  $drivers
     * @param  Closure(Driver): LookupResult  $call
     */
    private function run(array $drivers, string $country, string $operation, string $subject, Closure $call, bool $stopOnFound = true): LookupOutcome
    {
        if ($drivers === []) {
            return new LookupOutcome([], $this->registryUrl($country, true));
        }

        $stopOnFound = $stopOnFound && ($this->strategy ?? $this->stringConfig('strategy', 'first')) === 'first';
        $results = [];

        foreach ($drivers as $key => $driver) {
            $results[] = $result = $this->ask($key, $driver, $country, $operation, $subject, $call);

            if ($stopOnFound && $result->isFound()) {
                break;
            }
        }

        $found = array_filter($results, fn (LookupResult $result): bool => $result->isFound()) !== [];

        return new LookupOutcome($results, $this->registryUrl($country, ! $found));
    }

    /**
     * @param  Closure(Driver): LookupResult  $call
     */
    private function ask(string $key, Driver $driver, string $country, string $operation, string $subject, Closure $call): LookupResult
    {
        if (! $driver->isConfigured()) {
            return LookupResult::unconfigured($key, implode(', ', $driver->capabilities()->credentials));
        }

        $cacheKey = 'company-lookup:'.self::CACHE_VERSION.':'.$key.':'.$operation.':'.$country.':'
            .sha1($subject.'|'.$this->driverConfigHash($key));
        $cache = $this->cacheEnabled() ? $this->cache : null;

        if ($cache !== null && ($cached = $this->cachedResult($cache, $cacheKey)) !== null) {
            return $cached;
        }

        if ($limited = $this->rateLimited($key)) {
            return $limited;
        }

        $result = $call($driver);

        if ($cache !== null && $result->isCacheable()) {
            try {
                // Stored as a string: Laravel 13 apps refuse to unserialize objects from the cache by default.
                $cache->put($cacheKey, serialize($result), $this->intConfig('cache.ttl', 86400));
            } catch (Throwable) {
                // A failing cache store must not turn an answer into an exception.
            }
        }

        return $result;
    }

    private function cachedResult(CacheRepository $cache, string $cacheKey): ?LookupResult
    {
        try {
            $payload = $cache->get($cacheKey);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($payload)) {
            return null;
        }

        $result = @unserialize($payload, ['allowed_classes' => self::CACHED_CLASSES]);

        return $result instanceof LookupResult ? $result : null;
    }

    /**
     * Changes to a driver's settings (sandbox, base URL, credentials) start a fresh cache.
     */
    private function driverConfigHash(string $key): string
    {
        $section = data_get($this->config, 'drivers.'.$key);

        return sha1(serialize(is_array($section) ? $section : []));
    }

    private function rateLimited(string $key): ?LookupResult
    {
        $limits = $this->config['rate_limits'] ?? [];
        $limit = is_array($limits) ? ($limits[$key] ?? null) : null;

        if ($this->limiter === null || ! is_array($limit) || count($limit) !== 2) {
            return null;
        }

        [$maxAttempts, $decaySeconds] = array_map(fn (mixed $value): int => is_numeric($value) ? (int) $value : 0, array_values($limit));
        if ($maxAttempts < 1) {
            return null;
        }

        $limiterKey = 'company-lookup:rate:'.$key;

        try {
            if ($this->limiter->tooManyAttempts($limiterKey, $maxAttempts)) {
                return LookupResult::failed($key, FailureReason::RateLimited, true,
                    'Local rate limit reached; retry in '.$this->limiter->availableIn($limiterKey).' s.');
            }

            $this->limiter->hit($limiterKey, max(1, $decaySeconds));
        } catch (Throwable) {
            // Without a working limiter store the call goes ahead unthrottled.
        }

        return null;
    }

    /**
     * The manual search page of the country. Countries listed under "registries" have no free
     * register API, so their page is always given; the "*" fallback only when nothing was found.
     */
    private function registryUrl(string $country, bool $withFallback): ?string
    {
        $registries = $this->config['registries'] ?? [];
        if (! is_array($registries)) {
            return null;
        }

        $url = $registries[$country] ?? ($withFallback ? $registries['*'] ?? null : null);

        return is_string($url) ? $url : null;
    }

    private function cacheEnabled(): bool
    {
        $cache = $this->config['cache'] ?? [];

        return $this->cache !== null && filter_var(is_array($cache) ? ($cache['enabled'] ?? true) : true, FILTER_VALIDATE_BOOL);
    }

    private function intConfig(string $path, int $default): int
    {
        $value = data_get($this->config, $path);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function stringConfig(string $path, string $default): string
    {
        $value = data_get($this->config, $path);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
