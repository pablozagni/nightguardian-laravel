<?php

namespace NightGuardian\Laravel\Event;

use Illuminate\Http\Request;
use NightGuardian\Laravel\Privacy\Scrubber;
use Throwable;

/**
 * Describes an HTTP request for the `request` object of an event (protocol v1 §4.1).
 *
 * Query strings and headers are scrubbed. Headers go through an allowlist, the IP and the body are
 * only included when the app opts in.
 */
class RequestContext
{
    /**
     * @var Scrubber
     */
    private $scrubber;

    /**
     * @var bool
     */
    private $sendIp = false;

    /**
     * @var bool
     */
    private $sendBody = false;

    private const MAX_BODY_BYTES = 8192;

    public function __construct(Scrubber $scrubber, bool $sendIp = false, bool $sendBody = false)
    {
        $this->scrubber = $scrubber;
        $this->sendIp = $sendIp;
        $this->sendBody = $sendBody;
    }

    /**
     * @param  list<string>  $headerAllowlist
     * @param  Request|null  $request
     * @param  int|null  $statusCode
     * @param  int|null  $durationMs
     * @return array<string, mixed>|null
     */
    public function describe($request, $headerAllowlist, $statusCode = null, $durationMs = null): ?array
    {
        if ($request === null) {
            return null;
        }

        try {
            $data = [
                'method' => strtoupper($request->method()),
                'url' => mb_substr($this->scrubber->scrubUrl($request->fullUrl()), 0, 2048),
            ];

            $route = $this->routeName($request);

            if ($route !== null) {
                $data['route'] = $route;
            }

            if ($statusCode !== null) {
                $data['status_code'] = $statusCode;
            }

            $headers = $this->scrubber->scrubHeaders($this->headers($request), $headerAllowlist);

            // An empty PHP array would be encoded as a JSON list, not an object.
            if ($headers !== []) {
                $data['headers'] = $headers;
            }

            $data['ip'] = $this->sendIp ? $request->ip() : null;

            if ($durationMs !== null) {
                $data['duration_ms'] = $durationMs;
            }

            if ($this->sendBody) {
                $body = $this->body($request);

                if ($body !== null) {
                    $data['body'] = $body;
                }
            }

            return $data;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function routeName(Request $request): ?string
    {
        try {
            $route = $request->route();

            if (! is_object($route)) {
                return null;
            }

            $name = method_exists($route, 'getName') ? $route->getName() : null;

            if (is_string($name) && $name !== '') {
                return $name;
            }

            $uri = method_exists($route, 'uri') ? $route->uri() : null;

            return is_string($uri) && $uri !== '' ? mb_substr($uri, 0, 255) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string, string|array<int, string|null>|null>
     */
    private function headers(Request $request): array
    {
        /** @var array<string, string|array<int, string|null>|null> $headers */
        $headers = $request->headers->all();

        return $headers;
    }

    /**
     * Scrubbed request input, cut to a small JSON size so it can never blow up the event.
     *
     * @return array<array-key, mixed>|null
     */
    private function body(Request $request): ?array
    {
        // Only the body (the query string is already part of the scrubbed URL). Uploaded files are not included.
        $input = $request->isJson() ? $request->json()->all() : $request->request->all();
        unset($input['_token']);

        if (! is_array($input) || $input === []) {
            return null;
        }

        $input = $this->scrubber->scrub($input);

        if (strlen((string) json_encode($input, JSON_PARTIAL_OUTPUT_ON_ERROR)) > self::MAX_BODY_BYTES) {
            return ['_truncated' => true, 'keys' => array_slice(array_map('strval', array_keys($input)), 0, 50)];
        }

        return $input;
    }
}
