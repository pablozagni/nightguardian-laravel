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
        $json = json_encode($data, self::FLAGS);

        return $json === false ? '{}' : $json;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function size(array $data): int
    {
        return strlen(self::encode($data));
    }
}
