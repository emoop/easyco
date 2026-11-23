<?php

namespace App\Privacy;

use Illuminate\Support\Carbon;

/**
 * THE ONE reading of an order's consents (acc-4-gdpr-design.md D3, §4): the marketing consent, its text, the date of the order and the acceptance of the terms. Shared by the personal-data
 * export (PrivacyExportBuilder) and the admin customer page (ACC-4e-1), so the two never describe a consent differently. Pure: it reads the columns of one `orders` row it is given.
 */
final class OrderConsents
{
    /** The `orders` columns this reads (a caller that selects columns lists these). */
    public const COLUMNS = ['id', 'placed_at', 'marketing_consent', 'marketing_consent_text', 'terms_accepted_at', 'terms_version'];

    /**
     * @return array<string, mixed> `order`, `placed_at` (ISO 8601), `marketing_consent` (bool), and the text / terms fields that are not empty
     */
    public static function row(object $order): array
    {
        return array_filter([
            'order' => (string) $order->id,
            'placed_at' => self::date($order->placed_at),
            'marketing_consent' => (bool) $order->marketing_consent,
            'marketing_consent_text' => $order->marketing_consent_text,
            'terms_accepted_at' => self::date($order->terms_accepted_at),
            'terms_version' => $order->terms_version,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private static function date(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->toIso8601String();
    }
}
