<?php

namespace NightGuardian\Laravel\Capture;

use Illuminate\Log\Events\MessageLogged;
use NightGuardian\Laravel\NightGuardian;
use NightGuardian\Laravel\Support\Guard;
use NightGuardian\Laravel\Support\Json;
use Throwable;

/**
 * Primary capture hook. Laravel's exception handler reports exceptions by logging them with
 * `context['exception']`, so this one listener sees reported exceptions and plain `Log::error()` calls.
 */
class LogListener
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
     * @param  MessageLogged  $event
     */
    public function handle($event): void
    {
        Guard::run(function () use ($event) {
            if (! $this->client->isActive() || ! $this->client->meetsLogLevel((string) $event->level)) {
                return;
            }

            $context = $event->context;
            $exception = $context['exception'] ?? null;

            if ($exception instanceof Throwable) {
                unset($context['exception']);
                $this->client->captureThrowable($exception, (string) $event->level, $context);

                return;
            }

            $this->client->captureLog((string) $event->level, $this->text($event->message), $context);
        });
    }

    /**
     * @param  mixed  $message
     */
    private function text($message): string
    {
        if (is_string($message)) {
            return $message;
        }

        if (is_object($message) && method_exists($message, '__toString')) {
            return (string) $message;
        }

        return (string) Json::partial($message);
    }
}
