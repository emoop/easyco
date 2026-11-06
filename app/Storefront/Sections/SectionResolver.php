<?php

namespace App\Storefront\Sections;

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
     * @return list<array{key: string, title: string, html: string}>
     */
    public static function resolve(?array $definitions, array $texts): array
    {
        $out = [];

        foreach ($definitions ?? [] as $section) {
            $own = SectionSet::usesProductText($section['source']) ? DescriptionSanitizer::clean($texts[$section['key']] ?? '') : '';
            $html = match ($section['source']) {
                'global' => $section['html'],
                'product' => $own,
                default => self::isEmpty($own) ? $section['html'] : $own,
            };

            self::isEmpty($html) || $out[] = ['key' => $section['key'], 'title' => $section['title'], 'html' => $html];
        }

        return $out;
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
