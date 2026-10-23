<?php

namespace Tests\Feature\Storefront\Design;

use App\Storefront\Footer\FooterCatalog;
use App\Storefront\Footer\FooterLayout;
use App\Storefront\Header\HeaderCatalog;
use App\Storefront\Header\HeaderLayout;

/**
 * DS-1: the inputs of the golden tests (the SNAPSHOTS they are compared with live next to them in `fixtures/*.json`). Valid layouts are built here, in code, from the catalogs' own
 * defaults; the invalid ones are ONE fault each, applied to a valid base, so a snapshot says which message the validator gives for exactly that fault.
 */
final class DesignFixtures
{
    /** The id of the HTML block the footer fixtures show (explicit, so the compiled snapshot is byte-stable). */
    public const BLOCK_ID = 9001;

    /** @param  array<string, mixed>  $settings */
    public static function el(string $type, array $settings = []): array
    {
        return ['type' => $type, 'settings' => $settings + HeaderCatalog::defaultsFor($type)];
    }

    /** @param  array<string, mixed>  $settings */
    public static function block(string $type, array $settings = []): array
    {
        return ['type' => $type, 'settings' => $settings + FooterCatalog::defaultsFor($type)];
    }

    /** @return array<string, mixed> */
    public static function headerDefault(): array
    {
        return HeaderLayout::default();
    }

    /** Every built-in element type, desktop and phone, every row on, non-default heights, a drawer holding every type it accepts. @return array<string, mixed> */
    public static function headerLarge(): array
    {
        $row = static fn (int $height, array $left, array $center, array $right): array => ['enabled' => true, 'height' => $height, 'left' => $left, 'center' => $center, 'right' => $right];
        $social = [['network' => 'facebook', 'url' => 'https://example.test/fb'], ['network' => 'instagram', 'url' => 'https://example.test/ig'], ['network' => 'x', 'url' => 'https://example.test/x']];

        return [
            'desktop' => ['sticky' => false, 'rows' => [
                'top' => $row(40, [self::el('text', ['text' => '0888 123 456', 'url' => '/contacts']), self::el('spacer', ['width' => 'lg'])], [self::el('html', ['html' => '<p>Free delivery <b>over 100</b></p>'])], [self::el('social', ['items' => $social]), self::el('wishlist', ['style' => 'icon_label', 'show_count' => true]), self::el('account', ['style' => 'icon_label'])]),
                'main' => $row(80, [self::el('menu', ['style' => 'dropdown'])], [self::el('logo', ['height' => 40])], [self::el('search', ['mode' => 'field']), self::el('cart', ['style' => 'icon_label']), self::el('button', ['label' => 'Sale', 'url' => '/sale', 'style' => 'outline'])]),
                'bottom' => $row(50, [self::el('menu', ['style' => 'links'])], [self::el('text', ['text' => 'Open every day', 'url' => ''])], [self::el('spacer', ['width' => 'sm'])]),
            ]],
            'mobile' => ['sticky' => true, 'rows' => [
                'top' => $row(30, [self::el('text', ['text' => 'Call us', 'url' => '/contacts'])], [], [self::el('wishlist', ['style' => 'icon', 'show_count' => false])]),
                'main' => $row(60, [self::el('menu_toggle', ['label_visible' => true])], [self::el('logo', ['height' => 30])], [self::el('search', ['mode' => 'icon_row']), self::el('cart', ['style' => 'icon'])]),
            ], 'drawer' => [self::el('menu', ['style' => 'links']), self::el('account', ['style' => 'icon']), self::el('text', ['text' => 'Free delivery', 'url' => '']), self::el('button', ['label' => 'Shop now', 'url' => '/shop', 'style' => 'primary']), self::el('social', ['items' => $social])],
                'drawer_side' => 'right', 'drawer_upper' => false],
        ];
    }

    /** @return array<string, mixed> */
    public static function footerDefault(): array
    {
        return FooterLayout::default();
    }

    /** 2 rows x 5 columns, spans, aligns, every built-in block type, colours, a border and a lower line with the year token. @return array<string, mixed> */
    public static function footerLarge(): array
    {
        $column = static fn (int $span, string $align, array $blocks): array => ['span' => $span, 'align' => $align, 'blocks' => $blocks];
        $menu = static fn (string $heading): array => self::block('menu', ['menu' => null, 'heading' => $heading]);

        return [
            'upper' => [
                'enabled' => true, 'padding' => 'spacious', 'bg' => '#112233', 'text' => '#eeeeee', 'border_color' => '#c0392b', 'border_width' => 3,
                'rows' => [
                    ['columns' => [
                        $column(1, 'start', [$menu('Shop'), self::block('spacer', ['size' => 's']), self::block('text', ['heading' => 'About', 'body' => "Line one\nLine two"])]),
                        $column(1, 'start', [$menu('Help')]),
                        $column(2, 'center', [self::block('html_block', ['html_block' => self::BLOCK_ID]), self::block('contacts', ['phone' => '+359 888 123 456', 'email' => 'shop@example.test', 'address' => "Main st 1\nHarmanli", 'hours' => 'Mon-Fri 9-18'])]),
                        $column(1, 'end', [$menu('Legal')]),
                        $column(1, 'start', [self::block('newsletter', ['heading' => 'News', 'placeholder' => 'Email', 'button' => 'Join', 'consent' => 'No spam'])]),
                    ]],
                    ['columns' => [
                        $column(1, 'start', [self::block('social', ['items' => [['network' => 'facebook', 'url' => 'https://example.test/fb'], ['network' => 'tiktok', 'url' => 'https://example.test/tt']]])]),
                        $column(2, 'end', [self::block('payments', ['methods' => ['mastercard', 'visa', 'maestro', 'apple_pay', 'google_pay', 'paypal', 'bank_transfer', 'cash_on_delivery']]), self::block('spacer', ['size' => 'l'])]),
                    ]],
                ],
            ],
            'lower' => ['enabled' => true, 'padding' => 'normal', 'bg' => '#000000', 'text' => '#dddddd', 'align' => 'start', 'text_template' => 'Fetish Harmanli © [year] - run by Mari Ned [x]'],
        ];
    }

    /**
     * One fault each, header. Each entry: a callable that breaks the large valid layout.
     *
     * @return array<string, callable(array<string, mixed>): array<string, mixed>>
     */
    public static function headerInvalid(): array
    {
        $in = static function (callable $edit): callable {
            return static function (array $l) use ($edit): array {
                $edit($l);

                return $l;
            };
        };

        return [
            'an unknown element type' => $in(function (&$l) { $l['desktop']['rows']['top']['left'][0] = ['type' => 'carousel', 'settings' => []]; }),
            'an unknown setting key' => $in(function (&$l) { $l['desktop']['rows']['top']['left'][0]['settings']['colour'] = 'red'; }),
            'an int out of range' => $in(function (&$l) { $l['desktop']['rows']['main']['center'][0]['settings']['height'] = 999; }),
            'a numeric string for an int (accepted)' => $in(function (&$l) { $l['desktop']['rows']['main']['center'][0]['settings']['height'] = '36'; }),
            'a bad enum' => $in(function (&$l) { $l['desktop']['rows']['main']['left'][0]['settings']['style'] = 'carousel'; }),
            'an overlong text' => $in(function (&$l) { $l['desktop']['rows']['top']['left'][0]['settings']['text'] = str_repeat('x', 121); }),
            'a control character in a text' => $in(function (&$l) { $l['desktop']['rows']['top']['left'][0]['settings']['text'] = "a\nb"; }),
            'an unsafe url' => $in(function (&$l) { $l['desktop']['rows']['main']['right'][2]['settings']['url'] = 'http://example.test/x'; }),
            'a javascript url' => $in(function (&$l) { $l['desktop']['rows']['main']['right'][2]['settings']['url'] = 'javascript:alert(1)'; }),
            'a button without its label' => $in(function (&$l) { $l['desktop']['rows']['main']['right'][2]['settings']['label'] = ''; }),
            'a bad social network' => $in(function (&$l) { $l['desktop']['rows']['top']['right'][0]['settings']['items'][1]['network'] = 'myspace'; }),
            'an http social url' => $in(function (&$l) { $l['desktop']['rows']['top']['right'][0]['settings']['items'][0]['url'] = 'http://example.test/fb'; }),
            'too many social networks' => $in(function (&$l) { $l['desktop']['rows']['top']['right'][0]['settings']['items'] = array_fill(0, 9, ['network' => 'facebook', 'url' => 'https://example.test/fb']); }),
            'two logos' => $in(function (&$l) { $l['desktop']['rows']['bottom']['center'][] = ['type' => 'logo', 'settings' => ['height' => 20]]; }),
            'a logo taller than its row' => $in(function (&$l) { $l['desktop']['rows']['main']['center'][0]['settings']['height'] = 76; }),
            'seven elements in a slot' => $in(function (&$l) { $l['desktop']['rows']['bottom']['right'] = array_fill(0, 7, ['type' => 'spacer', 'settings' => ['width' => 'sm']]); }),
            'an html block that is too long' => $in(function (&$l) { $l['desktop']['rows']['top']['center'][0]['settings']['html'] = '<p>'.str_repeat('a', 4100).'</p>'; }),
            'a bad boolean' => $in(function (&$l) { $l['desktop']['sticky'] = 'yes'; }),
            'an element that cannot be in the drawer' => $in(function (&$l) { $l['mobile']['drawer'][] = ['type' => 'cart', 'settings' => ['style' => 'icon']]; }),
            'nine drawer elements' => $in(function (&$l) { $l['mobile']['drawer'] = array_fill(0, 9, ['type' => 'text', 'settings' => ['text' => 'x', 'url' => '']]); }),
            'an unknown row key' => $in(function (&$l) { $l['desktop']['rows']['main']['colour'] = 'red'; }),
            'a bad drawer side' => $in(function (&$l) { $l['mobile']['drawer_side'] = 'top'; }),
        ];
    }

    /**
     * One fault each, footer.
     *
     * @return array<string, callable(array<string, mixed>): array<string, mixed>>
     */
    public static function footerInvalid(): array
    {
        $in = static function (callable $edit): callable {
            return static function (array $l) use ($edit): array {
                $edit($l);

                return $l;
            };
        };

        return [
            'six columns' => $in(function (&$l) { $l['upper']['rows'][0]['columns'][] = ['span' => 1, 'align' => 'start', 'blocks' => []]; }),
            'three rows' => $in(function (&$l) { $l['upper']['rows'][] = ['columns' => [['span' => 1, 'align' => 'start', 'blocks' => []]]]; }),
            'no rows' => $in(function (&$l) { $l['upper']['rows'] = []; }),
            'a block type outside the catalog (dropped)' => $in(function (&$l) { $l['upper']['rows'][0]['columns'][0]['blocks'][] = ['type' => 'carousel', 'settings' => []]; }),
            'an unknown setting key' => $in(function (&$l) { $l['upper']['rows'][0]['columns'][0]['blocks'][0]['settings']['colour'] = 'red'; }),
            'a span of three' => $in(function (&$l) { $l['upper']['rows'][0]['columns'][1]['span'] = 3; }),
            'a bad align' => $in(function (&$l) { $l['upper']['rows'][0]['columns'][1]['align'] = 'left'; }),
            'seven blocks in a column' => $in(function (&$l) { $l['upper']['rows'][0]['columns'][1]['blocks'] = array_fill(0, 7, ['type' => 'spacer', 'settings' => ['size' => 's']]); }),
            'forty-one blocks' => $in(function (&$l) { foreach ($l['upper']['rows'] as $r => $row) { foreach ($row['columns'] as $c => $column) { $l['upper']['rows'][$r]['columns'][$c]['blocks'] = array_fill(0, 6, ['type' => 'spacer', 'settings' => ['size' => 's']]); } } }),
            'a javascript social url' => $in(function (&$l) { $l['upper']['rows'][1]['columns'][0]['blocks'][0]['settings']['items'][0]['url'] = 'javascript:alert(1)'; }),
            'an http social url' => $in(function (&$l) { $l['upper']['rows'][1]['columns'][0]['blocks'][0]['settings']['items'][1]['url'] = 'http://example.test/tt'; }),
            'a bad social network' => $in(function (&$l) { $l['upper']['rows'][1]['columns'][0]['blocks'][0]['settings']['items'][0]['network'] = 'myspace'; }),
            'a colour that is not hex' => $in(function (&$l) { $l['upper']['bg'] = 'red'; }),
            'a border of seven' => $in(function (&$l) { $l['upper']['border_width'] = 7; }),
            'a lower text over 300' => $in(function (&$l) { $l['lower']['text_template'] = str_repeat('a', 301); }),
            'a control character in the lower text' => $in(function (&$l) { $l['lower']['text_template'] = "a\nb"; }),
            'a bad padding' => $in(function (&$l) { $l['lower']['padding'] = 'huge'; }),
            'an html block with no id' => $in(function (&$l) { $l['upper']['rows'][0]['columns'][2]['blocks'][0]['settings']['html_block'] = null; }),
            'an html block id that is not a number' => $in(function (&$l) { $l['upper']['rows'][0]['columns'][2]['blocks'][0]['settings']['html_block'] = 'abc'; }),
            'a body over 400' => $in(function (&$l) { $l['upper']['rows'][0]['columns'][0]['blocks'][2]['settings']['body'] = str_repeat('a', 401); }),
            'a control character in the body' => $in(function (&$l) { $l['upper']['rows'][0]['columns'][0]['blocks'][2]['settings']['body'] = "ok\x07bad"; }),
            'a bad email' => $in(function (&$l) { $l['upper']['rows'][0]['columns'][2]['blocks'][1]['settings']['email'] = 'not-an-email'; }),
            'payments that are not a list' => $in(function (&$l) { $l['upper']['rows'][1]['columns'][1]['blocks'][0]['settings']['methods'] = 'visa'; }),
            'an unknown payment method (dropped)' => $in(function (&$l) { $l['upper']['rows'][1]['columns'][1]['blocks'][0]['settings']['methods'][] = 'bitcoin'; }),
            'a bad spacer size' => $in(function (&$l) { $l['upper']['rows'][0]['columns'][0]['blocks'][1]['settings']['size'] = 'xl'; }),
            'a menu id that is not a number' => $in(function (&$l) { $l['upper']['rows'][0]['columns'][0]['blocks'][0]['settings']['menu'] = 'abc'; }),
            'an unknown top level key' => $in(function (&$l) { $l['colour'] = 'red'; }),
        ];
    }
}
