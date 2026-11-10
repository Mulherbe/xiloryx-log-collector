<?php

/**
 * ErrorResolver (xiloryx-log:resolve): URL, key header, note, answers of Log.
 * A local PHP server plays Log. Plain PHP: composer install, then php tests/resolve-test.php
 * — exit code 0 = all passed.
 */

require __DIR__ . '/../vendor/autoload.php';

use Xiloryx\LogCollector\ErrorResolver;

$dir = sys_get_temp_dir() . '/xlx-resolve-' . getmypid();
@mkdir($dir);
$seen = $dir . '/seen.json';

// Fake Log: answers by error number, writes down what it received
file_put_contents($dir . '/router.php', '<?php
$id = (int) (explode("/", trim(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/"))[2] ?? 0);
file_put_contents(' . var_export($seen, true) . ', json_encode([
    "method" => $_SERVER["REQUEST_METHOD"],
    "path" => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH),
    "key" => $_SERVER["HTTP_X_API_KEY"] ?? null,
    "body" => file_get_contents("php://input"),
]));
$codes = [1 => 200, 2 => 404, 3 => 409, 4 => 401, 5 => 422, 6 => 500];
http_response_code($codes[$id] ?? 200);
header("Content-Type: application/json");
echo "{}";
');

$port = 18000 + getmypid() % 1000;
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, $dir . '/router.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);
for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . PHP_EOL;
    $failures += $ok ? 0 : 1;
}

$resolver = new ErrorResolver('http://127.0.0.1:' . $port . '/api/errors/', 'server-key-test');

check('url from the endpoint', $resolver->url(203) === 'http://127.0.0.1:' . $port . '/api/errors/203/resolve');

$r = $resolver->resolve(1, '  Fixed in 09a4caf  ');
$got = json_decode((string) file_get_contents($seen), true);
check('200 → fixed message', $r['status'] === 200 && $r['message'] === 'Error #1 is marked as fixed.');
check('POST to /api/errors/1/resolve', $got['method'] === 'POST' && $got['path'] === '/api/errors/1/resolve');
check('server key sent as X-API-KEY', $got['key'] === 'server-key-test');
check('note sent, trimmed', json_decode($got['body'], true) === ['note' => 'Fixed in 09a4caf']);
check('key never in the message', ! str_contains($r['message'], 'server-key-test'));

$resolver->resolve(1);
$got = json_decode((string) file_get_contents($seen), true);
check('no note → empty object', $got['body'] === '{}');

check('404 → not in this project', $resolver->resolve(2)['message'] === 'Error #2 does not exist in this project.');
check('409 → ignored', $resolver->resolve(3)['status'] === 409);
check('401 → key refused', str_contains($resolver->resolve(4)['message'], 'XILORYX_LOG_API_KEY'));
check('422 → note too long', $resolver->resolve(5)['status'] === 422);
check('other status → shown', $resolver->resolve(6)['message'] === 'Unexpected answer from Xiloryx Log (HTTP 500).');

$down = (new ErrorResolver('http://127.0.0.1:1/api/errors', 'k', 2))->resolve(1);
check('no server → status 0', $down['status'] === 0 && str_starts_with($down['message'], 'No answer'));

proc_terminate($server);
array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);

echo $failures === 0 ? 'All passed' . PHP_EOL : $failures . ' failed' . PHP_EOL;
exit($failures === 0 ? 0 : 1);
