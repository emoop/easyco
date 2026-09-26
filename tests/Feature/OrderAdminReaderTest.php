<?php

namespace Tests\Feature;

use App\Services\CheckoutInput;
use App\Services\CheckoutOrchestrator;
use App\Services\OrderAdminReader;
use DateTimeImmutable;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLineAdder;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Exercises OrderAdminReader directly against real orders — placed only
 * through CheckoutOrchestrator (§3's own requirement), never hand-built
 * rows. Fixture helpers mirror CheckoutOrchestratorTest's own established
 * shapes (same product/price/stock/promotion construction), duplicated
 * rather than shared, per that file's own precedent for
 * CartControllerTest.
 */
class OrderAdminReaderTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;
    private ?PriceList $priceList = null;

    private function variationId(?string $namePrefix = null): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;
        $name = ($namePrefix ?? 'Product').' '.$suffix;

        $product = Product::createSimple($name, "SKU-{$suffix}", "product-slug-{$suffix}");
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

    private function pricedPurchasableVariation(string $decimalAmount = '10.00', int $stock = 10, ?string $namePrefix = null): string
    {
        $variationId = $this->variationId($namePrefix);
        $this->setPrice($variationId, $decimalAmount);
        $this->setStock($variationId, $stock);

        return $variationId;
    }

    private function guestCart(): Cart
    {
        return Cart::forGuest((string) Str::uuid(), new DateTimeImmutable('+10 days'));
    }

    private function addLine(Cart $cart, string $variationId, int $quantity): void
    {
        app(CartLineAdder::class)->addLine($cart, $variationId, $quantity, null, null);
    }

    private function createPromotion(string $code, int $percentageBasisPoints = 1000): Promotion
    {
        $promotion = Promotion::create(
            code: $code,
            discountType: PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: $percentageBasisPoints,
        );
        app(PromotionRepository::class)->save($promotion);

        return $promotion;
    }

    /** @return array{0: \EasyCo\Order\Order, 1: string} [order, variationId] */
    private function placeGuestOrder(array $overrides = []): array
    {
        $variationId = $this->pricedPurchasableVariation(
            $overrides['price'] ?? '10.00',
            $overrides['stock'] ?? 10,
            $overrides['namePrefix'] ?? null
        );
        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, $overrides['quantity'] ?? 1);

        if (isset($overrides['promotionCode'])) {
            $cart->applyPromotionCode($overrides['promotionCode']);
            app(CartRepository::class)->save($cart);
        }

        // array_key_exists(), not ?? — an override explicitly set to
        // null (e.g. clearing 'country' for a pickup-point order) must
        // actually apply as null, not silently fall back to the
        // street-address default the way ?? would treat it.
        $get = fn (string $key, mixed $default): mixed => array_key_exists($key, $overrides) ? $overrides[$key] : $default;

        $input = new CheckoutInput(
            cartId: $cart->id(),
            guestCartToken: app(CartRepository::class)->findById($cart->id())?->sessionToken(),
            email: $get('email', 'guest@example.com'),
            recipientName: $get('recipientName', 'Guest Buyer'),
            phone: $get('phone', '+359888000000'),
            paymentMethod: $get('paymentMethod', 'cash_on_delivery'),
            deliveryType: $get('deliveryType', AddressDeliveryType::STREET_ADDRESS),
            country: $get('country', 'BG'),
            city: $get('city', 'Sofia'),
            postalCode: $get('postalCode', null),
            addressLine1: $get('addressLine1', 'Vitosha Blvd 1'),
            carrierCode: $get('carrierCode', null),
            pickupPointReference: $get('pickupPointReference', null),
            settlement: $get('settlement', null),
        );

        $result = app(CheckoutOrchestrator::class)->place($input, $overrides['placedAt'] ?? new DateTimeImmutable('2026-09-20 10:00:00'));

        return [$result->order(), $variationId];
    }

    /**
     * Replaces the removed applyListAggregates() test: the list's only
     * reader-side read now is the payment-method filter (D3) — its option
     * list AND its condition. The options are only methods that are some
     * order's LATEST payment, so every offered option matches at least one
     * order: a method that only ever appeared on a superseded (earlier)
     * attempt is deliberately not offered.
     */
    public function test_the_payment_method_filter_options_are_only_methods_that_are_an_orders_latest_payment(): void
    {
        [$first] = $this->placeGuestOrder(['email' => 'first@example.com']);
        [$second] = $this->placeGuestOrder(['email' => 'second@example.com']);

        $reader = app(OrderAdminReader::class);

        // Both orders' latest payment is the checkout's own method.
        $this->assertSame(['cash_on_delivery'], $reader->paymentMethodOptions());

        // Supersede ONE order with a different method — a real, NEW payment
        // row, per payment-domain-design.md §1's "a retry is a NEW row".
        $latest = Payment::create($first->id(), 'bank_transfer', $first->total(), PaymentStatus::PENDING);
        $latest->recordAttemptResult(PaymentStatus::CAPTURED, 'ref-latest', null, new DateTimeImmutable('+1 hour'));
        app(PaymentRepository::class)->save($latest);

        // Both are now somebody's latest payment.
        $this->assertSame(['bank_transfer', 'cash_on_delivery'], $reader->paymentMethodOptions());

        // Supersede the second order too: cash_on_delivery now exists as a
        // payment row, but is nobody's latest — so it is no longer offered.
        $secondLatest = Payment::create($second->id(), 'bank_transfer', $second->total(), PaymentStatus::PENDING);
        $secondLatest->recordAttemptResult(PaymentStatus::CAPTURED, 'ref-second', null, new DateTimeImmutable('+2 hours'));
        app(PaymentRepository::class)->save($secondLatest);

        $this->assertSame(['bank_transfer'], $reader->paymentMethodOptions());

        $this->assertSame(2, $reader->applyLatestPaymentMethodFilter(OrderModel::query(), 'bank_transfer')->count());
        $this->assertSame(0, $reader->applyLatestPaymentMethodFilter(OrderModel::query(), 'cash_on_delivery')->count());
    }

    public function test_for_order_shows_the_most_recent_payment_and_a_real_attempt_count(): void
    {
        [$order] = $this->placeGuestOrder();

        // Two extra retry attempts, via the real Payment domain API —
        // not hand-built rows, matching payment-domain-design.md §1's
        // own "a retry is a NEW row" rule. Timestamps relative to the
        // real "now" (not a fixed past date like the order's own
        // placedAt) — the original order's own Payment is recorded via
        // Phase 2's real new DateTimeImmutable() at actual test-run
        // time, so a fixed past date here would not reliably sort
        // after it.
        $failed = Payment::create($order->id(), 'cash_on_delivery', $order->total(), PaymentStatus::PENDING);
        $failed->recordAttemptResult(PaymentStatus::FAILED, null, 'declined', new DateTimeImmutable('+1 hour'));
        app(PaymentRepository::class)->save($failed);

        $latest = Payment::create($order->id(), 'cash_on_delivery', $order->total(), PaymentStatus::PENDING);
        $latest->recordAttemptResult(PaymentStatus::CAPTURED, 'ref-123', null, new DateTimeImmutable('+2 hours'));
        app(PaymentRepository::class)->save($latest);

        $view = app(OrderAdminReader::class)->forOrder($order->id());
        $this->assertNotNull($view->latestPayment);
        $this->assertSame('captured', $view->latestPayment->status()->value);
        $this->assertSame('ref-123', $view->latestPayment->providerReference());
        $this->assertSame(3, $view->paymentAttemptCount);
    }

    /**
     * The list's filter adds a NESTED condition, never another round trip —
     * so a filtered page still costs exactly one query, whatever the number
     * of orders it returns. Replaces the removed applyListAggregates()
     * query-count test (there is no aggregate select list any more).
     */
    public function test_query_count_for_the_list_filter_is_independent_of_row_count(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->placeGuestOrder(['email' => "buyer{$i}@example.com"]);
        }

        $reader = app(OrderAdminReader::class);
        $ids = OrderModel::orderBy('id')->pluck('id')->all();

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        // The filter is active in both measurements — the only variable is
        // the row count.
        $reader->applyLatestPaymentMethodFilter(OrderModel::query(), 'cash_on_delivery')
            ->whereIn('id', array_slice($ids, 0, 5))
            ->get();
        $queriesForFive = $count;
        $count = 0;

        $reader->applyLatestPaymentMethodFilter(OrderModel::query(), 'cash_on_delivery')->get();
        $queriesForTwentyFive = $count;
        $count = 0;

        // The option list behind that filter is ONE query too, however many
        // orders exist (distinct latest methods, collapsed in the database).
        $reader->paymentMethodOptions();
        $queriesForOptions = $count;

        DB::flushQueryLog();

        fwrite(STDERR, "\n[query-count] orders list filter, 5 rows: {$queriesForFive} queries, 25 rows: {$queriesForTwentyFive} queries; payment-method options: {$queriesForOptions}\n");

        $this->assertSame(1, $queriesForFive, 'the filter is a nested condition inside ONE query — one real query per page load');
        $this->assertSame($queriesForFive, $queriesForTwentyFive, 'query count must not grow with the number of rows on the page');
        $this->assertSame(1, $queriesForOptions, 'the filter option list is ONE query regardless of how many orders exist');
    }

    public function test_for_order_returns_the_full_snapshot_for_an_order_with_a_promotion_and_a_street_address(): void
    {
        $promotion = $this->createPromotion('save10');
        [$order, $variationId] = $this->placeGuestOrder([
            'price' => '20.00',
            'quantity' => 2,
            'promotionCode' => 'save10',
            'city' => 'Plovdiv',
            'addressLine1' => 'Main St 5',
        ]);

        $view = app(OrderAdminReader::class)->forOrder($order->id());

        $this->assertNotNull($view);
        $this->assertSame($order->id(), $view->order->id());
        $this->assertSame('Guest Buyer', $view->clientName);
        $this->assertSame('web', $view->channel);
        $this->assertTrue($view->hasPromotionRedemption);
        $this->assertSame('save10', $view->order->appliedPromotionCode());
        $this->assertGreaterThan(0, $view->order->discount()->minorValue());

        $this->assertCount(1, $view->lines);
        $line = $view->lines[0];
        $this->assertNotNull($line->productName);
        $this->assertNotNull($line->sku);
        $this->assertSame(2, $line->quantity);
        $this->assertSame(4000, $line->lineTotal->minorValue());
        $this->assertNotNull($line->unitPrice);
        $this->assertSame(2000, $line->unitPrice->minorValue());

        $this->assertNotNull($view->latestPayment);
        $this->assertSame(1, $view->paymentAttemptCount);
    }

    public function test_for_order_fail_softs_a_minimal_pickup_point_order_with_no_promotion(): void
    {
        [$order] = $this->placeGuestOrder([
            'deliveryType' => AddressDeliveryType::PICKUP_POINT,
            'country' => null,
            'city' => null,
            'addressLine1' => null,
            'carrierCode' => 'econt',
            'pickupPointReference' => 'EC-123',
            'settlement' => 'Office 1',
        ]);

        $view = app(OrderAdminReader::class)->forOrder($order->id());

        $this->assertNotNull($view);
        $this->assertFalse($view->hasPromotionRedemption);
        $this->assertNull($view->order->appliedPromotionCode());
        $this->assertNull($view->order->country());
        $this->assertNull($view->order->addressLine1());
        $this->assertSame('EC-123', $view->order->pickupPointReference());
        $this->assertNotNull($view->latestPayment);
    }

    public function test_for_order_returns_null_for_an_unknown_id(): void
    {
        $this->assertNull(app(OrderAdminReader::class)->forOrder('999999'));
    }

    /**
     * forOrder()'s $lines read uses raw DB::table(), not Eloquent —
     * SaleLineModel's own SoftDeletes global scope never applies to it, so
     * a soft-deleted SALE line (never hard-deleted per design doc §3.2/
     * SaleLineModel's own docblock — a correction is a future new row, not
     * an in-place rewrite) had to be excluded explicitly or it would keep
     * rendering after being "removed". TWO lines, only ONE soft-deleted —
     * proves exactly the deleted line drops out and the surviving one is
     * untouched, not just that "the list goes empty" (which a broken query
     * that excluded everything would also satisfy).
     */
    public function test_a_soft_deleted_sale_line_is_excluded_while_the_surviving_line_remains(): void
    {
        $variationA = $this->pricedPurchasableVariation('10.00', 10, 'Line A');
        $variationB = $this->pricedPurchasableVariation('20.00', 10, 'Line B');

        $cart = $this->guestCart();
        $this->addLine($cart, $variationA, 1);
        $this->addLine($cart, $variationB, 2);

        $input = new CheckoutInput(
            cartId: $cart->id(),
            guestCartToken: app(CartRepository::class)->findById($cart->id())?->sessionToken(),
            email: 'guest@example.com',
            recipientName: 'Guest Buyer',
            phone: '+359888000000',
            paymentMethod: 'cash_on_delivery',
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );

        $order = app(CheckoutOrchestrator::class)->place($input, new DateTimeImmutable('2026-09-20 10:00:00'))->order();

        $lineAId = DB::table('operational_sales_sale_lines')
            ->where('transaction_id', $order->transactionId())
            ->where('priceable_id', $variationA)
            ->value('id');

        DB::table('operational_sales_sale_lines')->where('id', $lineAId)->update(['deleted_at' => now()]);

        $view = app(OrderAdminReader::class)->forOrder($order->id());
        $this->assertCount(1, $view->lines);
        $this->assertStringContainsString('Line B', $view->lines[0]->productName);
    }

    /**
     * §3's own mandatory snapshot test: renaming the product, changing
     * its SKU and its price AFTER the order was placed must not change
     * anything this reader returns — D2's whole point.
     */
    public function test_snapshot_survives_a_later_product_rename_sku_change_and_price_change(): void
    {
        $variationId = $this->pricedPurchasableVariation('25.00', 10, 'Original Name');
        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, 1);

        $input = new CheckoutInput(
            cartId: $cart->id(),
            guestCartToken: app(CartRepository::class)->findById($cart->id())?->sessionToken(),
            email: 'guest@example.com',
            recipientName: 'Guest Buyer',
            phone: '+359888000000',
            paymentMethod: 'cash_on_delivery',
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );

        $order = app(CheckoutOrchestrator::class)->place($input, new DateTimeImmutable('2026-09-20 10:00:00'))->order();

        $lineBefore = app(OrderAdminReader::class)->forOrder($order->id())->lines[0];

        // Rename the Product, re-SKU its Variation, re-price it — all
        // through the real repository, same as any admin edit would.
        $productId = (string) VariationModel::find($variationId)->product_id;
        $product = app(ProductRepository::class)->findByIdWithVariations($productId);
        $product->rename('Renamed Product');
        $product->variations()[0]->setSku('SKU-RENAMED-999');
        app(ProductRepository::class)->save($product);

        $this->setPrice($variationId, '99.00');

        // A FRESH reader, not app() — OrderAdminReader::forOrder() is
        // now memoized per instance (see its own docblock), and
        // app(OrderAdminReader::class) within this same test method
        // still resolves the SAME scoped() instance from the "before"
        // call above; only a real new object forces a genuine re-read,
        // which is the actual point of this test (proving the
        // UNDERLYING DATA is stable, not merely that a cached object is
        // == itself).
        $freshReader = new \App\Services\OrderAdminReader(
            app(\EasyCo\Order\Contracts\OrderRepository::class),
            app(\EasyCo\OperationalSales\Contracts\ClientRepository::class),
            app(\EasyCo\Payment\Contracts\PaymentRepository::class),
        );

        $lineAfter = $freshReader->forOrder($order->id())->lines[0];

        $this->assertSame($lineBefore->productName, $lineAfter->productName);
        $this->assertSame($lineBefore->sku, $lineAfter->sku);
        $this->assertSame(2500, $lineAfter->lineTotal->minorValue());
        $this->assertSame(2500, $lineAfter->unitPrice->minorValue());
        $this->assertNotSame('Renamed Product', $lineAfter->productName);
    }

    /**
     * A legacy row predating the 2026-09-17 product_name/sku migration
     * — simulated by writing NULL directly, the only way this state can
     * exist at all (SaleLine's own constructor now rejects it for any
     * NEW row — see OrderAdminReader's own class docblock for the full
     * finding). Proves the reader survives it, per D2/D8.
     */
    public function test_a_line_with_null_product_name_and_sku_renders_as_null_not_an_exception(): void
    {
        [$order] = $this->placeGuestOrder();

        DB::table('operational_sales_sale_lines')
            ->where('transaction_id', $order->transactionId())
            ->update(['product_name' => null, 'sku' => null]);

        $view = app(OrderAdminReader::class)->forOrder($order->id());

        $this->assertNotNull($view);
        $this->assertCount(1, $view->lines);
        $this->assertNull($view->lines[0]->productName);
        $this->assertNull($view->lines[0]->sku);
    }
}
