<?php

namespace App\Console\Commands;

use EasyCo\Cart\Persistence\Eloquent\CartLineModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Inventory\Persistence\Eloquent\StockLevelModel;
use EasyCo\OperationalSales\Persistence\Eloquent\SaleLineModel;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Persistence\Eloquent\PriceListItemModel;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan products:prune-to-original` — deletes every product
 * except the 6 lowest-id ("original", pre-bulk-seed) products, and all
 * of their real dependent data, across every table that actually
 * references a product or variation.
 *
 * Dry-run by default (no flags): reports counts only, deletes nothing.
 * --execute: shows the same counts, then asks for confirmation before
 * deleting. --execute --force: skips the confirmation.
 *
 * HARD SAFETY GATE: operational_sales_sale_lines.priceable_id and
 * cart_lines.variation_id are checked for any reference to a doomed
 * variation BEFORE anything else happens. priceable_id is deliberately
 * not a foreign key (Operational Sales must never depend on Catalog
 * directly — operational-sales-domain-design.md §1), so a doomed
 * variation with real sale lines would NOT be blocked by the database
 * itself — it would silently orphan a real historical business record.
 * If anything is found, the whole command aborts — regardless of
 * --execute/--force — and nothing is deleted. This is a decision for a
 * human, not this command.
 *
 * catalog_variations.product_id and stock_levels.variation_id and
 * cart_lines.variation_id are all restrictOnDelete() — a real DB error
 * would also catch a missed case, but the sale-line gate above is the
 * one relationship the database itself cannot enforce, so it gets an
 * explicit application-layer check.
 *
 * catalog_variations also has softDeletes() — a plain delete() only
 * sets deleted_at and still blocks the parent product's FK, so doomed
 * variations (including already soft-deleted ones) are resolved via
 * ->withTrashed() and removed via ->forceDelete().
 *
 * The following tables are NOT deleted by hand — they cascade
 * automatically via a real DB-level ON DELETE CASCADE once their
 * parent catalog_products/catalog_variations row is force-deleted
 * (confirmed against each of their own migrations):
 * catalog_product_categories, catalog_product_tags,
 * catalog_product_media, catalog_product_attributes,
 * catalog_product_axis_values, catalog_variation_media,
 * catalog_variation_attribute_values. Their row counts are still
 * reported (for visibility) and re-checked at zero after execution
 * (to prove the cascade actually worked, not just assumed).
 *
 * catalog_media rows themselves (the underlying media records, not the
 * pivots) are NOT deleted here, and neither is the underlying physical
 * file/storage — a separate, already-tracked gap, see
 * media-cleanup-and-storage-optimization-note.md. Out of scope here.
 */
class PruneProductsToOriginal extends Command
{
    protected $signature = 'products:prune-to-original {--execute} {--force}';

    protected $description = 'Delete every product except the 6 oldest, and all their dependent data (dry-run unless --execute is given)';

    private const KEEP_COUNT = 6;

    public function handle(): int
    {
        $this->printTargetDatabase();

        $keepProducts = ProductModel::orderBy('id')->take(self::KEEP_COUNT)->get();

        $this->info('Keep-set (the '.self::KEEP_COUNT.' oldest products, by id):');
        $this->table(
            ['id', 'name', 'slug', 'base_sku', 'created_at'],
            $keepProducts->map(fn (ProductModel $product): array => [
                $product->id,
                $product->name,
                $product->slug,
                $product->base_sku,
                (string) $product->created_at,
            ])->all()
        );

        $keepProductIds = $keepProducts->pluck('id')->all();

        // withTrashed(): a soft-deleted product outside the keep-set is
        // still a real row that must be force-deleted too, or "products
        // remaining must be exactly 6" would never truly hold.
        $doomedProductIds = ProductModel::withTrashed()
            ->whereNotIn('id', $keepProductIds)
            ->pluck('id')
            ->all();

        // withTrashed(): a soft-deleted variation still physically
        // exists and still needs real cleanup (see class docblock).
        $doomedVariationIds = VariationModel::withTrashed()
            ->whereIn('product_id', $doomedProductIds)
            ->pluck('id')
            ->all();

        $this->newLine();
        $this->line('Doomed products: '.count($doomedProductIds));
        $this->line('Doomed variations (including soft-deleted): '.count($doomedVariationIds));

        if ($doomedProductIds === []) {
            $this->info('Nothing to prune.');

            return self::SUCCESS;
        }

        // pricing_price_list_items.target_id and
        // operational_sales_sale_lines.priceable_id both hold a
        // Variation/Product id AS A STRING (never a foreign key, by
        // design) — same reasoning just applied to priceMinorSubquery().
        $doomedVariationIdStrings = array_map('strval', $doomedVariationIds);
        $doomedProductIdStrings = array_map('strval', $doomedProductIds);

        if ($this->safetyGateTripped($doomedVariationIdStrings, $doomedVariationIds)) {
            return self::FAILURE;
        }

        $counts = $this->countWhatWouldBeDeleted($doomedProductIds, $doomedVariationIds, $doomedVariationIdStrings, $doomedProductIdStrings);

        $this->newLine();
        $this->info('Rows that would be deleted:');
        $this->table(['table', 'rows'], $counts);

        if (! $this->option('execute')) {
            $this->comment('Dry run only — pass --execute to actually delete. Nothing was deleted.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Permanently delete the rows above? This cannot be undone.')) {
            $this->comment('Aborted. Nothing was deleted.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($doomedProductIds, $doomedVariationIds, $doomedVariationIdStrings, $doomedProductIdStrings): void {
            StockLevelModel::whereIn('variation_id', $doomedVariationIds)->delete();

            $this->priceListItemsQuery($doomedVariationIdStrings, $doomedProductIdStrings)->delete();

            VariationModel::withTrashed()->whereIn('id', $doomedVariationIds)->forceDelete();

            // Relies on real DB cascade for the pivot/media/attribute
            // children — see class docblock.
            ProductModel::withTrashed()->whereIn('id', $doomedProductIds)->forceDelete();
        });

        $this->reportFinalState($keepProductIds, $doomedProductIds, $doomedVariationIds);

        return self::SUCCESS;
    }

    private function printTargetDatabase(): void
    {
        $connectionName = config('database.default');
        $databaseName = config("database.connections.{$connectionName}.database");

        $this->warn("=== Target connection: [{$connectionName}] database: [{$databaseName}] ===");
    }

    /**
     * @param  string[]  $doomedVariationIdStrings
     * @param  int[]  $doomedVariationIds
     */
    private function safetyGateTripped(array $doomedVariationIdStrings, array $doomedVariationIds): bool
    {
        // withTrashed(): a soft-deleted sale line is still a real,
        // physically-existing historical record (SaleLine is never hard-
        // deleted — operational-sales-domain-design.md §3.2) that would
        // still be orphaned by force-deleting the variation it references.
        $blockedSaleLines = SaleLineModel::withTrashed()
            ->whereIn('priceable_id', $doomedVariationIdStrings)
            ->get(['id', 'transaction_id', 'client_id', 'priceable_id']);

        // cart_lines.variation_id IS a real foreign key (restrictOnDelete)
        // — a plain forceDelete() would already fail loudly on this.
        // Checked explicitly anyway, per this task's own instruction, so
        // the report is clear rather than a raw SQL constraint error.
        $blockedCartLines = CartLineModel::whereIn('variation_id', $doomedVariationIds)
            ->get(['id', 'cart_id', 'variation_id']);

        if ($blockedSaleLines->isEmpty() && $blockedCartLines->isEmpty()) {
            return false;
        }

        $this->error('SAFETY GATE TRIPPED — real business records reference a variation outside the keep-set. Aborting. Nothing was deleted.');

        if ($blockedSaleLines->isNotEmpty()) {
            $this->error("{$blockedSaleLines->count()} operational_sales_sale_lines row(s) reference a doomed variation:");
            $this->table(
                ['id', 'transaction_id', 'client_id', 'priceable_id'],
                $blockedSaleLines->map(fn (SaleLineModel $line): array => [
                    $line->id, $line->transaction_id, $line->client_id, $line->priceable_id,
                ])->all()
            );
        }

        if ($blockedCartLines->isNotEmpty()) {
            $this->error("{$blockedCartLines->count()} cart_lines row(s) reference a doomed variation:");
            $this->table(
                ['id', 'cart_id', 'variation_id'],
                $blockedCartLines->map(fn (CartLineModel $line): array => [
                    $line->id, $line->cart_id, $line->variation_id,
                ])->all()
            );
        }

        return true;
    }

    /** @param  string[]  $doomedVariationIdStrings @param  string[]  $doomedProductIdStrings */
    private function priceListItemsQuery(array $doomedVariationIdStrings, array $doomedProductIdStrings): \Illuminate\Database\Eloquent\Builder
    {
        return PriceListItemModel::where(function ($query) use ($doomedVariationIdStrings): void {
            $query->where('target_type', PriceListItemTargetType::VARIATION->value)
                ->whereIn('target_id', $doomedVariationIdStrings);
        })->orWhere(function ($query) use ($doomedProductIdStrings): void {
            $query->where('target_type', PriceListItemTargetType::PRODUCT->value)
                ->whereIn('target_id', $doomedProductIdStrings);
        });
    }

    /**
     * @param  int[]  $doomedProductIds
     * @param  int[]  $doomedVariationIds
     * @param  string[]  $doomedVariationIdStrings
     * @param  string[]  $doomedProductIdStrings
     * @return array<int, array{0: string, 1: int}>
     */
    private function countWhatWouldBeDeleted(
        array $doomedProductIds,
        array $doomedVariationIds,
        array $doomedVariationIdStrings,
        array $doomedProductIdStrings,
    ): array {
        return [
            ['catalog_products', count($doomedProductIds)],
            ['catalog_variations (incl. soft-deleted)', count($doomedVariationIds)],
            ['stock_levels', StockLevelModel::whereIn('variation_id', $doomedVariationIds)->count()],
            ['pricing_price_list_items', $this->priceListItemsQuery($doomedVariationIdStrings, $doomedProductIdStrings)->count()],
            ['catalog_product_categories (cascades automatically)', DB::table('catalog_product_categories')->whereIn('product_id', $doomedProductIds)->count()],
            ['catalog_product_tags (cascades automatically)', DB::table('catalog_product_tags')->whereIn('product_id', $doomedProductIds)->count()],
            ['catalog_product_media (cascades automatically)', DB::table('catalog_product_media')->whereIn('product_id', $doomedProductIds)->count()],
            ['catalog_product_attributes (cascades automatically)', DB::table('catalog_product_attributes')->whereIn('product_id', $doomedProductIds)->count()],
            ['catalog_product_axis_values (cascades automatically)', DB::table('catalog_product_axis_values')->whereIn('product_id', $doomedProductIds)->count()],
            ['catalog_variation_media (cascades automatically)', DB::table('catalog_variation_media')->whereIn('variation_id', $doomedVariationIds)->count()],
            ['catalog_variation_attribute_values (cascades automatically)', DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $doomedVariationIds)->count()],
        ];
    }

    /**
     * @param  int[]  $keepProductIds
     * @param  int[]  $doomedProductIds
     * @param  int[]  $doomedVariationIds
     */
    private function reportFinalState(array $keepProductIds, array $doomedProductIds, array $doomedVariationIds): void
    {
        $this->newLine();
        $this->info('Verifying final state:');

        $remainingProductIds = ProductModel::withTrashed()->orderBy('id')->pluck('id')->all();
        $remainingMatchesKeepSet = $remainingProductIds === $keepProductIds;

        $this->line(
            'catalog_products remaining: '.count($remainingProductIds)
            .($remainingMatchesKeepSet ? ' (matches the keep-set exactly)' : ' — MISMATCH, expected exactly the keep-set ids!')
        );

        $rows = [
            ['catalog_products (doomed ids, incl. soft-deleted)', ProductModel::withTrashed()->whereIn('id', $doomedProductIds)->count()],
            ['catalog_variations (doomed ids, incl. soft-deleted)', VariationModel::withTrashed()->whereIn('id', $doomedVariationIds)->count()],
            ['stock_levels (doomed variation ids)', StockLevelModel::whereIn('variation_id', $doomedVariationIds)->count()],
            ['pricing_price_list_items (doomed ids)', $this->priceListItemsQuery(array_map('strval', $doomedVariationIds), array_map('strval', $doomedProductIds))->count()],
            ['catalog_product_categories (doomed product ids)', DB::table('catalog_product_categories')->whereIn('product_id', $doomedProductIds)->count()],
            ['catalog_product_tags (doomed product ids)', DB::table('catalog_product_tags')->whereIn('product_id', $doomedProductIds)->count()],
            ['catalog_product_media (doomed product ids)', DB::table('catalog_product_media')->whereIn('product_id', $doomedProductIds)->count()],
            ['catalog_product_attributes (doomed product ids)', DB::table('catalog_product_attributes')->whereIn('product_id', $doomedProductIds)->count()],
            ['catalog_product_axis_values (doomed product ids)', DB::table('catalog_product_axis_values')->whereIn('product_id', $doomedProductIds)->count()],
            ['catalog_variation_media (doomed variation ids)', DB::table('catalog_variation_media')->whereIn('variation_id', $doomedVariationIds)->count()],
            ['catalog_variation_attribute_values (doomed variation ids)', DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $doomedVariationIds)->count()],
        ];

        $this->table(['table (remaining rows for deleted ids — must be 0)', 'rows'], $rows);

        $allZero = Collection::make($rows)->every(fn (array $row): bool => $row[1] === 0);

        if ($remainingMatchesKeepSet && $allZero) {
            $this->info('Prune complete: exactly the '.self::KEEP_COUNT.' original products remain, zero dependent rows left behind.');
        } else {
            $this->error('Prune finished but verification found a discrepancy — see above. Investigate before trusting this database state.');
        }
    }
}
