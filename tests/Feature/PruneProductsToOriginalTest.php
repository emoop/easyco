<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLine;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\Persistence\Eloquent\StockLevelModel;
use EasyCo\Inventory\StockLevel;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Persistence\Eloquent\ClientModel;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Persistence\Eloquent\PriceListItemModel;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `php artisan products:prune-to-original` — a destructive, effectively
 * irreversible command against a real dataset, tested more thoroughly
 * than routine work per this task's own instruction. Fixture patterns
 * mirror EloquentStockLevelRepositoryTest / EloquentCartRepositoryTest /
 * EloquentPriceListItemRepositoryTest / EloquentTransactionRepositoryTest
 * exactly, confirmed against their own real constructor signatures
 * rather than assumed.
 */
class PruneProductsToOriginalTest extends TestCase
{
    use RefreshDatabase;

    /** Creates a SIMPLE product with one universal variation, returns its variation id. */
    private function createProductWithVariation(string $name, string $slug): array
    {
        $product = Product::createSimple($name, 'SKU-'.strtoupper($slug), $slug);
        app(ProductRepository::class)->save($product);

        $productId = $product->id();
        $variationId = $product->variations()[0]->id();

        return [$productId, $variationId];
    }

    private function addStockLevel(string $variationId, int $quantity = 10): void
    {
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $quantity));
    }

    private function addPriceListItem(string $priceListId, PriceListItemTargetType $targetType, string $targetId): void
    {
        $item = new PriceListItem(
            id: null,
            priceListId: $priceListId,
            targetType: $targetType,
            targetId: $targetId,
            price: Price::exclusiveOfTax(Money::fromMinorUnits(1999, 'EUR'), 2000),
        );
        app(PriceListItemRepository::class)->save($item);
    }

    private function persistedPriceListId(): string
    {
        $list = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        app(PriceListRepository::class)->save($list);

        return $list->id();
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
            productName: 'Doomed Product',
            sku: 'SKU-DOOMED',
            regularUnitPrice: Money::fromMinorUnits(2500, 'EUR'),
            finalUnitPrice: Money::fromMinorUnits(2500, 'EUR'),
            promotionDiscountShare: Money::zero('EUR'),
            discretionaryDiscount: Money::zero('EUR'),
            netPaidAmount: Money::fromMinorUnits(2500, 'EUR'),
            soldAttributes: [],
        ));

        app(TransactionRepository::class)->save($transaction);
    }

    /** @return array{0: array<int, array{0: int, 1: string}>, 1: string} 8 products (keep 6, doom 2), each [productId, variationId]; plus a persisted price list id. */
    private function seedEightProducts(): array
    {
        $products = [];

        for ($i = 1; $i <= 8; $i++) {
            [$productId, $variationId] = $this->createProductWithVariation("Product {$i}", "product-{$i}");
            $products[] = [(int) $productId, $variationId];
        }

        $priceListId = $this->persistedPriceListId();

        return [$products, $priceListId];
    }

    public function test_dry_run_with_no_flags_deletes_nothing_and_reports_counts(): void
    {
        [$products, $priceListId] = $this->seedEightProducts();
        [, $doomedVariationId1] = $products[6];
        [, $doomedVariationId2] = $products[7];

        $this->addStockLevel($doomedVariationId1);
        $this->addPriceListItem($priceListId, PriceListItemTargetType::VARIATION, $doomedVariationId2);

        $this->assertSame(8, ProductModel::count());

        $this->artisan('products:prune-to-original')
            ->expectsOutputToContain('Dry run only')
            ->assertExitCode(0);

        $this->assertSame(8, ProductModel::count());
        $this->assertSame(8, VariationModel::count());
        $this->assertSame(1, StockLevelModel::count());
        $this->assertSame(1, PriceListItemModel::count());
    }

    public function test_execute_is_aborted_by_the_safety_gate_when_a_doomed_variation_has_a_real_sale_line_and_nothing_is_deleted(): void
    {
        [$products] = $this->seedEightProducts();
        [, $doomedVariationId] = $products[7];

        $this->addSaleLine($doomedVariationId);

        $this->assertSame(8, ProductModel::count());
        $this->assertSame(1, DB::table('operational_sales_sale_lines')->count());

        $this->artisan('products:prune-to-original', ['--execute' => true, '--force' => true])
            ->expectsOutputToContain('SAFETY GATE TRIPPED')
            ->assertExitCode(1);

        // Nothing was deleted — full row counts unchanged.
        $this->assertSame(8, ProductModel::count());
        $this->assertSame(8, VariationModel::count());
        $this->assertSame(1, DB::table('operational_sales_sale_lines')->count());
    }

    public function test_execute_is_aborted_by_the_safety_gate_when_a_doomed_variation_has_a_real_cart_line_and_nothing_is_deleted(): void
    {
        [$products] = $this->seedEightProducts();
        [, $doomedVariationId] = $products[7];

        $this->addCartLine($doomedVariationId);

        $this->assertSame(1, DB::table('cart_lines')->count());

        $this->artisan('products:prune-to-original', ['--execute' => true, '--force' => true])
            ->expectsOutputToContain('SAFETY GATE TRIPPED')
            ->assertExitCode(1);

        $this->assertSame(8, ProductModel::count());
        $this->assertSame(8, VariationModel::count());
        $this->assertSame(1, DB::table('cart_lines')->count());
    }

    public function test_execute_force_prunes_every_dependent_table_down_to_exactly_the_six_oldest_products(): void
    {
        [$products, $priceListId] = $this->seedEightProducts();

        $keepProductIds = array_map(fn (array $p): int => $p[0], array_slice($products, 0, 6));
        [$doomedProductId1, $doomedVariationId1] = $products[6];
        [$doomedProductId2, $doomedVariationId2] = $products[7];

        // Real dependent data on BOTH doomed products/variations, none of
        // it referencing a variation outside the keep-set, so the safety
        // gate must not trip.
        $this->addStockLevel($doomedVariationId1);
        $this->addStockLevel($doomedVariationId2);
        $this->addPriceListItem($priceListId, PriceListItemTargetType::VARIATION, $doomedVariationId1);
        $this->addPriceListItem($priceListId, PriceListItemTargetType::PRODUCT, (string) $doomedProductId2);

        // Also give a KEPT variation a cart line / sale line / price item
        // / stock level, to prove the command doesn't over-delete.
        [, $keptVariationId] = $products[0];
        $this->addStockLevel($keptVariationId);
        $this->addCartLine($keptVariationId);
        $this->addSaleLine($keptVariationId);
        $this->addPriceListItem($priceListId, PriceListItemTargetType::VARIATION, $keptVariationId);

        $this->assertSame(8, ProductModel::count());
        $this->assertSame(3, StockLevelModel::count());
        $this->assertSame(3, PriceListItemModel::count());

        $this->artisan('products:prune-to-original', ['--execute' => true, '--force' => true])
            ->expectsOutputToContain('Prune complete')
            ->assertExitCode(0);

        $this->assertSame(6, ProductModel::count());
        $this->assertSame($keepProductIds, ProductModel::orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());

        $this->assertSame(6, VariationModel::count());
        $this->assertSame(0, VariationModel::withTrashed()->whereIn('id', [$doomedVariationId1, $doomedVariationId2])->count());

        // Dependent rows for the doomed ids are gone...
        $this->assertSame(0, StockLevelModel::whereIn('variation_id', [$doomedVariationId1, $doomedVariationId2])->count());
        $this->assertSame(0, PriceListItemModel::whereIn('target_id', [$doomedVariationId1, (string) $doomedProductId2])->count());

        // ...but the kept variation's dependent rows survive untouched.
        $this->assertSame(1, StockLevelModel::where('variation_id', $keptVariationId)->count());
        $this->assertSame(1, DB::table('cart_lines')->where('variation_id', $keptVariationId)->count());
        $this->assertSame(1, DB::table('operational_sales_sale_lines')->where('priceable_id', $keptVariationId)->count());
        $this->assertSame(1, PriceListItemModel::where('target_id', $keptVariationId)->count());

        // Cascading pivot/media/attribute tables for the doomed products
        // are empty too — proving the cascade, not assuming it.
        $this->assertSame(0, DB::table('catalog_product_categories')->whereIn('product_id', [$doomedProductId1, $doomedProductId2])->count());
        $this->assertSame(0, DB::table('catalog_product_tags')->whereIn('product_id', [$doomedProductId1, $doomedProductId2])->count());
        $this->assertSame(0, DB::table('catalog_product_media')->whereIn('product_id', [$doomedProductId1, $doomedProductId2])->count());
        $this->assertSame(0, DB::table('catalog_product_attributes')->whereIn('product_id', [$doomedProductId1, $doomedProductId2])->count());
        $this->assertSame(0, DB::table('catalog_product_axis_values')->whereIn('product_id', [$doomedProductId1, $doomedProductId2])->count());
        $this->assertSame(0, DB::table('catalog_variation_media')->whereIn('variation_id', [$doomedVariationId1, $doomedVariationId2])->count());
        $this->assertSame(0, DB::table('catalog_variation_attribute_values')->whereIn('variation_id', [$doomedVariationId1, $doomedVariationId2])->count());
    }

    public function test_execute_without_force_asks_for_confirmation_and_aborts_on_no(): void
    {
        $this->seedEightProducts();

        $this->artisan('products:prune-to-original', ['--execute' => true])
            ->expectsConfirmation('Permanently delete the rows above? This cannot be undone.', 'no')
            ->expectsOutputToContain('Aborted')
            ->assertExitCode(0);

        $this->assertSame(8, ProductModel::count());
    }

    public function test_a_soft_deleted_doomed_variation_is_genuinely_force_deleted(): void
    {
        [$products] = $this->seedEightProducts();
        [, $doomedVariationId] = $products[7];

        VariationModel::find($doomedVariationId)->delete();

        $this->assertNotNull(VariationModel::withTrashed()->find($doomedVariationId));
        $this->assertNull(VariationModel::find($doomedVariationId));

        $this->artisan('products:prune-to-original', ['--execute' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertNull(VariationModel::withTrashed()->find($doomedVariationId));
    }
}
