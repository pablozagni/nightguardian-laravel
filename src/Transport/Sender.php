<?php

namespace NightGuardian\Laravel\Transport;

/**
 * Receives the events of a request/job/command when it ends and gets them to the collector
 * (or to the spool). Implementations must never throw.
 */
interface Sender
{
    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function dispatch(array $events): void;
}
