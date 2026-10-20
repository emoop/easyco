<?php

namespace App\Storefront\Footer;

/**
 * The footer layout of the shop (FT-1, storefront-customization-design.md 18): TWO LEVELS. The upper level holds 1 to 2 rows of 1 to 5 columns, each column an ordered list of blocks
 * from the catalog (FooterCatalog); the lower level is one line of text with a `[year]` token. Both levels have a background and a text colour (`null` = the Облик colours
 * `footer_bg` / `footer_text`); the upper level also has a top border (colour, thickness 0 to 4 px).
 *
 *   { upper: { enabled, padding, bg, text, border_color, border_width, rows: [ { columns: [ { span, align, blocks: [ { type, settings } ] } ] } ] },
 *     lower: { enabled, padding, bg, text, align, text_template } }
 */
final class FooterLayout
{
    public const MAX_ROWS = 2;

    public const MAX_COLUMNS = 5;

    public const MAX_BLOCKS_PER_COLUMN = 6;

    public const MAX_BLOCKS = 40;

    public const MAX_TEMPLATE = 300;

    public const PADDINGS = ['compact', 'normal', 'spacious'];

    public const ALIGNS = ['start', 'center', 'end'];

    public const SPANS = [1, 2];

    public const BORDER_WIDTHS = [0, 1, 2, 3, 4];

    /** The token the lower line replaces with the CURRENT year at render time. */
    public const YEAR_TOKEN = '[year]';

    /** The seeded default for the editor: the footer menu in one column, and the copyright line. (The shop shows its old footer until something is published.) @return array<string, mixed> */
    public static function default(): array
    {
        return [
            'upper' => [
                'enabled' => true, 'padding' => 'normal', 'bg' => null, 'text' => null, 'border_color' => null, 'border_width' => 1,
                'rows' => [['columns' => [['span' => 1, 'align' => 'start', 'blocks' => [['type' => 'menu', 'settings' => ['menu' => null, 'heading' => '']]]]]]],
            ],
            'lower' => [
                'enabled' => true, 'padding' => 'compact', 'bg' => null, 'text' => null, 'align' => 'center',
                'text_template' => '© '.self::YEAR_TOKEN.' '.config('app.name'),
            ],
        ];
    }

    /**
     * The CSS variables a layout needs in the token stylesheet (only what differs from the fallbacks in storefront.css). Values are re-checked by the compiler.
     *
     * @param  array<string, mixed>  $layout  a VALIDATED layout
     * @return array<string, string>
     */
    public static function vars(array $layout): array
    {
        $vars = [];
        $upper = $layout['upper'];
        $lower = $layout['lower'];

        foreach (['--sf-ftu-bg' => $upper['bg'], '--sf-ftu-text' => $upper['text'], '--sf-ftu-bc' => $upper['border_color'], '--sf-ftl-bg' => $lower['bg'], '--sf-ftl-text' => $lower['text']] as $variable => $color) {
            if (is_string($color)) {
                $vars[$variable] = strtolower($color);
            }
        }

        if ($upper['border_width'] !== 1) {
            $vars['--sf-ftu-bw'] = $upper['border_width'].'px';
        }

        return $vars;
    }
}
