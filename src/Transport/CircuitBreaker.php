<?php

namespace NightGuardian\Laravel\Transport;

use NightGuardian\Laravel\Support\Clock;
use Throwable;

/**
 * Stops network attempts after a failure: open for 60 s, doubling up to 15 min; after the pause one
 * probe is allowed and the breaker closes on success. The state lives in a small JSON file, is read
 * once per process and only written on transitions.
 */
class CircuitBreaker
{
    /**
     * @var string
     */
    private $path;

    /**
     * @var Clock
     */
    private $clock;

    public const BASE_SECONDS = 60;

    public const MAX_SECONDS = 900;

    /** How long a probing request keeps the others out. */
    private const PROBE_SECONDS = 10;

    /** @var array{open_until: float, failures: int, probing_until: float, reason: ?string, opened_at: float}|null */
    private $state;

    public function __construct(string $path, Clock $clock)
    {
        $this->path = $path;
        $this->clock = $clock;
    }

    /**
     * True when a network attempt may be made now.
     */
    public function allows(): bool
    {
        $state = $this->state();

        if ($state['open_until'] <= 0.0) {
            return true;
        }

        $now = $this->clock->now();

        if ($now < $state['open_until'] || $now < $state['probing_until']) {
            return false;
        }

        // The pause is over: let exactly one request probe the collector.
        $this->state['probing_until'] = $now + self::PROBE_SECONDS;
        $this->save();

        return true;
    }

    public function recordSuccess(): void
    {
        $state = $this->state();

        if ($state['open_until'] <= 0.0 && $state['failures'] === 0) {
            return;
        }

        $this->state = $this->fresh();
        @unlink($this->path);
    }

    /**
     * A failure the collector may recover from: back off 60 s, doubling per consecutive failure, up to 15 min.
     *
     * @param  string  $reason
     */
    public function recordFailure($reason): void
    {
        $failures = $this->state()['failures'] + 1;
        $seconds = (int) min(self::MAX_SECONDS, self::BASE_SECONDS * (2 ** min($failures - 1, 10)));

        $this->open($seconds, $reason, $failures);
    }

    /**
     * Opens for a fixed time (rate limit, rejected token) without escalating the backoff.
     *
     * @param  int  $seconds
     * @param  string  $reason
     */
    public function trip($seconds, $reason): void
    {
        $this->open($seconds, $reason, $this->state()['failures']);
    }

    /**
     * @return array{state: string, open_until: ?float, seconds_left: int, failures: int, reason: ?string}
     */
    public function status(): array
    {
        $state = $this->state();
        $now = $this->clock->now();

        if ($state['open_until'] <= 0.0) {
            return ['state' => 'closed', 'open_until' => null, 'seconds_left' => 0, 'failures' => $state['failures'], 'reason' => null];
        }

        return [
            'state' => $now < $state['open_until'] ? 'open' : 'half-open',
            'open_until' => $state['open_until'],
            'seconds_left' => max(0, (int) ceil($state['open_until'] - $now)),
            'failures' => $state['failures'],
            'reason' => $state['reason'],
        ];
    }

    private function open(int $seconds, string $reason, int $failures): void
    {
        $now = $this->clock->now();

        $this->state = [
            'open_until' => $now + max(1, $seconds),
            'failures' => $failures,
            'probing_until' => 0.0,
            'reason' => mb_substr($reason, 0, 200),
            'opened_at' => $now,
        ];

        $this->save();
    }

    /**
     * @return array{open_until: float, failures: int, probing_until: float, reason: ?string, opened_at: float}
     */
    private function state(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }

        $state = $this->fresh();

        try {
            $raw = @file_get_contents($this->path);
            $data = $raw === false ? null : json_decode($raw, true);

            if (is_array($data)) {
                $state = [
                    'open_until' => (float) ($data['open_until'] ?? 0),
                    'failures' => (int) ($data['failures'] ?? 0),
                    'probing_until' => (float) ($data['probing_until'] ?? 0),
                    'reason' => is_string($data['reason'] ?? null) ? $data['reason'] : null,
                    'opened_at' => (float) ($data['opened_at'] ?? 0),
                ];
            }
        } catch (Throwable $e) {
            // A broken state file means "closed".
        }

        return $this->state = $state;
    }

    /**
     * @return array{open_until: float, failures: int, probing_until: float, reason: ?string, opened_at: float}
     */
    private function fresh(): array
    {
        return ['open_until' => 0.0, 'failures' => 0, 'probing_until' => 0.0, 'reason' => null, 'opened_at' => 0.0];
    }

    private function save(): void
    {
        try {
            $directory = dirname($this->path);

            if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                return;
            }

            @file_put_contents($this->path, json_encode($this->state), LOCK_EX);
        } catch (Throwable $e) {
            // Unwritable storage: the breaker still works for the rest of this process.
        }
    }
}
