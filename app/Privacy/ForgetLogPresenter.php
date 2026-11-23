<?php

namespace App\Privacy;

use App\Privacy\Persistence\PrivacyRequestModel;
use Illuminate\Support\Carbon;

/**
 * ACC-4e-2 (acc-4-gdpr-design.md D6, section 7): how ONE row of the forget log is read for the admin page. Pure: it reads the row it is given, asks nothing of the database.
 *
 *  - CLEAR VIEW is decided ON READ: the email and the phone are shown only while `clear_until` is in the future AND the value is not null. A row past `clear_until` whose daily nulling
 *    (`privacy:expire-clear-view`) has not run yet already reads «само хеш». The remaining time is the ceil of the days; amber at AMBER_DAYS or less.
 *  - ACTIONS: the closed keys of PrivacyActions::CORE read as short sentences (lang `privacy_admin.log.action.*`); any other key (a listener's) is plain `key: value`. A value's type is never
 *    trusted: everything is cast to a string here and escaped by the view.
 */
final class ForgetLogPresenter
{
    public const AMBER_DAYS = 7;

    /** @return array{visible: bool, days: ?int, amber: bool} */
    public static function clearView(PrivacyRequestModel $row, ?Carbon $now = null): array
    {
        $now ??= now();
        $until = $row->clear_until;

        if ($until === null || $until->getTimestamp() <= $now->getTimestamp()) {
            return ['visible' => false, 'days' => null, 'amber' => false];
        }

        $days = (int) ceil(($until->getTimestamp() - $now->getTimestamp()) / 86400);

        return ['visible' => true, 'days' => $days, 'amber' => $days <= self::AMBER_DAYS];
    }

    /** The clear email, or null when the clear view is over (or the value was already nulled). */
    public static function email(PrivacyRequestModel $row, ?Carbon $now = null): ?string
    {
        return self::clearView($row, $now)['visible'] && is_string($row->email) && $row->email !== '' ? $row->email : null;
    }

    public static function phone(PrivacyRequestModel $row, ?Carbon $now = null): ?string
    {
        return self::clearView($row, $now)['visible'] && is_string($row->phone) && $row->phone !== '' ? $row->phone : null;
    }

    /** @return list<string> the sentences, plain text (the view escapes them) */
    public static function actionLines(PrivacyRequestModel $row): array
    {
        $lines = [];
        $actions = $row->actions ?? [];
        $ordered = [];

        // the closed keys first, in their own order (MySQL stores a JSON object with its keys sorted), then the listeners' keys
        foreach (PrivacyActions::CORE as $core) {
            array_key_exists($core, $actions) && $ordered[$core] = $actions[$core];
        }

        foreach ($actions as $key => $value) {
            array_key_exists($key, $ordered) || $ordered[$key] = $value;
        }

        foreach ($ordered as $key => $value) {
            $key = (string) $key;
            $text = is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : '');

            if (in_array($key, PrivacyActions::CORE, true)) {
                $shown = $key === PrivacyActions::COD_BLOCKS_KEPT && $text === 'permanent' ? (string) __('privacy_admin.log.forever') : ($value === true ? '' : $text);
                $lines[] = trim((string) __('privacy_admin.log.action.'.$key, ['value' => $shown]));

                continue;
            }

            $lines[] = $key.': '.$text;
        }

        return $lines;
    }

    /**
     * The ticked steps of a row keyed by step key: `key => done_at`.
     *
     * @return array<string, ?string>
     */
    public static function ticked(PrivacyRequestModel $row): array
    {
        $ticked = [];

        foreach (($row->manual_steps ?? []) as $step) {
            is_array($step) && isset($step['key']) && is_string($step['key']) && $ticked[$step['key']] = is_string($step['done_at'] ?? null) ? $step['done_at'] : null;
        }

        return $ticked;
    }
}
