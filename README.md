# Xiloryx Log Collector

Laravel package for sending errors to [Xiloryx Log](https://log.xiloryx.fr) — real-time error monitoring for Laravel applications.

## Installation

```bash
composer require xiloryx/log-collector
```

That's it. The package auto-registers itself via Laravel's package discovery.

## Configuration

### 1. Add your API key

Add the following to your `.env` file:

```env
XILORYX_LOG_API_KEY=your_project_api_key_here
```

You can find your API key in your project settings on [log.xiloryx.fr](https://log.xiloryx.fr).

### 2. (Optional) Publish the config file

```bash
php artisan vendor:publish --tag=xiloryx-log-config
```

This gives you access to the full config at `config/xiloryx-log.php`.

## Available environment variables

| Variable | Default | Description |
|---|---|---|
| `XILORYX_LOG_API_KEY` | `null` | **Required.** Your project API key |
| `XILORYX_LOG_ENABLED` | `true` | Enable/disable error reporting |
| `XILORYX_LOG_HEARTBEAT` | `false` | Enable request counting (premium) |

## Features

### Error reporting

All unhandled exceptions are automatically captured and sent to your dashboard — no code changes required. The package hooks into Laravel's exception handler on boot.

Each error includes:
- Exception class, message, file, and line (messages over 500 characters are truncated; the full text is kept in `context.full_message`)
- Full stack trace
- Request URL, method, and IP — sensitive query-string values (`token`, `signature`, `password`, `key`, `code`…) are replaced with `[FILTERED]`; adjust the list with `redact_query_params` in the config
- Authenticated user ID (if available)
- PHP version and environment

### Project Health (premium)

When `XILORYX_LOG_HEARTBEAT=true`, the package counts requests in a small lock-protected file in the system temp directory (no database, no cache). The count is sent to Xiloryx Log every 50 requests, or on the first request once an hour has passed since the last send (so quiet sites still show up every day), from the `terminate()` phase, after the response has been sent.

Run the package tests with `php tests/heartbeat-test.php` (plain PHP, no dependency) and, after `composer install`, `php tests/sql-values-test.php` and `php tests/fatal-error-test.php`.

This powers the **Project Health** dashboard: total requests, total errors, and error rate over 30 days.

## Silent by design

The package will **never** throw an exception in your application:
- Every failure is caught and silently ignored
- Heartbeat runs in the `terminate()` phase, after the response is sent

- During HTTP requests, error reports are sent in the `terminate()` phase too, after the response has been sent, so users never wait on the monitoring API. Fatal errors (memory exhausted, timeout…) are the exception: the process is dying and never reaches that phase, so they are sent right away
- In console commands and queue workers, reports are sent immediately (1-second connect timeout, 2-second total timeout)
- If Xiloryx Log is down, errors out or rate-limits you (HTTP 429), the package stops sending for a cooldown (60 seconds, or the API's `Retry-After`, up to 10 minutes), shared by all workers through a small file in the system temp directory. Only the first failing call pays the timeout.

SQL errors (`QueryException`) are sent without the values of the query: the SQL keeps its `?` placeholders, the database host is left out, and values quoted by PostgreSQL or MySQL in their message (duplicate key, failing row, invalid input) are replaced with `?`. Other exception messages are sent as-is.

## Requirements

- PHP 8.1 or higher, with the `curl`, `json` and `mbstring` extensions
- Laravel 10, 11, 12 or 13

## Support

For support: contact@xiloryx.com
