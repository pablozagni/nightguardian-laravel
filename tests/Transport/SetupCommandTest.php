<?php

use Illuminate\Support\Facades\Artisan;
use NightGuardian\Laravel\Tests\Fakes\FakeTransport;
use NightGuardian\Laravel\Transport\Dispatcher;
use NightGuardian\Laravel\Transport\Transport;
use NightGuardian\Laravel\Transport\TransportResult;
use Symfony\Component\Console\Output\BufferedOutput;

const NG_SETUP_TOKEN = 'ng_SetupTokenOnlyForTests0123456789';

/**
 * Points the app at a throwaway .env file and returns its path.
 */
function ngSetupEnv(string $contents = "APP_NAME=Shop\nAPP_ENV=production\n"): string
{
    $directory = sys_get_temp_dir().'/ng-setup-'.bin2hex(random_bytes(5));
    mkdir($directory);
    file_put_contents($directory.'/.env', $contents);

    app()->useEnvironmentPath($directory);
    app()->loadEnvironmentFrom('.env');

    return $directory.'/.env';
}

/**
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function ngSetup(array $options): array
{
    $output = new BufferedOutput;
    $code = Artisan::call('nightguardian:setup', $options + ['--no-interaction' => true], $output);

    return [$code, $output->fetch()];
}

it('writes the URL and the token to the .env, keeps the rest and sends a test event', function () {
    $env = ngSetupEnv();

    [$code, $output] = ngSetup(['--url' => 'https://ng.example.com/', '--token' => NG_SETUP_TOKEN]);

    expect($code)->toBe(0)
        ->and(file_get_contents($env))->toBe("APP_NAME=Shop\nAPP_ENV=production\nNIGHTGUARDIAN_URL=https://ng.example.com\nNIGHTGUARDIAN_TOKEN=".NG_SETUP_TOKEN."\n")
        ->and($output)->toContain('written to')
        ->toContain('https://ng.example.com/api/v1/ingest')
        ->toContain('The collector accepted the test event')
        ->not->toContain(NG_SETUP_TOKEN);

    $event = app(Transport::class)->batches[0][0];
    expect($event['type'])->toBe('test');
});

it('never prints the token, not even when the collector rejects it', function () {
    ngSetupEnv();
    $this->app->singleton(Transport::class, fn () => tap(new FakeTransport, fn (FakeTransport $fake) => $fake->script[] = new TransportResult(401, 'HTTP 401')));

    [$code, $output] = ngSetup(['--url' => 'https://ng.example.com', '--token' => NG_SETUP_TOKEN]);

    expect($code)->toBe(1)->and($output)->toContain('rejected the token')->not->toContain(NG_SETUP_TOKEN);
});

it('can be run again: the second run changes nothing and still succeeds', function () {
    $env = ngSetupEnv();
    ngSetup(['--url' => 'https://ng.example.com', '--token' => NG_SETUP_TOKEN]);
    $first = file_get_contents($env);

    [$code, $output] = ngSetup(['--url' => 'https://ng.example.com', '--token' => NG_SETUP_TOKEN]);

    expect($code)->toBe(0)->and(file_get_contents($env))->toBe($first)->and($output)->toContain('already has this URL and token');
});

it('updates the values of a previous setup in place', function () {
    $env = ngSetupEnv("NIGHTGUARDIAN_URL=https://old.example.com\nAPP_ENV=production\nNIGHTGUARDIAN_TOKEN=ng_old\n");

    [$code] = ngSetup(['--url' => 'https://ng.example.com', '--token' => NG_SETUP_TOKEN, '--no-test' => true]);

    expect($code)->toBe(0)
        ->and(file_get_contents($env))->toBe("NIGHTGUARDIAN_URL=https://ng.example.com\nAPP_ENV=production\nNIGHTGUARDIAN_TOKEN=".NG_SETUP_TOKEN."\n");
});

it('does not send a test event with --no-test', function () {
    ngSetupEnv();

    [$code, $output] = ngSetup(['--url' => 'https://ng.example.com', '--token' => NG_SETUP_TOKEN, '--no-test' => true]);

    expect($code)->toBe(0)->and($output)->not->toContain('test event')->and(app(Transport::class)->batches)->toBe([]);
});

it('rejects a token that does not start with ng_ and leaves the .env alone', function () {
    $env = ngSetupEnv();

    [$code, $output] = ngSetup(['--url' => 'https://ng.example.com', '--token' => 'sk_live_whatever']);

    expect($code)->toBe(1)
        ->and($output)->toContain('start with "ng_"')->not->toContain('sk_live_whatever')
        ->and(file_get_contents($env))->toBe("APP_NAME=Shop\nAPP_ENV=production\n");
});

it('rejects an unusable URL', function (string $url) {
    $env = ngSetupEnv();

    [$code, $output] = ngSetup(['--url' => $url, '--token' => NG_SETUP_TOKEN]);

    expect($code)->toBe(1)->and($output)->toContain('http(s) address')->and(file_get_contents($env))->toBe("APP_NAME=Shop\nAPP_ENV=production\n");
})->with(['no scheme' => ['ng.example.com'], 'ftp' => ['ftp://ng.example.com'], 'garbage' => ['https://']]);

it('only warns about http://', function () {
    $env = ngSetupEnv();

    [$code, $output] = ngSetup(['--url' => 'http://ng.example.com', '--token' => NG_SETUP_TOKEN, '--no-test' => true]);

    expect($code)->toBe(0)->and($output)->toContain('unencrypted')->and(file_get_contents($env))->toContain('NIGHTGUARDIAN_URL=http://ng.example.com');
});

it('asks for what is missing, hiding the token', function () {
    $env = ngSetupEnv();

    $this->artisan('nightguardian:setup', ['--no-test' => true])
        ->expectsQuestion('Collector URL (e.g. https://ng.example.com)', 'https://ng.example.com')
        ->expectsQuestion('Project token (ng_..., input is hidden)', NG_SETUP_TOKEN)
        ->assertExitCode(0);

    expect(file_get_contents($env))->toContain('NIGHTGUARDIAN_URL=https://ng.example.com')->toContain('NIGHTGUARDIAN_TOKEN='.NG_SETUP_TOKEN);
});

it('fails instead of waiting for input when it cannot ask', function () {
    $env = ngSetupEnv();

    [$code, $output] = ngSetup(['--url' => 'https://ng.example.com']);

    expect($code)->toBe(1)->and($output)->toContain('token is required')->and(file_get_contents($env))->toBe("APP_NAME=Shop\nAPP_ENV=production\n");

    [$code, $output] = ngSetup(['--token' => NG_SETUP_TOKEN]);
    expect($code)->toBe(1)->and($output)->toContain('URL is required');
});

it('fails when the application has no .env file', function () {
    $env = ngSetupEnv();
    unlink($env);

    [$code, $output] = ngSetup(['--url' => 'https://ng.example.com', '--token' => NG_SETUP_TOKEN]);

    expect($code)->toBe(1)->and($output)->toContain('does not exist')->not->toContain(NG_SETUP_TOKEN);
});

it('saves the settings but fails when the collector does not confirm the test event', function () {
    $env = ngSetupEnv();
    $this->app->singleton(Transport::class, fn () => tap(new FakeTransport, fn (FakeTransport $fake) => $fake->script[] = new TransportResult(0, 'curl error 6: Could not resolve host')));

    [$code, $output] = ngSetup(['--url' => 'https://ng.invalid', '--token' => NG_SETUP_TOKEN]);

    expect($code)->toBe(1)
        ->and($output)->toContain('did not confirm the test event')->not->toContain(NG_SETUP_TOKEN)
        ->and(file_get_contents($env))->toContain('NIGHTGUARDIAN_URL=https://ng.invalid')
        ->and(app(Dispatcher::class)->spool()->count())->toBe(1);
});

it('caches the configuration again when it was cached', function () {
    ngSetupEnv();
    $cache = app()->getCachedConfigPath();
    file_put_contents($cache, '<?php return [];');

    try {
        [$code, $output] = ngSetup(['--url' => 'https://ng.example.com', '--token' => NG_SETUP_TOKEN, '--no-test' => true]);

        expect($code)->toBe(0)->and($output)->toContain('Cached configuration refreshed');
    } finally {
        @unlink($cache);
    }
});

it('does not touch the configuration cache when there is none', function () {
    ngSetupEnv();

    [, $output] = ngSetup(['--url' => 'https://ng.example.com', '--token' => NG_SETUP_TOKEN, '--no-test' => true]);

    expect($output)->not->toContain('Cached configuration');
});
