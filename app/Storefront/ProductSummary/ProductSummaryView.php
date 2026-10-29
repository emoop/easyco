<?php

namespace App\Storefront\ProductSummary;

use App\Content\HtmlBlocks\HtmlBlockSanitizer;
use App\Storefront\Conditions\ProductConditions;
use App\Storefront\Conditions\ProductContext;

/**
 * Turns a VALIDATED layout of the right column (plus the sanitised HTML of its blocks) into the list the product view loops over (SC-1): `[{type, html?}]`. An HTML block whose HTML is gone (the
 * block was deleted or is empty) prints nothing and is dropped. The HTML is cleaned AGAIN here, as the footer does.
 */
final class ProductSummaryView
{
    /**
     * @param  array{layout: array{components: list<array{type: string, settings: array<string, mixed>}>}, blocks: array<string, string>}|null  $stored  `{layout, blocks}` of ProductSummaryCompiler, or null (= the default layout)
     * @return list<array{type: string, html: string}>
     */
    public static function prepare(?array $stored): array
    {
        $layout = $stored['layout'] ?? ProductSummaryLayout::default();
        $blocks = $stored['blocks'] ?? [];
        $out = [];

        foreach ($layout['components'] as $component) {
            if ($component['type'] === 'html_block') {
                $html = HtmlBlockSanitizer::render($blocks[(string) ($component['settings']['html_block'] ?? '')] ?? '');

                if ($html === '') {
                    continue;
                }

                $entry = ['type' => 'html_block', 'html' => $html];
                // HB-2: the block's «Показвай, когато…» conditions (`false` = invalid, never shown); the key is there only for a block that has some, so a layout with none is what it was
                $conditions = $stored['conditions'][(string) ($component['settings']['html_block'] ?? '')] ?? null;
                $conditions === null || $entry['conditions'] = $conditions;
                $out[] = $entry;

                continue;
            }

            $entry = ['type' => $component['type'], 'html' => ''];
            // PB-1: the add-to-cart button's settings travel with the component ONLY when some differ from the defaults (the key is absent otherwise: the list is what it was)
            $component['type'] === 'purchase' && ($component['settings'] ?? []) != ProductSummaryCatalog::defaultsFor('purchase') && $entry['settings'] = $component['settings'];
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * HB-2: the components that are for THIS product: an HTML block whose conditions do not hold for it is not in the list at all (nothing is printed, no empty row). Every other component stays.
     *
     * @param  list<array{type: string, html: string, conditions?: array<string, mixed>|false}>  $components  prepare()'s list
     * @return list<array{type: string, html: string}>
     */
    public static function forProduct(array $components, ProductContext $context): array
    {
        return array_values(array_filter($components, static fn (array $component): bool => ! array_key_exists('conditions', $component) || ProductConditions::matches($context, $component['conditions'])));
    }
}
