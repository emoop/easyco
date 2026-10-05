<?php

namespace App\Settings;

use App\Settings\Contracts\SiteSettingsRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * THE ONE READER of the store's timezone (`site.timezone`), next to StoreLocale — for the
 * SERVICE layer, which must not depend on Filament's FilamentTimezone (a display setting).
 *
 * It answers the same question ApplyStoreTimezone feeds Filament, with the same fallback
 * chain, so a screen and a service can never disagree about what "today" is for the store:
 * the stored row, then `config('services.site.default_timezone')` (the project's own
 * developer-settable default), then `config('app.timezone')`. It changes nothing:
 * `config('app.timezone')` stays UTC and storage stays UTC. Only the CALENDAR DAY of a UTC
 * instant — "which day was that for the merchant" — is read through here.
 */
final class StoreTimezone
{
    public const KEY = 'site.timezone';

    public function __construct(
        private readonly SiteSettingsRepository $settings,
    ) {
    }

    public function current(): string
    {
        return $this->settings->get(self::KEY)
            ?? (config('services.site.default_timezone') ?: (string) config('app.timezone'));
    }

    public function zone(): DateTimeZone
    {
        return new DateTimeZone($this->current());
    }

    /** The store-local calendar day ('Y-m-d') of an instant. */
    public function dayOf(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->zone())->format('Y-m-d');
    }

    /** Today's calendar day ('Y-m-d') in the store timezone. */
    public function today(): string
    {
        return $this->dayOf(new DateTimeImmutable('now'));
    }

    /** Whole calendar days from one 'Y-m-d' day to another (negative when the second is earlier). */
    public static function daysBetween(string $fromDay, string $toDay): int
    {
        $from = new DateTimeImmutable($fromDay.' 00:00:00', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable($toDay.' 00:00:00', new DateTimeZone('UTC'));

        return intdiv($to->getTimestamp() - $from->getTimestamp(), 86400);
    }
}
