<?php

use NightGuardian\Laravel\NightGuardian;
use NightGuardian\Laravel\Transport\CurlTransport;

/**
 * Talks to a real HTTP server (PHP's built-in one) that plays the collector.
 */
function ngServer(): array
{
    static $server = null;

    if ($server !== null) {
        return $server;
    }

    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);

    $capture = tempnam(sys_get_temp_dir(), 'ngcap');
    $command = [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/../fixtures/ingest-server.php'];
    $env = array_merge(getenv(), ['NG_CAPTURE_FILE' => $capture, 'PHP_CLI_SERVER_WORKERS' => '4']);

    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir().'/ng-server.log', 'a'], 2 => ['file', sys_get_temp_dir().'/ng-server.log', 'a']], $pipes, null, $env);

    for ($i = 0; $i < 100; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

        if ($socket !== false) {
            fclose($socket);
            break;
        }

        usleep(50_000);
    }

    register_shutdown_function(function () use ($process, $capture) {
        proc_terminate($process);
        @unlink($capture);
    });

    return $server = ['url' => "http://127.0.0.1:{$port}/api/v1/ingest", 'capture' => $capture, 'base' => "http://127.0.0.1:{$port}"];
}

function ngCurl(string $token, float $timeout = 2.0, ?string $url = null): CurlTransport
{
    return new CurlTransport($url ?? ngServer()['url'], $token, $timeout, 1.0);
}

function ngRequests(): array
{
    return array_map(fn (string $line) => json_decode($line, true), array_filter(explode("\n", (string) file_get_contents(ngServer()['capture']))));
}

function ngRealEvents(int $count = 2): array
{
    return array_map(fn (int $i) => ['id' => "real{$i}", 'type' => 'log', 'level' => 'error', 'payload' => ['message' => "m{$i}"]], range(1, $count));
}

it('posts a gzipped batch with the protocol headers and parses the per-event results', function () {
    $result = ngCurl('ok')->send(ngRealEvents(2));

    $request = ngLastRequest();

    expect($result->status)->toBe(202)
        ->and($result->error)->toBeNull()
        ->and($result->results)->toHaveCount(2)
        ->and($result->results[0])->toMatchArray(['id' => 'real1', 'status' => 'accepted', 'issue' => '01JREALSERVER'])
        ->and($request['method'])->toBe('POST')
        ->and($request['headers']['authorization'])->toBe('Bearer ok')
        ->and($request['headers']['content-type'])->toBe('application/json')
        ->and($request['headers']['content-encoding'])->toBe('gzip')
        ->and($request['headers']['user-agent'])->toBe(NightGuardian::SDK_NAME.'/'.NightGuardian::VERSION)
        ->and($request['body']['sdk'])->toBe(['name' => 'nightguardian-laravel', 'version' => NightGuardian::VERSION])
        ->and($request['body']['sent_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/')
        ->and(array_column($request['body']['events'], 'id'))->toBe(['real1', 'real2']);
});

it('actually compresses the body', function () {
    ngCurl('ok')->send(array_map(fn ($i) => ['id' => "c{$i}", 'type' => 'log', 'payload' => ['message' => str_repeat('repeat ', 500)]], range(1, 20)));

    $request = ngLastRequest();
    $plain = strlen(json_encode($request['body']));

    expect($request['compressed_bytes'])->toBeLessThan($plain / 5);
});

it('reports the status of every kind of answer', function (string $token, int $status) {
    $result = ngCurl($token)->send(ngRealEvents(1));

    expect($result->status)->toBe($status)->and($result->isAccepted())->toBe($status === 202);
})->with([
    'unauthorized' => ['bad', 401],
    'forbidden' => ['forbidden', 403],
    'rate limited' => ['limit', 429],
    'server error' => ['boom', 500],
    'too large' => ['big', 413],
    'malformed' => ['malformed', 400],
    'redirect is not followed' => ['redirect', 302],
]);

it('reads Retry-After and the collector error message', function () {
    $limited = ngCurl('limit')->send(ngRealEvents(1));
    $unauthorized = ngCurl('bad')->send(ngRealEvents(1));

    expect($limited->retryAfter)->toBe(7)
        ->and($limited->error)->toBe('HTTP 429: Too many events.')
        ->and($unauthorized->error)->toBe('HTTP 401: Invalid token.')
        ->and($unauthorized->retryAfter)->toBeNull();
});

it('survives a 202 that is not the collector (HTML or oversized body)', function (string $token) {
    $result = ngCurl($token)->send(ngRealEvents(1));

    expect($result->results)->toBe([]);
})->with(['html', 'huge']);

it('gives up after the total timeout without hanging the request', function () {
    $started = microtime(true);
    $result = ngCurl('slow', timeout: 0.4)->send(ngRealEvents(1));
    $elapsed = microtime(true) - $started;

    expect($result->status)->toBe(0)
        ->and($result->error)->toContain('curl error 28')
        ->and($elapsed)->toBeLessThan(2.0);
});

it('reports a refused connection as a network failure', function () {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);

    $started = microtime(true);
    $result = ngCurl('ok', url: "http://127.0.0.1:{$port}/api/v1/ingest")->send(ngRealEvents(1));

    expect($result->status)->toBe(0)
        ->and($result->error)->toMatch('/curl error (7|28)/') // Windows reports a timeout instead of a refusal
        ->and(microtime(true) - $started)->toBeLessThan(2.0);
});

it('never throws, whatever the URL', function (string $url) {
    $result = (new CurlTransport($url, 'ok', 0.5, 0.5))->send(ngRealEvents(1));

    expect($result->status)->toBe(0)->and($result->error)->not->toBeNull();
})->with(['', 'not a url', 'ftp://127.0.0.1:1/x', 'http://']);

it('encodes events containing invalid UTF-8 instead of failing', function () {
    $result = ngCurl('ok')->send([['id' => 'bad-utf8', 'type' => 'log', 'payload' => ['message' => "broken \xB1\x31 text"]]]);

    expect($result->status)->toBe(202)->and(ngLastRequest()['body']['events'][0]['id'])->toBe('bad-utf8');
});

function ngLastRequest(): array
{
    $requests = ngRequests();

    return $requests[array_key_last($requests)];
}
