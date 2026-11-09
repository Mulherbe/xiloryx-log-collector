<?php

/**
 * context.release: XILORYX_LOG_RELEASE when set, otherwise the short git commit read
 * from .git (branch, packed ref, detached HEAD), otherwise nothing.
 * Plain PHP: composer install, then php tests/release-test.php — exit code 0 = all passed.
 */

require __DIR__ . '/../vendor/autoload.php';

$GLOBALS['config'] = ['app.env' => 'testing'];
$GLOBALS['base'] = sys_get_temp_dir() . '/xlx-release-' . getmypid();

function config(string $key, $default = null)
{
    return $GLOBALS['config'][$key] ?? $default;
}
function base_path(string $path = ''): string
{
    return $GLOBALS['base'] . ($path !== '' ? '/' . $path : '');
}
function app()
{
    return new class {
        public function bound(string $id): bool
        {
            return false;
        }
    };
}

use Xiloryx\LogCollector\XiloryxLogHandler;

final class InspectableHandler extends XiloryxLogHandler
{
    public function context(Throwable $e): array
    {
        return $this->getContext($e);
    }

    public static function forget(): void
    {
        static::$gitReleaseRead = false;
        static::$gitRelease = null;
    }
}

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . PHP_EOL;
    $failures += $ok ? 0 : 1;
}

function release(): ?string
{
    InspectableHandler::forget();

    return (new InspectableHandler())->context(new RuntimeException('boom'))['release'] ?? null;
}

$git = $GLOBALS['base'] . '/.git';
@mkdir($git . '/refs/heads', 0777, true);
$sha = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

check('no .git, no setting: no release sent', release() === null);

file_put_contents($git . '/HEAD', "ref: refs/heads/main\n");
file_put_contents($git . '/refs/heads/main', $sha . "\n");
check('branch: short commit', release() === 'a1b2c3d');

unlink($git . '/refs/heads/main');
file_put_contents($git . '/packed-refs', "# pack-refs with: peeled fully-peeled sorted\n{$sha} refs/heads/main\n");
check('packed ref: short commit', release() === 'a1b2c3d');

file_put_contents($git . '/HEAD', 'ffffffffffffffffffffffffffffffffffffffff');
check('detached HEAD: short commit', release() === 'fffffff');

file_put_contents($git . '/HEAD', "garbage\n");
check('unreadable HEAD: no release', release() === null);

$GLOBALS['config']['xiloryx-log.release'] = '  v2.3.0  ';
check('XILORYX_LOG_RELEASE wins', release() === 'v2.3.0');

$GLOBALS['config']['xiloryx-log.release'] = str_repeat('x', 100);
check('cut to 64 characters', strlen((string) release()) === 64);

array_map('unlink', array_filter(glob($git . '/{,*/,*/*/}*', GLOB_BRACE) ?: [], 'is_file'));

exit($failures === 0 ? 0 : 1);
