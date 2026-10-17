<?php

namespace NightGuardian\Laravel\Support;

/**
 * Time source, replaceable in tests so breaker and spool timings need no sleeping.
 */
interface Clock
{
    /** Unix time in seconds, with fractions. */
    public function now(): float;
}
