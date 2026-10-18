<?php

namespace NightGuardian\Laravel;

use LogicException;
use NightGuardian\Laravel\Capture\RequestStats;
use NightGuardian\Laravel\Event\EventFactory;
use NightGuardian\Laravel\Support\ContextSanitizer;
use NightGuardian\Laravel\Support\Guard;
use NightGuardian\Laravel\Transport\Buffer;
use NightGuardian\Laravel\Transport\Sender;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use WeakMap;

/**
 * Main client (facade root).
 *
 * Every public method is safe to call from application code: it never throws, never recurses into
 * itself and does nothing at all when the SDK is inactive.
 */
class NightGuardian
{
    public const VERSION = '2.0.2';

    public const SDK_NAME = 'nightguardian-laravel';

    /** PSR-3 levels from least to most severe. */
    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    private ?bool $active = null;

    /** @var WeakMap<Throwable, true> */
    private WeakMap $captured;

    /** @var WeakMap<Throwable, array{reference: string, id: string}> */
    private WeakMap $references;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private array $config,
        private ?Buffer $buffer = null,
        private ?EventFactory $factory = null,
        private ?Sender $sender = null,
        private ?RequestStats $stats = null,
    ) {
        $this->captured = new WeakMap;
        $this->references = new WeakMap;
    }

    public function isActive(): bool
    {
        return $this->active ??= $this->inactiveReason() === null;
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
    public static function inactiveReasonFor(array $config): ?string
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
     */
    public function report(Throwable $throwable, array $context = []): void
    {
        Guard::run(fn () => $this->captureThrowable($throwable, 'error', $context, handled: true, respectIgnoreList: false));
    }

    /**
     * Reports a message as a log event.
     *
     * @param  array<string, mixed>  $context
     */
    public function message(string $message, string $level = 'error', array $context = []): void
    {
        Guard::run(fn () => $this->captureLog($level, $message, $context));
    }

    /**
     * Sends everything captured so far. Called automatically at the end of requests, jobs and commands.
     */
    public function flush(): void
    {
        Guard::run(function () {
            $this->stats?->reset();

            if ($this->buffer === null || $this->buffer->isEmpty()) {
                return;
            }

            $events = $this->buffer->drain();

            $this->sender?->dispatch($events);
        });
    }

    // --- used by the capture listeners ----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $context
     */
    public function captureThrowable(Throwable $throwable, string $level, array $context = [], bool $handled = false, bool $respectIgnoreList = true): void
    {
        if (! $this->canCapture() || $this->alreadyCaptured($throwable)) {
            return;
        }

        if ($respectIgnoreList && $this->isIgnored($throwable)) {
            return;
        }

        $options = ['level' => $level, 'handled' => $handled];

        // An exception nobody caught ends the request with an error response: HTTP exceptions carry their code,
        // anything else becomes a 500. The error page decorator later replaces it with the real response status.
        // (Ignored when there is no request, e.g. in a console.)
        if (! $handled) {
            $options['status_code'] = $throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : 500;
        }

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
     * Records the status of the response that was actually sent for an exception, on its event if still buffered.
     */
    public function recordResponseStatus(Throwable $throwable, int $status): void
    {
        // contain(), not run(): this is called from inside the exception handler decorator's own guarded section.
        Guard::contain(function () use ($throwable, $status) {
            $captured = $this->references[$throwable] ?? null;

            if ($captured === null || $this->buffer === null) {
                return;
            }

            $this->buffer->amend($captured['id'], function (array $event) use ($status) {
                if (isset($event['request']) && is_array($event['request'])) {
                    $event['request']['status_code'] = $status;
                }

                return $event;
            });
        });
    }

    /**
     * Reference code and event id of the event captured for this very exception instance, if any.
     * Used by the error page to show the code of the error the user is looking at.
     *
     * @return array{reference: string, id: string}|null
     */
    public function referenceFor(Throwable $throwable): ?array
    {
        return $this->references[$throwable] ?? null;
    }

    /**
     * @param  array<string, mixed>  $context  PSR-3 context (an `exception` entry is ignored here).
     */
    public function captureLog(string $level, string $message, array $context = [], ?Throwable $exception = null): void
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
    public function push(array $event): void
    {
        $this->buffer?->add($event);
    }

    public function factory(): EventFactory
    {
        return $this->factory ?? throw new LogicException('The event factory is not available.');
    }

    public function canCapture(): bool
    {
        return $this->buffer !== null && $this->factory !== null && $this->isActive();
    }

    /**
     * Marks the throwable as captured; true when it already was (one event per exception instance).
     */
    public function alreadyCaptured(Throwable $throwable): bool
    {
        if (isset($this->captured[$throwable])) {
            return true;
        }

        $this->captured[$throwable] = true;

        return false;
    }

    public function meetsLogLevel(string $level): bool
    {
        $threshold = array_search(strtolower((string) ($this->config['log_level'] ?? 'error')), self::LEVELS, true);
        $actual = array_search(strtolower($level), self::LEVELS, true);

        return $actual !== false && $actual >= ($threshold === false ? 4 : $threshold);
    }

    public function isIgnored(Throwable $throwable): bool
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
