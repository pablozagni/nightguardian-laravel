<?php

namespace NightGuardian\Laravel\Transport;

/**
 * In-memory events of the current request/job/command, sent in one batch when it ends.
 * Bounded by the protocol's 100 events per request; extra events are counted and dropped.
 */
class Buffer
{
    public const MAX_EVENTS = 100;

    /** @var list<array<string, mixed>> */
    private array $events = [];

    private int $dropped = 0;

    private ?string $lastReference = null;

    private ?string $lastEventId = null;

    /**
     * @param  array<string, mixed>  $event
     */
    public function add(array $event): bool
    {
        if (isset($event['reference']) && is_string($event['reference']) && isset($event['id']) && is_string($event['id'])) {
            $this->lastReference = $event['reference'];
            $this->lastEventId = $event['id'];
        }

        if (count($this->events) >= self::MAX_EVENTS) {
            $this->dropped++;

            return false;
        }

        $this->events[] = $event;

        return true;
    }

    /**
     * Changes an event that is still buffered (it is not sent until the request ends).
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    public function amend(string $id, callable $change): bool
    {
        foreach ($this->events as $index => $event) {
            if (($event['id'] ?? null) === $id) {
                $this->events[$index] = $change($event);

                return true;
            }
        }

        return false;
    }

    /**
     * Removes and returns everything buffered.
     *
     * @return list<array<string, mixed>>
     */
    public function drain(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->events;
    }

    public function count(): int
    {
        return count($this->events);
    }

    public function isEmpty(): bool
    {
        return $this->events === [];
    }

    /**
     * Events dropped because the buffer was full, since the last call.
     */
    public function takeDropped(): int
    {
        $dropped = $this->dropped;
        $this->dropped = 0;

        return $dropped;
    }

    /**
     * Reference and id of the last captured event that has a reference (used by the error page).
     *
     * @return array{reference: string, id: string}|null
     */
    public function lastReference(): ?array
    {
        return $this->lastReference !== null && $this->lastEventId !== null
            ? ['reference' => $this->lastReference, 'id' => $this->lastEventId]
            : null;
    }

    public function clearReference(): void
    {
        $this->lastReference = null;
        $this->lastEventId = null;
    }
}
