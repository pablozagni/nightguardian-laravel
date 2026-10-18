<?php

namespace NightGuardian\Laravel\Transport;

use NightGuardian\Laravel\Support\Clock;
use NightGuardian\Laravel\Support\Guard;
use Throwable;

/**
 * Decides what happens to a batch of events: send now, spool, or drop. Never throws.
 *
 *   breaker open?  -> spool
 *   202            -> done (and maybe drain one spooled batch)
 *   413            -> split the batch and retry the halves; a single oversized event is dropped
 *   400            -> drop the batch (malformed, retrying cannot help)
 *   401 / 403      -> spool, open the breaker for 1 h
 *   429            -> spool, open the breaker for Retry-After
 *   5xx / network  -> spool, open the breaker with exponential backoff
 */
class Dispatcher implements Sender
{
    /**
     * @var Transport
     */
    private $transport;

    /**
     * @var Spool
     */
    private $spool;

    /**
     * @var CircuitBreaker
     */
    private $breaker;

    /**
     * @var Stats
     */
    private $stats;

    /**
     * @var Clock
     */
    private $clock;

    /**
     * @var string
     */
    private $lockPath;

    /** Minimum time between two automatic drains of the spool. */
    public const DRAIN_INTERVAL_SECONDS = 30;

    private const TOKEN_REJECTED_SECONDS = 3600;

    public function __construct(Transport $transport, Spool $spool, CircuitBreaker $breaker, Stats $stats, Clock $clock, string $lockPath)
    {
        $this->transport = $transport;
        $this->spool = $spool;
        $this->breaker = $breaker;
        $this->stats = $stats;
        $this->clock = $clock;
        $this->lockPath = $lockPath;
    }

    /**
     * @param  mixed[]  $events
     */
    public function dispatch($events): void
    {
        $this->deliver($events);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  bool  $force  Ignore an open breaker (explicit user actions such as `nightguardian:test`).
     */
    public function deliver($events, $force = false): DispatchResult
    {
        $result = new DispatchResult;

        Guard::contain(function () use ($events, $force, $result) {
            if ($events === []) {
                return;
            }

            $unsent = $this->attempt($events, $result, $force);
            $this->spoolUnsent($unsent, $result);

            if ($unsent === [] && $result->delivered > 0) {
                $this->drainOne($result);
            }
        });

        $this->finish();

        return $result;
    }

    /**
     * Sends spooled batches, oldest first. `$all` keeps going until the spool is empty or something fails.
     *
     * @param  bool  $all
     * @param  bool  $force
     */
    public function drain($all = false, $force = false): DispatchResult
    {
        $result = new DispatchResult;

        Guard::contain(function () use ($all, $force, $result) {
            $lock = $this->lock();

            if ($lock === null) {
                return;
            }

            try {
                do {
                    $before = $result->drained;
                    $this->drainBatch($result, $force);
                } while ($all && $result->drained > $before);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        });

        $this->finish();

        return $result;
    }

    public function spool(): Spool
    {
        return $this->spool;
    }

    public function breaker(): CircuitBreaker
    {
        return $this->breaker;
    }

    public function stats(): Stats
    {
        return $this->stats;
    }

    // --- internals -------------------------------------------------------------------------------

    /**
     * Tries to deliver the events and returns those that still need to be retried later.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function attempt(array $events, DispatchResult $result, bool $force = false): array
    {
        if (! $force && ! $this->breaker->allows()) {
            $result->circuitOpen = true;

            return $events;
        }

        try {
            $response = $this->transport->send($events);
        } catch (Throwable $e) {
            // Transports must not throw, but a broken one may never lose events or break the app.
            $response = TransportResult::failure('transport error: '.$e->getMessage());
        }

        $result->status = $response->status;
        $result->error = $response->error;

        if ($response->status === 202) {
            $this->breaker->recordSuccess();
            $this->accepted($events, $response, $result);

            return [];
        }

        $this->stats->increment('failed_batches');
        $this->stats->set('last_failure_at', $this->clock->now());
        $this->stats->set('last_error', $response->error ?? ($response->status === 0 ? 'no answer from the collector' : "HTTP {$response->status}"));

        switch (true) {
            case $response->status === 413:
                return $this->split($events, $result, $force);

            case $response->status === 400:
                $this->drop(count($events), $result);

                return [];

            case $response->status === 401 || $response->status === 403:
                $this->breaker->trip(self::TOKEN_REJECTED_SECONDS, 'the collector rejected the token (HTTP '.$response->status.')');

                return $events;

            case $response->status === 429:
                $this->breaker->trip(max(1, min($response->retryAfter ?? CircuitBreaker::BASE_SECONDS, 3600)), 'rate limited by the collector (HTTP 429)');

                return $events;

            default:
                $this->breaker->recordFailure($response->status === 0 ? (string) $response->error : "HTTP {$response->status}");

                return $events;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function accepted(array $events, TransportResult $response, DispatchResult $result): void
    {
        $delivered = 0;
        $rejected = 0;

        foreach ($response->results as $item) {
            $result->results[] = $item;

            if (($item['status'] ?? null) === 'rejected') {
                $rejected++;
                $errors = $item['errors'] ?? [];
                $this->stats->set('last_error', 'event rejected: '.(is_array($errors) ? implode('; ', array_map('strval', $errors)) : 'invalid'));
            } else {
                $delivered++;
            }
        }

        if ($response->results === []) {
            $delivered = count($events);
        }

        $result->delivered += $delivered;
        $result->rejected += $rejected;
        $this->stats->increment('sent_events', $delivered);
        $this->stats->increment('rejected_events', $rejected);
        $this->stats->set('last_success_at', $this->clock->now());
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function split(array $events, DispatchResult $result, bool $force): array
    {
        if (count($events) === 1) {
            $this->drop(1, $result);

            return [];
        }

        [$first, $second] = array_chunk($events, (int) ceil(count($events) / 2));

        $unsent = $this->attempt($first, $result, $force);

        // If the first half hit a problem the breaker may be open now; the second half then simply waits too.
        return array_merge($unsent, $this->attempt($second, $result, $force));
    }

    /**
     * @param  list<array<string, mixed>>  $unsent
     */
    private function spoolUnsent(array $unsent, DispatchResult $result): void
    {
        if ($unsent === []) {
            return;
        }

        if ($this->spool->write($unsent)) {
            $result->spooled += count($unsent);
            $this->stats->increment('spooled_events', count($unsent));

            return;
        }

        $this->drop(count($unsent), $result);
    }

    private function drop(int $count, DispatchResult $result): void
    {
        $result->dropped += $count;
        $this->stats->increment('dropped_events', $count);
    }

    /**
     * One spooled batch after a successful send, at most every 30 s, never in parallel.
     */
    private function drainOne(DispatchResult $result): void
    {
        $last = (float) $this->stats->get('last_drain_at', 0);

        if ($this->spool->oldest() === null || $this->clock->now() - $last < self::DRAIN_INTERVAL_SECONDS) {
            return;
        }

        $lock = $this->lock();

        if ($lock === null) {
            return;
        }

        try {
            $this->stats->set('last_drain_at', $this->clock->now());
            $this->drainBatch($result, false);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function drainBatch(DispatchResult $result, bool $force): void
    {
        $path = $this->spool->oldest();

        if ($path === null) {
            return;
        }

        $events = $this->spool->read($path);

        if ($events === null || $events === []) {
            $this->spool->delete($path);
            $result->drained++;

            return;
        }

        $unsent = $this->attempt($events, $result, $force);

        if ($unsent === []) {
            $this->spool->delete($path);
            $result->drained++;

            return;
        }

        // Part of the batch got through (a split after a 413): keep only what is still pending.
        if (count($unsent) < count($events) && $this->spool->write($unsent)) {
            $this->spool->delete($path);
        }
    }

    /**
     * @return resource|null
     */
    private function lock()
    {
        try {
            $directory = dirname($this->lockPath);

            if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                return null;
            }

            $handle = @fopen($this->lockPath, 'c');

            if ($handle === false) {
                return null;
            }

            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);

                return null;
            }

            return $handle;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function finish(): void
    {
        Guard::contain(function () {
            $internal = Guard::take();
            $this->stats->increment('internal_errors', $internal['count']);

            if ($internal['last'] !== null) {
                $this->stats->set('last_internal_error', $internal['last']);
            }

            $this->stats->save();
        });
    }
}
