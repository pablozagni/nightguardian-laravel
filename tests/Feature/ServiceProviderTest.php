<?php

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use NightGuardian\Laravel\Facades\NightGuardian as NightGuardianFacade;
use NightGuardian\Laravel\NightGuardian;
use NightGuardian\Laravel\NightGuardianServiceProvider;

function ngClient(array $config): NightGuardian
{
    return new NightGuardian($config);
}

it('boots the provider and binds the client as a singleton', function () {
    expect(app(NightGuardian::class))->toBeInstanceOf(NightGuardian::class)
        ->and(app(NightGuardian::class))->toBe(app(NightGuardian::class));
});

it('resolves the facade', function () {
    expect(NightGuardianFacade::getFacadeRoot())->toBeInstanceOf(NightGuardian::class);
});

it('merges the default configuration', function () {
    $config = config('nightguardian');

    expect($config)->toHaveKeys([
        'enabled', 'url', 'token', 'environment', 'release', 'log_level', 'slow_request_ms', 'slow_query_ms',
        'error_page', 'feedback', 'timeout', 'connect_timeout', 'spool_max_mb', 'spool_max_age_days',
        'send_ip', 'send_request_body', 'send_user_email',
        'ignore_exceptions', 'scrub_keys', 'header_allowlist', 'tags', 'user_resolver',
    ])
        ->and($config['log_level'])->toBe('error')
        ->and($config['slow_request_ms'])->toBe(2000)
        ->and($config['slow_query_ms'])->toBe(1000)
        ->and($config['timeout'])->toBe(2.0)
        ->and($config['connect_timeout'])->toBe(1.0)
        ->and($config['spool_max_mb'])->toBe(10)
        ->and($config['send_ip'])->toBeFalse()
        ->and($config['send_request_body'])->toBeFalse()
        ->and($config['send_user_email'])->toBeFalse()
        ->and($config['header_allowlist'])->not->toContain('cookie', 'authorization');
});

it('registers the publishable config', function () {
    $paths = ServiceProvider::pathsToPublish(
        NightGuardianServiceProvider::class,
        'nightguardian-config',
    );

    expect($paths)->not->toBeEmpty();
});

it('is inactive out of the box because no URL or token is configured', function () {
    $client = app(NightGuardian::class);

    expect($client->isActive())->toBeFalse()
        ->and($client->inactiveReason())->toContain('NIGHTGUARDIAN_URL');
});

it('is inactive when the token is missing', function () {
    $client = ngClient(['enabled' => true, 'url' => 'https://ng.example.com', 'token' => '']);

    expect($client->isActive())->toBeFalse()
        ->and($client->inactiveReason())->toContain('NIGHTGUARDIAN_TOKEN');
});

it('is inactive when disabled, even with a URL and a token', function () {
    $client = ngClient(['enabled' => false, 'url' => 'https://ng.example.com', 'token' => 'ng_abc']);

    expect($client->isActive())->toBeFalse()
        ->and($client->inactiveReason())->toContain('NIGHTGUARDIAN_ENABLED');
});

it('is inactive when the URL is not an absolute http(s) URL', function (string $url) {
    $client = ngClient(['enabled' => true, 'url' => $url, 'token' => 'ng_abc']);

    expect($client->isActive())->toBeFalse();
})->with(['ng.example.com', 'ftp://ng.example.com', 'https://', '   ']);

it('is active with a valid URL and token', function () {
    $client = ngClient(['enabled' => true, 'url' => 'https://ng.example.com/', 'token' => 'ng_abc']);

    expect($client->isActive())->toBeTrue()
        ->and($client->inactiveReason())->toBeNull()
        ->and($client->ingestUrl())->toBe('https://ng.example.com/api/v1/ingest');
});

it('becomes active from the application configuration', function () {
    config(['nightguardian.url' => 'http://collector.nightguardian.test', 'nightguardian.token' => 'ng_abc']);
    app()->forgetInstance(NightGuardian::class);
    Facade::clearResolvedInstances();

    expect(NightGuardianFacade::isActive())->toBeTrue();
});

it('keeps working when the configuration is garbage', function () {
    $client = ngClient(['enabled' => true, 'url' => ['not', 'a string'], 'token' => 42]);

    expect($client->isActive())->toBeFalse()
        ->and($client->ingestUrl())->toBeNull()
        ->and($client->internalErrors())->toBe(0);
});
