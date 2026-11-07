# Changelog

## [1.4.0] - 2026-11-07

### Fixed
- Errors with an empty message or a message longer than 500 characters (e.g. SQL errors) were rejected by the API (422) and silently lost. Messages are now truncated to 500 characters (full text kept in `context.full_message`); empty messages fall back to the exception class name.
- Errors containing invalid UTF-8 were sent with an empty body because `json_encode()` failed. Invalid sequences are now substituted.
- Heartbeat: the network call no longer runs while holding the counter file lock, so other workers no longer wait up to 3 s in `terminate()` when the API is slow.
- Removed `curl_close()` calls, which emit a deprecation notice on PHP 8.5 (no-op since PHP 8.0).
- `/_xiloryx-log/ping` reported a hard-coded version (`1.2.5`); it now reads the installed version from Composer.

### Changed
- `context.exception_class` is now sent with every error.
- Ping route compares API keys with `hash_equals()` (constant time).
- `composer.json` now declares the `ext-curl`, `ext-json` and `ext-mbstring` requirements.
- README and config comments corrected (heartbeat storage, batch size, requirements, Laravel 13).

## [1.3.1] - 2026-11-07

### Changed
- Laravel 13 support: `illuminate/support`, `illuminate/http` and `illuminate/routing` now accept `^10.0|^11.0|^12.0|^13.0`.
- PHP constraint simplified from `^8.1|^8.2|^8.3` to the equivalent `^8.1`.

No code changes. Smoke-tested on Laravel 10.50, 11.57, 12.71 and 13.40 (PHP 8.4): provider loading, config merge, `_xiloryx-log/ping` route, heartbeat middleware registration on `web`/`api`, and exception reporting via `ExceptionHandler::reportable()`.

## [1.3.0]

- Rename xyloryx → xiloryx.
