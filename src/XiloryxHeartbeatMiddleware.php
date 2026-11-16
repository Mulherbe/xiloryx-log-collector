<?php

namespace Xiloryx\LogCollector;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class XiloryxHeartbeatMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Send a heartbeat after batching requests to track total request count.
     * Uses a file-based counter with flock() for synchronization across workers.
     * Batches of 50 requests before sending to avoid spamming the API.
     * This runs in the terminate phase so it does NOT block the response.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (!config('xiloryx-log.heartbeat_enabled', false)) {
            return;
        }

        // Skip heartbeat for internal monitoring endpoints to avoid infinite loops
        $path = $request->path();
        if ($this->shouldSkipHeartbeat($path)) {
            return;
        }

        $apiKey = config('xiloryx-log.api_key');
        $endpoint = config('xiloryx-log.heartbeat_endpoint');

        if (empty($apiKey) || empty($endpoint)) {
            return;
        }

        try {
            $line = config('xiloryx-log.request_detail', true) ? $this->requestLine($request, $response) : null;
            $this->incrementAndMaybeSend($endpoint, $apiKey, $line);
        } catch (\Throwable $e) {
            // Fail silently — NEVER break the application for monitoring
        }
    }

    /**
     * Check if we should skip heartbeat for this request path.
     * Prevents infinite loops when monitoring endpoints call themselves.
     */
    protected function shouldSkipHeartbeat(string $path): bool
    {
        $excludedPaths = [
            'api/heartbeat',           // Heartbeat endpoint itself
            'api/errors',              // Error ingestion endpoint
            '_xiloryx-log/ping',       // Connection test endpoint
        ];

        foreach ($excludedPaths as $excluded) {
            if (str_contains($path, $excluded)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One line of request detail (1.8.0): no personal data leaves the app. Route pattern
     * (`products/{product}`) when Laravel matched one, otherwise the path with ids, tokens and
     * e-mails replaced by {id}; never the query string; IP with its end set to zero.
     *
     * @return array<string, mixed>
     */
    protected function requestLine(Request $request, Response $response): array
    {
        $route = $request->route();
        $uri = is_object($route) && method_exists($route, 'uri') ? (string) $route->uri() : static::cleanPath($request->path());
        $start = defined('LARAVEL_START') ? (float) LARAVEL_START : (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));
        $ua = (string) $request->userAgent();

        return [
            'method' => $request->method(),
            'route' => '/' . ltrim($uri, '/'),
            'status' => $response->getStatusCode(),
            'ms' => max(0, (int) round((microtime(true) - $start) * 1000)),
            'ip' => static::maskIp($request->ip()),
            'ua' => $ua === '' ? null : substr($ua, 0, 255),
            'at' => $this->now(),
        ];
    }

    /** Path segments that look like an id, a UUID, a token or an e-mail become {id} */
    public static function cleanPath(string $path): string
    {
        $segments = array_map(static function (string $segment): string {
            $private = preg_match('/^\d+$/', $segment)
                || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment)
                || (preg_match('/^[0-9a-z_-]{20,}$/i', $segment) && preg_match('/\d/', $segment))
                || str_contains($segment, '@')
                || str_contains($segment, '%40');

            return $private ? '{id}' : $segment;
        }, explode('/', trim($path, '/')));

        return substr(implode('/', $segments), 0, 255);
    }

    /** IPv4: 1.2.3.0; IPv6: first 3 groups only; anything else: null */
    public static function maskIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (string) preg_replace('/\.\d+$/', '.0', $ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return (string) inet_ntop(substr((string) inet_pton($ip), 0, 6) . str_repeat("\0", 10));
        }

        return null;
    }

    /** Lines kept between two sends at most (the API takes 500 per call) */
    protected const MAX_LINES = 500;

    /** Send as soon as this many requests are counted */
    protected const BATCH_SIZE = 50;

    /** ...or on the first request once this many seconds have passed since the last send */
    protected const MAX_INTERVAL = 3600;

    /**
     * Increment counter in /tmp and send when the batch is full or the last send is over an hour old.
     * Without the time limit, a quiet site could keep its count for days (and lose it on redeploy).
     * Uses file locking to work reliably across multiple PHP-FPM workers.
     * Stored in /tmp (always writable) with API key hash to avoid conflicts.
     * File content: "<count> <unix time of last send>" (a bare "<count>" from older versions is accepted).
     */
    protected function incrementAndMaybeSend(string $endpoint, string $apiKey, ?array $line = null): void
    {
        // Counter file in /tmp — always writable, unique per project via API key hash
        $counterFile = $this->counterFile($apiKey);

        // Open file for reading and writing, create if doesn't exist
        $fp = @fopen($counterFile, 'c+');

        if ($fp === false) {
            // If we can't open the file, fall back to sending every request
            $this->sendWithCurl($endpoint, $apiKey, 1, $line !== null ? [$line] : []);
            return;
        }

        $countToSend = 0;
        $linesToSend = [];

        // Lock only for the read-modify-write; never hold it during the network call,
        // otherwise every other worker blocks in terminate() while the API is slow.
        if (flock($fp, LOCK_EX)) {
            $now = $this->now();
            $parts = explode(' ', trim((string) stream_get_contents($fp)));
            $newCount = (int) $parts[0] + 1;
            // No time yet (new file or older version): start the clock now
            $lastSent = isset($parts[1]) ? (int) $parts[1] : $now;

            // Request detail, one JSON line per request, in a file next to the counter (same lock)
            $linesFile = $this->counterFile($apiKey) . '.lines';
            if ($line !== null && $newCount <= static::MAX_LINES) {
                @file_put_contents($linesFile, json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            }

            if ($newCount >= static::BATCH_SIZE || $now - $lastSent >= static::MAX_INTERVAL) {
                $countToSend = $newCount;
                $newCount = 0;
                $lastSent = $now;

                $raw = @file_get_contents($linesFile);
                @unlink($linesFile);
                foreach (explode("\n", (string) $raw) as $json) {
                    $decoded = json_decode($json, true);
                    if (is_array($decoded) && count($linesToSend) < static::MAX_LINES) {
                        $linesToSend[] = $decoded;
                    }
                }
            }

            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, $newCount . ' ' . $lastSent);
            fflush($fp);

            flock($fp, LOCK_UN);
        }

        fclose($fp);

        if ($countToSend > 0) {
            $this->sendWithCurl($endpoint, $apiKey, $countToSend, $linesToSend);
        }
    }

    protected function counterFile(string $apiKey): string
    {
        return sys_get_temp_dir() . '/xiloryx_hb_' . md5($apiKey) . '.count';
    }

    protected function now(): int
    {
        return time();
    }

    /**
     * Send heartbeat data using native cURL (no Laravel dependencies).
     */
    protected function sendWithCurl(string $endpoint, string $apiKey, int $count, array $lines = []): void
    {
        $breaker = new CircuitBreaker($apiKey);

        // API down or rate-limiting: drop this batch rather than block a worker
        if ($breaker->isOpen()) {
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
            CURLOPT_POSTFIELDS => json_encode($lines ? ['count' => $count, 'requests' => $lines] : ['count' => $count]),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        curl_exec($ch);
        $breaker->record($ch);
        // No curl_close(): it has no effect since PHP 8.0 and is deprecated in 8.5.
    }
}
