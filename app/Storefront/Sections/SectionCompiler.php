<?php

namespace App\Storefront\Sections;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\CheckoutHeader\CheckoutHeaderCompiler;
use App\Storefront\Home\HomeCompiler;
use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockSanitizer;
use App\Storefront\Conditions\ProductConditions;
use App\Storefront\Support\DescriptionSanitizer;
use InvalidArgumentException;

/**
 * P4b: the PUBLISHED sections as the storefront reads them: one compiled copy `zones.sections` inside the design document the appearance already reads once per request (no query of its own).
 *
 *   {sections: [ {key, title, source, html, meta?: {seasons, tags}, blocks?: [ {id, html, conditions} ]} ]}
 *
 * P4c: a `blocks` section carries its blocks WITH their «Показвай, когато…» conditions (the machinery of the P4 HTML-block slots: HtmlBlockModel::renderMany, ProductConditions::storedFor), so the page decides which
 * of them match the product without a query of its own; a change of a block or its conditions recompiles the copy (refresh(), called where the other compilers' is).
 *
 * `html` is the section's general text, already cleaned by the storefront's description sanitiser (and cleaned AGAIN when the copy is read, as the description is). A copy that does not validate on read costs
 * the sections it spoils, never the page.
 *
 * @phpstan-import-type Set from SectionSet
 */
final class SectionCompiler
{
    public const ZONE = 'sections';

    /** The copy may not make the whole design document too large (HomeCompiler::MAX_BYTES). */
    public const MAX_BYTES = 200_000;

    /**
     * @param  Set  $set  VALIDATED
     * @return array{sections: list<array{key: string, title: string, source: string, html: string}>}
     */
    public static function compiled(array $set): array
    {
        $ids = SectionSet::blockIds($set);
        $html = $ids === [] ? [] : HtmlBlockModel::renderMany($ids);
        $conditions = ProductConditions::storedFor($ids);

        return ['sections' => array_map(static function (array $section) use ($html, $conditions): array {
            $out = ['key' => $section['key'], 'title' => $section['title'], 'source' => $section['source'], 'html' => DescriptionSanitizer::clean($section['text'])];

            isset($section['meta']) && $out['meta'] = $section['meta'];

            if (isset($section['blocks'])) {
                $out['blocks'] = [];

                foreach ($section['blocks'] as $id) {
                    // a block that is gone or empty is not in the copy
                    ($html[$id] ?? '') === '' || $out['blocks'][] = ['id' => $id, 'html' => $html[$id], 'conditions' => $conditions[$id] ?? null];
                }
            }

            return $out;
        }, $set['sections'])];
    }

    /**
     * Publishes a validated set: its JSON (the admin's source) and the compiled copy. A blank set removes both: the product page is the one it always was.
     *
     * @param  Set  $set
     * @throws InvalidArgumentException when the compiled copy is too large
     */
    public function publish(array $set, SiteSettingsRepository $settings): void
    {
        $document = CheckoutHeaderCompiler::document($settings->get(AppearanceSettings::DESIGN_COMPILED_KEY));
        $blank = SectionSet::isBlank($set);

        if ($blank) {
            unset($document['zones'][self::ZONE]);
        } else {
            $compiled = self::compiled($set);

            if (strlen((string) json_encode($compiled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > self::MAX_BYTES) {
                throw new InvalidArgumentException('The compiled sections are too large.');
            }

            $document['zones'][self::ZONE] = $compiled;
        }

        // the design document first: when it is refused (too large) the published set is not touched
        app(HomeCompiler::class)->store($document, $settings);
        $blank ? $settings->forget(AppearanceSettings::SECTIONS_PUBLISHED_KEY) : $settings->set(AppearanceSettings::SECTIONS_PUBLISHED_KEY, (string) json_encode($set, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** Rebuilds the compiled copy from the PUBLISHED set when a block it shows (or the block's conditions) changed. Returns whether it changed; nothing published = nothing happens. */
    public function refresh(): bool
    {
        $settings = app(SiteSettingsRepository::class);
        $json = $settings->get(AppearanceSettings::SECTIONS_PUBLISHED_KEY);
        $set = SectionSet::read(is_string($json) && $json !== '' ? json_decode($json, true) : null);

        if (SectionSet::isBlank($set) || SectionSet::blockIds($set) === []) {
            return false;
        }

        $document = CheckoutHeaderCompiler::document($settings->get(AppearanceSettings::DESIGN_COMPILED_KEY));
        $compiled = self::compiled($set);

        if (($document['zones'][self::ZONE] ?? null) == $compiled) {
            return false;
        }

        $document['zones'][self::ZONE] = $compiled;
        app(HomeCompiler::class)->store($document, $settings);

        return true;
    }

    /**
     * The compiled copy from the design document, read again: the sections validated (SectionSet::read on the shape; a bad one is dropped), the HTML cleaned AGAIN. Null when there is no section.
     *
     * @return list<array{key: string, title: string, source: string, html: string}>|null
     */
    public static function published(?string $json): ?array
    {
        $entry = CheckoutHeaderCompiler::document($json)['zones'][self::ZONE] ?? null;

        if (! is_array($entry) || ! is_array($entry['sections'] ?? null)) {
            return null;
        }

        $read = SectionSet::read(['sections' => array_map(static function (mixed $section): mixed {
            if (! is_array($section)) {
                return $section;
            }

            $shape = ['key' => $section['key'] ?? null, 'title' => $section['title'] ?? null, 'source' => $section['source'] ?? null, 'text' => ''];

            if (($section['source'] ?? null) === 'meta' && array_key_exists('meta', $section)) {
                $shape['meta'] = $section['meta'];
            }

            if (($section['source'] ?? null) === 'blocks') {
                $shape['blocks'] = is_array($section['blocks'] ?? null) ? array_map(static fn (mixed $block): mixed => is_array($block) ? ($block['id'] ?? null) : null, array_values($section['blocks'])) : [];
            }

            return $shape;
        }, $entry['sections'])]);
        $own = [];

        foreach ($entry['sections'] as $section) {
            is_array($section) && is_string($section['key'] ?? null) && is_array($section['blocks'] ?? null) && $own[$section['key']] = $section['blocks'];
        }
        $html = [];

        foreach ($entry['sections'] as $section) {
            is_array($section) && is_string($section['key'] ?? null) && is_string($section['html'] ?? null) && strlen($section['html']) <= 65_536 && $html[$section['key']] = DescriptionSanitizer::clean($section['html']);
        }

        $out = array_map(static function (array $section) use ($html, $own): array {
            $one = ['key' => $section['key'], 'title' => $section['title'], 'source' => $section['source'], 'html' => SectionSet::usesGlobalText($section['source']) ? ($html[$section['key']] ?? '') : ''];

            isset($section['meta']) && $one['meta'] = $section['meta'];

            if (isset($section['blocks'])) {
                $one['blocks'] = [];
                $stored = [];

                foreach ($own[$section['key']] ?? [] as $block) {
                    is_array($block) && is_int($block['id'] ?? null) && $stored[$block['id']] = $block;
                }

                foreach ($section['blocks'] as $id) {
                    $block = $stored[$id] ?? null;
                    // cleaned AGAIN when read (a hand-edited copy cannot bring markup in), as the HTML-block slots do; a block that is gone or empty is dropped
                    $blockHtml = $block !== null && is_string($block['html'] ?? null) && strlen($block['html']) <= 65_536 ? HtmlBlockSanitizer::render($block['html']) : '';

                    $blockHtml === '' || $one['blocks'][] = ['id' => $id, 'html' => $blockHtml, 'conditions' => ProductConditions::active($block['conditions'] ?? null)];
                }
            }

            return $one;
        }, $read['sections']);

        return $out === [] ? null : $out;
    }
}
