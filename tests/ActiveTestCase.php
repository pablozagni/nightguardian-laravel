<?php

namespace NightGuardian\Laravel\Tests;

use NightGuardian\Laravel\Event\StackTrace;

/**
 * A Laravel app where the SDK is configured (and therefore active), with every capture feature on.
 */
abstract class ActiveTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('nightguardian', array_replace($app['config']->get('nightguardian', []), [
            'enabled' => true,
            'url' => 'http://collector.test',
            'token' => 'ng_testtoken',
            'environment' => 'testing',
            'release' => 'test-release',
            'slow_request_ms' => 50,
            'slow_query_ms' => 100,
        ]));

        // Errors raised by the code under test must not end up in a real log file.
        $app['config']->set('logging.default', 'null');

        // Make this package's directory "the application", so test files count as in-app code.
        $app->singleton(StackTrace::class, fn () => new StackTrace(dirname(__DIR__)));
    }
}
