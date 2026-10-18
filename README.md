# 🌙 NightGuardian for Laravel

[![CI](https://github.com/pablozagni/nightguardian-laravel/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/pablozagni/nightguardian-laravel/actions/workflows/ci.yml?query=branch%3Amain) [![Latest version](https://img.shields.io/packagist/v/nightguardian/laravel?label=packagist)](https://packagist.org/packages/nightguardian/laravel) [![PHP](https://img.shields.io/packagist/dependency-v/nightguardian/laravel/php?label=php)](https://packagist.org/packages/nightguardian/laravel) [![License: MIT](https://img.shields.io/github/license/pablozagni/nightguardian-laravel)](LICENSE)

[nightguardian.com.ar](https://nightguardian.com.ar) · [Live demo](https://nightguardian.com.ar/try)

<p align="center"><a href="https://nightguardian.com.ar"><img src="docs/img/error-page-en-dark.png" alt="The friendly error page with a reference code" width="520"></a></p>

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

## Friendly error page

When an unexpected error ends in a **500** on an **HTML** request and `APP_DEBUG=false`, visitors see a small,
self-contained page (inline CSS, no external assets, light and dark mode, English and Spanish) with a
**reference code** like `NG-7F3K-92QD` and an optional "what were you doing?" box. The same code is attached to the
event in your panel, so a user can quote it to support and you find the exact error in seconds.

| Light | Dark |
|---|---|
| ![Error page, light](docs/img/error-page-en-light.png) | ![Error page, dark](docs/img/error-page-en-dark.png) |

- It only replaces the response for errors NightGuardian captured; ignored exceptions, client errors and everything
  else keep your application's own pages. JSON requests are never changed, they only get an
  `X-NightGuardian-Reference` header.
- The comment arrives in the panel as feedback, linked to the original event. The thank-you page tells the truth:
  *sent* when the collector accepted it, *recorded* when it was queued on disk because the collector is unreachable.
- The feedback route has no session, cookies or CSRF token (the session may be what is broken). It is protected by a
  signed, 15-minute form token and a limit of 5 messages per minute per IP.
- Turn it off with `NIGHTGUARDIAN_ERROR_PAGE=false` or hide the box with `NIGHTGUARDIAN_FEEDBACK=false`. Customise the
  texts and the view with `php artisan vendor:publish --tag=nightguardian-views` / `--tag=nightguardian-lang`.

## Performance

NightGuardian never works before your response is ready: events are buffered in memory and sent when the request
terminates, with a 1 s connect and 2 s total timeout. After a failure a circuit breaker stops further attempts for
60 s (doubling up to 15 min) and events wait in an on-disk spool, so a collector outage costs your requests ~0.

One caveat depends on how PHP runs:

- **PHP-FPM (recommended):** the response is flushed to the browser before the SDK sends anything
  (`fastcgi_finish_request`). Visitors never wait, even when the collector is down.
- **`mod_php` (Apache without PHP-FPM, e.g. a default Laragon setup):** PHP cannot release the connection early. The
  first time the collector is down, the browser may wait **up to ~1 s longer** (the connect timeout) before the request
  completes. The circuit breaker then opens and the following requests are not delayed. If that matters, lower
  `NIGHTGUARDIAN_CONNECT_TIMEOUT` or switch to PHP-FPM.

## Versions

| Laravel | PHP | SDK line |
|---|---|---|
| 9 – 13 | 8.1+ | `^2.0` |
| 5.8 – 8 | 7.1.3 – 8.1 | `^1.0` |

## Contributing

PRs welcome! Read [CONTRIBUTING.md](CONTRIBUTING.md). The wire protocol lives in the collector repo:
[protocol v1](https://github.com/pablozagni/nightguardian/blob/main/docs/protocol/v1.md).

MIT © Pablo Zagni and contributors.
