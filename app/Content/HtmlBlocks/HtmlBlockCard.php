<?php

namespace App\Content\HtmlBlocks;

use App\Rules\PlainMultilineText;
use App\Rules\PlainText;
use App\Settings\AppearanceSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Снимка с текст» (HB-5): the second KIND of HTML block (`html_blocks.kind = 'card'`; the first is `text`, the block as it always was). A card is made from a plain form (a picture of the library, a title, a
 * subtitle, a paragraph, a link, closed lists for the size, the alignment, the veil, the height, the border and the corners) and NOTHING of it is stored as HTML: this class validates the settings
 * (`html_blocks.card`, a JSON object) and builds the markup from the validated values, so a hand-edited row can never print anything the form would refuse (an invalid card prints nothing).
 *
 * The look is CLASSES from closed lists (`resources/css/storefront.css`, «HB-5 picture block»); the only per-block CSS is ONE rule in the token stylesheet (`.sf-hb-{id}`): the picture (an address
 * of our own storage, resolved by HtmlBlockBackground, as HB-4 does) and the two colours that are not tokens (a custom text / border colour, `#rrggbb`). No `style=` ever reaches the storefront.
 */
final class HtmlBlockCard
{
    public const KINDS = ['text', 'card'];

    public const TITLE_MAX = 80;

    public const SUBTITLE_MAX = 120;

    public const TEXT_MAX = 300;

    public const ALT_MAX = 120;

    /** The most cards the token stylesheet carries (one rule each). */
    public const MAX_CARDS = 100;

    public const TITLE_SIZES = ['s', 'm', 'l', 'xl'];

    public const HALIGNS = ['left', 'center', 'right'];

    public const VALIGNS = ['top', 'middle', 'bottom'];

    public const OVERLAYS = ['none', 'medium', 'strong'];

    public const HEIGHTS = [160, 240, 320, 400];

    public const TEXT_COLORS = ['light', 'dark', 'custom'];

    public const BORDER_WIDTHS = [0, 1, 2, 4];

    public const BORDER_COLORS = ['dark', 'light', 'accent', 'custom'];

    public const RADII = [0, 8, 16, 24];

    /** @var array<string, mixed> every key with its default */
    public const DEFAULTS = [
        'picture' => null, 'title' => '', 'subtitle' => '', 'text' => '', 'link' => '', 'alt' => '',
        'title_size' => 'l', 'halign' => 'center', 'valign' => 'middle', 'overlay' => 'medium', 'height' => 320,
        'text_color' => 'light', 'text_custom' => null, 'border_w' => 0, 'border_color' => 'dark', 'border_custom' => null, 'radius' => 0,
    ];

    /** The keys whose value is one whole number out of a closed list. */
    private const NUMBER_LISTS = ['height' => self::HEIGHTS, 'border_w' => self::BORDER_WIDTHS, 'radius' => self::RADII];

    /** The keys whose value is one string out of a closed list. */
    private const STRING_LISTS = [
        'title_size' => self::TITLE_SIZES, 'halign' => self::HALIGNS, 'valign' => self::VALIGNS, 'overlay' => self::OVERLAYS,
        'text_color' => self::TEXT_COLORS, 'border_color' => self::BORDER_COLORS,
    ];

    /**
     * Validates a card as it comes from the form or the database: an unknown key, a value outside a closed list, a text that is too long or not plain, a colour that is not `#rrggbb`, a link that is
     * not an address of the shop or `https:`, no picture, or a picture-only card with no alt text is refused (the messages are by key). Returns the NORMALISED card (every key) and the errors.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function validate(mixed $card): array
    {
        $clean = self::DEFAULTS;
        $errors = [];

        $fail = static function (string $key, string $code, array $params = []) use (&$errors): void {
            $errors[$key] ??= (string) __('html_blocks.card.errors.'.$code, $params);
        };

        if (! is_array($card)) {
            $fail('card', 'bad_card');

            return [$clean, $errors];
        }

        foreach (array_diff(array_keys($card), array_keys(self::DEFAULTS)) as $key) {
            $fail('card', 'unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $empty = static fn (mixed $v): bool => $v === null || $v === '';

        // the picture: a positive whole number (that it is a picture of the library is checked by the form, and again when the stylesheet is built)
        $picture = $card['picture'] ?? null;
        $id = is_int($picture) ? $picture : (is_string($picture) && preg_match('/\A[1-9][0-9]{0,17}\z/', $picture) === 1 ? (int) $picture : null);

        if ($id === null || $id < 1) {
            $fail('picture', $empty($picture) ? 'picture_required' : 'bad_picture');
        } else {
            $clean['picture'] = $id;
        }

        foreach (['title' => self::TITLE_MAX, 'subtitle' => self::SUBTITLE_MAX, 'text' => self::TEXT_MAX, 'alt' => self::ALT_MAX] as $key => $max) {
            $value = $card[$key] ?? '';

            if (! is_string($value) && $value !== null) {
                $fail($key, 'bad_text');

                continue;
            }

            $value = trim((string) $value);

            if (mb_strlen($value) > $max) {
                $fail($key, 'too_long', ['max' => $max]);
            } elseif (! self::plain($value, $key === 'text')) {
                $fail($key, 'bad_text');
            } else {
                $clean[$key] = $key === 'text' ? str_replace("\r\n", "\n", $value) : $value;
            }
        }

        $link = $card['link'] ?? '';

        if (is_string($link) && trim($link) !== '') {
            $safe = HtmlBlockPictures::safeUrl($link);

            $safe === null ? $fail('link', 'bad_link') : $clean['link'] = $safe;
        } elseif (! $empty($link) && ! is_string($link)) {
            $fail('link', 'bad_link');
        }

        // a picture with nothing written on it still has to be described
        if ($clean['title'] === '' && $clean['subtitle'] === '' && $clean['text'] === '' && $clean['alt'] === '' && ! isset($errors['alt'])) {
            $fail('alt', 'alt_required');
        }

        foreach (self::STRING_LISTS as $key => $set) {
            $value = $card[$key] ?? null;

            if (! $empty($value)) {
                is_string($value) && in_array($value, $set, true) ? $clean[$key] = $value : $fail($key, 'bad_choice');
            }
        }

        foreach (self::NUMBER_LISTS as $key => $set) {
            $value = $card[$key] ?? null;

            if ($empty($value)) {
                continue;
            }

            $int = is_int($value) ? $value : (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,5})\z/', $value) === 1 ? (int) $value : null);

            $int !== null && in_array($int, $set, true) ? $clean[$key] = $int : $fail($key, 'bad_choice');
        }

        // a custom colour is needed (and kept) only when its choice is «custom»; one that is typed anyway is still checked, so a typo is not silently dropped
        foreach (['text_custom' => 'text_color', 'border_custom' => 'border_color'] as $key => $choice) {
            $value = $card[$key] ?? null;

            if ($empty($value)) {
                $clean[$choice] === 'custom' && ! isset($errors[$choice]) && $fail($key, 'color_required');

                continue;
            }

            if (! AppearanceSettings::isHex($value)) {
                $fail($key, 'bad_color');

                continue;
            }

            $clean[$choice] === 'custom' && $clean[$key] = strtolower($value);
        }

        return [$clean, $errors];
    }

    /** What is stored: the normalised card. @throws \InvalidArgumentException a card that does not validate */
    public static function storable(mixed $card): array
    {
        [$clean, $errors] = self::validate($card);

        if ($errors !== []) {
            throw new \InvalidArgumentException('The picture block is not valid.');
        }

        return $clean;
    }

    /** The card of a stored value if it is valid, else null (a hand-edited row prints nothing). @return array<string, mixed>|null */
    public static function active(mixed $stored): ?array
    {
        $stored = is_string($stored) ? json_decode($stored, true) : $stored;

        if (! is_array($stored)) {
            return null;
        }

        [$clean, $errors] = self::validate($stored);

        return $errors === [] ? $clean : null;
    }

    /** The class list of the block's box: every choice is a class of a closed list, nothing else. @param  array<string, mixed>  $card  NORMALISED */
    public static function classes(int $id, array $card): string
    {
        return implode(' ', [
            'sf-hb', 'sf-hb-'.$id, 'sf-pc',
            'sf-pc-t-'.$card['title_size'], 'sf-pc-ha-'.$card['halign'], 'sf-pc-va-'.$card['valign'], 'sf-pc-o-'.$card['overlay'], 'sf-pc-ht-'.$card['height'],
            'sf-pc-bw-'.$card['border_w'], 'sf-pc-r-'.$card['radius'], 'sf-pc-c-'.$card['text_color'], 'sf-pc-bc-'.$card['border_color'],
        ]);
    }

    /**
     * The markup of a card, built from the validated values (null = the card is not valid: nothing is printed). The caller passes it through HtmlBlockSanitizer::render() like every block (the card
     * path of the sanitiser keeps exactly this markup). All text is escaped; the paragraph's line breaks become `<br>` and nothing else; the title, the subtitle and the paragraph are `div`s (never headings).
     */
    public static function html(int $id, mixed $stored): string
    {
        $card = self::active($stored);

        if ($card === null) {
            return '';
        }

        $withText = $card['title'] !== '' || $card['subtitle'] !== '' || $card['text'] !== '';
        $inner = '';

        if ($withText) {
            $inner = '<div class="sf-pc-text">'
                .($card['title'] !== '' ? '<div class="sf-pc-title">'.e($card['title']).'</div>' : '')
                .($card['subtitle'] !== '' ? '<div class="sf-pc-sub">'.e($card['subtitle']).'</div>' : '')
                .($card['text'] !== '' ? '<div class="sf-pc-par">'.str_replace("\n", '<br>', e($card['text'])).'</div>' : '')
                .'</div>';
        }

        $label = ! $withText ? ' aria-label="'.e($card['alt']).'"' : '';

        if ($card['link'] !== '') {
            $inner = '<a class="sf-pc-link" href="'.e($card['link']).'"'.$label.'>'.$inner.'</a>';
            $root = '';
        } else {
            $root = $withText ? '' : ' role="img"'.$label;
        }

        return '<div class="'.self::classes($id, $card).'"'.$root.'>'.$inner.'</div>';
    }

    /**
     * The ONE rule of a card in the token stylesheet: the picture (the address resolved by HtmlBlockBackground; null = the picture is gone: no rule) and the custom colours. Closed template, re-checked by
     * HtmlBlockStyle::isValidRule(); `border-color` is the one declaration this feature adds to it.
     */
    public static function css(int $id, mixed $stored, ?string $imageUrl): string
    {
        $card = self::active($stored);

        if ($card === null || $imageUrl === null || ! HtmlBlockBackground::isLibraryPath($imageUrl)) {
            return '';
        }

        $d = ['background-image:url('.$imageUrl.')', 'background-size:cover', 'background-position:center', 'background-repeat:no-repeat'];
        $card['text_color'] === 'custom' && $d[] = 'color:'.$card['text_custom'];
        $card['border_color'] === 'custom' && $card['border_w'] > 0 && $d[] = 'border-color:'.$card['border_custom'];

        return '.'.HtmlBlockStyle::className($id).'{'.implode(';', $d).'}';
    }

    /**
     * The rules of the cards for the token stylesheet: those with a valid card whose picture has an address, ordered by id, at most MAX_CARDS.
     *
     * @return list<string>
     */
    public static function storedRules(): array
    {
        if (! self::hasColumns()) {
            return [];
        }

        $cards = [];

        foreach (DB::table('html_blocks')->where('kind', 'card')->orderBy('id')->limit(self::MAX_CARDS)->get(['id', 'card']) as $row) {
            $card = self::active($row->card);
            $card !== null && $cards[(int) $row->id] = $card;
        }

        $urls = HtmlBlockBackground::urls(array_values(array_column($cards, 'picture')));
        $rules = [];

        foreach ($cards as $id => $card) {
            $rule = self::css($id, $card, $urls[$card['picture']] ?? null);

            $rule !== '' && $rules[] = $rule;
        }

        return $rules;
    }

    /** Are there already MAX_CARDS cards besides this one? (the form refuses the next) */
    public static function capReached(?int $exceptId): bool
    {
        if (! self::hasColumns()) {
            return false;
        }

        $query = DB::table('html_blocks')->where('kind', 'card');

        $exceptId !== null && $query->where('id', '!=', $exceptId);

        return $query->count() >= self::MAX_CARDS;
    }

    /** The form's state for a stored card: every key, the numbers as the strings of a select. @return array<string, mixed> */
    public static function formState(mixed $stored): array
    {
        $card = self::active($stored) ?? self::DEFAULTS;

        foreach (array_keys(self::NUMBER_LISTS) as $key) {
            $card[$key] = (string) $card[$key];
        }

        return $card;
    }

    /**
     * The admin's live preview (the ONE place a card gets inline styles, on an admin page only: the storefront CSS is not loaded there): the same classes as the shop on the box and the same structure,
     * styled from the VALID values of the form; a value that does not validate falls back to its default.
     *
     * @return array{classes: string, box: string, veil: string, text: string, title: string, sub: string, par: string, lines: array{title: string, subtitle: string, text: string}, link: bool, role: ?string}
     */
    public static function preview(mixed $form, ?string $imageUrl): array
    {
        $form = is_array($form) ? $form : [];
        $form['picture'] = 1;
        [$clean, $errors] = self::validate($form);

        // every key that does not validate is taken from the defaults, so the preview always shows something
        $clean = array_replace(self::DEFAULTS, array_diff_key($clean, $errors));
        $clean['picture'] = 1;

        $size = ['s' => 18, 'm' => 24, 'l' => 32, 'xl' => 48][$clean['title_size']];
        $color = ['light' => '#ffffff', 'dark' => '#1a1a1a', 'custom' => (string) ($clean['text_custom'] ?? '#ffffff')][$clean['text_color']];
        $border = ['dark' => '#1a1a1a', 'light' => '#ffffff', 'accent' => '#2563eb', 'custom' => (string) ($clean['border_custom'] ?? '#1a1a1a')][$clean['border_color']];
        $justify = ['top' => 'flex-start', 'middle' => 'center', 'bottom' => 'flex-end'][$clean['valign']];
        $items = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][$clean['halign']];
        $veil = ['none' => 0, 'medium' => .4, 'strong' => .6][$clean['overlay']];
        $box = 'position:relative;overflow:hidden;box-sizing:border-box;display:flex;flex-direction:column;width:100%;background-color:#9ca3af;'
            .($imageUrl !== null ? 'background-image:url('.$imageUrl.');background-size:cover;background-position:center;' : '')
            .'min-height:'.$clean['height'].'px;justify-content:'.$justify.';align-items:'.$items.';text-align:'.$clean['halign'].';color:'.$color.';'
            .'border:'.$clean['border_w'].'px solid '.$border.';border-radius:'.$clean['radius'].'px';

        return [
            'classes' => self::classes(0, $clean),
            'box' => $box,
            'veil' => 'position:absolute;inset:0;background:rgba(0,0,0,'.$veil.')',
            'text' => 'position:relative;padding:16px;font-size:'.$size.'px',
            'title' => 'font-size:1em;font-weight:700;line-height:1.15',
            'sub' => 'font-size:max(14px,.38em);margin-top:.3em',
            'par' => 'font-size:15px;margin-top:.5em',
            'lines' => ['title' => $clean['title'], 'subtitle' => $clean['subtitle'], 'text' => $clean['text']],
            'link' => $clean['link'] !== '',
            'role' => $clean['title'] === '' && $clean['subtitle'] === '' && $clean['text'] === '' ? 'img' : null,
        ];
    }

    private static ?bool $hasColumns = null;

    /** Are the `kind` and `card` columns there (the migration may not have run yet: the code then behaves as if every block were a text block)? */
    public static function hasColumns(): bool
    {
        return self::$hasColumns ??= Schema::hasColumn('html_blocks', 'kind') && Schema::hasColumn('html_blocks', 'card');
    }

    private static function plain(string $text, bool $multiline): bool
    {
        $failed = false;
        $rule = $multiline ? new PlainMultilineText : new PlainText;
        $rule->validate('card', $text, static function () use (&$failed): void {
            $failed = true;
        });

        return ! $failed;
    }
}
