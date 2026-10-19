<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| NightGuardian
|--------------------------------------------------------------------------
|
| Every option can be set from .env. Without a URL and a token the SDK stays
| inactive: `php artisan nightguardian:status` explains why.
| Full reference: docs/configuration.md
|
*/

return [

    // Master switch. Set it to false in your test environment.
    'enabled' => (bool) env('NIGHTGUARDIAN_ENABLED', true),

    // Collector base URL (e.g. https://ng.example.com) and project token (ng_...).
    'url' => env('NIGHTGUARDIAN_URL'),
    'token' => env('NIGHTGUARDIAN_TOKEN'),

    // Defaults to APP_ENV.
    'environment' => env('NIGHTGUARDIAN_ENVIRONMENT', env('APP_ENV', 'production')),

    // Defaults to the current git commit (short SHA) when the app has a .git directory.
    'release' => env('NIGHTGUARDIAN_RELEASE'),

    // Minimum PSR-3 level captured from logs.
    'log_level' => env('NIGHTGUARDIAN_LOG_LEVEL', 'error'),

    // Thresholds in milliseconds. 0 disables the capture.
    'slow_request_ms' => (int) env('NIGHTGUARDIAN_SLOW_REQUEST_MS', 2000),
    'slow_query_ms' => (int) env('NIGHTGUARDIAN_SLOW_QUERY_MS', 1000),

    // Friendly 500 page (only when APP_DEBUG=false) and its "what were you doing?" box.
    'error_page' => (bool) env('NIGHTGUARDIAN_ERROR_PAGE', true),
    'feedback' => (bool) env('NIGHTGUARDIAN_FEEDBACK', true),

    // HTTP timeouts in seconds.
    'timeout' => (float) env('NIGHTGUARDIAN_TIMEOUT', 2),
    'connect_timeout' => (float) env('NIGHTGUARDIAN_CONNECT_TIMEOUT', 1),

    // On-disk queue used while the collector is unreachable (storage/nightguardian/spool).
    'spool_max_mb' => (int) env('NIGHTGUARDIAN_SPOOL_MAX_MB', 10),
    'spool_max_age_days' => (int) env('NIGHTGUARDIAN_SPOOL_MAX_AGE_DAYS', 7),

    // Opt-in data. Everything here is off by default.
    'send_ip' => (bool) env('NIGHTGUARDIAN_SEND_IP', false),
    'send_request_body' => (bool) env('NIGHTGUARDIAN_SEND_REQUEST_BODY', false),
    'send_user_email' => (bool) env('NIGHTGUARDIAN_SEND_USER_EMAIL', false),

    // Exception classes that are never reported (instanceof match). HTTP exceptions with a
    // status below 500 are always ignored as well.
    'ignore_exceptions' => [
        AuthenticationException::class,
        AuthorizationException::class,
        ModelNotFoundException::class,
        TokenMismatchException::class,
        ValidationException::class,
    ],

    // Extra key names to filter, added to the built-in denylist (case-insensitive substring
    // match), e.g. ['cuit', 'cbu'].
    'scrub_keys' => [],

    // Request headers that may be sent. Cookies and authorization are never sent.
    'header_allowlist' => [
        'user-agent',
        'accept',
        'accept-language',
        'content-type',
        'referer',
        'x-requested-with',
    ],

    // Tag every event with `host`, the host of APP_URL, so one collector project can tell many installations of the same
    // app apart. A `host` tag set in `tags` (or per event) always wins.
    'host_tag' => (bool) env('NIGHTGUARDIAN_HOST_TAG', true),

    // Static tags added to every event (name => value).
    'tags' => [],

    // Optional callable returning ['id' => ..., 'email' => ..., 'name' => ...] for the current user.
    // Without it the authenticated user's id is used.
    'user_resolver' => null,

];
