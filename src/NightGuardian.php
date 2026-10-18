<?php

namespace NightGuardian\Laravel;

use LogicException;
use NightGuardian\Laravel\Capture\RequestStats;
use NightGuardian\Laravel\Event\EventFactory;
use NightGuardian\Laravel\Support\ContextSanitizer;
use NightGuardian\Laravel\Support\Guard;
use NightGuardian\Laravel\Support\ObjectMap;
use NightGuardian\Laravel\Transport\Buffer;
use NightGuardian\Laravel\Transport\Sender;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Main client (facade root).
 *
 * Every public method is safe to call from application code: it never throws, never recurses into
 * itself and does nothing at all when the SDK is inactive.
 */
class NightGuardian
{
    /**
     * @var array<string, mixed>
     */
    private $config;

    /**
     * @var Buffer|null
     */
    private $buffer;

    /**
     * @var EventFactory|null
     */
    private $factory;

    /**
     * @var Sender|null
     */
    private $sender;

    /**
     * @var RequestStats|null
     */
    private $stats;

    public const VERSION = '2.0.0-dev';

    public const SDK_NAME = 'nightguardian-laravel';

    /** PSR-3 levels from least to most severe. */
    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * @var bool|null
     */
    private $active;

    /** @var ObjectMap */
    private $captured;

    /** @var ObjectMap */
    private $references;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config, ?Buffer $buffer = null, ?EventFactory $factory = null, ?Sender $sender = null, ?RequestStats $stats = null)
    {
        $this->config = $config;
        $this->buffer = $buffer;
        $this->factory = $factory;
        $this->sender = $sender;
        $this->stats = $stats;
        $this->captured = new ObjectMap;
        $this->references = new ObjectMap;
    }

    public function isActive(): bool
    {
        return $this->active = $this->active ?? $this->inactiveReason() === null;
    }

    /**
     * Why the SDK is doing nothing, or null when it is active.
     */
    public function inactiveReason(): ?string
    {
        return self::inactiveReasonFor($this->config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function inactiveReasonFor($config): ?string
    {
        try {
            if (! extension_loaded('curl')) {
                return 'The PHP curl extension is not installed.';
            }

            if (! ($config['enabled'] ?? true)) {
                return 'Disabled (NIGHTGUARDIAN_ENABLED=false).';
            }

            $url = $config['url'] ?? null;

            if (! is_string($url) || trim($url) === '') {
                return 'NIGHTGUARDIAN_URL is not set.';
            }

            if (! preg_match('#^https?://[^\s/]+#i', trim($url))) {
                return 'NIGHTGUARDIAN_URL must be an absolute http(s) URL.';
            }

            $token = $config['token'] ?? null;

            if (! is_string($token) || trim($token) === '') {
                return 'NIGHTGUARDIAN_TOKEN is not set.';
            }

            return null;
        } catch (Throwable $e) {
            Guard::record($e);

            return 'Configuration could not be read.';
        }
    }

    /**
     * The collector's ingest endpoint, or null when no URL is configured.
     */
    public function ingestUrl(): ?string
    {
        $url = $this->config['url'] ?? null;

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return rtrim(trim($url), '/').'/api/v1/ingest';
    }

    /**
     * Number of failures swallowed by the SDK itself (shown by `nightguardian:status`).
     */
    public function internalErrors(): int
    {
        return Guard::errors();
    }

    public function countInternalError(): void
    {
        Guard::record(new RuntimeException('internal error'));
    }

    // --- public API ------------------------------------------------------------------------------
    /**
     * Reports a handled exception.
     *
     * @param  array<string, mixed>  $context  Free-form data shown with the event.
     * @param  Throwable  $throwable
     */
    public function report($throwable, $context = []): void
    {
        Guard::run(function () use ($throwable, $context) {
            $this->captureThrowable($throwable, 'error', $context, true, false);
        });
    }

    /**
     * Reports a message as a log event.
     *
     * @param  array<string, mixed>  $context
     * @param  string  $message
     * @param  string  $level
     */
    public function message($message, $level = 'error', $context = []): void
    {
        Guard::run(function () use ($level, $message, $context) {
            $this->captureLog($level, $message, $context);
        });
    }

    /**
     * Sends everything captured so far. Called automatically at the end of requests, jobs and commands.
     */
    public function flush(): void
    {
        Guard::run(function () {
            ($nullsafeVariable1 = $this->stats) ? $nullsafeVariable1->reset() : null;

            if ($this->buffer === null || $this->buffer->isEmpty()) {
                return;
            }

            $events = $this->buffer->drain();

            ($nullsafeVariable2 = $this->sender) ? $nullsafeVariable2->dispatch($events) : null;
        });
    }

    // --- used by the capture listeners ----------------------------------------------------------
    /**
     * @param  array<string, mixed>  $context
     * @param  Throwable  $throwable
     * @param  string  $level
     * @param  bool  $handled
     * @param  bool  $respectIgnoreList
     */
    public function captureThrowable($throwable, $level, $context = [], $handled = false, $respectIgnoreList = true): void
    {
        if (! $this->canCapture() || $this->alreadyCaptured($throwable)) {
            return;
        }

        if ($respectIgnoreList && $this->isIgnored($throwable)) {
            return;
        }

        $options = ['level' => $level, 'handled' => $handled];

        if ($context !== []) {
            $options['context'] = ContextSanitizer::sanitize($context);
        }

        $event = $this->factory()->exception($throwable, $options);

        if (isset($event['reference'], $event['id'])) {
            $this->references[$throwable] = ['reference' => (string) $event['reference'], 'id' => (string) $event['id']];
        }

        $this->push($event);
    }

    /**
     * Reference code and event id of the event captured for this very exception instance, if any.
     * Used by the error page to show the code of the error the user is looking at.
     *
     * @param  Throwable  $throwable
     * @return array{reference: string, id: string}|null
     */
    public function referenceFor($throwable): ?array
    {
        return $this->references[$throwable] ?? null;
    }

    /**
     * @param  array<string, mixed>  $context  PSR-3 context (an `exception` entry is ignored here).
     * @param  string  $level
     * @param  string  $message
     * @param  Throwable|null  $exception
     */
    public function captureLog($level, $message, $context = [], $exception = null): void
    {
        if (! $this->canCapture()) {
            return;
        }

        unset($context['exception']);

        $this->push($this->factory()->log(strtolower($level), $message, ContextSanitizer::sanitize($context), $exception));
    }

    /**
     * @param  array<string, mixed>  $event  A complete protocol event built by the EventFactory.
     */
    public function push($event): void
    {
        ($nullsafeVariable3 = $this->buffer) ? $nullsafeVariable3->add($event) : null;
    }

    public function factory(): EventFactory
    {
        if (! isset($this->factory)) {
            throw new LogicException('The event factory is not available.');
        }

        return $this->factory;
    }

    public function canCapture(): bool
    {
        return $this->buffer !== null && $this->factory !== null && $this->isActive();
    }

    /**
     * Marks the throwable as captured; true when it already was (one event per exception instance).
     *
     * @param  Throwable  $throwable
     */
    public function alreadyCaptured($throwable): bool
    {
        if (isset($this->captured[$throwable])) {
            return true;
        }

        $this->captured[$throwable] = true;

        return false;
    }

    /**
     * @param  string  $level
     */
    public function meetsLogLevel($level): bool
    {
        $threshold = array_search(strtolower((string) ($this->config['log_level'] ?? 'error')), self::LEVELS, true);
        $actual = array_search(strtolower($level), self::LEVELS, true);

        return $actual !== false && $actual >= ($threshold === false ? 4 : $threshold);
    }

    /**
     * @param  Throwable  $throwable
     */
    public function isIgnored($throwable): bool
    {
        if ($throwable instanceof HttpExceptionInterface && $throwable->getStatusCode() < 500) {
            return true;
        }

        $ignored = $this->config['ignore_exceptions'] ?? [];

        foreach (is_array($ignored) ? $ignored : [] as $class) {
            if (is_string($class) && $throwable instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    public function buffer(): ?Buffer
    {
        return $this->buffer;
    }

    public function stats(): ?RequestStats
    {
        return $this->stats;
    }
}
