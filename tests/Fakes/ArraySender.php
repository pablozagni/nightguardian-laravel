<?php

namespace NightGuardian\Laravel\Tests\Fakes;

use NightGuardian\Laravel\Transport\Sender;

/**
 * Collects the batches handed to the transport layer.
 */
class ArraySender implements Sender
{
    /** @var list<list<array<string, mixed>>> */
    public array $batches = [];

    public function dispatch(array $events): void
    {
        $this->batches[] = $events;
    }
}
