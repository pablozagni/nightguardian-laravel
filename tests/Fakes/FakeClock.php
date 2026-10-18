<?php

namespace NightGuardian\Laravel\Tests\Fakes;

use NightGuardian\Laravel\Support\Clock;

class FakeClock implements Clock
{
    /**
     * @var float
     */
    private $now = 1800000000.0;

    public function __construct(float $now = 1800000000.0)
    {
        $this->now = $now;
    }

    public function now(): float
    {
        return $this->now;
    }

    /**
     * @param  float  $seconds
     */
    public function advance($seconds): void
    {
        $this->now += $seconds;
    }
}
