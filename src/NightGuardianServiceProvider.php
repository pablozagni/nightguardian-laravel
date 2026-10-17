<?php

namespace NightGuardian\Laravel;

use Closure;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\ServiceProvider;
use NightGuardian\Laravel\Capture\LogListener;
use NightGuardian\Laravel\Capture\QueueListener;
use NightGuardian\Laravel\Capture\RequestStats;
use NightGuardian\Laravel\Capture\ScheduleListener;
use NightGuardian\Laravel\Capture\SlowQueryListener;
use NightGuardian\Laravel\Capture\SlowRequestMiddleware;
use NightGuardian\Laravel\Event\EventFactory;
use NightGuardian\Laravel\Event\Release;
use NightGuardian\Laravel\Event\RequestContext;
use NightGuardian\Laravel\Event\RuntimeContext;
use NightGuardian\Laravel\Event\StackTrace;
use NightGuardian\Laravel\Event\UserContext;
use NightGuardian\Laravel\Privacy\Scrubber;
use NightGuardian\Laravel\Support\Guard;
use NightGuardian\Laravel\Transport\Buffer;
use Throwable;

class NightGuardianServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nightguardian.php', 'nightguardian');

        $this->app->singleton(Buffer::class);
        $this->app->singleton(RequestStats::class);
        $this->app->singleton(ScheduleListener::class);

        $this->app->singleton(NightGuardian::class, fn (Application $app) => new NightGuardian(
            $this->config($app),
            $app->make(Buffer::class),
            $app->make(EventFactory::class),
            null,
            $app->make(RequestStats::class),
        ));

        $this->app->singleton(Scrubber::class, function (Application $app) {
            $extra = $this->config($app)['scrub_keys'] ?? [];

            return new Scrubber(is_array($extra) ? array_values(array_filter($extra, 'is_string')) : []);
        });

        $this->app->singleton(StackTrace::class, fn (Application $app) => new StackTrace($app->basePath()));

        $this->app->singleton(Release::class, function (Application $app) {
            $configured = $this->config($app)['release'] ?? null;

            return new Release(is_string($configured) ? $configured : null, $app->basePath(), $app->storagePath('nightguardian'));
        });

        $this->app->singleton(EventFactory::class, function (Application $app) {
            $config = $this->config($app);

            return new EventFactory(
                $config,
                $app->make(Scrubber::class),
                $app->make(StackTrace::class),
                new RequestContext($app->make(Scrubber::class), (bool) ($config['send_ip'] ?? false), (bool) ($config['send_request_body'] ?? false)),
                new UserContext($this->guardUser($app), $config['user_resolver'] ?? null, (bool) ($config['send_user_email'] ?? false)),
                new RuntimeContext($app->version()),
                $app->make(Release::class),
                $this->currentRequest($app),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/nightguardian.php' => $this->app->configPath('nightguardian.php'),
            ], 'nightguardian-config');
        }

        Guard::run(function () {
            if (NightGuardian::inactiveReasonFor($this->config($this->app)) === null) {
                $this->registerCapture();
            }
        });
    }

    /**
     * Hooks into Laravel. Nothing is registered while the SDK is inactive, so an inactive SDK costs nothing.
     */
    private function registerCapture(): void
    {
        $config = $this->config($this->app);
        $events = $this->app['events'];

        $events->listen(MessageLogged::class, [LogListener::class, 'handle']);
        $events->listen(JobFailed::class, [QueueListener::class, 'handle']);
        $events->listen(ScheduledTaskStarting::class, [ScheduleListener::class, 'starting']);
        $events->listen(ScheduledTaskFailed::class, [ScheduleListener::class, 'failed']);
        $events->listen(ScheduledTaskFinished::class, [ScheduleListener::class, 'finished']);

        $slowQueryMs = (int) ($config['slow_query_ms'] ?? 0);
        $slowRequestMs = (int) ($config['slow_request_ms'] ?? 0);

        // Slow requests also need the query counter, so the listener runs when either feature is on.
        if ($slowQueryMs > 0 || $slowRequestMs > 0) {
            $events->listen(QueryExecuted::class, [SlowQueryListener::class, 'handle']);
        }

        if ($slowRequestMs > 0 && ! $this->app->runningInConsole()) {
            $this->registerSlowRequestMiddleware();
        }
    }

    private function registerSlowRequestMiddleware(): void
    {
        try {
            $kernel = $this->app->make(HttpKernel::class);

            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(SlowRequestMiddleware::class);
            }
        } catch (Throwable $e) {
            Guard::record($e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function config(Application $app): array
    {
        /** @var array<string, mixed> $config */
        $config = $app['config']->get('nightguardian', []);

        return $config;
    }

    /**
     * The HTTP request being served; null in the console, where Laravel's request is a placeholder.
     *
     * @return Closure(): ?Request
     */
    private function currentRequest(Application $app): Closure
    {
        return static function () use ($app): ?Request {
            if ($app->runningInConsole() || ! $app->bound('request')) {
                return null;
            }

            $request = $app->make('request');

            return $request instanceof Request ? $request : null;
        };
    }

    /**
     * The user the default guard has already loaded. Never asks the guard to query for one.
     *
     * @return Closure(): mixed
     */
    private function guardUser(Application $app): Closure
    {
        return static function () use ($app): mixed {
            if (! $app->bound('auth')) {
                return null;
            }

            $guard = $app->make('auth')->guard();

            return method_exists($guard, 'hasUser') && $guard->hasUser() ? $guard->user() : null;
        };
    }
}
