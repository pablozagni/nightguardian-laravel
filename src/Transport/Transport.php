<?php

namespace NightGuardian\Laravel\Transport;

/**
 * Sends one batch of events to the collector. Never throws: failures come back as a result.
 */
interface Transport
{
    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function send($events): TransportResult;
}
