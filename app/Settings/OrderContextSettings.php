<?php

namespace App\Settings;

use App\Enums\ConsentMode;
use App\Settings\Contracts\SiteSettingsRepository;

/**
 * THE ONE READER of the three `privacy.*` settings the order context needs (order-context-design.md sections 7.2 and 8.1, stage C2-A).
 * They are read through the checkout form's ONE `getMany` (CheckoutFormSettings::KEYS lists them), so a page shell and a checkout request
 * pay no query for them.
 *
 * DEFAULTS (site-settings-design.md section 5: the owner of a key owns its default), every one the CONSERVATIVE one:
 *  - `privacy.consent_mode`: strict | notice | off; absent, or anything else, is `strict` (the EU posture; an unreadable value never
 *    silently loosens the regime);
 *  - `privacy.user_agent_max_length`: 1..255 (the column's width); absent or out of range is 255;
 *  - `privacy.ip_full_retention_days`: 0..3650; absent or out of range is 30. ZERO means "anonymise on write": the full address is never kept.
 */
final class OrderContextSettings
{
    public const CONSENT_MODE_KEY = 'privacy.consent_mode';

    public const USER_AGENT_MAX_LENGTH_KEY = 'privacy.user_agent_max_length';

    public const IP_RETENTION_DAYS_KEY = 'privacy.ip_full_retention_days';

    public const KEYS = [
        self::CONSENT_MODE_KEY,
        self::USER_AGENT_MAX_LENGTH_KEY,
        self::IP_RETENTION_DAYS_KEY,
    ];

    public const DEFAULT_IP_RETENTION_DAYS = 30;

    public const MAX_IP_RETENTION_DAYS = 3650;

    public const MAX_USER_AGENT_LENGTH = 255;

    public function __construct(
        private readonly SiteSettingsRepository $settings,
    ) {
    }

    /** @return array<string, ?string> */
    private function raw(): array
    {
        return $this->settings->getMany(CheckoutFormSettings::KEYS);
    }

    public function consentMode(): ConsentMode
    {
        return ConsentMode::tryFrom((string) $this->raw()[self::CONSENT_MODE_KEY]) ?? ConsentMode::STRICT;
    }

    public function userAgentMaxLength(): int
    {
        return $this->boundedInt(self::USER_AGENT_MAX_LENGTH_KEY, 1, self::MAX_USER_AGENT_LENGTH) ?? self::MAX_USER_AGENT_LENGTH;
    }

    public function ipFullRetentionDays(): int
    {
        return $this->boundedInt(self::IP_RETENTION_DAYS_KEY, 0, self::MAX_IP_RETENTION_DAYS) ?? self::DEFAULT_IP_RETENTION_DAYS;
    }

    private function boundedInt(string $key, int $min, int $max): ?int
    {
        $value = $this->raw()[$key];

        if ($value === null || ! preg_match('/^\d{1,5}$/D', trim($value))) {
            return null;
        }

        $number = (int) trim($value);

        return $number >= $min && $number <= $max ? $number : null;
    }
}
