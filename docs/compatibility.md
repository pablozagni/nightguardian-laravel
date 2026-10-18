# Compatibility matrix

| SDK line | Branch | Laravel | PHP | Testbench |
|---|---|---|---|---|
| 2.x | `main` | 9, 10, 11, 12, 13 | 8.1 – 8.4 | 7, 8, 9, 10, 11* |
| 1.x | `1.x` | 5.8, 6, 7, 8 | 7.1.3 – 8.1 | 3.8, 4, 5, 6 |

\* Verified on Packagist: `orchestra/testbench` 11.x requires `laravel/framework ^13` and PHP ^8.3
(7 → L9, 8 → L10, 9 → L11, 10 → L12, 11 → L13).

Dev tooling resolves per job: Pest 1 on L9, Pest 2 on L10, Pest 3/4 on L11+ (tests therefore avoid `pest()`
helpers newer than v1). CI turns off Composer's `audit.block-insecure` because end-of-life Laravel lines have
published advisories.

## CI matrix (GitHub Actions)

2.x (`main`): L9/PHP8.1, L10/PHP8.1+8.2, L11/PHP8.2+8.3, L12/PHP8.3+8.4, L13/PHP8.3+8.4; `--prefer-lowest` on L9.

1.x (branch `1.x`), three kinds of jobs:

| Job | Matrix | What it checks |
|---|---|---|
| `syntax` | PHP 7.1, 7.2, 7.4 | `php -l` on every file of `src`, `config`, `routes`, `resources`, `tests` with the real interpreters |
| `tests` (Pest, full suite) | L6/PHP7.4 (stable and lowest), L7/PHP7.4, L8/PHP7.4, 8.0, 8.1 | everything: ~360 tests |
| `smoke` (PHPUnit) | L5.8/PHP7.1 (stable and lowest), L5.8/PHP7.3, L6/PHP7.2 | boot, capture, scrubbing, delivery, spooling, error page, `ObjectMap` |

Why a separate smoke suite: Pest 1 needs PHP 7.3+ and PHPUnit 9, and Testbench 3.8 (Laravel 5.8) needs PHPUnit 7.5/8,
so the Pest suite cannot run on Laravel 5.8 or on PHP 7.1/7.2. The smoke suite (`tests/Smoke`, plain PHPUnit) covers
the paths that matter most there. `lint` (Pint) and `analyse` (PHPStan level 5, no Larastan) run on PHP 8.3 with Laravel 8.

The 1.x code is the 2.x code converted to PHP 7.1 syntax (typed properties, arrow functions, `match`, enums, promoted
constructors and nullsafe calls removed). Container-resolved methods keep their type hints.

### 1.x differences from 2.x

- `WeakMap` is PHP 8.0+: `Support\ObjectMap` uses it when available and a bounded `spl_object_hash` map otherwise.
- `PHP_OS_FAMILY`, `spl_object_id` and `str_*` helpers have PHP 7.1 fallbacks.
- Spool and breaker live in `storage/nightguardian` (`storagePath()` takes no argument before Laravel 8.x).
- Views and translations publish to `resources/views/vendor/nightguardian` and `resources/lang/vendor/nightguardian`.
- Tests run Artisan commands through `tests/CommandProbe.php` (`expectsOutputToContain()` exists from Laravel 9).

## Version-sensitive spots (must have tests)

| Feature | Note |
|---|---|
| `MessageLogged` | 5.4+ ✅ all supported versions |
| `ScheduledTaskFailed` event | Added in later 8.x. On older versions use `$event->onFailure()` hooking via `Schedule` macro, or skip with a documented limitation |
| `JobFailed` | ✅ all |
| `QueryExecuted->time` | ✅ all (ms float) |
| Exception handler render | 5.8–10 `App\Exceptions\Handler`; 11+ `bootstrap/app.php` `withExceptions`. Decorator via container `extend()` works on both — verify |
| Middleware registration | 5.8–10 `Kernel::pushMiddleware`; 11+ same method on `Illuminate\Foundation\Http\Kernel` — verify |
| `spl_object_id` | PHP 7.2+; use `spl_object_hash` on 1.x |
| Lang path | Package uses `loadTranslationsFrom` — fine on all versions |
