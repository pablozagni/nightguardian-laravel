<?php

namespace NightGuardian\Laravel\Capture;

use Closure;
use Illuminate\Http\Request;
use NightGuardian\Laravel\NightGuardian;
use NightGuardian\Laravel\Support\Guard;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware (pushed first, so it wraps the others) that measures how long a request takes.
 * The slow request event is built in `terminate()`, after the response has been sent.
 */
class SlowRequestMiddleware
{
    /**
     * @var NightGuardian
     */
    private $client;

    /**
     * @var RequestStats
     */
    private $stats;

    private const START = 'nightguardian.started_at';

    private const DURATION = 'nightguardian.duration_ms';

    public function __construct(NightGuardian $client, RequestStats $stats)
    {
        $this->client = $client;
        $this->stats = $stats;
    }

    /**
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed
     */
    public function handle($request, $next)
    {
        Guard::run(function () use ($request) {
            $this->stats->reset();
            $request->attributes->set(self::START, microtime(true));
        });

        $response = $next($request);

        Guard::run(function () use ($request) {
            $started = $request->attributes->get(self::START);

            if (is_float($started)) {
                $request->attributes->set(self::DURATION, (int) round((microtime(true) - $started) * 1000));
            }
        });

        return $response;
    }

    /**
     * @param  Request  $request
     * @param  mixed  $response
     */
    public function terminate($request, $response): void
    {
        Guard::run(function () use ($request, $response) {
            $threshold = (int) ($this->client->config()['slow_request_ms'] ?? 0);
            $duration = $request->attributes->get(self::DURATION);

            if ($threshold <= 0 || ! is_int($duration) || $duration < $threshold || ! $this->client->canCapture()) {
                return;
            }

            $this->client->push($this->client->factory()->slowRequest($duration, $threshold, memory_get_peak_usage(true) / 1048576, $this->stats->queries(), [
                'request' => $request,
                'status_code' => $response instanceof Response ? $response->getStatusCode() : null,
            ]));
        });
    }
}
