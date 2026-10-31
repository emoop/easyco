<?php

namespace App\Content\HtmlBlocks;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Design\FieldKinds;
use App\Storefront\Header\AppearanceRecompiler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * «Стил на блока» (HB-1): the look of an HTML block as a box (background, text colour, padding, border, corners, width, alignment, size). The storefront forbids inline styles, so the values are
 * VALIDATED here and turned into ONE CSS rule for the class of the block (`.sf-hb-{id}`) by a CLOSED template; the token stylesheet carries the rules and re-checks every one with isValidRule() (the
 * closed-list pattern of PageDesign::isValidVar). Nothing a person types reaches CSS as a string: only validated colours (`#rrggbb`), whole numbers in a range and members of closed sets are printed.
 *
 * A style is «set» when any value differs from its default; a style that is all defaults is stored as null and the block prints with no wrapper (byte-identical to a block that never had one).
 */
final class HtmlBlockStyle
{
    /** The most styled blocks the token stylesheet carries. */
    public const MAX_STYLED = 100;

    public const WIDTH_MODES = ['auto', 'full', 'fixed'];

    public const ALIGNS = ['left', 'center', 'right'];

    public const SIZES = ['small', 'normal', 'large'];

    public const PAD_MAX = 64;

    public const BORDER_MAX = 4;

    public const RADIUS_MAX = 32;

    public const WIDTH_MIN = 100;

    public const WIDTH_MAX = 800;

    /** HB-4: the closed lists of the background picture's choices. */
    public const BG_SIZES = ['cover', 'contain'];

    public const BG_POSITIONS = ['center', 'top', 'bottom', 'left', 'right'];

    public const OVERLAYS = [0, 20, 40, 60];

    public const MIN_HEIGHTS = [0, 160, 240, 320, 400];

    public const VALIGNS = ['top', 'center', 'bottom'];

    /** The keys that mean something only with a picture (`bg_image`). */
    public const PICTURE_KEYS = ['bg_size', 'bg_position', 'overlay', 'min_height', 'valign'];

    /** @var array<string, mixed> every key with its default */
    public const DEFAULTS = [
        'bg' => null, 'color' => null, 'pad_v' => 0, 'pad_h' => 0, 'border_w' => 0, 'border_color' => null, 'radius' => 0,
        'width_mode' => 'auto', 'width_px' => null, 'align' => 'left', 'size' => 'normal',
        // HB-4: a background picture of the library (a media id), how it fills the box, how dark the veil over it is, a minimum height and where the content sits in it
        'bg_image' => null, 'bg_size' => 'cover', 'bg_position' => 'center', 'overlay' => 0, 'min_height' => 0, 'valign' => 'top',
    ];

    private const FONT_SIZES = ['small' => '0.875rem', 'large' => '1.25rem'];

    /** The class a styled block's wrapper carries, and the one the token stylesheet's rule is for. */
    public static function className(int $id): string
    {
        return 'sf-hb-'.$id;
    }

    /**
     * Validates a style as it comes from the form or the database: unknown keys, colours that are not `#rrggbb`, numbers out of range or not whole, a value outside a closed set, a fixed width with
     * no width are refused. `null` / an empty value of a key = its default. Returns the NORMALISED style (every key, colours lower case) and the messages by key.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function validate(mixed $style): array
    {
        $clean = self::DEFAULTS;
        $errors = [];

        if ($style === null || $style === []) {
            return [$clean, $errors];
        }

        if (! is_array($style)) {
            return [$clean, ['style' => (string) __('html_blocks.style.errors.bad_style')]];
        }

        $fail = static function (string $key, string $code, array $params = []) use (&$errors): void {
            $errors[$key] ??= (string) __('html_blocks.style.errors.'.$code, $params);
        };

        foreach (array_diff(array_keys($style), array_keys(self::DEFAULTS)) as $key) {
            $fail('style', 'unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $empty = static fn (mixed $v): bool => $v === null || $v === '';

        foreach (['bg', 'color', 'border_color'] as $key) {
            $value = $style[$key] ?? null;

            if ($empty($value)) {
                continue;
            }

            if (! AppearanceSettings::isHex($value)) {
                $fail($key, 'bad_color');

                continue;
            }

            $clean[$key] = strtolower($value);
        }

        foreach (['pad_v' => self::PAD_MAX, 'pad_h' => self::PAD_MAX, 'border_w' => self::BORDER_MAX, 'radius' => self::RADIUS_MAX] as $key => $max) {
            $value = $style[$key] ?? null;

            if ($empty($value)) {
                continue;
            }

            $int = FieldKinds::int($value, 0, $max, $key, 'bad_number', ['max' => $max], $fail);

            $int !== null && $clean[$key] = $int;
        }

        foreach (['width_mode' => self::WIDTH_MODES, 'align' => self::ALIGNS, 'size' => self::SIZES] as $key => $set) {
            $value = $style[$key] ?? null;

            if ($empty($value)) {
                continue;
            }

            in_array($value, $set, true) ? $clean[$key] = $value : $fail($key, 'bad_choice');
        }

        $widthPx = $style['width_px'] ?? null;

        if ($clean['width_mode'] === 'fixed') {
            if ($empty($widthPx)) {
                $fail('width_px', 'width_required', ['min' => self::WIDTH_MIN, 'max' => self::WIDTH_MAX]);
            } else {
                $int = FieldKinds::int($widthPx, self::WIDTH_MIN, self::WIDTH_MAX, 'width_px', 'bad_width', ['min' => self::WIDTH_MIN, 'max' => self::WIDTH_MAX], $fail);

                $int !== null && $clean['width_px'] = $int;
            }
        } elseif (! $empty($widthPx)) {
            // a width in px means nothing unless the width is fixed: it is dropped (still checked, so a typo is not silently kept)
            FieldKinds::int($widthPx, self::WIDTH_MIN, self::WIDTH_MAX, 'width_px', 'bad_width', ['min' => self::WIDTH_MIN, 'max' => self::WIDTH_MAX], $fail);
        }

        // HB-4: the picture is a media id (a positive whole number; that it is a picture of the library is checked by the form and, again, when the stylesheet is built); the keys that only make sense with
        // a picture are ignored (and not stored) without one
        $image = $style['bg_image'] ?? null;

        if (! $empty($image)) {
            $id = is_int($image) ? $image : (is_string($image) && preg_match('/\A[1-9][0-9]{0,17}\z/', $image) === 1 ? (int) $image : null);

            if ($id === null || $id < 1) {
                $fail('bg_image', 'bad_picture');
            } else {
                $clean['bg_image'] = $id;

                foreach (['bg_size' => self::BG_SIZES, 'bg_position' => self::BG_POSITIONS, 'valign' => self::VALIGNS] as $key => $set) {
                    $value = $style[$key] ?? null;

                    if (! $empty($value)) {
                        in_array($value, $set, true) ? $clean[$key] = $value : $fail($key, 'bad_choice');
                    }
                }

                foreach (['overlay' => self::OVERLAYS, 'min_height' => self::MIN_HEIGHTS] as $key => $set) {
                    $value = $style[$key] ?? null;

                    if ($empty($value)) {
                        continue;
                    }

                    $int = is_int($value) ? $value : (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,5})\z/', $value) === 1 ? (int) $value : null);

                    $int !== null && in_array($int, $set, true) ? $clean[$key] = $int : $fail($key, 'bad_choice');
                }
            }
        }

        return [$clean, $errors];
    }

    /** Is any value different from its default? @param  array<string, mixed>  $clean  a NORMALISED style (validate()) */
    public static function isSet(array $clean): bool
    {
        foreach (self::DEFAULTS as $key => $default) {
            if (($clean[$key] ?? $default) !== $default) {
                return true;
            }
        }

        return false;
    }

    /**
     * What is stored: only the keys that differ from their defaults, or null when the style is all defaults. Throws on a style that does not validate.
     *
     * @throws InvalidArgumentException
     */
    public static function storable(mixed $style): ?array
    {
        [$clean, $errors] = self::validate($style);

        if ($errors !== []) {
            throw new InvalidArgumentException('The block style is not valid.');
        }

        if (! self::isSet($clean)) {
            return null;
        }

        return array_filter($clean, static fn (mixed $value, string $key): bool => $value !== self::DEFAULTS[$key], ARRAY_FILTER_USE_BOTH);
    }

    /** The style of a stored value if it is valid AND set, else null (a hand-edited row never prints a wrapper it should not). @return array<string, mixed>|null the normalised style */
    public static function active(mixed $stored): ?array
    {
        $stored = is_string($stored) ? json_decode($stored, true) : $stored;

        if (! is_array($stored)) {
            return null;
        }

        [$clean, $errors] = self::validate($stored);

        return $errors === [] && self::isSet($clean) ? $clean : null;
    }

    /**
     * The ONE rule of a block: `.sf-hb-{id}{declarations}`, from a closed template. The style is validated HERE (an invalid one throws), so the template only ever prints validated values; px are
     * written as `Npx`, numbers with no locale, colours lower case. A style that is not set has no rule ('').
     *
     * HB-4: `$imageUrl` is the address of the style's background picture, resolved from the media id by HtmlBlockBackground (null = the picture is gone or unusable): the block then compiles WITHOUT its
     * picture keys, exactly as if it had none.
     *
     * @throws InvalidArgumentException a style that does not validate
     */
    public static function css(int $id, mixed $style, ?string $imageUrl = null): string
    {
        [$clean, $errors] = self::validate($style);

        if ($errors !== []) {
            throw new InvalidArgumentException('The block style is not valid.');
        }

        if ($clean['bg_image'] !== null && ($imageUrl === null || ! HtmlBlockBackground::isLibraryPath($imageUrl))) {
            $clean = array_replace($clean, array_intersect_key(self::DEFAULTS, array_flip(['bg_image', ...self::PICTURE_KEYS])));
        }

        if (! self::isSet($clean)) {
            return '';
        }

        $d = [];
        $clean['bg'] !== null && $d[] = 'background:'.$clean['bg'];
        $clean['color'] !== null && $d[] = 'color:'.$clean['color'];

        if ($clean['pad_v'] > 0 || $clean['pad_h'] > 0) {
            $d[] = 'padding:'.$clean['pad_v'].'px '.$clean['pad_h'].'px';
        }

        if ($clean['border_w'] > 0) {
            $d[] = 'border:'.$clean['border_w'].'px solid '.($clean['border_color'] ?? 'currentcolor');
        }

        $clean['radius'] > 0 && $d[] = 'border-radius:'.$clean['radius'].'px';

        $clean['align'] !== 'left' && $align = 'text-align:'.$clean['align'];
        $size = isset(self::FONT_SIZES[$clean['size']]) ? 'font-size:'.self::FONT_SIZES[$clean['size']] : null;
        $align ??= null;

        // HB-4: the picture's declarations, appended to the rule (the content stays a normal block flow inside; the flex column only places it vertically, and only when the box has a minimum height)
        $picture = [];

        if ($clean['bg_image'] !== null) {
            $veil = $clean['overlay'] > 0 ? 'linear-gradient(rgba(0,0,0,.'.($clean['overlay'] / 10).'),rgba(0,0,0,.'.($clean['overlay'] / 10).')),' : '';
            array_push($picture, 'background-image:'.$veil.'url('.$imageUrl.')', 'background-size:'.$clean['bg_size'], 'background-position:'.$clean['bg_position'], 'background-repeat:no-repeat');
            $clean['min_height'] > 0 && $picture[] = 'min-height:'.$clean['min_height'].'px';
            $clean['min_height'] > 0 && $clean['valign'] !== 'top' && array_push($picture, 'display:flex', 'flex-direction:column', 'justify-content:'.($clean['valign'] === 'center' ? 'center' : 'flex-end'));
        }

        // «auto» (as wide as the content) is what a styled box does by default; on its own it is nothing to print (a style that only names a border colour has no border to colour)
        if ($d === [] && $align === null && $size === null && $picture === [] && $clean['width_mode'] === 'auto') {
            return '';
        }

        match ($clean['width_mode']) {
            'full' => $d[] = 'width:100%',
            'fixed' => array_push($d, 'width:'.$clean['width_px'].'px', 'max-width:100%'),
            default => array_push($d, 'width:fit-content', 'max-width:100%'),
        };

        // HB-3.2: a box that is only as wide as its content (fit-content) sits at the LEFT edge of its place; the margins move it: a centred box to the middle, a right-aligned one to the right.
        // A full-width box fills its place already, and a left-aligned one stays where it is.
        if ($clean['width_mode'] === 'auto') {
            $clean['align'] === 'center' && array_push($d, 'margin-left:auto', 'margin-right:auto');
            $clean['align'] === 'right' && $d[] = 'margin-left:auto';
        }

        $align !== null && $d[] = $align;
        $size !== null && $d[] = $size;

        return '.'.self::className($id).'{'.implode(';', [...$d, ...$picture]).'}';
    }

    /** Is this string exactly one rule of the closed template (checked again by the token compiler)? The pattern covers the WHOLE rule: nothing else can ride along. */
    public static function isValidRule(string $rule): bool
    {
        $color = '#[0-9a-f]{6}';
        $pad = '(?:[0-9]|[1-5][0-9]|6[0-4])';
        $declaration = '(?:'.implode('|', [
            "background:{$color}",
            "color:{$color}",
            "padding:{$pad}px {$pad}px",
            "border:[1-4]px solid (?:{$color}|currentcolor)",
            'border-radius:(?:[1-9]|[12][0-9]|3[0-2])px',
            'width:(?:fit-content|100%|(?:[1-7][0-9]{2}|800)px)',
            'max-width:100%',
            // HB-3.2: the two margins a fit-content box needs to sit in the middle (centre) or at the right (right); nothing else of the margin family
            'margin-left:auto',
            'margin-right:auto',
            'text-align:(?:center|right)',
            'font-size:(?:0\.875|1\.25)rem',
            // HB-4: the background picture: ONLY an address of our own storage (HtmlBlockBackground::URL_PATH, the very pattern the resolver uses) with an optional black veil of exactly 20, 40 or 60 percent
            '(?:background-image:(?:linear-gradient\(rgba\(0,0,0,\.2\),rgba\(0,0,0,\.2\)\),|linear-gradient\(rgba\(0,0,0,\.4\),rgba\(0,0,0,\.4\)\),|linear-gradient\(rgba\(0,0,0,\.6\),rgba\(0,0,0,\.6\)\),)?url\('.HtmlBlockBackground::URL_PATH.'\))',
            'background-size:(?:cover|contain)',
            'background-position:(?:center|top|bottom|left|right)',
            'background-repeat:no-repeat',
            'min-height:(?:160|240|320|400)px',
            'display:flex',
            'flex-direction:column',
            'justify-content:(?:center|flex-end)',
        ]).')';

        // `~` is the delimiter: the picture's address has slashes in it
        return preg_match('~\A\.sf-hb-[1-9][0-9]{0,9}\{'.$declaration.'(?:;'.$declaration.')*\}\z~', $rule) === 1;
    }

    /** The wrapper of a styled block's HTML; an empty HTML has nothing to wrap. */
    public static function wrap(int $id, string $html): string
    {
        return $html === '' ? '' : '<div class="sf-hb '.self::className($id).'">'.$html.'</div>';
    }

    /**
     * The rules of the styled blocks for the token stylesheet: those with a valid, set style, ordered by id, at most MAX_STYLED. An empty list when no block is styled.
     *
     * @return list<string>
     */
    public static function storedRules(): array
    {
        $rules = [];

        if (! self::hasColumn()) {
            return $rules;
        }

        $styled = [];

        foreach (DB::table('html_blocks')->whereNotNull('style')->orderBy('id')->limit(self::MAX_STYLED)->get(['id', 'style']) as $row) {
            $style = self::active($row->style);
            $style !== null && $styled[(int) $row->id] = $style;
        }

        // HB-4: the addresses of every background picture, resolved at once (one query); a picture that is gone has none and its block compiles without it
        $urls = HtmlBlockBackground::urls(array_values(array_filter(array_column($styled, 'bg_image'))));

        foreach ($styled as $id => $style) {
            $rule = self::css($id, $style, $style['bg_image'] === null ? null : ($urls[$style['bg_image']] ?? null));

            $rule !== '' && $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * The form's state for a stored style: every key, the numbers as the text of a text input ('' = the default). The colours stay null when not set.
     *
     * @return array<string, mixed>
     */
    public static function formState(mixed $stored): array
    {
        $style = self::active($stored) ?? self::DEFAULTS;

        foreach (['pad_v', 'pad_h', 'border_w', 'radius'] as $key) {
            $style[$key] = $style[$key] === 0 ? '' : (string) $style[$key];
        }

        $style['width_px'] = $style['width_px'] === null ? '' : (string) $style['width_px'];

        // HB-4: the selects hold strings
        foreach (['overlay', 'min_height'] as $key) {
            $style[$key] = (string) $style[$key];
        }

        return $style;
    }

    /**
     * The declarations of the admin's live preview box (the ONE place an inline style exists, on an admin page only): the very declarations the storefront rule has, from the VALID values of the form;
     * a field that does not validate is left out (it simply does not show). '' when nothing is set.
     */
    public static function previewDeclarations(mixed $style): string
    {
        if (! is_array($style)) {
            return '';
        }

        for ($round = 0; $round < 3; $round++) {
            [, $errors] = self::validate($style);

            if ($errors === []) {
                break;
            }

            // a field that does not validate is left out; a fixed width with no usable width is left out with its mode
            isset($errors['width_px']) && $errors['width_mode'] = '';
            $style = array_diff_key($style, $errors);
        }

        if ($errors !== []) {
            return '';
        }

        $image = is_array($style) && filled($style['bg_image'] ?? null) ? HtmlBlockBackground::urlFor((int) $style['bg_image']) : null;
        $rule = self::css(1, $style, $image);

        return $rule === '' ? '' : substr($rule, strpos($rule, '{') + 1, -1);
    }

    private static ?bool $hasColumn = null;

    /** Is the `style` column there (the migration may not have run yet: the code then behaves as if no block had a style)? */
    public static function hasColumn(): bool
    {
        return self::$hasColumn ??= Schema::hasColumn('html_blocks', 'style');
    }

    /** Rebuilds the token stylesheet (which carries the rules of the styled blocks) and replaces the reader's cache entry. */
    public static function rebuildStylesheet(): void
    {
        app(AppearanceRecompiler::class)->run(app(SiteSettingsRepository::class));
    }

    /** Are there already MAX_STYLED styled blocks besides this one? (the form refuses the next) */
    public static function capReached(?int $exceptId): bool
    {
        if (! self::hasColumn()) {
            return false;
        }

        $query = DB::table('html_blocks')->whereNotNull('style');

        $exceptId !== null && $query->where('id', '!=', $exceptId);

        return $query->count() >= self::MAX_STYLED;
    }
}
