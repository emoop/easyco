<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The whole justification for cart-domain-design.md §4 (Cart resolves
 * pricing live on every read, stores nothing authoritative) lives in
 * this one test — see the WooCommerce/Shopify precedent recorded
 * there. Deliberately not weakened or skipped.
 */
class CartLivePricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost/');
    }

    private function variationId(): string
    {
        $product = Product::createSimple('Product 1', 'SKU-1', 'product-slug-1');
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    private function seedPriceListItem(string $priceListId, string $variationId, string $decimalAmount): void
    {
        $item = new PriceListItem(
            null,
            $priceListId,
            PriceListItemTargetType::VARIATION,
            $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($decimalAmount, 'EUR'), 0),
        );
        app(PriceListItemRepository::class)->save($item);
    }

    public function test_cart_reflects_a_price_change_made_after_the_item_was_added(): void
    {
        $variationId = $this->variationId();
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 10));

        $priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
        app(PriceListRepository::class)->save($priceList);
        $this->seedPriceListItem($priceList->id(), $variationId, '20.00');

        $add = $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1]);
        $add->assertStatus(201);

        $originalPriceAtAdd = $add->json('lines.0.price_at_add.minor');
        $this->assertSame(2000, $originalPriceAtAdd);
        $this->assertSame(2000, $add->json('lines.0.unit_price.minor'));
        $this->assertFalse($add->json('lines.0.price_changed_since_add'));

        // The underlying price changes AFTER the item was added — a
        // second, higher-priority winning price list, exactly like a
        // merchant launching a sale independently of this cart.
        $saleList = PriceList::create('Flash Sale', PriceListMode::FIXED_ITEMS, priority: 10);
        app(PriceListRepository::class)->save($saleList);
        $this->seedPriceListItem($saleList->id(), $variationId, '15.00');

        $get = $this->getJson('/api/cart');
        $get->assertStatus(200);

        // The live price now reflects the new, cheaper price...
        $this->assertSame(1500, $get->json('lines.0.unit_price.minor'));
        // ...while price_at_add still shows what it cost when added...
        $this->assertSame(2000, $get->json('lines.0.price_at_add.minor'));
        // ...and the cart makes the discrepancy visible.
        $this->assertTrue($get->json('lines.0.price_changed_since_add'));
    }
}
