<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Cart\Cart;
use EasyCo\Cart\Contracts\CartRepository;
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
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProvidesCheckoutShipping;
use Tests\TestCase;

/**
 * Shipping stage 4h (B6): `cart:prune` never deletes a CLAIMED cart. End to end: an order is placed, the cart's
 * expiry passes, the prune runs, and the same checkout request still answers 201 `already_placed` with the same
 * order — while an expired unclaimed cart is deleted by the same run.
 */
class CartPruneKeepsClaimedCartTest extends TestCase
{
    use RefreshDatabase;
    use ProvidesCheckoutShipping;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost/');
    }

    private function pricedVariation(): string
    {
        $product = Product::createSimple('Prune Product', 'PRUNE-1', 'prune-product');
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        $list = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
        app(PriceListRepository::class)->save($list);
        app(PriceListItemRepository::class)->save(new PriceListItem(
            null, $list->id(), PriceListItemTargetType::VARIATION, $variationId,
            Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 0),
        ));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 10));

        return $variationId;
    }

    public function test_the_replay_of_a_placed_order_still_answers_201_after_its_cart_expired_and_cart_prune_ran(): void
    {
        $variationId = $this->pricedVariation();
        $cartId = (string) $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 2])->assertStatus(201)->json('cart_id');
        $payload = [
            'cart_id' => $cartId, 'email' => 'guest@example.com', 'recipient_name' => 'Guest Buyer', 'phone' => '+359888000000',
            'payment_method' => 'cash_on_delivery', 'delivery_type' => 'street_address', 'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'Vitosha Blvd 1',
            ...$this->shippingPayload(),
        ];

        // An unrelated, expired, UNCLAIMED cart: the prune must still do its job.
        $abandoned = Cart::forGuest('token-abandoned', new DateTimeImmutable('-1 day'));
        app(CartRepository::class)->save($abandoned);

        $first = $this->postJson('/api/checkout', $payload)->assertStatus(201)->assertJsonPath('already_placed', false);

        // The claimed cart's expiry passes...
        DB::table('carts')->where('id', $cartId)->update(['expires_at' => now()->subDays(3)]);
        $this->assertNotNull(DB::table('carts')->where('id', $cartId)->value('order_id'), 'the cart is claimed');

        // ...and the prune runs: it deletes the abandoned cart and only that.
        $this->artisan('cart:prune')->expectsOutput('Pruned 1 expired cart(s).')->assertSuccessful();

        $this->assertSame(1, DB::table('carts')->count(), 'the claimed cart stays');
        $this->assertSame(0, DB::table('carts')->where('session_token', 'token-abandoned')->count());

        $replay = $this->postJson('/api/checkout', $payload)->assertStatus(201);

        $replay->assertJsonPath('already_placed', true)->assertJsonPath('order.id', $first->json('order.id'));
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(8, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity(), 'decremented once');
    }

    public function test_cart_prune_with_nothing_to_delete_reports_zero(): void
    {
        app(CartRepository::class)->save(Cart::forGuest('token-live', new DateTimeImmutable('+5 days')));

        $this->artisan('cart:prune')->expectsOutput('Pruned 0 expired cart(s).')->assertSuccessful();

        $this->assertSame(1, DB::table('carts')->count());
    }
}
