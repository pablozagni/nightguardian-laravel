<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use NightGuardian\Laravel\Capture\SlowRequestMiddleware;
use NightGuardian\Laravel\NightGuardian as Client;
use NightGuardian\Laravel\NightGuardianServiceProvider;
use NightGuardian\Laravel\Transport\Buffer;

function ngQuery(string $sql, float $ms, array $bindings = []): void
{
    event(new QueryExecuted($sql, $bindings, $ms, DB::connection()));
}

// --- slow queries ---------------------------------------------------------------------------------------

it('captures a slow query without its bindings and with the code location', function () {
    ngQuery("select * from invoices\n   where customer_id = ?  and date > ?", 2350.456, [77, '2026-01-01']);

    $events = ngCaptured();

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('slow_query')
        ->and($events[0]['payload']['sql'])->toBe('select * from invoices where customer_id = ? and date > ?')
        ->and($events[0]['payload']['duration_ms'])->toBe(2350.5)
        ->and($events[0]['payload']['threshold_ms'])->toBe(100)
        ->and($events[0]['payload']['connection'])->toBe(DB::connection()->getName())
        ->and($events[0]['payload']['location']['file'])->toBe('tests/Capture/SlowCaptureTest.php')
        ->and(json_encode($events[0]))->not->toContain('2026-01-01')
        ->and(ngSchemaErrors($events[0]))->toBe([]);
});

it('ignores queries under the threshold', function () {
    ngQuery('select 1', 99.9);

    expect(ngCaptured())->toBe([]);
});

it('does not capture slow queries when the threshold is 0', function () {
    config(['nightguardian.slow_query_ms' => 0]);
    app()->forgetInstance(Client::class);

    ngQuery('select 1', 5000.0);

    expect(ngCaptured())->toBe([]);
});

it('reports at most 5 slow queries per request', function () {
    for ($i = 0; $i < 9; $i++) {
        ngQuery("select {$i}", 500.0);
    }

    expect(ngCaptured('slow_query'))->toHaveCount(5);
});

it('allows slow queries again after a flush', function () {
    for ($i = 0; $i < 6; $i++) {
        ngQuery("select {$i}", 500.0);
    }

    app(Client::class)->flush();
    ngQuery('select again', 500.0);

    expect(app(Buffer::class)->count())->toBe(1)
        ->and(ngCaptured('slow_query'))->toHaveCount(6);
});

// --- slow requests --------------------------------------------------------------------------------------

function ngRunRequest(int $sleepMs, ?Request $request = null, int $queries = 0): void
{
    $request ??= Request::create('https://app.test/reports', 'GET');
    $middleware = app(SlowRequestMiddleware::class);

    $response = $middleware->handle($request, function () use ($sleepMs, $queries) {
        for ($i = 0; $i < $queries; $i++) {
            ngQuery('select 1', 1.0);
        }

        usleep($sleepMs * 1000);

        return new Response('ok', 200);
    });

    $middleware->terminate($request, $response);
}

it('captures a slow request in terminate() with duration, queries and status', function () {
    ngRunRequest(90, queries: 3);

    $events = ngCaptured();

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('slow_request')
        ->and($events[0]['payload']['duration_ms'])->toBeGreaterThanOrEqual(90)
        ->and($events[0]['payload']['threshold_ms'])->toBe(50)
        ->and($events[0]['payload']['queries'])->toBe(3)
        ->and($events[0]['request']['status_code'])->toBe(200)
        ->and($events[0]['request']['duration_ms'])->toBeGreaterThanOrEqual(90)
        ->and($events[0]['request']['url'])->toBe('https://app.test/reports')
        ->and(ngSchemaErrors($events[0]))->toBe([]);
});

it('does not capture fast requests', function () {
    ngRunRequest(0);

    expect(ngCaptured())->toBe([]);
});

it('does not capture slow requests when the threshold is 0', function () {
    config(['nightguardian.slow_request_ms' => 0]);
    app()->forgetInstance(Client::class);

    ngRunRequest(80);

    expect(ngCaptured())->toBe([]);
});

it('starts counting queries from zero on every request', function () {
    ngRunRequest(0, queries: 4);
    ngRunRequest(90, queries: 1);

    expect(ngCaptured('slow_request')[0]['payload']['queries'])->toBe(1);
});

it('does not measure anything when the middleware did not run', function () {
    $request = Request::create('https://app.test/x', 'GET');
    app(SlowRequestMiddleware::class)->terminate($request, new Response('ok'));

    expect(ngCaptured())->toBe([]);
});

it('registers itself as the outermost global middleware', function () {
    $method = new ReflectionMethod(NightGuardianServiceProvider::class, 'registerSlowRequestMiddleware');
    $method->setAccessible(true);
    $method->invoke(new NightGuardianServiceProvider(app()));

    $kernel = app(Kernel::class);

    expect($kernel->hasMiddleware(SlowRequestMiddleware::class))->toBeTrue();
});
