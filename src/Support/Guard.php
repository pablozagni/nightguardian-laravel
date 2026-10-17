<?php

namespace NightGuardian\Laravel\Support;

use Throwable;

/**
 * Safety net around every SDK entry point.
 *
 * - Re-entrancy: while SDK code is running, nested calls (for example a log line written by the SDK
 *   itself) are skipped, so the SDK can never capture its own activity or recurse.
 * - Containment: any throwable is swallowed and counted. `nightguardian:status` shows the counter.
 */
final class Guard
{
    private static bool $active = false;

    private static int $errors = 0;

    private static ?string $lastError = null;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T  $default  Returned when the guard is busy or the callback throws.
     * @return T
     */
    public static function run(callable $callback, mixed $default = null): mixed
    {
        if (self::$active) {
            return $default;
        }

        self::$active = true;

        try {
            return $callback();
        } catch (Throwable $e) {
            self::$errors++;
            self::$lastError = mb_substr($e::class.': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')', 0, 300);

            return $default;
        } finally {
            self::$active = false;
        }
    }

    /**
     * Like run(), but for SDK-internal composition: it works inside an already guarded section (no skip),
     * while still marking the SDK as busy when it is the outermost call.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T  $default
     * @return T
     */
    public static function contain(callable $callback, mixed $default = null): mixed
    {
        $owner = ! self::$active;
        self::$active = true;

        try {
            return $callback();
        } catch (Throwable $e) {
            self::record($e);

            return $default;
        } finally {
            if ($owner) {
                self::$active = false;
            }
        }
    }

    public static function busy(): bool
    {
        return self::$active;
    }

    public static function record(Throwable $e): void
    {
        self::$errors++;
        self::$lastError = mb_substr($e::class.': '.$e->getMessage(), 0, 300);
    }

    public static function errors(): int
    {
        return self::$errors;
    }

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * Returns and clears the counters (they are moved to the persisted stats on flush).
     *
     * @return array{count: int, last: ?string}
     */
    public static function take(): array
    {
        $taken = ['count' => self::$errors, 'last' => self::$lastError];
        self::$errors = 0;
        self::$lastError = null;

        return $taken;
    }

    public static function reset(): void
    {
        self::$active = false;
        self::$errors = 0;
        self::$lastError = null;
    }
}
