<?php

namespace NightGuardian\Laravel\Tests;

use NightGuardian\Laravel\NightGuardianServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [NightGuardianServiceProvider::class];
    }
}
