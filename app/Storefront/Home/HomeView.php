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

            $class = 'sf-hp-section sf-hp-'.str_replace('_', '-', $type);
            $own = $settings['spacing'] ?? 'default';

            if ($items !== [] && is_string($own) && in_array($own, HomeCatalog::SPACINGS, true)) {
                $class .= ' sf-hp-mt-'.$own;
            }

            $items[] = ['type' => $type, 'class' => $class, 'view' => $catalog[$type]['view'], 'settings' => $settings, 'html' => $html];
        }

        // every section hidden or empty: nothing to show, but the layout is published (the page is NOT the old one)
        return ['gap' => $gap, 'items' => $items];
    }
}
