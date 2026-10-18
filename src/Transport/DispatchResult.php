<?php

namespace NightGuardian\Laravel\Transport;

/**
 * What happened to the events of one dispatch, for commands that want to tell the user.
 */
final class DispatchResult
{
    /** Events the collector accepted (or already had).
     * @var int */
    public $delivered = 0;

    /** Events the collector refused as invalid; they are never retried.
     * @var int */
    public $rejected = 0;

    /** Events saved in the spool for later.
     * @var int */
    public $spooled = 0;

    /** Events that could not be delivered nor spooled.
     * @var int */
    public $dropped = 0;

    /** Spooled batches sent as a side effect (drain).
     * @var int */
    public $drained = 0;

    /** HTTP status of the last attempt, 0 for network failures, null when no attempt was made.
     * @var int|null */
    public $status;

    /**
     * @var string|null
     */
    public $error;

    /**
     * @var bool
     */
    public $circuitOpen = false;

    /** @var list<array<string, mixed>> Per-event results of accepted batches (id, status, issue, errors). */
    public $results = [];

    public function delivered(): bool
    {
        return $this->delivered > 0 && $this->spooled === 0 && $this->dropped === 0;
    }
}
