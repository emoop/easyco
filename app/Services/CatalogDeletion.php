<?php

namespace App\Services;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Exceptions\VariationNotDeletableException;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Variation;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The ONE place a Catalog record's deletability is decided, and the ONE
 * place a variation is hard-deleted — catalog-domain-design.md §3.19.3
 * (G-D1/G-D2). Two callers: the admin's per-variation delete
 * (ProductResource's EditVariableProduct page) and
 * App\Console\Commands\PruneProductsToOriginal, whose own inline
 * sale-line gate this replaces so the command and the admin path cannot
 * drift apart.
 *
 * WHY THIS IS APP-LAYER, NOT CATALOG: the deciding fact is
 * `operational_sales_sale_lines.priceable_id` — another domain's table.
 * Catalog may never query it (CLAUDE.md rule 1;
 * operational-sales-domain-design.md §1), and OperationalSales must never
 * be asked "is this Variation deletable?" either: that would make a sales
 * package own a catalog lifecycle decision. This is the same
 * cross-package composition point app/ already hosts for OrderAdminReader
 * (cross-domain reads) and CheckoutOrchestrator (cross-domain writes).
 *
 * THE HISTORY RULE, IN ONE PLACE: a variation has history iff at least one
 * `operational_sales_sale_lines` row references it by `priceable_id` —
 * ANY type (SALE, REFUND, RESERVATION, INSTALLMENT_PAYMENT), any status,
 * and INCLUDING soft-deleted lines, because a soft-deleted sale line is
 * still a record of money that changed hands. That read is deliberately a
 * raw DB::table(): the SoftDeletes scope on SaleLineModel would HIDE
 * exactly the rows this must see (the reverse of OrderAdminReader's own
 * explicit `whereNull('deleted_at')`, which wants the opposite).
 *
 * CONCURRENCY — see §3.19.5, and note the lock ORDER is the load-bearing
 * part: `deleteVariation()` locks the variation's `stock_levels` row
 * first and only then the sale-line index range, which is the same order
 * CheckoutOrchestrator's own transaction uses (step 7 decrements stock,
 * step 8 writes the sale lines, both inside one transaction). Two
 * transactions taking the same two resources in the same order cannot
 * deadlock, so no retry is needed for the checkout interaction; the
 * `attempts: 3` retry on the transaction covers the ordinary deadlock
 * risk between two concurrent deletions, which CAN interleave differently
 * (they lock several variations in ascending id order, §3.19.5 rule 4).
 *
 * NOT IN HERE, DELIBERATELY: authorization (the UI/HTTP layer enforces
 * who may ask — §3.19.9), and product deletion / the change-axes flow
 * (stages 3-4; `impactForProduct()`/`deleteProduct()` do not exist yet).
 */
final class CatalogDeletion
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly VariationRepository $variations,
        private readonly ActivityLogger $activityLogger,
    ) {
    }

    /**
     * Sale-line counts per variation id — the one history rule. Ids with
     * no sale lines are ABSENT from the result (not present as 0), the
     * same "the caller decides what a missing entry means" posture every
     * set-based Catalog lookup in this codebase already takes.
     *
     * One indexed query for any number of ids (the
     * `os_sale_lines_priceable_id_index` migration this stage adds), never
     * one per variation — which is what lets PruneProductsToOriginal check
     * thousands of doomed variations at once.
     *
     * @param string[] $variationIds
     * @return array<string, int>
     */
    public function variationIdsWithHistory(array $variationIds): array
    {
        if ($variationIds === []) {
            return [];
        }

        return DB::table('operational_sales_sale_lines')
            ->whereIn('priceable_id', $variationIds)
            ->groupBy('priceable_id')
            ->selectRaw('priceable_id, COUNT(*) as line_count')
            ->pluck('line_count', 'priceable_id')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * Read-only. Nothing is locked, nothing is written: this is what a
     * confirmation modal renders, and `deleteVariation()` re-runs every
     * check inside its own locking transaction regardless of what this
     * returned — an impact computed a second ago is never trusted as
     * permission to delete.
     */
    public function impactForVariation(string $variationId): VariationDeletionImpact
    {
        $variation = $this->requireVariation($variationId);
        $product = $this->requireProduct($variation->productId());

        $saleLineCount = $this->variationIdsWithHistory([$variationId])[$variationId] ?? 0;
        $stockQuantity = $this->stockQuantity($variationId);
        $counts = $this->configurationCounts($variationId);

        return new VariationDeletionImpact(
            variationId: $variationId,
            productId: $product->id() ?? '',
            productName: $product->name(),
            sku: $variation->sku(),
            barcode: $variation->barcode(),
            status: $variation->status()->value,
            attributeSignature: $variation->attributeSignature()->value(),
            attributes: $this->attributesFor($variationId),
            saleLineCount: $saleLineCount,
            stockQuantity: $stockQuantity,
            cartLineCount: $counts['cart_lines'],
            convertedCartLineCount: $counts['converted_cart_lines'],
            priceListItemCount: $counts['price_list_items'],
            costRowCount: $counts['cost_rows'],
            mediaCount: $counts['media'],
            refusal: $this->refusalFor($variation->sku(), $saleLineCount, $stockQuantity),
        );
    }

    /**
     * Permanently deletes one STANDARD variation, or refuses with the
     * domain's own reason — §3.19.4 steps 1-6, §3.19.5's locking rules and
     * §3.19.10's snapshot.
     *
     * @throws VariationNotDeletableException When it has history or non-zero stock.
     * @throws \LogicException When it does not belong to its product, or is UNIVERSAL — Product::removeStandardVariation()'s own guards.
     */
    public function deleteVariation(string $variationId): void
    {
        DB::transaction(function () use ($variationId): void {
            $variation = $this->requireVariation($variationId);

            // §3.19.5 rule 1: the stock row is locked FIRST, and that same
            // read performs the stock check. A missing row is zero
            // (StockLevelRepository::findByVariationId()'s own documented
            // "no row and zero are the same fact"), and FOR UPDATE on a
            // missing unique key takes a gap lock, so a concurrent
            // increase()/save() cannot create the row underneath this
            // decision.
            $stockQuantity = $this->lockStockRow($variationId);

            // §3.19.5 rule 2: a LOCKING read, never a plain count. Under
            // MySQL's default REPEATABLE READ the consistent snapshot is
            // fixed by the transaction's first non-locking read, so a sale
            // line committed by a checkout after that snapshot would be
            // invisible here — and a variation with history would be
            // deleted, which is the one thing CLAUDE.md rule 4 forbids.
            $saleLineCount = $this->lockHistoryCount($variationId);

            if (($refusal = $this->refusalFor($variation->sku(), $saleLineCount, $stockQuantity)) !== null) {
                throw $refusal;
            }

            // The domain's own two guards — belongs to this product, and
            // STANDARD (never UNIVERSAL) — run BEFORE anything is deleted,
            // so a refused variation is left completely untouched.
            $product = $this->requireProduct($variation->productId());
            $owned = $this->ownedVariation($product, $variationId);
            $product->removeStandardVariation($owned);

            // §3.19.10/G-D7: the snapshot is written BEFORE the rows go,
            // inside this same transaction and deliberately so — if the
            // delete rolls back, the record of a deletion that never
            // happened rolls back with it.
            $this->activityLogger->logDeleted(
                'variation',
                $variationId,
                $this->snapshotFor($product, $owned, $saleLineCount, $stockQuantity),
            );

            // §3.19.4 steps 1-4: cross-domain configuration rows. Deleted
            // explicitly because Catalog must not know these tables exist
            // (rule 1), and because stock_levels/cart_lines carry
            // restrictOnDelete() FKs that would block step 5 outright.
            DB::table('stock_levels')->where('variation_id', $variationId)->delete();
            DB::table('cart_lines')->where('variation_id', $variationId)->delete();
            DB::table('pricing_price_list_items')
                ->where('target_type', PriceListItemTargetType::VARIATION->value)
                ->where('target_id', $variationId)
                ->delete();
            DB::table('pricing_product_costs')->where('priceable_id', $variationId)->delete();

            // §3.19.4 step 5 — the catalog row itself, which then cascades
            // its own children (catalog_variation_attribute_values,
            // catalog_variation_media). forceDelete, inside the repository.
            $this->products->deleteVariation($owned);
        }, attempts: 3);
    }

    private function requireVariation(string $variationId): Variation
    {
        $variation = $this->variations->findById($variationId);

        if ($variation === null) {
            throw new InvalidArgumentException("Variation \"{$variationId}\" does not exist.");
        }

        return $variation;
    }

    private function requireProduct(string $productId): Product
    {
        $product = $this->products->findByIdWithVariations($productId);

        if ($product === null) {
            throw new InvalidArgumentException("Product \"{$productId}\" does not exist.");
        }

        return $product;
    }

    /**
     * The aggregate's own instance of this variation — the object
     * Product::removeStandardVariation() must receive, since it compares by
     * identity (§3.7's own ownership rule). A variation that is not in the
     * aggregate is either already gone or belongs elsewhere; both are
     * stale-page states, reported rather than half-acted-on.
     *
     * NOTE: this is also why a SOFT-deleted variation cannot be deleted
     * through this service — VariationModel's SoftDeletes scope hides it
     * from both finders, so it never reaches here. Nothing in this
     * codebase soft deletes a variation (Variation::archive() sets status
     * ARCHIVED — a real row), so that state is unreachable rather than
     * merely unhandled; product deletion's own withTrashed() sweep is
     * stage 3.
     */
    private function ownedVariation(Product $product, string $variationId): Variation
    {
        foreach ($product->variations() as $candidate) {
            if ((string) $candidate->id() === $variationId) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException(
            "Variation \"{$variationId}\" does not belong to product \"{$product->id()}\"."
        );
    }

    /** History first, stock second — G-D2's own order, and the order the two refusal messages assume. */
    private function refusalFor(string $sku, int $saleLineCount, int $stockQuantity): ?VariationNotDeletableException
    {
        if ($saleLineCount > 0) {
            return VariationNotDeletableException::becauseItHasHistory($sku, $saleLineCount);
        }

        if ($stockQuantity !== 0) {
            return VariationNotDeletableException::becauseStockIsNotZero($sku, $stockQuantity);
        }

        return null;
    }

    /** Plain read, no lock — the impact/report side. deleteVariation() uses lockStockRow() instead. */
    private function stockQuantity(string $variationId): int
    {
        $quantity = DB::table('stock_levels')->where('variation_id', $variationId)->value('quantity');

        return $quantity !== null ? (int) $quantity : 0;
    }

    /** @return int The current quantity, locked — 0 when no row exists (§3.19.5 rule 1). */
    private function lockStockRow(string $variationId): int
    {
        $quantity = DB::table('stock_levels')
            ->where('variation_id', $variationId)
            ->lockForUpdate()
            ->value('quantity');

        return $quantity !== null ? (int) $quantity : 0;
    }

    /**
     * The locking history read (§3.19.5 rule 2), emitting `... FOR UPDATE`
     * — index-backed by `os_sale_lines_priceable_id_index`, so it locks this
     * variation's own rows and their index neighbours rather than scanning
     * (and locking) the whole table.
     */
    private function lockHistoryCount(string $variationId): int
    {
        return DB::table('operational_sales_sale_lines')
            ->where('priceable_id', $variationId)
            ->lockForUpdate()
            ->count();
    }

    /**
     * Every configuration row count the delete removes, in ONE place — used
     * by both the impact report and the activity-log snapshot, so the modal
     * and the permanent record cannot disagree about what went.
     *
     * Raw DB::table() throughout, matching what the delete itself removes:
     * a row another model would hide behind SoftDeletes is a row this
     * delete genuinely takes, so the count and the delete stay the same set.
     *
     * @return array{cart_lines: int, converted_cart_lines: int, price_list_items: int, cost_rows: int, media: int}
     */
    private function configurationCounts(string $variationId): array
    {
        return [
            'cart_lines' => DB::table('cart_lines')->where('variation_id', $variationId)->count(),
            // Converted = its cart already became an order (checkout sets
            // carts.order_id and never clears the lines), so this row is a
            // basket that has already served its purpose, not a live one.
            'converted_cart_lines' => DB::table('cart_lines')
                ->join('carts', 'carts.id', '=', 'cart_lines.cart_id')
                ->where('cart_lines.variation_id', $variationId)
                ->whereNotNull('carts.order_id')
                ->count(),
            'price_list_items' => DB::table('pricing_price_list_items')
                ->where('target_type', PriceListItemTargetType::VARIATION->value)
                ->where('target_id', $variationId)
                ->count(),
            'cost_rows' => DB::table('pricing_product_costs')->where('priceable_id', $variationId)->count(),
            'media' => DB::table('catalog_variation_media')->where('variation_id', $variationId)->count(),
        ];
    }

    /**
     * The variation's sold combination as display pairs, ordered by
     * attribute_definition_id — the same deterministic order
     * SaleLineSnapshotBuilder uses for `sold_attributes` (§3.13 D5's own
     * finding that no authoritative merchant axis order exists yet), so a
     * confirmation modal and a receipt at least agree with each other.
     *
     * @return array<int, array{name: string, value: string}>
     */
    private function attributesFor(string $variationId): array
    {
        return DB::table('catalog_variation_attribute_values as av')
            ->join('catalog_attribute_definitions as d', 'd.id', '=', 'av.attribute_definition_id')
            ->join('catalog_attribute_values as v', 'v.id', '=', 'av.attribute_value_id')
            ->where('av.variation_id', $variationId)
            ->orderBy('d.id')
            ->get(['d.name as definition_name', 'v.value as value'])
            ->map(static fn (object $row): array => [
                'name' => (string) $row->definition_name,
                'value' => (string) $row->value,
            ])
            ->all();
    }

    /**
     * §3.19.10/G-D7: everything a human needs to know what was destroyed, in
     * one JSON payload. The product's own identifiers are included even
     * though only ONE variation goes, because a freed SKU/barcode is only
     * meaningful next to the product it belonged to — and because ids are
     * not a stable key once rows are hard-deleted.
     *
     * @return array<string, mixed>
     */
    private function snapshotFor(Product $product, Variation $variation, int $saleLineCount, int $stockQuantity): array
    {
        $counts = $this->configurationCounts((string) $variation->id());

        return [
            'product_id' => $product->id(),
            'product_name' => $product->name(),
            'product_base_sku' => $product->baseSku(),
            'product_slug' => $product->slug(),
            'variation_id' => $variation->id(),
            'variation_sku' => $variation->sku(),
            'variation_barcode' => $variation->barcode(),
            'variation_status' => $variation->status()->value,
            'variation_type' => $variation->type()->value,
            'attribute_signature' => $variation->attributeSignature()->value(),
            'attributes' => $this->attributesFor((string) $variation->id()),
            'sale_line_count' => $saleLineCount,
            'stock_quantity' => $stockQuantity,
            // $product has already had the variation removed by
            // removeStandardVariation() above, so this is what the product
            // keeps after the delete.
            'remaining_variation_count' => count($product->variations()),
            'cart_line_count' => $counts['cart_lines'],
            'converted_cart_line_count' => $counts['converted_cart_lines'],
            'price_list_item_count' => $counts['price_list_items'],
            'cost_row_count' => $counts['cost_rows'],
            'media_count' => $counts['media'],
        ];
    }
}
