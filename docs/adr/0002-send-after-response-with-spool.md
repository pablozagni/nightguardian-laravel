# ADR 0002 — Send after the response, spool on failure, no worker required

- Status: accepted · Date: 2026-10-17

## Context
Many target apps have no queue worker or cron. Sending synchronously during the request adds latency;
servers with saturated PHP-FPM pools can't afford workers blocked by a dead collector.

## Decision
Buffer in memory, send in `terminating` (after `fastcgi_finish_request`), short timeouts, circuit breaker,
bounded disk spool drained gradually under a file lock. Optional `nightguardian:flush` for scheduled drains.

## Consequences
+ No infra requirements; user latency unaffected. − A worker stays busy ≤ ~1 s once when the collector
goes down (then the breaker opens). − Events are lost only if the disk spool is full or not writable.
