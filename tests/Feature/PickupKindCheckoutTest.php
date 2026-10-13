<?php

namespace Tests\Feature;

use App\Services\QuoteDestination;
use App\Services\ShippingQuoteService;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
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
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingDeliveryType as Type;
use EasyCo\Shipping\Enums\ShippingDestinationScope as Scope;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingMethod;
use EasyCo\Shipping\ShippingZone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Shipping stage 5g-2a (shipping-domain-design.md 9.3.6, 9.3.9): the KIND of a pickup point — office or locker — through the
 * quote, the handle, the checkout and the order. A method that is free above a threshold only for some kinds is priced for the
 * kind that is stated, never cheaper; the handle binds the kind; the order, its snapshot and the saved address record it.
 *
 * Every test goes through HTTP like the storefront does: a REAL quote first, then POST /api/checkout. The standard cart is
 * 2 x 10.00 = 20.00; the method costs 7.00 and is free from 15.00, so the threshold is always reached.
 */
class PickupKindCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const GOODS = 2000;

    private const PRICE = 700;

    private static int $counter = 0;

    private ?PriceList $priceList = null;

    private ?string $zoneId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost/');
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    private function cart(int $quantity = 2): string
    {
        self::$counter++;
        $product = Product::createSimple('Kind product '.self::$counter, 'KP-'.self::$counter, 'kind-product-'.self::$counter);
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        $this->priceList ??= tap(PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0), fn (PriceList $list) => app(PriceListRepository::class)->save($list));
        app(PriceListItemRepository::class)->save(new PriceListItem(null, $this->priceList->id(), PriceListItemTargetType::VARIATION, $variationId, Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 0)));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 50));

        return (string) $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => $quantity])->assertStatus(201)->json('cart_id');
    }

    /** @param list<Type>|null $free */
    private function method(string $name, Scope $scope, ?array $free, ?Type $label = null, int $sort = 0): string
    {
        if ($this->zoneId === null) {
            $zone = ShippingZone::create('Zone', 0, ['BG']);
            app(ShippingZoneRepository::class)->save($zone);
            $this->zoneId = (string) $zone->id();
        }

        $method = ShippingMethod::create($this->zoneId, $name, ShippingMethodKind::FLAT, $sort, true, self::PRICE, [], 1500, null, false, ShippingClassMode::REPLACE, null, $label, $scope, $free);
        app(ShippingMethodRepository::class)->save($method);

        return (string) $method->id();
    }

    /** @return array<string, mixed> */
    private function street(): array
    {
        return ['delivery_type' => 'street_address', 'country' => 'BG', 'city' => 'Sofia', 'postal_code' => '1000', 'address_line_1' => 'Vitosha Blvd 1'];
    }

    /** @return array<string, mixed> */
    private function pickup(?string $kind = null): array
    {
        return array_filter([
            'delivery_type' => 'pickup_point', 'country' => 'BG', 'carrier_code' => 'econt', 'pickup_point_reference' => 'office-1234',
            'pickup_point_name' => 'Econt Center', 'pickup_point_address' => 'Vitosha Blvd 100', 'settlement' => 'Sofia',
            'pickup_point_kind' => $kind,
        ], fn ($v) => $v !== null);
    }

    /** The quote body for the same destination (the quote names the place `settlement` and the kind `pickup_kind`). */
    private function quoteBody(array $address): array
    {
        $pickup = $address['delivery_type'] === 'pickup_point';

        return array_filter([
            'delivery_type' => $address['delivery_type'],
            'country' => 'BG',
            'settlement' => $pickup ? $address['settlement'] : $address['city'],
            'postal_code' => $address['postal_code'] ?? null,
            'pickup_kind' => $address['pickup_point_kind'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, array<string, mixed>> the quote's methods by id */
    private function quote(array $address): array
    {
        return collect($this->postJson('/api/shipping/quote', $this->quoteBody($address))->assertOk()->json('methods'))->keyBy('id')->all();
    }

    /** @return array<string, mixed> */
    private function payload(string $cartId, array $address, array $shipping, array $override = []): array
    {
        return array_merge([
            'cart_id' => $cartId, 'email' => 'guest@example.com', 'recipient_name' => 'Guest Buyer', 'phone' => '+359888000000', 'payment_method' => 'cash_on_delivery',
        ], $address, $shipping, $override);
    }

    /** @return array<string, mixed> */
    private function choose(array $quoted, string $id, ?int $expected = null): array
    {
        return array_filter([
            'shipping_method_id' => $id, 'quote_handle' => $quoted[$id]['handle'], 'expected_shipping_minor' => $expected ?? $quoted[$id]['price']['minor'],
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, int> */
    private function rows(): array
    {
        return [
            'orders' => DB::table('orders')->count(), 'payments' => DB::table('payments')->count(), 'snapshots' => DB::table('order_placement_snapshots')->count(),
            'transactions' => DB::table('operational_sales_transactions')->count(), 'sale_lines' => DB::table('operational_sales_sale_lines')->count(),
        ];
    }

    private function loggedInAccount(): AccountModel
    {
        $account = Account::register('kind@example.com', 'hashed-password');
        app(AccountRepository::class)->save($account);
        $model = AccountModel::findOrFail($account->id());
        $this->actingAs($model, 'customer');

        return $model;
    }

    // ---- the security test: a handle never buys a cheaper kind --------------------------------------------------------

    public function test_a_locker_handle_cannot_buy_an_office_order(): void
    {
        $id = $this->method('Speedy', Scope::ANY, [Type::LOCKER]);
        $cartId = $this->cart();
        $quoted = $this->quote($this->pickup('locker'));

        $this->assertSame(0, $quoted[$id]['price']['minor'], 'the locker is free over the threshold');
        $this->assertStringStartsWith('qh_', $quoted[$id]['handle']);
        $this->assertSame(self::PRICE, $this->quote($this->pickup('office'))[$id]['price']['minor'], 'the office pays');

        // the locker's handle and its free figure, presented for an OFFICE order
        $response = $this->postJson('/api/checkout', $this->payload($cartId, $this->pickup('office'), $this->choose($quoted, $id)));

        $response->assertStatus(409)->assertJsonPath('reason', 'shipping_price_changed')->assertJsonPath('price.minor', self::PRICE);
        $this->assertSame(['orders' => 0, 'payments' => 0, 'snapshots' => 0, 'transactions' => 0, 'sale_lines' => 0], $this->rows());
        $this->assertNull(DB::table('carts')->where('id', $cartId)->value('order_id'), 'the cart is not claimed');
        $this->assertSame(1, DB::table('cart_lines')->where('cart_id', $cartId)->count(), 'the cart is unchanged');

        // the same handle with no expected amount: it does not verify for the office
        $without = $this->choose($quoted, $id);
        unset($without['expected_shipping_minor']);
        $this->postJson('/api/checkout', $this->payload($cartId, $this->pickup('office'), $without))->assertStatus(409)->assertJsonPath('reason', 'shipping_quote_expired');
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_the_same_flow_with_the_locker_places_the_order_free_and_records_the_kind_everywhere_including_a_replay(): void
    {
        $id = $this->method('Speedy', Scope::ANY, [Type::LOCKER]);
        $cartId = $this->cart();
        $quoted = $this->quote($this->pickup('locker'));
        $body = $this->payload($cartId, $this->pickup('locker'), $this->choose($quoted, $id));

        $response = $this->postJson('/api/checkout', $body)->assertStatus(201);

        $response->assertJsonPath('order.pickup_point_kind', 'locker')->assertJsonPath('shipping.amount_minor', 0)->assertJsonPath('order.total.minor', self::GOODS);
        $this->assertSame('locker', DB::table('orders')->sole()->pickup_point_kind);
        $this->assertSame('locker', DB::table('order_placement_snapshots')->sole()->pickup_point_kind);

        $this->postJson('/api/checkout', $body)->assertStatus(201)->assertJsonPath('already_placed', true)->assertJsonPath('order.pickup_point_kind', 'locker');
    }

    public function test_an_account_checkout_records_the_kind_on_the_saved_address_too(): void
    {
        $this->loggedInAccount();
        $id = $this->method('Speedy', Scope::ANY, [Type::LOCKER]);
        $cartId = $this->cart();
        $quoted = $this->quote($this->pickup('locker'));

        $this->postJson('/api/checkout', $this->payload($cartId, $this->pickup('locker'), $this->choose($quoted, $id)))->assertStatus(201);

        $this->assertSame('locker', DB::table('addresses')->sole()->pickup_point_kind);
        $this->assertSame('locker', DB::table('orders')->sole()->pickup_point_kind);
    }

    // ---- the hash binds the kind --------------------------------------------------------------------------------------

    public function test_the_pricing_hash_differs_by_kind_is_equal_for_the_same_kind_and_is_version_2(): void
    {
        $this->method('Speedy', Scope::ANY, [Type::LOCKER]);
        $cartId = $this->cart();
        $cart = app(CartRepository::class)->findById($cartId);
        $hash = fn (QuoteDestination $d): string => ShippingQuoteService::pricingHashFor($d, 'sofia', '1', self::GOODS, 'EUR', $cart);
        $pickup = fn (?Type $kind): QuoteDestination => QuoteDestination::forAddress(AddressDeliveryType::PICKUP_POINT, 'BG', 'Sofia', null, $kind);
        $address = QuoteDestination::forAddress(AddressDeliveryType::STREET_ADDRESS, 'BG', 'Sofia');

        $hashes = [$hash($address), $hash($pickup(Type::OFFICE)), $hash($pickup(Type::LOCKER)), $hash($pickup(null))];

        $this->assertCount(4, array_unique($hashes), 'address, office, locker and unspecified pickup hash differently');
        $this->assertSame($hash($pickup(Type::LOCKER)), $hash($pickup(Type::LOCKER)));
        $this->assertSame(['address', 'office', 'locker', 'pickup'], [$address->hashKind(), $pickup(Type::OFFICE)->hashKind(), $pickup(Type::LOCKER)->hashKind(), $pickup(null)->hashKind()]);

        // version 2, and the other keys as before: the hash of an unspecified pickup, recomputed by hand
        $lines = [];
        foreach ($cart->lines() as $line) {
            $lines[] = [$line->variationId(), $line->quantity()];
        }
        $expected = hash('sha256', json_encode([
            'v' => 2, 'country' => 'BG', 'settlement' => 'sofia', 'postcode' => '', 'pickup' => true, 'kind' => 'pickup', 'zone' => '1',
            'goods' => self::GOODS, 'currency' => 'EUR', 'lines' => $lines, 'promotion' => $cart->appliedPromotionCode(),
        ], JSON_THROW_ON_ERROR));
        $this->assertSame($expected, $hash($pickup(null)));
    }

    public function test_a_destination_refuses_a_kind_it_cannot_have(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new QuoteDestination(AddressDeliveryType::STREET_ADDRESS, 'BG', 'Sofia', null, Type::OFFICE);
    }

    public function test_a_destination_refuses_a_non_pickup_kind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new QuoteDestination(AddressDeliveryType::PICKUP_POINT, 'BG', 'Sofia', null, Type::ADDRESS);
    }

    // ---- never cheaper than stated ---------------------------------------------------------------------------------------

    public function test_an_unspecified_pickup_kind_is_accepted_stored_null_and_priced_conservatively(): void
    {
        $lockerOnly = $this->method('Locker free', Scope::ANY, [Type::LOCKER], sort: 0);
        $both = $this->method('Both free', Scope::ANY, [Type::OFFICE, Type::LOCKER], sort: 1);
        $cartId = $this->cart();
        $quoted = $this->quote($this->pickup());

        $this->assertSame(self::PRICE, $quoted[$lockerOnly]['price']['minor'], '{locker}: an unstated pickup could be an office, so it pays');
        $this->assertSame(0, $quoted[$both]['price']['minor'], '{office, locker}: free either way');

        $this->postJson('/api/checkout', $this->payload($cartId, $this->pickup(), $this->choose($quoted, $lockerOnly)))
            ->assertStatus(201)->assertJsonPath('shipping.amount_minor', self::PRICE)->assertJsonPath('order.pickup_point_kind', null);
        $this->assertNull(DB::table('orders')->sole()->pickup_point_kind);
        $this->assertNull(DB::table('order_placement_snapshots')->sole()->pickup_point_kind);
    }

    public function test_a_street_address_over_the_threshold_pays_unless_the_free_set_has_address(): void
    {
        $officeLocker = $this->method('Office and locker', Scope::ANY, [Type::OFFICE, Type::LOCKER], sort: 0);
        $address = $this->method('Address free', Scope::ANY, [Type::ADDRESS], sort: 1);
        $cartId = $this->cart();
        $quoted = $this->quote($this->street());

        $this->assertSame(self::PRICE, $quoted[$officeLocker]['price']['minor'], 'the interim bug of 9.3.11 is gone: an address does not get free delivery meant for offices');
        $this->assertSame(0, $quoted[$address]['price']['minor']);
        $this->assertNull($quoted[$officeLocker]['free_above_minor'], 'no threshold facts for a kind that is never free');

        $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), $this->choose($quoted, $officeLocker)))
            ->assertStatus(201)->assertJsonPath('shipping.amount_minor', self::PRICE)->assertJsonPath('order.total.minor', self::GOODS + self::PRICE)->assertJsonPath('order.pickup_point_kind', null);
    }

    public function test_a_street_address_with_address_in_the_set_is_free_at_checkout(): void
    {
        $address = $this->method('Address free', Scope::ANY, [Type::ADDRESS]);
        $cartId = $this->cart();
        $quoted = $this->quote($this->street());

        $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), $this->choose($quoted, $address)))
            ->assertStatus(201)->assertJsonPath('shipping.amount_minor', 0)->assertJsonPath('order.total.minor', self::GOODS);
    }

    // ---- Q4: the label decides which pickup kind a method serves -------------------------------------------------------------

    public function test_an_office_labelled_method_does_not_serve_a_locker(): void
    {
        $office = $this->method('To office', Scope::PICKUP, null, Type::OFFICE);
        $cartId = $this->cart();

        $locker = $this->quote($this->pickup('locker'));
        $this->assertFalse($locker[$office]['serves_destination']);
        $this->assertTrue($this->quote($this->pickup('office'))[$office]['serves_destination']);
        $this->assertTrue($this->quote($this->pickup())[$office]['serves_destination'], 'an unstated pickup kind is served by a method that serves any pickup kind');

        $this->postJson('/api/checkout', $this->payload($cartId, $this->pickup('locker'), $this->choose($locker, $office)))
            ->assertStatus(422)->assertJsonPath('reason', 'shipping_pickup_mismatch');
        $this->assertSame(0, DB::table('orders')->count());
    }

    // ---- validation -------------------------------------------------------------------------------------------------------

    /** @return array<string, array{mixed}> */
    public static function badKinds(): array
    {
        return ['other' => ['other'], 'address' => ['address'], 'an array' => [['office']], 'an unknown word' => ['nowhere']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badKinds')]
    public function test_the_quote_refuses_a_bad_pickup_kind(mixed $kind): void
    {
        $this->method('Speedy', Scope::ANY, null);
        $this->cart();

        $this->postJson('/api/shipping/quote', ['delivery_type' => 'pickup_point', 'country' => 'BG', 'settlement' => 'Sofia', 'pickup_kind' => $kind])->assertStatus(422)->assertJsonValidationErrors('pickup_kind');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badKinds')]
    public function test_checkout_refuses_a_bad_pickup_point_kind(mixed $kind): void
    {
        $id = $this->method('Speedy', Scope::ANY, null);
        $cartId = $this->cart();
        $quoted = $this->quote($this->pickup());

        $this->postJson('/api/checkout', $this->payload($cartId, $this->pickup(), $this->choose($quoted, $id), ['pickup_point_kind' => $kind]))->assertStatus(422)->assertJsonValidationErrors('pickup_point_kind');
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_a_kind_is_refused_for_a_street_address_and_with_a_saved_address_and_accepted_when_absent(): void
    {
        $id = $this->method('Speedy', Scope::ANY, null);
        $cartId = $this->cart();
        $quoted = $this->quote($this->street());

        $this->postJson('/api/shipping/quote', ['delivery_type' => 'street_address', 'country' => 'BG', 'settlement' => 'Sofia', 'pickup_kind' => 'office'])->assertStatus(422)->assertJsonValidationErrors('pickup_kind');
        $this->postJson('/api/shipping/quote', ['address_id' => '1', 'pickup_kind' => 'office'])->assertStatus(422)->assertJsonValidationErrors('address_id');
        $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), $this->choose($quoted, $id), ['pickup_point_kind' => 'office']))->assertStatus(422)->assertJsonValidationErrors('pickup_point_kind');
        $this->postJson('/api/checkout', $this->payload($cartId, [], $this->choose($quoted, $id), ['address_id' => '1', 'pickup_point_kind' => 'office']))->assertStatus(422)->assertJsonValidationErrors('address_id');

        $this->postJson('/api/shipping/quote', $this->quoteBody($this->pickup()))->assertOk();
        $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), $this->choose($quoted, $id)))->assertStatus(201);
    }

    // ---- the database is the net (rule 2) ------------------------------------------------------------------------------------

    public function test_the_database_refuses_a_kind_on_a_street_row_and_a_bad_value_on_all_three_tables(): void
    {
        $id = $this->method('Speedy', Scope::ANY, null);
        $cartId = $this->cart();
        $quoted = $this->quote($this->street());
        $this->postJson('/api/checkout', $this->payload($cartId, $this->street(), $this->choose($quoted, $id)))->assertStatus(201);

        foreach ([['orders', 'ord_pickup_kind_check'], ['order_placement_snapshots', 'ops_pickup_kind_check']] as [$table, $check]) {
            foreach (['office', 'locker', 'nowhere', 'address'] as $kind) {
                try {
                    DB::table($table)->update(['pickup_point_kind' => $kind]);
                    $this->fail("{$table} accepted the kind {$kind} on a street row");
                } catch (QueryException $e) {
                    $this->assertSame(3819, $e->errorInfo[1]);
                    $this->assertStringContainsString($check, $e->getMessage());
                }
            }
        }

        $address = Address::create(AddressDeliveryType::STREET_ADDRESS, 'Ana', '+359888111222', null, 'BG', 'Sofia', null, 'V 1');
        app(AddressRepository::class)->save($address);

        foreach (['office', 'nowhere'] as $kind) {
            try {
                DB::table('addresses')->where('id', $address->id())->update(['pickup_point_kind' => $kind]);
                $this->fail("addresses accepted the kind {$kind} on a street row");
            } catch (QueryException $e) {
                $this->assertStringContainsString('addr_pickup_kind_check', $e->getMessage());
            }
        }

        // and a pickup row accepts exactly office and locker
        $pickup = Address::create(AddressDeliveryType::PICKUP_POINT, 'Ana', '+359888111222', null, 'BG', null, null, null, null, 'econt', 'o-1', 'Sofia', 'N', 'A', 'office');
        app(AddressRepository::class)->save($pickup);
        DB::table('addresses')->where('id', $pickup->id())->update(['pickup_point_kind' => 'locker']);
        $this->assertSame('locker', DB::table('addresses')->where('id', $pickup->id())->value('pickup_point_kind'));
        $this->expectException(QueryException::class);
        DB::table('addresses')->where('id', $pickup->id())->update(['pickup_point_kind' => 'other']);
    }

    // ---- a historical pickup address ------------------------------------------------------------------------------------------

    public function test_a_historical_pickup_address_without_a_kind_still_checks_out_priced_as_unspecified(): void
    {
        $account = $this->loggedInAccount();
        $address = Address::create(AddressDeliveryType::PICKUP_POINT, 'Ana Historic', '+359888111222', (string) $account->id, 'BG', null, null, null, null, 'econt', 'office-1', 'Sofia');
        app(AddressRepository::class)->save($address);
        $this->assertNull(DB::table('addresses')->where('id', $address->id())->value('pickup_point_kind'));

        $id = $this->method('Speedy', Scope::ANY, [Type::LOCKER]);
        $cartId = $this->cart();
        $quoted = collect($this->postJson('/api/shipping/quote', ['address_id' => (string) $address->id()])->assertOk()->json('methods'))->keyBy('id')->all();

        $this->assertSame(self::PRICE, $quoted[$id]['price']['minor'], 'a saved address with no kind is an unstated pickup: {locker} pays');

        $this->postJson('/api/checkout', ['cart_id' => $cartId, 'email' => 'kind@example.com', 'recipient_name' => 'Ana', 'phone' => '+359888111222', 'payment_method' => 'cash_on_delivery', 'address_id' => (string) $address->id()] + $this->choose($quoted, $id))
            ->assertStatus(201)->assertJsonPath('shipping.amount_minor', self::PRICE)->assertJsonPath('order.pickup_point_kind', null);
    }

    public function test_a_saved_address_uses_its_own_stored_kind(): void
    {
        $account = $this->loggedInAccount();
        $address = Address::create(AddressDeliveryType::PICKUP_POINT, 'Ana Locker', '+359888111222', (string) $account->id, 'BG', null, null, null, null, 'econt', 'locker-1', 'Sofia', 'Locker 1', 'Mall', 'locker');
        app(AddressRepository::class)->save($address);

        $id = $this->method('Speedy', Scope::ANY, [Type::LOCKER]);
        $cartId = $this->cart();
        $quoted = collect($this->postJson('/api/shipping/quote', ['address_id' => (string) $address->id()])->assertOk()->json('methods'))->keyBy('id')->all();

        $this->assertSame(0, $quoted[$id]['price']['minor'], 'the saved address is a locker: free');

        $this->postJson('/api/checkout', ['cart_id' => $cartId, 'email' => 'kind@example.com', 'recipient_name' => 'Ana', 'phone' => '+359888111222', 'payment_method' => 'cash_on_delivery', 'address_id' => (string) $address->id()] + $this->choose($quoted, $id))
            ->assertStatus(201)->assertJsonPath('order.pickup_point_kind', 'locker');
        $this->assertSame('locker', DB::table('order_placement_snapshots')->sole()->pickup_point_kind);
    }
}
