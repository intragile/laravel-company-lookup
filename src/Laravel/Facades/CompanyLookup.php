<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\CompanyLookup as Manager;
use IntrAgile\CompanyLookup\LookupResult;
use IntrAgile\CompanyLookup\Testing\FakeCompanyLookup;

/**
 * @method static \IntrAgile\CompanyLookup\LookupOutcome lookup(string $country, string|\IntrAgile\CompanyLookup\Identifier $identifier, ?\IntrAgile\CompanyLookup\Enums\IdentifierType $type = null)
 * @method static \IntrAgile\CompanyLookup\LookupOutcome vat(string $vatNumber)
 * @method static \IntrAgile\CompanyLookup\LookupOutcome search(string $country, string $name, ?int $limit = null)
 * @method static Manager using(string ...$keys)
 * @method static Manager all()
 * @method static \IntrAgile\CompanyLookup\Contracts\Driver driver(string $key)
 * @method static array<string, \IntrAgile\CompanyLookup\Contracts\Driver> drivers()
 * @method static void extend(string $key, \Closure $factory)
 *
 * @see Manager
 */
final class CompanyLookup extends Facade
{
    /**
     * Replaces the lookup with a fake that answers from the given map.
     *
     * @param  array<string, Company|LookupResult|list<Company>|null>  $responses  "HU:10773381" or "search:HU:acme" => answer.
     */
    public static function fake(array $responses = []): FakeCompanyLookup
    {
        $fake = new FakeCompanyLookup($responses);
        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Manager::class;
    }
}
