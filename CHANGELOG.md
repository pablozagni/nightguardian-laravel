# Changelog

All notable changes to this project are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: [SemVer](https://semver.org).

## [Unreleased]

### Added
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
  Â±5 lines of code context for the first 30 in-app frames, first 50 + last 50 frames of very deep stacks, `previous` chain.
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
