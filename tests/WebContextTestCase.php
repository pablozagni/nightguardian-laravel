<?php

namespace NightGuardian\Laravel\Tests;

use ReflectionProperty;

/**
 * An active app that believes it is NOT running in a console (a web request, a deploy hook, a queued job
 * calling Artisan). The flag is set before the service providers boot.
 */
abstract class WebContextTestCase extends ActiveTestCase
{
    /** @var bool */
    private $cannotSimulateWeb = false;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Laravel 5.8 and 6 work out "running in console" from the SAPI on every call; there is nothing to switch.
        if (! property_exists($app, 'isRunningInConsole')) {
            $this->cannotSimulateWeb = true;

            return;
        }

        $flag = new ReflectionProperty($app, 'isRunningInConsole');
        $flag->setAccessible(true);
        $flag->setValue($app, false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->cannotSimulateWeb) {
            $this->markTestSkipped('This Laravel version cannot pretend not to be in a console; Laravel 7+ covers it.');
        }
    }
}
