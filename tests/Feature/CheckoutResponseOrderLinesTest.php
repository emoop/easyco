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
 * The checkout response's order.lines — added so the sandbox confirmation page
 * (stage D6) can render what was actually bought from the response it already
 * has, with no second lookup and no client-side re-derivation from the cart
 * (already claimed by then: the claim records the order id without clearing the
 * cart's lines — a known Cart defect tracked separately, see
 * sandbox-manual-test-checklist.md's scenario 10).
 *
 * Read from the sale-line SNAPSHOT the orchestrator has just written, so the
 * values asserted here are the recorded sale facts, not a re-pricing.
 *
 * The last test is the one that matters most: a customer-facing response must
 * never carry the shop's cost basis. It asserts the ABSENCE of unit cost and
 * profit by name, both as JSON keys and as substrings anywhere in the body.
 */
class CheckoutResponseOrderLinesTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;

    private ?PriceList $priceList = null;

    protected function setUp(): void
    {
        parent::setUp();

        // See account-domain-design.md §10 — Sanctum's stateful pipeline needs a
        // recognized Referer to engage the session at all, which the guest
        // cart_token depends on.
        $this->withHeader('Referer', 'http://localhost/');
    }

    private function pricedPurchasableVariation(string $decimalAmount = '10.00', int $stock = 10): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        $variationId = $product->variations()[0]->id();

        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        app(PriceListItemRepository::class)->save(new PriceListItem(
            null,
            $this->priceList->id(),
            PriceListItemTargetType::VARIATION,
            $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($decimalAmount, 'EUR'), 0),
        ));

        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $stock));

        return $variationId;
    }

    private string $cartId = '';

    private function addLineViaHttp(string $variationId, int $quantity = 1): void
    {
        $this->cartId = (string) $this->postJson('/api/cart/lines', [
            'variation_id' => $variationId,
            'quantity' => $quantity,
        ])->assertStatus(201)->json('cart_id');
    }

    /** @return array<string, mixed> */
    private function checkoutPayload(array $overrides = []): array
    {
        return array_merge([
            'cart_id' => $this->cartId,
            'email' => 'guest@example.com',
            'recipient_name' => 'Guest Buyer',
            'phone' => '+359888000000',
            'payment_method' => 'cash_on_delivery',
            'delivery_type' => 'street_address',
            'country' => 'BG',
            'city' => 'Sofia',
            'address_line_1' => 'Vitosha Blvd 1',
        ], $overrides);
    }

    public function test_a_placed_orders_lines_come_from_the_sale_line_snapshot(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 2);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload())->assertStatus(201);

        $lines = $response->json('order.lines');

        $this->assertCount(1, $lines);
        $line = $lines[0];

        $this->assertStringStartsWith('Product ', $line['product_name']);
        $this->assertStringStartsWith('SKU-', $line['sku']);
        $this->assertSame([], $line['attributes'], 'a SIMPLE product sold with no attributes records an empty list, not null');
        $this->assertSame(2, $line['quantity']);

        // 10.00 EUR unit, 2 units, no promotion: final = regular, nothing discounted,
        // and the net paid is what the customer owes.
        $this->assertSame(['minor' => 1000, 'currency' => 'EUR'], $line['final_unit_price']);
        $this->assertSame(['minor' => 1000, 'currency' => 'EUR'], $line['regular_unit_price']);
        $this->assertSame(['minor' => 0, 'currency' => 'EUR'], $line['promotion_discount_share']);
        $this->assertSame(['minor' => 2000, 'currency' => 'EUR'], $line['net_paid_amount']);
    }

    public function test_a_replayed_checkout_still_carries_the_lines(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId);

        $first = $this->postJson('/api/checkout', $this->checkoutPayload())->assertStatus(201);
        $second = $this->postJson('/api/checkout', $this->checkoutPayload())->assertStatus(201);

        $this->assertFalse($first->json('already_placed'));
        $this->assertTrue($second->json('already_placed'));

        // The idempotent replay reads the SAME recorded snapshot — a confirmation
        // page rendered from either response shows the same lines.
        $this->assertSame($first->json('order.lines'), $second->json('order.lines'));
        $this->assertCount(1, $second->json('order.lines'));
    }

    /**
     * COST MUST NEVER REACH A CUSTOMER. SaleLine carries unitCost and profit
     * (§3.13), and the admin surface legitimately reads them — this response is the
     * storefront's, so their absence is asserted by name, twice: as JSON keys on the
     * line, and as substrings anywhere in the raw body.
     */
    public function test_the_order_lines_never_carry_unit_cost_or_profit(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload())->assertStatus(201);

        $line = $response->json('order.lines')[0];

        $this->assertSame([
            'product_name',
            'sku',
            'attributes',
            'quantity',
            'final_unit_price',
            'regular_unit_price',
            'promotion_discount_share',
            'net_paid_amount',
        ], array_keys($line));

        $this->assertArrayNotHasKey('unit_cost', $line);
        $this->assertArrayNotHasKey('unitCost', $line);
        $this->assertArrayNotHasKey('profit', $line);
        $this->assertArrayNotHasKey('cost', $line);

        $body = $response->getContent();
        $this->assertStringNotContainsString('cost', $body);
        $this->assertStringNotContainsString('profit', $body);
    }
}
