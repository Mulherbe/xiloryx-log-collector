<?php

/**
 * A fatal error that kills the PHP process (memory exhausted…) during an HTTP
 * request must still be reported. Plain PHP: composer install, then
 * php tests/fatal-error-test.php — exit code 0 = all passed.
 *
 * The test runs a child PHP process that exhausts its memory. Like Laravel's
 * HandleExceptions::handleShutdown(), the child turns the fatal error into a
 * Symfony FatalError at shutdown and hands it to the handler. The kernel's
 * terminate phase never runs in that case.
 */

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\ErrorHandler\Error\FatalError;
use Xiloryx\LogCollector\XiloryxLogHandler;

/** Laravel application stand-in: an HTTP request whose terminate phase is run on demand. */
final class FakeApp
{
    /** @var Closure[] */
    public array $terminating = [];

    public function runningInConsole(): bool
    {
        return false;
    }

    public function terminating(Closure $callback): void
    {
        $this->terminating[] = $callback;
    }

    public function bound(string $id): bool
    {
        return false;
    }

    public function terminate(): void
    {
        foreach ($this->terminating as $callback) {
            $callback();
        }
    }
}

function app(): FakeApp
{
    return $GLOBALS['fakeApp'] ??= new FakeApp();
}

function config(string $key, $default = null)
{
    return [
        'xiloryx-log.enabled' => true,
        'xiloryx-log.endpoint' => 'https://example.invalid/api/errors',
        'xiloryx-log.api_key' => 'fatal-test-' . getmypid(),
        'app.env' => 'testing',
    ][$key] ?? $default;
}

/** Writes what would be sent to a file instead of calling the API. */
final class RecordingHandler extends XiloryxLogHandler
{
    public function __construct(private string $outFile)
    {
    }

    protected function sendWithCurl(string $endpoint, string $apiKey, array $payload): void
    {
        file_put_contents($this->outFile, json_encode($payload) . "\n", FILE_APPEND);
    }
}

// ----- Child process: dies of a fatal error -----
if (($argv[1] ?? '') === 'child') {
    $handler = new RecordingHandler($argv[2]);
    $reserved = str_repeat('x', 32768); // like Laravel's reserved memory

    register_shutdown_function(function () use ($handler, &$reserved) {
        $reserved = null;
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
            $handler->report(new FatalError($error['message'], 0, $error, 0));
        }
    });

    $chunks = [];
    while (true) {
        $chunks[] = str_repeat('x', 1024 * 1024);
    }
}

// ----- Parent: the tests -----
$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . PHP_EOL;
    $failures += $ok ? 0 : 1;
}

// 1. Memory exhausted: the process dies, the error is still sent
$out = tempnam(sys_get_temp_dir(), 'xiloryx-fatal-');
exec(sprintf('%s -d memory_limit=64M -d display_errors=0 %s child %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(__FILE__), escapeshellarg($out)), $output, $exitCode);
$sent = array_values(array_filter(explode("\n", (string) file_get_contents($out))));
@unlink($out);
check('child process died of the fatal error (precondition)', $exitCode !== 0);
check('fatal error reported once', count($sent) === 1);
$payload = json_decode($sent[0] ?? '{}', true);
check('report carries the fatal message', str_contains($payload['message'] ?? '', 'Allowed memory size'));
check('report carries the exception class', ($payload['context']['exception_class'] ?? '') === FatalError::class);

// 2. Ordinary errors during a request are still sent after the response (terminate phase)
$out = tempnam(sys_get_temp_dir(), 'xiloryx-fatal-');
$handler = new RecordingHandler($out);
$handler->report(new RuntimeException('Order not found'));
check('ordinary error: nothing sent before terminate', (string) file_get_contents($out) === '');
app()->terminate();
check('ordinary error: sent in the terminate phase', str_contains((string) file_get_contents($out), 'Order not found'));
@unlink($out);

echo $failures === 0 ? "All passed\n" : "$failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
