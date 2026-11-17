<?php

namespace App\Console\Commands;

use App\Services\GuestOrderAttacher;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use Illuminate\Console\Command;

/**
 * `php artisan accounts:attach-guest-orders {--account=}` (ACC-2) — attaches the guest orders (`account_id IS NULL`) whose email equals the account's email to that
 * account: ONE account (`--account=ID`) or every VERIFIED account (for the WooCommerce import, which sets `email_verified_at`). The rule is GuestOrderAttacher's
 * (only verified, not deleted accounts; orders of another account never move). Idempotent. It prints COUNTS only: never an email or an id list.
 */
class AttachGuestOrders extends Command
{
    protected $signature = 'accounts:attach-guest-orders {--account= : the id of one account (default: every verified account)}';

    protected $description = 'Attach guest orders to the account with the same email (verified accounts only; prints counts only)';

    public function handle(GuestOrderAttacher $attacher): int
    {
        $option = $this->option('account');
        $query = AccountModel::query()->whereNotNull('email_verified_at')->orderBy('id');

        if ($option !== null && $option !== '') {
            if (! ctype_digit((string) $option)) {
                $this->error('--account must be an account id.');

                return self::FAILURE;
            }

            $query->whereKey((int) $option);
        }

        $accounts = 0;
        $attached = 0;

        $query->each(function (AccountModel $account) use ($attacher, &$accounts, &$attached): void {
            $accounts++;
            $attached += $attacher->attach($account);
        });

        $this->info("Checked {$accounts} verified account(s); attached {$attached} guest order(s).");

        return self::SUCCESS;
    }
}
