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
    /**
     * @var NightGuardian
     */
    private $client;

    public function __construct(NightGuardian $client)
    {
        $this->client = $client;
    }

    /**
     * @param  JobFailed  $event
     */
    public function handle($event): void
    {
        Guard::run(function () use ($event) {
            if (! $this->client->canCapture() || $this->client->alreadyCaptured($event->exception)) {
                return;
            }

            $job = $event->job;
            $name = $this->read(function () use ($job) {
                return $job->resolveName();
            });
            $queue = $this->read(function () use ($job) {
                return $job->getQueue();
            });
            $attempts = $this->read(function () use ($job) {
                return $job->attempts();
            });

            $this->client->push($this->client->factory()->jobFailed(is_string($name) && $name !== '' ? $name : get_class($job), is_string($queue) ? $queue : null, $event->connectionName, is_int($attempts) ? $attempts : 0, $event->exception));
        });
    }

    /**
     * Job implementations differ between queue drivers; a missing detail must not lose the event.
     *
     * @return mixed
     */
    private function read(callable $getter)
    {
        try {
            return $getter();
        } catch (Throwable $e) {
            return null;
        }
    }
}
