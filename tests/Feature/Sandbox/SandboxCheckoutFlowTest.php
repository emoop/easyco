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
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private static int $counter = 0;

    private ?PriceList $priceList = null;

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
    private function checkoutPayload(): array
    {
        return [
            'email' => 'guest@example.com',
            'recipient_name' => 'Guest Buyer',
            'phone' => '+359888000000',
            // The method the checkout page itself offers — never a hardcoded code.
            'payment_method' => app(PaymentMethodAdapterResolver::class)->availableMethods()[0],
            'delivery_type' => 'street_address',
            'country' => 'BG',
            'city' => 'Sofia',
            'address_line_1' => 'Vitosha Blvd 1',
        ];
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
        $this->postJson('/api/cart/lines', [
            'variation_id' => $product['variation_id'],
            'quantity' => 2,
        ])->assertStatus(201);

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

        // 8. CURRENT, DEFECTIVE BEHAVIOUR, PINNED ON PURPOSE — NOT A REQUIREMENT.
        //    Checkout CLAIMS the cart but does not clear it, so the claimed cart stays
        //    the customer's current cart and a second checkout in the same session
        //    returns the FIRST order instead of creating a new one. Tracked as a
        //    separate Cart task (see sandbox-manual-test-checklist.md, scenario 10);
        //    the two assertions below exist so that fixing it means changing them
        //    deliberately rather than discovering them.
        $this->assertCount(1, $this->getJson('/api/cart')->assertOk()->json('lines'));

        $replay = $this->postJson('/api/checkout', $this->checkoutPayload())->assertStatus(201);
        $this->assertTrue($replay->json('already_placed'));
        $this->assertSame($orderId, $replay->json('order.id'));
    }
}
