<?php

namespace NightGuardian\Laravel\Transport;

/**
 * What the collector (or the network) answered to one batch.
 */
final class TransportResult
{
    /**
     * @var int
     *
     * @readonly
     */
    public $status;

    /**
     * @readonly
     *
     * @var string|null
     */
    public $error;

    /**
     * @readonly
     *
     * @var int|null
     */
    public $retryAfter;

    /**
     * @var list<array<string, mixed>>
     *
     * @readonly
     */
    public $results = [];

    /**
     * @param  int  $status  HTTP status, or 0 when no HTTP answer was received (DNS, connect, timeout, TLS).
     * @param  list<array<string, mixed>>  $results  Per-event results of a 202 answer.
     */
    public function __construct(int $status, ?string $error = null, ?int $retryAfter = null, array $results = [])
    {
        $this->status = $status;
        $this->error = $error;
        $this->retryAfter = $retryAfter;
        $this->results = $results;
    }

    public static function failure(string $error): self
    {
        return new self(0, $error);
    }

    public function isAccepted(): bool
    {
        return $this->status === 202;
    }
}
