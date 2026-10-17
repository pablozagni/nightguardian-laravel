<?php

namespace NightGuardian\Laravel\Tests\Fakes;

use NightGuardian\Laravel\Transport\Transport;
use NightGuardian\Laravel\Transport\TransportResult;

/**
 * Records the batches it is asked to send and answers from a queue of scripted results
 * (default: 202 Accepted with one "accepted" result per event).
 */
class FakeTransport implements Transport
{
    /** @var list<list<array<string, mixed>>> */
    public array $batches = [];

    /** @var list<TransportResult|callable> */
    public array $script = [];

    public function send(array $events): TransportResult
    {
        $this->batches[] = $events;

        $next = array_shift($this->script);

        if (is_callable($next)) {
            return $next($events);
        }

        return $next ?? self::accepted($events);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public static function accepted(array $events): TransportResult
    {
        return new TransportResult(202, null, null, array_map(
            fn (array $e) => ['id' => $e['id'] ?? null, 'status' => 'accepted', 'issue' => '01JTESTISSUE'],
            $events,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        return array_merge(...($this->batches ?: [[]]));
    }
}
