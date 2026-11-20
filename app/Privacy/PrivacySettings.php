<?php

namespace App\Privacy;

use App\Settings\Contracts\SiteSettingsRepository;
use InvalidArgumentException;

/**
 * THE ONE READER of the `privacy.*` retention settings of ACC-4 (acc-4-gdpr-design.md §5, §7). Same posture as OrderContextSettings: the owner of a key owns
 * its default, an absent or out-of-range value is the default, and a stored value is judged again on read.
 *
 *  - `privacy.order_retention_years`: 1..30, default 10 (used by ACC-4b);
 *  - `privacy.mail_log_retention_days`: 30..3650, default 365;
 *  - `privacy.manual_steps`: a JSON list of up to 10 labels, each plain text of at most 80 characters; an invalid entry is dropped on read, an invalid set is
 *    refused on write. Default empty.
 *
 * They are read ONE key at a time, NOT through CheckoutFormSettings::KEYS: no checkout or page shell pays a query for them.
 */
final class PrivacySettings
{
    public const ORDER_RETENTION_YEARS_KEY = 'privacy.order_retention_years';

    public const MAIL_LOG_RETENTION_DAYS_KEY = 'privacy.mail_log_retention_days';

    public const MANUAL_STEPS_KEY = 'privacy.manual_steps';

    public const DEFAULT_ORDER_RETENTION_YEARS = 10;

    /** The shortest mail log life. It MUST stay longer than ReconcileOrderConfirmations::WINDOW_HOURS: see PruneMailLog (a test pins the pair). */
    public const MIN_MAIL_LOG_RETENTION_DAYS = 30;

    public const DEFAULT_MAIL_LOG_RETENTION_DAYS = 365;

    public const MAX_MANUAL_STEPS = 10;

    public const MAX_MANUAL_STEP_LENGTH = 80;

    public function __construct(
        private readonly SiteSettingsRepository $settings,
    ) {
    }

    public function orderRetentionYears(): int
    {
        return $this->boundedInt(self::ORDER_RETENTION_YEARS_KEY, 1, 30) ?? self::DEFAULT_ORDER_RETENTION_YEARS;
    }

    public function mailLogRetentionDays(): int
    {
        return $this->boundedInt(self::MAIL_LOG_RETENTION_DAYS_KEY, self::MIN_MAIL_LOG_RETENTION_DAYS, 3650) ?? self::DEFAULT_MAIL_LOG_RETENTION_DAYS;
    }

    /** @return list<string> the valid labels, in order, at most MAX_MANUAL_STEPS */
    public function manualSteps(): array
    {
        $raw = $this->settings->get(self::MANUAL_STEPS_KEY);
        $decoded = $raw === null ? null : json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        $labels = [];

        foreach ($decoded as $label) {
            if (is_string($label) && self::isValidLabel($label)) {
                $labels[] = trim($label);
            }
        }

        return array_slice($labels, 0, self::MAX_MANUAL_STEPS);
    }

    /**
     * Writes the labels. Validation on write: at most MAX_MANUAL_STEPS, each non-blank plain text of at most MAX_MANUAL_STEP_LENGTH characters, no duplicates.
     *
     * @param  list<string>  $labels
     *
     * @throws InvalidArgumentException
     */
    public function saveManualSteps(array $labels): void
    {
        $clean = [];

        foreach ($labels as $label) {
            if (! is_string($label) || ! self::isValidLabel($label)) {
                throw new InvalidArgumentException('A manual step label is plain text of 1 to '.self::MAX_MANUAL_STEP_LENGTH.' characters.');
            }

            $clean[] = trim($label);
        }

        if (count($clean) > self::MAX_MANUAL_STEPS || count(array_unique($clean)) !== count($clean)) {
            throw new InvalidArgumentException('At most '.self::MAX_MANUAL_STEPS.' different manual steps.');
        }

        $this->settings->set(self::MANUAL_STEPS_KEY, json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function isValidLabel(string $label): bool
    {
        $label = trim($label);

        return $label !== '' && mb_strlen($label) <= self::MAX_MANUAL_STEP_LENGTH && preg_match('/[\p{Cc}\p{Cf}]/u', $label) !== 1;
    }

    private function boundedInt(string $key, int $min, int $max): ?int
    {
        $value = $this->settings->get($key);

        if ($value === null || ! preg_match('/^\d{1,5}$/D', trim($value))) {
            return null;
        }

        $number = (int) trim($value);

        return $number >= $min && $number <= $max ? $number : null;
    }
}
