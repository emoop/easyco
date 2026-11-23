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

    /**
     * ACC-4e-2: the writers of the two numbers, with the same bounds the readers judge by. The settings page and nothing else writes them.
     *
     * @throws InvalidArgumentException out of range
     */
    public function saveOrderRetentionYears(int $years): void
    {
        ($years >= 1 && $years <= 30) || throw new InvalidArgumentException('The order retention is 1 to 30 years.');
        $this->settings->set(self::ORDER_RETENTION_YEARS_KEY, (string) $years);
    }

    /** @throws InvalidArgumentException out of range */
    public function saveMailLogRetentionDays(int $days): void
    {
        ($days >= self::MIN_MAIL_LOG_RETENTION_DAYS && $days <= 3650) || throw new InvalidArgumentException('The mail log retention is '.self::MIN_MAIL_LOG_RETENTION_DAYS.' to 3650 days.');
        $this->settings->set(self::MAIL_LOG_RETENTION_DAYS_KEY, (string) $days);
    }

    /** @return list<string> the valid labels, in order, at most MAX_MANUAL_STEPS */
    public function manualSteps(): array
    {
        return array_column($this->manualStepList(), 'label');
    }

    /**
     * ACC-4e-2: the steps WITH their stable keys: `[{key, label}]`. The key is made when a step is added and never changes (a label edit keeps it); a ticked step in a forget-log row refers to it.
     * The stored JSON is a list of `{key, label}`; the older list of plain labels is still read (its keys are derived from the label once, and replaced by real ones on the next save).
     *
     * @return list<array{key: string, label: string}>
     */
    public function manualStepList(): array
    {
        $raw = $this->settings->get(self::MANUAL_STEPS_KEY);
        $decoded = $raw === null ? null : json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        $steps = [];
        $seen = [];

        foreach ($decoded as $entry) {
            $label = is_array($entry) ? ($entry['label'] ?? null) : $entry;
            $key = is_array($entry) ? ($entry['key'] ?? null) : null;

            if (! is_string($label) || ! self::isValidLabel($label)) {
                continue;
            }

            $key = is_string($key) && PrivacyActions::isValidKey($key) ? $key : 'step_'.substr(md5(trim($label)), 0, 10);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $steps[] = ['key' => $key, 'label' => trim($label)];
        }

        return array_slice($steps, 0, self::MAX_MANUAL_STEPS);
    }

    /** A new stable key for a step that is being added: `s` and eight hex characters, never made from the label. */
    public static function newStepKey(): string
    {
        return 's'.bin2hex(random_bytes(4));
    }

    /**
     * ACC-4e-2: writes the steps with their keys. A step without a key (new) gets one; a key that is not of the closed shape, or is used twice, is refused. Labels as in saveManualSteps().
     *
     * @param  list<array{key?: ?string, label: string}>  $steps
     *
     * @throws InvalidArgumentException
     */
    public function saveManualStepList(array $steps): void
    {
        $clean = [];

        foreach ($steps as $step) {
            $label = $step['label'] ?? null;
            $key = $step['key'] ?? null;

            if (! is_string($label) || ! self::isValidLabel($label)) {
                throw new InvalidArgumentException('A manual step label is plain text of 1 to '.self::MAX_MANUAL_STEP_LENGTH.' characters.');
            }

            if ($key === null || $key === '') {
                $key = self::newStepKey();
            } elseif (! is_string($key) || ! PrivacyActions::isValidKey($key)) {
                throw new InvalidArgumentException('A manual step key is a short stable identifier.');
            }

            $clean[] = ['key' => $key, 'label' => trim($label)];
        }

        if (count($clean) > self::MAX_MANUAL_STEPS || count(array_unique(array_column($clean, 'key'))) !== count($clean) || count(array_unique(array_column($clean, 'label'))) !== count($clean)) {
            throw new InvalidArgumentException('At most '.self::MAX_MANUAL_STEPS.' different manual steps.');
        }

        $this->settings->set(self::MANUAL_STEPS_KEY, json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
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

        // a label that already exists keeps its key; a new one gets a new key
        $known = array_column($this->manualStepList(), 'key', 'label');
        $this->saveManualStepList(array_map(static fn (string $label): array => ['key' => $known[$label] ?? null, 'label' => $label], $clean));
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
