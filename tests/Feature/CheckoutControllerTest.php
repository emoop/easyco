<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Cart\Persistence\Eloquent\CartModel;
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
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Checkout HTTP surface — real MySQL, real HTTP calls end to end,
 * including cart construction (via POST /api/cart/lines, never by
 * constructing Cart objects directly), so the real session/guard
 * plumbing CheckoutController::findCurrentCart() depends on is actually
 * exercised.
 *
 * NO EMPTY-CART TEST: there is no HTTP path that leaves an existing cart
 * reachable and empty — adding a line always makes it non-empty, and the
 * only way to remove a line (DELETE /api/cart/lines/{id}) on a single-line
 * cart leaves the cart itself still present but with zero lines... but
 * CheckoutOrchestratorTest.php already covers EmptyCartException directly
 * against the domain layer; contriving an HTTP round trip here just to
 * re-prove the same 422 mapping would test the mapping, not a real gap.
 */
class CheckoutControllerTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;
    private ?PriceList $priceList = null;

    protected function setUp(): void
    {
        parent::setUp();

        // See account-domain-design.md §10 — Sanctum's stateful pipeline
        // needs a recognized Referer to engage the session at all, which
        // the guest cart_token depends on.
        $this->withHeader('Referer', 'http://localhost/');
    }

    private function variationId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    private function setPrice(string $variationId, string $decimalAmount): void
    {
        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        $item = new PriceListItem(
            null,
            $this->priceList->id(),
            PriceListItemTargetType::VARIATION,
            $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($decimalAmount, 'EUR'), 0),
        );
        app(PriceListItemRepository::class)->save($item);
    }

    private function setStock(string $variationId, int $quantity): void
    {
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $quantity));
    }

    private function pricedPurchasableVariation(string $decimalAmount = '10.00', int $stock = 10): string
    {
        $variationId = $this->variationId();
        $this->setPrice($variationId, $decimalAmount);
        $this->setStock($variationId, $stock);

        return $variationId;
    }

    private function createPromotion(
        string $code,
        ?DateTimeImmutable $validFrom = null,
        ?DateTimeImmutable $validUntil = null,
        int $percentageBasisPoints = 1000,
    ): Promotion {
        $promotion = Promotion::create(
            code: $code,
            discountType: PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: $percentageBasisPoints,
            validFrom: $validFrom,
            validUntil: $validUntil,
        );
        app(PromotionRepository::class)->save($promotion);

        return $promotion;
    }

    private function loggedInAccount(string $email = 'user@example.com'): AccountModel
    {
        $account = Account::register($email, 'hashed-password');
        app(AccountRepository::class)->save($account);
        $model = AccountModel::findOrFail($account->id());

        $this->actingAs($model, 'customer');

        return $model;
    }

    private function addLineViaHttp(string $variationId, int $quantity = 1): void
    {
        $this->postJson('/api/cart/lines', [
            'variation_id' => $variationId,
            'quantity' => $quantity,
        ])->assertStatus(201);
    }

    private function checkoutPayload(array $overrides = []): array
    {
        return array_merge([
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

    public function test_a_full_guest_checkout_with_a_fresh_address_places_a_real_order(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 2);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('already_placed', false);
        $response->assertJsonPath('order.email', 'guest@example.com');
        $response->assertJsonPath('order.subtotal.minor', 2000);
        $response->assertJsonPath('order.total.minor', 2000);
        $response->assertJsonPath('payment.method', 'cash_on_delivery');
        $response->assertJsonPath('payment.status', 'pending');

        $orderId = $response->json('order.id');
        $this->assertNotNull(OrderModel::find($orderId));
        $this->assertSame(8, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity());
    }

    public function test_a_logged_in_checkout_using_a_saved_address_id_carries_the_account_id(): void
    {
        $account = $this->loggedInAccount();
        $accountId = (string) $account->id;

        $addressResponse = $this->postJson('/api/addresses', [
            'delivery_type' => 'street_address',
            'recipient_name' => 'Ivan Ivanov',
            'phone' => '+359888111222',
            'country' => 'BG',
            'city' => 'Sofia',
            'address_line_1' => 'Graf Ignatiev 5',
        ]);
        $addressResponse->assertStatus(201);
        $addressId = $addressResponse->json('id');

        $variationId = $this->pricedPurchasableVariation('15.00', 5);
        $this->addLineViaHttp($variationId, 1);

        $response = $this->postJson('/api/checkout', [
            'email' => $account->email,
            'recipient_name' => 'Ivan Ivanov',
            'phone' => '+359888111222',
            'payment_method' => 'bank_transfer',
            'address_id' => $addressId,
        ]);

        $response->assertStatus(201);
        $orderId = $response->json('order.id');
        $order = OrderModel::findOrFail($orderId);
        $this->assertSame($accountId, (string) $order->account_id);
        $this->assertSame((string) $addressId, (string) $order->address_id);
    }

    public function test_checkout_with_no_cart_returns_404(): void
    {
        $response = $this->postJson('/api/checkout', $this->checkoutPayload());

        $response->assertStatus(404);
    }

    public function test_double_submit_returns_201_twice_with_the_second_marked_already_placed(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 3);

        $payload = $this->checkoutPayload();

        $first = $this->postJson('/api/checkout', $payload);
        $second = $this->postJson('/api/checkout', $payload);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $first->assertJsonPath('already_placed', false);
        $second->assertJsonPath('already_placed', true);
        $second->assertJsonPath('payment', null);
        $this->assertSame($first->json('order.id'), $second->json('order.id'));
        $this->assertSame(1, OrderModel::count());
    }

    public function test_an_expired_promotion_code_returns_422_and_creates_no_order(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);
        $this->createPromotion('EXPIRED10', validUntil: new DateTimeImmutable('2020-01-01'));
        $this->putJson('/api/cart/promotion', ['code' => 'EXPIRED10'])->assertStatus(200);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload());

        $response->assertStatus(422);
        $response->assertJsonPath('reason', 'promotion_no_longer_valid');
        $this->assertSame(0, OrderModel::count());
    }

    public function test_insufficient_stock_at_checkout_returns_409_and_creates_no_order(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 5);
        $this->addLineViaHttp($variationId, 5);

        // Sold out by another concurrent transaction after add-to-cart.
        $this->setStock($variationId, 2);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload());

        $response->assertStatus(409);
        $response->assertJsonPath('reason', 'insufficient_stock');
        $this->assertSame(0, OrderModel::count());
    }

    /**
     * Deliberately different from CheckoutOrchestratorTest's own
     * equivalent test, which still asserts the Order survives: that one
     * exercises the orchestrator directly, where the Phase-1/Phase-2
     * boundary genuinely does leave the order standing. Both are
     * correct for their own layer — the controller's job is specifically
     * to make sure real HTTP traffic never reaches that state, by
     * rejecting an unknown payment_method BEFORE Phase 1 ever runs. An
     * unknown method reaching place() itself would leave a real,
     * unpayable Order behind (committed, no Payment, cart claimed) that
     * a retry could never fix, since a retry would just hit the
     * idempotent-replay path. This test proves that hole is closed.
     */
    public function test_an_unknown_payment_method_returns_422_with_nothing_committed(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload(['payment_method' => 'bitcoin']));

        $response->assertStatus(422);
        $response->assertJsonPath('reason', 'unknown_payment_method');

        $this->assertSame(0, OrderModel::count());
        $this->assertSame(10, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity());

        $cartId = (string) CartModel::firstOrFail()->id;
        $this->assertNull(app(CartRepository::class)->findOrderIdForCart($cartId));
    }

    public function test_a_checkout_rejected_for_an_unknown_payment_method_is_genuinely_retryable(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $rejected = $this->postJson('/api/checkout', $this->checkoutPayload(['payment_method' => 'bitcoin']));
        $rejected->assertStatus(422);

        $retried = $this->postJson('/api/checkout', $this->checkoutPayload(['payment_method' => 'cash_on_delivery']));

        $retried->assertStatus(201);
        $retried->assertJsonPath('already_placed', false);
        $this->assertNotNull($retried->json('payment'));
        $this->assertSame(1, OrderModel::count());
    }

    public function test_missing_email_returns_422(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $payload = $this->checkoutPayload();
        unset($payload['email']);

        $this->postJson('/api/checkout', $payload)->assertStatus(422);
    }

    public function test_address_id_together_with_delivery_type_returns_422(): void
    {
        $account = $this->loggedInAccount();

        $addressResponse = $this->postJson('/api/addresses', [
            'delivery_type' => 'street_address',
            'recipient_name' => 'Ivan Ivanov',
            'phone' => '+359888111222',
            'country' => 'BG',
            'city' => 'Sofia',
            'address_line_1' => 'Graf Ignatiev 5',
        ]);
        $addressId = $addressResponse->json('id');

        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $response = $this->postJson('/api/checkout', [
            'email' => $account->email,
            'recipient_name' => 'Ivan Ivanov',
            'phone' => '+359888111222',
            'payment_method' => 'cash_on_delivery',
            'address_id' => $addressId,
            'delivery_type' => 'street_address',
            'country' => 'BG',
            'city' => 'Sofia',
            'address_line_1' => 'Graf Ignatiev 5',
        ]);

        $response->assertStatus(422);
    }
}
