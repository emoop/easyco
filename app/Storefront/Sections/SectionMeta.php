<?php

namespace App\Storefront\Sections;

use App\Storefront\Url\StorefrontUrls;
use Illuminate\Support\Facades\DB;

/**
 * P4c: the «Мета информация» section source: the product's season and tags as plain links to their pages («Сезон:» / «Етикети:»).
 *
 * ONE query reads both (a UNION of the season and the tags of the product); it runs only when a published section has this source. The product on the page is visible, so each of its seasons / tags has at least
 * one visible product and its page exists. Names are escaped here; the URLs are the storefront's own (StorefrontUrls). Order: by name.
 */
final class SectionMeta
{
    /**
     * Does any of these definitions use the source?
     *
     * @param  list<array{source: string}>|null  $definitions
     */
    public static function isUsed(?array $definitions): bool
    {
        foreach ($definitions ?? [] as $section) {
            if ($section['source'] === 'meta') {
                return true;
            }
        }

        return false;
    }

    /**
     * The product's season(s) and tags: ONE query.
     *
     * @return array{seasons: list<array{name: string, url: string}>, tags: list<array{name: string, url: string}>}
     */
    public static function forProduct(int $productId, StorefrontUrls $urls): array
    {
        $out = ['seasons' => [], 'tags' => []];

        if ($productId < 1) {
            return $out;
        }

        $seasons = DB::table('catalog_products')->join('catalog_seasons', 'catalog_seasons.id', '=', 'catalog_products.season_id')->where('catalog_products.id', $productId)->selectRaw("'season' as kind, catalog_seasons.name as name, catalog_seasons.slug as slug");
        $tags = DB::table('catalog_product_tags')->join('catalog_tags', 'catalog_tags.id', '=', 'catalog_product_tags.tag_id')->where('catalog_product_tags.product_id', $productId)->selectRaw("'tag' as kind, catalog_tags.name as name, catalog_tags.slug as slug");

        foreach ($seasons->unionAll($tags)->get() as $row) {
            $link = ['name' => (string) $row->name, 'url' => $row->kind === 'season' ? $urls->season((string) $row->slug) : $urls->tag((string) $row->slug)];
            $out[$row->kind === 'season' ? 'seasons' : 'tags'][] = $link;
        }

        foreach ($out as $kind => $links) {
            usort($links, static fn (array $a, array $b): int => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])));
            $out[$kind] = $links;
        }

        return $out;
    }

    /**
     * The section's HTML: up to two lines, a line only when it is switched on AND has values; '' when there is none (the section is then not printed).
     *
     * @param  array{seasons: bool, tags: bool}  $settings
     * @param  array{seasons: list<array{name: string, url: string}>, tags: list<array{name: string, url: string}>}  $meta
     */
    public static function html(array $settings, array $meta): string
    {
        $lines = '';

        foreach (['seasons' => 'storefront.meta.season', 'tags' => 'storefront.meta.tags'] as $kind => $label) {
            if (($settings[$kind] ?? true) !== true || ($meta[$kind] ?? []) === []) {
                continue;
            }

            $links = implode(', ', array_map(static fn (array $link): string => '<a href="'.e($link['url']).'">'.e($link['name']).'</a>', $meta[$kind]));
            $lines .= '<p class="sf-psec-meta"><span class="sf-psec-meta-label">'.e((string) __($label)).'</span> '.$links.'</p>';
        }

        return $lines;
    }
}
