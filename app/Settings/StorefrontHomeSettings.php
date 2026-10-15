<?php

namespace App\Settings;

use App\Settings\Contracts\SiteSettingsRepository;

/**
 * THE ONE READER of the storefront home page's editable intro (stage S3-V): a heading and a short text the merchant types in Admin > Storefront.
 * Plain text only (the admin form enforces PlainText / PlainMultilineText and the two limits; this reader never trusts the stored value to be short:
 * it cuts at the limits). EMPTY = the language file's default, which stays as the fallback in the view.
 *
 * ONE `getMany`, deliberately shared with the key the home page reads LATER anyway while it prints prices (`site.currency_symbol_position`, by
 * PriceDisplayFormatter): the repository memoises every key it fetched, so the intro costs the home page NO query of its own (its query number is
 * pinned). The page's own order of work is what makes this true: the controller asks for the intro before the listing is formatted.
 */
final class StorefrontHomeSettings
{
    public const TITLE_KEY = 'storefront.home.title';

    public const TEXT_KEY = 'storefront.home.text';

    public const MAX_TITLE = 120;

    public const MAX_TEXT = 400;

    /** The key the price formatter reads while the page renders: fetched in the same query (see the class note). */
    private const COFETCHED_KEYS = ['site.currency_symbol_position'];

    public function __construct(private readonly SiteSettingsRepository $settings)
    {
    }

    /** The configured heading, or null: then the language default is used. */
    public function title(): ?string
    {
        return $this->clean(self::TITLE_KEY, self::MAX_TITLE);
    }

    /** The configured text, or null: then the language default is used. */
    public function text(): ?string
    {
        return $this->clean(self::TEXT_KEY, self::MAX_TEXT);
    }

    private function clean(string $key, int $max): ?string
    {
        $value = trim((string) ($this->settings->getMany([self::TITLE_KEY, self::TEXT_KEY, ...self::COFETCHED_KEYS])[$key] ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
