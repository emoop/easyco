<?php

namespace Tests\Concerns;

use EasyCo\Order\IdentityKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ACC-4e-1 tests: orders and accounts written straight into their tables with the identity keys the checkout would derive (email_key, phone_key, name_key), so a list of thousands is cheap.
 * The client / transaction rows the foreign keys point to are not what these tests are about (the precedent of PruneUnverifiedAccountsTest): the checks are switched off around the insert only.
 */
trait InsertsCustomerOrders
{
    private int $orderSeq = 0;

    /**
     * @param  array<string, mixed>  $o  overrides: email, first, last, phone, status, total (minor), placed_at, account_id, consent, consent_text, currency
     */
    private function insertOrder(array $o = []): int
    {
        return (int) $this->insertOrders([$o])[0];
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @return list<int> the ids
     */
    private function insertOrders(array $orders): array
    {
        $rows = [];

        foreach ($orders as $o) {
            $email = array_key_exists('email', $o) ? $o['email'] : 'person'.(++$this->orderSeq).'@example.com';
            $first = $o['first'] ?? 'Ivan';
            $last = $o['last'] ?? 'Ivanov';
            $phone = array_key_exists('phone', $o) ? $o['phone'] : '+359888'.str_pad((string) (100000 + $this->orderSeq), 6, '0', STR_PAD_LEFT);
            $total = $o['total'] ?? 1000;

            $rows[] = [
                'client_id' => 1, 'transaction_id' => 1,
                'email' => $email, 'email_key' => array_key_exists('email_key', $o) ? $o['email_key'] : IdentityKeys::email($email),
                'currency' => $o['currency'] ?? 'EUR',
                'subtotal_minor' => $total, 'discount_minor' => 0, 'shipping_minor' => 0, 'total_minor' => $total,
                'status' => $o['status'] ?? 'placed',
                'placed_at' => $o['placed_at'] ?? now()->subMinutes(10_000 - count($rows))->toDateTimeString(),
                'delivery_type' => 'street_address',
                'phone' => $phone, 'phone_key' => array_key_exists('phone_key', $o) ? $o['phone_key'] : IdentityKeys::phone($phone),
                'recipient_first_name' => $first, 'recipient_last_name' => $last,
                'name_key' => array_key_exists('name_key', $o) ? $o['name_key'] : IdentityKeys::name($first, $last),
                'account_id' => $o['account_id'] ?? null,
                'marketing_consent' => $o['consent'] ?? false, 'marketing_consent_text' => ! empty($o['consent']) ? ($o['consent_text'] ?? 'I agree') : null,
                'anonymised_at' => $o['anonymised_at'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }

        Schema::disableForeignKeyConstraints();
        $first = null;

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('orders')->insert($chunk);
            $first ??= (int) DB::table('orders')->max('id') - count($chunk) + 1;
        }

        Schema::enableForeignKeyConstraints();

        $last = (int) DB::table('orders')->max('id');

        return range($last - count($rows) + 1, $last);
    }

    private function insertAccount(string $email, bool $deleted = false): int
    {
        return (int) DB::table('accounts')->insertGetId(['email' => $email, 'password' => 'x', 'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null]);
    }
}
