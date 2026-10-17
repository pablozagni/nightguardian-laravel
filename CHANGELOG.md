# Changelog

All notable changes to this project are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: [SemVer](https://semver.org).

## [Unreleased]

### Added
- Project documentation, architecture, protocol v1 and build plan.
- Package skeleton for the 2.x line: `composer.json` (PHP ^8.1, Laravel 9–13, no runtime dependencies beyond
  `illuminate/*`, `ext-curl`, `ext-json`, `ext-zlib`), auto-discovered service provider, `NightGuardian` facade,
  publishable `config/nightguardian.php` with every option from `docs/configuration.md`.
- Inactive mode: without URL and token (or with `NIGHTGUARDIAN_ENABLED=false`, or without `ext-curl`) the SDK does
  nothing and can say why.
- GitHub Actions: lint, static analysis and a test matrix for Laravel 9–13 on PHP 8.1–8.4 (one `--prefer-lowest` job).
- Pest + Orchestra Testbench test suite, Pint and Larastan (level 6).
