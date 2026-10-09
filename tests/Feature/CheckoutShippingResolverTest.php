<?php

namespace Tests\Feature;

use App\Services\CheckoutShippingResolver;
use App\Services\QuoteDestination;
use App\Services\QuoteHandleStore;
use App\Services\ShippingQuoteService;
use App\Services\ShippingRefusal;
use App\Services\ShippingRefusalReason;
use App\Services\ShippingSelection;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Shipping\FakeRateProvider;
use Tests\Support\Shipping\Fakes;
use Tests\TestCase;

require_once __DIR__.'/../Support/Shipping/FakeCarrier.php';

/**
 * Shipping stage 4d (shipping-domain-design.md §9.1.3): CheckoutShippingResolver — (cart, destination, method id, quote
 * handle, expected amount) -> an immutable ShippingSelection or a NAMED refusal. Called directly; nothing is wired into
 * checkout. Real MySQL, real pricing, real zones, the real quote pipeline (so a handle is one the quote really issued).
 */
class CheckoutShippingResolverTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    private ?PriceList $priceList = null;

    private ?string $zoneId = null;

    /** @var array<string, true> */
    private array $classes = [];

    private function resolver(): CheckoutShippingResolver
    {
        return app(CheckoutShippingResolver::class);
    }

    private function variation(string $price, ?string $class = null): string
    {
        self::$counter++;
        $n = self::$counter;
        $product = Product::createSimple("Resolver Product {$n}", "RP-{$n}", "resolver-product-{$n}");

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
        app(PriceListItemRepository::class)->save(new PriceListItem(
            null, $this->priceList->id(), PriceListItemTargetType::VARIATION, $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($price, 'EUR'), 0),
        ));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 100));

        return $variationId;
    }

    private function zone(array $countries = ['BG']): string
    {
        $zone = ShippingZone::create('Zone '.(++self::$counter), 0, $countries);
        app(ShippingZoneRepository::class)->save($zone);

        return $this->zoneId = (string) $zone->id();
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
        bool $active = true,
        ShippingClassMode $mode = ShippingClassMode::REPLACE,
        ?string $courier = null,
        ?ShippingDeliveryType $type = null,
        ?string $zoneId = null,
    ): string {
        $zoneId ??= $this->zoneId ??= $this->zone();
        $method = ShippingMethod::create($zoneId, $name, $kind, $sort, $active, $amount, $classRates, $freeAbove, $carrier, $pickup, $mode, $courier, $type);
        app(ShippingMethodRepository::class)->save($method);

        return (string) $method->id();
    }

    /** @param array<int, array{0: string, 1: int}> $lines */
    private function cart(array $lines): Cart
    {
        $cart = Cart::forGuest((string) Str::uuid(), new \DateTimeImmutable('+10 days'));

        foreach ($lines as [$variationId, $quantity]) {
            app(CartLineAdder::class)->addLine($cart, $variationId, $quantity, null, null);
        }

        return app(CartRepository::class)->findById($cart->id());
    }

    private function bg(string $city = 'Sofia'): QuoteDestination
    {
        return new QuoteDestination(AddressDeliveryType::STREET_ADDRESS, 'BG', $city);
    }

    private function pickupPoint(): QuoteDestination
    {
        return new QuoteDestination(AddressDeliveryType::PICKUP_POINT, 'BG', 'Sofia');
    }

    /** The handle the real quote issues for a method (merchant filter included). */
    private function handleOf(Cart $cart, string $methodId, ?QuoteDestination $destination = null): string
    {
        return (string) app(ShippingQuoteService::class)->quote($cart, null, $destination ?? $this->bg())->method($methodId)->handle;
    }

    private function refusal(callable $operation): ShippingRefusal
    {
        try {
            $operation();
        } catch (ShippingRefusal $refusal) {
            return $refusal;
        }

        $this->fail('Expected a ShippingRefusal.');
    }

    private function fakeHandle(): string
    {
        return 'qh_'.Str::random(40);
    }

    // --- accept: the local recompute equals the quote, for every kind ---------------------------------------------

    public function test_every_local_kind_recomputes_to_exactly_the_quoted_amount_and_the_selection_carries_its_facts(): void
    {
        $light = $this->variation('10.00', 'light');
        $heavy = $this->variation('5.00', 'heavy');
        $ids = [
            'flat' => $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500),
            'free' => $this->method(ShippingMethodKind::FREE, 'Free', 1),
            'replace' => $this->method(ShippingMethodKind::PER_CLASS, 'Per class', 2, 400, ['light' => 300, 'heavy' => 900]),
            'adjust' => $this->method(ShippingMethodKind::PER_CLASS, 'Adjusting', 3, 400, ['heavy' => 250, 'light' => -50], mode: ShippingClassMode::ADJUST),
            'threshold' => $this->method(ShippingMethodKind::FLAT, 'Threshold', 4, 700, freeAbove: 3000),
            'office' => $this->method(ShippingMethodKind::FLAT, 'To address', 5, 450, courier: 'Econt', type: ShippingDeliveryType::ADDRESS),
        ];
        $cart = $this->cart([[$light, 2], [$heavy, 1]]);
        $quote = app(ShippingQuoteService::class)->quote($cart, null, $this->bg());

        foreach ($ids as $label => $id) {
            $quoted = $quote->method($id);
            $selection = $this->resolver()->resolve($cart, null, $this->bg(), $id, $quoted->handle, null);

            $this->assertInstanceOf(ShippingSelection::class, $selection);
            $this->assertSame($quoted->amountMinor, $selection->amount->minorValue(), "{$label}: the recompute equals the quote");
            $this->assertSame('EUR', $selection->amount->currency()->code());
            $this->assertSame($id, $selection->methodId);
            $this->assertSame($quoted->name, $selection->methodName);
            $this->assertSame($quoted->courier, $selection->courier);
            $this->assertSame($quoted->deliveryType, $selection->deliveryType);
            $this->assertNull($selection->serviceCode);
            $this->assertTrue($selection->isLocal());
        }

        $office = $this->resolver()->resolve($cart, null, $this->bg(), $ids['office'], $quote->method($ids['office'])->handle, null);
        $this->assertSame(['To address', 'Econt', 'address', 450, false], [$office->methodName, $office->courier, $office->deliveryType, $office->amount->minorValue(), $office->requiresPickupPoint]);
    }

    public function test_the_handle_is_not_consumed_by_a_successful_resolve(): void
    {
        $id = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cart = $this->cart([[$this->variation('10.00'), 1]]);
        $quote = app(ShippingQuoteService::class)->quote($cart, null, $this->bg());
        $handle = $quote->method($id)->handle;

        $this->resolver()->resolve($cart, null, $this->bg(), $id, $handle, null);
        $this->resolver()->resolve($cart, null, $this->bg(), $id, $handle, null);

        $this->assertTrue(app(QuoteHandleStore::class)->verify($handle, (string) $cart->id(), $id, 500, 'EUR', $quote->pricingHash, null));
    }

    // --- the merchant filter ---------------------------------------------------------------------------------------

    public function test_a_filter_that_removes_the_method_makes_it_unavailable_because_it_was_never_offered(): void
    {
        $id = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $other = $this->method(ShippingMethodKind::FLAT, 'Other', 1, 600);
        $cart = $this->cart([[$this->variation('10.00'), 1]]);
        $handle = $this->handleOf($cart, $id);

        Hook::filter('shipping.quotes', fn (array $methods): array => array_values(array_filter($methods, fn ($m) => $m->methodId !== $id)));

        $this->assertSame(ShippingRefusalReason::METHOD_UNAVAILABLE, $this->refusal(fn () => $this->resolver()->resolve($cart, null, $this->bg(), $id, $handle, 500))->reason);
        $this->assertSame(600, $this->resolver()->resolve($cart, null, $this->bg(), $other, $this->handleOf($cart, $other), null)->amount->minorValue());
    }

    public function test_a_filter_that_changes_the_amount_makes_the_selection_carry_the_filtered_amount_and_its_own_handle_verifies(): void
    {
        $id = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cart = $this->cart([[$this->variation('10.00'), 1]]);
        Hook::filter('shipping.quotes', fn (array $methods): array => array_map(
            fn ($m) => \App\Services\MethodQuote::priced($m->methodId, $m->name, $m->kind, $m->requiresPickupPoint, $m->currency, 250),
            $methods,
        ));
        $handle = $this->handleOf($cart, $id);

        $selection = $this->resolver()->resolve($cart, null, $this->bg(), $id, $handle, null);

        $this->assertSame(250, $selection->amount->minorValue(), 'the FILTERED amount, not the stored 500');
    }

    // --- the price decision table ------------------------------------------------------------------------------------

    public function test_row_1_a_verifying_handle_is_accepted_whatever_the_expected_amount_says(): void
    {
        $id = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cart = $this->cart([[$this->variation('10.00'), 1]]);
        $handle = $this->handleOf($cart, $id);

        foreach ([null, 500, 99999] as $expected) {
            $this->assertSame(500, $this->resolver()->resolve($cart, null, $this->bg(), $id, $handle, $expected)->amount->minorValue(), 'expected '.var_export($expected, true).' is only compared, never charged');
        }
    }

    public function test_row_2_a_handle_that_does_not_verify_and_an_expected_amount_that_differs_is_price_changed_with_the_new_figure(): void
    {
        $id = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cart = $this->cart([[$this->variation('10.00'), 1]]);

        $refusal = $this->refusal(fn () => $this->resolver()->resolve($cart, null, $this->bg(), $id, $this->fakeHandle(), 450));

        $this->assertSame(ShippingRefusalReason::PRICE_CHANGED, $refusal->reason);
        $this->assertSame([500, 'EUR'], [$refusal->newAmount()->minorValue(), $refusal->newAmount()->currency()->code()]);
    }

    public function test_row_3_a_lost_handle_is_tolerated_for_a_local_method_only_when_the_expected_amount_equals_the_recompute(): void
    {
        $id = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cart = $this->cart([[$this->variation('10.00'), 1]]);

        $selection = $this->resolver()->resolve($cart, null, $this->bg(), $id, $this->fakeHandle(), 500);

        $this->assertSame(500, $selection->amount->minorValue());
        $this->assertTrue($selection->isLocal());
    }

    public function test_row_4_everything_else_is_quote_expired_and_the_three_causes_are_not_told_apart(): void
    {
        $id = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cart = $this->cart([[$this->variation('10.00'), 1]]);
        $quote = app(ShippingQuoteService::class)->quote($cart, null, $this->bg());
        $valid = $quote->method($id)->handle;

        // unknown handle, no expected amount
        $this->assertSame(ShippingRefusalReason::QUOTE_EXPIRED, $this->refusal(fn () => $this->resolver()->resolve($cart, null, $this->bg(), $id, $this->fakeHandle(), null))->reason);

        // expired handle
        Cache::forget(QuoteHandleStore::KEY_PREFIX.hash('sha256', $valid));
        $this->assertSame(ShippingRefusalReason::QUOTE_EXPIRED, $this->refusal(fn () => $this->resolver()->resolve($cart, null, $this->bg(), $id, $valid, null))->reason);

        // a handle issued for another cart / method / amount / hash
        $store = app(QuoteHandleStore::class);
        $hash = $quote->pricingHash;
        foreach ([
            'another cart' => [$store->issue('999999', $id, 500, 'EUR', $hash, null)],
            'another method' => [$store->issue((string) $cart->id(), '424242', 500, 'EUR', $hash, null)],
            'another amount' => [$store->issue((string) $cart->id(), $id, 501, 'EUR', $hash, null)],
            'another hash' => [$store->issue((string) $cart->id(), $id, 500, 'EUR', hash('sha256', 'x'), null)],
            'another service' => [$store->issue((string) $cart->id(), $id, 500, 'EUR', $hash, 'address')],
        ] as $label => [$foreign]) {
            $this->assertSame(ShippingRefusalReason::QUOTE_EXPIRED, $this->refusal(fn () => $this->resolver()->resolve($cart, null, $this->bg(), $id, $foreign, null))->reason, $label);
        }
    }

    public function test_goods_changed_after_the_quote_falls_out_of_the_handle(): void
    {
        $threshold = $this->method(ShippingMethodKind::FLAT, 'Threshold', 0, 700, freeAbove: 3000);
        $a = $this->variation('10.00');
        $cart = $this->cart([[$a, 1]]);
        $handle = $this->handleOf($cart, $threshold);

        app(CartLineAdder::class)->addLine($cart, $a, 3, null, null);   // 40.00 of goods now: the threshold is crossed
        $changed = app(CartRepository::class)->findById($cart->id());

        $this->assertSame(ShippingRefusalReason::QUOTE_EXPIRED, $this->refusal(fn () => $this->resolver()->resolve($changed, null, $this->bg(), $threshold, $handle, null))->reason, 'the pricing hash differs');
        $moved = $this->refusal(fn () => $this->resolver()->resolve($changed, null, $this->bg(), $threshold, $handle, 700));
        $this->assertSame(ShippingRefusalReason::PRICE_CHANGED, $moved->reason, 'the customer saw 7.00; it is free now');
        $this->assertSame(0, $moved->newAmount()->minorValue());
    }

    public function test_a_destination_changed_after_the_quote_falls_out_of_the_handle(): void
    {
        $id = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cart = $this->cart([[$this->variation('10.00'), 1]]);
        $handle = $this->handleOf($cart, $id, $this->bg('Sofia'));

        $this->assertSame(ShippingRefusalReason::QUOTE_EXPIRED, $this->refusal(fn () => $this->resolver()->resolve($cart, null, $this->bg('Varna'), $id, $handle, null))->reason);
    }

    // --- availability, zone, activity -------------------------------------------------------------------------------

    public function test_an_unknown_inactive_or_other_zone_method_is_method_unavailable(): void
    {
        $inactive = $this->method(ShippingMethodKind::FLAT, 'Inactive', 0, 500, active: false);
        $otherZone = $this->method(ShippingMethodKind::FLAT, 'Germany', 1, 500, zoneId: $this->zone(['DE']));
        $this->zoneId = null;
        $this->method(ShippingMethodKind::FLAT, 'Home', 2, 500, zoneId: $this->zone(['BG']));
        $cart = $this->cart([[$this->variation('10.00'), 1]]);

        foreach (['inactive' => $inactive, 'other zone' => $otherZone, 'unknown' => '424242'] as $label => $id) {
            $this->assertSame(ShippingRefusalReason::METHOD_UNAVAILABLE, $this->refusal(fn () => $this->resolver()->resolve($cart, null, $this->bg(), $id, $this->fakeHandle(), 500))->reason, $label);
        }
    }

    public function test_a_destination_no_zone_covers_is_method_unavailable(): void
    {
        $id = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cart = $this->cart([[$this->variation('10.00'), 1]]);

        $this->assertSame(ShippingRefusalReason::METHOD_UNAVAILABLE, $this->refusal(fn () => $this->resolver()->resolve($cart, null, new QuoteDestination(AddressDeliveryType::STREET_ADDRESS, 'FR', 'Paris'), $id, $this->fakeHandle(), 500))->reason);
    }

    public function test_a_carrier_method_that_is_not_configured_is_method_unavailable(): void
    {
        $id = $this->method(ShippingMethodKind::CARRIER, 'Ghost carrier', 0, carrier: 'ghost');
        $cart = $this->cart([[$this->variation('10.00'), 1]]);

        $this->assertSame(ShippingRefusalReason::METHOD_UNAVAILABLE, $this->refusal(fn () => $this->resolver()->resolve($cart, null, $this->bg(), $id, $this->fakeHandle(), 500))->reason);
    }

    public function test_a_configured_carrier_is_accepted_only_through_a_verifying_handle_and_carries_its_service(): void
    {
        $this->app->instance(CarrierCapability::RATE->containerKey('acme'), new FakeRateProvider(fn () => [Fakes::quote(600), new \EasyCo\Shipping\Carrier\ShippingQuote('address', 'To address', 450, 'EUR')]));
        $this->app->make(CarrierRegistry::class)->register(new CarrierRegistration('acme', 'Acme', [CarrierCapability::RATE]));
        $id = $this->method(ShippingMethodKind::CARRIER, 'Acme', 0, carrier: 'acme');
        $cart = $this->cart([[$this->variation('10.00'), 1]]);
        $handle = $this->handleOf($cart, $id);

        $selection = $this->resolver()->resolve($cart, null, $this->bg(), $id, $handle, null);

        $this->assertFalse($selection->isLocal());
        $this->assertSame(['address', 450], [$selection->serviceCode, $selection->amount->minorValue()]);

        // Row 3 never applies to a carrier: a lost handle is quote_expired even when the figure matches.
        $this->assertSame(ShippingRefusalReason::QUOTE_EXPIRED, $this->refusal(fn () => $this->resolver()->resolve($cart, null, $this->bg(), $id, $this->fakeHandle(), 450))->reason);
    }

    // --- the pickup flag ---------------------------------------------------------------------------------------------

    public function test_the_pickup_flag_must_agree_with_the_address_both_ways(): void
    {
        $pickup = $this->method(ShippingMethodKind::FLAT, 'To locker', 0, 300, pickup: true);
        $home = $this->method(ShippingMethodKind::FLAT, 'To door', 1, 500);
        $cart = $this->cart([[$this->variation('10.00'), 1]]);

        $this->assertSame(ShippingRefusalReason::PICKUP_MISMATCH, $this->refusal(fn () => $this->resolver()->resolve($cart, null, $this->bg(), $pickup, $this->fakeHandle(), 300))->reason, 'a pickup method to a street address');
        $this->assertSame(ShippingRefusalReason::PICKUP_MISMATCH, $this->refusal(fn () => $this->resolver()->resolve($cart, null, $this->pickupPoint(), $home, $this->fakeHandle(), 500))->reason, 'a home method to a pickup point');

        $ok = $this->resolver()->resolve($cart, null, $this->pickupPoint(), $pickup, $this->handleOf($cart, $pickup, $this->pickupPoint()), null);
        $this->assertTrue($ok->requiresPickupPoint);
        $this->assertSame(300, $ok->amount->minorValue());
    }

    // --- shape: required, invalid, no lookups ------------------------------------------------------------------------

    public function test_a_missing_method_id_or_handle_is_required(): void
    {
        $id = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cart = $this->cart([[$this->variation('10.00'), 1]]);

        foreach ([[null, null], ['', ''], [$id, null], [$id, ''], [null, $this->fakeHandle()]] as [$methodId, $handle]) {
            $this->assertSame(ShippingRefusalReason::REQUIRED, $this->refusal(fn () => $this->resolver()->resolve($cart, null, $this->bg(), $methodId, $handle, 500))->reason);
        }
    }

    /** @return array<string, array{?string, ?string}> */
    public static function malformed(): array
    {
        $good = 'qh_'.str_repeat('a', 40);

        return [
            'letters in the id' => ['abc', $good],
            'space in the id' => ['12 3', $good],
            'sql in the id' => ["1' OR '1'='1", $good],
            'script in the id' => ['<script>alert(1)</script>', $good],
            'negative id' => ['-5', $good],
            '21 digits' => [str_repeat('9', 21), $good],
            '10,000 character id' => [str_repeat('9', 10000), $good],
            'no prefix' => ['5', str_repeat('a', 43)],
            'short handle' => ['5', 'qh_short'],
            'long handle' => ['5', 'qh_'.str_repeat('a', 41)],
            'dash in the handle' => ['5', 'qh_'.str_repeat('a', 39).'-'],
            'sql in the handle' => ['5', "qh_' OR '1'='1"],
            'script in the handle' => ['5', '<script>alert(1)</script>'],
            '10,000 character handle' => ['5', 'qh_'.str_repeat('a', 9997)],
            'trailing newline' => ['5', $good."\n"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function test_a_malformed_value_is_invalid_without_any_database_or_cache_read_and_is_never_echoed(?string $methodId, ?string $handle): void
    {
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cart = $this->cart([[$this->variation('10.00'), 1]]);
        $destination = $this->bg();
        Cache::spy();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $refusal = $this->refusal(fn () => $this->resolver()->resolve($cart, null, $destination, $methodId, $handle, 500));

        $this->assertSame(ShippingRefusalReason::INVALID, $refusal->reason);
        $this->assertSame([], $queries, 'no query ran');
        Cache::shouldNotHaveReceived('get');
        Cache::shouldNotHaveReceived('put');
        foreach (array_filter([$methodId, $handle], fn ($v) => $v !== null && strlen($v) > 4) as $typed) {
            $this->assertStringNotContainsString(substr($typed, 0, 12), $refusal->getMessage());
        }
    }

    // --- the reason values ---------------------------------------------------------------------------------------------

    public function test_the_refusal_reasons_are_exactly_the_six_api_codes(): void
    {
        $this->assertSame(
            ['shipping_required', 'shipping_invalid', 'shipping_method_unavailable', 'shipping_quote_expired', 'shipping_price_changed', 'shipping_pickup_mismatch'],
            array_map(fn (ShippingRefusalReason $r) => $r->value, ShippingRefusalReason::cases()),
        );
    }

    // --- cost ---------------------------------------------------------------------------------------------------------

    public function test_a_successful_resolve_costs_what_the_quote_pipeline_costs_and_nothing_per_line(): void
    {
        $id = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $this->method(ShippingMethodKind::FLAT, 'Threshold', 1, 700, freeAbove: 10000);
        $lines = [];
        for ($i = 0; $i < 8; $i++) {
            $lines[] = [$this->variation('10.00'), 1];
        }
        $cart = $this->cart($lines);
        $quote = app(ShippingQuoteService::class)->quote($cart, null, $this->bg());
        $handle = $quote->method($id)->handle;

        $count = function (callable $operation): int {
            $this->app->forgetScopedInstances();
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            $operation();

            return $n;
        };

        $viaQuote = $count(fn () => app(ShippingQuoteService::class)->quote($cart, null, $this->bg()));
        $viaResolver = $count(fn () => $this->resolver()->resolve($cart, null, $this->bg(), $id, $handle, null));

        fwrite(STDERR, sprintf("\n[query-count] CheckoutShippingResolver::resolve() (cold, 8 lines): %d queries | ShippingQuoteService::quote() (cold, same cart): %d queries\n", $viaResolver, $viaQuote));

        $this->assertSame($viaQuote, $viaResolver, 'exactly the quote pipeline\'s own reads');
    }
}
