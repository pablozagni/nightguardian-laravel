<?php

namespace NightGuardian\Laravel\Event;

use Throwable;

/**
 * Turns throwables into the protocol v1 `exception` object.
 *
 * Frames are ordered like the collector expects: the most recent call first (the place where the
 * exception was thrown), then its callers. Paths inside the project are relative to the base path.
 */
class StackTrace
{
    public const MAX_FRAMES = 100;

    public const MAX_FRAMES_WITH_CONTEXT = 30;

    public const MAX_MESSAGE = 8192;

    public const MAX_CHAIN = 10;

    private const CONTEXT_LINES = 5;

    private const MAX_LINE_LENGTH = 250;

    private const MAX_SOURCE_BYTES = 1_000_000;

    private string $basePath;

    /** @var array<string, list<string>|null> */
    private array $sourceCache = [];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim(self::normalize($basePath), '/');
    }

    /**
     * The throwable and its `previous` chain, ordered outermost to innermost.
     *
     * @return list<array<string, mixed>>
     */
    public function chain(Throwable $throwable): array
    {
        $chain = [];
        $seen = [];

        for ($current = $throwable; $current !== null && count($chain) < self::MAX_CHAIN; $current = $current->getPrevious()) {
            $id = spl_object_id($current);

            if (isset($seen[$id])) {
                break;
            }

            $seen[$id] = true;
            $chain[] = $this->describe($current);
        }

        return $chain;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(Throwable $throwable): array
    {
        $exception = [
            'class' => mb_substr($throwable::class, 0, 255),
            'message' => mb_substr($throwable->getMessage(), 0, self::MAX_MESSAGE),
        ];

        $code = $throwable->getCode();

        if ($code !== 0 && $code !== '' && $code !== null) {
            $exception['code'] = (string) $code;
        }

        $exception['frames'] = $this->frames($throwable);

        return $exception;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function frames(Throwable $throwable): array
    {
        $trace = $throwable->getTrace();
        $location = ['file' => $throwable->getFile(), 'line' => $throwable->getLine()];
        $frames = [];

        // Trace entry N says "function N was called from file:line"; the code running inside
        // function N is at the location recorded by entry N-1 (or the throw site for N = 0).
        foreach ($trace as $entry) {
            $frames[] = $this->frame($location['file'] ?? null, $location['line'] ?? null, $entry['function'] ?? null, $entry['class'] ?? null);
            $location = ['file' => $entry['file'] ?? null, 'line' => $entry['line'] ?? null];
        }

        $frames[] = $this->frame($location['file'] ?? null, $location['line'] ?? null, null, null);

        return $this->limit($frames);
    }

    /**
     * Relative path for files inside the project, absolute (forward slashes) otherwise.
     */
    public function relativePath(string $file): string
    {
        $file = self::normalize($file);

        if ($this->isInside($file)) {
            return ltrim(substr($file, strlen($this->basePath)), '/');
        }

        return $file;
    }

    public function isInApp(string $file): bool
    {
        $file = self::normalize($file);

        if (! $this->isInside($file)) {
            return false;
        }

        $relative = ltrim(substr($file, strlen($this->basePath)), '/');

        return ! str_starts_with($relative, 'vendor/')
            && ! str_contains($relative, '/vendor/')
            && ! str_starts_with($relative, 'node_modules/')
            && ! str_contains($relative, '/node_modules/');
    }

    /**
     * @return array<string, mixed>
     */
    private function frame(?string $file, ?int $line, ?string $function, ?string $class): array
    {
        $frame = [];

        if ($file === null || $file === '') {
            $frame['file'] = '[internal]';
            $frame['line'] = null;
            $inApp = false;
        } else {
            $frame['file'] = $this->relativePath($file);
            $frame['line'] = $line !== null && $line > 0 ? $line : null;
            $inApp = $this->isInApp($file);
        }

        if ($function !== null && $function !== '') {
            $frame['function'] = $function;
        }

        if ($class !== null && $class !== '') {
            $frame['class'] = $class;
        }

        $frame['in_app'] = $inApp;

        if ($inApp && $frame['line'] !== null) {
            $frame['_source'] = $file;
        }

        return $frame;
    }

    /**
     * Keeps the first 50 and last 50 frames when there are more than 100, then attaches the code context
     * to the first 30 in-app frames.
     *
     * @param  list<array<string, mixed>>  $frames
     * @return list<array<string, mixed>>
     */
    private function limit(array $frames): array
    {
        if (count($frames) > self::MAX_FRAMES) {
            $half = intdiv(self::MAX_FRAMES, 2);
            $frames = [...array_slice($frames, 0, $half), ...array_slice($frames, -$half)];
        }

        $withContext = 0;

        foreach ($frames as $index => $frame) {
            $source = $frame['_source'] ?? null;
            unset($frames[$index]['_source']);

            if ($source === null || $withContext >= self::MAX_FRAMES_WITH_CONTEXT) {
                continue;
            }

            $context = $this->codeContext($source, (int) $frame['line']);

            if ($context !== null) {
                $frames[$index]['context'] = $context;
                $withContext++;
            }
        }

        return array_values($frames);
    }

    /**
     * @return array{pre: list<string>, line: string, post: list<string>}|null
     */
    private function codeContext(string $file, int $line): ?array
    {
        $lines = $this->sourceLines($file);

        if ($lines === null || $line < 1 || $line > count($lines)) {
            return null;
        }

        $index = $line - 1;
        $clip = static fn (string $text): string => mb_substr(rtrim($text, "\r\n"), 0, self::MAX_LINE_LENGTH);

        return [
            'pre' => array_map($clip, array_slice($lines, max(0, $index - self::CONTEXT_LINES), min(self::CONTEXT_LINES, $index))),
            'line' => $clip($lines[$index]),
            'post' => array_map($clip, array_slice($lines, $index + 1, self::CONTEXT_LINES)),
        ];
    }

    /**
     * @return list<string>|null
     */
    private function sourceLines(string $file): ?array
    {
        if (array_key_exists($file, $this->sourceCache)) {
            return $this->sourceCache[$file];
        }

        $lines = null;

        try {
            $size = is_file($file) && is_readable($file) ? @filesize($file) : false;

            if ($size !== false && $size <= self::MAX_SOURCE_BYTES) {
                $read = @file($file, FILE_IGNORE_NEW_LINES);
                $lines = $read === false ? null : $read;
            }
        } catch (Throwable $e) {
            $lines = null;
        }

        if (count($this->sourceCache) >= 50) {
            $this->sourceCache = [];
        }

        return $this->sourceCache[$file] = $lines;
    }

    private function isInside(string $normalizedFile): bool
    {
        if ($this->basePath === '') {
            return false;
        }

        $prefix = $this->basePath.'/';

        if (DIRECTORY_SEPARATOR === '\\') {
            return stripos($normalizedFile, $prefix) === 0;
        }

        return str_starts_with($normalizedFile, $prefix);
    }

    private static function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
