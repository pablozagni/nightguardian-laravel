<?php

namespace NightGuardian\Laravel\Capture;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use NightGuardian\Laravel\NightGuardian;
use NightGuardian\Laravel\Support\Guard;
use Throwable;
use WeakMap;

/**
 * Scheduled tasks that throw (`ScheduledTaskFailed`) or finish with a non-zero exit code.
 * A task is reported once per run.
 */
class ScheduleListener
{
    private const OUTPUT_TAIL_BYTES = 2048;

    /** @var WeakMap<ScheduledEvent, true> */
    private WeakMap $reported;

    public function __construct(private NightGuardian $client)
    {
        $this->reported = new WeakMap;
    }

    public function starting(ScheduledTaskStarting $event): void
    {
        Guard::run(function () use ($event) {
            unset($this->reported[$event->task]);
        });
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        Guard::run(function () use ($event) {
            if (isset($this->reported[$event->task])) {
                // The task already produced its schedule_failed event (from its exit code). Laravel is now about to
                // report "Scheduled command [...] failed with exit code" as an exception: mark it as seen so it is
                // not captured a second time.
                $this->client->alreadyCaptured($event->exception);

                return;
            }

            $this->reported[$event->task] = true;
            $this->capture($event->task, $event->exception);
        });
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        Guard::run(function () use ($event) {
            $exitCode = $event->task->exitCode ?? null;

            if (! is_int($exitCode) || $exitCode === 0 || isset($this->reported[$event->task])) {
                return;
            }

            $this->reported[$event->task] = true;
            $this->capture($event->task, null);
        });
    }

    private function capture(ScheduledEvent $task, ?Throwable $exception): void
    {
        if (! $this->client->canCapture()) {
            return;
        }

        if ($exception !== null && $this->client->alreadyCaptured($exception)) {
            return;
        }

        $exitCode = $task->exitCode ?? null;

        $this->client->push($this->client->factory()->scheduleFailed(
            $this->describe($task),
            $task->expression !== '' ? $task->expression : null,
            is_int($exitCode) ? $exitCode : null,
            $this->outputTail($task),
            $exception,
        ));
    }

    private function describe(ScheduledEvent $task): string
    {
        $summary = (string) $task->getSummaryForDisplay();

        if ($summary === '') {
            $summary = (string) ($task->command ?? $task->description ?? 'scheduled task');
        }

        // Commands are stored as "'/usr/bin/php' 'artisan' name": show them like a person would type them.
        $summary = str_replace("'", '', $summary);
        $summary = preg_replace('#^\S*/php\S*\s+#i', 'php ', $summary) ?? $summary;

        return mb_substr(trim($summary), 0, 500);
    }

    private function outputTail(ScheduledEvent $task): ?string
    {
        $output = $task->output;

        if ($output === '' || in_array($output, ['/dev/null', 'NUL'], true) || ! is_file($output) || ! is_readable($output)) {
            return null;
        }

        $size = (int) @filesize($output);
        $handle = @fopen($output, 'rb');

        if ($handle === false) {
            return null;
        }

        if ($size > self::OUTPUT_TAIL_BYTES) {
            fseek($handle, -self::OUTPUT_TAIL_BYTES, SEEK_END);
        }

        $tail = stream_get_contents($handle);
        fclose($handle);

        return is_string($tail) && $tail !== '' ? $tail : null;
    }
}
