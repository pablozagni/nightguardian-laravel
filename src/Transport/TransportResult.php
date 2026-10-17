<?php

namespace NightGuardian\Laravel\Transport;

/**
 * What the collector (or the network) answered to one batch.
 */
final class TransportResult
{
    /**
     * @param  int  $status  HTTP status, or 0 when no HTTP answer was received (DNS, connect, timeout, TLS).
     * @param  list<array<string, mixed>>  $results  Per-event results of a 202 answer.
     */
    public function __construct(
        public readonly int $status,
        public readonly ?string $error = null,
        public readonly ?int $retryAfter = null,
        public readonly array $results = [],
    ) {}

    public static function failure(string $error): self
    {
        return new self(0, $error);
    }

    public function isAccepted(): bool
    {
        return $this->status === 202;
    }
}
