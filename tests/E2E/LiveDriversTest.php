<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\E2E;

use IntrAgile\CompanyLookup\BuiltInDrivers;
use IntrAgile\CompanyLookup\CompanyLookup;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Calls the real registries. Run with `composer test:e2e`.
 * Every built-in driver that needs no credentials looks up its own sample identifier.
 * Set COMPANY_LOOKUP_E2E_DRIVERS=vies,ares to run a subset.
 */
#[Group('e2e')]
final class LiveDriversTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        $only = array_filter(explode(',', (string) getenv('COMPANY_LOOKUP_E2E_DRIVERS')));

        foreach (array_keys(BuiltInDrivers::HTTP) as $key) {
            if ($only === [] || in_array($key, $only, true)) {
                yield $key => [$key];
            }
        }
    }

    #[DataProvider('drivers')]
    public function test_driver_finds_its_sample(string $key): void
    {
        $driver = $this->container()->make(CompanyLookup::class)->driver($key);
        $capabilities = $driver->capabilities();

        if (! $driver->isConfigured()) {
            self::markTestSkipped("{$key} needs credentials: ".implode(', ', $capabilities->credentials));
        }

        self::assertNotNull($capabilities->sample, "{$key} has no sample identifier.");
        $result = $driver->lookup($capabilities->sampleCountry(), new Identifier($capabilities->identifierTypes[0], $capabilities->sample));

        self::assertTrue($result->isFound(), "{$key}: ".$result->state.' '.($result->reason->value ?? '').' '.($result->message ?? ''));
        self::assertNotNull($result->first()?->name, "{$key}: the sample has no name.");
    }
}
