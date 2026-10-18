<?php

namespace NightGuardian\Laravel\Privacy;

/**
 * Removes sensitive data before an event leaves the server (collector/docs/security.md).
 *
 * Keys matching the denylist (case-insensitive substring) have their value replaced, and sensitive
 * value patterns (card numbers, bearer tokens, JWTs, private key blocks) are replaced inside every string.
 */
class Scrubber
{
    public const FILTERED = '[Filtered]';

    /** @var list<string> */
    public const DEFAULT_KEYS = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'api_key', 'apikey', 'access_key', 'private_key',
        'authorization', 'auth', 'cookie', 'session', 'csrf', 'xsrf', 'credit_card', 'card_number', 'cvv', 'iban',
    ];

    /** Headers that are never sent, even when the app puts them in the allowlist. */
    private const FORBIDDEN_HEADERS = ['cookie', 'set-cookie', 'authorization', 'proxy-authorization', 'x-csrf-token', 'x-xsrf-token'];

    /** @var list<string> */
    private $keys;

    /**
     * @param  list<string>  $extraKeys  Additional denylist keys (config `scrub_keys`).
     */
    public function __construct(array $extraKeys = [])
    {
        $this->keys = array_values(array_unique(array_filter(array_map(static function ($key) {
            return strtolower(trim((string) $key));
        }, array_merge(self::DEFAULT_KEYS, $extraKeys)), static function (string $key) {
            return $key !== '';
        })));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function scrub($data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $result[$key] = self::FILTERED;
            } elseif (is_array($value)) {
                $result[$key] = $this->scrub($value);
            } elseif (is_string($value)) {
                $result[$key] = $this->scrubString($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param  string  $value
     */
    public function scrubString($value): string
    {
        // Private key blocks (also an unterminated block: everything after the header goes).
        $value = preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s', self::FILTERED, $value) ?? $value;

        // Bearer tokens.
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer '.self::FILTERED, $value) ?? $value;

        // JWTs.
        $value = preg_replace('/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/', self::FILTERED, $value) ?? $value;

        // Card numbers: 13-19 digits, optionally separated by spaces or dashes, Luhn-valid.
        return preg_replace_callback('/(?<![\d-])\d(?:[ -]?\d){12,18}(?![\d-])/', function (array $m) {
            return $this->passesLuhn(preg_replace('/\D/', '', $m[0]) ?? '') ? self::FILTERED : $m[0];
        }, $value) ?? $value;
    }

    /**
     * Scrubs a URL: drops the fragment and any user:password@, filters sensitive query parameters
     * and runs the value patterns over the rest.
     *
     * @param  string  $url
     */
    public function scrubUrl($url): string
    {
        $url = explode('#', $url, 2)[0];
        [$base, $query] = array_pad(explode('?', $url, 2), 2, null);

        $base = preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@\s]*@#i', '$1', $base) ?? $base;
        $result = $this->scrubString($base);

        if ($query === null || $query === '') {
            return $query === null ? $result : $result.'?';
        }

        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, null);

            if ($this->isSensitiveKey(urldecode($name))) {
                $pairs[] = $name.'='.self::FILTERED;
            } elseif ($value === null) {
                $pairs[] = $this->scrubString($name);
            } else {
                $pairs[] = $name.'='.$this->scrubString($value);
            }
        }

        return $result.'?'.implode('&', $pairs);
    }

    /**
     * Keeps only allowlisted headers (lower-cased names), never cookies or authorization, scrubbed.
     *
     * @param  array<string, string|array<int, string|null>|null>  $headers
     * @param  list<string>  $allowlist
     * @return array<string, string>
     */
    public function scrubHeaders($headers, $allowlist): array
    {
        $allowed = array_diff(array_map('strtolower', $allowlist), self::FORBIDDEN_HEADERS);
        $result = [];

        foreach ($headers as $name => $value) {
            $name = strtolower((string) $name);

            if (! in_array($name, $allowed, true)) {
                continue;
            }

            $value = is_array($value) ? implode(', ', array_filter($value, 'is_string')) : (string) $value;
            $result[$name] = $name === 'referer' ? $this->scrubUrl($value) : $this->scrubString($value);
        }

        return $result;
    }

    /**
     * @param  string  $key
     */
    public function isSensitiveKey($key): bool
    {
        $key = strtolower($key);

        foreach ($this->keys as $needle) {
            if (strpos($key, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function passesLuhn(string $digits): bool
    {
        $length = strlen($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $digits[$length - 1 - $i];

            if ($i % 2 === 1) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}
