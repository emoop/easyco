<?php

namespace App\Customers;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

/**
 * BUG-2: the ONE rule for the name and the phone the admin shows for a customer. An account has no name or phone column of its own: the account area saves them as a saved ADDRESS
 * (`addresses.recipient_first_name`, `recipient_last_name`, `phone`), and an order carries its own copy. The order of preference, for the name and the phone alike:
 *
 *   1. the account's PREFERRED address: the default one (`default_marker`), else the oldest (the same order the customer page lists them in);
 *   2. the latest order of that email key;
 *   3. nothing (the title then shows the email, the cells are empty).
 *
 * The value is shown as stored (no reformatting). The two forms of the rule live side by side here so they cannot drift apart: `resolve()` for the customer page (rows it has already read),
 * `joinPreferredAddress()` + `nameSql()` + `phoneSql()` for the list (one statement, no query per row). An account that was erased has no address left (the eraser force-deletes it), so that
 * source is simply gone; the order columns are read exactly as before (an anonymised order has no email key and is never reached).
 */
final class CustomerContact
{
    /** The alias the preferred address is joined as. */
    public const ADDRESS = 'pa';

    /**
     * @param  ?object  $address  the preferred address row of the account (recipient_first_name, recipient_last_name, phone), or null
     * @param  ?object  $order  the latest order row (recipient_first_name, recipient_last_name, phone), or null
     * @return array{name: ?string, phone: ?string}
     */
    public static function resolve(?object $address, ?object $order): array
    {
        return [
            'name' => self::name($address) ?? self::name($order),
            'phone' => self::text($address->phone ?? null) ?? self::text($order->phone ?? null),
        ];
    }

    /** The first and the last name of a row as one trimmed string, or null when both are empty. */
    private static function name(?object $row): ?string
    {
        return $row === null ? null : self::text(trim((string) $row->recipient_first_name.' '.(string) $row->recipient_last_name));
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Joins the preferred address of the account `$accountId` (a column reference such as `acc.id`) as alias `pa`: the default one, else the lowest id. One indexed lookup
     * (`addr_account_id_index`) for the rows that have an account, none for the others.
     */
    public static function joinPreferredAddress(QueryBuilder $query, string $accountId): QueryBuilder
    {
        $alias = self::ADDRESS;

        return $query->leftJoin('addresses AS '.$alias, fn (JoinClause $join) => $join->whereRaw(
            "{$alias}.account_id = {$accountId} AND {$alias}.id = (SELECT x.id FROM addresses AS x WHERE x.account_id = {$accountId} ORDER BY x.default_marker DESC, x.id ASC LIMIT 1)"
        ));
    }

    /** SQL of the resolved name, after joinPreferredAddress(); `$orderAlias` is the latest order's alias, or null when there is none (an account with no order). */
    public static function nameSql(?string $orderAlias): string
    {
        $address = "NULLIF(TRIM(CONCAT_WS(' ', ".self::ADDRESS.'.recipient_first_name, '.self::ADDRESS.".recipient_last_name)), '')";

        return $orderAlias === null
            ? $address
            : "COALESCE({$address}, NULLIF(TRIM(CONCAT_WS(' ', {$orderAlias}.recipient_first_name, {$orderAlias}.recipient_last_name)), ''))";
    }

    /** SQL of the resolved phone; see nameSql(). */
    public static function phoneSql(?string $orderAlias): string
    {
        $address = 'NULLIF('.self::ADDRESS.".phone, '')";

        return $orderAlias === null ? $address : "COALESCE({$address}, NULLIF({$orderAlias}.phone, ''))";
    }
}
