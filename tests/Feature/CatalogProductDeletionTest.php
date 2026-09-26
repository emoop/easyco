<?php

namespace Tests\Feature;

use App\Services\ActivityLogger;
use App\Services\CatalogDeletion;
use App\Services\ProductDeletionRefusalMessage;
use App\Settings\Contracts\SiteSettingsRepository;
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
use EasyCo\Catalog\Enums\ProductDeletionRefusal;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Enums\VariationDeletionRefusal;
use EasyCo\Catalog\Exceptions\ProductNotDeletableException;
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
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Persistence\Eloquent\ProductCostModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CatalogDeletion's PRODUCT half — catalog-domain-design.md §3.19.3's
 * `impactForProduct()`/`deleteProduct()`, §3.19.4's product deletion steps,
 * §3.19.5's locking rules, §3.19.10's snapshot and §3.19.11's freed
 * identifiers. The variation half lives in CatalogDeletionTest; the admin
 * surface in ViewProductDeleteProductTest.
 *
 * The central assertion of the success cases is "zero rows in every table of
 * §3.19.4 for that product" — and it is deliberately computed from the
 * variation ids captured BEFORE the delete. Re-reading them afterwards would
 * make every variation-scoped count trivially zero, which is exactly the
 * vacuous pass this file must not allow.
 *
 * Fixture shapes mirror CatalogDeletionTest's own established constructions
 * (same repositories, same domain objects) rather than inventing new ones.
 */
class CatalogProductDeletionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The ids the concurrency tests commit on their SECOND connection —
     * committed outside this test's own transaction, so nothing else can
     * clean them up. See tearDown().
     */
    private ?string $raceClientId = null;

    private ?string $raceTransactionId = null;

    /** The product row the un-archive race commits on that same second connection. */
    private ?string $raceProductId = null;

    /**
     * These rows are committed on a connection the test's wrapping transaction
     * does not own, so a rollback cannot reach them: without this, one test's
     * committed rows leak into every LATER test in the same run (found the
     * honest way in CatalogDeletionTest — see its own tearDown()).
     */
    protected function tearDown(): void
    {
        // parent::tearDown() FIRST — see CatalogDeletionTest::tearDown()'s own
        // comment: it rolls back this test's wrapping transaction, and only
        // then are the locks that transaction holds released. Deleting the
        // committed rows while it is still open blocks on those locks.
        parent::tearDown();

        if ($this->raceProductId !== null) {
            DB::connection('deletion_race')->table('catalog_products')
                ->where('id', $this->raceProductId)
                ->delete();

            $this->raceProductId = null;
        }

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
     * Black, White and Red on ONE definition — attribute_definition.code is
     * DB-unique, so a test gets exactly one 'color' definition.
     *
     * @return array{0: AttributeDefinition, 1: AttributeValue, 2: AttributeValue, 3: AttributeValue}
     */
    private function persistedColorDefinition(): array
    {
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);

        $white = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'White');
        app(AttributeValueRepository::class)->save($white);

        $red = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Red');
        app(AttributeValueRepository::class)->save($red);

        return [$definition, $black, $white, $red];
    }

    /** @return array{0: Product, 1: string} the persisted SIMPLE product and its UNIVERSAL variation's id */
    private function simpleProduct(string $baseSku = 'SKU-HAT', string $slug = 'simple-hat'): array
    {
        $product = Product::createSimple('Simple Hat', $baseSku, $slug);
        app(ProductRepository::class)->save($product);

        return [$product, (string) $product->universalVariation()->id()];
    }

    /**
     * One VARIABLE product with THREE variation rows, covering every shape
     * §3.19.4 step 1 must take: a live STANDARD one, an ARCHIVED one (a real
     * row), and a SOFT-DELETED one.
     *
     * @return array{0: Product, 1: array{live: string, archived: string, soft_deleted: string}}
     */
    private function variableProductWithThreeVariationShapes(): array
    {
        [$definition, $black, $white, $red] = $this->persistedColorDefinition();

        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white, $red])]);

        $live = $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-VAR-BLACK');
        $archived = $product->addStandardVariation([$definition->id() => $white->id()], 'SKU-VAR-WHITE');
        $removed = $product->addStandardVariation([$definition->id() => $red->id()], 'SKU-VAR-RED');

        $archived->archive();

        app(ProductRepository::class)->save($product);

        $variationIds = [
            'live' => (string) $live->id(),
            'archived' => (string) $archived->id(),
            'soft_deleted' => (string) $removed->id(),
        ];

        return [$product, $variationIds];
    }

    /**
     * Soft-deletes one variation row at the DATABASE layer, directly: nothing
     * in this codebase soft deletes a variation, so this state is unreachable
     * through the domain — and it is precisely the state §3.19.4 step 1's
     * withTrashed() sweep exists for.
     *
     * DELIBERATELY SEPARATE FROM THE FIXTURE, and meant to be called AFTER
     * any ProductRepository::save() the test needs: EloquentProductRepository::save()
     * re-persists the aggregate's own variations by id via
     * `VariationModel::findOrFail()`, which a SoftDeletes scope hides — so a
     * save after this call would blow up rather than silently resurrect the
     * row. That is a real property of the repository, not a fixture quirk.
     */
    private function softDeleteVariation(string $variationId): void
    {
        VariationModel::whereKey($variationId)->delete();

        $this->assertSame(1, VariationModel::onlyTrashed()->whereKey($variationId)->count());
    }

    /** Any status -> ARCHIVED, persisted — the precondition G-D3 demands. */
    private function archiveProduct(Product $product): void
    {
        $product->archive();
        app(ProductRepository::class)->save($product);

        $this->assertSame(
            ProductStatus::ARCHIVED->value,
            DB::table('catalog_products')->where('id', $product->id())->value('status'),
        );
    }

    private function addStockLevel(string $variationId, int $quantity): void
    {
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $quantity));
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

    private function addCartLine(string $variationId): void
    {
        $cart = Cart::forGuest('token-'.uniqid(), new DateTimeImmutable('+10 days'));
        $cart->addLine(new CartLine(null, '', $variationId, 2));
        app(CartRepository::class)->save($cart);
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
        DB::table('catalog_variation_media')->insert([
            'variation_id' => $variationId,
            'media_id' => $this->mediaAssetId('products/shirt.jpg'),
            'sort_order' => 0,
        ]);
    }

    private function addProductMedia(string $productId): void
    {
        DB::table('catalog_product_media')->insert([
            'product_id' => $productId,
            'media_id' => $this->mediaAssetId('products/product.jpg'),
            'sort_order' => 0,
        ]);
    }

    /** catalog_media is the table MediaAssetModel maps; it is NOT deleted by a product delete (§3.19.7/D6). */
    private function mediaAssetId(string $path): int
    {
        return (int) DB::table('catalog_media')->insertGetId([
            'type' => 'image',
            'disk' => 'public',
            'path' => $path,
            'alt_text' => 'A shirt',
            'processing_status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addVariationPriceListItem(string $variationId): void
    {
        DB::table('pricing_price_list_items')->insert([
            'price_list_id' => $this->priceListId(),
            'target_type' => PriceListItemTargetType::VARIATION->value,
            'target_id' => $variationId,
            'min_quantity' => 1,
            'price_amount_minor' => 1999,
            'price_currency' => 'EUR',
            'price_tax_rate_basis_points' => 2000,
            'price_tax_inclusive' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addProductPriceListItem(string $productId): void
    {
        DB::table('pricing_price_list_items')->insert([
            'price_list_id' => $this->priceListId(),
            'target_type' => PriceListItemTargetType::PRODUCT->value,
            'target_id' => $productId,
            'min_quantity' => 1,
            'price_amount_minor' => 2499,
            'price_currency' => 'EUR',
            'price_tax_rate_basis_points' => 2000,
            'price_tax_inclusive' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The product-scope row §3.19.4 steps 3-4 remove, in whichever scope table is asked for. */
    private function addProductScopeRow(string $table, string $productId): void
    {
        $row = [
            'scope_type' => 'product',
            'scope_reference_id' => $productId,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($table === 'promotion_scopes') {
            $row['promotion_id'] = $this->promotionId();
            $row['mode'] = 'include';
        } else {
            $row['price_list_id'] = $this->priceListId();
        }

        DB::table($table)->insert($row);
    }

    private function promotionId(): int
    {
        return (int) DB::table('promotions')->insertGetId([
            'code' => 'PROMO-'.uniqid(),
            'discount_type' => 'percentage',
            'discount_percentage_basis_points' => 1000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function priceListId(): int
    {
        return (int) DB::table('pricing_price_lists')->insertGetId([
            'name' => 'Wholesale',
            'mode' => 'fixed_items',
            'priority' => random_int(1, 100000),
            'status' => 'active',
            'scope_signature' => str_pad((string) random_int(1, 100000), 64, '0', STR_PAD_LEFT),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Every table §3.19.4 touches for a product, and the ids captured BEFORE
     * the delete — `catalog_variations` rows are gone afterwards, so passing
     * them in is what keeps the variation-scoped counts from being vacuously
     * zero.
     *
     * @param string[] $variationIds
     * @return array<string, int>
     */
    private function productRowCounts(string $productId, array $variationIds): array
    {
        return [
            'catalog_products' => DB::table('catalog_products')->where('id', $productId)->count(),
            'catalog_variations (withTrashed)' => VariationModel::withTrashed()->where('product_id', $productId)->count(),
            'catalog_variation_attribute_values' => DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $variationIds)->count(),
            'catalog_variation_media' => DB::table('catalog_variation_media')->whereIn('variation_id', $variationIds)->count(),
            'catalog_product_media' => DB::table('catalog_product_media')->where('product_id', $productId)->count(),
            'catalog_product_attributes' => DB::table('catalog_product_attributes')->where('product_id', $productId)->count(),
            'catalog_product_axis_values' => DB::table('catalog_product_axis_values')->where('product_id', $productId)->count(),
            'stock_levels' => DB::table('stock_levels')->whereIn('variation_id', $variationIds)->count(),
            'cart_lines' => DB::table('cart_lines')->whereIn('variation_id', $variationIds)->count(),
            'pricing_price_list_items (variation target)' => DB::table('pricing_price_list_items')
                ->where('target_type', PriceListItemTargetType::VARIATION->value)
                ->whereIn('target_id', $variationIds)
                ->count(),
            'pricing_price_list_items (product target)' => DB::table('pricing_price_list_items')
                ->where('target_type', PriceListItemTargetType::PRODUCT->value)
                ->where('target_id', $productId)
                ->count(),
            'pricing_product_costs' => DB::table('pricing_product_costs')->whereIn('priceable_id', $variationIds)->count(),
            'pricing_price_list_scopes (product scope)' => DB::table('pricing_price_list_scopes')
                ->where('scope_type', 'product')
                ->where('scope_reference_id', $productId)
                ->count(),
            'promotion_scopes (product scope)' => DB::table('promotion_scopes')
                ->where('scope_type', 'product')
                ->where('scope_reference_id', $productId)
                ->count(),
        ];
    }

    public function test_the_impact_reports_every_variation_shape_and_every_count(): void
    {
        [$product, $ids] = $this->variableProductWithThreeVariationShapes();
        $this->archiveProduct($product);
        $this->softDeleteVariation($ids['soft_deleted']);

        $this->addCartLine($ids['live']);
        $this->addVariationPriceListItem($ids['live']);
        $this->addProductPriceListItem((string) $product->id());
        $this->addCostRow($ids['live']);
        $this->addVariationMedia($ids['live']);
        $this->addProductMedia((string) $product->id());
        $this->addProductScopeRow('pricing_price_list_scopes', (string) $product->id());
        $this->addProductScopeRow('promotion_scopes', (string) $product->id());

        $impact = app(CatalogDeletion::class)->impactForProduct((string) $product->id());

        $this->assertSame('SKU-VAR', $impact->baseSku);
        $this->assertSame('variable-shirt', $impact->slug);
        $this->assertSame('archived', $impact->status);
        $this->assertSame(3, $impact->variationCount());

        // Every shape §3.19.4 step 1 takes — live, ARCHIVED and SOFT-DELETED
        // alike — in ascending catalog_variations.id order.
        $this->assertSame(
            [$ids['live'], $ids['archived'], $ids['soft_deleted']],
            array_map(static fn ($variation): string => $variation->variationId, $impact->variations),
        );
        $this->assertSame(
            ['SKU-VAR-BLACK', 'SKU-VAR-WHITE', 'SKU-VAR-RED'],
            array_map(static fn ($variation): string => $variation->sku, $impact->variations),
        );
        $this->assertSame(
            ['draft', 'archived', 'draft'],
            array_map(static fn ($variation): string => $variation->status, $impact->variations),
        );
        $this->assertSame(
            ['standard', 'standard', 'standard'],
            array_map(static fn ($variation): string => $variation->variationType, $impact->variations),
        );
        $this->assertSame([['name' => 'Color', 'value' => 'Black']], $impact->variations[0]->attributes);

        $this->assertSame(1, $impact->cartLineCount);
        $this->assertSame(0, $impact->convertedCartLineCount);
        // One variation-target plus one product-target row.
        $this->assertSame(2, $impact->priceListItemCount);
        $this->assertSame(1, $impact->productPriceListItemCount);
        $this->assertSame(1, $impact->priceListScopeCount);
        $this->assertSame(1, $impact->promotionScopeCount);
        $this->assertSame(1, $impact->costRowCount);
        // One variation pivot plus one product pivot.
        $this->assertSame(2, $impact->mediaCount);
        $this->assertSame(1, $impact->productMediaCount);

        $this->assertTrue($impact->isDeletable());
        $this->assertNull($impact->refusal);
        $this->assertCount(3, $impact->deletableVariations());
        $this->assertCount(0, $impact->blockedVariations());
    }

    public function test_the_impact_refuses_a_product_that_is_not_archived(): void
    {
        [$product] = $this->variableProductWithThreeVariationShapes();

        $impact = app(CatalogDeletion::class)->impactForProduct((string) $product->id());

        $this->assertFalse($impact->isDeletable());
        $this->assertNotNull($impact->refusal);
        $this->assertSame(ProductDeletionRefusal::NOT_ARCHIVED, $impact->refusal->reason);
        $this->assertSame([], $impact->refusal->blockingVariations);

        app()->setLocale('en');
        $this->assertSame(
            'Only an archived product can be deleted. Archive Variable Shirt first.',
            ProductDeletionRefusalMessage::for($impact->refusal),
        );

        app()->setLocale('bg');
        $this->assertSame(
            'Само архивиран продукт може да бъде изтрит. Първо архивирайте Variable Shirt.',
            ProductDeletionRefusalMessage::for($impact->refusal),
        );

        // The exception's OWN sentence stays English in the same request —
        // the split VariationNotDeletableException's docblock states.
        $this->assertSame('bg', app()->getLocale());
        $this->assertStringContainsString('is not archived and cannot be deleted', $impact->refusal->getMessage());
    }

    /**
     * The refusal that carries the VARIATION-level facts: a soft-deleted sale
     * line is still history (the same rule the variation half of this design
     * enforces), and an ARCHIVED variation with stock still blocks the whole
     * product. Both at once, so the product sentence has to name two.
     */
    public function test_the_impact_refuses_when_variations_have_history_and_stock(): void
    {
        [$product, $ids] = $this->variableProductWithThreeVariationShapes();
        $this->archiveProduct($product);

        $this->addSaleLine($ids['live']);
        SaleLineModel::query()->where('priceable_id', $ids['live'])->delete();
        $this->assertSame(1, SaleLineModel::onlyTrashed()->where('priceable_id', $ids['live'])->count());

        $this->addStockLevel($ids['archived'], 4);

        $impact = app(CatalogDeletion::class)->impactForProduct((string) $product->id());

        $this->assertFalse($impact->isDeletable());
        $this->assertNotNull($impact->refusal);
        $this->assertSame(ProductDeletionRefusal::VARIATIONS_BLOCK_DELETION, $impact->refusal->reason);
        $this->assertSame(
            [
                ['sku' => 'SKU-VAR-BLACK', 'count' => 1, 'reason' => VariationDeletionRefusal::HAS_HISTORY],
                ['sku' => 'SKU-VAR-WHITE', 'count' => 4, 'reason' => VariationDeletionRefusal::HAS_STOCK],
            ],
            $impact->refusal->blockingVariations,
        );

        // Every variation still gets its own verdict — the deletable ones too.
        $this->assertCount(1, $impact->deletableVariations());
        $this->assertCount(2, $impact->blockedVariations());

        app()->setLocale('en');
        $this->assertSame(
            '"Variable Shirt" cannot be deleted: variation "SKU-VAR-BLACK" has 1 sale line(s). variation "SKU-VAR-WHITE" has 4 in stock.',
            ProductDeletionRefusalMessage::for($impact->refusal),
        );

        app()->setLocale('bg');
        $bulgarian = ProductDeletionRefusalMessage::for($impact->refusal);

        $this->assertSame(
            '„Variable Shirt“ не може да бъде изтрит: вариантът „SKU-VAR-BLACK“ има 1 реда в продажби. вариантът „SKU-VAR-WHITE“ има 4 бройки наличност.',
            $bulgarian,
        );

        // The English clause is nowhere in the merchant's sentence...
        $this->assertStringNotContainsString('sale line(s)', $bulgarian);

        // ...while the exception's own message stays English, in that very
        // same request.
        $this->assertSame('bg', app()->getLocale());
        $this->assertSame(
            'Product "Variable Shirt" cannot be deleted: variation "SKU-VAR-BLACK" has 1 sale line(s). variation "SKU-VAR-WHITE" has 4 in stock.',
            $impact->refusal->getMessage(),
        );
    }

    /**
     * §3.19.11/G-D8 for a product: the freed base_sku and slug must be
     * genuinely reusable, which means a NEW product taking both without a
     * unique-constraint failure — the assertion that would catch a soft delete
     * of the product row, or any catalog row left behind.
     */
    public function test_delete_removes_a_simple_product_and_its_universal_variation_and_frees_its_identifiers(): void
    {
        [$product, $universalId] = $this->simpleProduct();
        $productId = (string) $product->id();

        $this->addCartLine($universalId);
        $this->addCostRow($universalId);
        $this->addVariationPriceListItem($universalId);
        $this->addProductPriceListItem($productId);
        $this->addProductScopeRow('pricing_price_list_scopes', $productId);
        $this->addProductScopeRow('promotion_scopes', $productId);
        $this->addProductMedia($productId);
        $this->addVariationMedia($universalId);

        $this->archiveProduct($product);

        $before = $this->productRowCounts($productId, [$universalId]);
        $this->assertSame(0, $before['stock_levels']);
        $this->assertSame(1, $before['cart_lines']);
        $this->assertSame(1, $before['catalog_products']);
        $this->assertSame(1, $before['catalog_variations (withTrashed)']);

        app(CatalogDeletion::class)->deleteProduct($productId);

        // Every §3.19.4 table, for that product, empty — including the
        // UNIVERSAL variation, which goes only together with its product.
        foreach ($this->productRowCounts($productId, [$universalId]) as $table => $count) {
            $this->assertSame(0, $count, "Expected zero rows in {$table}.");
        }

        // D6/§3.19.7: the ASSET is not a child of the product — only the
        // pivot went.
        $this->assertSame(2, DB::table('catalog_media')->count());

        // §3.19.11: both identifiers are free, proven by using them again.
        $replacement = Product::createSimple('Simple Hat Again', 'SKU-HAT', 'simple-hat');
        app(ProductRepository::class)->save($replacement);

        $this->assertNotNull($replacement->id());
        $this->assertNotSame($productId, (string) $replacement->id());
        $this->assertNotNull(DB::table('catalog_products')->where('id', $replacement->id())->first());
    }

    /**
     * The VARIABLE case §3.19.4 step 1 is really about: a live STANDARD
     * variation, an ARCHIVED one (a real row) and a SOFT-DELETED one all go,
     * with their stock, baskets, costs, price items and media pivots — while
     * an unrelated product's identical rows are byte-for-byte untouched.
     */
    public function test_delete_removes_a_variable_product_with_live_archived_and_soft_deleted_variations(): void
    {
        [$product, $ids] = $this->variableProductWithThreeVariationShapes();
        $productId = (string) $product->id();
        $this->archiveProduct($product);

        foreach ($ids as $variationId) {
            // Quantity 0 on purpose: a REAL stock row the delete must remove
            // explicitly, not a refusal to dodge.
            $this->addStockLevel($variationId, 0);
            $this->addCostRow($variationId);
            $this->addVariationPriceListItem($variationId);
            $this->addVariationMedia($variationId);
        }

        $this->addCartLine($ids['live']);
        $this->addProductPriceListItem($productId);
        $this->addProductScopeRow('pricing_price_list_scopes', $productId);
        $this->addProductScopeRow('promotion_scopes', $productId);
        $this->addProductMedia($productId);

        // An unrelated product with the same shapes of rows, to prove the
        // delete is scoped to ONE product.
        [$other, $otherUniversalId] = $this->simpleProduct('SKU-OTHER', 'other-hat');
        $otherId = (string) $other->id();
        $this->addCartLine($otherUniversalId);
        $this->addCostRow($otherUniversalId);
        $this->addVariationPriceListItem($otherUniversalId);
        $this->addProductPriceListItem($otherId);
        $this->addProductScopeRow('pricing_price_list_scopes', $otherId);
        $this->addProductScopeRow('promotion_scopes', $otherId);
        $this->addVariationMedia($otherUniversalId);
        $this->addProductMedia($otherId);

        $otherBefore = $this->productRowCounts($otherId, [$otherUniversalId]);
        $this->assertSame(1, $otherBefore['catalog_products']);

        // LAST, so nothing above needed a domain object for this row.
        $this->softDeleteVariation($ids['soft_deleted']);

        $variationIds = array_values($ids);

        // Pre-conditions, so "zero afterwards" cannot pass vacuously.
        $before = $this->productRowCounts($productId, $variationIds);
        $this->assertSame(1, $before['catalog_products']);
        $this->assertSame(3, $before['catalog_variations (withTrashed)']);
        $this->assertSame(3, $before['stock_levels']);
        $this->assertSame(3, $before['pricing_product_costs']);
        $this->assertSame(3, $before['pricing_price_list_items (variation target)']);
        $this->assertSame(3, $before['catalog_variation_media']);
        $this->assertSame(1, $before['cart_lines']);
        $this->assertSame(1, $before['pricing_price_list_items (product target)']);
        $this->assertSame(1, $before['pricing_price_list_scopes (product scope)']);
        $this->assertSame(1, $before['promotion_scopes (product scope)']);

        app(CatalogDeletion::class)->deleteProduct($productId);

        foreach ($this->productRowCounts($productId, $variationIds) as $table => $count) {
            $this->assertSame(0, $count, "Expected zero rows in {$table}.");
        }

        // Nothing of the other product changed — every count identical.
        $this->assertSame($otherBefore, $this->productRowCounts($otherId, [$otherUniversalId]));

        // §3.19.11: both identifiers are free, proven by using them again.
        $replacement = Product::createVariable('Variable Shirt Again', 'SKU-VAR', 'variable-shirt');
        app(ProductRepository::class)->save($replacement);

        $this->assertNotNull($replacement->id());
        $this->assertNotSame($productId, (string) $replacement->id());
    }

    public function test_delete_refuses_a_product_that_is_not_archived_and_deletes_nothing(): void
    {
        [$product, $ids] = $this->variableProductWithThreeVariationShapes();
        $productId = (string) $product->id();
        $variationIds = array_values($ids);

        $before = $this->productRowCounts($productId, $variationIds);

        try {
            app(CatalogDeletion::class)->deleteProduct($productId);
            $this->fail('A non-archived product must not be deleted.');
        } catch (ProductNotDeletableException $e) {
            $this->assertSame(ProductDeletionRefusal::NOT_ARCHIVED, $e->reason);
            $this->assertSame([], $e->blockingVariations);
        }

        $this->assertSame($before, $this->productRowCounts($productId, $variationIds));
        $this->assertSame(0, DB::table('activity_log')->where('action', ActivityLogger::ACTION_DELETED)->count());
    }

    public function test_delete_refuses_when_a_variation_has_history_or_stock_and_deletes_nothing(): void
    {
        [$product, $ids] = $this->variableProductWithThreeVariationShapes();
        $productId = (string) $product->id();
        $this->archiveProduct($product);

        $this->addSaleLine($ids['live']);
        $this->addStockLevel($ids['archived'], 2);

        $variationIds = array_values($ids);
        $before = $this->productRowCounts($productId, $variationIds);

        try {
            app(CatalogDeletion::class)->deleteProduct($productId);
            $this->fail('A product with a blocked variation must not be deleted.');
        } catch (ProductNotDeletableException $e) {
            $this->assertSame(ProductDeletionRefusal::VARIATIONS_BLOCK_DELETION, $e->reason);
            $this->assertSame(
                [
                    ['sku' => 'SKU-VAR-BLACK', 'count' => 1, 'reason' => VariationDeletionRefusal::HAS_HISTORY],
                    ['sku' => 'SKU-VAR-WHITE', 'count' => 2, 'reason' => VariationDeletionRefusal::HAS_STOCK],
                ],
                $e->blockingVariations,
            );
        }

        $this->assertSame($before, $this->productRowCounts($productId, $variationIds));
        $this->assertSame(0, DB::table('activity_log')->where('action', ActivityLogger::ACTION_DELETED)->count());
    }

    /** A stale id (already deleted, or never existed) is reported, never half-acted-on. */
    public function test_delete_product_reports_an_unknown_product(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(CatalogDeletion::class)->deleteProduct('999999');
    }

    /**
     * §3.19.10's first decision: the snapshot is written even though the
     * activity log is OFF by default, it carries EVERY variation, and it is
     * ONE row — the whole operation is one event, not one row per fact.
     */
    public function test_delete_product_writes_one_snapshot_with_every_variation_while_the_log_is_disabled(): void
    {
        // Deliberately NOT enabled — the default installation.
        $this->assertNotSame(
            '1',
            app(SiteSettingsRepository::class)->get('admin.activity_log_enabled'),
        );

        [$product, $ids] = $this->variableProductWithThreeVariationShapes();
        $productId = (string) $product->id();
        $this->archiveProduct($product);

        foreach ($ids as $variationId) {
            $this->addStockLevel($variationId, 0);
            $this->addCostRow($variationId);
            $this->addVariationPriceListItem($variationId);
            $this->addVariationMedia($variationId);
        }

        $this->addCartLine($ids['live']);
        $this->addProductPriceListItem($productId);
        $this->addProductScopeRow('pricing_price_list_scopes', $productId);
        $this->addProductScopeRow('promotion_scopes', $productId);
        $this->addProductMedia($productId);

        $this->softDeleteVariation($ids['soft_deleted']);

        app(CatalogDeletion::class)->deleteProduct($productId);

        $rows = DB::table('activity_log')->where('action', ActivityLogger::ACTION_DELETED)->get();

        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame('product', $row->entity_type);
        $this->assertSame($productId, $row->entity_id);
        $this->assertNull($row->field);
        $this->assertNull($row->new_value);
        $this->assertNotNull($row->occurred_at);

        $snapshot = json_decode((string) $row->old_value, true);

        $this->assertSame($productId, $snapshot['product_id']);
        $this->assertSame('Variable Shirt', $snapshot['product_name']);
        $this->assertSame('SKU-VAR', $snapshot['product_base_sku']);
        $this->assertSame('variable-shirt', $snapshot['product_slug']);
        $this->assertSame('archived', $snapshot['product_status']);
        $this->assertSame(3, $snapshot['variation_count']);

        // Every variation, including the soft-deleted one, with its identity.
        $this->assertSame(
            ['SKU-VAR-BLACK', 'SKU-VAR-WHITE', 'SKU-VAR-RED'],
            array_column($snapshot['variations'], 'variation_sku'),
        );
        $this->assertSame(
            ['draft', 'archived', 'draft'],
            array_column($snapshot['variations'], 'variation_status'),
        );
        $this->assertSame(
            ['standard', 'standard', 'standard'],
            array_column($snapshot['variations'], 'variation_type'),
        );
        $this->assertSame(
            [
                [['name' => 'Color', 'value' => 'Black']],
                [['name' => 'Color', 'value' => 'White']],
                [['name' => 'Color', 'value' => 'Red']],
            ],
            array_column($snapshot['variations'], 'attributes'),
        );
        $this->assertSame([0, 0, 0], array_column($snapshot['variations'], 'sale_line_count'));
        $this->assertSame([0, 0, 0], array_column($snapshot['variations'], 'stock_quantity'));
        $this->assertSame([1, 1, 1], array_column($snapshot['variations'], 'price_list_item_count'));
        $this->assertSame([1, 1, 1], array_column($snapshot['variations'], 'cost_row_count'));
        $this->assertSame([1, 1, 1], array_column($snapshot['variations'], 'media_count'));

        // The aggregate counts: 3 variation-target + 1 product-target price
        // items, 3 variation + 1 product media pivots.
        $this->assertSame(1, $snapshot['cart_line_count']);
        $this->assertSame(0, $snapshot['converted_cart_line_count']);
        $this->assertSame(4, $snapshot['price_list_item_count']);
        $this->assertSame(3, $snapshot['cost_row_count']);
        $this->assertSame(4, $snapshot['media_count']);
        $this->assertSame(1, $snapshot['price_list_scope_count']);
        $this->assertSame(1, $snapshot['promotion_scope_count']);
    }

    /**
     * §3.19.5's locking rules, asserted at the level they are testable: the
     * emitted SQL locks the product row, EVERY variation's stock row and the
     * history read. A plain read on any of the three would be a snapshot read
     * under MySQL's default REPEATABLE READ, which is the failure mode the
     * locking reads exist to prevent (the two race tests below are the
     * behavioural half).
     */
    public function test_the_product_stock_and_history_reads_all_lock_their_rows(): void
    {
        [$product, $ids] = $this->variableProductWithThreeVariationShapes();
        $this->archiveProduct($product);
        $this->softDeleteVariation($ids['soft_deleted']);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(CatalogDeletion::class)->deleteProduct((string) $product->id());

        $productRead = Arr::first(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'catalog_products') && str_contains(strtolower($sql), 'for update')
        );
        $historyRead = Arr::first(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'operational_sales_sale_lines') && str_contains(strtolower($sql), 'for update')
        );
        $stockReads = Arr::where(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'stock_levels') && str_contains(strtolower($sql), 'for update')
        );

        $this->assertNotNull($productRead, 'The ARCHIVED gate must be re-read with FOR UPDATE, not from the snapshot.');
        $this->assertStringContainsString('status', $productRead);

        $this->assertNotNull($historyRead, 'The history check must be a FOR UPDATE read, not a snapshot read.');
        $this->assertStringContainsString('count(*)', strtolower($historyRead));

        // One stock lock per variation — THREE rows, including the
        // soft-deleted variation's, since §3.19.4 step 1 takes all of them.
        $this->assertCount(3, array_values($stockReads));
    }

    /**
     * §3.19.5's behavioural claim for a product, staged with a SECOND
     * connection: a checkout commits a sale line on one of the variations after
     * the impact was computed, and the in-transaction re-check must still
     * refuse the whole product.
     *
     * WHY THIS IS NOT A TAUTOLOGY: the snapshot on the first connection is
     * fixed by the transaction's first non-locking read (the fixture's own
     * reads), so a plain COUNT() would see zero sale lines and delete a product
     * whose variation has history. The re-check is a FOR UPDATE (current) read,
     * so it sees the row committed a moment ago — and the stock locks taken
     * before it are what close the window rather than open it.
     */
    public function test_a_sale_line_committed_after_the_impact_is_still_seen_by_the_locking_history_read(): void
    {
        config()->set(
            'database.connections.deletion_race',
            config('database.connections.'.config('database.default'))
        );
        DB::purge('deletion_race');

        [$product, $ids] = $this->variableProductWithThreeVariationShapes();
        $productId = (string) $product->id();
        $this->archiveProduct($product);

        $deletion = app(CatalogDeletion::class);

        // The merchant's own view of the world: deletable.
        $this->assertTrue($deletion->impactForProduct($productId)->isDeletable());

        $staged = false;
        DB::listen(function ($query) use (&$staged, $ids): void {
            if ($staged
                || ! str_contains($query->sql, 'stock_levels')
                || ! str_contains(strtolower($query->sql), 'for update')) {
                return;
            }

            $staged = true;
            $this->commitSaleLineOnSecondConnection($ids['archived']);
        });

        try {
            $deletion->deleteProduct($productId);
            $this->fail('The in-transaction re-check must refuse a sale line committed after the view was read.');
        } catch (ProductNotDeletableException $e) {
            $this->assertSame(ProductDeletionRefusal::VARIATIONS_BLOCK_DELETION, $e->reason);
            $this->assertSame(
                [['sku' => 'SKU-VAR-WHITE', 'count' => 1, 'reason' => VariationDeletionRefusal::HAS_HISTORY]],
                $e->blockingVariations,
            );
        }

        $this->assertTrue($staged, 'The race must actually have been staged — the hook never fired.');

        // Nothing was deleted: the product, every variation row and the
        // committed sale line are all still there.
        $this->assertSame(1, DB::table('catalog_products')->where('id', $productId)->count());
        $this->assertSame(3, VariationModel::withTrashed()->where('product_id', $productId)->count());
        $this->assertSame(
            1,
            DB::connection('deletion_race')->table('operational_sales_sale_lines')
                ->where('priceable_id', $ids['archived'])
                ->count()
        );
        $this->assertSame(0, DB::table('activity_log')->where('action', ActivityLogger::ACTION_DELETED)->count());
    }

    /**
     * The other half of §3.19.5's behavioural claim, and the reason the
     * ARCHIVED gate is a LOCKING read: another connection un-archives the
     * product between the merchant's impact and the delete. A plain read would
     * still see ARCHIVED — this transaction's snapshot predates that commit —
     * while the delete's own re-read must see ACTIVE and refuse.
     *
     * The product row is created ON the second connection deliberately: a row
     * this test's own transaction had written would be locked by it for the
     * rest of the test, so the other connection's UPDATE would block instead of
     * committing, and the test would prove nothing. Nothing else is needed,
     * because G-D3's refusal happens before any variation is read at all.
     */
    public function test_a_product_unarchived_by_another_connection_after_the_impact_is_refused(): void
    {
        config()->set(
            'database.connections.deletion_race',
            config('database.connections.'.config('database.default'))
        );
        DB::purge('deletion_race');

        $productId = $this->commitArchivedProductOnSecondConnection('SKU-RACE-PRODUCT', 'race-product');

        $deletion = app(CatalogDeletion::class);

        // The merchant's own view of the world: archived, deletable.
        $this->assertTrue($deletion->impactForProduct($productId)->isDeletable());

        // Another staff member un-archives it, committed immediately.
        DB::connection('deletion_race')->table('catalog_products')
            ->where('id', $productId)
            ->update(['status' => ProductStatus::ACTIVE->value]);

        $this->assertSame(
            ProductStatus::ACTIVE->value,
            DB::connection('deletion_race')->table('catalog_products')->where('id', $productId)->value('status'),
        );

        // A NON-locking read on this connection still sees the old value: the
        // snapshot predates the commit. That staleness is exactly what the
        // delete's locking re-read must not suffer from.
        $this->assertSame(
            ProductStatus::ARCHIVED->value,
            DB::table('catalog_products')->where('id', $productId)->value('status'),
            'A plain read must still see this transaction\'s snapshot — which is why the gate cannot use one.',
        );

        try {
            $deletion->deleteProduct($productId);
            $this->fail('A product un-archived before the delete must be refused.');
        } catch (ProductNotDeletableException $e) {
            $this->assertSame(ProductDeletionRefusal::NOT_ARCHIVED, $e->reason);
            $this->assertSame([], $e->blockingVariations);
        }

        // Nothing was deleted, and nothing was logged.
        $this->assertSame(
            1,
            DB::connection('deletion_race')->table('catalog_products')->where('id', $productId)->count(),
        );
        $this->assertSame(0, DB::table('activity_log')->where('action', ActivityLogger::ACTION_DELETED)->count());
    }

    /**
     * Commits a sale line for $variationId on the SECOND connection — outside
     * this test's own wrapping transaction, so its write is genuinely
     * committed and genuinely invisible to the snapshot the first connection
     * already established.
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
     * An ARCHIVED VARIABLE product with base_sku/slug committed on the second
     * connection — the fixture the un-archive race needs, and the reason it is
     * a raw insert: it must not be written by the test's own transaction.
     */
    private function commitArchivedProductOnSecondConnection(string $baseSku, string $slug): string
    {
        $id = (string) DB::connection('deletion_race')->table('catalog_products')->insertGetId([
            'type' => 'variable',
            'name' => 'Raced Product',
            'slug' => $slug,
            'base_sku' => $baseSku,
            'status' => ProductStatus::ARCHIVED->value,
            'catalog_visibility' => 'hidden',
            'timeline_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->raceProductId = $id;

        return $id;
    }
}
