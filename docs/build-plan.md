# Build plan — Laravel SDK

Prerequisite: collector stage C3 (ingest API) finished, so the SDK can be tested end-to-end against
`http://collector.nightguardian.test`.

## Stage L0 — Repository bootstrap
`git init -b main`, `.gitignore` (`/vendor`, `composer.lock`, `.phpunit.cache`, `.idea`, `.vscode`),
remote `https://github.com/pablozagni/nightguardian-laravel.git`, commit docs, push.

## Stage L1 — Package skeleton (2.x)
- `composer.json`: name `nightguardian/laravel`, PHP `^8.1`, `illuminate/support|contracts|http|log|queue|console`
  `^9.0|^10.0|^11.0|^12.0|^13.0`, dev: Testbench, Pest, Pint, PHPStan; auto-discovery provider + facade alias;
  `branch-alias dev-main → 2.x-dev`; scripts `test`, `lint`, `analyse`.
- Service provider, config file (all keys from `docs/configuration.md`), facade, inactive mode when URL/token missing.
- GitHub Actions matrix for 2.x (see `docs/compatibility.md`).
- Tests: provider boots on every Laravel version in the matrix; inactive mode.

## Stage L2 — Event building & privacy
- `EventFactory`, `StackTrace` (in_app detection, relative paths, ±5 lines context for in-app frames, limits),
  `RequestContext`, `UserContext`, `RuntimeContext`, `Reference`, `Release`, `Scrubber`.
- Output validated against `../collector/docs/protocol/event.v1.schema.json` in tests (use a small JSON-schema
  validator as **dev** dependency only).
- Tests: scrubbing table (keys, values, headers), frame limits, reference format, release detection.

## Stage L3 — Capture
- `LogListener` (exceptions + logs, level threshold, ignore list, dedupe, re-entrancy guard).
- `NightGuardian::report()`, `message()`, `flush()`.
- `QueueListener` (JobFailed), `ScheduleListener`, `SlowRequestMiddleware` (pushed globally), `SlowQueryListener`.
- Tests per capture source and per Laravel version quirk listed in `docs/compatibility.md`.

## Stage L4 — Transport
- `Buffer`, `CurlTransport` (gzip, timeouts, response parsing per protocol §5), `Spool`, `CircuitBreaker`, `Dispatcher`.
- Flush points: `terminating`, `JobProcessed`/`JobFailed`, `CommandFinished`, shutdown fallback.
- Commands `nightguardian:test`, `:status`, `:flush`, `:deploy`.
- Tests: fake transport + a local PHP built-in server for real HTTP cases; all failure modes; drain lock;
  bounds; breaker timings (use a clock abstraction).
- Manual E2E (document result in PR): against the local collector, `php artisan nightguardian:test` from a
  Testbench app (`vendor/bin/testbench nightguardian:test`).

## Stage L5 — Error page & feedback
- `ExceptionHandlerDecorator`, views, translations, signed feedback route, thanks page.
- Tests: page only when debug=false + HTML + 500; JSON untouched except header; signature/expiry; rate limit;
  "sent" vs "recorded" messages; decorator never breaks the original response.

## Stage L6 — 1.x line (Laravel 5.8 – 8)
- `git worktree add ../laravel-1x -b 1.x` from `main` at the end of L5.
- Port to PHP 7.1 syntax; constraints `php ^7.1.3|^8.0`, `illuminate/* ^5.8|^6.0|^7.0|^8.0`; Testbench 3.8–6.
- 1.x CI matrix. Resolve version quirks (scheduler failure event, handler decorator) with tests or documented
  limitations in `docs/compatibility.md`.
- Push branch `1.x`.

## Stage L7 — Release
- README badges, `CHANGELOG` 2.0.0 and 1.0.0 entries, tags `v2.0.0` (main) and `v1.0.0` (1.x).
- Register on Packagist (manual step by the maintainer) with vendor `nightguardian`; enable the GitHub hook.
