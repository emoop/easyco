<?php

namespace App\Privacy;

use App\Privacy\Persistence\PrivacyRequestModel;
use Illuminate\Support\Carbon;

/**
 * ACC-4e-2 (acc-4-gdpr-design.md D6, section 7): the erase requests that still WAIT FOR MANUAL STEPS: `done`, the clear view still on (`clear_until` in the future) and at least one step of the CURRENT
 * list in the settings not ticked. An empty list means nothing is pending. Computed in PHP over the rows with a future `clear_until` (a bounded set: they leave it after 30 days), ONE query for them and
 * one for the settings; it reads no hash. Read once per request (the dashboard asks twice: `canView()` and the render).
 */
final class ForgetPending
{
    /** The most rows read: the clear view lasts 30 days, so this is far above any real number. */
    private const LIMIT = 1000;

    /** @var array{count: int, email: ?string, days: int}|false|null */
    private static array|false|null $memo = null;

    public static function forget(): void
    {
        self::$memo = null;
    }

    /** @return array{count: int, email: ?string, days: int}|null null when nothing is pending */
    public static function summary(?Carbon $now = null): ?array
    {
        if (self::$memo !== null) {
            return self::$memo === false ? null : self::$memo;
        }

        $now ??= now();
        $steps = array_column(app(PrivacySettings::class)->manualStepList(), 'key');

        if ($steps === []) {
            self::$memo = false;

            return null;
        }

        $rows = PrivacyRequestModel::query()
            ->where('kind', PrivacyRequestModel::KIND_ERASE)
            ->where('status', PrivacyRequestModel::STATUS_DONE)
            ->where('clear_until', '>', $now)
            ->orderBy('clear_until')
            ->limit(self::LIMIT)
            ->get(['id', 'email', 'clear_until', 'manual_steps']);

        $count = 0;
        $soonest = null;

        foreach ($rows as $row) {
            $ticked = array_keys(ForgetLogPresenter::ticked($row));

            if (array_diff($steps, $ticked) === []) {
                continue;
            }

            $count++;
            $soonest ??= $row;
        }

        if ($count === 0 || $soonest === null) {
            self::$memo = false;

            return null;
        }

        self::$memo = ['count' => $count, 'email' => ForgetLogPresenter::email($soonest, $now), 'days' => ForgetLogPresenter::clearView($soonest, $now)['days'] ?? 0];

        return self::$memo;
    }
}
