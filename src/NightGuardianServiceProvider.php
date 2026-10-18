<?php

namespace NightGuardian\Laravel;

use Closure;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\ServiceProvider;
use NightGuardian\Laravel\Capture\ExceptionHandlerDecorator;
use NightGuardian\Laravel\Capture\LegacyExceptionHandlerDecorator;
use NightGuardian\Laravel\Capture\LogListener;
use NightGuardian\Laravel\Capture\QueueListener;
use NightGuardian\Laravel\Capture\RequestStats;
use NightGuardian\Laravel\Capture\ScheduleListener;
use NightGuardian\Laravel\Capture\SlowQueryListener;
use NightGuardian\Laravel\Capture\SlowRequestMiddleware;
use NightGuardian\Laravel\Console\DeployCommand;
use NightGuardian\Laravel\Console\FlushCommand;
use NightGuardian\Laravel\Console\StatusCommand;
use NightGuardian\Laravel\Console\TestCommand;
use NightGuardian\Laravel\Event\EventFactory;
use NightGuardian\Laravel\Event\Release;
use NightGuardian\Laravel\Event\RequestContext;
use NightGuardian\Laravel\Event\RuntimeContext;
use NightGuardian\Laravel\Event\StackTrace;
use NightGuardian\Laravel\Event\UserContext;
use NightGuardian\Laravel\Http\FeedbackSigner;
use NightGuardian\Laravel\Privacy\Scrubber;
use NightGuardian\Laravel\Support\Clock;
use NightGuardian\Laravel\Support\Guard;
use NightGuardian\Laravel\Support\SystemClock;
use NightGuardian\Laravel\Transport\Buffer;
use NightGuardian\Laravel\Transport\CircuitBreaker;
use NightGuardian\Laravel\Transport\CurlTransport;
use NightGuardian\Laravel\Transport\Dispatcher;
use NightGuardian\Laravel\Transport\Spool;
use NightGuardian\Laravel\Transport\Stats;
use NightGuardian\Laravel\Transport\Transport;
use Throwable;

class NightGuardianServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nightguardian.php', 'nightguardian');

        $this->app->singleton(Buffer::class);
        $this->app->singleton(RequestStats::class);
        $this->app->singleton(ScheduleListener::class);

        $this->app->singleton(NightGuardian::class, function (Application $app) {
            return new NightGuardian($this->config($app), $app->make(Buffer::class), $app->make(EventFactory::class), $app->make(Dispatcher::class), $app->make(RequestStats::class));
        });

        $this->app->singleton(FeedbackSigner::class, function (Application $app) {
            $key = $app['config']->get('app.key');

            return new FeedbackSigner(is_string($key) ? $key : '', $app->make(Clock::class));
        });

        $this->registerTransport();

        $this->app->singleton(Scrubber::class, function (Application $app) {
            $extra = $this->config($app)['scrub_keys'] ?? [];

            return new Scrubber(is_array($extra) ? array_values(array_filter($extra, 'is_string')) : []);
        });

        $this->app->singleton(StackTrace::class, function (Application $app) {
            return new StackTrace($app->basePath());
        });

        $this->app->singleton(Release::class, function (Application $app) {
            $configured = $this->config($app)['release'] ?? null;

            return new Release(is_string($configured) ? $configured : null, $app->basePath(), $app->storagePath().DIRECTORY_SEPARATOR.'nightguardian');
        });

        $this->app->singleton(EventFactory::class, function (Application $app) {
            $config = $this->config($app);

            return new EventFactory($config, $app->make(Scrubber::class), $app->make(StackTrace::class), new RequestContext($app->make(Scrubber::class), (bool) ($config['send_ip'] ?? false), (bool) ($config['send_request_body'] ?? false)), new UserContext($this->guardUser($app), $config['user_resolver'] ?? null, (bool) ($config['send_user_email'] ?? false)), new RuntimeContext($app->version()), $app->make(Release::class), $this->currentRequest($app));
        });
    }

    private function registerTransport(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);

        $this->app->singleton(Transport::class, function (Application $app) {
            $config = $this->config($app);
            $url = (new NightGuardian($config))->ingestUrl();

            return new CurlTransport($url ?? '', is_string($config['token'] ?? null) ? trim($config['token']) : '', (float) ($config['timeout'] ?? 2), (float) ($config['connect_timeout'] ?? 1));
        });

        $this->app->singleton(Dispatcher::class, function (Application $app) {
            $config = $this->config($app);
            $directory = $app->storagePath().DIRECTORY_SEPARATOR.'nightguardian';
            $clock = $app->make(Clock::class);

            return new Dispatcher($app->make(Transport::class), new Spool($directory.'/spool', max(0, (int) ($config['spool_max_mb'] ?? 10)) * 1048576, (int) ($config['spool_max_age_days'] ?? 7), $clock), new CircuitBreaker($directory.'/breaker.json', $clock), new Stats($directory.'/stats.json'), $clock, $directory.'/drain.lock');
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nightguardian');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'nightguardian');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/nightguardian'),
            ], 'nightguardian-views');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->resourcePath('lang/vendor/nightguardian'),
            ], 'nightguardian-lang');

            $this->publishes([
                __DIR__.'/../config/nightguardian.php' => $this->app->configPath('nightguardian.php'),
            ], 'nightguardian-config');

        }

        // Registered in every context (not only in a console) so that Artisan::call('nightguardian:...') works
        // from a web request, a deploy hook or a queued job.
        $this->commands([TestCommand::class, StatusCommand::class, FlushCommand::class, DeployCommand::class]);

        Guard::run(function () {
            if (NightGuardian::inactiveReasonFor($this->config($this->app)) === null) {
                $this->registerCapture();
            }
        });
    }

    /**
     * Hooks into Laravel. Nothing is registered while the SDK is inactive, so an inactive SDK costs nothing.
     */
    /**
     * Registers `Class::method` as a listener through the container. Laravel 5.8 treats `[Class::class, 'method']`
     * as a static callable, newer versions as a class listener; a closure behaves the same everywhere.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @param  string  $event
     * @param  string  $class
     * @param  string  $method
     */
    private function listen($events, $event, $class, $method): void
    {
        $events->listen($event, function (...$arguments) use ($class, $method) {
            return $this->app->make($class)->{$method}(...$arguments);
        });
    }

    private function registerCapture(): void
    {
        $config = $this->config($this->app);
        $events = $this->app['events'];

        $this->listen($events, MessageLogged::class, LogListener::class, 'handle');
        $this->listen($events, JobFailed::class, QueueListener::class, 'handle');
        $this->listen($events, ScheduledTaskStarting::class, ScheduleListener::class, 'starting');
        $this->listen($events, ScheduledTaskFailed::class, ScheduleListener::class, 'failed');
        $this->listen($events, ScheduledTaskFinished::class, ScheduleListener::class, 'finished');

        $slowQueryMs = (int) ($config['slow_query_ms'] ?? 0);
        $slowRequestMs = (int) ($config['slow_request_ms'] ?? 0);

        // Slow requests also need the query counter, so the listener runs when either feature is on.
        if ($slowQueryMs > 0 || $slowRequestMs > 0) {
            $this->listen($events, QueryExecuted::class, SlowQueryListener::class, 'handle');
        }

        if ($slowRequestMs > 0 && ! $this->app->runningInConsole()) {
            $this->registerSlowRequestMiddleware();
        }

        $this->registerFlushPoints($events);
        $this->registerErrorPage();
    }

    /**
     * Where the buffered events leave: after the response, after each job, after each command, and at
     * shutdown as a last resort. These listeners are registered after the capture ones on purpose.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     */
    private function registerFlushPoints($events): void
    {
        $flush = function () {
            return $this->app->make(NightGuardian::class)->flush();
        };

        /** @var \Illuminate\Foundation\Application $application */
        $application = $this->app;
        $application->terminating($flush);

        foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class, CommandFinished::class, ScheduledTaskFinished::class, ScheduledTaskFailed::class] as $event) {
            $events->listen($event, static function () use ($flush) {
                return $flush();
            });
        }

        static $shutdownRegistered = false;

        if (! $shutdownRegistered) {
            $shutdownRegistered = true;
            $app = $this->app;

            register_shutdown_function(static function () use ($app) {
                Guard::run(function () use ($app) {
                    $buffer = $app->make(Buffer::class);

                    if (! $buffer->isEmpty()) {
                        $app->make(NightGuardian::class)->flush();
                    }
                });
            });
        }
    }

    /**
     * The friendly 500 page (decorated exception handler) and the signed feedback route.
     */
    private function registerErrorPage(): void
    {
        $this->app->extend(ExceptionHandler::class, function (ExceptionHandler $handler, Application $app) {
            if ($handler instanceof ExceptionHandlerDecorator || $handler instanceof LegacyExceptionHandlerDecorator) {
                return $handler;
            }

            // PHP 7.1 cannot widen parameter types, so it needs the decorator that declares `Exception $e`.
            $decorator = PHP_VERSION_ID < 70200 ? LegacyExceptionHandlerDecorator::class : ExceptionHandlerDecorator::class;

            return new $decorator($handler, $app->make(NightGuardian::class), $app->make(ViewFactory::class), $app->make(FeedbackSigner::class), $app->make(ConfigRepository::class));
        });

        if ((bool) ($this->config($this->app)['feedback'] ?? true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/feedback.php');
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
        return static function () use ($app) {
            if (! $app->bound('auth')) {
                return null;
            }

            /** @var \Illuminate\Contracts\Auth\Guard $guard */
            $guard = $app->make('auth')->guard();

            return method_exists($guard, 'hasUser') && $guard->hasUser() ? $guard->user() : null;
        };
    }
}
