<?php

namespace NightGuardian\Laravel\Tests;

use ReflectionProperty;

/**
 * An active app that believes it is NOT running in a console (a web request, a deploy hook, a queued job
 * calling Artisan). The flag is set before the service providers boot.
 */
abstract class WebContextTestCase extends ActiveTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $flag = new ReflectionProperty($app, 'isRunningInConsole');
        $flag->setAccessible(true);
        $flag->setValue($app, false);
    }
}
