<?php

namespace NightGuardian\Laravel\Tests;

use NightGuardian\Laravel\NightGuardianServiceProvider;
use NightGuardian\Laravel\Support\Guard;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Guard::reset();
    }

    protected function getPackageProviders($app): array
    {
        return [NightGuardianServiceProvider::class];
    }
}
