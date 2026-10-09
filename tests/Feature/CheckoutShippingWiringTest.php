<?php

namespace Tests\Feature;

use App\Services\CheckoutInput;
use App\Services\CheckoutOrchestrator;
use App\Services\OrderEditor;
use App\Services\OrderPlacementSnapshotWriter;
use App\Services\OrderPromotionCodeChange;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Extensibility\Hook;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Order;
use EasyCo\Payment\Contracts\PaymentMethodAdapter;
use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentAttemptResult;
use EasyCo\Payment\PaymentContext;
use EasyCo\Payment\PaymentRefundAttemptResult;
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
use EasyCo\Shipping\Carrier\CarrierCapability;
use EasyCo\Shipping\Carrier\CarrierRegistration;
use EasyCo\Shipping\Carrier\CarrierRegistry;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingDeliveryType;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingClass;
use EasyCo\Shipping\ShippingMethod;
use EasyCo\Shipping\ShippingZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\Shipping\FakeRateProvider;
use Tests\Support\Shipping\Fakes;
use Tests\TestCase;

require_once __DIR__.'/../Support/Shipping/FakeCarrier.php';

/**
 * Shipping stage 4e (shipping-domain-design.md §9.1): POST /api/checkout resolves the customer's shipping choice through
 * CheckoutShippingResolver BEFORE the placement transaction, puts it into the order total and the payment amount, stores
 * the facts on the order and its placement snapshot, and returns them. No rollout switch: shipping is required.
 *
 * REAL handles here: every checkout asks the real quote endpoint first, like the storefront does.
 */
class CheckoutShippingWiringTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    private ?PriceList $priceList = null;

    private ?string $zoneId = null;

    /** @var array<string, true> */
    private array $classes = [];

    /** Goods of the standard cart: light 10.00 x 2 + heavy 5.00 x 1. */
    private const GOODS = 2500;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost/');
    }

    // --- fixtures -------------------------------------------------------------------------------------------------

    private function variation(?string $price, ?string $class = null, int $stock = 100): string
    {
        self::$counter++;
        $n = self::$counter;
        $product = Product::createSimple("Wiring Product {$n}", "WP-{$n}", "wiring-product-{$n}");

        if ($class !== null) {
            if (! isset($this->classes[$class])) {
                app(ShippingClassRepository::class)->save(ShippingClass::create(ucfirst($class), $class));
                $this->classes[$class] = true;
            }
            $product->variations()[0]->setShippingClass($class);
        }

        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        $this->priceList ??= tap(PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0), fn (PriceList $list) => app(PriceListRepository::class)->save($list));

        if ($price !== null) {
            app(PriceListItemRepository::class)->save(new PriceListItem(
                null, $this->priceList->id(), PriceListItemTargetType::VARIATION, $variationId,
                Price::exclusiveOfTax(Money::fromDecimal($price, 'EUR'), 0),
            ));
        }
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $stock));

        return $variationId;
    }

    private function method(
        ShippingMethodKind $kind,
        string $name,
        int $sort = 0,
        ?int $amount = null,
        array $classRates = [],
        ?int $freeAbove = null,
        ?string $carrier = null,
        bool $pickup = false,
        ShippingClassMode $mode = ShippingClassMode::REPLACE,
        ?string $courier = null,
        ?ShippingDeliveryType $type = null,
    ): string {
        if ($this->zoneId === null) {
            $zone = ShippingZone::create('Zone '.(++self::$counter), 0, ['BG']);
            app(ShippingZoneRepository::class)->save($zone);
            $this->zoneId = (string) $zone->id();
        }

        $method = ShippingMethod::create($this->zoneId, $name, $kind, $sort, true, $amount, $classRates, $freeAbove, $carrier, $pickup, $mode, $courier, $type);
        app(ShippingMethodRepository::class)->save($method);

        return (string) $method->id();
    }

    /** The standard cart (goods 25.00, classes light + heavy), put in the session cart through the API like the storefront does. */
    private function standardCart(): string
    {
        $light = $this->variation('10.00', 'light');
        $heavy = $this->variation('5.00', 'heavy');
        $this->postJson('/api/cart/lines', ['variation_id' => $light, 'quantity' => 2])->assertStatus(201);

        return (string) $this->postJson('/api/cart/lines', ['variation_id' => $heavy, 'quantity' => 1])->assertStatus(201)->json('cart_id');
    }

    /** @return array<string, mixed> */
    private function street(): array
    {
        return ['delivery_type' => 'street_address', 'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'Vitosha Blvd 1'];
    }

    /** @return array<string, mixed> */
    private function pickupPoint(): array
    {
        return ['delivery_type' => 'pickup_point', 'country' => 'BG', 'carrier_code' => 'econt', 'pickup_point_reference' => 'office-1', 'settlement' => 'Sofia', 'pickup_point_name' => 'Econt office Center', 'pickup_point_address' => 'Vitosha Blvd 100, Sofia'];
    }

    /** The real quote for an address (the quote endpoint's own body), keyed by method id. @return array<string, array<string, mixed>> */
    private function quote(array $address): array
    {
        $body = ['delivery_type' => $address['delivery_type'], 'country' => $address['country'], 'settlement' => $address['city'] ?? $address['settlement']];
        $methods = [];

        foreach ($this->postJson('/api/shipping/quote', $body)->assertOk()->json('methods') as $method) {
            $methods[$method['id']] = $method;
        }

        return $methods;
    }

    /** @return array<string, mixed> */
    private function payload(string $cartId, array $address, array $shipping, array $override = []): array
    {
        return array_merge([
            'cart_id' => $cartId,
            'email' => 'guest@example.com',
            'recipient_name' => 'Guest Buyer',
            'phone' => '+359888000000',
            'payment_method' => 'cash_on_delivery',
        ], $address, $shipping, $override);
    }

    /** @return array<string, mixed> */
    private function choose(array $quoted, string $methodId, ?int $expected = null): array
    {
        return array_filter([
            'shipping_method_id' => $methodId,
            'quote_handle' => $quoted[$methodId]['handle'],
            'expected_shipping_minor' => $expected ?? $quoted[$methodId]['price']['minor'],
        ], fn ($v) => $v !== null);
    }

    private function storeLocale(string $locale): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', $locale);
    }

    /** @return array<string, int> */
    private function rows(): array
    {
        return [
            'orders' => DB::table('orders')->count(),
            'payments' => DB::table('payments')->count(),
            'snapshots' => DB::table('order_placement_snapshots')->count(),
            'transactions' => DB::table('operational_sales_transactions')->count(),
            'sale_lines' => DB::table('operational_sales_sale_lines')->count(),
        ];
    }

    private function assertNothingWritten(string $cartId): void
    {
        $this->assertSame(['orders' => 0, 'payments' => 0, 'snapshots' => 0, 'transactions' => 0, 'sale_lines' => 0], $this->rows());
        $this->assertNull(DB::table('carts')->where('id', $cartId)->value('order_id'), 'the cart is not claimed');
    }

    // --- end to end, per kind ------------------------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function kinds(): array
    {
        return [['flat'], ['free'], ['replace'], ['adjust'], ['threshold'], ['office'], ['locker']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function test_each_kind_of_method_is_chosen_priced_stored_and_returned(string $kind): void
    {
        $cartId = $this->standardCart();   // first: it creates the shipping classes the per-class methods name
        $ids = [
            'flat' => $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500),
            'free' => $this->method(ShippingMethodKind::FREE, 'Free', 1),
            'replace' => $this->method(ShippingMethodKind::PER_CLASS, 'Per class', 2, 400, ['light' => 300, 'heavy' => 900]),
            'adjust' => $this->method(ShippingMethodKind::PER_CLASS, 'Adjusting', 3, 400, ['heavy' => 250, 'light' => -50], mode: ShippingClassMode::ADJUST),
            'threshold' => $this->method(ShippingMethodKind::FLAT, 'Threshold', 4, 700, freeAbove: 3000),
            'office' => $this->method(ShippingMethodKind::FLAT, 'To office', 5, 450, pickup: true, courier: 'Econt', type: ShippingDeliveryType::OFFICE),
            'locker' => $this->method(ShippingMethodKind::FLAT, 'To locker', 6, 300, pickup: true, courier: 'Econt', type: ShippingDeliveryType::LOCKER),
        ];
        $address = in_array($kind, ['office', 'locker'], true) ? $this->pickupPoint() : $this->street();
        $quoted = $this->quote($address);
        $id = $ids[$kind];
        $amount = $quoted[$id]['price']['minor'];

        $response = $this->postJson('/api/checkout', $this->payload($cartId, $address, $this->choose($quoted, $id)))->assertStatus(201);

        $response->assertJsonPath('already_placed', false)
            ->assertJsonPath('shipping.method_id', $id)
            ->assertJsonPath('shipping.method_name', $quoted[$id]['name'])
            ->assertJsonPath('shipping.courier', $quoted[$id]['courier'])
            ->assertJsonPath('shipping.delivery_type', $quoted[$id]['delivery_type'])
            ->assertJsonPath('shipping.service_code', null)
            ->assertJsonPath('shipping.amount_minor', $amount)
            ->assertJsonPath('shipping.currency', 'EUR')
            ->assertJsonPath('order.subtotal.minor', self::GOODS)
            ->assertJsonPath('order.total.minor', self::GOODS + $amount)
            ->assertJsonPath('payment.amount.minor', self::GOODS + $amount);

        $order = DB::table('orders')->sole();
        $this->assertSame([$amount, $id, $quoted[$id]['name'], $quoted[$id]['courier'], $quoted[$id]['delivery_type']], [(int) $order->shipping_minor, $order->shipping_method_code, $order->shipping_method_name, $order->shipping_courier, $order->shipping_delivery_type]);
        $this->assertSame(self::GOODS + $amount, (int) DB::table('payments')->sole()->amount_minor);
    }

    public function test_a_carrier_method_with_a_verifying_handle_is_placed_with_its_service_code(): void
    {
        $this->app->instance(CarrierCapability::RATE->containerKey('acme'), new FakeRateProvider(fn () => [Fakes::quote(600), new \EasyCo\Shipping\Carrier\ShippingQuote('address', 'To address', 450, 'EUR')]));
        $this->app->make(CarrierRegistry::class)->register(new CarrierRegistration('acme', 'Acme', [CarrierCapability::RATE]));
        $id = $this->method(ShippingMethodKind::CARRIER, 'Acme', 0, carrier: 'acme');
        $cartId = $this->standardCart();
        $quoted = $this->quote($this->street());

        $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), $this->choose($quoted, $id)))->assertStatus(201)
            ->assertJsonPath('shipping.service_code', 'address')
            ->assertJsonPath('shipping.amount_minor', 450)
            ->assertJsonPath('order.total.minor', self::GOODS + 450);
        $this->assertSame('address', DB::table('orders')->sole()->shipping_service_code);
    }

    public function test_the_placement_snapshot_carries_the_shipping_facts_and_matches_the_snapshot_writer(): void
    {
        $id = $this->method(ShippingMethodKind::FLAT, 'To address', 0, 450, courier: 'Econt', type: ShippingDeliveryType::ADDRESS);
        $cartId = $this->standardCart();
        $quoted = $this->quote($this->street());

        $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), $this->choose($quoted, $id)))->assertStatus(201);

        $order = app(OrderRepository::class)->findById((string) DB::table('orders')->value('id'));
        $row = (array) DB::table('order_placement_snapshots')->sole();
        $expected = app(OrderPlacementSnapshotWriter::class)->rowFor($order, new \DateTimeImmutable('2000-01-01'));

        foreach ($expected as $column => $value) {
            if ($column === 'created_at') {
                continue;
            }
            $this->assertEquals($value, $row[$column], "snapshot column {$column}");
        }
        $this->assertSame([450, $id, 'To address', 'Econt', 'address'], [(int) $row['shipping_minor'], $row['shipping_method_code'], $row['shipping_method_name'], $row['shipping_courier'], $row['shipping_delivery_type']]);
        $this->assertSame((int) $row['total_minor'], (int) $row['subtotal_minor'] - (int) $row['discount_minor'] + (int) $row['shipping_minor']);
    }

    // --- refusals ---------------------------------------------------------------------------------------------------------

    /** @return array<string, array{string, int, string}> */
    public static function refusals(): array
    {
        return [
            'required' => ['shipping_required', 422, 'required'],
            'invalid' => ['shipping_invalid', 422, 'invalid'],
            'method unavailable' => ['shipping_method_unavailable', 409, 'unavailable'],
            'quote expired' => ['shipping_quote_expired', 409, 'expired'],
            'price changed' => ['shipping_price_changed', 409, 'changed'],
            'pickup mismatch' => ['shipping_pickup_mismatch', 422, 'pickup'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_each_refusal_has_its_status_reason_and_a_translated_sentence_and_writes_nothing(string $reason, int $status, string $scenario): void
    {
        $flat = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $locker = $this->method(ShippingMethodKind::FLAT, 'To locker', 1, 300, pickup: true);
        $cartId = $this->standardCart();
        $quoted = $this->quote($this->street());
        $stranger = 'qh_'.str_repeat('z', 40);
        $shipping = match ($scenario) {
            'required' => [],
            'invalid' => ['shipping_method_id' => 'abc', 'quote_handle' => $stranger],
            'unavailable' => ['shipping_method_id' => '987654', 'quote_handle' => $stranger],
            'expired' => ['shipping_method_id' => $flat, 'quote_handle' => $stranger],
            'changed' => ['shipping_method_id' => $flat, 'quote_handle' => $stranger, 'expected_shipping_minor' => 450],
            'pickup' => ['shipping_method_id' => $locker, 'quote_handle' => $quoted[$flat]['handle']],
        };
        $fired = 0;
        Hook::action('order.placed', function () use (&$fired): void {
            $fired++;
        });
        $stock = DB::table('stock_levels')->pluck('quantity', 'variation_id')->all();

        foreach (['en', 'bg'] as $locale) {
            $this->storeLocale($locale);
            $response = $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), $shipping))->assertStatus($status)->assertJsonPath('reason', $reason);

            $this->assertSame(trans('checkout.'.$reason, [], $locale), $response->json('message'));
            $this->assertStringNotContainsString('abc', (string) $response->getContent());
            $this->assertStringNotContainsString($stranger, (string) $response->getContent());

            if ($scenario === 'changed') {
                $response->assertJsonPath('price', ['minor' => 500, 'currency' => 'EUR']);
            } else {
                $response->assertJsonMissingPath('price');
            }
        }

        $this->assertNotSame(trans('checkout.'.$reason, [], 'en'), trans('checkout.'.$reason, [], 'bg'));
        $this->assertNothingWritten($cartId);
        $this->assertSame(0, $fired, 'no order.placed event');
        $this->assertSame($stock, DB::table('stock_levels')->pluck('quantity', 'variation_id')->all(), 'no stock moved');
        $this->assertSame(2, DB::table('cart_lines')->where('cart_id', $cartId)->count(), 'the cart keeps its lines');
    }

    public function test_after_price_changed_a_retry_with_the_new_figure_and_a_fresh_handle_succeeds(): void
    {
        $flat = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cartId = $this->standardCart();
        $stale = $this->quote($this->street());

        DB::table('shipping_methods')->where('id', $flat)->update(['amount_minor' => 650]);

        $refused = $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), $this->choose($stale, $flat)))->assertStatus(409)->assertJsonPath('reason', 'shipping_price_changed');
        $refused->assertJsonPath('price', ['minor' => 650, 'currency' => 'EUR']);
        $this->assertNothingWritten($cartId);

        $fresh = $this->quote($this->street());
        $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), $this->choose($fresh, $flat, $refused->json('price.minor'))))->assertStatus(201)
            ->assertJsonPath('shipping.amount_minor', 650)
            ->assertJsonPath('order.total.minor', self::GOODS + 650);
    }

    public function test_a_lost_handle_is_tolerated_for_a_local_method_with_the_exact_amount_and_refused_for_a_carrier(): void
    {
        $this->app->instance(CarrierCapability::RATE->containerKey('acme'), new FakeRateProvider(fn () => [new \EasyCo\Shipping\Carrier\ShippingQuote('address', 'To address', 450, 'EUR')]));
        $this->app->make(CarrierRegistry::class)->register(new CarrierRegistration('acme', 'Acme', [CarrierCapability::RATE]));
        $carrier = $this->method(ShippingMethodKind::CARRIER, 'Acme', 0, carrier: 'acme');
        $flat = $this->method(ShippingMethodKind::FLAT, 'Flat', 1, 500);
        $cartId = $this->standardCart();
        $lost = 'qh_'.str_repeat('q', 40);

        $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), ['shipping_method_id' => $carrier, 'quote_handle' => $lost, 'expected_shipping_minor' => 450]))
            ->assertStatus(409)->assertJsonPath('reason', 'shipping_quote_expired');
        $this->assertNothingWritten($cartId);

        $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), ['shipping_method_id' => $flat, 'quote_handle' => $lost, 'expected_shipping_minor' => 500]))
            ->assertStatus(201)->assertJsonPath('shipping.amount_minor', 500);
    }

    // --- unpriced lines keep their old refusal -----------------------------------------------------------------------------

    /** @return array<string, array{list<?string>}> what each line has left of its price, null = unpriced */
    public static function unpricedCarts(): array
    {
        return ['every line unpriced' => [[null]], 'all lines unpriced' => [[null, null]], 'one priced, one unpriced' => [['10.00', null]]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unpricedCarts')]
    public function test_a_cart_with_an_unpriced_line_is_still_409_price_not_available_and_writes_nothing(array $prices): void
    {
        $flat = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cartId = '';
        foreach ($prices as $price) {
            // The cart API refuses an unpriced variation, so the line goes in priced and loses its price afterwards
            // (a merchant removing a price while the cart is open).
            $variationId = $this->variation('10.00');
            $cartId = (string) $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1])->assertStatus(201)->json('cart_id');

            if ($price === null) {
                DB::table('pricing_price_list_items')->where('target_id', $variationId)->delete();
            }
        }
        $stock = DB::table('stock_levels')->pluck('quantity', 'variation_id')->all();
        $fired = 0;
        Hook::action('order.placed', function () use (&$fired): void {
            $fired++;
        });
        $shipping = ['shipping_method_id' => $flat, 'quote_handle' => 'qh_'.str_repeat('a', 40), 'expected_shipping_minor' => 500];

        foreach (['en', 'bg'] as $locale) {
            $this->storeLocale($locale);
            $response = $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), $shipping))->assertStatus(409)->assertJsonPath('reason', 'price_not_available');

            $this->assertSame(trans('checkout.price_not_available', [], $locale), $response->json('message'));
            $this->assertStringNotContainsString('Regular Prices', (string) $response->getContent());
        }

        $this->assertNothingWritten($cartId);
        $this->assertSame(0, $fired);
        $this->assertSame($stock, DB::table('stock_levels')->pluck('quantity', 'variation_id')->all());
        $this->assertSame(count($prices), DB::table('cart_lines')->where('cart_id', $cartId)->count(), 'the cart keeps its lines');
    }

    // --- the handle, the claim, the replay ---------------------------------------------------------------------------

    public function test_the_handle_is_not_consumed_and_expected_shipping_minor_is_never_charged(): void
    {
        $flat = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cartId = $this->standardCart();
        $quoted = $this->quote($this->street());

        $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), $this->choose($quoted, $flat, 99999)))->assertStatus(201)
            ->assertJsonPath('shipping.amount_minor', 500)
            ->assertJsonPath('order.total.minor', self::GOODS + 500);

        $this->assertIsArray(\Illuminate\Support\Facades\Cache::get(\App\Services\QuoteHandleStore::KEY_PREFIX.hash('sha256', $quoted[$flat]['handle'])), 'the handle is still stored after placement');
    }

    public function test_a_double_click_is_exactly_one_order_with_the_same_shipping_and_the_replay_does_not_run_the_quote_pipeline(): void
    {
        $flat = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cartId = $this->standardCart();
        $quoted = $this->quote($this->street());
        $payload = $this->payload($cartId, $this->street(), $this->choose($quoted, $flat));

        $first = $this->postJson('/api/checkout', $payload)->assertStatus(201);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $second = $this->postJson('/api/checkout', $payload)->assertStatus(201);

        $second->assertJsonPath('already_placed', true);
        $this->assertSame($first->json('shipping'), $second->json('shipping'), 'the identical shipping object');
        $this->assertSame($first->json('order.id'), $second->json('order.id'));
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'shipping_zones') || str_contains($sql, 'shipping_methods') || str_contains($sql, 'pricing_price_list'))), 'a replay asks the quote pipeline nothing');
    }

    public function test_when_a_competing_checkout_wins_during_the_shipping_step_this_one_replays_and_there_is_one_order(): void
    {
        $flat = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cartId = $this->standardCart();
        $quoted = $this->quote($this->street());
        $payload = $this->payload($cartId, $this->street(), $this->choose($quoted, $flat));
        $token = session('cart_token');

        // The competitor places the SAME cart while this request is inside its shipping step (the merchant filter runs there).
        $raced = false;
        Hook::filter('shipping.quotes', function (array $methods) use (&$raced, $cartId, $flat, $token): array {
            if (! $raced) {
                $raced = true;
                app(CheckoutOrchestrator::class)->place(new CheckoutInput(
                    cartId: $cartId, email: 'rival@example.com', recipientName: 'Rival', phone: '+359888000001', paymentMethod: 'cash_on_delivery',
                    guestCartToken: $token, deliveryType: AddressDeliveryType::STREET_ADDRESS, country: 'BG', city: 'Sofia', addressLine1: 'Rival 1',
                    shippingMethodId: $flat, quoteHandle: 'qh_'.str_repeat('r', 40), expectedShippingMinor: 500,
                ), new \DateTimeImmutable());
            }

            return $methods;
        });

        $response = $this->postJson('/api/checkout', $payload)->assertStatus(201);

        $response->assertJsonPath('already_placed', true)->assertJsonPath('shipping.amount_minor', 500);
        $this->assertSame(1, DB::table('orders')->count(), 'exactly one order');
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame('rival@example.com', DB::table('orders')->value('email'));
    }

    public function test_goods_that_change_between_the_shipping_step_and_the_claim_are_a_retryable_409_and_write_nothing(): void
    {
        $flat = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cartId = $this->standardCart();
        $quoted = $this->quote($this->street());
        $payload = $this->payload($cartId, $this->street(), $this->choose($quoted, $flat));

        // After the shipping resolved and before the transaction prices the goods, a price moves (the filter runs inside step 0).
        $changed = false;
        Hook::filter('shipping.quotes', function (array $methods) use (&$changed): array {
            if (! $changed) {
                $changed = true;
                DB::table('pricing_price_list_items')->update(['price_amount_minor' => DB::raw('price_amount_minor + 100')]);
            }

            return $methods;
        });

        $this->postJson('/api/checkout', $payload)->assertStatus(409)->assertJsonPath('reason', 'checkout_state_changed');
        $this->assertNothingWritten($cartId);
    }

    // --- payment ----------------------------------------------------------------------------------------------------------

    private function offlineAdapter(string $method): void
    {
        $this->app->bind('payment.adapter.'.$method, fn () => new class implements PaymentMethodAdapter {
            public function charge(Money $amount, PaymentContext $context): PaymentAttemptResult
            {
                return PaymentAttemptResult::pending();
            }

            public function refund(Payment $original, Money $amount, PaymentContext $context): PaymentRefundAttemptResult
            {
                throw new RuntimeException('not exercised');
            }

            public function isOffline(): bool
            {
                return true;
            }
        });
    }

    /** @return array<string, array{string}> */
    public static function paymentMethods(): array
    {
        return [['cash_on_delivery'], ['bank_transfer'], ['card']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('paymentMethods')]
    public function test_the_payment_amount_of_every_method_is_subtotal_minus_discount_plus_shipping(string $method): void
    {
        if ($method === 'card') {
            $this->offlineAdapter('card');
        }
        app(PromotionRepository::class)->save(Promotion::create(code: 'TEN', discountType: PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 1000));
        $flat = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cartId = $this->standardCart();
        $this->putJson('/api/cart/promotion', ['code' => 'TEN'])->assertStatus(200);
        $quoted = $this->quote($this->street());

        $response = $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), $this->choose($quoted, $flat), ['payment_method' => $method]))->assertStatus(201);

        $this->assertSame(2500 - 250 + 500, $response->json('payment.amount.minor'));
        $this->assertSame(2500 - 250 + 500, $response->json('order.total.minor'));
        $this->assertSame(2750, (int) DB::table('payments')->sole()->amount_minor);
        $this->assertSame($method, DB::table('payments')->sole()->method);
    }

    public function test_a_payment_step_failure_after_phase_one_still_answers_201_with_the_message_a_pending_payment_and_the_shipping_on_the_order(): void
    {
        $this->app->bind('payment.adapter.cash_on_delivery', fn () => new class implements PaymentMethodAdapter {
            public function charge(Money $amount, PaymentContext $context): PaymentAttemptResult
            {
                throw new RuntimeException('provider down');
            }

            public function refund(Payment $original, Money $amount, PaymentContext $context): PaymentRefundAttemptResult
            {
                throw new RuntimeException('not exercised');
            }

            public function isOffline(): bool
            {
                return true;
            }
        });
        $flat = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cartId = $this->standardCart();
        $quoted = $this->quote($this->street());

        $response = $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), $this->choose($quoted, $flat)))->assertStatus(201);

        $response->assertJsonPath('message', trans('checkout.payment_needs_attention', [], 'en'))
            ->assertJsonPath('shipping.amount_minor', 500)
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.amount.minor', self::GOODS + 500);
        $this->assertNull(DB::table('payments')->sole()->attempted_at);
        $this->assertSame(500, (int) DB::table('orders')->sole()->shipping_minor);
        $this->assertNotNull(DB::table('carts')->where('id', $cartId)->value('order_id'));
    }

    // --- zero totals --------------------------------------------------------------------------------------------------------

    public function test_free_goods_with_paid_shipping_is_a_payable_order_and_free_goods_with_free_shipping_is_still_zero_total(): void
    {
        app(PromotionRepository::class)->save(Promotion::create(code: 'FIX', discountType: PromotionDiscountType::FIXED_AMOUNT, discountAmount: Money::fromDecimal('50.00', 'EUR')));
        $flat = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $free = $this->method(ShippingMethodKind::FREE, 'Free', 1);
        $cartId = $this->standardCart();
        $this->putJson('/api/cart/promotion', ['code' => 'FIX'])->assertStatus(200);
        $quoted = $this->quote($this->street());

        $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), $this->choose($quoted, $free)))->assertStatus(422)->assertJsonPath('reason', 'zero_total');
        $this->assertNothingWritten($cartId);

        $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), $this->choose($quoted, $flat)))->assertStatus(201)
            ->assertJsonPath('order.subtotal.minor', self::GOODS)
            ->assertJsonPath('order.discount_amount.minor', self::GOODS)
            ->assertJsonPath('order.total.minor', 500)
            ->assertJsonPath('payment.amount.minor', 500);
    }

    // --- input security -----------------------------------------------------------------------------------------------------

    /** @return array<string, array{array<string, mixed>}> */
    public static function hostileShipping(): array
    {
        $good = 'qh_'.str_repeat('a', 40);

        return [
            'array id' => [['shipping_method_id' => ['1'], 'quote_handle' => $good]],
            'object id' => [['shipping_method_id' => ['a' => 1], 'quote_handle' => $good]],
            'array handle' => [['shipping_method_id' => '1', 'quote_handle' => [$good]]],
            'object handle' => [['shipping_method_id' => '1', 'quote_handle' => ['k' => $good]]],
            'huge id' => [['shipping_method_id' => str_repeat('9', 10000), 'quote_handle' => $good]],
            'huge handle' => [['shipping_method_id' => '1', 'quote_handle' => 'qh_'.str_repeat('a', 10000)]],
            'sql id' => [['shipping_method_id' => "1' OR '1'='1", 'quote_handle' => $good]],
            'sql handle' => [['shipping_method_id' => '1', 'quote_handle' => "qh_' OR '1'='1"]],
            'script id' => [['shipping_method_id' => '<script>alert(1)</script>', 'quote_handle' => $good]],
            'script handle' => [['shipping_method_id' => '1', 'quote_handle' => '<script>alert(1)</script>']],
            'text expected' => [['shipping_method_id' => '1', 'quote_handle' => $good, 'expected_shipping_minor' => 'abc']],
            'sql expected' => [['shipping_method_id' => '1', 'quote_handle' => $good, 'expected_shipping_minor' => '1; DROP TABLE orders']],
            'negative expected' => [['shipping_method_id' => '1', 'quote_handle' => $good, 'expected_shipping_minor' => -1]],
            'array expected' => [['shipping_method_id' => '1', 'quote_handle' => $good, 'expected_shipping_minor' => [1]]],
            'float expected' => [['shipping_method_id' => '1', 'quote_handle' => $good, 'expected_shipping_minor' => 1.5]],
            'overflow expected' => [['shipping_method_id' => '1', 'quote_handle' => $good, 'expected_shipping_minor' => '99999999999999999999']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileShipping')]
    public function test_hostile_values_in_the_three_new_fields_are_a_422_without_echo_and_write_nothing(array $shipping): void
    {
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cartId = $this->standardCart();

        $response = $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), $shipping))->assertStatus(422);

        $body = (string) $response->getContent();
        foreach (['<script', 'DROP TABLE', "OR '1'", 'aaaaaaaaaa', '9999999999'] as $typed) {
            $this->assertStringNotContainsString($typed, $body);
        }
        $this->assertNothingWritten($cartId);
    }

    // --- consistency --------------------------------------------------------------------------------------------------------

    public function test_the_orders_delivery_type_list_is_the_shipping_enums_values_in_the_same_order(): void
    {
        $this->assertSame(
            array_map(fn (ShippingDeliveryType $type): string => $type->value, ShippingDeliveryType::cases()),
            Order::SHIPPING_DELIVERY_TYPES,
        );
    }

    // --- admin: the order editor and the page on an order that has shipping ---------------------------------------------------

    public function test_the_order_editor_and_the_order_page_work_on_an_order_that_checkout_placed_with_shipping(): void
    {
        $flat = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500, courier: 'Econt', type: ShippingDeliveryType::ADDRESS);
        $cartId = $this->standardCart();
        $quoted = $this->quote($this->street());
        $orderId = (string) $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), $this->choose($quoted, $flat)))->assertStatus(201)->json('order.id');
        $order = app(OrderRepository::class)->findById($orderId);
        $line = app(TransactionRepository::class)->findByIdWithSaleLines($order->transactionId())->saleLines()[0];

        app(OrderEditor::class)->apply(
            orderId: $orderId,
            expectedRevision: 0,
            lineChanges: [['change' => 'change_quantity', 'originatingLine' => $line, 'quantity' => 1]],
            delivery: null,
            promotionCode: OrderPromotionCodeChange::unchanged(),
            editedBy: null,
            editedByName: null,
            reason: null,
            occurredAt: new \DateTimeImmutable(),
        );

        $row = DB::table('orders')->where('id', $orderId)->first();
        $this->assertSame(500, (int) $row->shipping_minor, 'the edit keeps the shipping');
        $this->assertSame('Econt', $row->shipping_courier);
        $this->assertSame((int) $row->subtotal_minor - (int) $row->discount_minor + 500, (int) $row->total_minor);
        $this->assertSame(
            (int) $row->total_minor,
            (int) DB::table('payments')->where('order_id', $orderId)->whereNull('voided_at')->orderByDesc('id')->value('amount_minor'),
            'the reissued pending payment is for the new total including shipping',
        );
    }
}
