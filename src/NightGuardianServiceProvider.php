<?php

namespace NightGuardian\Laravel;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use NightGuardian\Laravel\Event\EventFactory;
use NightGuardian\Laravel\Event\Release;
use NightGuardian\Laravel\Event\RequestContext;
use NightGuardian\Laravel\Event\RuntimeContext;
use NightGuardian\Laravel\Event\StackTrace;
use NightGuardian\Laravel\Event\UserContext;
use NightGuardian\Laravel\Privacy\Scrubber;

class NightGuardianServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nightguardian.php', 'nightguardian');

        $this->app->singleton(NightGuardian::class, fn (Application $app) => new NightGuardian($this->config($app)));

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
