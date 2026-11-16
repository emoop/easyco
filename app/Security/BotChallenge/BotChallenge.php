<?php

namespace App\Security\BotChallenge;

use App\Settings\Contracts\SiteSettingsRepository;

/**
 * P8d: the bot-challenge switch «Усилена защита от ботове» (the setting `security.bot_challenge`): a CLOSED list, `off` (the default) | `filters` | `misses`. Anything else
 * that is stored reads as `off`. It never purges the page cache (`security.` is in EloquentSiteSettingsRepository::INVISIBLE). The edge (Varnish, P8c) reads it through
 * BotChallengeSwitch; written by the admin field (the owner only) and by `php artisan security:bot-challenge`.
 */
final class BotChallenge
{
    public const KEY = 'security.bot_challenge';

    public const OFF = 'off';

    public const FILTERS = 'filters';

    public const MISSES = 'misses';

    /** @var list<string> */
    public const MODES = [self::OFF, self::FILTERS, self::MISSES];

    public function __construct(private readonly SiteSettingsRepository $settings)
    {
    }

    public static function valid(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::MODES, true);
    }

    public function current(): string
    {
        $value = $this->settings->get(self::KEY);

        return self::valid($value) ? $value : self::OFF;
    }

    public function set(string $mode): void
    {
        if (! self::valid($mode)) {
            throw new \InvalidArgumentException('The bot-challenge mode must be one of: '.implode(', ', self::MODES).'.');
        }

        $mode === self::OFF ? $this->settings->forget(self::KEY) : $this->settings->set(self::KEY, $mode);
    }
}
