<?php

namespace App\Storefront\ProductSummary;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Conditions\ProductConditions;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\CheckoutHeader\CheckoutHeaderCompiler;
use InvalidArgumentException;

/**
 * PUBLISH of the right column of the product page (SC-1): validates the layout, stores it as `design.product_summary.global.published` and as the zone's entry of `design.compiled`
 * (`{hash, zones: {product_summary: {global: {layout, blocks}}}}`: the validated layout PLUS the sanitised HTML of every HTML block it shows, so that printing costs no query), and replaces the
 * appearance reader's cache entry. `design.compiled` is shared by every zone the Design screen stores this way: the other zones' entries are kept. A layout that is the DEFAULT is not stored at
 * all (the key and the zone's entry are forgotten, and the document too when it is left empty): the shop then prints the default layout, which is the same page.
 */
final class ProductSummaryCompiler
{
    public const MAX_BYTES = 262_144;

    /**
     * What a layout needs from the rest of the shop, as it is NOW: the sanitised HTML of its HTML blocks.
     *
     * @param  array{components: list<array{type: string, settings: array<string, mixed>}>}  $layout  VALIDATED
     * @return array{layout: array<string, mixed>, blocks: array<string, string>}
     */
    public static function compiled(array $layout): array
    {
        $ids = [];

        foreach ($layout['components'] as $component) {
            if ($component['type'] === 'html_block' && is_int($component['settings']['html_block'] ?? null)) {
                $ids[] = $component['settings']['html_block'];
            }
        }

        $blocks = [];

        if ($ids !== []) {
            foreach (HtmlBlockModel::renderMany(array_unique($ids)) as $id => $html) {
                if ($html !== '') {
                    $blocks[(string) $id] = $html;
                }
            }
        }

        $compiled = ['layout' => $layout, 'blocks' => $blocks];

        // HB-2: the «Показвай, когато…» conditions of the blocks that have some travel with their HTML (the key is not there at all when no block has any: the copy is what it was)
        $conditions = ProductConditions::storedFor(array_values(array_unique($ids)));
        $conditions === [] || $compiled['conditions'] = $conditions;

        return $compiled;
    }

    /**
     * Publishes a layout (validated again here).
     *
     * @param  array<string, mixed>  $layout
     * @throws InvalidArgumentException when the layout does not validate or the compiled document is too large
     */
    public function publish(array $layout, SiteSettingsRepository $settings): void
    {
        [$clean, $errors] = ProductSummaryValidator::validate($layout);

        if ($errors !== []) {
            throw new InvalidArgumentException('The product summary layout is not valid.');
        }

        $document = CheckoutHeaderCompiler::document($settings->get(AppearanceSettings::DESIGN_COMPILED_KEY));

        if ($clean == ProductSummaryLayout::default()) {
            $settings->forget(AppearanceSettings::SUMMARY_PUBLISHED_KEY);
            unset($document['zones'][ProductSummaryCatalog::ZONE]);
        } else {
            $document['zones'][ProductSummaryCatalog::ZONE][ProductSummaryCatalog::SCOPE] = self::compiled($clean);
            $settings->set(AppearanceSettings::SUMMARY_PUBLISHED_KEY, (string) json_encode($clean, JSON_THROW_ON_ERROR));
        }

        $this->store($document, $settings);
    }

    /** Forgets the published layout: the shop prints the default one. */
    public function unpublish(SiteSettingsRepository $settings): void
    {
        $document = CheckoutHeaderCompiler::document($settings->get(AppearanceSettings::DESIGN_COMPILED_KEY));
        unset($document['zones'][ProductSummaryCatalog::ZONE]);
        $settings->forget(AppearanceSettings::SUMMARY_PUBLISHED_KEY);
        $this->store($document, $settings);
    }

    /** @param  array{hash: string, zones: array<string, mixed>}  $document */
    private function store(array $document, SiteSettingsRepository $settings): void
    {
        if ($document['zones'] === []) {
            $settings->forget(AppearanceSettings::DESIGN_COMPILED_KEY);
        } else {
            $document['hash'] = substr(sha1((string) json_encode($document['zones'])), 0, 12);
            $json = (string) json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            if (strlen($json) > self::MAX_BYTES) {
                throw new InvalidArgumentException('The compiled design is too large.');
            }

            $settings->set(AppearanceSettings::DESIGN_COMPILED_KEY, $json);
        }

        app(StorefrontAppearance::class)->refresh();
    }

    /**
     * Rebuilds the compiled copy from the PUBLISHED layout when something it shows changed (an HTML block): nothing happens when none is published or it no longer validates. Returns whether it
     * changed. (Not called by the menu rebuild yet: see the SC-1 report.)
     */
    public function refresh(): bool
    {
        $settings = app(SiteSettingsRepository::class);
        $json = $settings->get(AppearanceSettings::SUMMARY_PUBLISHED_KEY);
        $layout = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($layout)) {
            return false;
        }

        [$clean, $errors] = ProductSummaryValidator::validate($layout);

        if ($errors !== []) {
            return false;
        }

        $document = CheckoutHeaderCompiler::document($settings->get(AppearanceSettings::DESIGN_COMPILED_KEY));
        $compiled = self::compiled($clean);

        if (($document['zones'][ProductSummaryCatalog::ZONE][ProductSummaryCatalog::SCOPE] ?? null) == $compiled) {
            return false;
        }

        $document['zones'][ProductSummaryCatalog::ZONE][ProductSummaryCatalog::SCOPE] = $compiled;
        $this->store($document, $settings);

        return true;
    }

    /**
     * The PUBLISHED layout and the sanitised HTML of its blocks, read from the compiled document and validated AGAIN (a layout that does not validate = null: the page prints the default one).
     *
     * @return array{layout: array{components: list<array{type: string, settings: array<string, mixed>}>}, blocks: array<string, string>, conditions: array<string, array<string, mixed>|false>}|null
     */
    public static function published(?string $json): ?array
    {
        $entry = CheckoutHeaderCompiler::document($json)['zones'][ProductSummaryCatalog::ZONE][ProductSummaryCatalog::SCOPE] ?? null;

        if (! is_array($entry) || ! is_array($entry['layout'] ?? null)) {
            return null;
        }

        [$clean, $errors] = ProductSummaryValidator::validate($entry['layout']);

        if ($errors !== []) {
            return null;
        }

        $blocks = [];

        foreach (is_array($entry['blocks'] ?? null) ? $entry['blocks'] : [] as $id => $html) {
            if (is_string($html) && strlen($html) <= 65_536) {
                $blocks[(string) $id] = $html;
            }
        }

        // HB-2: the conditions of the blocks, checked for their SHAPE again (no query on the storefront); a block whose conditions are not valid is `false` and is never shown
        $conditions = [];

        foreach (is_array($entry['conditions'] ?? null) ? $entry['conditions'] : [] as $id => $stored) {
            $active = $stored === false ? false : (is_array($stored) ? ProductConditions::active($stored) : false);

            $active === null || $conditions[(string) $id] = $active;
        }

        return ['layout' => $clean, 'blocks' => $blocks, 'conditions' => $conditions];
    }
}
