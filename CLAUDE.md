# CLAUDE.md — nightguardian/laravel (SDK)

You are building the Laravel SDK of NightGuardian (open source, MIT).
Repo: github.com/pablozagni/nightguardian-laravel. Composer name: `nightguardian/laravel`.
Local path: `C:\laragon\www\nightguardian\laravel` (branch `main`, line 2.x).
The 1.x line lives in the worktree `C:\laragon\www\nightguardian\laravel-1x` (branch `1.x`).

## Read first
1. `../collector/docs/protocol/v1.md` — the wire contract (source of truth; do not deviate)
2. `docs/design.md` 3. `docs/compatibility.md` 4. `docs/configuration.md` 5. `docs/build-plan.md`
   (do ONLY the stage you are asked for) 6. `../collector/docs/security.md` (scrubbing rules)

## Non-negotiable rules
- **Never harm the host app**: no exceptions escape, no recursion, no output, no added latency before
  the response. Every public entry point is wrapped in `try { … } catch (\Throwable $e) { /* count */ }`.
- **No runtime dependencies** other than `illuminate/*` (versions per line) and `ext-curl`, `ext-json`, `ext-zlib`.
  If `ext-curl` is missing the SDK disables itself and `nightguardian:status` says why.
- No use of the app's DB, cache, queue or session in the capture/transport path.
- **Syntax per branch:** `main` PHP 8.1; `1.x` PHP 7.1 (no typed properties, arrow functions, `match`,
  union types, nullsafe operator, `str_contains`, constructor promotion).
- Every feature and every failure mode has tests (Pest + Orchestra Testbench). Simulate collector down,
  timeouts, 401/429/5xx, unwritable storage, full spool.
- Views use inline CSS only; translations `en` and `es`; nothing company-specific.
- English for code/comments/commits; Conventional Commits; keep README/CHANGELOG updated.

## Git
- Commit at the end of each stage and push: `git add -A && git commit -m "<type>: <summary>" && git push`.
- Work on `main` here; 1.x work happens only inside `../laravel-1x`.
- Composer `extra.branch-alias`: `dev-main` → `2.x-dev`; on `1.x` branch no alias needed.

## Developer machine
Windows 11, Laragon, PHP 8.3, Composer. Commands for cmd/PowerShell. The full Laravel × PHP matrix
runs in GitHub Actions; locally run the tests with PHP 8.3 only.
