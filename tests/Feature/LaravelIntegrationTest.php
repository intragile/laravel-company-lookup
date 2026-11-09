<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Feature;

use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\CompanyLookup;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Laravel\Facades\CompanyLookup as CompanyLookupFacade;
use IntrAgile\CompanyLookup\LookupResult;
use IntrAgile\CompanyLookup\Tests\Support\InMemoryDriver;
use IntrAgile\CompanyLookup\Tests\TestCase;

final class LaravelIntegrationTest extends TestCase
{
    public function test_manager_is_a_singleton_with_config_merged(): void
    {
        $lookup = $this->container()->make(CompanyLookup::class);

        self::assertSame($lookup, $this->container()->make('company-lookup'));
        self::assertSame('first', config('company-lookup.strategy'));
        self::assertFalse($lookup->isEnabled('brasilapi'));
        self::assertTrue($lookup->isEnabled('vies'));
    }

    public function test_fake_answers_and_records(): void
    {
        $fake = CompanyLookupFacade::fake([
            'HU:HU 10773381' => new Company('Magyar Telekom Nyrt.', 'HU', 'fake'),
            'search:CZ:ČEZ' => [new Company('ČEZ, a. s.', 'CZ', 'fake')],
            'SK:1' => LookupResult::failed('fake', FailureReason::Timeout, true),
        ]);

        self::assertSame('Magyar Telekom Nyrt.', CompanyLookupFacade::vat('HU10773381')->first()?->name);
        self::assertSame('ČEZ, a. s.', CompanyLookupFacade::search('cz', 'cez')->first()?->name);
        self::assertSame(['fake'], CompanyLookupFacade::lookup('SK', '1')->failedSources());
        self::assertFalse(CompanyLookupFacade::lookup('PL', '1')->found());
        self::assertSame($fake, $this->container()->make(CompanyLookup::class));

        $fake->assertLookedUp('HU', '10773381');
        $fake->assertSearched('CZ', 'ČEZ');
    }

    public function test_fake_keeps_non_latin_search_keys_apart(): void
    {
        CompanyLookupFacade::fake(['search:JP:トヨタ' => [new Company('トヨタ自動車株式会社', 'JP', 'fake')]]);

        self::assertTrue(CompanyLookupFacade::search('JP', 'トヨタ')->found());
        self::assertFalse(CompanyLookupFacade::search('JP', 'ソニー')->found());
    }

    public function test_fake_asserts_nothing_looked_up(): void
    {
        CompanyLookupFacade::fake()->assertNothingLookedUp();
    }

    public function test_lookup_command_prints_the_company(): void
    {
        $this->registerDriver(LookupResult::found('test', new Company('Acme Kft.', 'HU', 'test')));

        $this->command('company-lookup:lookup', ['country' => 'HU', 'identifier' => '12345678', '--driver' => ['test']])
            ->expectsOutputToContain('test: 1 found')
            ->expectsOutputToContain('Acme Kft.')
            ->assertExitCode(0);
    }

    public function test_lookup_command_json_and_failure_exit_code(): void
    {
        $this->registerDriver(LookupResult::notFound('test'));

        $this->command('company-lookup:lookup', ['country' => 'HU', 'identifier' => '1', '--driver' => ['test'], '--json' => true])
            ->expectsOutputToContain('"state": "not_found"')
            ->assertExitCode(1);
    }

    public function test_lookup_command_rejects_unknown_type(): void
    {
        $this->command('company-lookup:lookup', ['country' => 'HU', 'identifier' => '1', '--type' => 'iban'])
            ->expectsOutputToContain('Unknown identifier type')
            ->assertExitCode(2);
    }

    public function test_commands_reject_unknown_drivers(): void
    {
        $this->command('company-lookup:lookup', ['country' => 'CZ', 'identifier' => '45274649', '--driver' => ['typo']])
            ->expectsOutputToContain('Unknown driver [typo]')
            ->assertExitCode(2);

        $this->command('company-lookup:doctor', ['--driver' => ['typo']])
            ->expectsOutputToContain('Unknown driver [typo]')
            ->assertExitCode(2);
    }

    public function test_company_serialises_to_its_array_form(): void
    {
        $company = new Company('Acme Kft.', 'HU', 'test', raw: ['secret' => 'x']);

        self::assertSame($company->toArray(), json_decode((string) json_encode($company), true));
    }

    public function test_doctor_lists_status_and_live_check(): void
    {
        $lookup = $this->container()->make(CompanyLookup::class);
        $lookup->extend('test', fn () => new InMemoryDriver('test', ['HU'], fn () => LookupResult::found('test', new Company('Sample Co', 'HU', 'test'))));
        $lookup->extend('keyed', fn () => new InMemoryDriver('keyed', ['GB'], fn () => LookupResult::notFound('keyed'),
            [IdentifierType::Registration], credentials: ['key' => 'COMPANY_LOOKUP_KEYED_KEY'], configured: false));

        $this->command('company-lookup:doctor', ['--live' => true, '--driver' => ['test', 'keyed']])
            ->expectsOutputToContain('ok: Sample Co')
            ->expectsOutputToContain('missing: COMPANY_LOOKUP_KEYED_KEY')
            ->assertExitCode(0);
    }

    private function registerDriver(LookupResult $answer): void
    {
        $this->container()->make(CompanyLookup::class)
            ->extend('test', fn () => new InMemoryDriver('test', ['HU'], fn () => $answer, [IdentifierType::Registration, IdentifierType::Vat]));
    }
}
