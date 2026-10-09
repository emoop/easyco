<?php

namespace Tests\Feature\Sandbox;

use App\Services\PaymentMethodAdapterResolver;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\Order\Contracts\OrderRepository;
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
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingMethod;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\ProvidesCheckoutShipping;
use Tests\TestCase;

/**
 * THE END-TO-END FLOW, over real HTTP: a sandbox page load that starts the session,
 * then the four API calls the sandbox's own JavaScript makes in that order —
 * add a line, read the cart, apply a promotion, check out — ending at a real Order
 * with a real §3.13 sale-line snapshot and real stock movement.
 *
 * WHAT MAKES THIS DIFFERENT FROM CartControllerTest/CheckoutControllerTest: those
 * assert the API on its own; this one starts from a SANDBOX PAGE and never leaves
 * the session it establishes. The cart here is identified the way a guest's cart
 * really is (the session's own cart_token, established by the page load), and the
 * requests carry the same Referer and CSRF header the page's fetch() sends.
 *
 * ON THAT CSRF HEADER, HONESTLY: in this environment the framework disables CSRF
 * verification for PHPUnit runs (VerifyCsrfToken::runningUnitTests()), so the
 * header here is sent to mirror the browser rather than to be what makes the
 * request succeed. The enforcement itself is asserted where it can be asserted —
 * see SandboxCartApiCsrfTest, which boots a production application for exactly
 * that reason.
 */
final class SandboxCheckoutFlowTest extends TestCase
{
    use RefreshDatabase;
    use ProvidesCheckoutShipping;

    private static int $counter = 0;

    private ?PriceList $priceList = null;

    /** The cart the page displayed for this test's flow — cart-domain-design.md §14.2. */
    private string $cartId = '';

    /**
     * PUBLIC, matching Illuminate\Foundation\Testing\TestCase's own (public)
     * signature — narrowing it to protected is a fatal error. The flag must be a
     * real process-environment value BEFORE the container is built, because the
     * sandbox's routes are registered once, at boot (see SandboxTestEnvironment).
     *
     * @return Application
     */
    public function createApplication()
    {
        SandboxTestEnvironment::enableSandboxFlag();

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        SandboxTestEnvironment::restoreSandboxFlag();
    }

    /** The session token every request in a real browser would echo back. */
    private function sessionToken(): string
    {
        return (string) $this->app['session.store']->token();
    }

    /**
     * A published SIMPLE product priced at 10.00 EUR with real stock.
     *
     * @return array{variation_id: string, product_id: string, name: string, sku: string}
     */
    private function pricedStockedProduct(int $stock = 5): array
    {
        self::$counter++;
        $suffix = (string) self::$counter;
        $name = "Product {$suffix}";
        $sku = "SKU-{$suffix}";

        $product = Product::createSimple($name, $sku, "sandbox-flow-{$suffix}");
        $product->setCatalogVisibility(CatalogVisibility::VISIBLE);
        $variation = $product->variations()[0];
        $variation->activate();
        $product->publish();
        app(ProductRepository::class)->save($product);

        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        app(PriceListItemRepository::class)->save(new PriceListItem(
            null,
            $this->priceList->id(),
            PriceListItemTargetType::VARIATION,
            $variation->id(),
            Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 0),
        ));

        app(StockLevelRepository::class)->save(StockLevel::forVariation($variation->id(), $stock));

        return [
            'variation_id' => $variation->id(),
            'product_id' => (string) $product->id(),
            'name' => $name,
            'sku' => $sku,
        ];
    }

    /** A 10% promotion with no scopes — valid for every line, as CartControllerTest's own happy path does it. */
    private function promotion(string $code): void
    {
        app(PromotionRepository::class)->save(Promotion::create(
            code: $code,
            discountType: PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 1000,
            validFrom: null,
            validUntil: null,
        ));
    }

    /** @return array<string, string> */
    private function checkoutPayload(array $overrides = []): array
    {
        return array_merge([
            // The cart this browser is confirming — REQUIRED since
            // cart-domain-design.md §14.2, and the only thing that can answer a
            // replay once the cart stops being the current one.
            'cart_id' => $this->cartId,
            'email' => 'guest@example.com',
            'recipient_name' => 'Guest Buyer',
            'phone' => '+359888000000',
            // The method the checkout page itself offers — never a hardcoded code.
            'payment_method' => app(PaymentMethodAdapterResolver::class)->availableMethods()[0],
            'delivery_type' => 'street_address',
            'country' => 'BG',
            'city' => 'Sofia',
            'address_line_1' => 'Vitosha Blvd 1',
            ...$this->shippingPayload(),
        ], $overrides);
    }

    /**
     * A priced FLAT method in the SAME zone the trait's free methods use, so the
     * quote resolves one zone and the delivery has a non-zero price to assert on.
     */
    private function pricedDeliveryMethod(bool $pickup, int $amountMinor = 500): string
    {
        $zoneId = app(ShippingMethodRepository::class)->findById($this->shippingMethodId(false))->zoneId();

        $method = ShippingMethod::create(
            $zoneId,
            $pickup ? 'Paid pickup' : 'Paid delivery',
            ShippingMethodKind::FLAT,
            sortOrder: 10,
            amountMinor: $amountMinor,
            requiresPickupPoint: $pickup,
        );
        app(ShippingMethodRepository::class)->save($method);

        return (string) $method->id();
    }

    /** A real page load (session + cart identity), then the cart line the product page's own control adds. */
    private function openSessionAndAddLine(array $product, int $quantity): void
    {
        $this->get(route('sandbox.products.show', ['productId' => $product['product_id']]))->assertOk();
        $this->withHeader('Referer', 'http://localhost/')->withHeader('X-CSRF-TOKEN', $this->sessionToken());

        $this->cartId = (string) $this->postJson('/api/cart/lines', [
            'variation_id' => $product['variation_id'],
            'quantity' => $quantity,
        ])->assertStatus(201)->json('cart_id');
    }

    /** @return array<string, mixed> the quote endpoint's whole body, as the button's fetch reads it */
    private function shippingQuote(string $deliveryType, string $settlement): array
    {
        return $this->postJson('/api/shipping/quote', [
            'delivery_type' => $deliveryType,
            'country' => 'BG',
            'settlement' => $settlement,
        ])->assertOk()->json();
    }

    /** The quote's own method, looked up by id the way the page picks one from the response. @return array<string, mixed> */
    private function offeredMethod(array $methods, string $methodId): array
    {
        foreach ($methods as $method) {
            if ($method['id'] === $methodId) {
                return $method;
            }
        }

        throw new RuntimeException("The quote did not offer method {$methodId}.");
    }

    public function test_a_sandbox_visitor_can_add_a_line_apply_a_promotion_and_place_a_real_order(): void
    {
        $product = $this->pricedStockedProduct(5);
        $this->promotion('DEMO10');

        // 1. A REAL PAGE LOAD from the sandbox — what gives a guest browser its
        //    session, and therefore the cart identity the API reads.
        $page = $this->get(route('sandbox.products.show', ['productId' => $product['product_id']]))->assertOk();
        $this->assertNotEmpty($page->headers->getCookies(), 'the page load must issue the session cookie');

        // The two headers the sandbox's own JavaScript sends on every write.
        $this->withHeader('Referer', 'http://localhost/')
            ->withHeader('X-CSRF-TOKEN', $this->sessionToken());

        // 2. Add to cart, exactly as the product page's add-to-cart control does.
        $this->cartId = (string) $this->postJson('/api/cart/lines', [
            'variation_id' => $product['variation_id'],
            'quantity' => 2,
        ])->assertStatus(201)->json('cart_id');

        // 3. The cart page's own read: the display fields it renders must be there.
        $cart = $this->getJson('/api/cart')->assertOk();
        $cart->assertJsonPath('lines.0.product_name', $product['name']);
        $cart->assertJsonPath('lines.0.sku', $product['sku']);
        $cart->assertJsonPath('lines.0.attributes', []);
        $cart->assertJsonPath('lines.0.quantity', 2);
        $this->assertSame(2000, $cart->json('total.minor'));

        // 4. The promotion the merchant seeded, applied through the cart page's own control.
        $this->putJson('/api/cart/promotion', ['code' => 'DEMO10'])
            ->assertStatus(200)
            ->assertJsonPath('promotion.valid', true);
        $this->assertSame(1800, $this->getJson('/api/cart')->assertOk()->json('total.minor'));

        // 5. Checkout, with the payment method the checkout page itself offers.
        $response = $this->postJson('/api/checkout', $this->checkoutPayload())->assertStatus(201);
        $response->assertJsonPath('already_placed', false);
        $response->assertJsonPath('order.lines.0.quantity', 2);
        $response->assertJsonPath('order.lines.0.final_unit_price.minor', 1000);
        $response->assertJsonPath('order.lines.0.promotion_discount_share.minor', 200);
        $response->assertJsonPath('order.lines.0.net_paid_amount.minor', 1800);
        $response->assertJsonPath('payment.method', app(PaymentMethodAdapterResolver::class)->availableMethods()[0]);

        // A customer-facing response never carries the shop's cost basis.
        $this->assertStringNotContainsString('cost', $response->getContent());
        $this->assertStringNotContainsString('profit', $response->getContent());

        $orderId = $response->json('order.id');
        $this->assertNotNull($orderId);

        // 6. THE §3.13 SNAPSHOT, READ BACK FROM THE DATABASE — through the order's own
        //    transaction, not through the response body asserted above.
        $order = app(OrderRepository::class)->findById($orderId);
        $transaction = app(TransactionRepository::class)->findByIdWithSaleLines($order->transactionId());

        $this->assertCount(1, $transaction->saleLines());
        $line = $transaction->saleLines()[0];
        $this->assertSame($product['name'], $line->productName());
        $this->assertSame($product['sku'], $line->sku());
        $this->assertSame(2, $line->quantity());
        $this->assertSame([], $line->soldAttributes());
        $this->assertSame(1000, $line->finalUnitPrice()->minorValue());
        $this->assertSame(1000, $line->regularUnitPrice()->minorValue());
        $this->assertSame(200, $line->promotionDiscountShare()->minorValue());
        $this->assertSame(1800, $line->netPaidAmount()->minorValue());

        // 7. Real stock movement.
        $this->assertSame(3, app(StockLevelRepository::class)->findByVariationId($product['variation_id'])->quantity());

        // 8. THE DEFECT IS GONE, AND THE NEXT PURCHASE WORKS: the claimed cart is no
        //    longer this session's current cart at all, so the next add starts a NEW
        //    one — reusing the very same session cart_token (cart-domain-design.md
        //    §14.1) — while a late replay naming the OLD cart still returns the first
        //    order instead of placing a second one (§14.2).
        $this->assertSame([], $this->getJson('/api/cart')->assertOk()->json('lines'));
        $this->assertNull($this->getJson('/api/cart')->assertOk()->json('cart_id'));

        $secondProduct = $this->pricedStockedProduct(9);
        $secondCartId = (string) $this->postJson('/api/cart/lines', [
            'variation_id' => $secondProduct['variation_id'],
            'quantity' => 1,
        ])->assertStatus(201)->json('cart_id');

        $this->assertNotSame($this->cartId, $secondCartId, 'the next add must start a new cart');
        $this->assertSame(
            [$secondProduct['name']],
            array_column($this->getJson('/api/cart')->assertOk()->json('lines'), 'product_name'),
            'the new cart holds the new line and nothing that was already bought'
        );

        $lateReplay = $this->postJson('/api/checkout', $this->checkoutPayload(['cart_id' => $this->cartId]))
            ->assertStatus(201);
        $this->assertTrue($lateReplay->json('already_placed'));
        $this->assertSame($orderId, $lateReplay->json('order.id'));
    }

    /**
     * The street-address path the page's JavaScript now performs, over real HTTP and
     * in the same order: add a line, quote, pick a method FROM THE QUOTE, check out.
     */
    public function test_the_checkout_flow_quotes_a_street_delivery_and_places_the_order_with_it(): void
    {
        $product = $this->pricedStockedProduct(5);
        $this->openSessionAndAddLine($product, 2);

        $methodId = $this->pricedDeliveryMethod(false, 500);

        // 1. "Show delivery options": the quote the page's own button sends.
        $quote = $this->shippingQuote('street_address', 'Sofia');
        $goods = $quote['goods_after_discount']['minor'];
        $this->assertSame(2000, $goods);

        $method = $this->offeredMethod($quote['methods'], $methodId);
        $this->assertTrue($method['available']);
        $this->assertSame(500, $method['price']['minor']);

        // 2. Checkout with the method id, handle and shown price taken FROM THE QUOTE.
        $response = $this->postJson('/api/checkout', $this->checkoutPayload([
            'shipping_method_id' => $method['id'],
            'quote_handle' => $method['handle'],
            'expected_shipping_minor' => $method['price']['minor'],
        ]))->assertStatus(201);

        $response->assertJsonPath('shipping.method_id', $method['id'])
            ->assertJsonPath('shipping.method_name', $method['name'])
            ->assertJsonPath('shipping.amount_minor', 500)
            ->assertJsonPath('shipping.currency', 'EUR')
            ->assertJsonPath('order.total.minor', $goods + 500)
            ->assertJsonPath('payment.amount.minor', $goods + 500);
    }

    /** The pickup-point path: the office the customer typed is required and stored. */
    public function test_the_checkout_flow_quotes_a_pickup_point_and_places_the_order_with_the_office(): void
    {
        $product = $this->pricedStockedProduct(5);
        $this->openSessionAndAddLine($product, 1);

        $methodId = $this->pricedDeliveryMethod(true, 350);

        $quote = $this->shippingQuote('pickup_point', 'Sofia');
        $goods = $quote['goods_after_discount']['minor'];

        $method = $this->offeredMethod($quote['methods'], $methodId);
        $this->assertTrue($method['requires_pickup_point']);
        $this->assertSame(350, $method['price']['minor']);

        // A pickup order carries no street fields at all; the office is named.
        $payload = $this->checkoutPayload([
            'delivery_type' => 'pickup_point',
            'carrier_code' => 'econt',
            'pickup_point_reference' => 'office-1',
            'pickup_point_name' => 'Econt office Center',
            'pickup_point_address' => 'Vitosha Blvd 100, Sofia',
            'settlement' => 'Sofia',
            'shipping_method_id' => $method['id'],
            'quote_handle' => $method['handle'],
            'expected_shipping_minor' => $method['price']['minor'],
        ]);
        unset($payload['city'], $payload['address_line_1']);

        $response = $this->postJson('/api/checkout', $payload)->assertStatus(201);

        $response->assertJsonPath('shipping.method_id', $method['id'])
            ->assertJsonPath('shipping.amount_minor', 350)
            ->assertJsonPath('order.pickup_point_name', 'Econt office Center')
            ->assertJsonPath('order.pickup_point_address', 'Vitosha Blvd 100, Sofia')
            ->assertJsonPath('order.total.minor', $goods + 350);
    }

    /** A price changed between the quote and the checkout: the refusal names the new figure. */
    public function test_the_checkout_flow_refuses_a_stale_delivery_price_and_reports_the_new_one(): void
    {
        $product = $this->pricedStockedProduct(5);
        $this->openSessionAndAddLine($product, 1);

        $methodId = $this->pricedDeliveryMethod(false, 500);

        $quote = $this->shippingQuote('street_address', 'Sofia');
        $method = $this->offeredMethod($quote['methods'], $methodId);
        $this->assertSame(500, $method['price']['minor']);

        // The merchant changes the price after the quote — the customer still holds
        // the old handle and the old figure.
        DB::table('shipping_methods')->where('id', $methodId)->update(['amount_minor' => 650]);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload([
            'shipping_method_id' => $method['id'],
            'quote_handle' => $method['handle'],
            'expected_shipping_minor' => $method['price']['minor'],
        ]))->assertStatus(409)->assertJsonPath('reason', 'shipping_price_changed');

        // The refusal carries the new price the page prints ("New delivery price: …").
        $response->assertJsonPath('price', ['minor' => 650, 'currency' => 'EUR']);

        // Nothing was placed.
        $this->assertNull(DB::table('carts')->where('id', $this->cartId)->value('order_id'));
        $this->assertSame(0, DB::table('orders')->count());
    }
}
