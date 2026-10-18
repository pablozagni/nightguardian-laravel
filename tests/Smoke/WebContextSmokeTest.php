<?php

namespace NightGuardian\Laravel\Tests\Smoke;

use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use NightGuardian\Laravel\Tests\Fakes\FakeTransport;
use NightGuardian\Laravel\Tests\WebContextTestCase;
use NightGuardian\Laravel\Transport\Buffer;
use NightGuardian\Laravel\Transport\Transport;
use RuntimeException;

/**
 * The 1.0.1 fixes, on the Laravel and PHP versions where Pest cannot run (5.8, 6, PHP 7.1/7.2).
 */
class WebContextSmokeTest extends WebContextTestCase
{
    public function test_the_commands_exist_without_a_console()
    {
        $this->assertFalse($this->app->runningInConsole());
        $this->assertArrayHasKey('nightguardian:status', Artisan::all());
        $this->assertSame(0, Artisan::call('nightguardian:status'));
        $this->assertStringContainsString('NightGuardian SDK', Artisan::output());
    }

    public function test_an_unhandled_exception_carries_the_status_code_of_the_response()
    {
        $this->app['config']->set('app.debug', false);
        Route::get('/boom', function () {
            throw new RuntimeException('Kaboom');
        });

        $this->get('/boom', ['Accept' => 'text/html'])->assertStatus(500);

        $exceptions = array_values(array_filter($this->transport()->events(), function ($event) {
            return $event['type'] === 'exception';
        }));

        $this->assertCount(1, $exceptions);
        $this->assertSame(500, $exceptions[0]['request']['status_code']);
    }

    public function test_a_failed_scheduled_task_is_reported_once()
    {
        $finished = 'Illuminate\Console\Events\ScheduledTaskFinished';
        $failed = 'Illuminate\Console\Events\ScheduledTaskFailed';

        if (! class_exists($failed)) {
            $this->markTestSkipped('ScheduledTaskFailed exists from Laravel 8.');
        }

        $task = new Event(new CacheEventMutex($this->app->make('cache')), 'php artisan demo:fail');
        $task->exitCode = 1;
        $exception = new \Exception('Scheduled command [php artisan demo:fail] failed with exit code [1].');

        event(new ScheduledTaskStarting($task));
        event(new $finished($task, 0.4));
        event(new $failed($task, $exception));
        Log::error($exception->getMessage(), ['exception' => $exception]);

        $types = array_map(function ($event) {
            return $event['type'];
        }, array_merge($this->transport()->events(), $this->app->make(Buffer::class)->all()));

        $this->assertSame(['schedule_failed'], $types);
    }

    /**
     * @return FakeTransport
     */
    private function transport()
    {
        return $this->app->make(Transport::class);
    }
}
