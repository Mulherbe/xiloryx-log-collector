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
            $payload = [
                'message' => $this->formatMessage($exception),
                'file' => $exception->getFile() ?: 'unknown',
                'line' => max(1, (int) $exception->getLine()),
                'stacktrace' => $exception->getTraceAsString() ?: '(no stack trace)',
                'context' => $this->getContext($exception),
            ];

            // Use native cURL instead of Laravel Http to avoid cache/redis dependencies
            $this->sendWithCurl($endpoint, $apiKey, $payload);

        } catch (\Throwable $e) {
            // Fail silently - NEVER break the application if monitoring is down
            // Optionally log to local error log in dev mode
            if (config('app.debug', false)) {
                error_log('Xiloryx Log: Failed to report error - ' . $e->getMessage());
            }
        }
    }

    /**
     * Build a message the API accepts: never empty, at most 500 characters.
     * Empty messages fall back to the exception class; long ones (e.g. SQL
     * errors) are truncated — the full text stays in context.full_message.
     */
    protected function formatMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        if ($message === '') {
            return get_class($exception);
        }

        if (mb_strlen($message) > static::MAX_MESSAGE_LENGTH) {
            return mb_substr($message, 0, static::MAX_MESSAGE_LENGTH - 1) . '…';
        }

        return $message;
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
        ]);

        curl_exec($ch);
        // No curl_close(): it has no effect since PHP 8.0 and is deprecated in 8.5.
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

        // Keep the untruncated message when it was shortened for the API
        if (mb_strlen($exception->getMessage()) > static::MAX_MESSAGE_LENGTH) {
            $context['full_message'] = $exception->getMessage();
        }

        // Add request information if available
        if (app()->bound('request')) {
            $request = request();
            $context['url'] = $request->fullUrl();
            $context['method'] = $request->method();
            $context['ip'] = $request->ip();
            
            // Add user information if authenticated
            if (function_exists('auth') && auth()->check()) {
                $context['user_id'] = auth()->id();
            }
        }

        return $context;
    }
}
