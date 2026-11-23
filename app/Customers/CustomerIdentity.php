<?php

namespace App\Customers;

use EasyCo\Account\Persistence\Eloquent\AccountModel;
use Illuminate\Support\Facades\DB;

/**
 * ACC-4e-1: WHO a customer page is about, derived from its URL key. The URL never carries the email: `o-{orderId}` (any order of the person: the page takes the email key from it) or
 * `a-{accountId}` (an account that has no order). An unknown key, a malformed one, an order that has no email key (anonymised at retention, ACC-4b) and a deleted account all give null:
 * the page answers 404, the same for every one of them.
 */
final class CustomerIdentity
{
    public function __construct(
        public readonly string $key,
        public readonly string $emailKey,
        public readonly ?int $accountId,
    ) {
    }

    public static function resolve(string $key): ?self
    {
        if (preg_match('/^([oa])-([1-9][0-9]{0,17})$/', $key, $m) !== 1) {
            return null;
        }

        if ($m[1] === 'o') {
            $emailKey = DB::table('orders')->where('id', (int) $m[2])->whereNotNull('email_key')->whereNull('anonymised_at')->value('email_key');

            if (! is_string($emailKey) || $emailKey === '') {
                return null;
            }

            $accountId = AccountModel::query()->where('email', $emailKey)->value('id');

            return new self($key, $emailKey, $accountId === null ? null : (int) $accountId);
        }

        $account = AccountModel::query()->whereKey((int) $m[2])->first(['id', 'email']);

        return $account === null ? null : new self($key, (string) $account->email, (int) $account->getKey());
    }

    /** The URL key of a list row. */
    public static function keyOf(CustomerRow $row): string
    {
        return $row->row_key;
    }
}
