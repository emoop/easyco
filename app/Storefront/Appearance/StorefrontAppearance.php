<?php

namespace App\Storefront\Appearance;

use App\Rules\PlainText;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\StoreTimezone;
use App\Storefront\Footer\FooterValidator;
use App\Storefront\Footer\FooterView;
use App\Storefront\Header\HeaderLayout;
use App\Storefront\Header\HeaderValidator;
use App\Storefront\Header\HeaderView;
use App\Storefront\Menu\CompiledMenus;
use App\Storefront\Menu\MenuRecompiler;
use DateTimeImmutable;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * THE ONE READER of everything the storefront shows from the appearance settings (storefront-customization-design.md 3.4): the compiled token
 * stylesheet's hash and fonts, the theme key and the top banner. ONE `getMany` of AppearanceSettings::READ_KEYS (D2 adds `header.compiled` to the same
 * list), memoised for the request, and the result is kept in the APPLICATION CACHE under the stable key `storefront.appearance` (no expiry): the admin
 * save REPLACES that entry after a successful save (`refresh()`), and a cache miss falls back to the one `getMany` and fills the entry. A warm page pays
 * no query for appearance; a cold one pays exactly one, the same for every page.
 *
 * The banner's DATES are compared per request (a comparison, no query) in the store time zone. Every stored value is validated again when the payload
 * is built, so a hand-edited database row cannot print an unsafe link or a text with control characters: such a banner is simply not shown.
 */
#[Scoped]
final class StorefrontAppearance
{
    public const CACHE_KEY = 'storefront.appearance';

    /** @var array{theme: ?string, compiled: ?array{hash: string, fonts: list<string>, body: ?string}, banner: array<string, mixed>, header: ?array<string, mixed>}|null */
    private ?array $data = null;

    /** The layout the header preview shows (the admin's DRAFT), set for ONE request by the preview controller; null on every public request. */
    private ?array $headerOverride = null;

    private ?string $previewCss = null;

    /** DS-5: the banner of the admin's signed preview of the top banner's draft (never set on a public request). @var array<string, mixed>|null */
    private ?array $bannerOverride = null;

    /** The simplified checkout header the Design screen's preview shows (the admin's DRAFT, or null = the full header), set for ONE request by the preview controller; false on every public request. */
    private array|false|null $checkoutHeaderOverride = false;

    /** The validated PUBLISHED simplified checkout header (null = off), memoised for the request; false = not read yet. */
    private array|false|null $checkoutHeader = false;

    /** The compiled footer the footer preview shows (the admin's DRAFT: {layout, menus, blocks}), set for ONE request by the preview controller; null on every public request. */
    private ?array $footerOverride = null;

    /** SC-1: the compiled right column of the product page ({layout, blocks}) the admin's signed preview shows (the DRAFT), set for ONE request by the preview controller; null on every public request. */
    private ?array $summaryOverride = null;

    /** The prepared right column (what the product view loops over); null = not computed yet. @var list<array{type: string, html: string}>|null */
    private ?array $summaryPrepared = null;

    /** The prepared footer (what the views print) for this request; false = not computed yet. */
    private array|false|null $footerPrepared = false;

    /** The validated published layout (or the seeded default), memoised for the request. */
    private ?array $headerLayout = null;

    /** The validated compiled menus (menu key => items), memoised for the request. @var array<string, list<array<string, mixed>>>|null */
    private ?array $menus = null;

    public function __construct(
        private readonly SiteSettingsRepository $settings,
        private readonly StoreTimezone $timezone,
    ) {
    }

    /**
     * @return array{theme: ?string, compiled: ?array{hash: string, fonts: list<string>, body: ?string}, banner: array<string, mixed>}
     */
    public function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        // A menu rebuild asked for earlier in this process (a page or category changed) runs before the first read, so nothing is read stale.
        $recompiler = app(MenuRecompiler::class);

        if ($recompiler->hasPending()) {
            $recompiler->flush();
        }

        if ($this->data !== null) {
            return $this->data;
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && array_key_exists('theme', $cached) && array_key_exists('compiled', $cached) && array_key_exists('header', $cached) && array_key_exists('menus', $cached) && array_key_exists('locations', $cached) && array_key_exists('footer', $cached) && array_key_exists('design', $cached) && is_array($cached['banner'] ?? null)) {
            return $this->data = $cached;
        }

        return $this->data = $this->rebuild();
    }

    /** Reads the settings (ONE query) and REPLACES the cache entry: what the admin save calls after a successful save. */
    public function refresh(): void
    {
        $this->checkoutHeader = false;
        $this->headerLayout = null;
        $this->menus = null;
        $this->footerPrepared = false;
        $this->summaryPrepared = null;
        $this->data = $this->rebuild();
    }

    /** @return array{theme: ?string, compiled: ?array{hash: string, fonts: list<string>, body: ?string}, banner: array<string, mixed>} */
    private function rebuild(): array
    {
        $payload = self::build($this->settings->getMany(AppearanceSettings::READ_KEYS));
        Cache::forever(self::CACHE_KEY, $payload);

        return $payload;
    }

    /**
     * The payload for the raw stored values (pure: no query, no clock).
     *
     * @param  array<string, ?string>  $raw
     * @return array{theme: ?string, compiled: ?array{hash: string, fonts: list<string>, body: ?string}, banner: array<string, mixed>}
     */
    public static function build(array $raw): array
    {
        $theme = trim((string) ($raw[AppearanceSettings::THEME_KEY] ?? ''));

        return [
            'theme' => $theme === '' ? null : mb_substr($theme, 0, 40),
            'compiled' => self::parseCompiled($raw[AppearanceSettings::COMPILED_KEY] ?? null),
            'banner' => self::parseBanner($raw),
            'header' => self::parseHeader($raw[AppearanceSettings::HEADER_COMPILED_KEY] ?? null),
            'menus' => self::parseMenus($raw[AppearanceSettings::MENUS_COMPILED_KEY] ?? null),
            'locations' => self::parseLocations($raw[AppearanceSettings::MENUS_COMPILED_KEY] ?? null),
            'footer' => self::parseFooter($raw[AppearanceSettings::FOOTER_COMPILED_KEY] ?? null),
            // DS-2b: the Design screen's compiled document (the simplified checkout header today); validated again when it is read
            'design' => is_string($raw[AppearanceSettings::DESIGN_COMPILED_KEY] ?? null) ? $raw[AppearanceSettings::DESIGN_COMPILED_KEY] : null,
        ];
    }

    /** The stored compiled menus as an array (NOT yet trusted: menus() validates them on every read), or null. */
    private static function parseMenus(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) && is_array($data['menus'] ?? null) ? $data['menus'] : null;
    }

    /** The stored compiled footer ({layout, menus, blocks}; NOT yet trusted: footer() validates it on every read), or null. */
    private static function parseFooter(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) && is_array($data['layout'] ?? null) ? ['layout' => $data['layout'], 'menus' => is_array($data['menus'] ?? null) ? $data['menus'] : [], 'blocks' => is_array($data['blocks'] ?? null) ? $data['blocks'] : []] : null;
    }

    /** The stored `locations` object of the compiled menus (NOT yet trusted: locations() validates it), or null. */
    private static function parseLocations(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) && is_array($data['locations'] ?? null) ? $data['locations'] : null;
    }

    /** The stored published layout as an array (NOT yet trusted: header() validates it on every read), or null. */
    private static function parseHeader(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) && is_array($data['layout'] ?? null) ? $data['layout'] : null;
    }

    /** @return array{hash: string, fonts: list<string>, body: ?string}|null */
    private static function parseCompiled(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        $data = json_decode($json, true);

        if (! is_array($data) || ! is_string($data['hash'] ?? null) || preg_match('/^[0-9a-f]{12}$/', $data['hash']) !== 1) {
            return null;
        }

        $fonts = array_values(array_filter((array) ($data['fonts'] ?? []), static fn ($font): bool => is_string($font) && array_key_exists($font, AppearanceSettings::FONTS) && $font !== 'system'));
        $body = $data['body'] ?? null;

        $body = is_string($body) && in_array($body, $fonts, true) ? $body : null;
        $compiled = ['hash' => $data['hash'], 'fonts' => $fonts, 'body' => $body];
        // FN-1: the file of a static body family (a file name of the closed form; else the old name pattern is used)
        $preload = $data['preload'] ?? null;
        $body !== null && is_string($preload) && preg_match('/^[a-z0-9-]{1,80}\.woff2$/', $preload) === 1 && $compiled['preload'] = $preload;

        return $compiled;
    }

    /**
     * The banner as stored, validated: `enabled` is false for anything that would not be safe to print.
     *
     * @param  array<string, ?string>  $raw
     * @return array<string, mixed>
     */
    private static function parseBanner(array $raw): array
    {
        $text = trim((string) ($raw[AppearanceSettings::BANNER_TEXT] ?? ''));
        $url = trim((string) ($raw[AppearanceSettings::BANNER_URL] ?? ''));
        $label = trim((string) ($raw[AppearanceSettings::BANNER_LINK_LABEL] ?? ''));
        $enabled = ($raw[AppearanceSettings::BANNER_ENABLED] ?? null) === '1'
            && $text !== '' && mb_strlen($text) <= AppearanceSettings::BANNER_TEXT_MAX && self::isPlain($text);

        if ($url !== '' && ! AppearanceSettings::isSafeBannerUrl($url)) {
            $url = '';
        }

        if ($label !== '' && (mb_strlen($label) > AppearanceSettings::BANNER_LABEL_MAX || ! self::isPlain($label))) {
            $label = '';
        }

        return [
            'enabled' => $enabled,
            'text' => $enabled ? $text : '',
            'url' => $enabled && $url !== '' ? $url : null,
            'label' => $enabled && $url !== '' && $label !== '' ? $label : null,
            'starts_at' => self::date($raw[AppearanceSettings::BANNER_STARTS_AT] ?? null),
            'ends_at' => self::date($raw[AppearanceSettings::BANNER_ENDS_AT] ?? null),
        ];
    }

    private static function isPlain(string $text): bool
    {
        $failed = false;
        (new PlainText)->validate('text', $text, static function () use (&$failed): void {
            $failed = true;
        });

        return ! $failed;
    }

    /** A stored `Y-m-d H:i` (store time zone) or null. */
    private static function date(?string $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * The layout to print: the PUBLISHED one, re-validated against the catalog on every read (a hand-edited row or cache entry that does not validate
     * shows the seeded default instead), or the seeded default when nothing is published. During a header preview, the draft the controller set.
     *
     * @return array<string, mixed>
     */
    public function header(): array
    {
        if ($this->headerOverride !== null) {
            return $this->headerOverride;
        }

        if ($this->headerLayout !== null) {
            return $this->headerLayout;
        }

        $stored = $this->data()['header'];

        if ($stored !== null) {
            $validation = HeaderValidator::validate($stored);

            if ($validation->passes()) {
                return $this->headerLayout = $validation->layout;
            }
        }

        return $this->headerLayout = HeaderLayout::default();
    }

    /**
     * The compiled menus, validated again on every read (an invalid menu is empty and logged once).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function menus(): array
    {
        return $this->menus ??= CompiledMenus::read($this->data()['menus']);
    }

    /** One compiled menu by key ([] when there is none). @return list<array<string, mixed>> */
    public function menu(string $key): array
    {
        return $this->menus()[$key] ?? [];
    }

    /**
     * The menu key of each location (header, drawer, footer); a location with no menu is null and renders NOTHING.
     *
     * @return array<string, ?string>
     */
    public function locations(): array
    {
        return CompiledMenus::locations($this->data()['locations'] ?? null, $this->menus());
    }

    /** The menu of a location ([] when it has none). @return list<array<string, mixed>> */
    public function menuAt(string $location): array
    {
        $key = $this->locations()[$location] ?? null;

        return $key === null ? [] : $this->menu($key);
    }

    /**
     * The footer to print: the PUBLISHED layout with its menus and HTML blocks, validated again on every read (a layout that does not validate = null), prepared for the views
     * (FooterView; the year is put in later, by the view, at render time), or null when nothing is published: the shop then prints its OLD footer. During a footer preview, the draft.
     *
     * @return array{upper: ?array<string, mixed>, lower: ?array<string, mixed>}|null
     */
    public function footer(): ?array
    {
        if ($this->footerPrepared !== false) {
            return $this->footerPrepared;
        }

        $stored = $this->footerOverride ?? $this->data()['footer'];

        if ($stored === null) {
            return $this->footerPrepared = null;
        }

        $validation = FooterValidator::validate($stored['layout']);

        if (! $validation->passes()) {
            return $this->footerPrepared = null;
        }

        $blocks = [];

        foreach ($stored['blocks'] as $id => $html) {
            if (is_string($html) && strlen($html) <= 65_536) {
                $blocks[(string) $id] = $html;
            }
        }

        return $this->footerPrepared = FooterView::prepare($validation->layout, CompiledMenus::read($stored['menus']), $blocks);
    }

    /**
     * SC-1: the components of the product page's right column, in order: the PUBLISHED layout (read from the one cached payload, no query, validated again on every read), or the default layout when
     * none is published or it does not validate. During a signed preview, the draft.
     *
     * @return list<array{type: string, html: string}>
     */
    public function productSummary(): array
    {
        if ($this->summaryPrepared !== null) {
            return $this->summaryPrepared;
        }

        $stored = $this->summaryOverride ?? \App\Storefront\ProductSummary\ProductSummaryCompiler::published($this->data()['design'] ?? null);

        return $this->summaryPrepared = \App\Storefront\ProductSummary\ProductSummaryView::prepare($stored);
    }

    /** For ONE request (SC-1, the admin's signed preview): show this compiled right column ({layout, blocks}, validated) instead of the published one. Never called on a public request. @param array<string, mixed> $compiled */
    public function previewProductSummary(array $compiled): void
    {
        $this->summaryOverride = $compiled;
        $this->summaryPrepared = null;
    }

    /** For ONE request: show this compiled footer ({layout, menus, blocks}) instead of the published one, and link the stylesheet with its colours. Never called on a public request. */
    public function previewFooter(array $compiled, ?string $cssUrl): void
    {
        $this->footerOverride = $compiled;
        $this->footerPrepared = false;
        $this->previewCss = $cssUrl;
    }

    /**
     * The simplified header of the cart and the checkout (DS-2b): the published one, read from the one cached payload (no query) and validated again, or null when it is off, never
     * published or invalid (the shop then keeps its full header).
     *
     * @return array<string, mixed>|null
     */
    public function checkoutHeader(): ?array
    {
        if ($this->checkoutHeaderOverride !== false) {
            return $this->checkoutHeaderOverride;
        }

        if ($this->checkoutHeader === false) {
            $values = \App\Storefront\CheckoutHeader\CheckoutHeaderCompiler::published($this->data()['design'] ?? null);
            $this->checkoutHeader = $values !== null && $values['enabled'] === true ? $values : null;
        }

        return $this->checkoutHeader;
    }

    /** For ONE request: show this (validated) simplified header on the cart page instead of the full one (null = the full one), and link the stylesheet with its variables. Never called on a public request. */
    public function previewCheckoutHeader(?array $values, ?string $cssUrl): void
    {
        $this->checkoutHeaderOverride = $values;
        $this->previewCss = $cssUrl;
    }

    /** For ONE request (DS-5, the admin's signed preview): show this banner (StorefrontAppearance::bannerFromValues) instead of the published one, and link the stylesheet with its colours. Never called on a public request. @param array<string, mixed> $banner */
    public function previewBanner(array $banner, ?string $cssUrl): void
    {
        $this->bannerOverride = $banner;
        $this->previewCss = $cssUrl;
    }

    /**
     * The banner of the Design screen's values (`enabled`, `text`, `url`, `link_label`, `starts_at`, `ends_at`), parsed by the SAME rules as the published one: anything that would not be safe to print
     * turns the banner off. For the admin's draft preview only.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function bannerFromValues(array $values): array
    {
        return self::parseBanner([
            AppearanceSettings::BANNER_ENABLED => ! empty($values['enabled']) ? '1' : null,
            AppearanceSettings::BANNER_TEXT => is_string($values['text'] ?? null) ? $values['text'] : '',
            AppearanceSettings::BANNER_URL => is_string($values['url'] ?? null) ? $values['url'] : '',
            AppearanceSettings::BANNER_LINK_LABEL => is_string($values['link_label'] ?? null) ? $values['link_label'] : '',
            AppearanceSettings::BANNER_STARTS_AT => is_string($values['starts_at'] ?? null) ? $values['starts_at'] : null,
            AppearanceSettings::BANNER_ENDS_AT => is_string($values['ends_at'] ?? null) ? $values['ends_at'] : null,
        ]);
    }

    /** For ONE request: show this (already validated) layout instead of the published one, and link the stylesheet with its sizes. Never called on a public request. */
    public function previewHeader(array $layout, ?string $cssUrl): void
    {
        $this->headerOverride = $layout;
        $this->previewCss = $cssUrl;
    }

    public function themeKey(): ?string
    {
        return $this->data()['theme'];
    }

    /** @return array{hash: string, fonts: list<string>, body: ?string}|null */
    public function compiled(): ?array
    {
        return $this->data()['compiled'];
    }

    /**
     * The banner to print NOW, or null: off, empty, or outside its dates (compared in the store time zone, no query).
     *
     * @return array{text: string, url: ?string, label: ?string}|null
     */
    public function banner(?DateTimeImmutable $now = null): ?array
    {
        $banner = $this->bannerOverride ?? $this->data()['banner'];

        if (($banner['enabled'] ?? false) !== true) {
            return null;
        }

        $zone = $this->timezone->zone();
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone($zone);

        foreach (['starts_at' => 1, 'ends_at' => -1] as $field => $direction) {
            if ($banner[$field] === null) {
                continue;
            }

            try {
                $edge = new DateTimeImmutable($banner[$field], $zone);
            } catch (Throwable) {
                continue;
            }

            if (($direction === 1 && $now < $edge) || ($direction === -1 && $now >= $edge)) {
                return null;
            }
        }

        return ['text' => $banner['text'], 'url' => $banner['url'], 'label' => $banner['label']];
    }

    /**
     * What the layout needs: the token stylesheet's URL, the font to preload (the body font's latin file only) and the banner.
     *
     * @return array{tokensUrl: ?string, preloadFont: ?string, banner: array{text: string, url: ?string, label: ?string}|null, header: array<string, mixed>, footerMenu: list<array<string, mixed>>, footer: ?array<string, mixed>, previewCss: ?string}
     */
    public function forLayout(): array
    {
        $compiled = $this->compiled();

        return [
            'tokensUrl' => $compiled === null ? null : '/storage/'.\App\Storefront\Appearance\AppearanceCompiler::DIRECTORY.'/tokens-'.$compiled['hash'].'.css',
            'preloadFont' => $compiled === null || $compiled['body'] === null ? null : '/fonts/storefront/'.$compiled['body'].'/'.($compiled['preload'] ?? $compiled['body'].'-latin-wght-normal.woff2'),
            'banner' => $this->banner(),
            'header' => HeaderView::prepare($this->header(), $this->menuAt('header'), $this->menuAt('drawer') ?: $this->menuAt('header')),
            'footerMenu' => $this->menuAt('footer'),
            'footer' => $this->footer(),
            'previewCss' => $this->previewCss,
            // DS-2b: the simplified header of the cart, the checkout and the order-received page; `forced` = the Design screen's preview, which is not one of those routes
            'checkoutHeader' => $this->checkoutHeader(),
            'checkoutHeaderForced' => $this->checkoutHeaderOverride !== false,
        ];
    }
}
