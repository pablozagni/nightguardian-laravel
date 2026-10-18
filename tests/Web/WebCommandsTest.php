<?php

use Illuminate\Support\Facades\Artisan;

it('really runs without a console in this test', function () {
    expect(app()->runningInConsole())->toBeFalse();
});

it('registers the nightguardian commands outside a console, so Artisan::call works from a web request', function () {
    expect(array_keys(Artisan::all()))->toContain('nightguardian:status', 'nightguardian:test', 'nightguardian:flush', 'nightguardian:deploy');

    expect(Artisan::call('nightguardian:status'))->toBe(0)
        ->and(Artisan::output())->toContain('NightGuardian SDK');
});

it('can deploy-mark from a web context', function () {
    expect(Artisan::call('nightguardian:deploy', ['--release' => 'web-1']))->toBe(0);
});
