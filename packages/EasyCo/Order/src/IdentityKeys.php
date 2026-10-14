<?php

namespace EasyCo\Order;

/**
 * THE ONE NORMALISER of the identifiers two orders may share (cod-protection-design.md section 2, stage C2-B): the phone, the email, the
 * client address, the name and the delivery place are each reduced to a KEY, so that the same person typing the same thing in different ways
 * still matches. Used by BOTH the matcher («Съвпадения») and the block check at checkout: one class, so they can never disagree.
 *
 * PURE PHP (CLAUDE.md rule 1): no framework, no database, no clock. The keys are DERIVED data stored on `orders` (phone_key, email_key,
 * name_key, place_key); changing a rule here means re-computing them with `orders:rebuild-identity-keys`, never a hand edit.
 *
 *  - PHONE: the digits only; then the LAST 9 digits when there are at least 9 (`0888 123 456`, `+359 888 123456`, `00359888123456` are all
 *    `888123456`). A heuristic chosen for the shop's market, documented as such: real phone validation can replace it later without changing the
 *    table. Fewer than 9 digits is NO key (an odd or short number never matches and is never blocked).
 *  - EMAIL: trimmed and lower-cased. NO provider-specific tricks (dots and plus-tags are NOT stripped: too clever, false positives).
 *  - IP: the canonical text form; an IPv4 address is compared exactly; an IPv6 address is compared as its /64 prefix (one household or device
 *    rotates inside it); an IPv4-mapped IPv6 address is the IPv4 address. Not a matching key for orders, only for blocks.
 *  - NAME (weak): first + last, lower-cased, inner whitespace collapsed. Only ever a "possible match", never a block.
 *  - PLACE: street delivery = country | city | address line 1; pick-up = carrier | the pick-up point's address text; each part lower-cased with
 *    spaces and punctuation collapsed; the joined text is hashed (sha1). One key per order, for "same address / same office".
 */
final class IdentityKeys
{
    public const PHONE_DIGITS = 9;

    public static function phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === null || strlen($digits) < self::PHONE_DIGITS) {
            return null;
        }

        return substr($digits, -self::PHONE_DIGITS);
    }

    public static function email(?string $email): ?string
    {
        $key = mb_strtolower(trim((string) $email));

        return $key === '' ? null : mb_substr($key, 0, 255);
    }

    public static function ip(?string $ip): ?string
    {
        if ($ip === null || trim($ip) === '') {
            return null;
        }

        $packed = @inet_pton(trim($ip));

        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16) {
            // An IPv4-mapped IPv6 address (::ffff:a.b.c.d) is the IPv4 address it carries.
            if (substr($packed, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
                $packed = substr($packed, 12);
            } else {
                // The /64 prefix: the first eight bytes, the rest zero.
                $packed = substr($packed, 0, 8).str_repeat("\0", 8);
            }
        }

        $text = inet_ntop($packed);

        return $text === false ? null : $text;
    }

    public static function name(?string $first, ?string $last): ?string
    {
        $key = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(trim((string) $first).' '.trim((string) $last))));

        return $key === '' ? null : mb_substr($key, 0, 201);
    }

    /** Street: country | city | address line 1. Pick-up point: carrier | the point's address text. NULL when the identifying part is empty. */
    public static function place(bool $pickupPoint, ?string $country, ?string $city, ?string $addressLine1, ?string $carrierCode, ?string $pickupPointAddress): ?string
    {
        $parts = $pickupPoint
            ? [self::collapse($carrierCode), self::collapse($pickupPointAddress)]
            : [self::collapse($country), self::collapse($city), self::collapse($addressLine1)];

        // The last part (the address line / the point's text) is what identifies a place: without it the key would match every order of a city.
        if (end($parts) === '') {
            return null;
        }

        return sha1(($pickupPoint ? 'pickup' : 'street').'|'.implode('|', $parts));
    }

    /**
     * The four keys of an `orders` ROW (its raw columns), for the back-fill migration and the rebuild command: the same rules as above,
     * read from the stored values instead of an Order.
     *
     * @param  object|array<string, mixed>  $row  email, phone, recipient_first_name, recipient_last_name, delivery_type, country, city, address_line_1, carrier_code, pickup_point_address
     * @return array{phone_key: ?string, email_key: ?string, name_key: ?string, place_key: ?string}
     */
    public static function forRow(object|array $row): array
    {
        $get = static fn (string $column): ?string => is_array($row) ? ($row[$column] ?? null) : ($row->{$column} ?? null);

        return [
            'phone_key' => self::phone($get('phone')),
            'email_key' => self::email($get('email')),
            'name_key' => self::name($get('recipient_first_name'), $get('recipient_last_name')),
            'place_key' => self::place($get('delivery_type') === 'pickup_point', $get('country'), $get('city'), $get('address_line_1'), $get('carrier_code'), $get('pickup_point_address')),
        ];
    }

    /** Lower-case, every run of spaces and punctuation one space, trimmed ("Vitosha Blvd. 1" = "vitosha  blvd 1"). */
    private static function collapse(?string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string) $text)));
    }
}
