<?php

namespace App\Storefront\Home;

use App\Content\HtmlBlocks\HtmlBlockSanitizer;

/**
 * What the home page prints from a PUBLISHED layout (HP-1): the sections that are shown, each with the classes of its place and what its Blade partial needs. Pure: no query, no setting. Every value that
 * reaches a class name is from a closed list (HomeCatalog), checked again here; the HTML of a block is the compiled, sanitised copy.
 *
 * The first section that is printed has no top spacing; every other one has the general spacing, or its own when it overrides it (`sf-hp-mt-N`).
 */
final class HomeView
{
    /**
     * @param  array{layout: array{spacing: string, sections: list<array{type: string, settings: array<string, mixed>}>}, blocks: array<string, string>}|null  $stored  HomeCompiler::published()
     * @return array{gap: string, items: list<array{type: string, class: string, view: string, settings: array<string, mixed>, html: string}>}|null null = print the home page as it always was
     */
    public static function prepare(?array $stored): ?array
    {
        if ($stored === null) {
            return null;
        }

        $catalog = HomeCatalog::all();
        $gap = in_array($stored['layout']['spacing'], HomeCatalog::SPACINGS, true) ? $stored['layout']['spacing'] : HomeCatalog::DEFAULT_SPACING;
        $items = [];

        foreach ($stored['layout']['sections'] as $section) {
            $type = $section['type'];
            $settings = $section['settings'];

            if (! isset($catalog[$type]) || ($settings['show'] ?? true) === false) {
                continue;
            }

            $html = '';

            if ($type === 'html_block') {
                // cleaned AGAIN when the layout is read (a hand-edited compiled copy cannot bring markup in), as the footer and the right column do
                $html = HtmlBlockSanitizer::render((string) ($stored['blocks'][(string) ($settings['html_block'] ?? '')] ?? ''));

                // a block that is gone (or empty) prints nothing: no empty box in the page
                if ($html === '') {
                    continue;
                }
            }

            $grid = null;

            if ($type === 'image_grid') {
                $grid = self::grid($settings, $stored['blocks']);

                // a grid with no block to show (no cell, no phone block) prints nothing: no empty box in the page
                if ($grid === null) {
                    continue;
                }
            }

            $class = 'sf-hp-section sf-hp-'.str_replace('_', '-', $type);
            $own = $settings['spacing'] ?? 'default';

            if ($items !== [] && is_string($own) && in_array($own, HomeCatalog::SPACINGS, true)) {
                $class .= ' sf-hp-mt-'.$own;
            }

            $items[] = ['type' => $type, 'class' => $class, 'view' => $catalog[$type]['view'], 'settings' => $settings, 'html' => $html, 'grid' => $grid];
        }

        // every section hidden or empty: nothing to show, but the layout is published (the page is NOT the old one)
        return ['gap' => $gap, 'items' => $items];
    }

    /**
     * HP-2: what an image grid prints. Every class is from a closed list (checked again here; a value outside it is its default); the blocks are the compiled, sanitised HTML, cleaned AGAIN on read. A cell with
     * no block (or a block that is gone) is NOT printed; the phone list is the chosen blocks in order. Null = nothing to show at all.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, string>  $blocks  the compiled HTML by block id
     * @return array{class: string, cells: list<array{name: string, html: string}>, phone_class: string, phone: list<string>}|null
     */
    private static function grid(array $settings, array $blocks): ?array
    {
        $pick = static fn (string $key, array $list, string $default): string => in_array($settings[$key] ?? null, $list, true) ? (string) $settings[$key] : $default;
        $scheme = $pick('scheme', array_keys(ImageGridGeometry::SCHEMES), ImageGridGeometry::DEFAULT_SCHEME);
        $ratio = $pick('ratio', ImageGridGeometry::RATIOS, ImageGridGeometry::DEFAULT_RATIO);
        $gap = $pick('gap', ImageGridGeometry::GAPS, ImageGridGeometry::DEFAULT_GAP);
        $radius = $pick('radius', ImageGridGeometry::RADII, ImageGridGeometry::DEFAULT_RADIUS);
        $phoneGap = $pick('phone_gap', ImageGridGeometry::GAPS, ImageGridGeometry::PHONE_DEFAULT_GAP);
        $html = static fn (mixed $id): string => is_int($id) ? HtmlBlockSanitizer::render((string) ($blocks[(string) $id] ?? '')) : '';
        $cells = [];

        foreach (ImageGridGeometry::names($scheme) as $name) {
            $one = $html(is_array($settings['cells'] ?? null) ? ($settings['cells'][$name] ?? null) : null);
            $one === '' || $cells[] = ['name' => $name, 'html' => $one];
        }

        $phone = [];

        foreach (array_slice(is_array($settings['phone_blocks'] ?? null) ? array_values($settings['phone_blocks']) : [], 0, HomeCatalog::PHONE_BLOCKS_MAX) as $id) {
            $one = $html($id);
            $one === '' || $phone[] = $one;
        }

        if ($cells === [] && $phone === []) {
            return null;
        }

        return [
            'class' => 'sf-hp-grid sf-hp-s-'.$scheme.' sf-hp-r-'.str_replace(':', '-', $ratio).' sf-hp-gg-'.$gap.' sf-hp-cr-'.$radius,
            'cells' => $cells,
            'phone_class' => 'sf-hp-phone sf-hp-pg-'.$phoneGap.' sf-hp-cr-'.$radius,
            'phone' => $phone,
        ];
    }
}
