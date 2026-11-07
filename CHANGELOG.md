# Changelog

## [1.3.1] - 2026-11-07

### Changed
- Laravel 13 support: `illuminate/support`, `illuminate/http` and `illuminate/routing` now accept `^10.0|^11.0|^12.0|^13.0`.
- PHP constraint simplified from `^8.1|^8.2|^8.3` to the equivalent `^8.1`.

No code changes. Smoke-tested on Laravel 10.50, 11.57, 12.71 and 13.40 (PHP 8.4): provider loading, config merge, `_xiloryx-log/ping` route, heartbeat middleware registration on `web`/`api`, and exception reporting via `ExceptionHandler::reportable()`.

## [1.3.0]

- Rename xyloryx → xiloryx.
