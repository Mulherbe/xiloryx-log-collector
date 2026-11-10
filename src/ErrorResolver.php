<?php

namespace Xiloryx\LogCollector;

/**
 * Marks one of the project's errors as fixed on Xiloryx Log (POST {endpoint}/{id}/resolve).
 * Plain cURL, like the error reports. The API key is sent as a header and never returned or printed.
 */
class ErrorResolver
{
    /** Short messages for the answers of Log, keyed by HTTP status */
    public const MESSAGES = [
        200 => 'Error #%d is marked as fixed.',
        401 => 'Refused: the server API key (XILORYX_LOG_API_KEY) is missing or wrong.',
        404 => 'Error #%d does not exist in this project.',
        409 => 'Error #%d is ignored: change its status in the dashboard.',
        422 => 'Refused: the note is too long (1000 characters max).',
        429 => 'Too many requests: try again in a minute.',
    ];

    public function __construct(
        private string $endpoint,
        private string $apiKey,
        private int $timeout = 10,
    ) {}

    public function url(int $id): string
    {
        return rtrim($this->endpoint, '/') . '/' . $id . '/resolve';
    }

    /**
     * @return array{status: int, message: string} status 0 = no answer (network)
     */
    public function resolve(int $id, ?string $note = null): array
    {
        $ch = curl_init($this->url($id));

        if ($ch === false) {
            return ['status' => 0, 'message' => 'cURL could not start.'];
        }

        $note = $note !== null ? trim($note) : '';
        $body = json_encode($note !== '' ? ['note' => $note] : new \stdClass(), JSON_UNESCAPED_UNICODE);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'X-API-KEY: ' . $this->apiKey,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $answer = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($answer === false || $status === 0) {
            return ['status' => 0, 'message' => 'No answer from Xiloryx Log: ' . curl_error($ch)];
        }

        $message = isset(self::MESSAGES[$status])
            ? sprintf(self::MESSAGES[$status], $id)
            : 'Unexpected answer from Xiloryx Log (HTTP ' . $status . ').';

        return ['status' => $status, 'message' => $message];
    }
}
