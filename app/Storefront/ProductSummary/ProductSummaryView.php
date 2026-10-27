<?php

namespace App\Storefront\ProductSummary;

use App\Content\HtmlBlocks\HtmlBlockSanitizer;

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

                $out[] = ['type' => 'html_block', 'html' => $html];

                continue;
            }

            $out[] = ['type' => $component['type'], 'html' => ''];
        }

        return $out;
    }
}
