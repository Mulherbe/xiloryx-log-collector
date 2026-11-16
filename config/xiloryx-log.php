<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Xiloryx Log Monitoring Enabled
    |--------------------------------------------------------------------------
    |
    | This option controls whether errors should be sent to Xiloryx Log.
    | You can disable it in development or for debugging purposes.
    |
    */

    'enabled' => env('XILORYX_LOG_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Xiloryx Log Endpoint
    |--------------------------------------------------------------------------
    |
    | The endpoint URL of the Xiloryx Log monitoring platform.
    | This is pre-configured to point to https://log.xiloryx.fr.
    |
    */

    'endpoint' => 'https://log.xiloryx.fr/api/errors',

    /*
    |--------------------------------------------------------------------------
    | Xiloryx Log API Key
    |--------------------------------------------------------------------------
    |
    | Your project's unique API key from Xiloryx Log.
    | You can find this in your project settings.
    |
    */

    'api_key' => env('XILORYX_LOG_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Release (version of the site)
    |--------------------------------------------------------------------------
    |
    | Sent with each error as context.release: Log shows the version in which
    | an error first appeared. Empty = the short git commit of the deployed
    | code, read from .git when it is there.
    |
    */

    'release' => env('XILORYX_LOG_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | Heartbeat (Project Health)
    |--------------------------------------------------------------------------
    |
    | Tracks total request count per project. Batches 50 requests before
    | sending to avoid spamming the API. Uses a file-based counter in the
    | system temp directory (no DB, no cache dependencies).
    |
    | Works reliably across multiple PHP-FPM workers using file locking.
    | Runs in terminate phase (non-blocking).
    |
    */

    'heartbeat_enabled' => env('XILORYX_LOG_HEARTBEAT', false),

    'heartbeat_endpoint' => 'https://log.xiloryx.fr/api/heartbeat',

    /*
    | Request detail (1.8.0): with the count, one line per request (method, route pattern, status,
    | duration, IP ending in .0, user agent), shown on the "Requêtes" page of Log for 7 days.
    | Never the query string nor the full IP. Set to false to send only the count.
    */
    'request_detail' => env('XILORYX_LOG_REQUEST_DETAIL', true),

    /*
    | Environment sent with the heartbeat (1.9.0): Log shows a test site apart from the production
    | when both share a key. Defaults to APP_ENV (production, staging, local...).
    */
    'environment' => env('XILORYX_LOG_ENVIRONMENT'),

    /*
    |--------------------------------------------------------------------------
    | Redacted Query Parameters
    |--------------------------------------------------------------------------
    |
    | Query-string parameters whose values are replaced with [FILTERED] in the
    | URL sent with each error. Matching is case-insensitive and partial
    | ("token" also matches "access_token", "reset_token"…).
    |
    */

    'redact_query_params' => [
        'token', 'signature', 'password', 'passwd', 'secret', 'key',
        'auth', 'code', 'session', 'sig', 'jwt', 'otp',
    ],

];
