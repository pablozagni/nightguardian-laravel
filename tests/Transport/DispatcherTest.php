<?php

use NightGuardian\Laravel\Support\Clock;
use NightGuardian\Laravel\Support\Guard;
use NightGuardian\Laravel\Tests\Fakes\FakeClock;
use NightGuardian\Laravel\Tests\Fakes\FakeTransport;
use NightGuardian\Laravel\Transport\CircuitBreaker;
use NightGuardian\Laravel\Transport\Dispatcher;
use NightGuardian\Laravel\Transport\Spool;
use NightGuardian\Laravel\Transport\Stats;
use NightGuardian\Laravel\Transport\Transport;
use NightGuardian\Laravel\Transport\TransportResult;

function ngDispatcher(): Dispatcher
{
    return app(Dispatcher::class);
}

function ngTransport(): FakeTransport
{
    return app(Transport::class);
}

function ngClock(): FakeClock
{
    return app(Clock::class);
}

function ngBatch(int $count = 1, string $prefix = 'e'): array
{
    return array_map(fn (int $i) => ['id' => "{$prefix}{$i}", 'type' => 'log', 'level' => 'error', 'payload' => ['message' => "event {$i}"]], range(1, $count));
}

function ngFail(int $status, ?string $error = null, ?int $retryAfter = null): TransportResult
{
    return new TransportResult($status, $error ?? ($status === 0 ? 'could not connect' : "HTTP {$status}"), $retryAfter);
}

// --- the happy path ---------------------------------------------------------------------------------------

it('sends a batch and does not spool it when the collector accepts it', function () {
    $result = ngDispatcher()->deliver(ngBatch(3));

    expect($result->delivered)->toBe(3)
        ->and($result->spooled)->toBe(0)
        ->and($result->status)->toBe(202)
        ->and($result->results[0]['issue'])->toBe('01JTESTISSUE')
        ->and(ngTransport()->batches)->toHaveCount(1)
        ->and(ngDispatcher()->spool()->count())->toBe(0)
        ->and(ngDispatcher()->stats()->get('sent_events'))->toBe(3)
        ->and(ngDispatcher()->stats()->get('last_success_at'))->toEqual(ngClock()->now());
});

it('does nothing for an empty batch', function () {
    ngDispatcher()->deliver([]);

    expect(ngTransport()->batches)->toBe([]);
});

it('counts rejected events and never retries them', function () {
    ngTransport()->script[] = new TransportResult(202, null, null, [
        ['id' => 'e1', 'status' => 'accepted'],
        ['id' => 'e2', 'status' => 'duplicate'],
        ['id' => 'e3', 'status' => 'rejected', 'errors' => ['payload.exceptions: required']],
    ]);

    $result = ngDispatcher()->deliver(ngBatch(3));

    expect($result->delivered)->toBe(2)
        ->and($result->rejected)->toBe(1)
        ->and(ngDispatcher()->spool()->count())->toBe(0)
        ->and(ngDispatcher()->stats()->get('rejected_events'))->toBe(1)
        ->and(ngDispatcher()->stats()->get('last_error'))->toContain('payload.exceptions: required');
});

// --- failure modes ----------------------------------------------------------------------------------------

it('spools the batch and opens the breaker when the collector cannot be reached', function () {
    ngTransport()->script[] = ngFail(0, 'curl error 7: Failed to connect');

    $result = ngDispatcher()->deliver(ngBatch(2));

    expect($result->delivered)->toBe(0)
        ->and($result->spooled)->toBe(2)
        ->and($result->status)->toBe(0)
        ->and(ngDispatcher()->spool()->count())->toBe(1)
        ->and(ngDispatcher()->breaker()->status()['state'])->toBe('open')
        ->and(ngDispatcher()->stats()->get('last_error'))->toContain('Failed to connect')
        ->and(ngDispatcher()->stats()->get('failed_batches'))->toBe(1);
});

it('does not touch the network while the breaker is open, it only spools', function () {
    ngTransport()->script[] = ngFail(0);
    ngDispatcher()->deliver(ngBatch(1));
    ngDispatcher()->deliver(ngBatch(1, 'b'));
    ngDispatcher()->deliver(ngBatch(1, 'c'));

    expect(ngTransport()->batches)->toHaveCount(1)
        ->and(ngDispatcher()->spool()->count())->toBe(3);
});

it('tries again after the pause and drains one spooled batch when the probe works', function () {
    ngTransport()->script[] = ngFail(503);
    ngDispatcher()->deliver(ngBatch(2, 'old'));

    ngClock()->advance(61);
    $result = ngDispatcher()->deliver(ngBatch(1, 'new'));

    expect($result->delivered)->toBe(3)
        ->and($result->drained)->toBe(1)
        ->and(array_column(ngTransport()->batches[2], 'id'))->toBe(['old1', 'old2'])
        ->and(ngDispatcher()->spool()->count())->toBe(0)
        ->and(ngDispatcher()->breaker()->status()['state'])->toBe('closed');
});

it('drains at most one spooled batch per delivery and at most every 30 seconds', function () {
    ngTransport()->script[] = ngFail(500);
    ngDispatcher()->deliver(ngBatch(1, 'a'));
    ngClock()->advance(61);
    ngDispatcher()->breaker()->allows();
    ngDispatcher()->breaker()->recordSuccess();

    // Two more batches waiting.
    ngDispatcher()->spool()->write(ngBatch(1, 'b'));
    ngDispatcher()->spool()->write(ngBatch(1, 'c'));
    expect(ngDispatcher()->spool()->count())->toBe(3);

    ngDispatcher()->deliver(ngBatch(1, 'now1'));
    expect(ngDispatcher()->spool()->count())->toBe(2);

    ngClock()->advance(10);
    ngDispatcher()->deliver(ngBatch(1, 'now2'));
    expect(ngDispatcher()->spool()->count())->toBe(2);

    ngClock()->advance(25);
    ngDispatcher()->deliver(ngBatch(1, 'now3'));
    expect(ngDispatcher()->spool()->count())->toBe(1);
});

it('spools on server errors', function (int $status) {
    ngTransport()->script[] = ngFail($status);

    $result = ngDispatcher()->deliver(ngBatch());

    expect($result->spooled)->toBe(1)
        ->and(ngDispatcher()->breaker()->status())->toMatchArray(['state' => 'open', 'seconds_left' => 60]);
})->with([500, 502, 503, 504, 404, 302]);

it('respects Retry-After on 429 and keeps the batch', function () {
    ngTransport()->script[] = ngFail(429, null, 120);

    $result = ngDispatcher()->deliver(ngBatch());

    expect($result->spooled)->toBe(1)
        ->and(ngDispatcher()->breaker()->status())->toMatchArray(['state' => 'open', 'seconds_left' => 120]);
});

it('uses a one minute pause on 429 without Retry-After and caps absurd values', function () {
    ngTransport()->script[] = ngFail(429);
    ngDispatcher()->deliver(ngBatch());

    expect(ngDispatcher()->breaker()->status()['seconds_left'])->toBe(60);

    ngClock()->advance(61);
    ngTransport()->script[] = ngFail(429, null, 999999);
    ngDispatcher()->deliver(ngBatch());

    expect(ngDispatcher()->breaker()->status()['seconds_left'])->toBe(3600);
});

it('stops sending for one hour when the token is rejected, but keeps spooling', function (int $status) {
    ngTransport()->script[] = ngFail($status);

    $result = ngDispatcher()->deliver(ngBatch());

    expect($result->spooled)->toBe(1)
        ->and($result->status)->toBe($status)
        ->and(ngDispatcher()->breaker()->status())->toMatchArray(['state' => 'open', 'seconds_left' => 3600])
        ->and(ngDispatcher()->breaker()->status()['reason'])->toContain('rejected the token');

    ngDispatcher()->deliver(ngBatch(1, 'later'));

    expect(ngTransport()->batches)->toHaveCount(1)->and(ngDispatcher()->spool()->count())->toBe(2);
})->with([401, 403]);

it('drops a malformed batch on 400 instead of retrying it', function () {
    ngTransport()->script[] = ngFail(400);

    $result = ngDispatcher()->deliver(ngBatch(2));

    expect($result->dropped)->toBe(2)
        ->and($result->spooled)->toBe(0)
        ->and(ngDispatcher()->spool()->count())->toBe(0)
        ->and(ngDispatcher()->breaker()->status()['state'])->toBe('closed')
        ->and(ngDispatcher()->stats()->get('dropped_events'))->toBe(2);
});

it('splits a batch the collector finds too large and delivers both halves', function () {
    ngTransport()->script = [ngFail(413)];

    $result = ngDispatcher()->deliver(ngBatch(5));

    expect($result->delivered)->toBe(5)
        ->and(ngTransport()->batches)->toHaveCount(3)
        ->and(count(ngTransport()->batches[1]) + count(ngTransport()->batches[2]))->toBe(5)
        ->and(ngDispatcher()->spool()->count())->toBe(0);
});

it('drops a single event that is too large', function () {
    ngTransport()->script = [ngFail(413)];

    $result = ngDispatcher()->deliver(ngBatch(1));

    expect($result->dropped)->toBe(1)->and(ngDispatcher()->spool()->count())->toBe(0);
});

it('splits repeatedly until only the oversized event is dropped', function () {
    // 3 events: the whole batch and the half that contains the big event (e1,e2) are too large.
    ngTransport()->script = [
        fn () => ngFail(413),
        fn () => ngFail(413),
        fn () => ngFail(413),      // e1 alone is too large
        fn ($events) => FakeTransport::accepted($events),   // e2
        fn ($events) => FakeTransport::accepted($events),   // e3
    ];

    $result = ngDispatcher()->deliver(ngBatch(3));

    expect($result->dropped)->toBe(1)->and($result->delivered)->toBe(2);
});

it('keeps the second half of a split for later when the first half hits an outage', function () {
    ngTransport()->script = [ngFail(413), ngFail(503)];

    $result = ngDispatcher()->deliver(ngBatch(4));

    expect($result->delivered)->toBe(0)
        ->and($result->spooled)->toBe(4)
        ->and(ngTransport()->batches)->toHaveCount(2)
        ->and(ngDispatcher()->breaker()->status()['state'])->toBe('open');
});

it('never loses events when the transport itself throws', function () {
    ngTransport()->script[] = function () {
        throw new RuntimeException('transport exploded');
    };

    $result = ngDispatcher()->deliver(ngBatch(2));

    expect($result->spooled)->toBe(2)
        ->and(ngDispatcher()->stats()->get('last_error'))->toContain('transport exploded');
});

it('drops events and counts them when the spool cannot be written', function () {
    $blocker = tempnam(sys_get_temp_dir(), 'ngfile');
    $clock = ngClock();

    $dispatcher = new Dispatcher(
        ngTransport(),
        new Spool($blocker.'/spool', 1048576, 7, $clock),
        new CircuitBreaker($blocker.'/breaker.json', $clock),
        new Stats($blocker.'/stats.json'),
        $clock,
        $blocker.'/drain.lock',
    );

    ngTransport()->script[] = ngFail(0);
    $result = $dispatcher->deliver(ngBatch(3));

    unlink($blocker);

    expect($result->dropped)->toBe(3)
        ->and($result->spooled)->toBe(0)
        ->and($dispatcher->stats()->get('dropped_events'))->toBe(3);
});

// --- draining -----------------------------------------------------------------------------------------------

it('drains the whole spool oldest first on demand, even with the breaker open', function () {
    ngTransport()->script[] = ngFail(0);
    ngDispatcher()->deliver(ngBatch(1, 'a'));
    ngClock()->advance(1);
    ngDispatcher()->deliver(ngBatch(1, 'b'));
    ngClock()->advance(1);
    ngDispatcher()->deliver(ngBatch(1, 'c'));

    expect(ngDispatcher()->breaker()->status()['state'])->toBe('open');

    $result = ngDispatcher()->drain(all: true, force: true);

    expect($result->drained)->toBe(3)
        ->and(array_column(array_slice(ngTransport()->batches, 1), 0))->toBe([ngBatch(1, 'a')[0], ngBatch(1, 'b')[0], ngBatch(1, 'c')[0]])
        ->and(ngDispatcher()->spool()->count())->toBe(0)
        ->and(ngDispatcher()->breaker()->status()['state'])->toBe('closed');
});

it('does not drain through an open breaker unless forced', function () {
    ngTransport()->script[] = ngFail(0);
    ngDispatcher()->deliver(ngBatch(1));

    $result = ngDispatcher()->drain(all: true);

    expect($result->drained)->toBe(0)->and($result->circuitOpen)->toBeTrue()->and(ngTransport()->batches)->toHaveCount(1);
});

it('stops draining at the first failure and keeps the rest', function () {
    ngTransport()->script[] = ngFail(0);
    ngDispatcher()->deliver(ngBatch(1, 'a'));
    ngDispatcher()->spool()->write(ngBatch(1, 'b'));

    ngTransport()->script = [FakeTransport::accepted(ngBatch(1, 'a')), ngFail(503)];
    $result = ngDispatcher()->drain(all: true, force: true);

    expect($result->drained)->toBe(1)->and(ngDispatcher()->spool()->count())->toBe(1);
});

it('removes corrupt spool files while draining', function () {
    ngDispatcher()->spool()->write(ngBatch(1));
    file_put_contents(ngDispatcher()->spool()->oldest(), 'garbage');

    $result = ngDispatcher()->drain(all: true, force: true);

    expect($result->drained)->toBe(1)->and(ngDispatcher()->spool()->count())->toBe(0)->and(ngTransport()->batches)->toBe([]);
});

it('keeps only the undelivered part of a spooled batch that had to be split', function () {
    ngDispatcher()->spool()->write(ngBatch(4, 'x'));

    // Too large -> split; first half accepted, second half unavailable.
    ngTransport()->script = [ngFail(413), FakeTransport::accepted(ngBatch(2)), ngFail(503)];
    ngDispatcher()->drain(force: true);

    $remaining = ngDispatcher()->spool()->read(ngDispatcher()->spool()->oldest());

    expect(ngDispatcher()->spool()->count())->toBe(1)
        ->and(array_column($remaining, 'id'))->toBe(['x3', 'x4']);
});

it('never drains in parallel: a held lock skips the drain', function () {
    ngDispatcher()->spool()->write(ngBatch(1));

    $lock = fopen($this->storage.'/drain.lock', 'c');
    flock($lock, LOCK_EX | LOCK_NB);

    $result = ngDispatcher()->drain(all: true, force: true);

    flock($lock, LOCK_UN);
    fclose($lock);

    expect($result->drained)->toBe(0)->and(ngDispatcher()->spool()->count())->toBe(1);

    expect(ngDispatcher()->drain(force: true)->drained)->toBe(1);
});

it('moves SDK internal errors into the persisted stats', function () {
    Guard::run(function () {
        throw new RuntimeException('earlier problem');
    });

    ngDispatcher()->deliver(ngBatch());

    expect(ngDispatcher()->stats()->get('internal_errors'))->toBe(1)
        ->and(ngDispatcher()->stats()->get('last_internal_error'))->toContain('earlier problem')
        ->and(Guard::errors())->toBe(0);
});
