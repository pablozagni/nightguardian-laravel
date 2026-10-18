<?php

namespace NightGuardian\Laravel\Transport;

use NightGuardian\Laravel\Support\Clock;
use NightGuardian\Laravel\Support\Json;
use Throwable;

/**
 * Bounded on-disk queue of batches the collector could not take: `<dir>/<ms timestamp>-<random>.json.gz`.
 *
 * Bounds: `maxBytes` and `maxAgeDays`, oldest deleted first. When the directory cannot be written the
 * batch is dropped (the caller counts it). No constructor does any I/O.
 */
class Spool
{
    /**
     * @var string
     */
    private $directory;

    /**
     * @var int
     */
    private $maxBytes;

    /**
     * @var int
     */
    private $maxAgeDays;

    /**
     * @var Clock
     */
    private $clock;

    private const EXTENSION = '.json.gz';

    public function __construct(string $directory, int $maxBytes, int $maxAgeDays, Clock $clock)
    {
        $this->directory = $directory;
        $this->maxBytes = $maxBytes;
        $this->maxAgeDays = $maxAgeDays;
        $this->clock = $clock;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function write($events): bool
    {
        if ($events === []) {
            return true;
        }

        try {
            if (! is_dir($this->directory) && ! @mkdir($this->directory, 0775, true) && ! is_dir($this->directory)) {
                return false;
            }

            $payload = gzencode(Json::encode(['v' => 1, 'events' => $events]), 6);

            if ($payload === false || ($this->maxBytes > 0 && strlen($payload) > $this->maxBytes)) {
                return false;
            }

            $name = sprintf('%013d-%s', (int) ($this->clock->now() * 1000), bin2hex(random_bytes(6)));
            $temp = $this->directory.'/'.$name.'.tmp';

            if (@file_put_contents($temp, $payload, LOCK_EX) === false) {
                @unlink($temp);

                return false;
            }

            if (! @rename($temp, $this->directory.'/'.$name.self::EXTENSION)) {
                @unlink($temp);

                return false;
            }

            $this->prune();

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Paths of the spooled batches, oldest first.
     *
     * @return list<string>
     */
    public function files(): array
    {
        $files = @glob($this->directory.'/*'.self::EXTENSION);

        if ($files === false) {
            return [];
        }

        sort($files, SORT_STRING);

        return $files;
    }

    public function oldest(): ?string
    {
        return $this->files()[0] ?? null;
    }

    /**
     * @param  string  $path
     * @return list<array<string, mixed>>|null null when the file is unreadable or corrupt
     */
    public function read($path): ?array
    {
        try {
            $raw = @file_get_contents($path);
            $json = $raw === false ? false : @gzdecode($raw);
            $data = $json === false ? null : json_decode($json, true);

            if (! is_array($data) || ! isset($data['events']) || ! is_array($data['events'])) {
                return null;
            }

            return array_values(array_filter($data['events'], 'is_array'));
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @param  string  $path
     */
    public function delete($path): void
    {
        @unlink($path);
    }

    public function count(): int
    {
        return count($this->files());
    }

    public function bytes(): int
    {
        $total = 0;

        foreach ($this->files() as $file) {
            $total += (int) @filesize($file);
        }

        return $total;
    }

    /**
     * Age in seconds of the oldest batch, or null when the spool is empty.
     */
    public function oldestAge(): ?int
    {
        $oldest = $this->oldest();

        if ($oldest === null) {
            return null;
        }

        return max(0, (int) ($this->clock->now() - $this->timestampOf($oldest)));
    }

    public function isWritable(): bool
    {
        if (is_dir($this->directory)) {
            return is_writable($this->directory);
        }

        $parent = dirname($this->directory);

        while (! is_dir($parent) && $parent !== dirname($parent)) {
            // A regular file in the way means the directory can never be created.
            if (file_exists($parent)) {
                return false;
            }

            $parent = dirname($parent);
        }

        return is_writable($parent);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Enforces the age and size bounds, deleting the oldest batches first.
     */
    public function prune(): void
    {
        $now = $this->clock->now();
        $maxAge = $this->maxAgeDays * 86400;
        $files = $this->files();

        if ($maxAge > 0) {
            foreach ($files as $index => $file) {
                if ($now - $this->timestampOf($file) > $maxAge) {
                    @unlink($file);
                    unset($files[$index]);
                }
            }
        }

        if ($this->maxBytes <= 0) {
            return;
        }

        $sizes = [];
        $total = 0;

        foreach ($files as $file) {
            $sizes[$file] = (int) @filesize($file);
            $total += $sizes[$file];
        }

        foreach ($files as $file) {
            if ($total <= $this->maxBytes) {
                break;
            }

            @unlink($file);
            $total -= $sizes[$file];
        }

        // Leftovers of interrupted writes.
        foreach (@glob($this->directory.'/*.tmp') ?: [] as $temp) {
            if ($now - (int) @filemtime($temp) > 3600) {
                @unlink($temp);
            }
        }
    }

    private function timestampOf(string $path): float
    {
        return ((int) substr(basename($path), 0, 13)) / 1000;
    }
}
