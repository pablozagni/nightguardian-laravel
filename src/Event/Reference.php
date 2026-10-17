<?php

namespace NightGuardian\Laravel\Event;

/**
 * Human-friendly code shown to end users, format NG-XXXX-XXXX (Crockford base32: no I, L, O, U).
 */
final class Reference
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const PATTERN = '/^NG-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/';

    public static function generate(): string
    {
        $code = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, 31)];
        }

        return 'NG-'.substr($code, 0, 4).'-'.substr($code, 4);
    }

    public static function isValid(string $reference): bool
    {
        return preg_match(self::PATTERN, $reference) === 1;
    }
}
