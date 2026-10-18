<?php

namespace NightGuardian\Laravel\Tests\Fakes;

use NightGuardian\Laravel\Transport\Sender;

/**
 * Collects the batches handed to the transport layer.
 */
class ArraySender implements Sender
{
    /** @var list<list<array<string, mixed>>> */
    public $batches = [];

    /**
     * @param  mixed[]  $events
     */
    public function dispatch($events): void
    {
        $this->batches[] = $events;
    }
}
