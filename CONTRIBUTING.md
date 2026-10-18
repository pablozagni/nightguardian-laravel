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

### Trying it against a real collector

`testbench.yaml` registers the package, so the Artisan commands work from a clone. With the collector running locally
(`http://collector.nightguardian.test`) and a project token from its panel or seeder:

```powershell
$env:NIGHTGUARDIAN_URL = "http://collector.nightguardian.test"
$env:NIGHTGUARDIAN_TOKEN = "ng_..."
vendor/bin/testbench nightguardian:test
vendor/bin/testbench nightguardian:status
```

The spool and breaker live in `vendor/orchestra/testbench-core/laravel/storage/nightguardian`.

### The suite never touches a real collector

`vendor/bin/pest` must be safe to run anywhere, with any environment:

- Every app under test gets an empty `NIGHTGUARDIAN_URL`/`NIGHTGUARDIAN_TOKEN`, a **fake transport** and a throwaway
  storage directory (spool, breaker), whatever your shell or a `.env` file says (`tests/TestCase.php`).
- A test that needs real HTTP starts PHP's built-in server on `127.0.0.1` itself (`tests/Transport/CurlTransportTest.php`).
  Literal URLs in tests must be fake (`*.test`, `*.invalid`, `example.com`) or loopback.
- `tests/Feature/NoRealNetworkTest.php` **fails** if the environment or a `.env` file the suite could load holds a real
  collector URL/token, unless you asked for E2E on purpose. If it fails, unset the variable (`Remove-Item Env:NIGHTGUARDIAN_URL`).
- Checks against a real collector live in `tests/E2E` and are **skipped by default**. They run only with all three:

```powershell
$env:NIGHTGUARDIAN_E2E = "1"
$env:NIGHTGUARDIAN_E2E_URL = "http://collector.nightguardian.test"
$env:NIGHTGUARDIAN_E2E_TOKEN = "ng_..."     # a token of a throwaway project
vendor/bin/pest tests/E2E
```

## Golden rules

1. The SDK must **never** throw into, slow down, or recurse inside the host app. Every public entry point
   is wrapped and tested for "collector down", "disk full", "storage not writable".
2. No new runtime dependencies (only `illuminate/*` and PHP extensions).
3. Follow the [protocol](https://github.com/pablozagni/nightguardian/blob/main/docs/protocol/v1.md) exactly.
