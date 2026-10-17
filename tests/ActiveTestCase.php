<?php

namespace NightGuardian\Laravel\Tests;

use NightGuardian\Laravel\Event\StackTrace;
use NightGuardian\Laravel\Support\Clock;
use NightGuardian\Laravel\Tests\Fakes\FakeClock;
use NightGuardian\Laravel\Tests\Fakes\FakeTransport;
use NightGuardian\Laravel\Transport\CircuitBreaker;
use NightGuardian\Laravel\Transport\Dispatcher;
use NightGuardian\Laravel\Transport\Spool;
use NightGuardian\Laravel\Transport\Stats;
use NightGuardian\Laravel\Transport\Transport;

/**
 * A Laravel app where the SDK is configured (and therefore active), with every capture feature on,
 * a fake transport, a fake clock and a private storage directory per test.
 */
abstract class ActiveTestCase extends TestCase
{
    protected string $storage = '';

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

        $this->storage = sys_get_temp_dir().'/ng-test-'.bin2hex(random_bytes(5));

        $app->singleton(Clock::class, FakeClock::class);
        $app->singleton(Transport::class, FakeTransport::class);

        $app->singleton(Dispatcher::class, function ($app) {
            $clock = $app->make(Clock::class);

            return new Dispatcher(
                $app->make(Transport::class),
                new Spool($this->storage.'/spool', 10 * 1048576, 7, $clock),
                new CircuitBreaker($this->storage.'/breaker.json', $clock),
                new Stats($this->storage.'/stats.json'),
                $clock,
                $this->storage.'/drain.lock',
            );
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->removeDirectory($this->storage);
    }

    private function removeDirectory(string $directory): void
    {
        if ($directory === '' || ! is_dir($directory)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}
