<?php

namespace NightGuardian\Laravel\Support;

final class SystemClock implements Clock
{
    public function now(): float
    {
        return microtime(true);
    }
}
