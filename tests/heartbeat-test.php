<?php

/**
 * Heartbeat counter tests, plain PHP (no dependency): php tests/heartbeat-test.php
 * Exit code 0 = all passed.
 */

require __DIR__ . '/../src/XiloryxHeartbeatMiddleware.php';

use Xiloryx\LogCollector\XiloryxHeartbeatMiddleware;

final class FakeClockHeartbeat extends XiloryxHeartbeatMiddleware
{
    public int $clock = 1_000_000;

    /** @var int[] counts that would have been sent */
    public array $sent = [];

    /** @var array<int, array<int, array<string, mixed>>> request lines sent with each count */
    public array $sentLines = [];

    public function hit(?array $line = null): void
    {
        $this->incrementAndMaybeSend('https://example.invalid/api/heartbeat', 'test-key-' . getmypid(), $line);
    }

    public function file(): string
    {
        return $this->counterFile('test-key-' . getmypid());
    }

    protected function now(): int
    {
        return $this->clock;
    }

    protected function sendWithCurl(string $endpoint, string $apiKey, int $count, array $lines = []): void
    {
        $this->sent[] = $count;
        $this->sentLines[] = $lines;
    }
}

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . PHP_EOL;
    $failures += $ok ? 0 : 1;
}

function fresh(): FakeClockHeartbeat
{
    $hb = new FakeClockHeartbeat();
    @unlink($hb->file());
    return $hb;
}

// 1. A busy site still sends every 50 requests
$hb = fresh();
for ($i = 0; $i < 120; $i++) {
    $hb->hit();
}
check('busy site: a batch every 50 requests', $hb->sent === [50, 50]);

// 2. A quiet site sends on the first request after an hour, with everything counted so far
$hb = fresh();
for ($i = 0; $i < 3; $i++) {
    $hb->hit();
    $hb->clock += 600; // one request every 10 min
}
check('quiet site: nothing sent within the hour', $hb->sent === []);
$hb->clock += 3600;
$hb->hit();
check('quiet site: 4 sent after an hour', $hb->sent === [4]);
$hb->hit();
check('quiet site: clock restarts after a send', $hb->sent === [4]);

// 3. The file of an older version (bare count) is read, and the clock starts then
$hb = fresh();
file_put_contents($hb->file(), '12');
$hb->hit();
check('old file: count kept, nothing sent yet', $hb->sent === [] && trim(file_get_contents($hb->file())) === '13 ' . $hb->clock);
$hb->clock += 3600;
$hb->hit();
check('old file: 14 sent an hour later', $hb->sent === [14]);

// 4. Request detail (1.8.0): the lines go with the count, then the buffer is empty
$hb = fresh();
@unlink($hb->file() . '.lines');
for ($i = 0; $i < 50; $i++) {
    $hb->hit(['method' => 'GET', 'route' => '/products/{id}', 'status' => 200, 'n' => $i]);
}
check('detail: 50 lines sent with the count of 50', $hb->sent === [50] && count($hb->sentLines[0]) === 50
    && $hb->sentLines[0][49]['n'] === 49);
check('detail: buffer emptied after the send', !file_exists($hb->file() . '.lines'));
$hb->hit();
check('detail: a hit without line (detail off) adds no line', !file_exists($hb->file() . '.lines'));

// 5. Nothing private leaves the app
check('cleanPath: ids, uuid, token, e-mail', XiloryxHeartbeatMiddleware::cleanPath('/users/1234/reset/someone@example.com/550e8400-e29b-41d4-a716-446655440000/abcdef1234567890abcdef/edit')
    === 'users/{id}/reset/{id}/{id}/{id}/edit');
check('cleanPath: JWT', XiloryxHeartbeatMiddleware::cleanPath('auth/eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abc') === 'auth/{id}');
check('unmatchedPath: only the first segment', XiloryxHeartbeatMiddleware::unmatchedPath('users/jean-dupont/edit') === 'users/{…}'
    && XiloryxHeartbeatMiddleware::unmatchedPath('wp-login.php') === 'wp-login.php');
check('cleanPath: words kept', XiloryxHeartbeatMiddleware::cleanPath('api/products/search') === 'api/products/search');
check('maskIp: IPv4', XiloryxHeartbeatMiddleware::maskIp('203.0.113.57') === '203.0.113.0');
check('maskIp: IPv6', XiloryxHeartbeatMiddleware::maskIp('2001:db8:85a3:1:2:3:4:5') === '2001:db8:85a3::');
check('maskIp: garbage', XiloryxHeartbeatMiddleware::maskIp('nope') === null && XiloryxHeartbeatMiddleware::maskIp(null) === null);

@unlink($hb->file());
@unlink($hb->file() . '.lines');
echo $failures === 0 ? "All passed\n" : "$failures failed\n";
exit($failures === 0 ? 0 : 1);
