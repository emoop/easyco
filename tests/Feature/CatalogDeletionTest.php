<?php

namespace Tests\Feature;

use App\Services\ActivityLogger;
use App\Services\CatalogDeletion;
use DateTimeImmutable;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLine;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Exceptions\VariationNotDeletableException;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Persistence\Eloquent\ClientModel;
use EasyCo\OperationalSales\Persistence\Eloquent\SaleLineModel;
use EasyCo\OperationalSales\Persistence\Eloquent\TransactionModel;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Persistence\Eloquent\ProductCostModel;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * App\Services\CatalogDeletion — catalog-domain-design.md §3.19.3-§3.19.5
 * and §3.19.10. The service is the ONLY place the history rule and the
 * variation delete live, so this file covers both halves: what it reports
 * (the impact) and what it does (deleteVariation), including the two
 * concurrency properties §3.19.5 states as requirements.
 *
 * Fixture shapes mirror PruneProductsToOriginalTest /
 * EditVariableProductTest's own established constructions (same
 * repositories, same domain objects) rather than inventing new ones.
 */
class CatalogDeletionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The ids the concurrency test commits on its SECOND connection —
     * committed outside this test's own transaction, so nothing else can
     * clean them up. See tearDown().
     */
    private ?string $raceClientId = null;

    private ?string $raceTransactionId = null;

    /**
     * These rows are committed on a connection the test's wrapping
     * transaction does not own, so a rollback cannot reach them: without
     * this, one test's committed sale line leaks into every LATER test in
     * the same run. Found the honest way — a full-suite run, where
     * PruneProductsToOriginalTest's own sale-line counts suddenly saw two
     * rows instead of one — and fixed where it belongs, in the only test
     * that commits anything.
     */
    protected function tearDown(): void
    {
        // parent::tearDown() FIRST: it rolls back this test's wrapping
        // transaction, and only then are any locks that transaction still
        // holds released. Deleting the committed rows while it is still
        // open blocks on those locks — a 50-second lock-wait timeout, and
        // (worse) an exception thrown before parent::tearDown() ran, which
        // leaves the transaction open and hangs the NEXT test too. Both
        // observed, not theorised.
        parent::tearDown();

        if ($this->raceClientId === null) {
            return;
        }

        DB::connection('deletion_race')->table('operational_sales_sale_lines')
            ->where('client_id', $this->raceClientId)
            ->delete();

        DB::connection('deletion_race')->table('operational_sales_transactions')
            ->where('id', $this->raceTransactionId)
            ->delete();

        DB::connection('deletion_race')->table('operational_sales_clients')
            ->where('id', $this->raceClientId)
            ->delete();

        $this->raceClientId = null;
        $this->raceTransactionId = null;
    }

    /**
     * Black and White, on one definition — attribute_definition.code is DB-unique,
     * so a test gets exactly one 'color' definition.
     *
     * @return array{0: AttributeDefinition, 1: AttributeValue, 2: AttributeValue}
     */
    private function persistedColorDefinition(): array
    {
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);

        $white = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'White');
        app(AttributeValueRepository::class)->save($white);

        return [$definition, $black, $white];
    }

    /** @return array{0: Product, 1: string, 2: string} one product, two live STANDARD variations (Black, White) */
    private function variableProductWithTwoVariations(): array
    {
        [$definition, $black, $white] = $this->persistedColorDefinition();

        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white])]);
        $blackVariation = $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-VAR-BLACK');
        $whiteVariation = $product->addStandardVariation([$definition->id() => $white->id()], 'SKU-VAR-WHITE');
        app(ProductRepository::class)->save($product);

        return [$product, (string) $blackVariation->id(), (string) $whiteVariation->id()];
    }

    /** @return array{0: Product, 1: string} the persisted VARIABLE product and its one live STANDARD variation's id */
    private function variableProductWithOneVariation(string $sku = 'SKU-VAR-BLACK'): array
    {
        [$definition, $black] = $this->persistedColorDefinition();

        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black])]);
        $variation = $product->addStandardVariation([$definition->id() => $black->id()], $sku);
        app(ProductRepository::class)->save($product);

        return [$product, (string) $variation->id()];
    }

    private function addStockLevel(string $variationId, int $quantity): void
    {
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $quantity));
    }

    private function addCartLine(string $variationId): void
    {
        $cart = Cart::forGuest('token-'.uniqid(), new DateTimeImmutable('+10 days'));
        $cart->addLine(new CartLine(null, '', $variationId, 2));
        app(CartRepository::class)->save($cart);
    }

    private function addSaleLine(string $variationId): void
    {
        $clientId = (string) ClientModel::create(['name' => 'Test Client'])->id;

        $transaction = new Transaction(id: null, channel: Channel::POS);
        $transaction->addSaleLine(SaleLine::create(
            transactionId: '',
            clientId: $clientId,
            priceableId: $variationId,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: Money::fromMinorUnits(2500, 'EUR'),
            profit: Money::fromMinorUnits(400, 'EUR'),
            recordedAt: new DateTimeImmutable('2026-08-25 10:00:00'),
            effectiveAt: new DateTimeImmutable('2026-08-20 09:00:00'),
            productName: 'Variable Shirt',
            sku: 'SKU-VAR-BLACK',
            regularUnitPrice: Money::fromMinorUnits(2500, 'EUR'),
            finalUnitPrice: Money::fromMinorUnits(2500, 'EUR'),
            promotionDiscountShare: Money::zero('EUR'),
            discretionaryDiscount: Money::zero('EUR'),
            netPaidAmount: Money::fromMinorUnits(2500, 'EUR'),
            soldAttributes: [],
        ));

        app(TransactionRepository::class)->save($transaction);
    }

    private function addPriceListItem(string $variationId): void
    {
        $list = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        app(PriceListRepository::class)->save($list);

        app(PriceListItemRepository::class)->save(new PriceListItem(
            id: null,
            priceListId: $list->id(),
            targetType: PriceListItemTargetType::VARIATION,
            targetId: $variationId,
            price: Price::exclusiveOfTax(Money::fromMinorUnits(1999, 'EUR'), 2000),
        ));
    }

    private function addCostRow(string $variationId): void
    {
        ProductCostModel::create([
            'priceable_id' => $variationId,
            'cost_amount_minor' => 1200,
            'cost_currency' => 'EUR',
        ]);
    }

    private function addVariationMedia(string $variationId): void
    {
        // catalog_media is the table MediaAssetModel actually maps
        // (2026_08_29_000001 dropped its old `url` in favour of disk+path,
        // and added the NOT NULL processing_status).
        $mediaId = DB::table('catalog_media')->insertGetId([
            'type' => 'image',
            'disk' => 'public',
            'path' => 'products/shirt.jpg',
            'alt_text' => 'A shirt',
            'processing_status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('catalog_variation_media')->insert([
            'variation_id' => $variationId,
            'media_id' => $mediaId,
            'sort_order' => 0,
        ]);
    }

    /** @return int[] every row of §3.19.4's own scope, plus the variation row itself */
    private function rowCountsFor(string $variationId): array
    {
        return [
            DB::table('stock_levels')->where('variation_id', $variationId)->count(),
            DB::table('cart_lines')->where('variation_id', $variationId)->count(),
            DB::table('pricing_price_list_items')
                ->where('target_type', PriceListItemTargetType::VARIATION->value)
                ->where('target_id', $variationId)
                ->count(),
            DB::table('pricing_product_costs')->where('priceable_id', $variationId)->count(),
            DB::table('catalog_variation_attribute_values')->where('variation_id', $variationId)->count(),
            DB::table('catalog_variation_media')->where('variation_id', $variationId)->count(),
            VariationModel::withTrashed()->whereKey($variationId)->count(),
        ];
    }

    public function test_the_impact_reports_every_count_the_delete_would_remove(): void
    {
        [$product, $variationId] = $this->variableProductWithOneVariation();
        $this->addCartLine($variationId);
        $this->addPriceListItem($variationId);
        $this->addCostRow($variationId);
        $this->addVariationMedia($variationId);

        $impact = app(CatalogDeletion::class)->impactForVariation($variationId);

        $this->assertSame($variationId, $impact->variationId);
        $this->assertSame('SKU-VAR-BLACK', $impact->sku);
        $this->assertSame('Variable Shirt', $impact->productName);
        $this->assertSame('draft', $impact->status);
        $this->assertSame([['name' => 'Color', 'value' => 'Black']], $impact->attributes);
        $this->assertSame(1, $impact->cartLineCount);
        $this->assertSame(0, $impact->convertedCartLineCount);
        $this->assertSame(1, $impact->priceListItemCount);
        $this->assertSame(1, $impact->costRowCount);
        $this->assertSame(1, $impact->mediaCount);
        $this->assertSame(0, $impact->stockQuantity);
        $this->assertSame(0, $impact->saleLineCount);

        // G-D2's two checks both pass -> deletable, and no refusal reason is
        // invented to go with it.
        $this->assertTrue($impact->isDeletable());
        $this->assertNull($impact->refusal);
    }

    public function test_the_impact_refuses_a_variation_with_a_sale_line(): void
    {
        [, $variationId] = $this->variableProductWithOneVariation();
        $this->addSaleLine($variationId);

        $impact = app(CatalogDeletion::class)->impactForVariation($variationId);

        $this->assertFalse($impact->isDeletable());
        $this->assertSame(1, $impact->saleLineCount);
        $this->assertStringContainsString('1 sale line(s)', $impact->refusal->getMessage());
        $this->assertStringContainsString('Archive it instead', $impact->refusal->getMessage());
    }

    /**
     * A SOFT-DELETED sale line is still history — the read is deliberately
     * raw (no SoftDeletes scope), the exact opposite of OrderAdminReader's
     * explicit whereNull('deleted_at'). Getting this backwards would
     * re-open the one hole the whole design exists to close.
     */
    public function test_the_impact_refuses_a_variation_whose_only_sale_line_is_soft_deleted(): void
    {
        [, $variationId] = $this->variableProductWithOneVariation();
        $this->addSaleLine($variationId);

        SaleLineModel::query()->where('priceable_id', $variationId)->delete();

        $this->assertSame(1, SaleLineModel::onlyTrashed()->where('priceable_id', $variationId)->count());

        $impact = app(CatalogDeletion::class)->impactForVariation($variationId);

        $this->assertFalse($impact->isDeletable());
        $this->assertSame(1, $impact->saleLineCount);
    }

    public function test_the_impact_refuses_a_variation_with_non_zero_stock(): void
    {
        [, $variationId] = $this->variableProductWithOneVariation();
        $this->addStockLevel($variationId, 3);

        $impact = app(CatalogDeletion::class)->impactForVariation($variationId);

        $this->assertFalse($impact->isDeletable());
        $this->assertSame(3, $impact->stockQuantity);
        $this->assertStringContainsString('still has 3 in stock', $impact->refusal->getMessage());
    }

    public function test_history_is_reported_before_stock_when_both_would_refuse(): void
    {
        [, $variationId] = $this->variableProductWithOneVariation();
        $this->addSaleLine($variationId);
        $this->addStockLevel($variationId, 5);

        $impact = app(CatalogDeletion::class)->impactForVariation($variationId);

        // §3.19.8 D's own order: history is the irreversible one, so it is
        // the reason shown, not the stock.
        $this->assertStringContainsString('sale line(s)', $impact->refusal->getMessage());
    }

    public function test_variation_ids_with_history_returns_only_the_ids_that_have_some(): void
    {
        [, $withHistory, $withoutHistory] = $this->variableProductWithTwoVariations();
        $this->addSaleLine($withHistory);

        $result = app(CatalogDeletion::class)->variationIdsWithHistory([$withHistory, $withoutHistory]);

        $this->assertSame([$withHistory => 1], $result);
        $this->assertArrayNotHasKey($withoutHistory, $result);
    }

    public function test_variation_ids_with_history_is_empty_for_an_empty_input(): void
    {
        $this->assertSame([], app(CatalogDeletion::class)->variationIdsWithHistory([]));
    }

    public function test_delete_variation_refuses_a_variation_with_history_and_deletes_nothing(): void
    {
        [, $variationId] = $this->variableProductWithOneVariation();
        $this->addSaleLine($variationId);

        try {
            app(CatalogDeletion::class)->deleteVariation($variationId);
            $this->fail('A variation with a sale line must never be deleted.');
        } catch (VariationNotDeletableException $e) {
            $this->assertStringContainsString('1 sale line(s)', $e->getMessage());
        }

        $this->assertSame([0, 0, 0, 0, 1, 0, 1], $this->rowCountsFor($variationId));
    }

    public function test_delete_variation_refuses_a_variation_with_non_zero_stock_and_deletes_nothing(): void
    {
        [, $variationId] = $this->variableProductWithOneVariation();
        $this->addStockLevel($variationId, 2);

        try {
            app(CatalogDeletion::class)->deleteVariation($variationId);
            $this->fail('A variation with stock on hand must never be deleted.');
        } catch (VariationNotDeletableException $e) {
            $this->assertStringContainsString('still has 2 in stock', $e->getMessage());
        }

        // The stocked row itself must still be there, untouched.
        $this->assertSame([1, 0, 0, 0, 1, 0, 1], $this->rowCountsFor($variationId));
    }

    /**
     * §3.19.4's whole table, verified row by row rather than by trusting
     * the transaction: stock, cart lines, variation-target price items,
     * cost rows, the pivot the DB cascades, the media pivot the DB
     * cascades, and the variation row itself — which must be gone even
     * from withTrashed() (a soft delete would leave it occupying its SKU
     * and its (product_id, attribute_signature) slot forever).
     */
    public function test_delete_variation_removes_every_row_of_its_own_scope(): void
    {
        [, $variationId] = $this->variableProductWithOneVariation();
        $this->addCartLine($variationId);
        $this->addPriceListItem($variationId);
        $this->addCostRow($variationId);
        $this->addVariationMedia($variationId);

        // Pre-conditions: every one of them exists to begin with, so
        // "zero afterwards" cannot pass vacuously.
        $this->assertSame([0, 1, 1, 1, 1, 1, 1], $this->rowCountsFor($variationId));

        app(CatalogDeletion::class)->deleteVariation($variationId);

        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $this->rowCountsFor($variationId));
    }

    /**
     * §3.19.11 — the freed identifiers must be genuinely reusable, which
     * means a NEW variation with a NEW id, not a revival of the deleted
     * row and not a unique-constraint failure. This is the assertion that
     * would catch a soft delete, a half-deleted pivot, or an
     * (product_id, attribute_signature) row left behind.
     */
    public function test_a_deleted_variations_own_combination_sku_and_barcode_can_be_reused_by_a_brand_new_variation(): void
    {
        [$product, $variationId] = $this->variableProductWithOneVariation();

        // The barcode must genuinely be persisted BEFORE the delete, or the
        // reuse assertion below would be proving nothing.
        $aggregate = app(ProductRepository::class)->findByIdWithVariations((string) $product->id());

        foreach ($aggregate->variations() as $candidate) {
            if ((string) $candidate->id() === $variationId) {
                $candidate->setBarcode('1112223334445');
            }
        }

        app(ProductRepository::class)->save($aggregate);

        $this->assertSame('1112223334445', VariationModel::find($variationId)->barcode);

        app(CatalogDeletion::class)->deleteVariation($variationId);

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $product->id());
        $definitionId = (string) DB::table('catalog_attribute_definitions')->where('code', 'color')->value('id');
        $blackId = (string) DB::table('catalog_attribute_values')->where('value', 'Black')->value('id');

        $replacement = $reloaded->addStandardVariation([$definitionId => $blackId], 'SKU-VAR-BLACK');
        $replacement->setBarcode('1112223334445');

        // No DuplicateVariationCombinationException, no unique-constraint
        // violation on sku/barcode — the point of the whole exercise.
        app(ProductRepository::class)->save($reloaded);

        $this->assertNotNull($replacement->id());
        $this->assertNotSame($variationId, (string) $replacement->id());
        $this->assertNotNull(VariationModel::withTrashed()->find($replacement->id()));
    }

    /**
     * §3.19.5 rule 2 — asserted at the level it is testable, exactly as the
     * design's own stage list says: the emitted SQL locks BOTH reads. A
     * plain read here would be a snapshot read under MySQL's default
     * REPEATABLE READ, which is the failure mode the locking read exists to
     * prevent (see the race test below for the behavioural half).
     */
    public function test_the_stock_and_history_reads_both_lock_their_rows(): void
    {
        [, $variationId] = $this->variableProductWithOneVariation();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(CatalogDeletion::class)->deleteVariation($variationId);

        $stockRead = Arr::first(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'stock_levels') && str_contains(strtolower($sql), 'for update')
        );
        $historyRead = Arr::first(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'operational_sales_sale_lines') && str_contains(strtolower($sql), 'for update')
        );

        $this->assertNotNull($stockRead, 'The stock check must be a FOR UPDATE read, not a snapshot read.');
        $this->assertNotNull($historyRead, 'The history check must be a FOR UPDATE read, not a snapshot read.');
        $this->assertStringContainsString('count(*)', strtolower($historyRead));
    }

    /**
     * §3.19.5's behavioural claim, staged with a SECOND connection: a
     * checkout commits a sale line after the deleting transaction has
     * already read the variation, and the in-transaction re-check must
     * still refuse.
     *
     * WHY THIS IS NOT A TAUTOLOGY: MySQL's default REPEATABLE READ fixes a
     * consistent snapshot at the transaction's first NON-locking read —
     * here, the variation lookup. A plain COUNT() afterwards would read that
     * snapshot, see zero sale lines, and delete a variation with history.
     * The re-check uses FOR UPDATE, a locking (current) read, so it sees the
     * row committed a moment ago.
     *
     * The insert is made on a separate connection, committed, at the exact
     * moment the deleting transaction takes its stock lock — real
     * concurrency, not a simulated ordering.
     */
    public function test_a_sale_line_committed_after_the_transaction_snapshot_is_still_seen_by_the_locking_history_read(): void
    {
        config()->set(
            'database.connections.deletion_race',
            config('database.connections.'.config('database.default'))
        );
        DB::purge('deletion_race');

        [, $variationId] = $this->variableProductWithOneVariation();

        $deletion = app(CatalogDeletion::class);

        // The merchant's own view of the world: deletable.
        $this->assertTrue($deletion->impactForVariation($variationId)->isDeletable());

        $staged = false;
        DB::listen(function ($query) use (&$staged, $variationId): void {
            if ($staged
                || ! str_contains($query->sql, 'stock_levels')
                || ! str_contains(strtolower($query->sql), 'for update')) {
                return;
            }

            $staged = true;
            $this->commitSaleLineOnSecondConnection($variationId);
        });

        try {
            $deletion->deleteVariation($variationId);
            $this->fail('The in-transaction re-check must refuse a sale line committed after the view was read.');
        } catch (VariationNotDeletableException $e) {
            $this->assertStringContainsString('1 sale line(s)', $e->getMessage());
        }

        $this->assertTrue($staged, 'The race must actually have been staged — the hook never fired.');
        $this->assertNotNull(VariationModel::withTrashed()->find($variationId));

        // Counted on the SECOND connection on purpose: the first one is
        // still inside the test's own transaction, whose snapshot predates
        // the commit — exactly the blindness the locking read had to see
        // past.
        $this->assertSame(
            1,
            DB::connection('deletion_race')->table('operational_sales_sale_lines')
                ->where('priceable_id', $variationId)
                ->count()
        );
    }

    /**
     * A SECOND database connection — outside the test's own wrapping
     * transaction, so its write is genuinely committed and genuinely
     * invisible to the already-established snapshot on the first one.
     */
    private function commitSaleLineOnSecondConnection(string $variationId): void
    {
        $clientId = (string) ClientModel::on('deletion_race')->create(['name' => 'Race Client'])->id;
        $transactionId = (string) TransactionModel::on('deletion_race')->create(['channel' => Channel::POS->value])->id;

        $this->raceClientId = $clientId;
        $this->raceTransactionId = $transactionId;

        SaleLineModel::on('deletion_race')->create([
            'transaction_id' => $transactionId,
            'client_id' => $clientId,
            'priceable_id' => $variationId,
            'type' => 'sale',
            'status' => 'completed',
            'quantity' => 1,
            'amount_minor' => 2500,
            'amount_currency' => 'EUR',
            'profit_minor' => 400,
            'profit_currency' => 'EUR',
            'recorded_at' => '2026-08-25 10:00:00',
            'effective_at' => '2026-08-20 09:00:00',
        ]);
    }

    /**
     * §3.19.10's first decision: the snapshot is written even though the
     * activity log is OFF by default, and it carries the facts a human would
     * need to reconstruct what was destroyed. `new_value` stays null — a
     * deletion has no new state.
     */
    public function test_delete_variation_writes_a_snapshot_even_while_the_activity_log_is_disabled(): void
    {
        // Deliberately NOT enabled — the default installation.
        $this->assertNotSame(
            '1',
            app(\App\Settings\Contracts\SiteSettingsRepository::class)->get('admin.activity_log_enabled')
        );

        [$product, $variationId] = $this->variableProductWithOneVariation();
        $this->addCartLine($variationId);
        $this->addPriceListItem($variationId);
        $this->addCostRow($variationId);
        $this->addVariationMedia($variationId);

        app(CatalogDeletion::class)->deleteVariation($variationId);

        $row = DB::table('activity_log')->where('action', ActivityLogger::ACTION_DELETED)->sole();

        $this->assertSame('variation', $row->entity_type);
        $this->assertSame($variationId, $row->entity_id);
        $this->assertNull($row->field);
        $this->assertNull($row->new_value);
        $this->assertNotNull($row->occurred_at);

        $snapshot = json_decode((string) $row->old_value, true);

        $this->assertSame($product->id(), $snapshot['product_id']);
        $this->assertSame('Variable Shirt', $snapshot['product_name']);
        $this->assertSame('SKU-VAR', $snapshot['product_base_sku']);
        $this->assertSame('variable-shirt', $snapshot['product_slug']);
        $this->assertSame($variationId, $snapshot['variation_id']);
        $this->assertSame('SKU-VAR-BLACK', $snapshot['variation_sku']);
        $this->assertSame('draft', $snapshot['variation_status']);
        $this->assertSame('standard', $snapshot['variation_type']);
        $this->assertSame([['name' => 'Color', 'value' => 'Black']], $snapshot['attributes']);
        $this->assertSame(0, $snapshot['sale_line_count']);
        $this->assertSame(0, $snapshot['stock_quantity']);
        $this->assertSame(0, $snapshot['remaining_variation_count']);
        $this->assertSame(1, $snapshot['cart_line_count']);
        $this->assertSame(0, $snapshot['converted_cart_line_count']);
        $this->assertSame(1, $snapshot['price_list_item_count']);
        $this->assertSame(1, $snapshot['cost_row_count']);
        $this->assertSame(1, $snapshot['media_count']);
    }

    /**
     * G-D2: a UNIVERSAL variation is never deleted on its own — the refusal
     * comes from the aggregate itself (Product::removeStandardVariation()),
     * because it is a structural rule rather than a cross-domain check.
     * Nothing is touched, including its product.
     */
    public function test_delete_variation_refuses_a_universal_variation(): void
    {
        $product = Product::createSimple('Simple Hat', 'SKU-HAT', 'simple-hat');
        app(ProductRepository::class)->save($product);

        $universalId = (string) $product->universalVariation()->id();

        try {
            app(CatalogDeletion::class)->deleteVariation($universalId);
            $this->fail('A UNIVERSAL variation must never be deleted on its own.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('UNIVERSAL variation', $e->getMessage());
        }

        $this->assertSame([0, 0, 0, 0, 0, 0, 1], $this->rowCountsFor($universalId));
        $this->assertNotNull(DB::table('catalog_products')->where('id', $product->id())->first());
    }

    /** A stale id (already deleted, or never existed) is reported, never half-acted-on. */
    public function test_delete_variation_reports_an_unknown_variation(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(CatalogDeletion::class)->deleteVariation('999999');
    }

    /**
     * §3.19.5 rule 3 — the one schema change the design requires. Without
     * this index the locking history read scans (and locks) the whole
     * sale-line table, which is a production incident rather than a slow
     * query; the other assertions in this file would all still pass, so it
     * is asserted directly.
     */
    public function test_the_priceable_id_index_the_locking_history_read_depends_on_exists(): void
    {
        $indexNames = collect(DB::select('SHOW INDEX FROM operational_sales_sale_lines'))
            ->pluck('Key_name')
            ->unique()
            ->all();

        $this->assertContains('os_sale_lines_priceable_id_index', $indexNames);
    }
}
