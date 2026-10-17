# 🌙 NightGuardian for Laravel

Official Laravel SDK for [NightGuardian](https://github.com/pablozagni/nightguardian) — self-hosted,
open source error & health monitoring.

[Español](README.es.md) · [Configuration](docs/configuration.md) · [How it works](docs/design.md) · [Compatibility](docs/compatibility.md)

- **Laravel 5.8 → 13.** One package name, two release lines (1.x and 2.x); Composer picks the right one.
- **Zero dependencies** beyond Laravel itself (uses ext-curl). No Guzzle version conflicts.
- **Never slows down or breaks your app.** Sends after the response, 2 s timeout, circuit breaker,
  on-disk queue when the collector is unreachable. No queue worker or cron required.
- **Friendly error page** with a reference code and an optional "what were you doing?" box.
- **Private by default.** Passwords, tokens, cookies and auth headers never leave your server.

## Install

```bash
composer require nightguardian/laravel
```

```dotenv
NIGHTGUARDIAN_URL=https://nightguardian.example.com
NIGHTGUARDIAN_TOKEN=          # create it in your collector: Project → Tokens
```

```bash
php artisan nightguardian:test
```

Laravel's package auto-discovery registers everything. Publishing the config is optional:
`php artisan vendor:publish --tag=nightguardian-config`.

## What gets captured

| | Default |
|---|---|
| Unhandled exceptions | ✅ |
| `Log::error()` and above | ✅ (`NIGHTGUARDIAN_LOG_LEVEL`) |
| Failed queued jobs | ✅ |
| Failed scheduled tasks | ✅ |
| Slow requests (> 2 s) | ✅ (`NIGHTGUARDIAN_SLOW_REQUEST_MS`, 0 = off) |
| Slow queries (> 1 s) | ✅ (`NIGHTGUARDIAN_SLOW_QUERY_MS`, 0 = off) |
| Deploy markers | `php artisan nightguardian:deploy` in your deploy script |

Report manually:

```php
use NightGuardian\Laravel\Facades\NightGuardian;

NightGuardian::report($exception, ['invoice' => $invoice->id]);
NightGuardian::message('Payment gateway answered late', 'warning', ['gateway' => 'mp']);
```

## Commands

| Command | |
|---|---|
| `nightguardian:test` | Sends a test event and shows the collector's answer |
| `nightguardian:status` | Config check, spool size, circuit breaker state |
| `nightguardian:flush` | Sends everything waiting in the spool now |
| `nightguardian:deploy` | Records a deploy (release, commit, branch) |

## Versions

| Laravel | PHP | SDK line |
|---|---|---|
| 9 – 13 | 8.1+ | `^2.0` |
| 5.8 – 8 | 7.1.3 – 8.1 | `^1.0` |

## Contributing

PRs welcome! Read [CONTRIBUTING.md](CONTRIBUTING.md). The wire protocol lives in the collector repo:
[protocol v1](https://github.com/pablozagni/nightguardian/blob/main/docs/protocol/v1.md).

MIT © Pablo Zagni and contributors.
