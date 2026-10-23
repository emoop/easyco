<?php

namespace App\Storefront\Appearance;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Footer\FooterLayout;
use App\Storefront\Footer\FooterValidator;
use App\Storefront\Header\HeaderLayout;
use App\Storefront\Header\HeaderValidator;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Compiles the saved appearance values into ONE small stylesheet (storefront-customization-design.md 3.2, C-D8): `:root { --sf-...: ...; }` for every
 * token that DIFFERS from its default, plus the `@font-face` rules of the chosen families ONLY. Written to the `public` disk as
 * `appearance/tokens-{sha1-12}.css` (the name changes with the content, so the link can be immutable); the previous file is kept for pages cached a
 * moment earlier and every older one is removed.
 *
 * PURE apart from the write: css() takes values in and gives the text out. EVERY value is re-validated here (a colour is `#RRGGBB`, a font / radius /
 * button style is an entry of its closed list), so nothing that is not a validated value can reach the output, whoever calls it. Hex colours are written
 * in lower case. An untouched shop (no override) compiles to NOTHING: no file, no link, the fallbacks inside storefront.css apply (today's look).
 */
final class AppearanceCompiler
{
    public const DIRECTORY = 'appearance';

    private const SYSTEM_SANS = "ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";

    private const SERIF = "Georgia, 'Times New Roman', serif";

    /** How far the hover colour of the primary moves towards white: #171717 becomes today's #404040. */
    private const HOVER_MIX = 0.1767;

    public function __construct(private readonly ?string $fontManifestPath = null)
    {
    }

    /**
     * The stylesheet text for these OVERRIDES (setting key => value; a missing key = its default), or null when nothing differs from the defaults.
     *
     * @param  array<string, string>  $overrides
     * @throws InvalidArgumentException a value that is not valid for its key
     */
    public function css(array $overrides, array $metrics = [], array $badgeColors = [], array $footerVars = [], array $headerColors = []): ?string
    {
        $effective = $this->effective($overrides);
        $defaults = AppearanceSettings::defaults();
        $vars = [];

        foreach (AppearanceSettings::COLORS as [$key, $variable]) {
            if ($effective[$key] !== $defaults[$key]) {
                $vars[] = "{$variable}: ".strtolower($effective[$key]).';';

                if ($key === 'appearance.color.primary') {
                    $vars[] = '--sf-primary-hover: '.$this->towardsWhite(strtolower($effective[$key]), self::HOVER_MIX).';';
                }
            }
        }

        if ($effective[AppearanceSettings::FONT_BODY] !== 'system') {
            $vars[] = '--sf-font-body: '.$this->stack($effective[AppearanceSettings::FONT_BODY]).';';
        }

        if ($effective[AppearanceSettings::FONT_HEADING] !== 'same') {
            $vars[] = '--sf-font-heading: '.$this->stack($effective[AppearanceSettings::FONT_HEADING]).';';
        }

        $radius = $effective[AppearanceSettings::RADIUS];

        if ($radius !== 'md') {
            $base = AppearanceSettings::RADII[$radius];

            if ($radius === 'pill') {
                $vars[] = '--sf-r-btn: 9999px;';
            } else {
                $vars[] = '--sf-r-sm: '.$this->px($base * 0.75).';';
                $vars[] = '--sf-r-md: '.$this->px($base).';';
                $vars[] = '--sf-r-lg: '.$this->px($base * 1.5).';';
            }
        }

        if ($effective[AppearanceSettings::BUTTON] === 'outline') {
            // An outline button: no fill, the primary colour as its text and as a 2px ring (an inset shadow: no layout shift).
            $vars[] = '--sf-btn-bg: transparent;';
            $vars[] = '--sf-btn-text: var(--sf-primary, #171717);';
            $vars[] = '--sf-btn-bw: 2px;';
        }

        // D2: the header's row and logo heights (px). A value equal to its default is NOT written (the fallbacks in storefront.css are the defaults).
        $metricDefaults = HeaderLayout::metricDefaults();

        foreach ($metrics as $variable => $value) {
            if (! array_key_exists($variable, $metricDefaults) || ! is_int($value) || $value < 0 || $value > 400) {
                throw new InvalidArgumentException('A header metric is not valid.');
            }

            if ($value !== $metricDefaults[$variable]) {
                $vars[] = "{$variable}: {$value}px;";
            }
        }

        // FT-1: the footer's colours and border (the published footer's variables): a colour `#rrggbb` or a width `Npx`, checked again here.
        foreach ($footerVars as $variable => $value) {
            $isColor = in_array($variable, ['--sf-ftu-bg', '--sf-ftu-text', '--sf-ftu-bc', '--sf-ftl-bg', '--sf-ftl-text'], true) && AppearanceSettings::isHex($value);
            $isWidth = $variable === '--sf-ftu-bw' && is_string($value) && preg_match('/^[0-4]px$/', $value) === 1;

            if (! $isColor && ! $isWidth) {
                throw new InvalidArgumentException('A footer variable is not valid.');
            }

            $vars[] = "{$variable}: ".strtolower($value).';';
        }

        // DS-2a: the header rows' colours (only the rows that set one): `--sf-hdr-{d|m}-{top|main|bottom}-{bg|fg}: #rrggbb`, checked again here.
        foreach ($headerColors as $variable => $value) {
            if (preg_match('/^--sf-hdr-[dm]-(?:top|main|bottom)-(?:bg|fg)$/', (string) $variable) !== 1 || ! AppearanceSettings::isHex($value)) {
                throw new InvalidArgumentException('A header colour is not valid.');
            }

            $vars[] = "{$variable}: ".strtolower($value).';';
        }

        $faces = $this->fontFaces($this->fontKeys($effective));
        $badges = $this->badgeClasses($badgeColors);

        if ($vars === [] && $faces === '' && $badges === '') {
            return null;
        }

        $css = $vars === [] ? '' : ":root {\n    ".implode("\n    ", $vars)."\n}\n";

        return $css.$badges.$faces;
    }

    /**
     * The family keys that need files (never `system`), body first.
     *
     * @param  array<string, string>  $overrides
     * @return list<string>
     */
    public function fontKeys(array $overrides): array
    {
        $effective = $this->effective($overrides);
        $keys = [];

        foreach ([$effective[AppearanceSettings::FONT_BODY], $effective[AppearanceSettings::FONT_HEADING]] as $key) {
            if ($key !== 'system' && $key !== 'same' && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Writes the stylesheet and returns what `appearance.compiled` stores, or null when there is nothing to compile.
     *
     * @param  array<string, string>  $overrides
     * @return array{hash: string, fonts: list<string>, body: ?string}|null
     */
    public function compile(array $overrides, ?string $previousHash = null, ?array $metrics = null): ?array
    {
        $css = $this->css($overrides, $metrics ?? $this->publishedMetrics(), $this->storedBadgeColors(), $this->publishedFooterVars(), $this->publishedHeaderColors());

        if ($css === null) {
            $this->prune(null, $previousHash);

            return null;
        }

        $hash = substr(sha1($css), 0, 12);
        $disk = Storage::disk('public');
        $path = self::DIRECTORY.'/tokens-'.$hash.'.css';

        if (! $disk->exists($path)) {
            $disk->put($path, $css);
        }

        $this->prune($hash, $previousHash);

        $effective = $this->effective($overrides);
        $body = $effective[AppearanceSettings::FONT_BODY];

        return ['hash' => $hash, 'fonts' => $this->fontKeys($overrides), 'body' => $body === 'system' ? null : $body];
    }

    /**
     * MENU-2: one class per custom badge colour (`.sf-badge-c-1a2b3c`), so that a menu item's colour is never a `style=` attribute. The text colour is the one (white or
     * black) with the better contrast on that background.
     *
     * @param  list<string>  $colors  `#rrggbb`, already validated by the menu validator (checked again here)
     */
    private function badgeClasses(array $colors): string
    {
        $css = '';

        foreach (array_slice(array_values(array_unique(array_map('strtolower', array_filter($colors, fn ($c): bool => AppearanceSettings::isHex($c))))), 0, 48) as $color) {
            $light = $this->luminance($color) > 0.179;
            $css .= '.sf-badge-c-'.substr($color, 1).' { --sf-badge-bg: '.$color.'; --sf-badge-fg: '.($light ? '#111111' : '#ffffff').'; }'."\n";
        }

        return $css;
    }

    /** WCAG relative luminance of `#rrggbb`. */
    private function luminance(string $hex): float
    {
        $channel = static function (int $value): float {
            $v = $value / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel((int) hexdec(substr($hex, 1, 2))) + 0.7152 * $channel((int) hexdec(substr($hex, 3, 2))) + 0.0722 * $channel((int) hexdec(substr($hex, 5, 2)));
    }

    /**
     * The custom badge colours of the STORED compiled menus (`menus.compiled`), so that an appearance save never drops their classes from the stylesheet.
     *
     * @return list<string>
     */
    private function storedBadgeColors(): array
    {
        $json = app(SiteSettingsRepository::class)->get(AppearanceSettings::MENUS_COMPILED_KEY);
        $data = is_string($json) ? json_decode($json, true) : null;

        return is_array($data) ? \App\Storefront\Menu\MenuCompiler::customColors($data['menus'] ?? []) : [];
    }

    /**
     * The sizes of the PUBLISHED header (header.compiled), so that an appearance save never drops them from the stylesheet. Re-validated: a layout that
     * does not validate contributes nothing.
     *
     * @return array<string, int>
     */
    private function publishedMetrics(): array
    {
        $json = app(SiteSettingsRepository::class)->get(AppearanceSettings::HEADER_COMPILED_KEY);
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data) || ! is_array($data['layout'] ?? null)) {
            return [];
        }

        $validation = HeaderValidator::validate($data['layout']);

        return $validation->passes() ? HeaderLayout::metrics($validation->layout) : [];
    }

    /**
     * The row colours of the PUBLISHED header (header.compiled), kept on every later appearance or footer save like the heights. Re-validated.
     *
     * @return array<string, string>
     */
    private function publishedHeaderColors(): array
    {
        $json = app(SiteSettingsRepository::class)->get(AppearanceSettings::HEADER_COMPILED_KEY);
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data) || ! is_array($data['layout'] ?? null)) {
            return [];
        }

        $validation = HeaderValidator::validate($data['layout']);

        return $validation->passes() ? HeaderLayout::colorVars($validation->layout) : [];
    }

    /**
     * The colours and border of the PUBLISHED footer (footer.compiled), so that an appearance or header save never drops them from the stylesheet. Re-validated.
     *
     * @return array<string, string>
     */
    private function publishedFooterVars(): array
    {
        $json = app(SiteSettingsRepository::class)->get(AppearanceSettings::FOOTER_COMPILED_KEY);
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data) || ! is_array($data['layout'] ?? null)) {
            return [];
        }

        $validation = FooterValidator::validate($data['layout']);

        return $validation->passes() ? FooterLayout::vars($validation->layout) : [];
    }

    /** Keeps the new file and the previous one; removes every other tokens file. */
    private function prune(?string $current, ?string $previous): void
    {
        $disk = Storage::disk('public');
        $keep = array_filter([$current, $previous]);

        foreach ($disk->files(self::DIRECTORY) as $file) {
            if (preg_match('#/tokens-([0-9a-f]{12})\.css$#', $file, $m) === 1 && ! in_array($m[1], $keep, true)) {
                $disk->delete($file);
            }
        }
    }

    /**
     * Every token with its value (the default where not overridden), each one validated.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function effective(array $overrides): array
    {
        $effective = AppearanceSettings::defaults();

        foreach ($overrides as $key => $value) {
            if (! array_key_exists($key, $effective)) {
                throw new InvalidArgumentException('Unknown appearance setting.');
            }

            $effective[$key] = $value;
        }

        foreach ($effective as $key => $value) {
            if (! AppearanceSettings::isValid($key, $value)) {
                throw new InvalidArgumentException('The value of an appearance setting is not valid.');
            }
        }

        return $effective;
    }

    private function stack(string $font): string
    {
        if ($font === 'system') {
            return self::SYSTEM_SANS;
        }

        $family = $this->manifest()[$font]['family'] ?? null;

        if (! is_string($family) || preg_match('/^[A-Za-z0-9 ]{1,40}$/', $family) !== 1) {
            throw new InvalidArgumentException('The font family is not available.');
        }

        return "'{$family}', ".($font === 'lora' ? self::SERIF : self::SYSTEM_SANS);
    }

    /** @param  list<string>  $keys */
    private function fontFaces(array $keys): string
    {
        $css = '';

        foreach ($keys as $key) {
            $entry = $this->manifest()[$key] ?? null;

            if (! is_array($entry)) {
                throw new InvalidArgumentException('The font is not available.');
            }

            foreach ($entry['faces'] as $face) {
                if (preg_match('/^[a-z0-9-]{1,80}\.woff2$/', (string) $face['file']) !== 1 || preg_match('/^[U+0-9A-Fa-f,\- ]{1,400}$/', (string) $face['range']) !== 1) {
                    throw new InvalidArgumentException('The font manifest is not valid.');
                }

                $css .= "@font-face {\n    font-family: '{$entry['family']}';\n    font-style: normal;\n    font-display: swap;\n    font-weight: 100 900;\n"
                    ."    src: url(/fonts/storefront/{$key}/{$face['file']}) format('woff2-variations');\n    unicode-range: {$face['range']};\n}\n";
            }
        }

        return $css;
    }

    /** @return array<string, array{family: string, faces: list<array{subset: string, file: string, range: string}>}> */
    private function manifest(): array
    {
        $path = $this->fontManifestPath ?? public_path('fonts/storefront/fonts.json');
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($data)) {
            throw new InvalidArgumentException('The font manifest is missing (run npm run build).');
        }

        return $data;
    }

    private function px(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').'px';
    }

    private function towardsWhite(string $hex, float $amount): string
    {
        $out = '#';

        foreach ([substr($hex, 1, 2), substr($hex, 3, 2), substr($hex, 5, 2)] as $part) {
            $c = (int) hexdec($part);
            $out .= str_pad(dechex((int) round($c + (255 - $c) * $amount)), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }
}
