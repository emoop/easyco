<?php

namespace App\Console\Commands;

use App\Privacy\PrivacyConfirmationRequester;
use App\Privacy\PrivacyMailNotQueued;
use App\Privacy\PrivacyNotConfigured;
use App\Privacy\PrivacyRequestAlreadyOpen;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * `php artisan privacy:ask-confirmation {email} {kind} [--resend]` (ACC-4d, ACC-4e-1): a thin entry to PrivacyConfirmationRequester so the flow can be tried and for ops. `kind` is `export` or `erase`.
 * It prints the request id and its status; never the link, never the data.
 *
 * A request of this email and kind that is already waiting is NOT a second row: without `--resend` the command says so (one line, with the end of the link) and fails; with `--resend` it mails a NEW
 * link for the SAME row (the ops twin of «Изпрати отново»; the link still ends where the row ends). A mail that could not be queued is reported as a failure, with the id of the row that was written.
 */
class AskPrivacyConfirmation extends Command
{
    protected $signature = 'privacy:ask-confirmation {email : the address on record} {kind : export or erase} {--resend : when a request is already waiting, mail its link again instead of failing}';

    protected $description = 'Mail a person the link that confirms a privacy request is theirs (nothing is exported or erased)';

    public function handle(PrivacyConfirmationRequester $requester): int
    {
        try {
            try {
                $request = $requester->request((string) $this->argument('email'), (string) $this->argument('kind'), null);
            } catch (PrivacyRequestAlreadyOpen $open) {
                if (! $this->option('resend')) {
                    $until = Carbon::createFromTimestamp($requester->endsAt($open->request))->toDateTimeString();
                    $this->error("A request {$open->request->getKey()} is already waiting for confirmation; its link is valid until {$until}. Run again with --resend to mail the link again.");

                    return self::FAILURE;
                }

                $request = $requester->resend($open->request, null);
            }
        } catch (PrivacyMailNotQueued $e) {
            $this->error("The request {$e->request->getKey()} was written, but the mail could not be queued. Run again with --resend to try again.");

            return self::FAILURE;
        } catch (InvalidArgumentException|PrivacyNotConfigured $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Request id: '.$request->getKey());
        $this->line('Status: '.$request->status);

        return self::SUCCESS;
    }
}
