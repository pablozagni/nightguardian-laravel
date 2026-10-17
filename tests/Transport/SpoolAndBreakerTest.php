<?php

use NightGuardian\Laravel\Tests\Fakes\FakeClock;
use NightGuardian\Laravel\Transport\CircuitBreaker;
use NightGuardian\Laravel\Transport\Spool;
use NightGuardian\Laravel\Transport\Stats;

function ngEventsOf(int $count, string $prefix = 'e'): array
{
    return array_map(fn (int $i) => ['id' => "{$prefix}{$i}", 'type' => 'log', 'payload' => ['message' => str_repeat('x', 200)]], range(1, $count));
}

function ngSpool(string $dir, ?FakeClock $clock = null, int $maxMb = 10, int $maxDays = 7): Spool
{
    return new Spool($dir.'/spool', $maxMb * 1048576, $maxDays, $clock ?? new FakeClock);
}

// --- spool ------------------------------------------------------------------------------------------------

it('writes batches as gzip files and reads them back, oldest first', function () {
    $clock = new FakeClock;
    $spool = ngSpool($this->storage, $clock);

    expect($spool->write(ngEventsOf(2, 'a')))->toBeTrue();
    $clock->advance(1);
    expect($spool->write(ngEventsOf(1, 'b')))->toBeTrue();

    $files = $spool->files();

    expect($files)->toHaveCount(2)
        ->and($files[0])->toEndWith('.json.gz')
        ->and(file_get_contents($files[0], false, null, 0, 2))->toBe("\x1f\x8b")
        ->and(array_column($spool->read($files[0]), 'id'))->toBe(['a1', 'a2'])
        ->and(array_column($spool->read($files[1]), 'id'))->toBe(['b1'])
        ->and($spool->count())->toBe(2)
        ->and($spool->bytes())->toBeGreaterThan(0)
        ->and($spool->oldest())->toBe($files[0]);
});

it('reports the age of the oldest batch', function () {
    $clock = new FakeClock;
    $spool = ngSpool($this->storage, $clock);

    expect($spool->oldestAge())->toBeNull();

    $spool->write(ngEventsOf(1));
    $clock->advance(125);

    expect($spool->oldestAge())->toBe(125);
});

it('deletes batches older than the maximum age', function () {
    $clock = new FakeClock;
    $spool = ngSpool($this->storage, $clock, maxDays: 7);

    $spool->write(ngEventsOf(1, 'old'));
    $clock->advance(8 * 86400);
    $spool->write(ngEventsOf(1, 'new'));

    expect($spool->count())->toBe(1)
        ->and($spool->read($spool->oldest())[0]['id'])->toBe('new1');
});

it('deletes the oldest batches first when the spool is over its size limit', function () {
    $clock = new FakeClock;
    $spool = new Spool($this->storage.'/spool', 6000, 7, $clock);

    // Incompressible payloads of about 2 KB each.
    for ($i = 1; $i <= 5; $i++) {
        $clock->advance(1);
        $spool->write([['id' => "n{$i}", 'type' => 'log', 'payload' => ['message' => bin2hex(random_bytes(1000))]]]);
    }

    $ids = array_map(fn (string $f) => $spool->read($f)[0]['id'], $spool->files());

    expect($spool->bytes())->toBeLessThanOrEqual(6000)
        ->and($ids)->not->toContain('n1')
        ->and(end($ids))->toBe('n5');
});

it('refuses a single batch bigger than the whole spool', function () {
    $spool = new Spool($this->storage.'/spool', 500, 7, new FakeClock);

    expect($spool->write([['id' => 'big', 'payload' => ['message' => bin2hex(random_bytes(5000))]]]))->toBeFalse()
        ->and($spool->count())->toBe(0);
});

it('drops silently when the storage cannot be written', function () {
    $blocker = tempnam(sys_get_temp_dir(), 'ngfile');
    $spool = new Spool($blocker.'/spool', 1048576, 7, new FakeClock);

    expect($spool->write(ngEventsOf(1)))->toBeFalse()
        ->and($spool->isWritable())->toBeFalse()
        ->and($spool->files())->toBe([])
        ->and($spool->count())->toBe(0);

    unlink($blocker);
});

it('treats corrupt files as unreadable', function () {
    $spool = ngSpool($this->storage);
    $spool->write(ngEventsOf(1));
    $path = $spool->oldest();
    file_put_contents($path, 'this is not gzip');

    expect($spool->read($path))->toBeNull();

    file_put_contents($path, gzencode('{"not":"events"}'));

    expect($spool->read($path))->toBeNull()->and($spool->read($this->storage.'/missing.json.gz'))->toBeNull();
});

it('removes stale temporary files', function () {
    $spool = ngSpool($this->storage);
    $spool->write(ngEventsOf(1));
    $stale = $this->storage.'/spool/leftover.tmp';
    file_put_contents($stale, 'x');
    touch($stale, time() - 7200);

    $spool->prune();

    expect(file_exists($stale))->toBeFalse();
});

// --- circuit breaker --------------------------------------------------------------------------------------

function ngBreaker(string $dir, FakeClock $clock): CircuitBreaker
{
    return new CircuitBreaker($dir.'/breaker.json', $clock);
}

it('starts closed and allows requests', function () {
    $breaker = ngBreaker($this->storage, new FakeClock);

    expect($breaker->allows())->toBeTrue()->and($breaker->status()['state'])->toBe('closed');
});

it('opens for 60 seconds after a failure, then lets one probe through', function () {
    $clock = new FakeClock;
    $breaker = ngBreaker($this->storage, $clock);

    $breaker->recordFailure('timeout');

    expect($breaker->allows())->toBeFalse()
        ->and($breaker->status())->toMatchArray(['state' => 'open', 'seconds_left' => 60, 'reason' => 'timeout']);

    $clock->advance(59);
    expect($breaker->allows())->toBeFalse();

    $clock->advance(2);
    expect($breaker->status()['state'])->toBe('half-open')
        ->and($breaker->allows())->toBeTrue()
        ->and($breaker->allows())->toBeFalse();
});

it('closes when the probe succeeds and removes its state file', function () {
    $clock = new FakeClock;
    $breaker = ngBreaker($this->storage, $clock);

    $breaker->recordFailure('down');
    expect(file_exists($this->storage.'/breaker.json'))->toBeTrue();

    $clock->advance(61);
    $breaker->allows();
    $breaker->recordSuccess();

    expect($breaker->allows())->toBeTrue()
        ->and($breaker->status()['state'])->toBe('closed')
        ->and(file_exists($this->storage.'/breaker.json'))->toBeFalse();
});

it('doubles the pause on consecutive failures up to 15 minutes', function () {
    $clock = new FakeClock;
    $breaker = ngBreaker($this->storage, $clock);
    $pauses = [];

    for ($i = 0; $i < 7; $i++) {
        $breaker->recordFailure('down');
        $pauses[] = $breaker->status()['seconds_left'];
        $clock->advance($breaker->status()['seconds_left'] + 1);
        $breaker->allows();
    }

    expect($pauses)->toBe([60, 120, 240, 480, 900, 900, 900]);
});

it('a failed probe reopens the breaker with a longer pause', function () {
    $clock = new FakeClock;
    $breaker = ngBreaker($this->storage, $clock);

    $breaker->recordFailure('down');
    $clock->advance(61);
    $breaker->allows();
    $breaker->recordFailure('still down');

    expect($breaker->status())->toMatchArray(['state' => 'open', 'seconds_left' => 120, 'failures' => 2]);
});

it('can be tripped for a fixed time without escalating', function () {
    $breaker = ngBreaker($this->storage, new FakeClock);

    $breaker->trip(3600, 'token rejected');
    $breaker->trip(3600, 'token rejected');

    expect($breaker->status())->toMatchArray(['state' => 'open', 'seconds_left' => 3600, 'failures' => 0, 'reason' => 'token rejected']);
});

it('shares its state between processes through the state file', function () {
    $clock = new FakeClock;
    ngBreaker($this->storage, $clock)->recordFailure('down');

    $other = ngBreaker($this->storage, $clock);

    expect($other->allows())->toBeFalse()->and($other->status()['state'])->toBe('open');
});

it('writes the state file only on transitions', function () {
    $clock = new FakeClock;
    $breaker = ngBreaker($this->storage, $clock);

    $breaker->allows();
    $breaker->recordSuccess();

    expect(file_exists($this->storage.'/breaker.json'))->toBeFalse();

    $breaker->recordFailure('down');
    $mtime = filemtime($this->storage.'/breaker.json');
    clearstatcache();
    $breaker->allows();
    $breaker->allows();

    expect(filemtime($this->storage.'/breaker.json'))->toBe($mtime);
});

it('ignores a corrupt state file and works without a writable directory', function () {
    mkdir($this->storage, 0777, true);
    file_put_contents($this->storage.'/breaker.json', '{not json');

    expect(ngBreaker($this->storage, new FakeClock)->allows())->toBeTrue();

    $blocker = tempnam(sys_get_temp_dir(), 'ngfile');
    $breaker = new CircuitBreaker($blocker.'/x/breaker.json', new FakeClock);
    $breaker->recordFailure('down');

    // The state cannot be saved, but it still protects the rest of this process.
    expect($breaker->allows())->toBeFalse();

    unlink($blocker);
});

// --- stats ------------------------------------------------------------------------------------------------

it('accumulates counters across saves and keeps values', function () {
    $stats = new Stats($this->storage.'/stats.json');
    $stats->increment('sent_events', 3);
    $stats->set('last_error', 'boom');
    $stats->save();

    $again = new Stats($this->storage.'/stats.json');
    $again->increment('sent_events', 2);

    expect($again->get('sent_events'))->toBe(5)
        ->and($again->get('last_error'))->toBe('boom')
        ->and($again->get('missing', 'default'))->toBe('default');

    $again->save();

    expect((new Stats($this->storage.'/stats.json'))->get('sent_events'))->toBe(5);
});

it('does not touch the disk when nothing changed', function () {
    (new Stats($this->storage.'/stats.json'))->save();

    expect(file_exists($this->storage.'/stats.json'))->toBeFalse();
});
