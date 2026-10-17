<?php

namespace NightGuardian\Laravel\Tests\Fakes;

use NightGuardian\Laravel\Support\Clock;

class FakeClock implements Clock
{
    public function __construct(private float $now = 1_800_000_000.0) {}

    public function now(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
