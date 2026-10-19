<?php

namespace NightGuardian\Laravel\Support;

use RuntimeException;

/**
 * Updates KEY=value lines of a .env file in place: every other byte of the file stays as it was, the file is
 * rewritten (not replaced) so its permissions and owner are untouched, and keys that do not exist yet are appended.
 */
final class EnvFile
{
    /**
     * @param  string  $path
     * @param  array<string, string>  $values  Environment variable => value.
     * @return bool Whether the file changed.
     */
    public static function set($path, array $values)
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("The environment file {$path} does not exist or is not readable.");
        }

        $original = (string) file_get_contents($path);
        $contents = $original;
        $eol = strpos($contents, "\r\n") !== false ? "\r\n" : "\n";

        foreach ($values as $key => $value) {
            $line = $key.'='.self::format($value);
            $pattern = '/^[ \t]*'.preg_quote($key, '/').'[ \t]*=.*?(?=\r?$)/m';

            if (preg_match($pattern, $contents) === 1) {
                $contents = (string) preg_replace_callback($pattern, static function () use ($line) {
                    return $line;
                }, $contents, 1);

                continue;
            }

            if ($contents !== '' && substr($contents, -1) !== "\n") {
                $contents .= $eol;
            }

            $contents .= $line.$eol;
        }

        if ($contents === $original) {
            return false;
        }

        if (! is_writable($path) || @file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException("The environment file {$path} is not writable.");
        }

        return true;
    }

    /**
     * Quotes the value only when .env syntax requires it.
     *
     * @param  string  $value
     * @return string
     */
    private static function format($value)
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/@%+,=-]+$/', $value) === 1) {
            return $value;
        }

        return '"'.addcslashes($value, '"\\').'"';
    }
}
