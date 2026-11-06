<?php

namespace App\Storefront\Sections;

use App\Storefront\Conditions\ProductConditions;
use App\Storefront\Conditions\ProductContext;
use App\Storefront\Support\DescriptionSanitizer;

/**
 * P4b: the sections a product page prints: the DEFINITIONS (the compiled copy, SectionCompiler::published) resolved against the PRODUCT's own texts. Pure: no query, no setting.
 *
 *   global             → the section's general text
 *   product            → the product's own text
 *   product_or_global  → the product's own text when it has one, else the general text
 *
 * A text goes through the storefront's description sanitiser (the product's own text is cleaned HERE, the general one was cleaned when it was compiled and is cleaned again when it is read); a section whose
 * resolved text is empty is NOT in the list (no title, no empty box). The list is in the definitions' order.
 */
final class SectionResolver
{
    /**
     * @param  list<array{key: string, title: string, source: string, html: string}>|null  $definitions  SectionCompiler::published()
     * @param  array<string, string>  $texts  the product's texts by section key (SectionTexts::forProduct), `[]` when none were asked for
     * @param  string  $description  P4b.1: the product's description (the field the product already has, on the row the page loads: no query), already passed through DescriptionSanitizer; the text of a `description` section
     * @param  array<string, mixed>  $hidden  P4c: the sections switched off for THIS product (key => true), SectionTexts::forProduct
     * @param  array{seasons: list<array{name: string, url: string}>, tags: list<array{name: string, url: string}>}|null  $meta  P4c: the product's season and tags (SectionMeta::forProduct), null = not read (no `meta` section)
     * @param  ProductContext|null  $context  P4c: the product the page is for: the conditions of the blocks of a `blocks` section are checked against it
     * @return list<array{key: string, title: string, html: string}>
     */
    public static function resolve(?array $definitions, array $texts, string $description = '', array $hidden = [], ?array $meta = null, ?ProductContext $context = null): array
    {
        $out = [];

        foreach ($definitions ?? [] as $section) {
            // P4c: a section the merchant switched off for THIS product is never printed, whatever its source
            if (isset($hidden[$section['key']])) {
                continue;
            }

            $own = SectionSet::usesProductText($section['source']) ? DescriptionSanitizer::clean($texts[$section['key']] ?? '') : '';
            $html = match ($section['source']) {
                'description' => $description,
                'global' => $section['html'],
                'product' => $own,
                'meta' => $meta === null ? '' : SectionMeta::html($section['meta'] ?? ['seasons' => true, 'tags' => true], $meta),
                'blocks' => self::blocks($section['blocks'] ?? [], $context),
                default => self::isEmpty($own) ? $section['html'] : $own,
            };

            // a `blocks` / `meta` section is printed when it HAS content (a block may be only a picture: no text to test)
            $empty = in_array($section['source'], ['blocks', 'meta'], true) ? $html === '' : self::isEmpty($html);

            $empty || $out[] = ['key' => $section['key'], 'title' => $section['title'], 'html' => $html];
        }

        return $out;
    }

    /**
     * P4c: the HTML of a `blocks` section for THIS product: every block, in the chosen order, whose «Показвай, когато…» conditions hold (a block with none holds for every product; all the conditions of one block
     * must hold: ProductConditions::matches). The conditions were compiled with the blocks: no query here beyond what a condition itself reads. No product context (a bare resolve) = only the blocks with no condition.
     *
     * @param  list<array{id: int, html: string, conditions: array<string, mixed>|false|null}>  $blocks
     */
    private static function blocks(array $blocks, ?ProductContext $context): string
    {
        $html = '';

        foreach ($blocks as $block) {
            $conditions = $block['conditions'] ?? null;

            if ($conditions !== null && ($context === null || ! ProductConditions::matches($context, $conditions))) {
                continue;
            }

            $html .= '<div class="sf-psec-block">'.$block['html'].'</div>';
        }

        return $html;
    }

    /** Is the text empty? The rich-text editor's empty value is `<p></p>`: no text at all (the sanitiser drops pictures, so text is all there is). */
    public static function isEmpty(?string $html): bool
    {
        return $html === null || preg_replace('/[\s\x{00A0}]+/u', '', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '';
    }

    /**
     * Does any of the definitions take the product's own text? (When none does, the page does not ask for the product's texts at all.)
     *
     * @param  list<array{source: string}>|null  $definitions
     */
    public static function needsProductTexts(?array $definitions): bool
    {
        foreach ($definitions ?? [] as $section) {
            if (SectionSet::usesProductText($section['source'])) {
                return true;
            }
        }

        return false;
    }
}
