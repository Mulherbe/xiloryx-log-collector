# Changelog

## [1.5.1] - 2026-11-09

### Changed
- Heartbeat: the request count is also sent on the first request once an hour has passed since the last send, not only every 50 requests. Quiet sites kept their count for days and lost it on every redeploy (the counter lives in the container's temp directory); now at most an hour of counts can be lost. Same endpoint and payload. Counter files written by older versions are read as-is.

### Added
- `tests/heartbeat-test.php`: plain-PHP tests of the heartbeat counter (batches, hourly send, old counter files).

## [1.5.0] - 2026-11-07

### Added
- Circuit breaker: when the API times out, returns 5xx or 429, error reports and heartbeats are skipped for a cooldown (60 s, or `Retry-After`, max 10 min) shared across workers via a temp file. A down monitoring server no longer adds up to 3 s to every failing request.
- New `redact_query_params` config option: sensitive query-string values (tokens, signatures, passwords, keys, codes…) are replaced with `[FILTERED]` in `context.url`.

### Changed
- During HTTP requests, error reports are now sent in the terminate phase, after the response has been flushed to the client. Console commands and queue workers still send immediately.

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
