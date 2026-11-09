<?php

namespace Xiloryx\LogCollector;

use Throwable;

class XiloryxLogHandler
{
    /**
     * Max message length accepted by the Xiloryx Log API.
     */
    protected const MAX_MESSAGE_LENGTH = 500;

    /**
     * Report an exception to Xiloryx Log.
     * Uses native cURL to be completely independent from Laravel's cache/redis configuration.
     */
    public function report(Throwable $exception): void
    {
        // Skip if not enabled
        if (!config('xiloryx-log.enabled', true)) {
            return;
        }

        $endpoint = config('xiloryx-log.endpoint');
        $apiKey = config('xiloryx-log.api_key');

        // Skip if configuration is missing
        if (empty($endpoint) || empty($apiKey)) {
            return;
        }

        try {
            // API down or rate-limiting us: don't make this request pay the timeout
            if ($this->breaker($apiKey)->isOpen()) {
                return;
            }

            $payload = [
                'message' => $this->formatMessage($exception),
                'file' => $exception->getFile() ?: 'unknown',
                'line' => max(1, (int) $exception->getLine()),
                'stacktrace' => $exception->getTraceAsString() ?: '(no stack trace)',
                'context' => $this->getContext($exception),
            ];

            // Use native cURL instead of Laravel Http to avoid cache/redis dependencies
            $send = fn () => $this->sendWithCurl($endpoint, $apiKey, $payload);

            // A fatal error is reported from PHP's shutdown, once the process is
            // already dying: the terminate phase will never come, send now.
            if ($exception instanceof \Symfony\Component\ErrorHandler\Error\FatalError) {
                $send();
            } else {
                $this->dispatch($send);
            }

        } catch (\Throwable $e) {
            // Fail silently - NEVER break the application if monitoring is down
            // Optionally log to local error log in dev mode
            if (config('app.debug', false)) {
                error_log('Xiloryx Log: Failed to report error - ' . $e->getMessage());
            }
        }
    }

    /**
     * During an HTTP request, send after the response has been flushed to the
     * client (terminate phase, after fastcgi_finish_request under PHP-FPM) so
     * users never wait on the monitoring API. In console/queue workers there
     * is no response to protect, so send immediately.
     */
    protected function dispatch(\Closure $send): void
    {
        $app = app();

        if (! $app->runningInConsole() && method_exists($app, 'terminating')) {
            $app->terminating(function () use ($send) {
                try {
                    $send();
                } catch (\Throwable $e) {
                    // never break the application
                }
            });

            return;
        }

        $send();
    }

    protected function breaker(string $apiKey): CircuitBreaker
    {
        return new CircuitBreaker($apiKey);
    }

    /**
     * Build a message the API accepts: never empty, at most 500 characters.
     * Empty messages fall back to the exception class; long ones (e.g. SQL
     * errors) are truncated — the full text stays in context.full_message.
     */
    protected function formatMessage(Throwable $exception): string
    {
        $message = trim($this->messageOf($exception));

        if ($message === '') {
            return get_class($exception);
        }

        if (mb_strlen($message) > static::MAX_MESSAGE_LENGTH) {
            return mb_substr($message, 0, static::MAX_MESSAGE_LENGTH - 1) . '…';
        }

        return $message;
    }

    /**
     * The exception message, without the values of SQL queries.
     *
     * Laravel's QueryException message embeds the bound values (emails, tokens,
     * personal data…) in the SQL, plus the database host. It is rebuilt from
     * the driver message and the SQL with its "?" placeholders. Values that the
     * database itself quotes in its message (duplicate key, invalid input…)
     * are masked too.
     */
    protected function messageOf(Throwable $exception): string
    {
        if (! $exception instanceof \Illuminate\Database\QueryException) {
            return $exception->getMessage();
        }

        $previous = $exception->getPrevious();
        $driverMessage = $previous ? $this->maskDriverValues($previous->getMessage()) : '';

        return trim($driverMessage . ' (Connection: ' . $exception->getConnectionName() . ', SQL: ' . $exception->getSql() . ')');
    }

    /**
     * Mask the values PostgreSQL and MySQL quote in their error messages.
     */
    protected function maskDriverValues(string $message): string
    {
        $patterns = [
            // PostgreSQL: Key (email)=(a@b.c) already exists / is not present in table…
            '/(Key \([^)]*\)=)\(.*?\)(?= (?:already exists|is not present|conflicts with|is still referenced))/s' => '$1(?)',
            // PostgreSQL: Failing row contains (1, a@b.c, …).
            '/(Failing row contains )\(.*\)/' => '$1(?)',
            // PostgreSQL: invalid input syntax for type integer: "abc"
            '/(invalid input (?:syntax|value) for [^:\n]*: )".*?"/' => '$1"?"',
            // MySQL: Duplicate entry 'a@b.c' for key …, Incorrect integer value: 'abc' for column …
            "/(Duplicate entry |value: )'.*?'(?= for )/s" => "\$1'?'",
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $message) ?? $message;
    }

    /**
     * Encode the payload as JSON, replacing invalid UTF-8 instead of failing.
     */
    protected function encodePayload(array $payload): ?string
    {
        $json = json_encode(
            $payload,
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES
        );

        return $json === false ? null : $json;
    }

    /**
     * Send error data using native cURL (no Laravel dependencies).
     */
    protected function sendWithCurl(string $endpoint, string $apiKey, array $payload): void
    {
        $body = $this->encodePayload($payload);

        if ($body === null) {
            return;
        }

        $ch = curl_init($endpoint);

        if ($ch === false) {
            return; // cURL init failed, fail silently
        }

        $headers = '';

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_HTTPHEADER => [
                'X-API-KEY: ' . $apiKey,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers) {
                $headers .= $line;

                return strlen($line);
            },
        ]);

        curl_exec($ch);
        $this->breaker($apiKey)->record($ch, $headers);
        // No curl_close(): it has no effect since PHP 8.0 and is deprecated in 8.5.
    }

    /**
     * Mask sensitive query-string values (reset tokens, signed URL
     * signatures, API keys…) before the URL leaves the application.
     */
    protected function redactUrl(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return $url;
        }

        $sensitive = array_map('strtolower', (array) config('xiloryx-log.redact_query_params', []));

        $parts = array_map(function (string $pair) use ($sensitive) {
            [$key] = explode('=', $pair, 2);
            $name = strtolower(urldecode($key)); // includes nested keys: user[api_key]

            foreach ($sensitive as $needle) {
                if ($needle !== '' && str_contains($name, $needle)) {
                    return $key . '=[FILTERED]';
                }
            }

            return $pair;
        }, explode('&', $query));

        return str_replace('?' . $query, '?' . implode('&', $parts), $url);
    }

    /**
     * Get contextual information about the error.
     */
    protected function getContext(Throwable $exception): array
    {
        $context = [
            'exception_class' => get_class($exception),
            'environment' => config('app.env'),
            'php_version' => PHP_VERSION,
        ];

        // Version of the site: Log keeps the one in which the error first appeared
        $release = $this->release();
        if ($release !== null) {
            $context['release'] = $release;
        }

        // Keep the untruncated message when it was shortened for the API
        $message = $this->messageOf($exception);
        if (mb_strlen($message) > static::MAX_MESSAGE_LENGTH) {
            $context['full_message'] = $message;
        }

        // Add request information if available
        if (app()->bound('request')) {
            $request = request();
            $context['url'] = $this->redactUrl($request->fullUrl());
            $context['method'] = $request->method();
            $context['ip'] = $request->ip();
            
            // Add user information if authenticated
            if (function_exists('auth') && auth()->check()) {
                $context['user_id'] = auth()->id();
            }
        }

        return $context;
    }

    /** Release detected from git, read once per process (null = not found). */
    protected static ?string $gitRelease = null;

    protected static bool $gitReleaseRead = false;

    /**
     * Version of the site sent as `context.release`: XILORYX_LOG_RELEASE when set,
     * otherwise the short git commit of the deployed code (read from .git, no shell),
     * otherwise nothing.
     */
    protected function release(): ?string
    {
        $configured = config('xiloryx-log.release');
        if (is_string($configured) && trim($configured) !== '') {
            return mb_substr(trim($configured), 0, 64);
        }

        if (! static::$gitReleaseRead) {
            static::$gitReleaseRead = true;
            static::$gitRelease = function_exists('base_path') ? static::gitCommit(base_path('.git')) : null;
        }

        return static::$gitRelease;
    }

    /**
     * Short commit of the checked-out code, or null. Handles a branch (loose or packed ref)
     * and a detached HEAD. Never throws.
     */
    public static function gitCommit(string $gitDir): ?string
    {
        try {
            $head = @file_get_contents($gitDir . '/HEAD');
            if (! is_string($head)) {
                return null;
            }
            $head = trim($head);

            if (str_starts_with($head, 'ref: ')) {
                $ref = substr($head, 5);
                $sha = @file_get_contents($gitDir . '/' . $ref);
                if (! is_string($sha)) {
                    $packed = @file_get_contents($gitDir . '/packed-refs');
                    $sha = null;
                    if (is_string($packed) && preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', $packed, $m)) {
                        $sha = $m[1];
                    }
                }
                $head = trim((string) $sha);
            }

            return preg_match('/^[0-9a-f]{40}$/', $head) ? substr($head, 0, 7) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
