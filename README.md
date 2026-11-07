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
- Request URL, method, and IP
- Authenticated user ID (if available)
- PHP version and environment

### Project Health (premium)

When `XILORYX_LOG_HEARTBEAT=true`, the package counts requests in a small lock-protected file in the system temp directory (no database, no cache). Every 50 requests, the count is sent to Xiloryx Log from the `terminate()` phase, after the response has been sent.

This powers the **Project Health** dashboard: total requests, total errors, and error rate over 30 days.

## Silent by design

The package will **never** throw an exception in your application:
- Every failure is caught and silently ignored
- Heartbeat runs in the `terminate()` phase, after the response is sent

Error reports are sent synchronously when an exception is reported, with a 1-second connect timeout and a 2-second total timeout. If Xiloryx Log is unreachable, a failing request can take up to that long before it completes.

## Requirements

- PHP 8.1 or higher, with the `curl`, `json` and `mbstring` extensions
- Laravel 10, 11, 12 or 13

## Support

For support: contact@xiloryx.com
