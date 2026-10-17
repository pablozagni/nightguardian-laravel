# Compatibility matrix

| SDK line | Branch | Laravel | PHP | Testbench |
|---|---|---|---|---|
| 2.x | `main` | 9, 10, 11, 12, 13 | 8.1 – 8.4 | 7, 8, 9, 10, 11* |
| 1.x | `1.x` | 5.8, 6, 7, 8 | 7.1.3 – 8.1 | 3.8, 4, 5, 6 |

\* Verify the Testbench major for Laravel 13 when building (check orchestra/testbench releases).

## CI matrix (GitHub Actions)

2.x: L9/PHP8.1, L10/PHP8.1+8.2, L11/PHP8.2+8.3, L12/PHP8.3+8.4, L13/PHP8.3+8.4.
1.x: L5.8/PHP7.1+7.3, L6/PHP7.2+7.4, L7/PHP7.4, L8/PHP7.4+8.0+8.1.
Use `--prefer-lowest` on one job per line.

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
