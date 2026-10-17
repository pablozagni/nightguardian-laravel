<?php

namespace NightGuardian\Laravel\Capture;

use Illuminate\Queue\Events\JobFailed;
use NightGuardian\Laravel\NightGuardian;
use NightGuardian\Laravel\Support\Guard;
use Throwable;

/**
 * Failed queued jobs (after their last attempt) become `job_failed` events.
 */
class QueueListener
{
    public function __construct(private NightGuardian $client) {}

    public function handle(JobFailed $event): void
    {
        Guard::run(function () use ($event) {
            if (! $this->client->canCapture() || $this->client->alreadyCaptured($event->exception)) {
                return;
            }

            $job = $event->job;
            $name = $this->read(fn () => $job->resolveName());
            $queue = $this->read(fn () => $job->getQueue());
            $attempts = $this->read(fn () => $job->attempts());

            $this->client->push($this->client->factory()->jobFailed(
                is_string($name) && $name !== '' ? $name : $job::class,
                is_string($queue) ? $queue : null,
                $event->connectionName,
                is_int($attempts) ? $attempts : 0,
                $event->exception,
            ));
        });
    }

    /**
     * Job implementations differ between queue drivers; a missing detail must not lose the event.
     */
    private function read(callable $getter): mixed
    {
        try {
            return $getter();
        } catch (Throwable $e) {
            return null;
        }
    }
}
