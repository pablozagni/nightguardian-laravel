# ADR 0001 — Capture through `MessageLogged`, not by editing the exception handler

- Status: accepted · Date: 2026-10-17

## Context
Apps range from Laravel 5.8 (`App\Exceptions\Handler`) to 13 (`bootstrap/app.php`). Asking users to edit
their handler is error-prone and differs per version.

## Decision
Listen to `Illuminate\Log\Events\MessageLogged`. Reported exceptions arrive with `context['exception']`.
Use a container decorator of `ExceptionHandler` only for the optional error page.

## Consequences
+ Zero-touch install on all versions; respects the app's `dontReport`. + Also captures `Log::error()`.
− If an app removes exceptions from log context or replaces the logger entirely, capture is lost (documented;
manual `NightGuardian::report()` remains).
