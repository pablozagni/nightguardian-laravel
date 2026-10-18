<?php

namespace NightGuardian\Laravel\Support;

use Throwable;

/**
 * Creates the SDK's runtime directory (storage/nightguardian) and, the first time it does, drops a .gitignore in it so
 * the spool, lock, statistics and release cache can never be committed by accident.
 */
final class StorageDirectory
{
    private const GITIGNORE = "*\n!.gitignore\n";

    /**
     * Makes sure $directory exists. Returns false when it cannot be created (unwritable storage).
     */
    public static function ensure(string $directory): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        // The topmost missing ancestor is the folder this call creates first (storage/nightguardian when nothing existed yet).
        $top = $directory;

        while (! is_dir($parent = dirname($top)) && $parent !== $top) {
            $top = $parent;
        }

        if (! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return false;
        }

        try {
            if (is_dir($top) && ! file_exists($top.'/.gitignore')) {
                @file_put_contents($top.'/.gitignore', self::GITIGNORE);
            }
        } catch (Throwable $e) {
            // The ignore file is a safeguard only.
        }

        return true;
    }
}
