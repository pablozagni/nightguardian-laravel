<?php

namespace NightGuardian\Laravel\Transport;

use Throwable;

/**
 * Small persisted counters for `nightguardian:status`. Increments are collected in memory and merged
 * into `stats.json` once per `save()`, so a request touches the file at most once.
 */
class Stats
{
    /**
     * @var string
     */
    private $path;

    public const COUNTERS = ['sent_events', 'rejected_events', 'dropped_events', 'spooled_events', 'failed_batches', 'internal_errors'];

    /** @var array<string, int> */
    private $increments = [];

    /** @var array<string, mixed> */
    private $values = [];

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * @param  string  $counter
     * @param  int  $by
     */
    public function increment($counter, $by = 1): void
    {
        if ($by > 0) {
            $this->increments[$counter] = ($this->increments[$counter] ?? 0) + $by;
        }
    }

    /**
     * @param  string  $key
     * @param  mixed  $value
     */
    public function set($key, $value): void
    {
        $this->values[$key] = $value;
    }

    /**
     * Persisted values with this process' pending changes applied.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $data = $this->read();

        foreach ($this->increments as $counter => $by) {
            $data[$counter] = (int) ($data[$counter] ?? 0) + $by;
        }

        return $this->values + $data;
    }

    /**
     * @param  string  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        return $this->all()[$key] ?? $default;
    }

    public function save(): void
    {
        if ($this->increments === [] && $this->values === []) {
            return;
        }

        try {
            $directory = dirname($this->path);

            if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                return;
            }

            $data = $this->all();

            if (@file_put_contents($this->path, json_encode($data), LOCK_EX) !== false) {
                $this->increments = [];
                $this->values = [];
            }
        } catch (Throwable $e) {
            // Statistics are best effort.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        try {
            $raw = @file_get_contents($this->path);
            $data = $raw === false ? null : json_decode($raw, true);

            return is_array($data) ? $data : [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
