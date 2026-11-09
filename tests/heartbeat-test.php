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

    public function hit(): void
    {
        $this->incrementAndMaybeSend('https://example.invalid/api/heartbeat', 'test-key-' . getmypid());
    }

    public function file(): string
    {
        return $this->counterFile('test-key-' . getmypid());
    }

    protected function now(): int
    {
        return $this->clock;
    }

    protected function sendWithCurl(string $endpoint, string $apiKey, int $count): void
    {
        $this->sent[] = $count;
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

@unlink($hb->file());
echo $failures === 0 ? "All passed\n" : "$failures failed\n";
exit($failures === 0 ? 0 : 1);
