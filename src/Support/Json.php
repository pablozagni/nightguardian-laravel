<?php

namespace NightGuardian\Laravel\Support;

/**
 * JSON encoding that never throws and never fails on bad UTF-8 or unencodable values.
 */
final class Json
{
    private const FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PARTIAL_OUTPUT_ON_ERROR
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function encode(array $data): string
    {
        $json = json_encode(self::clean($data), self::FLAGS | self::substitute());

        return $json === false ? '{}' : $json;
    }

    /**
     * Lenient encoding of any value (log messages, request bodies); never fails on bad UTF-8.
     *
     * @param  mixed  $value
     */
    public static function partial($value): string
    {
        return (string) json_encode(self::clean($value), JSON_PARTIAL_OUTPUT_ON_ERROR | self::substitute());
    }

    /**
     * JSON_INVALID_UTF8_SUBSTITUTE exists from PHP 7.2; on PHP 7.1 clean() does the substitution.
     */
    private static function substitute(): int
    {
        return defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
    }

    /**
     * Without the flag (PHP 7.1) invalid UTF-8 would turn a whole string into null.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private static function clean($value)
    {
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            return $value;
        }

        if (is_string($value)) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        if (is_array($value)) {
            return array_map([self::class, 'clean'], $value);
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function size(array $data): int
    {
        return strlen(self::encode($data));
    }
}
