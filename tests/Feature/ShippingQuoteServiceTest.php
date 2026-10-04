<?php

namespace Tests\Feature;

use App\Services\CarrierQuoteCache;
use App\Services\Exceptions\ShippingQuoteRefusedException;
use App\Services\MethodQuote;
use App\Services\QuoteCachePolicy;
use App\Services\QuoteDestination;
use App\Services\QuoteHandleStore;
use App\Services\ShippingQuoteService;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLineAdder;
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
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Promotion;
use EasyCo\Shipping\Carrier\CarrierCapability;
use EasyCo\Shipping\Carrier\CarrierRegistration;
use EasyCo\Shipping\Carrier\CarrierRegistry;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingClass;
use EasyCo\Shipping\ShippingMethod;
use EasyCo\Shipping\ShippingZone;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\Shipping\FakeRateProvider;
use Tests\Support\Shipping\Fakes;
use Tests\TestCase;

require_once __DIR__.'/../Support/Shipping/FakeCarrier.php';

/**
 * Shipping stage 3d part 1 (shipping-domain-design.md §6.7): the quote service and
 * the quote handle, called directly. Real MySQL, real pricing, real zones.
 *
 * Fixture: variations at 10.00 (class "light"), 5.00 (class "heavy") and one with no
 * price; one zone "Bulgaria" (BG). Methods are added per test.
 */
class ShippingQuoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    private ?PriceList $priceList = null;

    private ?string $zoneId = null;

    private function service(): ShippingQuoteService
    {
        return app(ShippingQuoteService::class);
    }

    private function variation(?string $price, ?string $class = null, ?int $weight = null): string
    {
        self::$counter++;
        $n = self::$counter;

        $product = Product::createSimple("Quote Product {$n}", "QP-{$n}", "quote-product-{$n}");
        $variation = $product->variations()[0];

        if ($class !== null) {
            $this->shippingClass($class);
            $variation->setShippingClass($class);
        }

        if ($weight !== null) {
            $variation->setDimensions($weight, null, null, null);
        }

        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        if ($price !== null) {
            app(PriceListItemRepository::class)->save(new PriceListItem(
                null, $this->priceList->id(), PriceListItemTargetType::VARIATION, $variationId,
                Price::exclusiveOfTax(Money::fromDecimal($price, 'EUR'), 0),
            ));
        }

        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 100));

        return $variationId;
    }

    /** @var array<string, true> */
    private array $classes = [];

    private function shippingClass(string $code): void
    {
        if (! isset($this->classes[$code])) {
            app(ShippingClassRepository::class)->save(ShippingClass::create(ucfirst($code), $code));
            $this->classes[$code] = true;
        }
    }

    private function zone(array $countries = ['BG'], ?array $settlements = null): string
    {
        $zone = ShippingZone::create('Zone '.(++self::$counter), 0, $countries, $settlements);
        app(ShippingZoneRepository::class)->save($zone);

        return $this->zoneId = (string) $zone->id();
    }

    private function method(ShippingMethodKind $kind, string $name, int $sort = 0, ?int $amount = null, array $classRates = [], ?int $freeAbove = null, ?string $carrier = null, bool $pickup = false, bool $active = true): string
    {
        $this->zoneId ??= $this->zone();
        $method = ShippingMethod::create($this->zoneId, $name, $kind, $sort, $active, $amount, $classRates, $freeAbove, $carrier, $pickup);
        app(ShippingMethodRepository::class)->save($method);

        return (string) $method->id();
    }

    /** @param array<int, array{0: string, 1: int}> $lines */
    private function cart(array $lines, ?string $accountId = null, ?string $code = null): Cart
    {
        $cart = $accountId !== null ? Cart::forAccount($accountId, new \DateTimeImmutable('+10 days')) : Cart::forGuest((string) Str::uuid(), new \DateTimeImmutable('+10 days'));

        if ($lines === []) {
            app(CartRepository::class)->save($cart);
        }

        foreach ($lines as [$variationId, $quantity]) {
            app(CartLineAdder::class)->addLine($cart, $variationId, $quantity, null, null);
        }

        $cart = app(CartRepository::class)->findById($cart->id());

        if ($code !== null) {
            $cart->applyPromotionCode($code);
            app(CartRepository::class)->save($cart);
            $cart = app(CartRepository::class)->findById($cart->id());
        }

        return $cart;
    }

    private function account(string $email = 'buyer@example.com'): string
    {
        $account = Account::register($email, 'hashed-password');
        app(AccountRepository::class)->save($account);

        return (string) $account->id();
    }

    private function bg(string $city = 'Sofia', ?string $postcode = null): QuoteDestination
    {
        return new QuoteDestination(AddressDeliveryType::STREET_ADDRESS, 'BG', $city, $postcode);
    }

    private function amounts($result): array
    {
        $out = [];

        foreach ($result->methods as $m) {
            $out[$m->name] = $m->amountMinor ?? $m->unavailableReason;
        }

        return $out;
    }

    private function promotion(string $code, int $basisPoints): void
    {
        app(PromotionRepository::class)->save(Promotion::create(code: $code, discountType: PromotionDiscountType::PERCENTAGE, percentageBasisPoints: $basisPoints));
    }

    private function refusal(callable $operation): ShippingQuoteRefusedException
    {
        try {
            $operation();
        } catch (ShippingQuoteRefusedException $e) {
            return $e;
        }

        $this->fail('the quote should have been refused');
    }

    /** What an extension package's provider does for a carrier with a rate capability. */
    private function carrier(string $code, FakeRateProvider $provider): void
    {
        $this->app->instance(CarrierCapability::RATE->containerKey($code), $provider);
        $this->app->make(CarrierRegistry::class)->register(new CarrierRegistration($code, ucfirst($code), [CarrierCapability::RATE]));
    }

    // --- who may be quoted, and what is refused -----------------------------------------------------------------------------

    public function test_a_guest_cart_is_quoted_with_the_zones_active_methods_in_order(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $this->method(ShippingMethodKind::FREE, 'Pickup in shop', 1);
        $this->method(ShippingMethodKind::FLAT, 'Hidden', 2, 100, active: false);

        $result = $this->service()->quote($this->cart([[$a, 2]]), null, $this->bg());

        $this->assertSame(['Flat' => 500, 'Pickup in shop' => 0], $this->amounts($result), 'inactive methods are not offered');
        $this->assertSame('EUR', $result->currency);
        $this->assertSame(2000, $result->goodsAfterDiscountMinor);
        $this->assertSame($this->zoneId, $result->zoneId);
        $this->assertSame(['flat', 'free'], array_map(static fn (MethodQuote $m): string => $m->kind, $result->methods));
    }

    public function test_a_customer_cart_is_quoted_and_the_account_reaches_the_promotion_rules(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $accountId = $this->account();
        $this->promotion('HALF', 5000);

        $result = $this->service()->quote($this->cart([[$a, 2]], $accountId, 'HALF'), $accountId, $this->bg());

        $this->assertSame(1000, $result->goodsAfterDiscountMinor, '20.00 less 50%');
        $this->assertSame(['Flat' => 500], $this->amounts($result));
    }

    public function test_an_empty_cart_is_refused(): void
    {
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);

        $e = $this->refusal(fn () => $this->service()->quote($this->cart([]), null, $this->bg()));

        $this->assertSame('empty_cart', $e->reason);
    }

    public function test_a_cart_with_no_priced_line_is_refused_rather_than_quoted_for_nothing(): void
    {
        $unpriced = $this->variation(null);
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);

        $e = $this->refusal(fn () => $this->service()->quote($this->cart([[$unpriced, 1]]), null, $this->bg()));

        $this->assertSame('no_priced_lines', $e->reason);
    }

    public function test_a_destination_no_zone_covers_is_refused_with_no_zone_for_destination(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);

        $e = $this->refusal(fn () => $this->service()->quote($this->cart([[$a, 1]]), null, new QuoteDestination(AddressDeliveryType::STREET_ADDRESS, 'RO', 'Cluj')));

        $this->assertSame('no_zone_for_destination', $e->reason);
    }

    public function test_a_saved_address_without_a_country_is_refused_with_address_incomplete(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $accountId = $this->account();
        $address = Address::create(AddressDeliveryType::PICKUP_POINT, 'Maria P', '+359888654321', $accountId, 'BG', carrierCode: 'econt', pickupPointReference: 'o-1', settlement: 'Varna');
        app(AddressRepository::class)->save($address);
        DB::table('addresses')->where('id', $address->id())->update(['country' => null]);

        $e = $this->refusal(fn () => $this->service()->quoteForSavedAddress($this->cart([[$a, 1]], $accountId), $accountId, (string) $address->id()));

        $this->assertSame('address_incomplete', $e->reason);
    }

    public function test_an_unknown_or_foreign_address_is_refused_the_same_way(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $mine = $this->account('mine@example.com');
        $other = $this->account('other@example.com');
        $theirs = Address::create(AddressDeliveryType::STREET_ADDRESS, 'Ivan I', '+359888123456', $other, 'BG', 'Sofia', addressLine1: 'Vitosha 1');
        app(AddressRepository::class)->save($theirs);

        $cart = $this->cart([[$a, 1]], $mine);
        $foreign = $this->refusal(fn () => $this->service()->quoteForSavedAddress($cart, $mine, (string) $theirs->id()));
        $unknown = $this->refusal(fn () => $this->service()->quoteForSavedAddress($cart, $mine, '999999'));

        $this->assertSame('address_not_found', $foreign->reason);
        $this->assertSame('address_not_found', $unknown->reason, 'indistinguishable from a foreign one');
    }

    public function test_a_saved_street_address_and_a_saved_pickup_point_are_quoted_by_their_city_and_settlement(): void
    {
        $a = $this->variation('10.00');
        $this->zone(['BG'], ['Sofia']);
        $this->method(ShippingMethodKind::FLAT, 'Sofia only', 0, 300);
        $accountId = $this->account();
        $street = Address::create(AddressDeliveryType::STREET_ADDRESS, 'Ivan I', '+359888123456', $accountId, 'BG', 'Sofia', addressLine1: 'Vitosha 1');
        $point = Address::create(AddressDeliveryType::PICKUP_POINT, 'Ivan I', '+359888123456', $accountId, 'BG', carrierCode: 'econt', pickupPointReference: 'o-1', settlement: 'Sofia');
        $varna = Address::create(AddressDeliveryType::PICKUP_POINT, 'Ivan I', '+359888123456', $accountId, 'BG', carrierCode: 'econt', pickupPointReference: 'o-2', settlement: 'Varna');
        foreach ([$street, $point, $varna] as $address) {
            app(AddressRepository::class)->save($address);
        }
        $cart = $this->cart([[$a, 1]], $accountId);

        $this->assertSame(['Sofia only' => 300], $this->amounts($this->service()->quoteForSavedAddress($cart, $accountId, (string) $street->id())));
        $this->assertSame(['Sofia only' => 300], $this->amounts($this->service()->quoteForSavedAddress($cart, $accountId, (string) $point->id())));
        $this->assertSame('no_zone_for_destination', $this->refusal(fn () => $this->service()->quoteForSavedAddress($cart, $accountId, (string) $varna->id()))->reason);
    }

    // --- the local rates, with exact amounts --------------------------------------------------------------------------------

    public function test_flat_free_per_class_and_threshold_methods_are_priced_exactly(): void
    {
        $light = $this->variation('10.00', 'light');
        $heavy = $this->variation('5.00', 'heavy');
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $this->method(ShippingMethodKind::FREE, 'Free', 1);
        $this->method(ShippingMethodKind::PER_CLASS, 'Per class', 2, 400, ['light' => 300, 'heavy' => 900]);
        $this->method(ShippingMethodKind::FLAT, 'Threshold', 3, 700, freeAbove: 3000);

        $result = $this->service()->quote($this->cart([[$light, 2], [$heavy, 1]]), null, $this->bg());

        $this->assertSame(
            ['Flat' => 500, 'Free' => 0, 'Per class' => 900, 'Threshold' => 700],
            $this->amounts($result),
            'goods 25.00: per class charges the MOST EXPENSIVE class (9.00, not 12.00); 25.00 is below the 30.00 threshold',
        );
    }

    public function test_the_threshold_uses_goods_after_the_discount_a_promotion_can_make_shipping_chargeable_again(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Threshold', 0, 700, freeAbove: 3000);
        $this->promotion('HALF', 5000);

        $before = $this->service()->quote($this->cart([[$a, 4]]), null, $this->bg());
        $after = $this->service()->quote($this->cart([[$a, 4]], null, 'HALF'), null, $this->bg());

        $this->assertSame(4000, $before->goodsAfterDiscountMinor);
        $this->assertSame(['Threshold' => 0], $this->amounts($before), '40.00 clears the 30.00 threshold');
        $this->assertSame(2000, $after->goodsAfterDiscountMinor);
        $this->assertSame(['Threshold' => 700], $this->amounts($after), 'after a 50% promotion the goods are 20.00: below the threshold, shipping is charged again');
    }

    public function test_an_unpriced_line_is_skipped_and_does_not_count_toward_the_threshold(): void
    {
        $priced = $this->variation('10.00');
        $unpriced = $this->variation(null);
        $this->method(ShippingMethodKind::FLAT, 'Threshold', 0, 700, freeAbove: 3000);

        $result = $this->service()->quote($this->cart([[$priced, 2], [$unpriced, 50]]), null, $this->bg());

        $this->assertSame(2000, $result->goodsAfterDiscountMinor);
        $this->assertSame(['Threshold' => 700], $this->amounts($result), 'fifty unpriced units add nothing to the goods');
    }

    // --- CARRIER methods ----------------------------------------------------------------------------------------------------

    public function test_a_carrier_method_answers_through_the_provider_with_its_cheapest_quote(): void
    {
        $a = $this->variation('10.00', weight: 500);
        $provider = new FakeRateProvider(fn ($context) => [Fakes::quote(600), new \EasyCo\Shipping\Carrier\ShippingQuote('address', 'To address', 450, 'EUR')]);
        $this->carrier('acme', $provider);
        $this->method(ShippingMethodKind::CARRIER, 'Acme', 0, carrier: 'acme');

        $result = $this->service()->quote($this->cart([[$a, 3]]), null, $this->bg('Plovdiv'));

        $method = $result->methods[0];
        $this->assertSame(450, $method->amountMinor);
        $this->assertSame('address', $method->serviceCode, 'the cheapest quote of the carrier');
        $this->assertNotNull($method->handle);
        $this->assertSame(QuoteCachePolicy::CARRIER_BUDGET_MS, $provider->lastBudget->inMilliseconds(), 'an explicit budget is handed to the provider');
    }

    public function test_a_carrier_context_carries_destination_goods_value_and_the_summed_weight_but_no_cost(): void
    {
        $a = $this->variation('10.00', weight: 500);
        $seen = null;
        $this->carrier('acme', new FakeRateProvider(function ($context) use (&$seen) {
            $seen = $context;

            return [];
        }));
        $this->method(ShippingMethodKind::CARRIER, 'Acme', 0, carrier: 'acme');

        $this->service()->quote($this->cart([[$a, 3]]), null, $this->bg('Plovdiv'));

        $this->assertSame(['country' => 'BG', 'settlement' => 'Plovdiv', 'pickup' => false, 'currency' => 'EUR', 'goods' => 3000, 'cod' => null, 'weight' => 1500, 'length' => null, 'width' => null, 'height' => null], $seen->toCanonicalArray());
    }

    public function test_a_line_with_unknown_weight_makes_the_parcel_weight_unknown_not_a_partial_sum(): void
    {
        $known = $this->variation('10.00', weight: 500);
        $unknown = $this->variation('5.00');
        $seen = null;
        $this->carrier('acme', new FakeRateProvider(function ($context) use (&$seen) {
            $seen = $context;

            return [];
        }));
        $this->method(ShippingMethodKind::CARRIER, 'Acme', 0, carrier: 'acme');

        $this->service()->quote($this->cart([[$known, 1], [$unknown, 1]]), null, $this->bg());

        $this->assertNull($seen->weightGrams);
    }

    public function test_a_failing_carrier_is_unavailable_while_the_local_methods_still_answer(): void
    {
        $a = $this->variation('10.00');
        $this->carrier('acme', new FakeRateProvider(fn () => throw new RuntimeException('down')));
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $this->method(ShippingMethodKind::CARRIER, 'Acme', 1, carrier: 'acme');

        $result = $this->service()->quote($this->cart([[$a, 1]]), null, $this->bg());

        $this->assertSame(['Flat' => 500, 'Acme' => 'provider_error'], $this->amounts($result));
        $this->assertNull($result->methods[1]->handle, 'no price, no handle');
        $this->assertNotNull($result->methods[0]->handle);
    }

    public function test_a_carrier_that_is_not_configured_and_one_that_offers_nothing_are_unavailable_with_their_reasons(): void
    {
        $a = $this->variation('10.00');
        $this->carrier('quiet', new FakeRateProvider(fn () => []));
        $this->method(ShippingMethodKind::CARRIER, 'Nobody', 0, carrier: 'nobody');
        $this->method(ShippingMethodKind::CARRIER, 'Quiet', 1, carrier: 'quiet');

        $result = $this->service()->quote($this->cart([[$a, 1]]), null, $this->bg());

        $this->assertSame(['Nobody' => 'not_configured', 'Quiet' => 'no_quote'], $this->amounts($result));
    }

    public function test_a_destination_with_no_settlement_cannot_be_quoted_by_a_carrier(): void
    {
        $a = $this->variation('10.00');
        $this->carrier('acme', new FakeRateProvider(fn () => [Fakes::quote(600)]));
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $this->method(ShippingMethodKind::CARRIER, 'Acme', 1, carrier: 'acme');

        $result = $this->service()->quote($this->cart([[$a, 1]]), null, new QuoteDestination(AddressDeliveryType::STREET_ADDRESS, 'BG', null, '1000'));

        $this->assertSame(['Flat' => 500, 'Acme' => 'no_settlement'], $this->amounts($result));
    }

    public function test_a_second_identical_call_hits_the_cache_and_a_different_context_does_not(): void
    {
        $a = $this->variation('10.00');
        $calls = 0;
        $this->carrier('acme', new FakeRateProvider(function () use (&$calls) {
            $calls++;

            return [Fakes::quote(600)];
        }));
        $this->method(ShippingMethodKind::CARRIER, 'Acme', 0, carrier: 'acme');
        $cart = $this->cart([[$a, 1]]);

        $this->service()->quote($cart, null, $this->bg());
        $this->service()->quote($cart, null, $this->bg());
        $this->assertSame(1, $calls, 'the second identical call is served from the cache');

        $this->service()->quote($cart, null, $this->bg('Varna'));
        $this->assertSame(2, $calls, 'another settlement is another cache key');
    }

    public function test_the_cache_key_follows_the_design_and_holds_no_customer_identity(): void
    {
        $key = CarrierQuoteCache::keyFor('acme', Fakes::context('Sofia'));

        $this->assertMatchesRegularExpression('/^shipping:quote:v1:acme:[0-9a-f]{64}$/D', $key);
        $this->assertNotSame($key, CarrierQuoteCache::keyFor('acme', Fakes::context('Varna')));
        $this->assertNotSame($key, CarrierQuoteCache::keyFor('beta', Fakes::context('Sofia')));
    }

    public function test_an_unavailable_result_is_cached_briefly_and_an_answer_for_ten_minutes(): void
    {
        $a = $this->variation('10.00');
        $calls = 0;
        $fail = true;
        $this->carrier('acme', new FakeRateProvider(function () use (&$calls, &$fail) {
            $calls++;

            return $fail ? throw new RuntimeException('down') : [Fakes::quote(600)];
        }));
        $this->method(ShippingMethodKind::CARRIER, 'Acme', 0, carrier: 'acme');
        $cart = $this->cart([[$a, 1]]);

        $this->service()->quote($cart, null, $this->bg());
        $this->service()->quote($cart, null, $this->bg());
        $this->assertSame(1, $calls, 'a failing carrier is not hammered');

        $fail = false;
        Carbon::setTestNow(Carbon::now()->addSeconds(QuoteCachePolicy::UNAVAILABLE_TTL + 1));
        $this->assertSame(['Acme' => 600], $this->amounts($this->service()->quote($cart, null, $this->bg())), 'after 30 s the carrier is asked again and has recovered');

        Carbon::setTestNow(Carbon::now()->addSeconds(QuoteCachePolicy::ANSWER_TTL - 5));
        $this->service()->quote($cart, null, $this->bg());
        $this->assertSame(2, $calls, 'an answer is still cached inside ten minutes');

        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $this->service()->quote($cart, null, $this->bg());
        $this->assertSame(3, $calls, 'and expired after');
        Carbon::setTestNow();
    }

    public function test_a_broken_cache_never_takes_the_quote_down(): void
    {
        $a = $this->variation('10.00');
        $this->carrier('acme', new FakeRateProvider(fn () => [Fakes::quote(600)]));
        $this->method(ShippingMethodKind::CARRIER, 'Acme', 0, carrier: 'acme');
        config(['cache.default' => 'broken', 'cache.stores.broken' => ['driver' => 'nonexistent-driver']]);
        app('cache')->forgetDriver();

        try {
            \Illuminate\Support\Facades\Cache::get('probe');
            $this->fail('the fixture must really break the cache');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $cart = $this->cart([[$a, 1]]);
        // The handle store also needs the cache: a quote cannot be issued handles without it, but the carrier
        // call itself must have degraded to "ask the carrier", so the failure here is the HANDLE's, not the cache read's.
        $offers = $this->service()->offers($cart, null, $this->bg());

        $this->assertSame(600, $offers->methods[0]->amountMinor);
    }

    // --- structure: handles last, nothing written ---------------------------------------------------------------------------

    public function test_offers_carry_no_handle_and_issue_handles_adds_one_per_priced_method_last(): void
    {
        $a = $this->variation('10.00');
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $this->carrier('acme', new FakeRateProvider(fn () => throw new RuntimeException('down')));
        $this->method(ShippingMethodKind::CARRIER, 'Acme', 1, carrier: 'acme');

        $offers = $this->service()->offers($this->cart([[$a, 1]]), null, $this->bg());
        $this->assertSame([null, null], array_map(static fn (MethodQuote $m): ?string => $m->handle, $offers->methods));

        $result = $this->service()->issueHandles($offers);
        $this->assertNotNull($result->methods[0]->handle);
        $this->assertNull($result->methods[1]->handle);
    }

    public function test_a_handle_names_the_price_finally_offered_so_a_filter_between_the_steps_is_honoured(): void
    {
        $a = $this->variation('10.00');
        $methodId = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cart = $this->cart([[$a, 1]]);

        $offers = $this->service()->offers($cart, null, $this->bg());
        $filtered = $offers->withMethods([MethodQuote::priced($methodId, 'Flat', 'flat', false, 'EUR', 250)]);
        $result = $this->service()->issueHandles($filtered);

        $handle = $result->methods[0]->handle;
        $store = app(QuoteHandleStore::class);
        $this->assertTrue($store->verify($handle, (string) $cart->id(), $methodId, 250, 'EUR', $result->pricingHash));
        $this->assertFalse($store->verify($handle, (string) $cart->id(), $methodId, 500, 'EUR', $result->pricingHash), 'the pre-filter price is not what the handle says');
    }

    public function test_a_quote_writes_no_database_row_and_opens_no_transaction(): void
    {
        $light = $this->variation('10.00', 'light', 300);
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $this->method(ShippingMethodKind::PER_CLASS, 'Per class', 1, 400, ['light' => 300]);
        $this->carrier('acme', new FakeRateProvider(fn () => [Fakes::quote(600)]));
        $this->method(ShippingMethodKind::CARRIER, 'Acme', 2, carrier: 'acme');
        $accountId = $this->account();
        $cart = $this->cart([[$light, 2]], $accountId);
        $before = $this->rowCounts();

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $transactions = 0;
        Event::listen(TransactionBeginning::class, function () use (&$transactions): void {
            $transactions++;
        });

        $this->service()->quote($cart, $accountId, $this->bg());

        $this->assertSame($before, $this->rowCounts(), 'no table gained or lost a row');
        $this->assertSame(0, $transactions, 'no transaction was opened');
        $this->assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace)\b/i', $sql) === 1)), 'no write statement was issued');
    }

    public function test_the_shipping_classes_of_the_lines_are_read_in_one_variation_query(): void
    {
        $a = $this->variation('10.00', 'light');
        $b = $this->variation('5.00', 'heavy');
        $c = $this->variation('2.00', 'heavy');
        $this->method(ShippingMethodKind::PER_CLASS, 'Per class', 0, 400, ['light' => 300]);
        $cart = $this->cart([[$a, 1], [$b, 1], [$c, 1]]);

        $variationReads = 0;
        DB::listen(function ($query) use (&$variationReads): void {
            if (str_contains($query->sql, 'catalog_variations') && str_starts_with(strtolower(ltrim($query->sql)), 'select') && str_contains($query->sql, ' in (?, ?, ?)')) {
                $variationReads++;
            }
        });

        $this->service()->quote($cart, null, $this->bg());

        // (CartPricing's own line pricer reads each variation singly; that is the shared calculation's cost, not this service's.)
        $this->assertSame(1, $variationReads, 'VariationRepository::findByIds: ONE query naming all three lines');
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (DB::select('show tables') as $row) {
            $table = (string) array_values((array) $row)[0];
            $counts[$table] = (int) DB::table($table)->count();
        }

        return $counts;
    }

    // --- the quote handle -------------------------------------------------------------------------------------------------

    /** @return array{ShippingQuoteService, mixed, Cart, string} */
    private function quotedFlat(): array
    {
        $a = $this->variation('10.00');
        $methodId = $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $cart = $this->cart([[$a, 1]]);
        $result = $this->service()->quote($cart, null, $this->bg());

        return [$this->service(), $result, $cart, $methodId];
    }

    public function test_verify_accepts_an_unchanged_quote(): void
    {
        [, $result, $cart, $methodId] = $this->quotedFlat();

        $this->assertTrue(app(QuoteHandleStore::class)->verify($result->methods[0]->handle, (string) $cart->id(), $methodId, 500, 'EUR', $result->pricingHash));
    }

    public function test_verify_refuses_an_expired_handle(): void
    {
        [, $result, $cart, $methodId] = $this->quotedFlat();
        $store = app(QuoteHandleStore::class);

        Carbon::setTestNow(Carbon::now()->addSeconds(QuoteCachePolicy::HANDLE_TTL - 1));
        $this->assertTrue($store->verify($result->methods[0]->handle, (string) $cart->id(), $methodId, 500, 'EUR', $result->pricingHash), 'still valid just before the lifetime ends');

        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $this->assertFalse($store->verify($result->methods[0]->handle, (string) $cart->id(), $methodId, 500, 'EUR', $result->pricingHash));
        Carbon::setTestNow();
    }

    public function test_verify_refuses_another_cart_another_method_a_changed_hash_a_changed_amount_a_changed_currency_and_an_unknown_handle(): void
    {
        [, $result, $cart, $methodId] = $this->quotedFlat();
        $store = app(QuoteHandleStore::class);
        $handle = $result->methods[0]->handle;
        $cartId = (string) $cart->id();
        $hash = $result->pricingHash;

        $this->assertFalse($store->verify($handle, $cartId.'9', $methodId, 500, 'EUR', $hash), 'another cart');
        $this->assertFalse($store->verify($handle, $cartId, $methodId.'9', 500, 'EUR', $hash), 'another method');
        $this->assertFalse($store->verify($handle, $cartId, $methodId, 500, 'EUR', hash('sha256', 'something else')), 'a changed hash');
        $this->assertFalse($store->verify($handle, $cartId, $methodId, 499, 'EUR', $hash), 'a changed amount');
        $this->assertFalse($store->verify($handle, $cartId, $methodId, 500, 'USD', $hash), 'a changed currency');
        $this->assertFalse($store->verify('qh_unknown', $cartId, $methodId, 500, 'EUR', $hash), 'a handle that never existed');
        $this->assertFalse($store->verify('', $cartId, $methodId, 500, 'EUR', $hash));
    }

    public function test_the_pricing_hash_changes_with_anything_that_priced_the_quote(): void
    {
        $a = $this->variation('10.00');
        $b = $this->variation('5.00');
        $this->method(ShippingMethodKind::FLAT, 'Flat', 0, 500);
        $this->promotion('HALF', 5000);
        $hash = fn (Cart $cart, QuoteDestination $to) => $this->service()->quote($cart, null, $to)->pricingHash;

        $base = $hash($this->cart([[$a, 2]]), $this->bg());

        $this->assertSame($base, $hash($this->cart([[$a, 2]]), $this->bg()), 'the same pricing, the same hash — even for another cart object');
        $this->assertSame($base, $hash($this->cart([[$a, 2]]), $this->bg(' SOFIA ')), 'a settlement is hashed as normalized');
        $this->assertNotSame($base, $hash($this->cart([[$a, 3]]), $this->bg()), 'a quantity');
        $this->assertNotSame($base, $hash($this->cart([[$a, 2], [$b, 1]]), $this->bg()), 'a line');
        $this->assertNotSame($base, $hash($this->cart([[$a, 2]], null, 'HALF'), $this->bg()), 'the promotion code and so the goods');
        $this->assertNotSame($base, $hash($this->cart([[$a, 2]]), $this->bg('Varna')), 'the destination');
    }
}
