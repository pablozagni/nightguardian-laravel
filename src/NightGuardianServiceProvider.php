<?php

namespace NightGuardian\Laravel;

use Illuminate\Support\ServiceProvider;

class NightGuardianServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nightguardian.php', 'nightguardian');

        $this->app->singleton(NightGuardian::class, function ($app) {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('nightguardian', []);

            return new NightGuardian($config);
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
}
