<?php

namespace NightGuardian\Laravel\Transport;

/**
 * What happened to the events of one dispatch, for commands that want to tell the user.
 */
final class DispatchResult
{
    /** Events the collector accepted (or already had). */
    public int $delivered = 0;

    /** Events the collector refused as invalid; they are never retried. */
    public int $rejected = 0;

    /** Events saved in the spool for later. */
    public int $spooled = 0;

    /** Events that could not be delivered nor spooled. */
    public int $dropped = 0;

    /** Spooled batches sent as a side effect (drain). */
    public int $drained = 0;

    /** HTTP status of the last attempt, 0 for network failures, null when no attempt was made. */
    public ?int $status = null;

    public ?string $error = null;

    public bool $circuitOpen = false;

    /** @var list<array<string, mixed>> Per-event results of accepted batches (id, status, issue, errors). */
    public array $results = [];

    public function delivered(): bool
    {
        return $this->delivered > 0 && $this->spooled === 0 && $this->dropped === 0;
    }
}
