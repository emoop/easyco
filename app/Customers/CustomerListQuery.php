<?php

namespace App\Customers;

use App\Services\PhoneNumbers;
use App\Settings\StoreCountry;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\IdentityKeys;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * ACC-4e-1 (acc-4-gdpr-design.md D5, §7): the read model of the admin «Клиенти» list: ONE ROW PER PERSON, accounts and guests together, in ONE statement.
 *
 *  - PERSON = a non-NULL `orders.email_key` (a NULL key is an order anonymised at retention: not listed, ACC-4b), plus every account whose email has no order.
 *  - The aggregate is ONE `GROUP BY email_key` over `orders` (index `ord_email_key_index`): the order count, the total spent and the id of the LATEST order (the highest id). The latest
 *    order is then read by its primary key for the name, the email as typed, the phone and the date. No per-row query anywhere.
 *  - TOTAL SPENT: the orders list defines no revenue, so this is the sum of `total_minor` of the orders that still count: every status EXCEPT `cancelled` and `refunded` (the shop has no
 *    «failed» order status; a refunded order gave the money back). The currency shown is the latest order's (a shop has one currency; a person who ordered in two would show a mixed sum:
 *    reported, not solved here).
 *  - `has_account`: an account (not soft-deleted) with that email exists. An account has no name or phone column: the NAME and the PHONE are the account's preferred saved address, else the latest order
 *    (CustomerContact, BUG-2); a person with neither shows «—».
 *
 * SEARCH is ONE box: the email (substring), the name (every word, through `orders.name_key`, and the account's saved addresses) and the phone (reduced by the same normaliser as `orders.phone_key`, plus the digits as a part, in orders and saved addresses); a hit on any order of a person gives that
 * person ONCE (the answer is a set of email keys, never a join). `%`, `_` and `\` are escaped.
 */
final class CustomerListQuery
{
    public const PER_PAGE = 25;

    public const TAB_ALL = 'all';

    public const TAB_ACCOUNTS = 'accounts';

    public const TAB_GUESTS = 'guests';

    /** The statuses that do NOT count as money spent. */
    public const NOT_SPENT = [OrderStatus::CANCELLED, OrderStatus::REFUNDED];

    /** @return Builder<CustomerRow> */
    public static function query(string $tab = self::TAB_ALL): Builder
    {
        $query = CustomerRow::query()->fromSub(self::union(), 'customers')->select('customers.*');

        return match ($tab) {
            self::TAB_ACCOUNTS => $query->where('customers.has_account', 1),
            self::TAB_GUESTS => $query->where('customers.has_account', 0),
            default => $query,
        };
    }

    public static function union(): QueryBuilder
    {
        $notSpent = array_map(static fn (OrderStatus $status): string => $status->value, self::NOT_SPENT);
        $marks = implode(',', array_fill(0, count($notSpent), '?'));

        $aggregate = DB::table('orders')
            ->whereNotNull('email_key')
            ->groupBy('email_key')
            ->selectRaw("email_key, COUNT(*) AS orders_count, SUM(CASE WHEN status IN ({$marks}) THEN 0 ELSE total_minor END) AS spent_minor, MAX(id) AS last_id", $notSpent);

        // name and phone: the account's preferred address, else the latest order (CustomerContact, BUG-2)
        $people = DB::query()
            ->fromSub($aggregate, 'g')
            ->join('orders AS o', 'o.id', '=', 'g.last_id')
            ->leftJoin('accounts AS acc', fn ($join) => $join->on('acc.email', '=', 'g.email_key')->whereNull('acc.deleted_at'));
        CustomerContact::joinPreferredAddress($people, 'acc.id')
            ->selectRaw("CONCAT('o-', o.id) AS row_key, g.email_key AS email_key, ".CustomerContact::nameSql('o').' AS name, o.email AS email, '.CustomerContact::phoneSql('o').' AS phone, g.orders_count AS orders_count, g.spent_minor AS spent_minor, o.currency AS currency, o.placed_at AS last_order_at, (acc.id IS NOT NULL) AS has_account');

        $accountsOnly = DB::table('accounts AS a')
            ->whereNull('a.deleted_at')
            ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('orders AS x')->whereColumn('x.email_key', 'a.email'));
        CustomerContact::joinPreferredAddress($accountsOnly, 'a.id')
            ->selectRaw("CONCAT('a-', a.id), a.email, ".CustomerContact::nameSql(null).', a.email, '.CustomerContact::phoneSql(null).', 0, 0, NULL, NULL, 1');

        return $people->unionAll($accountsOnly);
    }

    /**
     * Narrows an outer query over the derived table `customers` by the search box.
     *
     * @param  Builder<CustomerRow>  $query
     * @return Builder<CustomerRow>
     */
    public static function search(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.self::escapeLike(mb_strtolower($term)).'%';
        $words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower($term)) ?: [], static fn (string $word): bool => $word !== ''));
        $digits = preg_replace('/\D/', '', $term) ?? '';
        $phoneSearch = strlen($digits) >= 4 && preg_match('/^[\d\s+()\-.\/]+$/', $term) === 1;

        return $query->where(function (Builder $where) use ($like, $words, $digits, $phoneSearch, $term): void {
            $where->where('customers.email_key', 'like', $like);

            if ($words !== []) {
                $where->orWhereIn('customers.email_key', function ($sub) use ($words): void {
                    $sub->select('email_key')->from('orders')->whereNotNull('email_key');

                    foreach ($words as $word) {
                        $sub->where('name_key', 'like', '%'.self::escapeLike($word).'%');
                    }
                });

                // BUG-2: the name of the account's saved addresses too (an account with no order has no order name to find)
                $where->orWhereIn('customers.email_key', function ($sub) use ($words): void {
                    $sub->select('s.email')->from('accounts AS s')->join('addresses AS sa', 'sa.account_id', '=', 's.id')->whereNull('s.deleted_at');

                    foreach ($words as $word) {
                        $sub->whereRaw("LOWER(CONCAT_WS(' ', sa.recipient_first_name, sa.recipient_last_name)) LIKE ?", ['%'.self::escapeLike($word).'%']);
                    }
                });
            }

            if ($phoneSearch) {
                // `phone_key` is the E.164 number (or, for an old unreadable one, its digits): the typed number is reduced by the SAME normaliser (exact match, index), and the digits
                // typed are also looked for inside a key (a part of a number)
                $keys = array_values(array_unique(array_filter([
                    PhoneNumbers::identityKey($term, app(StoreCountry::class)->currentOrNull()),
                    IdentityKeys::phone($term),
                ])));

                $where->orWhereIn('customers.email_key', function ($sub) use ($keys, $digits): void {
                    $sub->select('email_key')->from('orders')->whereNotNull('email_key')->where(function ($match) use ($keys, $digits): void {
                        $keys !== [] && $match->whereIn('phone_key', $keys);
                        $match->orWhere('phone_key', 'like', '%'.$digits.'%');
                    });
                });

                // BUG-2: the phone of the account's saved addresses (stored as E.164, like `phone_key`)
                $where->orWhereIn('customers.email_key', function ($sub) use ($keys, $digits): void {
                    $sub->select('s.email')->from('accounts AS s')->join('addresses AS sa', 'sa.account_id', '=', 's.id')->whereNull('s.deleted_at')->where(function ($match) use ($keys, $digits): void {
                        $keys !== [] && $match->whereIn('sa.phone', $keys);
                        $match->orWhere('sa.phone', 'like', '%'.$digits.'%');
                    });
                });
            }
        });
    }

    /** `%`, `_` and the escape character itself lose their meaning in a LIKE pattern. */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
