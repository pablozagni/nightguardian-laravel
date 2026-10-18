<?php

namespace NightGuardian\Laravel\Capture;

use Illuminate\Database\Events\QueryExecuted;
use NightGuardian\Laravel\Event\StackTrace;
use NightGuardian\Laravel\NightGuardian;
use NightGuardian\Laravel\Support\Guard;

/**
 * Counts queries for the slow request report and captures the slow ones.
 *
 * Only the SQL with its `?` placeholders is sent, never the bindings.
 */
class SlowQueryListener
{
    /**
     * @var NightGuardian
     */
    private $client;

    /**
     * @var RequestStats
     */
    private $stats;

    /**
     * @var StackTrace
     */
    private $stackTrace;

    private const MAX_SQL = 2000;

    public function __construct(NightGuardian $client, RequestStats $stats, StackTrace $stackTrace)
    {
        $this->client = $client;
        $this->stats = $stats;
        $this->stackTrace = $stackTrace;
    }

    /**
     * @param  QueryExecuted  $event
     */
    public function handle($event): void
    {
        Guard::run(function () use ($event) {
            $this->stats->countQuery();

            $threshold = (int) ($this->client->config()['slow_query_ms'] ?? 0);

            if ($threshold <= 0 || $event->time < $threshold || ! $this->client->canCapture() || ! $this->stats->claimSlowQuery()) {
                return;
            }

            $sql = trim((string) preg_replace('/\s+/', ' ', $event->sql));

            $this->client->push($this->client->factory()->slowQuery(mb_substr($sql, 0, self::MAX_SQL), (float) $event->time, $threshold, $event->connectionName, $this->location()));
        });
    }

    /**
     * The first application frame that triggered the query.
     *
     * @return array{file: string, line: int}|null
     */
    private function location(): ?array
    {
        $own = str_replace('\\', '/', dirname(__DIR__)).'/';

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $frame) {
            $file = $frame['file'] ?? null;

            if (is_string($file) && isset($frame['line']) && $this->stackTrace->isInApp($file) && strncmp(str_replace('\\', '/', $file), $own, strlen($own)) !== 0) {
                return ['file' => $file, 'line' => (int) $frame['line']];
            }
        }

        return null;
    }
}
