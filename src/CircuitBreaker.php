<?php

namespace Xiloryx\LogCollector;

/**
 * File-based circuit breaker shared by all PHP workers.
 *
 * When the Xiloryx Log API is down, slow or rate-limiting us, sends are
 * skipped for a cooldown period instead of making every failing request
 * pay the network timeout again.
 */
class CircuitBreaker
{
    public const DEFAULT_COOLDOWN = 60;

    protected const MAX_COOLDOWN = 600;

    public function __construct(protected string $apiKey)
    {
    }

    public function isOpen(): bool
    {
        $until = @file_get_contents($this->file());

        return $until !== false && (int) $until > time();
    }

    /**
     * Inspect a finished cURL handle and open the circuit if the API failed.
     *
     * @param \CurlHandle|resource $ch
     */
    public function record($ch, string|false $responseHeaders = false): void
    {
        if (curl_errno($ch) !== 0) {
            $this->open(self::DEFAULT_COOLDOWN);

            return;
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($status === 429) {
            $this->open($this->retryAfter($responseHeaders) ?? self::DEFAULT_COOLDOWN);
        } elseif ($status >= 500 || $status === 0) {
            $this->open(self::DEFAULT_COOLDOWN);
        }
    }

    public function open(int $seconds): void
    {
        $seconds = max(1, min($seconds, self::MAX_COOLDOWN));

        @file_put_contents($this->file(), (string) (time() + $seconds), LOCK_EX);
    }

    public function reset(): void
    {
        @unlink($this->file());
    }

    protected function retryAfter(string|false $headers): ?int
    {
        if ($headers && preg_match('/^Retry-After:\s*(\d+)/mi', $headers, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    protected function file(): string
    {
        return sys_get_temp_dir() . '/xiloryx_cb_' . md5($this->apiKey) . '.until';
    }
}
