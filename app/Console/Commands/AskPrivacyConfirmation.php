<?php

namespace App\Console\Commands;

use App\Privacy\PrivacyConfirmationRequester;
use App\Privacy\PrivacyNotConfigured;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `php artisan privacy:ask-confirmation {email} {kind}` (ACC-4d): a thin entry to PrivacyConfirmationRequester so the flow can be tried before the admin button exists (ACC-4e), and for
 * ops. `kind` is `export` or `erase`. It prints the request id and its status; never the link, never the data.
 */
class AskPrivacyConfirmation extends Command
{
    protected $signature = 'privacy:ask-confirmation {email : the address on record} {kind : export or erase}';

    protected $description = 'Mail a person the link that confirms a privacy request is theirs (nothing is exported or erased)';

    public function handle(PrivacyConfirmationRequester $requester): int
    {
        try {
            $request = $requester->request((string) $this->argument('email'), (string) $this->argument('kind'), null);
        } catch (InvalidArgumentException|PrivacyNotConfigured $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Request id: '.$request->getKey());
        $this->line('Status: '.$request->status);

        return self::SUCCESS;
    }
}
