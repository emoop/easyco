<?php

namespace App\Settings;

use App\Content\Models\PageModel;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Cookies\Contrast;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CC-1 (cookie-consent.md): THE ONE READER AND WRITER of the cookie banner's settings. Closed lists, ranges, hex colours, the plain texts of each shop language and the text version.
 *
 *   cookies.enabled         '1' | '0'            default OFF (nothing optional exists on the storefront yet)
 *   cookies.position        center | bottom      default center
 *   cookies.initial_view    summary | details    default summary (layer 1)
 *   cookies.validity_months 1..13                default 12
 *   cookies.policy_page_id  a PUBLISHED page     nullable
 *   cookies.policy_link     {slug, title}        derived from the page, kept in step by refreshPolicyLink() (the storefront reads it without a query)
 *   cookies.button_color    #rrggbb              default #111111
 *   cookies.texts.<locale>  ONE JSON object per shop language (bg, en); a missing / damaged / empty key falls back, per key, to the shipped lang/<locale>/cookies.php
 *   cookies.text_version    int                  bumped by THIS class only, when a text or the picked policy page changes
 *
 * `payload()` and `vars()` are PURE (raw stored strings in, a validated array out): the storefront reads them through StorefrontAppearance (one `getMany`, then the application cache), the
 * token stylesheet through AppearanceCompiler. Nothing here reads a cookie, a request or the clock.
 */
final class CookieSettings
{
    public const ENABLED = 'cookies.enabled';

    public const POSITION = 'cookies.position';

    public const VIEW = 'cookies.initial_view';

    public const VALIDITY = 'cookies.validity_months';

    public const POLICY_PAGE = 'cookies.policy_page_id';

    public const POLICY_LINK = 'cookies.policy_link';

    public const COLOR = 'cookies.button_color';

    public const VERSION = 'cookies.text_version';

    public const TEXTS = 'cookies.texts.';

    public const POSITIONS = ['center', 'bottom'];

    public const VIEWS = ['summary', 'details'];

    /** The shop languages that have shipped defaults (lang/<locale>/cookies.php). */
    public const LOCALES = ['bg', 'en'];

    /** The OPTIONAL categories (always off until chosen). One today; a second one is a new entry here and its two texts. */
    public const CATEGORIES = ['ads'];

    public const DEFAULT_COLOR = '#111111';

    public const MIN_MONTHS = 1;

    public const MAX_MONTHS = 13;

    /** text key => max length (the closed list of texts) */
    public const TEXT_LIMITS = [
        'title' => 80, 'text' => 600, 'accept_all' => 30, 'reject' => 30, 'settings_link' => 30, 'layer2_title' => 80,
        'necessary_title' => 60, 'necessary_text' => 300, 'ads_title' => 60, 'ads_text' => 300, 'always_on' => 40, 'save' => 40, 'back' => 30, 'footer_link' => 40,
        // CC-2: the accessible name of the «×» that closes the banner without a choice, and the one short line of the thin bar shown on the policy page
        'close' => 30, 'bar_text' => 200,
    ];

    /** The keys the storefront reads (one `getMany`, with the appearance keys): the banner's settings, the derived policy link and the texts of every shop language. */
    public const READ_KEYS_LIST = [
        self::ENABLED, self::POSITION, self::VIEW, self::VALIDITY, self::POLICY_LINK, self::COLOR, self::VERSION, self::TEXTS.'bg', self::TEXTS.'en',
    ];

    /** @return list<string> */
    public static function readKeys(): array
    {
        return self::READ_KEYS_LIST;
    }

    /** @return array<string, string> the shipped texts of $locale */
    public static function defaults(string $locale): array
    {
        $texts = [];

        foreach (array_keys(self::TEXT_LIMITS) as $key) {
            $texts[$key] = (string) __('cookies.'.$key, [], $locale);
        }

        return $texts;
    }

    /**
     * The texts of $locale: each key the stored one when it is a non-empty, plain, in-limit string, else the shipped default. NEVER empty.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, string>
     */
    public static function resolveTexts(array $stored, string $locale): array
    {
        $texts = self::defaults($locale);

        foreach (self::TEXT_LIMITS as $key => $limit) {
            $value = $stored[$key] ?? null;

            if (is_string($value) && trim($value) !== '' && mb_strlen($value) <= $limit && preg_match('/[\p{Cc}\p{Cf}]/u', $value) !== 1) {
                $texts[$key] = $value;
            }
        }

        return $texts;
    }

    /**
     * What the storefront needs, from the raw stored values: null while the banner is off.
     *
     * @param  array<string, ?string>  $raw
     * @return array{position: string, view: string, months: int, version: int, texts: array<string, array<string, string>>, policy: ?array{slug: string, title: string}}|null
     */
    public static function payload(array $raw): ?array
    {
        if (($raw[self::ENABLED] ?? null) !== '1') {
            return null;
        }

        $texts = [];

        foreach (self::LOCALES as $locale) {
            $decoded = json_decode((string) ($raw[self::TEXTS.$locale] ?? ''), true);
            $texts[$locale] = self::resolveTexts(is_array($decoded) ? $decoded : [], $locale);
        }

        $months = (int) ($raw[self::VALIDITY] ?? 0);
        $version = (int) ($raw[self::VERSION] ?? 0);

        return [
            'position' => in_array($raw[self::POSITION] ?? null, self::POSITIONS, true) ? (string) $raw[self::POSITION] : 'center',
            'view' => in_array($raw[self::VIEW] ?? null, self::VIEWS, true) ? (string) $raw[self::VIEW] : 'summary',
            'months' => $months >= self::MIN_MONTHS && $months <= self::MAX_MONTHS ? $months : 12,
            'version' => max(1, $version),
            'texts' => $texts,
            'policy' => self::parsePolicy($raw[self::POLICY_LINK] ?? null),
        ];
    }

    /** @return array{slug: string, title: string}|null */
    private static function parsePolicy(?string $json): ?array
    {
        $link = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($link) || ! is_string($link['slug'] ?? null) || ! is_string($link['title'] ?? null)) {
            return null;
        }

        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._\-]{0,190}$#D', $link['slug']) !== 1 || trim($link['title']) === '' || mb_strlen($link['title']) > 255) {
            return null;
        }

        return ['slug' => $link['slug'], 'title' => $link['title']];
    }

    /**
     * The CSS variables of the buttons (a CLOSED list, only while the banner is on and the colour differs from the default): the colour and the COMPUTED text colour.
     *
     * @param  array<string, ?string>  $raw
     * @return array<string, string>
     */
    public static function vars(array $raw): array
    {
        $color = strtolower((string) ($raw[self::COLOR] ?? ''));

        if (($raw[self::ENABLED] ?? null) !== '1' || ! Contrast::isHex($color) || $color === self::DEFAULT_COLOR) {
            return [];
        }

        return ['--sf-cc-btn' => $color, '--sf-cc-btn-text' => Contrast::bestText($color)['color']];
    }

    public static function isValidVar(string $variable, mixed $value): bool
    {
        return in_array($variable, ['--sf-cc-btn', '--sf-cc-btn-text'], true) && Contrast::isHex($value);
    }

    // ---- the admin side -------------------------------------------------------------------------------------------------------------

    public function __construct(private readonly SiteSettingsRepository $settings)
    {
    }

    /**
     * The stored values for the admin form (texts prefilled with the defaults).
     *
     * @return array<string, mixed>
     */
    public function current(): array
    {
        $raw = $this->settings->getMany([...self::readKeys(), self::POLICY_PAGE]);
        $texts = [];

        foreach (self::LOCALES as $locale) {
            $decoded = json_decode((string) ($raw[self::TEXTS.$locale] ?? ''), true);
            $texts[$locale] = self::resolveTexts(is_array($decoded) ? $decoded : [], $locale);
        }

        $months = (int) ($raw[self::VALIDITY] ?? 0);
        $color = strtolower((string) ($raw[self::COLOR] ?? ''));
        $page = (int) ($raw[self::POLICY_PAGE] ?? 0);

        return [
            'enabled' => ($raw[self::ENABLED] ?? null) === '1',
            'position' => in_array($raw[self::POSITION] ?? null, self::POSITIONS, true) ? (string) $raw[self::POSITION] : 'center',
            'view' => in_array($raw[self::VIEW] ?? null, self::VIEWS, true) ? (string) $raw[self::VIEW] : 'summary',
            'months' => $months >= self::MIN_MONTHS && $months <= self::MAX_MONTHS ? $months : 12,
            'page' => $page > 0 ? $page : null,
            'color' => Contrast::isHex($color) ? $color : self::DEFAULT_COLOR,
            'texts' => $texts,
            'version' => max(1, (int) ($raw[self::VERSION] ?? 0)),
        ];
    }

    /** Is the picked policy page still a published page? (the admin's visible warning is the opposite) */
    public function hasPolicyLink(): bool
    {
        return self::parsePolicy($this->settings->get(self::POLICY_LINK)) !== null;
    }

    /**
     * Validates and stores. The text version is bumped when a text of any language, or the picked policy page, changes (never for position, colour, view or validity).
     * The caller refreshes the storefront payload and the token stylesheet afterwards (CookieSettingsPage: AppearanceRecompiler::run), one purge.
     *
     * @param  array<string, mixed>  $data  {enabled, position, view, months, page, color, texts: {locale: {key: string}}}
     *
     * @throws ValidationException
     */
    public function save(array $data): void
    {
        $clean = $this->validated($data);
        $before = $this->current();

        DB::transaction(function () use ($clean, $before): void {
            $this->settings->set(self::ENABLED, $clean['enabled'] ? '1' : '0');
            $this->settings->set(self::POSITION, $clean['position']);
            $this->settings->set(self::VIEW, $clean['view']);
            $this->settings->set(self::VALIDITY, (string) $clean['months']);
            $this->settings->set(self::COLOR, $clean['color']);

            foreach (self::LOCALES as $locale) {
                $this->settings->set(self::TEXTS.$locale, (string) json_encode($clean['texts'][$locale], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }

            $clean['page'] === null ? $this->settings->forget(self::POLICY_PAGE) : $this->settings->set(self::POLICY_PAGE, (string) $clean['page']);
            $this->refreshPolicyLink();

            if ($clean['texts'] !== $before['texts'] || $clean['page'] !== $before['page']) {
                $this->settings->set(self::VERSION, (string) ($before['version'] + 1));
            } elseif ($this->settings->get(self::VERSION) === null) {
                $this->settings->set(self::VERSION, (string) $before['version']);
            }
        });

        // the menus hold the item «Настройки за бисквитки» only while the banner is on: compiled again now (also on every other save: one rebuild, one purge), never at the end of a request that may not come
        app(\App\Storefront\Menu\MenuRecompiler::class)->now();
    }

    /** Resolves the picked page to {slug, title} (a PUBLISHED page only) or forgets the link: a draft, archived or deleted page leaves NO link. */
    public function refreshPolicyLink(): void
    {
        $id = (int) $this->settings->get(self::POLICY_PAGE);
        $page = $id > 0 ? PageModel::query()->where('status', PageModel::PUBLISHED)->find($id, ['id', 'slug', 'title']) : null;

        $page === null
            ? $this->settings->forget(self::POLICY_LINK)
            : $this->settings->set(self::POLICY_LINK, (string) json_encode(['slug' => (string) $page->slug, 'title' => (string) $page->title], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function policyPageId(): ?int
    {
        $id = (int) $this->settings->get(self::POLICY_PAGE);

        return $id > 0 ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{enabled: bool, position: string, view: string, months: int, page: ?int, color: string, texts: array<string, array<string, string>>}
     *
     * @throws ValidationException
     */
    public function validated(array $data): array
    {
        $errors = [];

        $position = $data['position'] ?? null;
        in_array($position, self::POSITIONS, true) || $errors['data.position'] = __('cookies_admin.errors.position');

        $view = $data['view'] ?? null;
        in_array($view, self::VIEWS, true) || $errors['data.view'] = __('cookies_admin.errors.view');

        $months = $data['months'] ?? null;
        (is_numeric($months) && (int) $months == $months && (int) $months >= self::MIN_MONTHS && (int) $months <= self::MAX_MONTHS)
            || $errors['data.months'] = __('cookies_admin.errors.months', ['min' => self::MIN_MONTHS, 'max' => self::MAX_MONTHS]);

        $page = $data['page'] ?? null;
        $page = $page === null || $page === '' ? null : (is_numeric($page) ? (int) $page : -1);

        if ($page !== null && (int) DB::table('pages')->where('id', $page)->where('status', PageModel::PUBLISHED)->count() !== 1) {
            $errors['data.page'] = __('cookies_admin.errors.page');
        }

        $color = strtolower(trim((string) ($data['color'] ?? '')));

        if (! Contrast::isHex($color)) {
            $errors['data.color'] = __('cookies_admin.errors.color_format');
        } else {
            $best = Contrast::bestText($color);

            if ($best['ratio'] < Contrast::TEXT_MIN) {
                $errors['data.color'] = __('cookies_admin.errors.color_text', ['ratio' => number_format($best['ratio'], 1), 'min' => Contrast::TEXT_MIN]);
            } elseif (Contrast::ratio($color, Contrast::DIALOG_BACKGROUND) < Contrast::AGAINST_DIALOG_MIN) {
                $errors['data.color'] = __('cookies_admin.errors.color_dialog', ['ratio' => number_format(Contrast::ratio($color, Contrast::DIALOG_BACKGROUND), 1), 'min' => Contrast::AGAINST_DIALOG_MIN]);
            }
        }

        $texts = [];

        foreach (self::LOCALES as $locale) {
            foreach (self::TEXT_LIMITS as $key => $limit) {
                $value = $data['texts'][$locale][$key] ?? null;
                $field = "data.texts.{$locale}.{$key}";

                if (! is_string($value) || trim($value) === '') {
                    $errors[$field] = __('cookies_admin.errors.text_required');
                } elseif (mb_strlen($value) > $limit) {
                    $errors[$field] = __('cookies_admin.errors.text_long', ['max' => $limit]);
                } elseif (preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
                    $errors[$field] = __('validation.plain_text');
                } else {
                    $texts[$locale][$key] = trim($value);
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['enabled' => (bool) ($data['enabled'] ?? false), 'position' => (string) $position, 'view' => (string) $view, 'months' => (int) $months, 'page' => $page, 'color' => $color, 'texts' => $texts];
    }
}
