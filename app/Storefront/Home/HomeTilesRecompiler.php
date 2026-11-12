<?php

namespace App\Storefront\Home;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\CheckoutHeader\CheckoutHeaderCompiler;

/**
 * HP-3: when the published home layout's tiles are rebuilt. The names, links and pictures of the tiles are compiled into the published home layout, so a change of what they point at must recompile it:
 * a category, a season, a tag or a brand that is saved or deleted, the visibility of a category (the products it shows) and a picture that becomes READY or is deleted. Each trigger first asks, with ONE read of
 * the published layout (or of its compiled copy, for pictures), whether the layout holds a tile of THAT entity: when it does not, nothing is asked (a shop with no tiles, or a change of something no tile
 * shows, pays one cached setting read and no recompile). The rebuild is DEBOUNCED like the menus': several changes in one request compile once, at the end of the request or after a queue job.
 *
 * A singleton (the flag lives for the process). `runs` counts the recompiles (the tests read it).
 */
final class HomeTilesRecompiler
{
    public int $runs = 0;

    private bool $pending = false;

    private bool $hooked = false;

    /** A category, season, tag or brand changed: recompile when a tile shows exactly that one. */
    public function entity(string $source, int $id): void
    {
        $shown = $this->shown();

        // HP-5: a brands carousel shows EVERY brand that has a logo: any brand that is saved or deleted
        (($shown[$source][$id] ?? false) || ($source === 'brand' && ($shown['brands'] ?? false))) && $this->ask();
    }

    /**
     * P6e-3: the collections switch was saved. A compiled collection tile exists only while the switch is ON (HomeTiles reads the collection with the switch inside the statement), so a layout that holds a
     * collection tile must be rebuilt in both directions: OFF drops the tile from the compiled copy, ON brings it back from the stored layout.
     */
    public function collectionsSwitch(): void
    {
        ! empty($this->shown()['collection']) && $this->ask();
    }

    /** Which categories are shown changed (a product's visibility, its categories): recompile when a tile shows any category. */
    public function anyCategory(): void
    {
        ($this->shown()['category'] ?? []) !== [] && $this->ask();
    }

    /** A picture became ready, changed or was deleted: recompile when a tile's picture (its own or its entity's) is that one. */
    public function asset(int $id): void
    {
        $settings = app(SiteSettingsRepository::class);
        $entry = CheckoutHeaderCompiler::document($settings->get(AppearanceSettings::DESIGN_COMPILED_KEY))['zones'][HomeCatalog::ZONE][HomeCatalog::SCOPE] ?? null;

        is_array($entry) && in_array($id, is_array($entry['assets'] ?? null) ? $entry['assets'] : [], true) && $this->ask();
    }

    /** Recompiles now when something asked (the end of the request, or after a queue job). */
    public function flush(): void
    {
        if (! $this->pending) {
            return;
        }

        $this->pending = false;
        $this->runs++;
        app(HomeCompiler::class)->refresh();
    }

    private function ask(): void
    {
        $this->pending = true;

        if (! $this->hooked) {
            $this->hooked = true;
            app()->terminating(fn () => $this->flush());
        }
    }

    /** The entities the PUBLISHED layout's tiles show: source => id => true. @return array<string, array<int, true>> */
    private function shown(): array
    {
        $json = app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY);

        // the cheap test first: a layout with no tiles section and no brands carousel is not even decoded
        if (! is_string($json) || (! str_contains($json, '"tiles"') && ! str_contains($json, '"brands"'))) {
            return [];
        }

        $layout = json_decode($json, true);
        $shown = [];

        foreach (is_array($layout['sections'] ?? null) ? $layout['sections'] : [] as $section) {
            // HP-5: a brands carousel (the flag `brands` is no source name: it is read by entity())
            if (is_array($section) && ($section['type'] ?? null) === 'carousel' && ($section['settings']['kind'] ?? null) === 'brands') {
                $shown['brands'] = true;
            }

            if (is_array($section) && ($section['type'] ?? null) === 'tiles') {
                foreach (is_array($section['settings']['tiles'] ?? null) ? $section['settings']['tiles'] : [] as $tile) {
                    is_array($tile) && is_string($tile['source'] ?? null) && is_int($tile['entity'] ?? null) && $shown[$tile['source']][$tile['entity']] = true;
                }
            }
        }

        return $shown;
    }
}
