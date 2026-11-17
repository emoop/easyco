<?php

namespace App\Console\Commands;

use EasyCo\Account\Persistence\Eloquent\AccountModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan accounts:prune-unverified` (ACC-1) — deletes the accounts that were never verified within 7 days: an address that registered and never proved its
 * mailbox must not sit in the table forever (and keep its email reserved). An account that has an ORDER is never deleted (CLAUDE.md rule 4: an order references it),
 * and a verified account never is. Its carts and addresses go with it (their foreign keys cascade). Scheduled DAILY in routes/console.php; only the COUNT is logged.
 */
class PruneUnverifiedAccounts extends Command
{
    public const AFTER_DAYS = 7;

    protected $signature = 'accounts:prune-unverified';

    protected $description = 'Delete accounts that were never verified within 7 days and have no order (only the count is logged)';

    public function handle(): int
    {
        $deleted = 0;

        AccountModel::withTrashed()
            ->whereNull('email_verified_at')
            ->where('created_at', '<', now()->subDays(self::AFTER_DAYS))
            ->whereNotIn('id', DB::table('orders')->whereNotNull('account_id')->select('account_id'))
            ->orderBy('id')
            ->each(function (AccountModel $account) use (&$deleted): void {
                $account->forceDelete();
                $deleted++;
            });

        if ($deleted > 0) {
            Log::info('accounts.unverified_pruned', ['count' => $deleted]);
        }

        $this->info('Pruned '.$deleted.' unverified account(s) older than '.self::AFTER_DAYS.' days.');

        return self::SUCCESS;
    }
}
