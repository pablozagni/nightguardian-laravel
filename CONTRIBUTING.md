# Contributing to nightguardian/laravel

Thanks! General guidelines (code of conduct, commit style, workflow) are in the
[collector's CONTRIBUTING.md](https://github.com/pablozagni/nightguardian/blob/main/CONTRIBUTING.md).

## Branches

| Branch | Line | Laravel | PHP syntax allowed |
|---|---|---|---|
| `main` | 2.x | 9 – 13 | PHP 8.1 (enums, readonly, typed props, `match`, named args) |
| `1.x` | 1.x | 5.8 – 8 | **PHP 7.1** (no typed properties, no arrow functions, no `match`, no union types, no `?->`) |

Fixes usually land in `main` first and are backported to `1.x` in a separate PR.

## Local setup

```bash
composer install
composer test      # Pest + Orchestra Testbench
composer lint      # Pint
composer analyse   # PHPStan
```

CI runs the full Laravel × PHP matrix (see `docs/compatibility.md`).

## Golden rules

1. The SDK must **never** throw into, slow down, or recurse inside the host app. Every public entry point
   is wrapped and tested for "collector down", "disk full", "storage not writable".
2. No new runtime dependencies (only `illuminate/*` and PHP extensions).
3. Follow the [protocol](https://github.com/pablozagni/nightguardian/blob/main/docs/protocol/v1.md) exactly.
