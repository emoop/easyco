<?php

namespace App\Services;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Enums\VariationDeletionRefusal;
use EasyCo\Catalog\Exceptions\ProductNotDeletableException;
use EasyCo\Catalog\Exceptions\VariationNotDeletableException;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Variation;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Promotions\Enums\PromotionScopeType;
use Illuminate\Database\Query\Builder;
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
 * who may ask — §3.19.9), and the change-axes flow (stage 4).
 *
 * PRODUCT DELETION (stage 3) IS THE SAME SERVICE, ON PURPOSE — §3.19.3's
 * own "the impact objects and the delete methods are one service": its
 * step 1 for every variation IS deleteVariation()'s own steps, reached
 * through `deleteVariationConfigurationRows()` below rather than written
 * out a second time, and its per-variation verdicts ARE
 * VariationDeletionImpact built by the same private builder the
 * single-variation impact uses. The one thing the product path adds is the
 * `catalog_variations`/`catalog_products` sweep, which is the repository's
 * (delete()), because a SOFT-DELETED variation has no domain object to pass
 * to deleteVariation().
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

        return $this->historyCountsQuery($variationIds)
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

        return $this->buildVariationImpact(
            $product,
            $this->variationIdentity($variation),
            $this->variationIdsWithHistory([$variationId]),
            $this->stockQuantities([$variationId]),
            $this->configurationCountsForMany([$variationId]),
            $this->attributesForMany([$variationId]),
        );
    }

    /**
     * §3.19.3/§3.19.8 B — the product-delete counterpart of
     * impactForVariation(), and the same posture: read-only, nothing locked,
     * nothing written, and never trusted by `deleteProduct()` as permission
     * to delete (it re-runs every check inside its own locking transaction).
     *
     * THE VARIATION SET IS THE DELETE'S OWN SCOPE, NOT THE AGGREGATE'S: every
     * `catalog_variations` row of the product — UNIVERSAL, live STANDARD,
     * ARCHIVED and soft-deleted alike — because §3.19.4 step 1 takes all of
     * them, and a soft-deleted row has no domain object to ask. The per-row
     * verdicts themselves are built by the ONE builder impactForVariation()
     * also uses.
     *
     * @throws InvalidArgumentException When the product does not exist.
     */
    public function impactForProduct(string $productId): ProductDeletionImpact
    {
        return $this->buildProductImpact($this->requireProduct($productId));
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

            // §3.19.5 rule 2: a LOCKING read, never a plain count — the same
            // one the product path makes, for this single id. Under MySQL's
            // default REPEATABLE READ the consistent snapshot is fixed by the
            // transaction's first non-locking read, so a sale line committed
            // by a checkout after that snapshot would be invisible here — and
            // a variation with history would be deleted, which is the one
            // thing CLAUDE.md rule 4 forbids.
            $saleLineCount = $this->lockVariationIdsWithHistory([$variationId])[$variationId] ?? 0;

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

            // §3.19.4 steps 1-4 — the SAME single routine the product path
            // applies once per variation (step 1 of a product deletion IS this
            // sequence), deleted explicitly because Catalog must not know
            // these tables exist (rule 1) and because stock_levels/cart_lines
            // carry restrictOnDelete() FKs that would block step 5 outright.
            $this->deleteVariationConfigurationRows($variationId);

            // §3.19.4 step 5 — the catalog row itself, which then cascades
            // its own children (catalog_variation_attribute_values,
            // catalog_variation_media). forceDelete, inside the repository.
            $this->products->deleteVariation($owned);
        }, attempts: 3);
    }

    /**
     * Permanently deletes an ARCHIVED product and everything §3.19.4 lists
     * for it, or refuses with the domain's own reason — §3.19.3's
     * `deleteProduct()`, §3.19.4 steps 1-6, §3.19.5's locking rules.
     *
     * THE ORDER INSIDE THE TRANSACTION IS THE DESIGN, IN FOUR PARTS:
     *
     *  1. LOCK the product row and re-read its status ON THAT LOCKING READ.
     *     G-D3's gate is re-checked here, never trusted from the impact: a
     *     concurrent un-archive either already committed (this read sees
     *     ACTIVE/DRAFT and we refuse) or blocks here until it does — the
     *     delete loses cleanly, never half-way. A plain read would see the
     *     transaction's own snapshot instead, which is exactly the staleness
     *     §3.19.5 exists to close.
     *  2. Lock EVERY variation's `stock_levels` row, one per variation in
     *     ascending `catalog_variations.id` order (§3.19.5 rule 4), reading
     *     each quantity with the same lock that decides on it. A checkout
     *     for any of them now blocks on the stock decrement that precedes
     *     its sale-line write, so no new history can be committed for this
     *     product from here on.
     *  3. ONE locking history read for all of the variation ids (§3.19.5
     *     rule 2) — after the stock locks, never before: the stock locks are
     *     what make that window closed.
     *  4. Only then write: the snapshot, then step 1 per variation, then the
     *     product-scope rows, then the repository's own catalog sweep.
     *
     * THE VARIATION ROWS INCLUDED ARE EVERY ONE OF THE PRODUCT'S — UNIVERSAL,
     * live STANDARD, ARCHIVED and soft-deleted alike, in that same ascending
     * id order — because §3.19.4 step 1 says so and because
     * `catalog_variations.product_id` is `restrictOnDelete()`: the product
     * row cannot go until all of them have, including the ones a SoftDeletes
     * scope would hide.
     *
     * A UNIVERSAL variation goes with its product here without
     * Product::removeStandardVariation()'s structural objection ever being
     * consulted — that guard protects "delete one variation on its own",
     * which this is not (G-D2's own words).
     *
     * @throws ProductNotDeletableException When it is not ARCHIVED, or any of its variations has history or non-zero stock.
     * @throws InvalidArgumentException When the product does not exist.
     */
    public function deleteProduct(string $productId): void
    {
        DB::transaction(function () use ($productId): void {
            // 1. The product row's own lock, and G-D3's re-check on it.
            $status = DB::table('catalog_products')
                ->where('id', $productId)
                ->lockForUpdate()
                ->value('status');

            if ($status === null) {
                throw new InvalidArgumentException("Product \"{$productId}\" does not exist.");
            }

            $product = $this->requireProduct($productId);

            if ($status !== ProductStatus::ARCHIVED->value) {
                throw ProductNotDeletableException::becauseNotArchived($product->name());
            }

            $identities = $this->allVariationIdentities($productId);
            $variationIds = array_map(
                static fn (array $identity): string => $identity['id'],
                $identities,
            );

            // 2. The stock lock per variation, ascending — the SAME routine
            // (and so the same SQL) deleteVariation() locks with.
            $stockQuantities = [];
            foreach ($variationIds as $variationId) {
                $stockQuantities[$variationId] = $this->lockStockRow($variationId);
            }

            // 3. ONE locking history read for all of them.
            $saleLineCounts = $this->lockVariationIdsWithHistory($variationIds);

            // 4. The verdicts, built by the same builder the impact uses.
            $counts = $this->configurationCountsForMany($variationIds);
            $attributes = $this->attributesForMany($variationIds);

            $impacts = array_map(
                fn (array $identity): VariationDeletionImpact => $this->buildVariationImpact(
                    $product,
                    $identity,
                    $saleLineCounts,
                    $stockQuantities,
                    $counts,
                    $attributes,
                ),
                $identities,
            );

            $blocked = $this->blockingVariations($impacts);

            if ($blocked !== []) {
                throw ProductNotDeletableException::becauseVariationsBlockDeletion($product->name(), $blocked);
            }

            // §3.19.10/G-D7: the snapshot is written BEFORE the rows go, in
            // this same transaction — and it carries EVERY variation, because
            // this one record is the only trace the product and its
            // variations ever existed.
            $this->activityLogger->logDeleted(
                'product',
                $productId,
                $this->productSnapshotFor($product, $impacts, $this->productScopeCounts($productId)),
            );

            // §3.19.4 step 1 — the per-variation sequence, once per variation.
            foreach ($variationIds as $variationId) {
                $this->deleteVariationConfigurationRows($variationId);
            }

            // §3.19.4 steps 2-4 — the rows only a product deletion reaches:
            // price items and scopes that apply BECAUSE OF this product.
            DB::table('pricing_price_list_items')
                ->where('target_type', PriceListItemTargetType::PRODUCT->value)
                ->where('target_id', $productId)
                ->delete();
            DB::table('pricing_price_list_scopes')
                ->where('scope_type', PriceListScopeType::PRODUCT->value)
                ->where('scope_reference_id', $productId)
                ->delete();
            DB::table('promotion_scopes')
                ->where('scope_type', PromotionScopeType::PRODUCT->value)
                ->where('scope_reference_id', $productId)
                ->delete();

            // §3.19.4 steps 5-6 — the catalog rows, repository-owned and in
            // that one order (the variations' restrict FK forbids the reverse).
            $this->products->delete($product);
        }, attempts: 3);
    }

    /**
     * §3.19.4 steps 1-4 for ONE variation — the single definition of "how a
     * variation's configuration goes away", and therefore the one routine
     * BOTH deletions call: deleteVariation() for its own row, and
     * deleteProduct() once per variation of the product. §3.19.4's own note
     * is the reason: step 1 of a product deletion IS the variation sequence,
     * and a second, parallel version would be a second thing to keep in step.
     *
     * Cross-domain tables, explicitly (the caller is inside a transaction):
     * Catalog must not know these tables exist (CLAUDE.md rule 1), and
     * `stock_levels`/`cart_lines` carry restrictOnDelete() FKs that would
     * otherwise block the catalog row's own delete.
     */
    private function deleteVariationConfigurationRows(string $variationId): void
    {
        DB::table('stock_levels')->where('variation_id', $variationId)->delete();
        DB::table('cart_lines')->where('variation_id', $variationId)->delete();
        DB::table('pricing_price_list_items')
            ->where('target_type', PriceListItemTargetType::VARIATION->value)
            ->where('target_id', $variationId)
            ->delete();
        DB::table('pricing_product_costs')->where('priceable_id', $variationId)->delete();
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
     * through deleteVariation() — VariationModel's SoftDeletes scope hides it
     * from both finders, so it never reaches here. Nothing in this codebase
     * soft deletes a variation (Variation::archive() sets status ARCHIVED — a
     * real row), so that state is unreachable rather than merely unhandled;
     * deletion of a whole product sweeps such rows up through the raw read in
     * allVariationIdentities() plus ProductRepository::delete()'s own
     * withTrashed() force-delete.
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

    /**
     * §3.19.3's product impact — the whole set §3.19.4 step 1 takes,
     * each variation's verdict built by the same builder the single-variation
     * impact uses, and the totals summed from those per-variation counts plus
     * the product-scope rows the variation loop never sees.
     */
    private function buildProductImpact(Product $product): ProductDeletionImpact
    {
        $productId = (string) $product->id();

        $identities = $this->allVariationIdentities($productId);
        $variationIds = array_map(
            static fn (array $identity): string => $identity['id'],
            $identities,
        );

        $saleLineCounts = $this->variationIdsWithHistory($variationIds);
        $stockQuantities = $this->stockQuantities($variationIds);
        $counts = $this->configurationCountsForMany($variationIds);
        $attributes = $this->attributesForMany($variationIds);

        $variations = array_map(
            fn (array $identity): VariationDeletionImpact => $this->buildVariationImpact(
                $product,
                $identity,
                $saleLineCounts,
                $stockQuantities,
                $counts,
                $attributes,
            ),
            $identities,
        );

        $cartLineCount = 0;
        $convertedCartLineCount = 0;
        $variationPriceListItemCount = 0;
        $costRowCount = 0;
        $variationMediaCount = 0;

        foreach ($variations as $variation) {
            $cartLineCount += $variation->cartLineCount;
            $convertedCartLineCount += $variation->convertedCartLineCount;
            $variationPriceListItemCount += $variation->priceListItemCount;
            $costRowCount += $variation->costRowCount;
            $variationMediaCount += $variation->mediaCount;
        }

        $productCounts = $this->productScopeCounts($productId);

        return new ProductDeletionImpact(
            productId: $productId,
            productName: $product->name(),
            baseSku: $product->baseSku(),
            slug: $product->slug(),
            status: $product->status()->value,
            variations: $variations,
            cartLineCount: $cartLineCount,
            convertedCartLineCount: $convertedCartLineCount,
            priceListItemCount: $variationPriceListItemCount + $productCounts['price_list_items'],
            productPriceListItemCount: $productCounts['price_list_items'],
            priceListScopeCount: $productCounts['price_list_scopes'],
            promotionScopeCount: $productCounts['promotion_scopes'],
            costRowCount: $costRowCount,
            mediaCount: $variationMediaCount + $productCounts['media'],
            productMediaCount: $productCounts['media'],
            refusal: $this->productRefusalFor($product, $this->blockingVariations($variations)),
        );
    }

    /**
     * G-D3's gate, in the impact's own (read-only) terms: NOT ARCHIVED first,
     * then a blocked variation. THE ORDER MATTERS — "archive it first" is the
     * answer for every non-archived product regardless of its variations, so a
     * merchant is never told about a stock problem on a product they were
     * never going to be allowed to delete anyway.
     *
     * @param list<array{sku: string, count: int, reason: VariationDeletionRefusal}> $blocked
     */
    private function productRefusalFor(Product $product, array $blocked): ?ProductNotDeletableException
    {
        if ($product->status() !== ProductStatus::ARCHIVED) {
            return ProductNotDeletableException::becauseNotArchived($product->name());
        }

        if ($blocked !== []) {
            return ProductNotDeletableException::becauseVariationsBlockDeletion($product->name(), $blocked);
        }

        return null;
    }

    /**
     * The ONE per-variation impact builder: impactForVariation() calls it with
     * one variation's reads, and both product paths call it once per variation
     * with the batched reads — so a per-variation verdict, its counts or its
     * refusal cannot differ between the two flows.
     *
     * @param array{id: string, sku: string, barcode: ?string, status: string, type: string, signature: string} $identity
     * @param array<string, int> $saleLineCounts
     * @param array<string, int> $stockQuantities
     * @param array<string, array{cart_lines: int, converted_cart_lines: int, price_list_items: int, cost_rows: int, media: int}> $counts
     * @param array<string, array<int, array{name: string, value: string}>> $attributes
     */
    private function buildVariationImpact(
        Product $product,
        array $identity,
        array $saleLineCounts,
        array $stockQuantities,
        array $counts,
        array $attributes,
    ): VariationDeletionImpact {
        $variationId = $identity['id'];
        $saleLineCount = $saleLineCounts[$variationId] ?? 0;
        $stockQuantity = $stockQuantities[$variationId] ?? 0;
        $countsForVariation = $counts[$variationId] ?? [
            'cart_lines' => 0,
            'converted_cart_lines' => 0,
            'price_list_items' => 0,
            'cost_rows' => 0,
            'media' => 0,
        ];

        return new VariationDeletionImpact(
            variationId: $variationId,
            productId: $product->id() ?? '',
            productName: $product->name(),
            sku: $identity['sku'],
            barcode: $identity['barcode'],
            status: $identity['status'],
            variationType: $identity['type'],
            attributeSignature: $identity['signature'],
            attributes: $attributes[$variationId] ?? [],
            saleLineCount: $saleLineCount,
            stockQuantity: $stockQuantity,
            cartLineCount: $countsForVariation['cart_lines'],
            convertedCartLineCount: $countsForVariation['converted_cart_lines'],
            priceListItemCount: $countsForVariation['price_list_items'],
            costRowCount: $countsForVariation['cost_rows'],
            mediaCount: $countsForVariation['media'],
            refusal: $this->refusalFor($identity['sku'], $saleLineCount, $stockQuantity),
        );
    }

    /** @return array{id: string, sku: string, barcode: ?string, status: string, type: string, signature: string} */
    private function variationIdentity(Variation $variation): array
    {
        return [
            'id' => (string) $variation->id(),
            'sku' => $variation->sku(),
            'barcode' => $variation->barcode(),
            'status' => $variation->status()->value,
            'type' => $variation->type()->value,
            'signature' => $variation->attributeSignature()->value(),
        ];
    }

    /**
     * Every variation row of this product INCLUDING soft-deleted ones, in
     * ascending `catalog_variations.id` order — §3.19.4 step 1's own scope,
     * and the reason this is a raw read of Catalog's own table rather than a
     * repository finder: `VariationModel` uses SoftDeletes, so the scope would
     * hide exactly the rows that must go, and an ARCHIVED or soft-deleted row
     * has no domain object to ask for its identity.
     *
     * `sku` is cast like EloquentProductRepository::toDomainVariation() casts
     * it (the column is still nullable), so a legacy null row surfaces as ''
     * here too rather than as a second, differently-typed representation.
     *
     * @return list<array{id: string, sku: string, barcode: ?string, status: string, type: string, signature: string}>
     */
    private function allVariationIdentities(string $productId): array
    {
        return DB::table('catalog_variations')
            ->where('product_id', $productId)
            ->orderBy('id')
            ->get(['id', 'sku', 'barcode', 'status', 'type', 'attribute_signature'])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'sku' => (string) $row->sku,
                'barcode' => $row->barcode !== null ? (string) $row->barcode : null,
                'status' => (string) $row->status,
                'type' => (string) $row->type,
                'signature' => (string) $row->attribute_signature,
            ])
            ->all();
    }

    /**
     * The blocked variations of a built product impact, as the exception's
     * own payload — each entry carrying the variation's SKU, the number that
     * is not zero, and its OWN `VariationDeletionRefusal` (never a re-worded
     * product-level reason). Order is the impact's: ascending
     * catalog_variations.id.
     *
     * @param list<VariationDeletionImpact> $impacts
     * @return list<array{sku: string, count: int, reason: VariationDeletionRefusal}>
     */
    private function blockingVariations(array $impacts): array
    {
        $blocked = [];

        foreach ($impacts as $impact) {
            if ($impact->refusal === null) {
                continue;
            }

            $blocked[] = [
                'sku' => $impact->sku,
                // The exception's own `count` is already "the number that must
                // be zero first" — sale lines for HAS_HISTORY, the quantity on
                // hand for HAS_STOCK (VariationNotDeletableException's docblock).
                'count' => $impact->refusal->count,
                'reason' => $impact->refusal->reason,
            ];
        }

        return $blocked;
    }

    /**
     * The product-scope configuration counts — the rows the per-variation
     * sequence cannot reach, because they reference the PRODUCT
     * (`target_type`/`scope_type = product`), plus the product's own media
     * pivots. Raw DB::table() for the same reason every other count here is:
     * this is exactly what the delete removes.
     *
     * @return array{price_list_items: int, price_list_scopes: int, promotion_scopes: int, media: int}
     */
    private function productScopeCounts(string $productId): array
    {
        return [
            'price_list_items' => DB::table('pricing_price_list_items')
                ->where('target_type', PriceListItemTargetType::PRODUCT->value)
                ->where('target_id', $productId)
                ->count(),
            'price_list_scopes' => DB::table('pricing_price_list_scopes')
                ->where('scope_type', PriceListScopeType::PRODUCT->value)
                ->where('scope_reference_id', $productId)
                ->count(),
            'promotion_scopes' => DB::table('promotion_scopes')
                ->where('scope_type', PromotionScopeType::PRODUCT->value)
                ->where('scope_reference_id', $productId)
                ->count(),
            'media' => DB::table('catalog_product_media')->where('product_id', $productId)->count(),
        ];
    }

    /**
     * §3.19.10/G-D7's product snapshot: everything a human needs to know what
     * was destroyed, in one JSON payload — the product's own identifiers, and
     * EVERY variation with its SKU, barcode, status, type, combination, sale
     * lines, stock, price-list items and cost rows, plus the aggregate and
     * product-scope counts.
     *
     * IT IS THE ONLY REMAINING TRACE OF THE ROWS, which is why it carries
     * identifiers rather than ids alone (§3.19.10's own caveat: a hard-deleted
     * row's auto-increment id can be handed to a later record) and why
     * deleting cannot be undone while this entry cannot be lost.
     *
     * @param list<VariationDeletionImpact> $impacts
     * @param array{price_list_items: int, price_list_scopes: int, promotion_scopes: int, media: int} $productCounts
     * @return array<string, mixed>
     */
    private function productSnapshotFor(Product $product, array $impacts, array $productCounts): array
    {
        $variations = array_map(
            static fn (VariationDeletionImpact $impact): array => [
                'variation_id' => $impact->variationId,
                'variation_sku' => $impact->sku,
                'variation_barcode' => $impact->barcode,
                'variation_status' => $impact->status,
                'variation_type' => $impact->variationType,
                'attribute_signature' => $impact->attributeSignature,
                'attributes' => $impact->attributes,
                'sale_line_count' => $impact->saleLineCount,
                'stock_quantity' => $impact->stockQuantity,
                'cart_line_count' => $impact->cartLineCount,
                'converted_cart_line_count' => $impact->convertedCartLineCount,
                'price_list_item_count' => $impact->priceListItemCount,
                'cost_row_count' => $impact->costRowCount,
                'media_count' => $impact->mediaCount,
            ],
            $impacts,
        );

        return [
            'product_id' => $product->id(),
            'product_name' => $product->name(),
            'product_base_sku' => $product->baseSku(),
            'product_slug' => $product->slug(),
            'product_status' => $product->status()->value,
            'variation_count' => count($impacts),
            'variations' => $variations,
            'cart_line_count' => array_sum(array_column($variations, 'cart_line_count')),
            'converted_cart_line_count' => array_sum(array_column($variations, 'converted_cart_line_count')),
            'price_list_item_count' => array_sum(array_column($variations, 'price_list_item_count')) + $productCounts['price_list_items'],
            'cost_row_count' => array_sum(array_column($variations, 'cost_row_count')),
            'media_count' => array_sum(array_column($variations, 'media_count')) + $productCounts['media'],
            'price_list_scope_count' => $productCounts['price_list_scopes'],
            'promotion_scope_count' => $productCounts['promotion_scopes'],
        ];
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

    /**
     * The plain-read quantities for any number of variations, ONE query — the
     * impact/report side. deleteVariation()/deleteProduct() use lockStockRow()
     * instead, per variation: same "no row and zero are the same fact" rule
     * (StockLevelRepository::findByVariationId()'s own), different lock.
     *
     * @param string[] $variationIds
     * @return array<string, int>
     */
    private function stockQuantities(array $variationIds): array
    {
        $quantities = [];
        foreach ($variationIds as $variationId) {
            $quantities[$variationId] = 0;
        }

        if ($quantities === []) {
            return $quantities;
        }

        foreach (DB::table('stock_levels')
            ->whereIn('variation_id', $variationIds)
            ->get(['variation_id', 'quantity']) as $row) {
            $quantities[(string) $row->variation_id] = (int) $row->quantity;
        }

        return $quantities;
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
     * The LOCKING sibling of variationIdsWithHistory() — §3.19.5 rule 2, and
     * the read both deletions make. One statement for any number of ids: the
     * product path must not take one history lock per variation, since the
     * same rule that makes the read index-backed (the
     * `os_sale_lines_priceable_id_index` migration) is what keeps its lock
     * footprint proportional to the rows it actually decides on.
     *
     * A plain count is NOT sufficient: under MySQL's default REPEATABLE READ
     * the consistent snapshot is fixed by the transaction's first
     * non-locking read, so a sale line committed after that snapshot would be
     * invisible and a variation or a whole product with history would be
     * deleted anyway. A locking read always reads the latest committed
     * version, and it additionally blocks a concurrent INSERT of a new sale
     * line for these variations.
     *
     * @param string[] $variationIds
     * @return array<string, int>
     */
    private function lockVariationIdsWithHistory(array $variationIds): array
    {
        if ($variationIds === []) {
            return [];
        }

        return $this->historyCountsQuery($variationIds)
            ->lockForUpdate()
            ->pluck('line_count', 'priceable_id')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }

    /** The one history query shape — variationIdsWithHistory() and lockVariationIdsWithHistory() differ only by their lock. */
    private function historyCountsQuery(array $variationIds): Builder
    {
        return DB::table('operational_sales_sale_lines')
            ->whereIn('priceable_id', $variationIds)
            ->groupBy('priceable_id')
            ->selectRaw('priceable_id, COUNT(*) as line_count');
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
    /** @return array{cart_lines: int, converted_cart_lines: int, price_list_items: int, cost_rows: int, media: int} */
    private function configurationCounts(string $variationId): array
    {
        return $this->configurationCountsForMany([$variationId])[$variationId];
    }

    /**
     * Every configuration row count the delete removes, for any number of
     * variations in a BOUNDED number of queries — one per table, grouped by
     * the variation id, never one query per variation. The single-variation
     * configurationCounts() above is this method with one id, so the modal,
     * the product modal and both snapshots cannot disagree about what went.
     *
     * Raw DB::table() throughout, matching what the delete itself removes: a
     * row another model would hide behind SoftDeletes is a row this delete
     * genuinely takes, so the count and the delete stay the same set.
     *
     * Ids with no rows keep an all-zero entry (present, not omitted) — a
     * variation genuinely has "no cart lines", and a missing key would make
     * every caller invent the same default.
     *
     * @param string[] $variationIds
     * @return array<string, array{cart_lines: int, converted_cart_lines: int, price_list_items: int, cost_rows: int, media: int}>
     */
    private function configurationCountsForMany(array $variationIds): array
    {
        $counts = [];
        foreach ($variationIds as $variationId) {
            $counts[$variationId] = [
                'cart_lines' => 0,
                'converted_cart_lines' => 0,
                'price_list_items' => 0,
                'cost_rows' => 0,
                'media' => 0,
            ];
        }

        if ($counts === []) {
            return $counts;
        }

        foreach ($this->groupedCounts('cart_lines', 'variation_id', $variationIds) as $variationId => $rowCount) {
            $counts[$variationId]['cart_lines'] = $rowCount;
        }

        // Converted = its cart already became an order (checkout sets
        // carts.order_id and never clears the lines), so this row is a basket
        // that has already served its purpose, not a live one. A subset of
        // cart_lines, not a separate bucket.
        foreach (DB::table('cart_lines')
            ->join('carts', 'carts.id', '=', 'cart_lines.cart_id')
            ->whereIn('cart_lines.variation_id', $variationIds)
            ->whereNotNull('carts.order_id')
            ->groupBy('cart_lines.variation_id')
            ->selectRaw('cart_lines.variation_id as variation_id, COUNT(*) as row_count')
            ->get() as $row) {
            $counts[(string) $row->variation_id]['converted_cart_lines'] = (int) $row->row_count;
        }

        foreach (DB::table('pricing_price_list_items')
            ->where('target_type', PriceListItemTargetType::VARIATION->value)
            ->whereIn('target_id', $variationIds)
            ->groupBy('target_id')
            ->selectRaw('target_id, COUNT(*) as row_count')
            ->get() as $row) {
            $counts[(string) $row->target_id]['price_list_items'] = (int) $row->row_count;
        }

        foreach ($this->groupedCounts('pricing_product_costs', 'priceable_id', $variationIds) as $variationId => $rowCount) {
            $counts[$variationId]['cost_rows'] = $rowCount;
        }

        foreach ($this->groupedCounts('catalog_variation_media', 'variation_id', $variationIds) as $variationId => $rowCount) {
            $counts[$variationId]['media'] = $rowCount;
        }

        return $counts;
    }

    /**
     * `SELECT `$column`, COUNT(*) ... GROUP BY `$column`` for one table, as
     * variation id => count — the plain grouped read three of the five
     * configuration counts share.
     *
     * @param string[] $variationIds
     * @return array<string, int>
     */
    private function groupedCounts(string $table, string $column, array $variationIds): array
    {
        return DB::table($table)
            ->whereIn($column, $variationIds)
            ->groupBy($column)
            ->selectRaw("{$column}, COUNT(*) as row_count")
            ->pluck('row_count', $column)
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }

    /** @return array<int, array{name: string, value: string}> */
    private function attributesFor(string $variationId): array
    {
        return $this->attributesForMany([$variationId])[$variationId] ?? [];
    }

    /**
     * The variations' sold combinations as display pairs, ordered by
     * attribute_definition_id — the same deterministic order
     * SaleLineSnapshotBuilder uses for `sold_attributes` (§3.13 D5's own
     * finding that no authoritative merchant axis order exists yet), so a
     * confirmation modal and a receipt at least agree with each other — for
     * any number of variations, in ONE query rather than one per variation.
     *
     * A variation with no attribute rows (a UNIVERSAL one) is simply absent;
     * every caller reads that as "no combination", which is what it means.
     *
     * @param string[] $variationIds
     * @return array<string, array<int, array{name: string, value: string}>>
     */
    private function attributesForMany(array $variationIds): array
    {
        if ($variationIds === []) {
            return [];
        }

        $rows = DB::table('catalog_variation_attribute_values as av')
            ->join('catalog_attribute_definitions as d', 'd.id', '=', 'av.attribute_definition_id')
            ->join('catalog_attribute_values as v', 'v.id', '=', 'av.attribute_value_id')
            ->whereIn('av.variation_id', $variationIds)
            ->orderBy('av.variation_id')
            ->orderBy('d.id')
            ->get(['av.variation_id', 'd.name as definition_name', 'v.value as value']);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row->variation_id][] = [
                'name' => (string) $row->definition_name,
                'value' => (string) $row->value,
            ];
        }

        return $grouped;
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
