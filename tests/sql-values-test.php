<?php

/**
 * SQL errors must not send the values of the query (emails, tokens, personal
 * data) nor the database host. Plain PHP: composer install, then
 * php tests/sql-values-test.php — exit code 0 = all passed.
 */

require __DIR__ . '/../vendor/autoload.php';

// Minimal stand-ins for the Laravel helpers the handler calls
if (! function_exists('config')) {
    function config(string $key, $default = null)
    {
        return ['app.env' => 'testing'][$key] ?? $default;
    }
}
if (! function_exists('app')) {
    function app()
    {
        return new class {
            public function bound(string $id): bool
            {
                return false;
            }
        };
    }
}

use Illuminate\Database\QueryException;
use Xiloryx\LogCollector\XiloryxLogHandler;

final class InspectableHandler extends XiloryxLogHandler
{
    public function message(Throwable $e): string
    {
        return $this->formatMessage($e);
    }

    public function context(Throwable $e): array
    {
        return $this->getContext($e);
    }
}

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . PHP_EOL;
    $failures += $ok ? 0 : 1;
}

/** Everything the handler would send about this exception, as one string. */
function sent(Throwable $e): string
{
    $handler = new InspectableHandler();

    return $handler->message($e) . "\n" . json_encode($handler->context($e), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function queryException(string $driverMessage, string $sql, array $bindings, string $driver = 'pgsql'): QueryException
{
    return new QueryException($driver, $sql, $bindings, new PDOException($driverMessage), [
        'driver' => $driver, 'host' => 'db.internal.example', 'port' => 5432, 'database' => 'shop',
    ]);
}

// 1. Unique violation (PostgreSQL): no bound value, no value quoted by the database, no host
$e = queryException(
    "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_email_unique\"\nDETAIL:  Key (email)=(jane.doe@example.com) already exists.",
    'insert into "users" ("email", "remember_token") values (?, ?)',
    ['jane.doe@example.com', 'tok_9f8e7d6c5b4a']
);
check('laravel puts the values in the raw message (precondition)', str_contains($e->getMessage(), 'tok_9f8e7d6c5b4a'));
$out = sent($e);
check('pgsql: bound values are not sent', ! str_contains($out, 'jane.doe@example.com') && ! str_contains($out, 'tok_9f8e7d6c5b4a'));
check('pgsql: the database host is not sent', ! str_contains($out, 'db.internal.example'));
check('pgsql: SQL kept with its placeholders', str_contains($out, 'values (?, ?)'));
check('pgsql: constraint and column kept, value masked', str_contains($out, 'users_email_unique') && str_contains($out, 'Key (email)=(?) already exists'));
check('pgsql: connection name kept', str_contains($out, 'Connection: pgsql'));

// 2. Long query (> 500 chars): the full message in the context is clean too
$columns = implode(', ', array_map(fn ($i) => "\"column_$i\"", range(1, 60)));
$e = queryException(
    'SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column "x" violates not-null constraint' . "\nDETAIL:  Failing row contains (42, secret-iban-FR7612345, null).",
    "insert into \"orders\" ($columns) values (" . implode(', ', array_fill(0, 60, '?')) . ')',
    array_fill(0, 60, 'secret-iban-FR7612345')
);
$handler = new InspectableHandler();
$context = $handler->context($e);
check('long query: full_message present', isset($context['full_message']));
check('long query: no value in message nor full_message', ! str_contains(sent($e), 'secret-iban-FR7612345'));
check('long query: failing row masked', str_contains($context['full_message'] ?? '', 'Failing row contains (?)'));

// 3. Invalid input (PostgreSQL) quotes the value
$out = sent(queryException(
    'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type integer: "4111111111111111"',
    'select * from "cards" where "id" = ?',
    ['4111111111111111']
));
check('pgsql invalid input: value masked', ! str_contains($out, '4111111111111111') && str_contains($out, 'for type integer: "?"'));

// 4. MySQL duplicate entry
$out = sent(queryException(
    "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'jane.doe@example.com' for key 'users.users_email_unique'",
    'insert into `users` (`email`) values (?)',
    ['jane.doe@example.com'],
    'mysql'
));
check('mysql duplicate entry: value masked, key kept', ! str_contains($out, 'jane.doe@example.com') && str_contains($out, "Duplicate entry '?' for key 'users.users_email_unique'"));

// 5. Other exceptions are untouched
check('non-SQL exception message unchanged', (new InspectableHandler())->message(new RuntimeException('Order 42 not found')) === 'Order 42 not found');

echo $failures === 0 ? "All passed\n" : "$failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
