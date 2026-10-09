<?php

namespace Tests\Feature;

use App\Services\Exceptions\ShippingQuoteFilterException;
use App\Services\CheckoutShippingResolver;
use App\Services\MethodQuote;
use App\Services\QuoteDestination;
use App\Services\ShippingQuoteService;
use App\Services\ShippingRefusal;
use App\Services\ShippingRefusalReason;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLineAdder;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Extensibility\Hook;
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
use EasyCo\Shipping\Carrier\CarrierCapability;
use EasyCo\Shipping\Carrier\CarrierRegistration;
use EasyCo\Shipping\Carrier\CarrierRegistry;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingDeliveryType;
use EasyCo\Shipping\Enums\ShippingDestinationScope;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingMethod;
use EasyCo\Shipping\ShippingZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Shipping\FakeRateProvider;
use Tests\Support\Shipping\Fakes;
use Tests\TestCase;

require_once __DIR__.'/../Support/Shipping/FakeCarrier.php';

/**
 * Shipping stage 6b (shipping-domain-design.md section 9.2.4): the quote and the checkout resolver decide eligibility with the
 * method's destination SCOPE. An `any` method serves a street address and a pickup point; the reason code and status of a
 * disagreement (`shipping_pickup_mismatch`, 422) are unchanged; the hash, the handle store and the price decision table are not touched.
 */
class DestinationScopeQuoteAndResolverTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    private ?PriceList $priceList = null;

    private ?string $zoneId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost/');
    }

    // --- fixtures -------------------------------------------------------------------------------------------------

    private function variation(string $price = '10.00'): string
    {
        self::$counter++;
        $n = self::$counter;
        $product = Product::createSimple("Scope Product {$n}", "SP-{$n}", "scope-product-{$n}");
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        $this->priceList ??= tap(PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0), fn (PriceList $list) => app(PriceListRepository::class)->save($list));
        app(PriceListItemRepository::class)->save(new PriceListItem(null, $this->priceList->id(), PriceListItemTargetType::VARIATION, $variationId, Price::exclusiveOfTax(Money::fromDecimal($price, 'EUR'), 0)));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 100));

        return $variationId;
    }

    private function method(
        string $name,
        ShippingDestinationScope $scope,
        int $sort = 0,
        int $amount = 500,
        ?string $courier = null,
        ?ShippingDeliveryType $label = null,
        ?int $freeAbove = null,
        ShippingMethodKind $kind = ShippingMethodKind::FLAT,
        ?string $carrier = null,
    ): string {
        if ($this->zoneId === null) {
            $zone = ShippingZone::create('Zone '.(++self::$counter), 0, ['BG']);
            app(ShippingZoneRepository::class)->save($zone);
            $this->zoneId = (string) $zone->id();
        }

        $method = ShippingMethod::create($this->zoneId, $name, $kind, $sort, true, $kind === ShippingMethodKind::CARRIER ? null : $amount, [], $freeAbove, $carrier, false, ShippingClassMode::REPLACE, $courier, $label, $scope);
        app(ShippingMethodRepository::class)->save($method);

        return (string) $method->id();
    }

    private function domainCart(int $quantity = 2): Cart
    {
        $cart = Cart::forGuest((string) Str::uuid(), new \DateTimeImmutable('+10 days'));
        app(CartLineAdder::class)->addLine($cart, $this->variation(), $quantity, null, null);

        return app(CartRepository::class)->findById($cart->id());
    }

    private function street(): QuoteDestination
    {
        return QuoteDestination::forAddress(AddressDeliveryType::STREET_ADDRESS, 'BG', 'Sofia');
    }

    private function pickup(): QuoteDestination
    {
        return QuoteDestination::forAddress(AddressDeliveryType::PICKUP_POINT, 'BG', 'Sofia');
    }

    private function handleOf(Cart $cart, string $methodId, QuoteDestination $destination): string
    {
        return (string) app(ShippingQuoteService::class)->quote($cart, null, $destination)->method($methodId)->handle;
    }

    private function quoteOf(\App\Services\QuoteOffers $offers, string $methodId): MethodQuote
    {
        foreach ($offers->methods as $method) {
            if ($method->methodId === $methodId) {
                return $method;
            }
        }

        $this->fail("Method {$methodId} is not in the offers.");
    }

    private function reason(callable $call): ShippingRefusalReason
    {
        try {
            $call();
        } catch (ShippingRefusal $refusal) {
            return $refusal->reason;
        }

        $this->fail('Expected a ShippingRefusal.');
    }

    private function resolve(Cart $cart, QuoteDestination $destination, string $methodId, string $handle, ?int $expected = null)
    {
        return app(CheckoutShippingResolver::class)->resolve($cart, null, $destination, $methodId, $handle, $expected);
    }

    // --- the quote JSON -----------------------------------------------------------------------------------------------------

    /** @return array<string, array{string, string, string, bool, bool}> kind of request, method, scope, serves, requires_pickup_point */
    public static function sixCases(): array
    {
        return [
            'address-only asked as a street address' => ['street_address', 'address', 'address', true, false],
            'address-only asked as a pickup point' => ['pickup_point', 'address', 'address', false, false],
            'pickup-only asked as a street address' => ['street_address', 'pickup', 'pickup', false, true],
            'pickup-only asked as a pickup point' => ['pickup_point', 'pickup', 'pickup', true, true],
            'any asked as a street address' => ['street_address', 'any', 'any', true, false],
            'any asked as a pickup point' => ['pickup_point', 'any', 'any', true, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sixCases')]
    public function test_the_quote_reports_each_methods_scope_and_whether_it_serves_this_requests_destination(string $requestKind, string $which, string $scope, bool $serves, bool $requiresPickup): void
    {
        $ids = [
            'address' => $this->method('Home', ShippingDestinationScope::ADDRESS, 0),
            'pickup' => $this->method('Lockers', ShippingDestinationScope::PICKUP, 1),
            'any' => $this->method('Anywhere', ShippingDestinationScope::ANY, 2),
        ];
        $this->postJson('/api/cart/lines', ['variation_id' => $this->variation(), 'quantity' => 1])->assertStatus(201);

        $response = $this->postJson('/api/shipping/quote', ['delivery_type' => $requestKind, 'country' => 'BG', 'settlement' => 'Sofia'])->assertOk();
        $methods = collect($response->json('methods'))->keyBy('id');

        $this->assertSame(array_values($ids), $response->json('methods.*.id'), 'every active method is still listed, in the merchant\'s order');
        $this->assertSame($scope, $methods[$ids[$which]]['destination_scope']);
        $this->assertSame($serves, $methods[$ids[$which]]['serves_destination']);
        $this->assertSame($requiresPickup, $methods[$ids[$which]]['requires_pickup_point']);
        $this->assertTrue($methods[$ids[$which]]['available'], 'a local method is priced even when it does not serve the kind');
        $this->assertStringStartsWith('qh_', $methods[$ids[$which]]['handle']);
    }

    public function test_requires_pickup_point_is_true_only_for_the_pickup_only_method(): void
    {
        $this->method('Home', ShippingDestinationScope::ADDRESS, 0);
        $this->method('Lockers', ShippingDestinationScope::PICKUP, 1);
        $this->method('Anywhere', ShippingDestinationScope::ANY, 2);
        $this->postJson('/api/cart/lines', ['variation_id' => $this->variation(), 'quantity' => 1])->assertStatus(201);

        $flags = $this->postJson('/api/shipping/quote', ['delivery_type' => 'street_address', 'country' => 'BG', 'settlement' => 'Sofia'])->assertOk()->json('methods.*.requires_pickup_point');

        $this->assertSame([false, true, false], $flags);
    }

    public function test_groups_from_minor_and_the_hint_do_not_depend_on_scope_or_on_the_kind_asked(): void
    {
        $this->method('To address', ShippingDestinationScope::ADDRESS, 0, 700, 'Econt', ShippingDeliveryType::ADDRESS, freeAbove: 5000);
        $office = $this->method('To office', ShippingDestinationScope::PICKUP, 1, 400, 'Econt', ShippingDeliveryType::OFFICE);
        $any = $this->method('Anywhere', ShippingDestinationScope::ANY, 2, 450, 'Speedy');
        $this->postJson('/api/cart/lines', ['variation_id' => $this->variation(), 'quantity' => 2])->assertStatus(201);

        $street = $this->postJson('/api/shipping/quote', ['delivery_type' => 'street_address', 'country' => 'BG', 'settlement' => 'Sofia'])->assertOk();
        $pickup = $this->postJson('/api/shipping/quote', ['delivery_type' => 'pickup_point', 'country' => 'BG', 'settlement' => 'Sofia'])->assertOk();

        $expectedGroups = [
            ['courier' => 'Econt', 'methods' => [$street->json('methods.0.id'), $office], 'from_minor' => 400, 'currency' => 'EUR'],
            ['courier' => 'Speedy', 'methods' => [$any], 'from_minor' => 450, 'currency' => 'EUR'],
        ];
        $this->assertSame($expectedGroups, $street->json('groups'), 'from_minor counts the pickup-only method although the request is a street address');
        $this->assertSame($expectedGroups, $pickup->json('groups'));
        $this->assertSame($street->json('free_shipping_hint'), $pickup->json('free_shipping_hint'), 'the hint is not kind-aware (stage 6c)');
        $this->assertSame([5000, 3000], [$street->json('free_shipping_hint.free_above_minor'), $street->json('free_shipping_hint.remaining_minor')]);
    }

    // --- the resolver --------------------------------------------------------------------------------------------------------

    public function test_the_four_old_combinations_keep_their_meaning(): void
    {
        $address = $this->method('Home', ShippingDestinationScope::ADDRESS, 0);
        $pickup = $this->method('Lockers', ShippingDestinationScope::PICKUP, 1);
        $cart = $this->domainCart();

        $this->assertSame(500, $this->resolve($cart, $this->street(), $address, $this->handleOf($cart, $address, $this->street()))->amount->minorValue());
        $this->assertSame(500, $this->resolve($cart, $this->pickup(), $pickup, $this->handleOf($cart, $pickup, $this->pickup()))->amount->minorValue());
        $this->assertSame(ShippingRefusalReason::PICKUP_MISMATCH, $this->reason(fn () => $this->resolve($cart, $this->pickup(), $address, $this->handleOf($cart, $address, $this->pickup()))), 'address-only to a pickup point');
        $this->assertSame(ShippingRefusalReason::PICKUP_MISMATCH, $this->reason(fn () => $this->resolve($cart, $this->street(), $pickup, $this->handleOf($cart, $pickup, $this->street()))), 'pickup-only to a street address');
        $this->assertSame('shipping_pickup_mismatch', ShippingRefusalReason::PICKUP_MISMATCH->value, 'the reason code is unchanged');
    }

    public function test_an_any_method_is_accepted_for_both_kinds_with_a_handle_issued_for_that_kind(): void
    {
        $any = $this->method('Anywhere', ShippingDestinationScope::ANY, 0);
        $cart = $this->domainCart();

        $onStreet = $this->resolve($cart, $this->street(), $any, $this->handleOf($cart, $any, $this->street()));
        $onPickup = $this->resolve($cart, $this->pickup(), $any, $this->handleOf($cart, $any, $this->pickup()));

        $this->assertSame([500, 500], [$onStreet->amount->minorValue(), $onPickup->amount->minorValue()]);
        $this->assertSame([false, true], [$onStreet->requiresPickupPoint, $onPickup->requiresPickupPoint], 'the selection records the kind of destination it was placed on');
    }

    public function test_a_handle_issued_for_the_other_kind_never_verifies_even_for_an_any_method(): void
    {
        $any = $this->method('Anywhere', ShippingDestinationScope::ANY, 0);
        $cart = $this->domainCart();
        $streetHandle = $this->handleOf($cart, $any, $this->street());
        $pickupHandle = $this->handleOf($cart, $any, $this->pickup());

        $this->assertSame(ShippingRefusalReason::QUOTE_EXPIRED, $this->reason(fn () => $this->resolve($cart, $this->pickup(), $any, $streetHandle)), 'a street handle used for a pickup point');
        $this->assertSame(ShippingRefusalReason::QUOTE_EXPIRED, $this->reason(fn () => $this->resolve($cart, $this->street(), $any, $pickupHandle)), 'and the reverse');
    }

    public function test_a_not_served_carrier_is_a_pickup_mismatch_not_method_unavailable(): void
    {
        $this->app->instance(CarrierCapability::RATE->containerKey('acme-addr'), new FakeRateProvider(fn () => [Fakes::quote(600)]));
        $this->app->make(CarrierRegistry::class)->register(new CarrierRegistration('acme-addr', 'Acme', [CarrierCapability::RATE]));
        $carrier = $this->method('Acme home', ShippingDestinationScope::ADDRESS, 0, kind: ShippingMethodKind::CARRIER, carrier: 'acme-addr');
        $cart = $this->domainCart();

        $this->assertSame(ShippingRefusalReason::PICKUP_MISMATCH, $this->reason(fn () => $this->resolve($cart, $this->pickup(), $carrier, 'qh_'.str_repeat('a', 40))));
    }

    // --- the filter contract ---------------------------------------------------------------------------------------------------

    public function test_a_filter_that_states_another_scope_is_refused_even_when_the_pickup_boolean_is_unchanged(): void
    {
        // An address-only and an `any` method both have requires_pickup_point = false, so the OLD comparison cannot see the
        // difference: only the new scope comparison can refuse this filter.
        $this->method('Home', ShippingDestinationScope::ADDRESS, 0);
        $cart = $this->domainCart();
        Hook::filter('shipping.quotes', fn (array $methods): array => array_map(
            fn (MethodQuote $m) => MethodQuote::priced($m->methodId, $m->name, $m->kind, $m->requiresPickupPoint, $m->currency, $m->amountMinor, null, null, null, null, null, 'any'),
            $methods,
        ));

        try {
            app(ShippingQuoteService::class)->quote($cart, null, $this->street());
            $this->fail('the filter should have been refused');
        } catch (ShippingQuoteFilterException $e) {
            $this->assertSame(ShippingQuoteFilterException::CHANGED_FIELD, $e->reason);
        }
    }

    public function test_a_filter_that_states_the_same_scope_as_the_method_row_is_accepted(): void
    {
        $address = $this->method('Home', ShippingDestinationScope::ADDRESS, 0);
        $cart = $this->domainCart();
        Hook::filter('shipping.quotes', fn (array $methods): array => array_map(
            fn (MethodQuote $m) => MethodQuote::priced($m->methodId, $m->name, $m->kind, $m->requiresPickupPoint, $m->currency, 250, null, null, null, null, null, 'address'),
            $methods,
        ));

        $quote = app(ShippingQuoteService::class)->quote($cart, null, $this->street())->method($address);

        $this->assertSame([250, 'address'], [$quote->amountMinor, $quote->destinationScope]);
    }

    public function test_a_filter_that_rebuilds_a_quote_the_old_way_cannot_narrow_an_any_method_and_gets_the_scope_back(): void
    {
        $any = $this->method('Anywhere', ShippingDestinationScope::ANY, 0);
        $cart = $this->domainCart();
        Hook::filter('shipping.quotes', fn (array $methods): array => array_map(
            fn (MethodQuote $m) => MethodQuote::priced($m->methodId, $m->name, $m->kind, $m->requiresPickupPoint, $m->currency, 250),
            $methods,
        ));

        $quote = app(ShippingQuoteService::class)->quote($cart, null, $this->pickup())->method($any);

        $this->assertSame(250, $quote->amountMinor, 'the amount change went through');
        $this->assertSame(['any', true], [$quote->destinationScope, $quote->servesDestination], 'the scope and the serves flag are the method row\'s');
    }

    // --- end to end ----------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function kinds(): array
    {
        return ['a street address' => ['street_address'], 'a pickup point' => ['pickup_point']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function test_checkout_with_an_any_method_to_either_kind_stores_the_total_the_payment_and_the_methods_own_label(string $kind): void
    {
        $unlabelled = $this->method('Anywhere', ShippingDestinationScope::ANY, 0, 450);
        $cartId = (string) $this->postJson('/api/cart/lines', ['variation_id' => $this->variation(), 'quantity' => 2])->assertStatus(201)->json('cart_id');
        $address = $kind === 'street_address'
            ? ['delivery_type' => 'street_address', 'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'Vitosha 1']
            : ['delivery_type' => 'pickup_point', 'country' => 'BG', 'carrier_code' => 'econt', 'pickup_point_reference' => 'office-1', 'settlement' => 'Sofia', 'pickup_point_name' => 'Office', 'pickup_point_address' => 'Vitosha 100'];
        $quoted = collect($this->postJson('/api/shipping/quote', ['delivery_type' => $kind, 'country' => 'BG', 'settlement' => 'Sofia'])->assertOk()->json('methods'))->firstWhere('id', $unlabelled);

        $this->postJson('/api/checkout', array_merge([
            'cart_id' => $cartId, 'email' => 'g@example.com', 'recipient_name' => 'G B', 'phone' => '+359888000000', 'payment_method' => 'cash_on_delivery',
            'shipping_method_id' => $quoted['id'], 'quote_handle' => $quoted['handle'], 'expected_shipping_minor' => 450,
        ], $address))->assertStatus(201)
            ->assertJsonPath('order.total.minor', 2000 + 450)
            ->assertJsonPath('payment.amount.minor', 2000 + 450)
            ->assertJsonPath('shipping.amount_minor', 450)
            ->assertJsonPath('shipping.delivery_type', null)
            ->assertJsonPath('shipping.courier', null);

        $order = DB::table('orders')->sole();
        $this->assertSame([450, null, $kind], [(int) $order->shipping_minor, $order->shipping_delivery_type, $order->delivery_type]);
    }

    public function test_an_any_method_with_the_other_label_stores_that_label(): void
    {
        $any = $this->method('Courier', ShippingDestinationScope::ANY, 0, 450, 'Econt', ShippingDeliveryType::OTHER);
        $cartId = (string) $this->postJson('/api/cart/lines', ['variation_id' => $this->variation(), 'quantity' => 1])->assertStatus(201)->json('cart_id');
        $quoted = collect($this->postJson('/api/shipping/quote', ['delivery_type' => 'street_address', 'country' => 'BG', 'settlement' => 'Sofia'])->assertOk()->json('methods'))->firstWhere('id', $any);

        $this->postJson('/api/checkout', [
            'cart_id' => $cartId, 'email' => 'g@example.com', 'recipient_name' => 'G B', 'phone' => '+359888000000', 'payment_method' => 'cash_on_delivery',
            'delivery_type' => 'street_address', 'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'Vitosha 1',
            'shipping_method_id' => $quoted['id'], 'quote_handle' => $quoted['handle'],
        ])->assertStatus(201)->assertJsonPath('shipping.delivery_type', 'other')->assertJsonPath('shipping.courier', 'Econt');
    }

    // --- carriers ----------------------------------------------------------------------------------------------------------------

    /** @param array<int, bool> $calls the isPickupPoint of every context that reached the fake */
    private function carrier(string $code, array &$calls): void
    {
        $this->app->instance(CarrierCapability::RATE->containerKey($code), new FakeRateProvider(function ($context) use (&$calls) {
            $calls[] = $context->isPickupPoint;

            return [Fakes::quote(600)];
        }));
        $this->app->make(CarrierRegistry::class)->register(new CarrierRegistration($code, ucfirst($code), [CarrierCapability::RATE]));
    }

    public function test_an_any_carrier_is_asked_with_the_real_kind_and_a_restricted_one_is_never_asked_for_the_other_kind(): void
    {
        $anyCalls = $addrCalls = $pickCalls = [];
        $this->carrier('acme-any', $anyCalls);
        $this->carrier('acme-addr', $addrCalls);
        $this->carrier('acme-pick', $pickCalls);
        $any = $this->method('Any', ShippingDestinationScope::ANY, 0, kind: ShippingMethodKind::CARRIER, carrier: 'acme-any');
        $addr = $this->method('Home', ShippingDestinationScope::ADDRESS, 1, kind: ShippingMethodKind::CARRIER, carrier: 'acme-addr');
        $pick = $this->method('Lockers', ShippingDestinationScope::PICKUP, 2, kind: ShippingMethodKind::CARRIER, carrier: 'acme-pick');
        $cart = $this->domainCart();
        $service = app(ShippingQuoteService::class);

        // As a street address
        $street = $service->offers($cart, null, $this->street());
        $this->assertSame([[false], [false], []], [$anyCalls, $addrCalls, $pickCalls], 'the any carrier saw a street address; the pickup-only carrier was never called');
        $this->assertTrue($this->quoteOf($street, $any)->isAvailable());
        $this->assertTrue($this->quoteOf($street, $addr)->isAvailable());
        $this->assertFalse($this->quoteOf($street, $pick)->isAvailable());
        $this->assertSame(MethodQuote::DESTINATION_NOT_SERVED, $this->quoteOf($street, $pick)->unavailableReason);
        $this->assertFalse($this->quoteOf($street, $pick)->servesDestination);
        $this->assertSame('destination_not_served', MethodQuote::DESTINATION_NOT_SERVED);

        // As a pickup point (a different settlement, so the carrier answer cache cannot hide a call)
        $anyCalls = $addrCalls = $pickCalls = [];
        $pickup = $service->offers($cart, null, QuoteDestination::forAddress(AddressDeliveryType::PICKUP_POINT, 'BG', 'Plovdiv'));
        $this->assertSame([[true], [], [true]], [$anyCalls, $addrCalls, $pickCalls], 'the any carrier saw a pickup point; the address-only carrier was never called');
        $this->assertSame(MethodQuote::DESTINATION_NOT_SERVED, $this->quoteOf($pickup, $addr)->unavailableReason);
        $this->assertFalse($this->quoteOf($pickup, $addr)->servesDestination);
        $this->assertTrue($this->quoteOf($pickup, $pick)->isAvailable());
    }

    public function test_a_carrier_that_serves_the_kind_but_gets_no_answer_stays_no_quote(): void
    {
        $this->app->instance(CarrierCapability::RATE->containerKey('acme-none'), new FakeRateProvider(fn () => []));
        $this->app->make(CarrierRegistry::class)->register(new CarrierRegistration('acme-none', 'Acme', [CarrierCapability::RATE]));
        $any = $this->method('Any', ShippingDestinationScope::ANY, 0, kind: ShippingMethodKind::CARRIER, carrier: 'acme-none');

        $quote = $this->quoteOf(app(ShippingQuoteService::class)->offers($this->domainCart(), null, $this->street()), $any);

        $this->assertSame([MethodQuote::NO_QUOTE, true], [$quote->unavailableReason, $quote->servesDestination]);
    }
}
