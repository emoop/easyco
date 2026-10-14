<?php

namespace App\Services;

use App\Enums\OrderBlockType;

/**
 * How a blocked identifier is SHOWN (cod-protection-design.md section 3.3): a phone as `*** *** 456` (the last three digits), an email as
 * `m***@gmail.com` (the first letter and the domain), an IP address masked (`203.0.*.*`) unless the viewer may read the full value
 * (ORDER_MANAGE). The activity log and every list for a viewer without that permission use the MASKED form, always: a full IP never reaches a
 * log line (order-context-design.md section 7.2).
 */
final class OrderBlockMasker
{
    public static function mask(OrderBlockType $type, string $value, bool $fullIp = false): string
    {
        return match ($type) {
            OrderBlockType::PHONE => self::phone($value),
            OrderBlockType::EMAIL => self::email($value),
            OrderBlockType::IP => $fullIp ? $value : self::ip($value),
        };
    }

    private static function phone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return '*** *** '.substr($digits, -3);
    }

    private static function email(string $value): string
    {
        $value = trim($value);
        $at = strrpos($value, '@');

        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_strtolower(mb_substr($value, 0, 1)).'***'.substr($value, $at);
    }

    private static function ip(string $value): string
    {
        $value = trim($value);

        if (str_contains($value, ':')) {
            // IPv6: the first two groups only.
            $groups = explode(':', $value);

            return ($groups[0] ?? '').':'.($groups[1] ?? '').':*';
        }

        $octets = explode('.', $value);

        return ($octets[0] ?? '*').'.'.($octets[1] ?? '*').'.*.*';
    }
}
