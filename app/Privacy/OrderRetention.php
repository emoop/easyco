<?php

namespace App\Privacy;

use App\Settings\StoreTimezone;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Date;

/**
 * THE ONE retention cutoff of orders (acc-4-gdpr-design.md D1, ACC-4b): an order is anonymised once today >= 1 January of (placement year + 1 + N), N =
 * `privacy.order_retention_years` (default 10). Written as a cutoff: `placed_at < start of THIS year in the store timezone - N years`. With N = 10 and
 * today 2037-03-05 the cutoff is 2027-01-01 00:00 (store time): the orders placed in 2026 and earlier. The accountant's «10 years from 1 January of the
 * following period» is exactly this rule.
 *
 * The year is the STORE's (site.timezone), not the server's: an order placed at 23:59 on 31 December store time belongs to that year, whatever UTC says.
 * The result is an absolute instant (UTC), what the database compares `placed_at` with.
 */
final class OrderRetention
{
    public function __construct(
        private readonly PrivacySettings $settings,
        private readonly StoreTimezone $timezone,
    ) {
    }

    /** The cutoff now (or at `$now`, for a test that travels): an order placed STRICTLY BEFORE it is past its retention period. */
    public function cutoff(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        return $this->cutoffForYears($this->settings->orderRetentionYears(), $now);
    }

    /** ACC-4e-2: the same cutoff for a GIVEN retention (the settings page asks what a LOWER value would catch). The one place the date rule lives. */
    public function cutoffForYears(int $years, ?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $local = ($now ?? Date::now()->toDateTimeImmutable())->setTimezone($this->timezone->zone());
        $startOfYear = $local->setDate((int) $local->format('Y'), 1, 1)->setTime(0, 0, 0);

        return $startOfYear->modify('-'.$years.' years')->setTimezone(new DateTimeZone('UTC'));
    }
}
