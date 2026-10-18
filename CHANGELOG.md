# Changelog

All notable changes to this project are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: [SemVer](https://semver.org).

## [Unreleased]

## [2.0.1] - 2026-10-18

### Fixed
- The `nightguardian:*` Artisan commands are now registered in every context, not only in a console, so
  `Artisan::call('nightguardian:status')` (or `:deploy`, `:test`, `:flush`) works from a web request, a deploy hook or a job.
- A failed scheduled task no longer produces a second, redundant exception event: Laravel reports
  "Scheduled command [...] failed with exit code" in the parent process after the `schedule_failed` event was already
  sent, and the SDK now recognises and skips it. One failed task is one `schedule_failed` (plus, at most, the real exception
  of the command's own process).
- Exception events now carry `request.status_code`: the code of an HTTP exception or 500 when captured, replaced by the status
  of the response that was actually sent once the error page decorator sees it. Handled exceptions (`NightGuardian::report()`)
  and exceptions outside a request have none.

## [2.0.0] - 2026-10-18

First stable release of the 2.x line (Laravel 9–13, PHP 8.1+).

### Added
- Test suite hardening: every app under test has no collector URL/token, a fake transport and a throwaway storage
  directory; `NoRealNetworkTest` fails if a real collector is configured without `NIGHTGUARDIAN_E2E=1`; real-collector
  checks are opt-in in `tests/E2E` (documented in CONTRIBUTING.md).
- Project documentation, architecture, protocol v1 and build plan.
- Package skeleton for the 2.x line: `composer.json` (PHP ^8.1, Laravel 9–13, no runtime dependencies beyond
  `illuminate/*`, `ext-curl`, `ext-json`, `ext-zlib`), auto-discovered service provider, `NightGuardian` facade,
  publishable `config/nightguardian.php` with every option from `docs/configuration.md`.
- Inactive mode: without URL and token (or with `NIGHTGUARDIAN_ENABLED=false`, or without `ext-curl`) the SDK does
  nothing and can say why.
- GitHub Actions: lint, static analysis and a test matrix for Laravel 9–13 on PHP 8.1–8.4 (one `--prefer-lowest` job).
- Pest + Orchestra Testbench test suite, Pint and Larastan (level 6).
- `EventFactory`: builds protocol v1 events for every type (`exception`, `log`, `job_failed`, `schedule_failed`,
  `slow_request`, `slow_query`, `deploy`, `feedback`, `test`) with UUID v4 ids, UTC millisecond timestamps, tags, user,
  request and runtime context. Events are kept under the 256 KB limit (code context is dropped first).
- `StackTrace`: frames most-recent-first with relative paths, `in_app` detection (outside `vendor/` and `node_modules/`),
  ±5 lines of code context for the first 30 in-app frames, first 50 + last 50 frames of very deep stacks, `previous` chain.
- `RequestContext`, `UserContext`, `RuntimeContext`, `Reference` (`NG-XXXX-XXXX`) and `Release` (config, then git SHA
  cached in `storage/nightguardian/release`).
- `Scrubber`: key denylist, card numbers (Luhn), bearer tokens, JWTs, private key blocks, URL query strings, header allowlist.
  Cookies and authorization headers are never sent, even if allowlisted.
- Privacy defaults: IP, request body and user e-mail/name are opt-in; the user is only read from an auth guard that has
  already loaded it, so capturing an error never queries the database.
- Tests validate every event type against the protocol's JSON schema (`opis/json-schema`, dev only).
- Capture (stage L3): `LogListener` on `MessageLogged` (reported exceptions and `Log::error()` calls, level threshold,
  ignore list, HTTP exceptions below 500 ignored, one event per exception instance), `QueueListener` (`job_failed`),
  `ScheduleListener` (thrown exceptions and non-zero exit codes, once per run, output tail), `SlowRequestMiddleware`
  (outermost global middleware, event built after the response) and `SlowQueryListener` (placeholders only, never
  bindings; at most 5 per request).
- `NightGuardian::report()`, `message()` and `flush()` (facade). Every entry point is guarded: nothing escapes, nested
  calls are skipped, failures are only counted. Contexts are made JSON-safe (objects, closures, resources, depth, width).
- Nothing is registered while the SDK is inactive.
- Transport (stage L4): `Buffer` (one batch per request/job/command, max 100 events), `CurlTransport` (gzip POST, 1 s connect /
  2 s total timeouts, `Retry-After`, bounded response), `Spool` (gzip files in `storage/nightguardian/spool`, 10 MB / 7 days,
  oldest deleted first, silent drop when storage is not writable), `CircuitBreaker` (60 s doubling to 15 min, one probe,
  state in `breaker.json`, 1 h on rejected tokens, `Retry-After` on 429), `Dispatcher` (413 splits the batch, 400 drops it,
  at most one spooled batch drained per delivery and every 30 s, guarded by a non-blocking `flock`) and persisted counters.
- Flush points: end of the request (after the response), after each job, after each command and scheduled task, plus a
  shutdown fallback.
- Commands: `nightguardian:test`, `nightguardian:status`, `nightguardian:flush`, `nightguardian:deploy`.
- `testbench.yaml` so the commands can be run from a clone with `vendor/bin/testbench`.
- Friendly error page (stage L5): `ExceptionHandlerDecorator` shows a self-contained page (inline CSS, no external assets,
  light/dark, English/Spanish, JavaScript only for an optional copy button) with the `NG-XXXX-XXXX` reference for HTML
  requests that end in a 500 with `APP_DEBUG=false`. JSON requests only get an `X-NightGuardian-Reference` header. If anything
  fails the original response is returned; exceptions NightGuardian did not capture keep the application's own page.
- Feedback box: `POST /nightguardian/feedback` without session, cookies or CSRF, protected by an HMAC-signed 15-minute form
  token and 5 messages/minute/IP (skipped if the cache is down). Sent synchronously as a `feedback` event linked to the
  original event; the thank-you page says *sent*, *recorded* (spooled) or that it could not be saved, never claiming a
  delivery that did not happen.
- Publishable views and translations (`--tag=nightguardian-views`, `--tag=nightguardian-lang`); README screenshots in
  `docs/img/`; documented the `mod_php` (no PHP-FPM) caveat in README and `docs/design.md`.
- New runtime requirements, all part of Laravel: `illuminate/view`, `illuminate/routing`, `illuminate/cache`.
