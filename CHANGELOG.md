# Changelog

All notable changes to this project are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: [SemVer](https://semver.org).

## [Unreleased]

## [1.1.0] - 2026-10-19

Same features as 2.1.0, ported to the 1.x line (Laravel 5.8 to 8, PHP 7.1+).

### Added
- `php artisan nightguardian:setup [--url=] [--token=] [--no-test]`: connects an installation in one step. It asks for what is
  missing (the token with hidden input), checks that the token starts with `ng_` and that the URL is `https://` (plain `http://`
  only warns), writes `NIGHTGUARDIAN_URL` and `NIGHTGUARDIAN_TOKEN` to the `.env` without touching the rest of the file, its
  permissions or its owner (missing keys are appended), caches the configuration again when it was cached and sends a test
  event. It never prints the token, is idempotent and exits with an error code when anything fails.
- Every event is tagged with `host`, the host of `APP_URL`, jobs, scheduler and logs included, so apps with many installations
  can share one collector project and still see where a bug happens. `NIGHTGUARDIAN_HOST_TAG=false` turns it off; a `host`
  tag set by the app (configuration or per event) is respected. `nightguardian:status` shows the setting.
- Documentation "One app, many installations" (README, README.es, `docs/configuration.md`): shared token vs one token per
  installation, when to use each and a loop to set up many installations on one server.

## [1.0.2] - 2026-10-18

Same fix as 2.0.2, ported to the 1.x line.

### Fixed
- The SDK now writes `storage/nightguardian/.gitignore` (`*` and `!.gitignore`) the first time it creates that folder, so the spool,
  lock, statistics and release cache can no longer be committed by accident. Existing folders are left untouched: add
  `/storage/nightguardian/` to your `.gitignore` if the files are already tracked (`git rm -r --cached storage/nightguardian`).

## [1.0.1] - 2026-10-18

Same fixes as 2.0.1, ported to the 1.x line.

### Fixed
- The `nightguardian:*` Artisan commands are registered in every context, not only in a console, so `Artisan::call()` works
  from a web request, a deploy hook or a job.
- A failed scheduled task no longer produces a second, redundant exception event ("Scheduled command [...] failed with exit
  code" reported by Laravel in the parent process) when its `schedule_failed` event was already sent (Laravel 8.x and later,
  where Laravel dispatches `ScheduledTaskFailed`).
- Exception events carry `request.status_code` (HTTP exception code or 500, then the status of the response actually sent).

## [1.0.0] - 2026-10-18

First stable release of the 1.x line (Laravel 5.8–8, PHP 7.1+): the 2.x code base converted to PHP 7.1 syntax.

Differences from 2.x (see `docs/compatibility.md`): PHP `^7.1.3|^8.0` and `illuminate/* ^5.8|^6|^7|^8` instead of
PHP 8.1+ and Laravel 9–13; `Support\ObjectMap` instead of `WeakMap`; a handler decorator that declares `Exception $e` for
PHP 7.1 (Laravel 5.8/6); listeners registered through the container; the CI runs the full Pest suite on Laravel 7 and 8
(PHP 7.4–8.1) and a PHPUnit smoke suite on Laravel 5.8 and 6 (PHP 7.1–7.4). The entries below describe the 2.x
development history this line was ported from.

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
