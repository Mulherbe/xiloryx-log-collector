<?php

namespace Xiloryx\LogCollector;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class XiloryxLogServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/xiloryx-log.php',
            'xiloryx-log'
        );

        $this->app->singleton(XiloryxLogHandler::class, function ($app) {
            return new XiloryxLogHandler();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/xiloryx-log.php' => config_path('xiloryx-log.php'),
        ], 'xiloryx-log-config');

        $this->registerRoutes();
        $this->registerHeartbeatMiddleware();
        $this->registerExceptionReporting();
    }

    /**
     * Register the ping route for connection testing.
     */
    protected function registerRoutes(): void
    {
        Route::get('_xiloryx-log/ping', function () {
            // Only respond to requests from the Xiloryx Log platform
            $origin = request()->header('X-XILORYX-ORIGIN');
            if ($origin !== 'https://log.xiloryx.fr') {
                abort(403, 'Unauthorized origin');
            }

            // Verify the API key matches the configured one
            $requestKey = request()->header('X-API-KEY');
            $configuredKey = config('xiloryx-log.api_key');

            if (empty($configuredKey) || ! is_string($requestKey) || ! hash_equals((string) $configuredKey, $requestKey)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'API key mismatch',
                ], 401);
            }

            return response()->json([
                'status' => 'ok',
                'package' => 'xiloryx/log-collector',
                'version' => static::packageVersion(),
                'enabled' => config('xiloryx-log.enabled', false),
                'heartbeat' => config('xiloryx-log.heartbeat_enabled', false),
            ]);
        });
    }

    /**
     * Installed package version, read from Composer metadata.
     */
    protected static function packageVersion(): string
    {
        try {
            if (class_exists(\Composer\InstalledVersions::class)) {
                return \Composer\InstalledVersions::getPrettyVersion('xiloryx/log-collector') ?? 'unknown';
            }
        } catch (\Throwable $e) {
            // Package not registered with Composer (e.g. path install edge cases)
        }

        return 'unknown';
    }

    /**
     * Register the heartbeat middleware for request counting.
     * Uses the terminate phase so it doesn't block the response.
     * Registered on both web and api groups to cover all traffic.
     */
    protected function registerHeartbeatMiddleware(): void
    {
        if (config('xiloryx-log.heartbeat_enabled', false)) {
            /** @var \Illuminate\Routing\Router $router */
            $router = $this->app['router'];
            $router->pushMiddlewareToGroup('web', XiloryxHeartbeatMiddleware::class);
            $router->pushMiddlewareToGroup('api', XiloryxHeartbeatMiddleware::class);
        }
    }

    /**
     * Auto-wire exception reporting into Laravel's exception handler.
     * Works on Laravel 10, 11, 12 and 13 — zero config required from the client.
     */
    protected function registerExceptionReporting(): void
    {
        if (!config('xiloryx-log.enabled', true)) {
            return;
        }

        $handler = $this->app->make(ExceptionHandler::class);

        if (method_exists($handler, 'reportable')) {
            $handler->reportable(function (\Throwable $e) {
                $this->app->make(XiloryxLogHandler::class)->report($e);
            });
        }
    }
}
