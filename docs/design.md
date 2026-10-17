# SDK design — nightguardian/laravel

## 1. Package layout (2.x; 1.x mirrors it with PHP 7.1 syntax)

```
src/
  NightGuardianServiceProvider.php
  NightGuardian.php                 # main client (facade root): report(), message(), flush()
  Facades/NightGuardian.php
  Capture/
    LogListener.php                 # MessageLogged → events (exceptions + logs)
    ExceptionHandlerDecorator.php   # only for the error page / reference header
    QueueListener.php               # JobFailed
    ScheduleListener.php            # ScheduledTaskFailed (+ fallback, see compatibility.md)
    SlowRequestMiddleware.php       # global middleware, measures duration
    SlowQueryListener.php           # QueryExecuted
  Event/
    EventFactory.php                # builds protocol v1 events
    StackTrace.php                  # frames, in_app, code context
    RequestContext.php  UserContext.php  RuntimeContext.php
    Reference.php                   # NG-XXXX-XXXX generator
    Release.php                     # env → .git/HEAD → null (cached)
  Privacy/Scrubber.php
  Transport/
    Buffer.php                      # in-memory, per request/job
    CurlTransport.php               # gzip POST, timeouts
    Spool.php                       # files in storage/nightguardian/spool, bounded, locked drain
    CircuitBreaker.php              # state in storage/nightguardian/breaker.json
    Dispatcher.php                  # decides: send now / spool / skip
  Http/
    FeedbackController.php          # POST /nightguardian/feedback (signed, no session)
  Console/ TestCommand StatusCommand FlushCommand DeployCommand
resources/views/error.blade.php  resources/views/feedback-thanks.blade.php
resources/lang/en/*.php  resources/lang/es/*.php
config/nightguardian.php
```

## 2. Capture

**Primary hook: `Illuminate\Log\Events\MessageLogged`** (exists from Laravel 5.4 to 13).
Laravel's exception handler reports exceptions by logging them with `context['exception']`, so one
listener captures both reported exceptions and `Log::error()` calls, without touching the app's
`Handler.php` / `bootstrap/app.php`, and respecting the app's own `dontReport` list.

- Level ≥ `NIGHTGUARDIAN_LOG_LEVEL` (default `error`).
- If `context['exception']` is a `Throwable` → `exception` event; else → `log` event.
- Ignore list (config) of exception classes, defaults: validation, authentication, authorization,
  404/model-not-found, token mismatch, `HttpException` with status < 500.
- Re-entrancy guard: a static flag prevents capturing anything emitted while the SDK itself runs.
- Deduplicate: the same Throwable instance is captured once per request (`spl_object_id` / `spl_object_hash` on 1.x).

**Error page hook: `ExceptionHandlerDecorator`** registered with `$app->extend(ExceptionHandler::class)`.
It proxies everything to the original handler and only, in `render()`, when all are true:
`config('nightguardian.error_page')`, `!config('app.debug')`, response status 500, request expects HTML →
returns the NightGuardian error view with the reference code. For JSON requests it only adds header
`X-NightGuardian-Reference` and never changes the body. If anything fails it returns the original response.
The reference is created at capture time and remembered per exception instance (`NightGuardian::referenceFor()`),
so the decorator only shows the code of the very exception it is rendering. If that exception was not captured
(SDK inactive, ignored, below the log level) the application's own page stays in charge.

> Must be validated per Laravel version in CI (5.8 Handler signature vs 11+ `withExceptions`). If the
> decorator can't be applied safely on a version, the error page is disabled there and documented.

## 3. Transport

```
capture → Buffer (memory) ──► at terminate ──► Dispatcher
                                              ├─ breaker open? → Spool.write(batch)
                                              ├─ send via CurlTransport (connect 1 s, total 2 s)
                                              │    ├─ 202 → done (+ maybe drain 1 spooled batch)
                                              │    ├─ 429/5xx/timeout → Spool.write, breaker.open
                                              │    └─ 401/403 → Spool.write (bounded), breaker.open 1 h
                                              └─ never throws
```

- **When:** `$app->terminating()` callback. On PHP-FPM, Symfony's `Response::send()` already called
  `fastcgi_finish_request()`, so the user has the page before we send. **With `mod_php` (no PHP-FPM) PHP cannot
  release the connection early**: while the collector is down the browser may wait up to ~1 s more (the connect
  timeout) the first time, until the circuit breaker opens; later requests are not delayed. PHP-FPM is unaffected. Queue workers: flush after
  `JobProcessed`/`JobFailed`. Console: flush on `CommandFinished` and via `register_shutdown_function` as last resort.
  Octane: flush per request via `terminating`.
- **Batching:** all events of one request/job in one POST (max 100).
- **Spool:** `storage/nightguardian/spool/<timestamp>-<uuid>.json.gz`. Bounds: `spool_max_mb` (10) and
  `spool_max_age_days` (7); oldest deleted first. If storage isn't writable → drop silently (count in status).
- **Drain:** at most one spooled batch per terminate, only if breaker closed and last drain > 30 s ago,
  guarded by `flock` on `storage/nightguardian/drain.lock` (non-blocking). `nightguardian:flush` drains all.
- **Circuit breaker:** on failure open for 60 s, doubling up to 15 min; one probe after expiry; close on success.
  State file read once per request, written only on transitions.
- **Worst case cost** for a request when the collector is down and the breaker is closed: one 1 s connect
  timeout, after the response, then the breaker opens and subsequent requests cost ~0.

## 4. Error page & feedback

- `resources/views/error.blade.php`: self-contained HTML, **inline CSS only**, no external assets, no JS
  required, no app layout. Light/dark via `prefers-color-scheme`. Shows: friendly title, reference code
  (copyable), optional feedback form, link to home.
- Feedback form posts (plain HTML form) to `POST /nightguardian/feedback`, route registered **without
  the `web` group** (no session, no CSRF — the session may be what is broken). Protection: hidden fields
  `event_id`, `reference`, `expires`, `signature = HMAC-SHA256(app.key, event_id|reference|expires)`;
  15-minute validity; rate limit 5/min per IP via cache (skip if cache fails); comment max 2,000 chars.
- Controller sends a `feedback` event **synchronously** with the 2 s timeout:
  success → "Thanks, your message was sent ✓"; failure → spooled and "Thanks, your message was recorded
  and will be delivered shortly". Never claims delivery that didn't happen.
- Texts in `resources/lang/{en,es}`, views publishable (`--tag=nightguardian-views`).

## 5. Release detection

`NIGHTGUARDIAN_RELEASE` → else read `.git/HEAD` (+ ref file / `packed-refs`) → short SHA, cached in
`storage/nightguardian/release` per deploy (invalidated when `.git/HEAD` mtime changes) → else null.

## 6. What the SDK must never do

- Throw, `dd()`, `echo`, or write to the app's logs on its own failures (use an internal counter shown by `nightguardian:status`).
- Open DB connections, use the app's cache/queue/session for core paths.
- Send cookies, `authorization`, request body or IP unless explicitly enabled.
