<?php

namespace Xiloryx\LogCollector;

use Illuminate\Console\Command;

/**
 * php artisan xiloryx-log:resolve 203 "Fixed in 09a4caf"
 * Marks one of this project's errors as fixed on Xiloryx Log, with the key of the project's config
 * (the key is never shown). If the error comes back later, Log reopens it.
 */
class ResolveErrorCommand extends Command
{
    protected $signature = 'xiloryx-log:resolve
        {id : Number of the error in Xiloryx Log}
        {note? : Why it is fixed (commit, explanation), 1000 characters max}';

    protected $description = 'Mark one of this project\'s errors as fixed on Xiloryx Log';

    public function handle(): int
    {
        $id = $this->argument('id');

        if (! is_string($id) || ! ctype_digit($id) || (int) $id < 1) {
            $this->error('The error number must be a positive whole number.');

            return self::INVALID;
        }

        $endpoint = config('xiloryx-log.endpoint');
        $apiKey = config('xiloryx-log.api_key');

        if (empty($endpoint) || empty($apiKey)) {
            $this->error('XILORYX_LOG_API_KEY (or the endpoint) is not configured.');

            return self::FAILURE;
        }

        $note = $this->argument('note');
        $result = (new ErrorResolver((string) $endpoint, (string) $apiKey))
            ->resolve((int) $id, is_string($note) ? $note : null);

        if ($result['status'] === 200) {
            $this->info($result['message']);

            return self::SUCCESS;
        }

        $this->error($result['message']);

        return self::FAILURE;
    }
}
