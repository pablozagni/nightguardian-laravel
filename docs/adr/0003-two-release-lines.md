# ADR 0003 — One package, two release lines

- Status: accepted · Date: 2026-10-17

## Context
Supporting Laravel 5.8 requires PHP 7.1 syntax; modern Laravel benefits from PHP 8.1 features.

## Decision
Same package `nightguardian/laravel`. `main` = 2.x (Laravel 9–13, PHP 8.1+). Branch `1.x` (Laravel 5.8–8,
PHP 7.1.3+). Composer constraints make the right line install automatically. Protocol parity is required;
1.x receives bug fixes and protocol updates, new features are optional.

## Consequences
+ One name for users. − Two code bases to maintain; backports via separate PRs.
