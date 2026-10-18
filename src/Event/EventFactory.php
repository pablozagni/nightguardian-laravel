<?php

namespace NightGuardian\Laravel\Event;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use NightGuardian\Laravel\Privacy\Scrubber;
use NightGuardian\Laravel\Support\Json;
use Throwable;

/**
 * Builds protocol v1 events (collector/docs/protocol/v1.md). The result is plain, scrubbed and
 * size-bounded data, ready to be buffered and JSON-encoded by the transport.
 *
 * Options accepted by every builder:
 *   level, reference, tags (array), context (array), fingerprint (list<string>), timestamp (DateTimeInterface),
 *   request (Illuminate\Http\Request; defaults to the current HTTP request, if any),
 *   status_code, duration_ms (for the request object).
 */
class EventFactory
{
    /**
     * @var array<string, mixed>
     */
    private $config;

    /**
     * @var Scrubber
     */
    private $scrubber;

    /**
     * @var StackTrace
     */
    private $stackTrace;

    /**
     * @var RequestContext
     */
    private $requestContext;

    /**
     * @var UserContext
     */
    private $userContext;

    /**
     * @var RuntimeContext
     */
    private $runtimeContext;

    /**
     * @var Release
     */
    private $release;

    /**
     * @var Closure():?Request|null
     */
    private $currentRequest;

    /**
     * @var Closure():DateTimeInterface|null
     */
    private $clock;

    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    public const MAX_EVENT_BYTES = 256 * 1024;

    public const MAX_CONTEXT_BYTES = 32 * 1024;

    private const MAX_TAGS = 20;

    private const MAX_OUTPUT_TAIL = 2048;

    private const MAX_COMMENT = 2000;

    /**
     * @param  array<string, mixed>  $config  The `nightguardian` configuration.
     * @param  (Closure(): ?Request)|null  $currentRequest  Returns the HTTP request being served, or null (console).
     * @param  (Closure(): DateTimeInterface)|null  $clock
     */
    public function __construct(array $config, Scrubber $scrubber, StackTrace $stackTrace, RequestContext $requestContext, UserContext $userContext, RuntimeContext $runtimeContext, Release $release, ?Closure $currentRequest = null, ?Closure $clock = null)
    {
        $this->config = $config;
        $this->scrubber = $scrubber;
        $this->stackTrace = $stackTrace;
        $this->requestContext = $requestContext;
        $this->userContext = $userContext;
        $this->runtimeContext = $runtimeContext;
        $this->release = $release;
        $this->currentRequest = $currentRequest;
        $this->clock = $clock;
    }

    /**
     * @param  array<string, mixed>  $options  Extra: handled (bool, default false).
     * @param  Throwable  $throwable
     * @return array<string, mixed>
     */
    public function exception($throwable, $options = []): array
    {
        $options['reference'] = $options['reference'] ?? Reference::generate();

        return $this->make('exception', [
            'exceptions' => $this->stackTrace->chain($throwable),
            'handled' => (bool) ($options['handled'] ?? false),
        ], $options);
    }

    /**
     * A manual or automatic connectivity test: same payload as an exception, never alerts.
     *
     * @param  array<string, mixed>  $options
     * @param  Throwable  $throwable
     * @return array<string, mixed>
     */
    public function test($throwable, $options = []): array
    {
        $options['level'] = $options['level'] ?? 'info';

        return $this->make('test', [
            'exceptions' => $this->stackTrace->chain($throwable),
            'handled' => true,
        ], $options);
    }

    /**
     * @param  array<string, mixed>  $logContext  The PSR-3 context (without the exception).
     * @param  array<string, mixed>  $options
     * @param  string  $level
     * @param  string  $message
     * @param  Throwable|null  $exception
     * @param  string|null  $channel
     * @return array<string, mixed>
     */
    public function log($level, $message, $logContext = [], $exception = null, $channel = null, $options = []): array
    {
        $options['level'] = $options['level'] ?? $level;

        $payload = ['message' => $message];

        if ($channel !== null && $channel !== '') {
            $payload['channel'] = $channel;
        }

        if ($logContext !== []) {
            $payload['context'] = $this->limitContext($logContext);
        }

        $payload['exception'] = $exception !== null ? $this->stackTrace->describe($exception) : null;

        if ($exception !== null) {
            $options['reference'] = $options['reference'] ?? Reference::generate();
        }

        return $this->make('log', $payload, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  string  $job
     * @param  string|null  $queue
     * @param  string|null  $connection
     * @param  int  $attempts
     * @param  Throwable  $exception
     * @return array<string, mixed>
     */
    public function jobFailed($job, $queue, $connection, $attempts, $exception, $options = []): array
    {
        return $this->make('job_failed', array_filter([
            'job' => $job,
            'queue' => $queue,
            'connection' => $connection,
            'attempts' => $attempts,
            'exceptions' => $this->stackTrace->chain($exception),
        ], static function ($value) {
            return $value !== null;
        }), $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  string  $command
     * @param  string|null  $expression
     * @param  int|null  $exitCode
     * @param  string|null  $output
     * @param  Throwable|null  $exception
     * @return array<string, mixed>
     */
    public function scheduleFailed($command, $expression, $exitCode, $output, $exception, $options = []): array
    {
        $payload = array_filter([
            'command' => $command,
            'expression' => $expression,
            'exit_code' => $exitCode,
            'output_tail' => $output !== null && $output !== '' ? mb_substr($output, -self::MAX_OUTPUT_TAIL) : null,
        ], static function ($value) {
            return $value !== null;
        });

        $payload['exceptions'] = $exception !== null ? $this->stackTrace->chain($exception) : [];

        return $this->make('schedule_failed', $payload, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  int  $durationMs
     * @param  int  $thresholdMs
     * @param  float|null  $memoryMb
     * @param  int|null  $queries
     * @return array<string, mixed>
     */
    public function slowRequest($durationMs, $thresholdMs, $memoryMb = null, $queries = null, $options = []): array
    {
        $options['level'] = $options['level'] ?? 'warning';
        $options['duration_ms'] = $options['duration_ms'] ?? $durationMs;

        return $this->make('slow_request', array_filter([
            'duration_ms' => $durationMs,
            'threshold_ms' => $thresholdMs,
            'memory_mb' => $memoryMb !== null ? round($memoryMb, 1) : null,
            'queries' => $queries,
        ], static function ($value) {
            return $value !== null;
        }), $options);
    }

    /**
     * @param  string  $sql  Already normalized: bindings replaced by `?`.
     * @param  array{file: string, line: int}|null  $location
     * @param  array<string, mixed>  $options
     * @param  float  $durationMs
     * @param  int  $thresholdMs
     * @param  string|null  $connection
     * @return array<string, mixed>
     */
    public function slowQuery($sql, $durationMs, $thresholdMs, $connection = null, $location = null, $options = []): array
    {
        $options['level'] = $options['level'] ?? 'warning';

        if ($location !== null) {
            $location['file'] = $this->stackTrace->relativePath($location['file']);
        }

        return $this->make('slow_query', array_filter([
            'sql' => $sql,
            'duration_ms' => round($durationMs, 1),
            'threshold_ms' => $thresholdMs,
            'connection' => $connection,
            'location' => $location,
        ], static function ($value) {
            return $value !== null;
        }), $options);
    }

    /**
     * @param  mixed[]  $info
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function deploy($info, $options = []): array
    {
        $payload = array_filter($info, static function ($value) {
            return is_string($value) && $value !== '';
        });

        if (isset($payload['release'])) {
            $options['release'] = $payload['release'];
        }

        return $this->make('deploy', $payload, $options, false);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  string  $eventId
     * @param  string|null  $reference
     * @param  string  $comment
     * @param  string|null  $email
     * @return array<string, mixed>
     */
    public function feedback($eventId, $reference, $comment, $email = null, $options = []): array
    {
        $options['request'] = $options['request'] ?? false;

        return $this->make('feedback', array_filter([
            'event_id' => $eventId,
            'reference' => $reference,
            'comment' => mb_substr($comment, 0, self::MAX_COMMENT),
            'email' => $email,
        ], static function ($value) {
            return $value !== null;
        }), $options, false);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options
     * @param  string  $type
     * @param  bool  $withLevel
     * @return array<string, mixed>
     */
    public function make($type, $payload, $options = [], $withLevel = true): array
    {
        $event = [
            'id' => Str::uuid()->toString(),
            'type' => $type,
            'timestamp' => $this->timestamp($options['timestamp'] ?? null),
        ];

        if ($withLevel) {
            $event['level'] = $this->level($options['level'] ?? 'error');
        }

        if (isset($options['reference']) && is_string($options['reference']) && Reference::isValid($options['reference'])) {
            $event['reference'] = $options['reference'];
        }

        $event['environment'] = mb_substr($this->string($this->config['environment'] ?? null) ?? 'production', 0, 64);

        $release = $this->string($options['release'] ?? null) ?? $this->release->resolve();

        if ($release !== null) {
            $event['release'] = mb_substr($release, 0, 128);
        }

        $server = $this->runtimeContext->hostname();

        if ($server !== null) {
            $event['server'] = $server;
        }

        $event['platform'] = 'php';

        $fingerprint = $this->fingerprint($options['fingerprint'] ?? null);

        if ($fingerprint !== null) {
            $event['fingerprint'] = $fingerprint;
        }

        $tags = $this->tags($options['tags'] ?? []);

        if ($tags !== []) {
            $event['tags'] = $tags;
        }

        if (isset($options['context']) && is_array($options['context']) && $options['context'] !== []) {
            $event['context'] = $options['context'];
        }

        $user = $this->userContext->resolve();

        if ($user !== null) {
            $event['user'] = $user;
        }

        $request = $this->request($options);

        if ($request !== null) {
            $event['request'] = $request;
        }

        $event['runtime'] = $this->runtimeContext->toArray();
        $event['payload'] = $payload;

        return $this->fit($this->scrub($event));
    }

    /**
     * Scrubs the free-form parts of an event. Identity fields (ids, timestamps, versions) are left alone so a
     * numeric id that happens to look like a card number can never be mangled.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function scrub(array $event): array
    {
        $userId = $event['user']['id'] ?? null;
        $eventId = $event['payload']['event_id'] ?? null;
        $reference = $event['payload']['reference'] ?? null;

        foreach (['fingerprint', 'tags', 'context', 'user', 'request', 'payload'] as $key) {
            if (isset($event[$key]) && is_array($event[$key])) {
                $event[$key] = $this->scrubber->scrub($event[$key]);
            }
        }

        if ($userId !== null) {
            $event['user']['id'] = $userId;
        }

        if ($eventId !== null) {
            $event['payload']['event_id'] = $eventId;
        }

        if ($reference !== null) {
            $event['payload']['reference'] = $reference;
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|null
     */
    private function request(array $options): ?array
    {
        $request = $options['request'] ?? null;

        if ($request === false) {
            return null;
        }

        if (! $request instanceof Request) {
            try {
                $request = $this->currentRequest !== null ? ($this->currentRequest)() : null;
            } catch (Throwable $e) {
                $request = null;
            }
        }

        $allowlist = $this->config['header_allowlist'] ?? [];

        return $this->requestContext->describe($request instanceof Request ? $request : null, is_array($allowlist) ? array_values(array_filter($allowlist, 'is_string')) : [], isset($options['status_code']) ? (int) $options['status_code'] : null, isset($options['duration_ms']) ? (int) $options['duration_ms'] : null);
    }

    /**
     * Brings an event under the 256 KB protocol limit by dropping the least valuable data first:
     * code context, then free-form context and request body, then the middle of long stacks.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function fit(array $event): array
    {
        if (isset($event['context']) && is_array($event['context'])) {
            $event['context'] = $this->limitContext($event['context']);
        }

        if (Json::size($event) <= self::MAX_EVENT_BYTES) {
            return $event;
        }

        $event = $this->mapFrames($event, static function (array $frame): array {
            unset($frame['context']);

            return $frame;
        });

        if (Json::size($event) <= self::MAX_EVENT_BYTES) {
            return $event;
        }

        unset($event['context'], $event['request']['body']);

        if (isset($event['payload']['context'])) {
            $event['payload']['context'] = ['_truncated' => true];
        }

        if (Json::size($event) <= self::MAX_EVENT_BYTES) {
            return $event;
        }

        foreach (['exceptions', 'exception'] as $key) {
            if (isset($event['payload'][$key]) && is_array($event['payload'][$key])) {
                $event['payload'][$key] = $key === 'exceptions'
                    ? array_map(function (array $e) {
                        return $this->truncateFrames($e);
                    }, $event['payload'][$key])
                    : $this->truncateFrames($event['payload'][$key]);
            }
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function mapFrames(array $event, Closure $callback): array
    {
        $apply = static function (array $exception) use ($callback): array {
            if (isset($exception['frames']) && is_array($exception['frames'])) {
                $exception['frames'] = array_map($callback, $exception['frames']);
            }

            return $exception;
        };

        if (isset($event['payload']['exceptions']) && is_array($event['payload']['exceptions'])) {
            $event['payload']['exceptions'] = array_map($apply, $event['payload']['exceptions']);
        }

        if (isset($event['payload']['exception']) && is_array($event['payload']['exception'])) {
            $event['payload']['exception'] = $apply($event['payload']['exception']);
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $exception
     * @return array<string, mixed>
     */
    private function truncateFrames(array $exception): array
    {
        if (isset($exception['frames']) && is_array($exception['frames'])) {
            $exception['frames'] = array_merge(array_slice($exception['frames'], 0, 10), array_slice($exception['frames'], -5));
        }

        if (isset($exception['message']) && is_string($exception['message'])) {
            $exception['message'] = mb_substr($exception['message'], 0, 2000);
        }

        return $exception;
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    private function limitContext(array $context): array
    {
        if (Json::size($context) <= self::MAX_CONTEXT_BYTES) {
            return $context;
        }

        return ['_truncated' => true, 'keys' => array_slice(array_map('strval', array_keys($context)), 0, 50)];
    }

    /**
     * @param  mixed  $given
     */
    private function timestamp($given): string
    {
        $time = $given instanceof DateTimeInterface
            ? $given
            : ($this->clock !== null ? ($this->clock)() : new DateTimeImmutable('now'));

        return DateTimeImmutable::createFromInterface($time)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * @param  mixed  $level
     */
    private function level($level): string
    {
        $level = is_string($level) ? strtolower($level) : '';

        return in_array($level, self::LEVELS, true) ? $level : 'error';
    }

    /**
     * @param  mixed  $fingerprint
     * @return list<string>|null
     */
    private function fingerprint($fingerprint): ?array
    {
        if (! is_array($fingerprint) || $fingerprint === []) {
            return null;
        }

        $parts = [];

        foreach (array_slice(array_values($fingerprint), 0, 10) as $part) {
            if (is_scalar($part)) {
                $parts[] = (string) $part;
            }
        }

        return $parts === [] ? null : $parts;
    }

    /**
     * @param  mixed  $extra
     * @return array<string, string>
     */
    private function tags($extra): array
    {
        $configured = $this->config['tags'] ?? [];
        $tags = [];

        foreach ([is_array($configured) ? $configured : [], is_array($extra) ? $extra : []] as $source) {
            foreach ($source as $name => $value) {
                if (count($tags) >= self::MAX_TAGS && ! isset($tags[mb_substr((string) $name, 0, 64)])) {
                    continue;
                }

                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }

                if (! is_scalar($value) || (string) $name === '') {
                    continue;
                }

                $tags[mb_substr((string) $name, 0, 64)] = mb_substr((string) $value, 0, 200);
            }
        }

        return $tags;
    }

    /**
     * @param  mixed  $value
     */
    private function string($value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
