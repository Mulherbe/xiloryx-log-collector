# Changelog

## [1.9.0] - 2026-11-16

### Added
- The heartbeat carries the site's environment (`"environment": "staging"`), so Log shows a test site apart from the production when both share a key (« Marie Colibri · test »), and the requests page shows the production by default. Value: `XILORYX_LOG_ENVIRONMENT` when set, otherwise `APP_ENV`; lowercased, letters, digits, `-` and `_` only (20 max), left out otherwise. Older Log versions ignore the field; Log treats a heartbeat without it as production. Nothing to change in the app: `composer update xiloryx/log-collector` is enough.

## [1.8.0] - 2026-11-16

### Added
- Request detail: with the heartbeat count, one line per request (method, route, status, duration, masked IP, user agent, time), shown on the « Requêtes » page of Xiloryx Log for 7 days. Needs Log `4c44679` or later (older Log versions ignore the extra field and keep counting). Nothing private leaves the app: the route is Laravel's pattern (`products/{product}`), or, when no route matched (404), only the first segment of the path (`users/{…}`); ids, UUIDs, tokens, JWTs and e-mails replaced by `{id}`; never the query string; IPv4 ends in `.0`, IPv6 keeps its first 3 groups; user agent cut to 255 characters. Lines wait in a file next to the counter (same lock, at most 500) and go with the next count. `XILORYX_LOG_REQUEST_DETAIL=false` sends the count only, as before.

## [1.7.0] - 2026-11-10

### Added
- `php artisan xiloryx-log:resolve <id> "note"`: marks one of the project's errors as fixed on Xiloryx Log (`POST /api/errors/{id}/resolve`, needs Log `0c63cd7` or later). Uses the configured server key, which is never printed. Only "fixed": an ignored error is refused (409), an error of another project answers 404, and an error that comes back is reopened by Log. Optional note (1000 characters max) kept in the error's history.
- `tests/resolve-test.php`: a local PHP server plays Log (URL, key header, note, every answer, no server).

### Changed
- `illuminate/console` is now required (any Laravel app already has it).

## [1.6.0] - 2026-11-10

### Added
- Each error now carries the version of the site as `context.release`: `XILORYX_LOG_RELEASE` when set, otherwise the short git commit of the deployed code (read from `.git`, once per process, no shell command), otherwise nothing. Log shows the version in which an error first appeared and the last one it was seen in. Same endpoint and payload shape (`context` was already free-form).
- `tests/release-test.php`: setting, branch, packed ref, detached HEAD, unreadable `.git`.

## [1.5.2] - 2026-11-09

### Fixed
- SQL errors sent the values of the failing query (emails, tokens, personal data) and the database host: Laravel's `QueryException` message embeds them. The message is now rebuilt from the driver message and the SQL with its `?` placeholders; values quoted by PostgreSQL/MySQL (duplicate key, failing row, invalid input) are masked. Same for `context.full_message`.
- Fatal errors that kill the process during an HTTP request (memory exhausted, max execution time…) were lost since 1.5.0: the report waited for the terminate phase, which never runs once PHP is shutting down. They are now sent immediately.

### Added
- `tests/fatal-error-test.php`: a child process dies of memory exhaustion during a request; the error must still be sent once.
- `tests/sql-values-test.php` (needs `composer install`: `illuminate/database` is a dev dependency).

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
