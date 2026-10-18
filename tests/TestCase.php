<?php

namespace NightGuardian\Laravel\Tests;

use NightGuardian\Laravel\NightGuardianServiceProvider;
use NightGuardian\Laravel\Support\Guard;
use NightGuardian\Laravel\Tests\Fakes\FakeTransport;
use NightGuardian\Laravel\Transport\Transport;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Guard::reset();
    }

    /**
     * The suite never talks to a real collector: whatever the shell or a .env file says, the app under test
     * has no URL or token, nothing is sent over the network (the transport is a fake) and its spool lives
     * in a throwaway directory. Real-collector checks live in tests/E2E and are opt-in (see CONTRIBUTING.md).
     */
    /**
     * `expectsOutputToContain()` only exists from Laravel 9, so the 1.x line runs commands itself.
     *
     * @param  string  $command
     * @param  array<string, mixed>  $parameters
     * @return CommandProbe
     */
    public function artisan($command, $parameters = [])
    {
        return new CommandProbe($this, $command, $parameters);
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('nightguardian.url', '');
        $app['config']->set('nightguardian.token', '');
        $app->singleton(Transport::class, FakeTransport::class);
        $app->useStoragePath(sys_get_temp_dir().'/ng-storage-'.bin2hex(random_bytes(5)));
    }

    protected function getPackageProviders($app): array
    {
        return [NightGuardianServiceProvider::class];
    }
}
