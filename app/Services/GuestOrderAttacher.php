<?php

namespace App\Services;

use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Extensibility\Hook;
use EasyCo\Order\IdentityKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ACC-2: guest orders are attached to an account BY EMAIL. Every order with `account_id IS NULL` whose email key (`orders.email_key`: the customer's email
 * trimmed and lower-cased, written by the order repository, indexed `ord_email_key_index`) equals the account's own email gets `account_id` = this account. An order of another
 * account is never moved (the update is conditional on `account_id IS NULL`, so even a race cannot move it). Only a VERIFIED, not deleted account attaches anything: the
 * mailbox must be proven first.
 *
 * Triggers: the first verification of the email (verify link, or the password reset that verifies an unverified email), a confirmed email change (the NEW address),
 * a guest placement with the email of a verified account, and the `accounts:attach-guest-orders` command. In chunks of CHUNK ids; idempotent (a second run finds nothing).
 *
 * attach() NEVER throws (a trigger must not turn into a 500): a failure is logged with the account id and the count so far only (no email), and the next trigger
 * or the command retries. The hook `account.orders_attached` `(string $accountId, int $count)` is fired once, after the writes, only when something was attached.
 */
final class GuestOrderAttacher
{
    public const CHUNK = 200;

    public function attach(AccountModel $account): int
    {
        $count = 0;

        try {
            if ($account->email_verified_at === null || $account->trashed()) {
                return 0;
            }

            $key = IdentityKeys::email($account->email);

            if ($key === null) {
                return 0;
            }

            do {
                $ids = DB::table('orders')->whereNull('account_id')->where('email_key', $key)->orderBy('id')->limit(self::CHUNK)->pluck('id');
                $moved = $ids->isEmpty() ? 0 : DB::table('orders')->whereIn('id', $ids)->whereNull('account_id')->update(['account_id' => $account->getKey()]);
                $count += $moved;
            } while ($ids->count() === self::CHUNK && $moved > 0);
        } catch (Throwable $e) {
            Log::warning('account.orders_attach_failed', ['account_id' => $account->getKey(), 'attached_so_far' => $count, 'exception' => $e::class]);
        }

        if ($count > 0) {
            try {
                Hook::fire('account.orders_attached', (string) $account->getKey(), $count);
            } catch (Throwable $e) {
                Log::warning('account.orders_attached_hook_failed', ['account_id' => $account->getKey(), 'exception' => $e::class]);
            }
        }

        return $count;
    }

    /** a guest placement: when a verified account owns the order's email, the same rule runs for that account (the order itself and any older guest order of the address) */
    public function afterGuestPlacement(string $email): int
    {
        try {
            $account = AccountModel::query()->where('email', mb_strtolower(trim($email)))->whereNotNull('email_verified_at')->first();
        } catch (Throwable $e) {
            Log::warning('account.orders_attach_lookup_failed', ['exception' => $e::class]);

            return 0;
        }

        return $account === null ? 0 : $this->attach($account);
    }
}
