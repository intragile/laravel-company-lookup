<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Testing\PendingCommand;
use IntrAgile\CompanyLookup\Laravel\CompanyLookupServiceProvider;
use LogicException;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [CompanyLookupServiceProvider::class];
    }

    protected function container(): Application
    {
        return $this->app ?? throw new LogicException('The application is not booted.');
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function command(string $name, array $parameters = []): PendingCommand
    {
        $command = $this->artisan($name, $parameters);

        if (! $command instanceof PendingCommand) {
            throw new LogicException('artisan() did not return a pending command.');
        }

        return $command;
    }
}
