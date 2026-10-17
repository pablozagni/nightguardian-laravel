<?php

namespace NightGuardian\Laravel\Capture;

/**
 * Per-request counters shared by the slow request middleware and the query listener.
 */
class RequestStats
{
    public const MAX_SLOW_QUERIES = 5;

    private int $queries = 0;

    private int $slowQueries = 0;

    public function countQuery(): void
    {
        $this->queries++;
    }

    public function queries(): int
    {
        return $this->queries;
    }

    /**
     * True while this request may still report another slow query.
     */
    public function claimSlowQuery(): bool
    {
        return ++$this->slowQueries <= self::MAX_SLOW_QUERIES;
    }

    public function reset(): void
    {
        $this->queries = 0;
        $this->slowQueries = 0;
    }
}
