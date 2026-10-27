<?php

namespace App\Settings;

use App\Settings\Contracts\SiteSettingsRepository;

/**
 * THE ONE READER of the product page gallery's settings (GL-1, product-display-spec.md 4.2): a small read-only value object over a closed set
 * of values per setting. A stored value outside its set (or none) is the default: never an error. There is no admin screen yet (GL-2).
 *
 * ONE `getMany`, shared with the key the page reads anyway while it prints prices (`site.currency_symbol_position`): the repository memoises
 * every key it fetched, so the gallery costs the product page NO query of its own (its query number is pinned).
 */
final readonly class GallerySettings
{
    public const DESKTOP_MODE_KEY = 'storefront.gallery.desktop_mode';

    public const MOBILE_MODE_KEY = 'storefront.gallery.mobile_mode';

    public const WIDTH_PERCENT_KEY = 'storefront.gallery.width_percent';

    public const ODD_LAST_KEY = 'storefront.gallery.odd_last';

    public const HOVER_ZOOM_KEY = 'storefront.gallery.hover_zoom';

    public const DESKTOP_MODES = ['grid', 'thumbs-left', 'thumbs-below'];

    public const MOBILE_MODES = ['carousel', 'thumbs-below'];

    public const WIDTH_PERCENTS = [50, 55, 60, 65, 70, 75, 80];

    public const ODD_LAST = ['same', 'full'];

    public const HOVER_ZOOMS = ['light', 'medium', 'off'];

    public const DEFAULT_DESKTOP_MODE = 'grid';

    public const DEFAULT_MOBILE_MODE = 'carousel';

    public const DEFAULT_WIDTH_PERCENT = 65;

    public const DEFAULT_ODD_LAST = 'same';

    public const DEFAULT_HOVER_ZOOM = 'light';

    /** The key the price formatter reads while the page renders: fetched in the same query (see the class note). */
    private const COFETCHED_KEYS = ['site.currency_symbol_position'];

    public function __construct(
        public string $desktopMode = self::DEFAULT_DESKTOP_MODE,
        public string $mobileMode = self::DEFAULT_MOBILE_MODE,
        public int $widthPercent = self::DEFAULT_WIDTH_PERCENT,
        public string $oddLast = self::DEFAULT_ODD_LAST,
        public string $hoverZoom = self::DEFAULT_HOVER_ZOOM,
    ) {
    }

    /** Every value outside its closed set becomes the default (the stored values are strings). */
    public static function fromValues(?string $desktopMode, ?string $mobileMode, ?string $widthPercent, ?string $oddLast, ?string $hoverZoom): self
    {
        $width = $widthPercent !== null && preg_match('/^\d{1,3}$/', trim($widthPercent)) === 1 ? (int) trim($widthPercent) : self::DEFAULT_WIDTH_PERCENT;

        return new self(
            self::pick($desktopMode, self::DESKTOP_MODES, self::DEFAULT_DESKTOP_MODE),
            self::pick($mobileMode, self::MOBILE_MODES, self::DEFAULT_MOBILE_MODE),
            in_array($width, self::WIDTH_PERCENTS, true) ? $width : self::DEFAULT_WIDTH_PERCENT,
            self::pick($oddLast, self::ODD_LAST, self::DEFAULT_ODD_LAST),
            self::pick($hoverZoom, self::HOVER_ZOOMS, self::DEFAULT_HOVER_ZOOM),
        );
    }

    public static function fromRepository(SiteSettingsRepository $settings): self
    {
        $values = $settings->getMany([
            self::DESKTOP_MODE_KEY, self::MOBILE_MODE_KEY, self::WIDTH_PERCENT_KEY, self::ODD_LAST_KEY, self::HOVER_ZOOM_KEY,
            ...self::COFETCHED_KEYS,
        ]);

        return self::fromValues(
            $values[self::DESKTOP_MODE_KEY],
            $values[self::MOBILE_MODE_KEY],
            $values[self::WIDTH_PERCENT_KEY],
            $values[self::ODD_LAST_KEY],
            $values[self::HOVER_ZOOM_KEY],
        );
    }

    /** The class that sets the gallery's share of the two-column area (never a `style=`). */
    public function widthClass(): string
    {
        return 'sf-gal-w-'.$this->widthPercent;
    }

    /** @return array<string, string> the data attributes of the gallery container, for the view to print (escaped) */
    public function dataAttributes(): array
    {
        return [
            'data-gal-desktop' => $this->desktopMode,
            'data-gal-mobile' => $this->mobileMode,
            'data-gal-odd' => $this->oddLast,
            'data-gal-zoom' => $this->hoverZoom,
        ];
    }

    /** @param list<string> $allowed */
    private static function pick(?string $value, array $allowed, string $default): string
    {
        $value = $value === null ? null : trim($value);

        return $value !== null && in_array($value, $allowed, true) ? $value : $default;
    }
}
