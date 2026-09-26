<?php

namespace Tests\Feature;

use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
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
 * THE REPORTED DEFECT, at the HTTP level — cart-domain-design.md §14.
 *
 * Before the fix, a claimed cart stayed the customer's current cart forever, so a
 * second purchase in the same session or account returned the FIRST order instead of
 * placing a second one. These tests are the defect's own obituary: buying twice
 * creates two orders, while a genuine double-click still returns one.
 *
 * They are separate from CheckoutControllerTest on purpose — that file tests the
 * checkout API's own contract, this one tests the LIFECYCLE of a cart across it.
 */
final class CartAfterCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;

    private ?PriceList $priceList = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost/');
    }

    private function pricedVariation(int $stock = 10): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "cart-after-checkout-{$suffix}");
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
            Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 0),
        ));

        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $stock));

        return $variationId;
    }

    /** Adds a line the way a page would, and returns the cart_id that page is now displaying. */
    private function addLine(string $variationId, int $quantity = 1): string
    {
        return (string) $this->postJson('/api/cart/lines', [
            'variation_id' => $variationId,
            'quantity' => $quantity,
        ])->assertStatus(201)->json('cart_id');
    }

    /** @return array<string, string> */
    private function payload(string $cartId): array
    {
        return [
            'cart_id' => $cartId,
            'email' => 'guest@example.com',
            'recipient_name' => 'Guest Buyer',
            'phone' => '+359888000000',
            'payment_method' => 'cash_on_delivery',
            'delivery_type' => 'street_address',
            'country' => 'BG',
            'city' => 'Sofia',
            'address_line_1' => 'Vitosha Blvd 1',
        ];
    }

    private function loggedInAccount(string $email): AccountModel
    {
        $account = Account::register($email, 'hashed-password');
        app(AccountRepository::class)->save($account);
        $model = AccountModel::findOrFail($account->id());

        $this->actingAs($model, 'customer');

        return $model;
    }

    public function test_a_guest_can_buy_twice_in_one_session_and_gets_two_orders(): void
    {
        $firstCartId = $this->addLine($this->pricedVariation(), 2);
        $firstOrder = $this->postJson('/api/checkout', $this->payload($firstCartId))->assertStatus(201);
        $firstOrder->assertJsonPath('already_placed', false);

        // The bought cart is nobody's current cart any more: the session has no live
        // cart at all until the next add — not the one that was just bought.
        $this->assertNull($this->getJson('/api/cart')->assertOk()->json('cart_id'));
        $this->assertSame([], $this->getJson('/api/cart')->assertOk()->json('lines'));

        // The second purchase: a NEW cart, reusing the same session token, and a
        // SECOND order — the reported defect, gone.
        $secondCartId = $this->addLine($this->pricedVariation(20), 1);
        $this->assertNotSame($firstCartId, $secondCartId);

        $secondOrder = $this->postJson('/api/checkout', $this->payload($secondCartId))->assertStatus(201);
        $secondOrder->assertJsonPath('already_placed', false);
        $this->assertNotSame($firstOrder->json('order.id'), $secondOrder->json('order.id'));
        $this->assertSame(2, OrderModel::count());
    }

    public function test_a_logged_in_account_can_buy_twice_in_one_session(): void
    {
        $this->loggedInAccount('twice@example.com');

        $firstCartId = $this->addLine($this->pricedVariation(), 1);
        $firstOrder = $this->postJson('/api/checkout', $this->payload($firstCartId))->assertStatus(201);

        $this->assertNull($this->getJson('/api/cart')->assertOk()->json('cart_id'));

        $secondCartId = $this->addLine($this->pricedVariation(20), 1);
        $secondOrder = $this->postJson('/api/checkout', $this->payload($secondCartId))->assertStatus(201);

        $this->assertNotSame($firstOrder->json('order.id'), $secondOrder->json('order.id'));
        $this->assertSame(2, OrderModel::count());
    }

    public function test_a_double_click_still_returns_one_order_and_charges_once(): void
    {
        $variationId = $this->pricedVariation(10);
        $cartId = $this->addLine($variationId, 3);

        $first = $this->postJson('/api/checkout', $this->payload($cartId))->assertStatus(201);
        $second = $this->postJson('/api/checkout', $this->payload($cartId))->assertStatus(201);

        $this->assertFalse($first->json('already_placed'));
        $this->assertTrue($second->json('already_placed'));
        $this->assertSame($first->json('order.id'), $second->json('order.id'));
        $this->assertNull($second->json('payment'), 'a replay never re-charges');
        $this->assertSame(1, OrderModel::count());
        $this->assertSame(7, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity());
    }

    public function test_another_customers_cart_id_never_returns_their_order(): void
    {
        $this->loggedInAccount('owner@example.com');
        $cartId = $this->addLine($this->pricedVariation(), 1);
        $orderId = (string) $this->postJson('/api/checkout', $this->payload($cartId))->assertStatus(201)->json('order.id');

        // A different customer — another account, no session token of the owner's —
        // names the owner's cart id.
        $this->loggedInAccount('stranger@example.com');

        $response = $this->postJson('/api/checkout', $this->payload($cartId));

        $response->assertStatus(404);

        // The body must not even hint at the owner's order: it is a plain "no cart
        // found" for the id that was named, and nothing else. Asserted structurally
        // rather than by id substring, because a fresh order's id ("1") can collide
        // with a cart id in the message by pure coincidence.
        $this->assertArrayNotHasKey('order', $response->json());
        $this->assertArrayNotHasKey('payment', $response->json());
        $this->assertArrayNotHasKey('already_placed', $response->json());
        $this->assertSame(1, OrderModel::count());
    }
}
