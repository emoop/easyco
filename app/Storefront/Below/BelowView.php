<?php

namespace App\Storefront\Below;

use App\Storefront\Conditions\ProductConditions;
use App\Storefront\Conditions\ProductContext;
use App\Storefront\Home\HomeCatalog;
use App\Storefront\Home\HomeView;

/**
 * P4: what the product page prints below its main area, from the PUBLISHED compiled copy (BelowCompiler::published) and the CURRENT product. Each slot becomes the same `{gap, items}` a home section list is (HomeView:
 * the home page's own partials, classes from closed lists, HTML cleaned again) plus its heading. Nothing here depends on the visitor: the same product gives the same HTML.
 *
 * A slot that has nothing to show is dropped WITH its heading: an HTML block that is gone or empty, an HTML block whose «Показвай, когато…» conditions do not hold for this product, a carousel with no card, and a
 * «Подобни продукти» carousel (source `similar`) with fewer than two products.
 */
final class BelowView
{
    /** The fewest products «Подобни продукти» needs to be printed. */
    public const SIMILAR_MIN = 2;

    /**
     * @param  array{blocks: array<string, string>, conditions: array<string, mixed>, slots: list<array<string, mixed>>}|null  $compiled  BelowCompiler::published()
     * @param  \Closure(string, ?int, string, int, string): list<\App\Storefront\ReadModels\ProductCard>  $read  the carousel's products (source, entity, sort, count, sizes), for THIS product
     * @return list<array{gap: string, items: list<array<string, mixed>>, heading: string|null}>|null null = nothing to print (the page is what it always was)
     */
    public static function forProduct(?array $compiled, ProductContext $context, \Closure $read, int $gapX = 16, int $phoneColumns = 2): ?array
    {
        if ($compiled === null) {
            return null;
        }

        $out = [];

        foreach ($compiled['slots'] as $slot) {
            // HB-2: an HTML block that is not for this product is not printed at all
            if ($slot['type'] === 'html_block') {
                $conditions = $compiled['conditions'][(string) ($slot['html_block'] ?? '')] ?? null;

                if ($conditions !== null && ! ProductConditions::matches($context, $conditions)) {
                    continue;
                }
            }

            $prepared = HomeView::prepare(['layout' => ['spacing' => '0', 'sections' => BelowSlots::sections($slot)], 'blocks' => $compiled['blocks']], HomeCatalog::PRODUCT_MODE);

            if ($prepared === null || $prepared['items'] === []) {
                continue;
            }

            $prepared = HomeView::fill($prepared, $read, $gapX, $phoneColumns);

            if ($prepared['items'] === []) {
                continue;
            }

            $carousel = $prepared['items'][0]['carousel'] ?? null;

            if (is_array($carousel) && ($carousel['source'] ?? null) === HomeCatalog::SIMILAR_SOURCE && count($carousel['cards'] ?? []) < self::SIMILAR_MIN) {
                continue;
            }

            $out[] = $prepared + ['heading' => ($slot['heading'] ?? '') === '' ? null : $slot['heading']];
        }

        return $out === [] ? null : $out;
    }
}
