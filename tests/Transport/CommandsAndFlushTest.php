<?php

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Log;
use NightGuardian\Laravel\Facades\NightGuardian;
use NightGuardian\Laravel\NightGuardian as Client;
use NightGuardian\Laravel\Tests\Fakes\FakeTransport;
use NightGuardian\Laravel\Transport\Dispatcher;
use NightGuardian\Laravel\Transport\Transport;
use NightGuardian\Laravel\Transport\TransportResult;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

function ngFakeTransport(): FakeTransport
{
    return app(Transport::class);
}

// --- nightguardian:test -----------------------------------------------------------------------------------

it('sends a test event and shows the collector\'s answer', function () {
    $this->artisan('nightguardian:test')
        ->expectsOutputToContain('http://collector.test/api/v1/ingest')
        ->expectsOutputToContain('The collector accepted the test event')
        ->expectsOutputToContain('Issue: 01JTESTISSUE')
        ->assertExitCode(0);

    $event = ngFakeTransport()->batches[0][0];

    expect($event['type'])->toBe('test')
        ->and($event)->not->toHaveKey('request')
        ->and($event['payload']['exceptions'][0]['message'])->toContain('NightGuardian test event')
        ->and(ngSchemaErrors($event))->toBe([]);
});

it('says it is inactive without URL and token and exits with an error', function () {
    config(['nightguardian.token' => '']);
    app()->forgetInstance(Client::class);

    $this->artisan('nightguardian:test')
        ->expectsOutputToContain('NIGHTGUARDIAN_TOKEN is not set')
        ->assertExitCode(1);

    expect(ngFakeTransport()->batches)->toBe([]);
});

it('reports the event as saved in the spool when the collector is down, without errors', function () {
    ngFakeTransport()->script[] = new TransportResult(0, 'curl error 6: Could not resolve host: collector.invalid');

    $this->artisan('nightguardian:test')
        ->expectsOutputToContain('could not be reached (curl error 6: Could not resolve host')
        ->expectsOutputToContain('saved in the spool')
        ->assertExitCode(0);

    expect(app(Dispatcher::class)->spool()->count())->toBe(1);
});

it('explains a rejected token and fails', function () {
    ngFakeTransport()->script[] = new TransportResult(401, 'HTTP 401');

    $this->artisan('nightguardian:test')
        ->expectsOutputToContain('rejected the token (HTTP 401)')
        ->assertExitCode(1);
});

it('explains rate limiting and server errors as a spooled event', function (int $status, string $text) {
    ngFakeTransport()->script[] = new TransportResult($status, "HTTP {$status}", 30);

    $this->artisan('nightguardian:test')->expectsOutputToContain($text)->assertExitCode(0);
})->with([[429, 'rate limiting'], [503, 'failed to process']]);

it('tries even when the breaker is open, because the user asked for it', function () {
    ngFakeTransport()->script[] = new TransportResult(0, 'down');
    NightGuardian::message('first failure');
    NightGuardian::flush();

    $this->artisan('nightguardian:test')->expectsOutputToContain('accepted the test event')->assertExitCode(0);
});

it('warns about plain http in production', function () {
    config(['nightguardian.environment' => 'production']);
    app()->forgetInstance(Client::class);

    $this->artisan('nightguardian:test')->expectsOutputToContain('http:// in a production environment')->assertExitCode(0);
});

// --- nightguardian:status -----------------------------------------------------------------------------------

it('shows configuration, breaker, spool and counters', function () {
    $this->artisan('nightguardian:status')
        ->expectsOutputToContain('active')
        ->expectsOutputToContain('http://collector.test/api/v1/ingest')
        ->expectsOutputToContain('ng_******oken')
        ->expectsOutputToContain('closed')
        ->expectsOutputToContain('0 batch(es)')
        ->assertExitCode(0);
});

it('explains why the SDK is inactive', function () {
    config(['nightguardian.url' => null]);
    app()->forgetInstance(Client::class);

    $this->artisan('nightguardian:status')
        ->expectsOutputToContain('INACTIVE - NIGHTGUARDIAN_URL is not set.')
        ->assertExitCode(0);
});

it('shows an open breaker, the spool and the last error', function () {
    ngFakeTransport()->script[] = new TransportResult(0, 'curl error 7: refused');
    NightGuardian::message('boom');
    NightGuardian::flush();

    $this->artisan('nightguardian:status')
        ->expectsOutputToContain('OPEN for 60s - curl error 7: refused')
        ->expectsOutputToContain('1 batch(es)')
        ->expectsOutputToContain('Events spooled')
        ->expectsOutputToContain('curl error 7: refused')
        ->assertExitCode(0);
});

// --- nightguardian:flush ------------------------------------------------------------------------------------

it('flushes the spool and says so', function () {
    ngFakeTransport()->script[] = new TransportResult(0, 'down');
    NightGuardian::message('one');
    NightGuardian::flush();

    $this->artisan('nightguardian:flush')
        ->expectsOutputToContain('Sent 1 of 1 spooled batch(es)')
        ->expectsOutputToContain('The spool is empty.')
        ->assertExitCode(0);
});

it('has nothing to say about an empty spool', function () {
    $this->artisan('nightguardian:flush')->expectsOutputToContain('spool is empty')->assertExitCode(0);
});

it('keeps what it could not send', function () {
    ngFakeTransport()->script = [new TransportResult(0, 'down'), new TransportResult(503, 'HTTP 503')];
    NightGuardian::message('one');
    NightGuardian::flush();

    $this->artisan('nightguardian:flush')->expectsOutputToContain('1 batch(es) still waiting')->assertExitCode(0);
});

// --- nightguardian:deploy -----------------------------------------------------------------------------------

it('sends a deploy marker with the given details', function () {
    $this->artisan('nightguardian:deploy', ['--release' => 'v1.4.2', '--commit' => 'abc123def456', '--branch' => 'main', '--deployer' => 'pull.sh', '--url' => 'https://github.com/acme/app/commit/abc'])
        ->expectsOutputToContain('Deploy marker sent for release v1.4.2')
        ->assertExitCode(0);

    $event = ngFakeTransport()->batches[0][0];

    expect($event['type'])->toBe('deploy')
        ->and($event['release'])->toBe('v1.4.2')
        ->and($event['payload'])->toBe(['release' => 'v1.4.2', 'commit' => 'abc123def456', 'branch' => 'main', 'deployer' => 'pull.sh', 'url' => 'https://github.com/acme/app/commit/abc'])
        ->and(ngSchemaErrors($event))->toBe([]);
});

it('defaults the deploy release to the configured one', function () {
    $this->artisan('nightguardian:deploy')->assertExitCode(0);

    expect(ngFakeTransport()->batches[0][0]['payload']['release'])->toBe('test-release');
});

it('keeps the deploy marker in the spool when the collector is down', function () {
    ngFakeTransport()->script[] = new TransportResult(0, 'down');

    $this->artisan('nightguardian:deploy')->expectsOutputToContain('saved in the spool')->assertExitCode(0);
});

it('refuses to deploy when inactive', function () {
    config(['nightguardian.url' => '']);
    app()->forgetInstance(Client::class);

    $this->artisan('nightguardian:deploy')->expectsOutputToContain('inactive')->assertExitCode(1);
});

// --- flush points -------------------------------------------------------------------------------------------

it('flushes when the application terminates (after the HTTP response)', function () {
    Log::error('during the request');

    expect(ngFakeTransport()->batches)->toBe([]);

    app()->terminate();

    expect(ngFakeTransport()->batches)->toHaveCount(1)->and(ngFakeTransport()->batches[0])->toHaveCount(1);
});

it('flushes after a job is processed', function () {
    Log::error('inside a job');

    event(new JobProcessed('database', Mockery::mock(Job::class)));

    expect(ngFakeTransport()->batches)->toHaveCount(1);
});

it('flushes when a console command finishes', function () {
    Log::error('inside a command');

    event(new CommandFinished('some:command', new StringInput(''), new NullOutput, 0));

    expect(ngFakeTransport()->batches)->toHaveCount(1);
});

it('sends all the events of a request in one batch', function () {
    Log::error('one');
    Log::error('two');
    Log::critical('three');

    app()->terminate();

    expect(ngFakeTransport()->batches)->toHaveCount(1)->and(ngFakeTransport()->batches[0])->toHaveCount(3);
});

it('sends nothing when nothing was captured', function () {
    app()->terminate();
    event(new CommandFinished('some:command', new StringInput(''), new NullOutput, 0));

    expect(ngFakeTransport()->batches)->toBe([]);
});

it('does not capture what the transport layer logs while flushing', function () {
    ngFakeTransport()->script[] = function () {
        Log::error('transport noise', ['exception' => new RuntimeException('should not be captured')]);

        return FakeTransport::accepted([]);
    };

    Log::error('real event');
    app()->terminate();

    expect(ngFakeTransport()->events())->toHaveCount(1);
});

it('does not break the application when the collector is down at the end of a request', function () {
    ngFakeTransport()->script[] = new TransportResult(0, 'refused');

    Log::error('lost? no, spooled');

    expect(function () {
        return app()->terminate();
    })->not->toThrow(Throwable::class)
        ->and(app(Dispatcher::class)->spool()->count())->toBe(1);
});
