<?php

namespace NightGuardian\Laravel\Support;

use Closure;
use JsonSerializable;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Makes arbitrary PHP values (log contexts, user supplied context) safe to JSON-encode:
 * no objects, resources or closures, bounded depth, bounded width, bounded strings.
 */
final class ContextSanitizer
{
    private const MAX_DEPTH = 5;

    private const MAX_ITEMS = 50;

    private const MAX_STRING = 1000;

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    public static function sanitize(array $context): array
    {
        $result = self::value($context, 0);

        return is_array($result) ? $result : [];
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private static function value($value, int $depth)
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : (string) $value;
        }

        if (is_string($value)) {
            return mb_strlen($value) > self::MAX_STRING ? mb_substr($value, 0, self::MAX_STRING).'…' : $value;
        }

        if (is_resource($value)) {
            return '[resource '.get_resource_type($value).']';
        }

        if ($depth >= self::MAX_DEPTH) {
            return is_array($value) ? '[array]' : '[object '.get_class($value).']';
        }

        if (is_array($value)) {
            $result = [];
            $count = 0;

            foreach ($value as $key => $item) {
                if (++$count > self::MAX_ITEMS) {
                    $result['…'] = (count($value) - self::MAX_ITEMS).' more';
                    break;
                }

                $result[$key] = self::value($item, $depth + 1);
            }

            return $result;
        }

        if ($value instanceof Closure) {
            return '[closure]';
        }

        if ($value instanceof Throwable) {
            return ['class' => get_class($value), 'message' => self::value($value->getMessage(), $depth + 1)];
        }

        if ($value instanceof UnitEnum) {
            return $value instanceof \BackedEnum ? $value->value : $value->name;
        }

        if ($value instanceof Stringable) {
            return self::value((string) $value, $depth + 1);
        }

        if ($value instanceof JsonSerializable) {
            try {
                return self::value($value->jsonSerialize(), $depth + 1);
            } catch (Throwable $e) {
                return '[object '.get_class($value).']';
            }
        }

        return '[object '.get_class($value).']';
    }
}
