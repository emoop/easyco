<?php

namespace Tests\Feature\Couriers;

use App\Services\ShippingMethodInput;
use App\Services\ShippingMethodWriter;
use App\Settings\Contracts\SiteSettingsRepository;
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
use EasyCo\Shipping\Enums\ShippingDeliveryType;
use EasyCo\Shipping\Enums\ShippingDestinationScope;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingMethod;
use EasyCo\Shipping\ShippingZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EnablesBoxNow;
use Tests\TestCase;

/**
 * COUR-2a: the server's check when an order is placed with a point chosen from a courier's list (courier-integration-design.md §4.5).
 * Every test goes through HTTP like the storefront: a REAL quote, then POST /api/checkout. Our own tables only; no courier is called.
 */
class PickupPointOrderTest extends TestCase
{
    use EnablesBoxNow;
    use RefreshDatabase;

    private static int $counter = 0;

    private ?string $zoneId = null;

    private ?PriceList $priceList = null;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost/');
        $this->enableBoxNow();
        // the field error is the owner's sentence, in the store's language
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
    }

    // --- fixtures -------------------------------------------------------------------------------------------------

    private function cart(): string
    {
        self::$counter++;
        $product = Product::createSimple('Pick product '.self::$counter, 'PK-'.self::$counter, 'pick-product-'.self::$counter);
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        $this->priceList ??= tap(PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0), fn (PriceList $list) => app(PriceListRepository::class)->save($list));
        $list = $this->priceList;
        app(PriceListItemRepository::class)->save(new PriceListItem(null, $list->id(), PriceListItemTargetType::VARIATION, $variationId, Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 0)));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 50));

        return (string) $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 2])->assertStatus(201)->json('cart_id');
    }

    /** A flat method with the courier whose points it offers (null: no list). */
    private function method(string $name, ?string $carrier, ShippingDeliveryType $label = ShippingDeliveryType::LOCKER, ShippingDestinationScope $scope = ShippingDestinationScope::PICKUP): string
    {
        if ($this->zoneId === null) {
            $zone = ShippingZone::create('Zone', 0, ['BG']);
            app(ShippingZoneRepository::class)->save($zone);
            $this->zoneId = (string) $zone->id();
        }

        $method = ShippingMethod::create($this->zoneId, $name, ShippingMethodKind::FLAT, 0, true, 500, [], null, $carrier, false, courier: 'BOX NOW', deliveryType: $label, destinationScope: $scope);
        app(ShippingMethodRepository::class)->save($method);

        return (string) $method->id();
    }

    private function point(string $carrier = 'boxnow', string $kind = 'locker', array $override = []): int
    {
        $this->seq++;

        return (int) DB::table('shipping_pickup_points')->insertGetId($override + [
            'carrier_code' => $carrier, 'external_ref' => 'REF'.$this->seq, 'kind' => $kind, 'name' => 'Locker '.$this->seq, 'settlement_name' => 'Пловдив',
            'postcode' => '4000', 'address_line' => 'ул. Тест '.$this->seq, 'latitude' => 42.1354, 'longitude' => 24.7453, 'is_active' => 1,
        ]);
    }

    /** @return array<string, mixed> the quote's methods by id */
    private function quote(string $kind = 'locker'): array
    {
        return collect($this->postJson('/api/shipping/quote', ['delivery_type' => 'pickup_point', 'country' => 'BG', 'settlement' => 'Пловдив', 'pickup_kind' => $kind])->assertOk()->json('methods'))->keyBy('id')->all();
    }

    /** @return array<string, mixed> */
    private function payload(string $cartId, array $quoted, string $methodId, array $override = [], string $kind = 'locker'): array
    {
        return array_merge([
            'cart_id' => $cartId, 'email' => 'guest@example.com', 'recipient_first_name' => 'Guest', 'recipient_last_name' => 'Buyer', 'phone' => '+359888000000',
            'payment_method' => 'cash_on_delivery', 'delivery_type' => 'pickup_point', 'country' => 'BG', 'settlement' => 'Пловдив', 'pickup_point_kind' => $kind,
            'carrier_code' => 'BOX NOW',
            'shipping_method_id' => $methodId, 'quote_handle' => $quoted[$methodId]['handle'], 'expected_shipping_minor' => $quoted[$methodId]['price']['minor'],
        ], $override);
    }

    /** @return array<string, int> */
    private function rows(): array
    {
        return [
            'orders' => DB::table('orders')->count(), 'payments' => DB::table('payments')->count(), 'snapshots' => DB::table('order_placement_snapshots')->count(),
            'transactions' => DB::table('operational_sales_transactions')->count(), 'points' => DB::table('order_pickup_points')->count(),
        ];
    }

    private function assertRefused(string $cartId, TestResponse $response, int $existing = 0): void
    {
        $response->assertStatus(422)->assertJsonValidationErrors('pickup_point_id');
        $this->assertSame('Избраният офис или автомат вече не е наличен. Моля, изберете друг.', $response->json('errors.pickup_point_id.0'));
        $this->assertSame(['orders' => $existing, 'payments' => $existing, 'snapshots' => $existing, 'transactions' => $existing, 'points' => $existing], $this->rows(), 'no order and no payment step');
        $this->assertNull(DB::table('carts')->where('id', $cartId)->value('order_id'), 'the cart stays, unclaimed');
    }

    // --- the quote says whether there is a list -------------------------------------------------------------------

    public function test_the_quote_names_the_courier_of_a_method_that_has_a_list_and_nothing_else(): void
    {
        $this->cart();
        $withList = $this->method('Locker with list', 'boxnow');
        $without = $this->method('Locker free text', null);
        $address = $this->method('To address', null, ShippingDeliveryType::ADDRESS, ShippingDestinationScope::ADDRESS);
        $this->point();

        $quoted = collect($this->postJson('/api/shipping/quote', ['delivery_type' => 'all', 'country' => 'BG', 'settlement' => 'Пловдив'])->assertOk()->json('methods'))->keyBy('id');

        $this->assertSame(['carrier' => 'boxnow', 'map' => false], $quoted[$withList]['integration']);
        $this->assertNull($quoted[$without]['integration']);
        $this->assertNull($quoted[$address]['integration']);

        $single = $this->quote();
        $this->assertSame(['carrier' => 'boxnow', 'map' => false], $single[$withList]['integration']);
    }

    public function test_a_disabled_courier_or_one_without_points_shows_no_list(): void
    {
        $this->cart();
        $id = $this->method('Locker with list', 'boxnow');

        $this->assertNull($this->quote()[$id]['integration'], 'enabled but no point yet');

        $this->point();
        $this->assertNotNull($this->quote()[$id]['integration']);

        app(SiteSettingsRepository::class)->set('couriers.boxnow.enabled', '0');
        $this->assertNull($this->quote()[$id]['integration'], 'switched off');
    }

    // --- a valid point places the order and is stored as a snapshot ------------------------------------------------

    public function test_a_valid_point_places_the_order_and_its_snapshot_is_taken_from_our_table_not_the_request(): void
    {
        $cartId = $this->cart();
        $id = $this->method('Locker with list', 'boxnow');
        $pointId = $this->point('boxnow', 'locker', ['external_ref' => 'LOCKER-77', 'name' => 'Lidl Пловдив', 'address_line' => 'бул. Христо Ботев 1', 'postcode' => '4002']);
        $quoted = $this->quote();

        $response = $this->postJson('/api/checkout', $this->payload($cartId, $quoted, $id, [
            'pickup_point_id' => (string) $pointId,
            // whatever the browser claims about the point is overwritten by what OUR table says
            'pickup_point_name' => 'FORGED NAME', 'pickup_point_address' => 'FORGED ADDRESS', 'pickup_point_reference' => 'FORGED-REF', 'carrier_code' => 'forged',
        ]))->assertStatus(201);

        $response->assertJsonPath('order.carrier_code', 'boxnow')
            ->assertJsonPath('order.pickup_point_reference', 'LOCKER-77')
            ->assertJsonPath('order.pickup_point_name', 'Lidl Пловдив')
            ->assertJsonPath('order.pickup_point_address', 'бул. Христо Ботев 1')
            ->assertJsonPath('order.pickup_point_kind', 'locker')
            ->assertJsonPath('order.settlement', 'Пловдив');

        $orderId = DB::table('orders')->value('id');
        $snapshot = (array) DB::table('order_pickup_points')->where('order_id', $orderId)->first();

        $this->assertSame([$pointId, 'boxnow', 'LOCKER-77', 'locker', 'Lidl Пловдив', 'бул. Христо Ботев 1', 'Пловдив', '4002'], [
            $snapshot['point_id'], $snapshot['carrier_code'], $snapshot['external_ref'], $snapshot['kind'], $snapshot['name'], $snapshot['address_line'], $snapshot['settlement_name'], $snapshot['postcode'],
        ]);

        // later changes to the point never change the order
        DB::table('shipping_pickup_points')->where('id', $pointId)->update(['name' => 'Renamed', 'address_line' => 'Elsewhere', 'postcode' => '9999', 'is_active' => 0]);
        $this->assertSame('Lidl Пловдив', DB::table('order_pickup_points')->where('order_id', $orderId)->value('name'));
        $this->assertSame('Lidl Пловдив', DB::table('orders')->value('pickup_point_name'));
        $this->assertSame('4002', DB::table('order_pickup_points')->where('order_id', $orderId)->value('postcode'));
    }

    public function test_a_replay_of_the_same_order_does_not_write_a_second_snapshot(): void
    {
        $cartId = $this->cart();
        $id = $this->method('Locker with list', 'boxnow');
        $pointId = $this->point();
        $payload = $this->payload($cartId, $this->quote(), $id, ['pickup_point_id' => (string) $pointId]);

        $this->postJson('/api/checkout', $payload)->assertStatus(201)->assertJsonPath('already_placed', false);
        $this->postJson('/api/checkout', $payload)->assertStatus(201)->assertJsonPath('already_placed', true);

        $this->assertSame(1, DB::table('order_pickup_points')->count());
    }

    // --- refusals: no order, no payment, the cart stays ------------------------------------------------------------

    public function test_a_point_that_cannot_be_chosen_is_refused_with_a_field_error_and_nothing_is_written(): void
    {
        $cartId = $this->cart();
        $id = $this->method('Locker with list', 'boxnow');
        $this->point();
        $quoted = $this->quote();

        $inactive = $this->point('boxnow', 'locker', ['is_active' => 0]);
        $otherCourier = $this->point('econt', 'locker');
        $wrongKind = $this->point('boxnow', 'office');
        $notAllowed = $this->point('boxnow', 'locker', ['features' => json_encode(['pickUpAllowed' => false])]);
        $expired = $this->point('boxnow', 'locker', ['features' => json_encode(['validTo' => '2020-01-01'])]);
        $mobile = $this->point('boxnow', 'locker', ['courier_type' => 'mps']);

        foreach ([$inactive, $otherCourier, $wrongKind, $notAllowed, $expired, $mobile, 999999] as $forged) {
            $this->assertRefused($cartId, $this->postJson('/api/checkout', $this->payload($cartId, $quoted, $id, ['pickup_point_id' => (string) $forged])));
        }

        // not a number at all
        $this->postJson('/api/checkout', $this->payload($cartId, $quoted, $id, ['pickup_point_id' => '1 OR 1=1']))->assertStatus(422)->assertJsonValidationErrors('pickup_point_id');
        $this->assertSame(['orders' => 0, 'payments' => 0, 'snapshots' => 0, 'transactions' => 0, 'points' => 0], $this->rows());
    }

    public function test_a_point_deactivated_after_the_customer_saw_the_list_is_refused_and_another_can_then_be_placed(): void
    {
        $cartId = $this->cart();
        $id = $this->method('Locker with list', 'boxnow');
        $first = $this->point();
        $second = $this->point();
        $quoted = $this->quote();

        DB::table('shipping_pickup_points')->where('id', $first)->update(['is_active' => 0]);

        $this->assertRefused($cartId, $this->postJson('/api/checkout', $this->payload($cartId, $quoted, $id, ['pickup_point_id' => (string) $first])));
        $this->postJson('/api/checkout', $this->payload($cartId, $quoted, $id, ['pickup_point_id' => (string) $second]))->assertStatus(201);
    }

    public function test_the_point_must_belong_to_the_courier_of_the_chosen_method(): void
    {
        $this->enableCourier('econt');
        $cartId = $this->cart();
        $id = $this->method('Locker with list', 'boxnow');
        $this->point();
        $econt = $this->point('econt', 'locker');

        $this->assertRefused($cartId, $this->postJson('/api/checkout', $this->payload($cartId, $this->quote(), $id, ['pickup_point_id' => (string) $econt])));
    }

    public function test_a_point_of_a_kind_the_customer_did_not_ask_for_is_refused(): void
    {
        $cartId = $this->cart();
        $id = $this->method('To office or locker', 'boxnow', ShippingDeliveryType::OFFICE, ShippingDestinationScope::PICKUP);
        $office = $this->point('boxnow', 'office');
        $locker = $this->point('boxnow', 'locker');

        // a method labelled "office" does not serve lockers, and the customer asked for an office
        $this->assertRefused($cartId, $this->postJson('/api/checkout', $this->payload($cartId, $this->quote('office'), $id, ['pickup_point_id' => (string) $locker], 'office')));
        $this->postJson('/api/checkout', $this->payload($cartId, $this->quote('office'), $id, ['pickup_point_id' => (string) $office], 'office'))->assertStatus(201)->assertJsonPath('order.pickup_point_kind', 'office');
    }

    public function test_the_refusal_is_written_in_both_languages(): void
    {
        $cartId = $this->cart();
        $id = $this->method('Locker with list', 'boxnow');
        $this->point();
        app(SiteSettingsRepository::class)->set('site.locale', 'en');

        $this->postJson('/api/checkout', $this->payload($cartId, $this->quote(), $id, ['pickup_point_id' => '999999']))
            ->assertStatus(422)->assertJsonPath('errors.pickup_point_id.0', 'The office or locker you chose is no longer available. Please choose another.');
    }

    public function test_a_method_with_a_list_needs_a_point_unless_the_page_showed_the_free_text_field(): void
    {
        $cartId = $this->cart();
        $id = $this->method('Locker with list', 'boxnow');
        $this->point();
        $quoted = $this->quote();

        $this->assertRefused($cartId, $this->postJson('/api/checkout', $this->payload($cartId, $quoted, $id, ['pickup_point_address' => 'Somewhere I typed'])));

        // the page fell back to the free-text field (a list did not load, or the customer chose it): today's behaviour
        $this->postJson('/api/checkout', $this->payload($cartId, $quoted, $id, ['pickup_point_manual' => true, 'pickup_point_address' => 'Somewhere I typed']))
            ->assertStatus(201)->assertJsonPath('order.pickup_point_address', 'Somewhere I typed')->assertJsonPath('order.pickup_point_reference', null)->assertJsonPath('order.carrier_code', 'BOX NOW');
        $this->assertSame(0, DB::table('order_pickup_points')->count(), 'a described point has no snapshot');
    }

    // --- no list: exactly as today ---------------------------------------------------------------------------------

    public function test_a_link_kept_while_the_courier_is_off_hides_the_picker_and_it_returns_when_the_courier_is_back(): void
    {
        $this->cart();
        $id = $this->method('Locker with list', 'boxnow');
        $this->point();
        $integration = fn () => $this->quote()[$id]['integration'];

        app(SiteSettingsRepository::class)->set('couriers.boxnow.enabled', '0');
        $this->assertNull($integration());

        // an edit that leaves the courier alone keeps the link
        app(ShippingMethodWriter::class)->update($id, new ShippingMethodInput(name: 'Renamed', kind: 'flat', price: Money::fromDecimal('5.00', 'EUR'), carrierCode: 'boxnow', destinationScope: 'pickup', deliveryType: 'locker', courier: 'BOX NOW'));
        $this->assertSame('boxnow', DB::table('shipping_methods')->where('id', $id)->value('carrier_code'));
        $this->assertNull($integration());

        app(SiteSettingsRepository::class)->set('couriers.boxnow.enabled', '1');
        $this->assertSame(['carrier' => 'boxnow', 'map' => false], $integration());
    }

    public function test_one_method_that_serves_every_destination_places_an_office_and_a_locker_and_refuses_another_couriers_point(): void
    {
        $this->enableCourier('speedy');
        $zone = ShippingZone::create('Any zone', 0, ['BG']);
        app(ShippingZoneRepository::class)->save($zone);
        // PER_CLASS, scope ANY, no delivery type: the shop's real setup (one method per courier for address, office and locker)
        $method = ShippingMethod::create((string) $zone->id(), 'Доставка със Спиди', ShippingMethodKind::PER_CLASS, 0, true, 500, [], null, 'speedy', false, courier: 'Спиди', deliveryType: null, destinationScope: ShippingDestinationScope::ANY);
        app(ShippingMethodRepository::class)->save($method);
        $id = (string) $method->id();

        $office = $this->point('speedy', 'office', ['external_ref' => 'SP-O', 'name' => 'Speedy office']);
        $locker = $this->point('speedy', 'locker', ['external_ref' => 'SP-L', 'name' => 'Speedy locker']);
        $boxnow = $this->point('boxnow', 'locker');

        foreach (['office' => $office, 'locker' => $locker] as $kind => $pointId) {
            $cartId = $this->cart();
            $quoted = $this->quote($kind);
            $this->assertSame(['carrier' => 'speedy', 'map' => false], $quoted[$id]['integration']);

            $this->postJson('/api/checkout', $this->payload($cartId, $quoted, $id, ['pickup_point_id' => (string) $pointId], $kind))
                ->assertStatus(201)->assertJsonPath('order.pickup_point_kind', $kind)->assertJsonPath('order.carrier_code', 'speedy');
        }

        $this->assertSame(['SP-O', 'SP-L'], DB::table('order_pickup_points')->orderBy('id')->pluck('external_ref')->all());

        // another courier's point, and an office id when a locker was chosen
        $cartId = $this->cart();
        $this->assertRefused($cartId, $this->postJson('/api/checkout', $this->payload($cartId, $this->quote('locker'), $id, ['pickup_point_id' => (string) $boxnow], 'locker')), 2);
        $this->assertRefused($cartId, $this->postJson('/api/checkout', $this->payload($cartId, $this->quote('locker'), $id, ['pickup_point_id' => (string) $office], 'locker')), 2);
    }

    public function test_an_id_alone_is_refused_when_no_list_applies(): void
    {
        $withoutCourier = $this->method('No courier', null);
        $withCourier = $this->method('With courier', 'boxnow');
        $cartId = $this->cart();
        $pointId = $this->point();

        // the courier was switched off after the quote: the id cannot be judged and nothing was typed
        $quoted = $this->quote();
        app(SiteSettingsRepository::class)->set('couriers.boxnow.enabled', '0');
        $this->assertRefused($cartId, $this->postJson('/api/checkout', $this->payload($cartId, $quoted, $withCourier, ['pickup_point_id' => (string) $pointId])));

        // a method with no courier at all
        $this->assertRefused($cartId, $this->postJson('/api/checkout', $this->payload($cartId, $this->quote(), $withoutCourier, ['pickup_point_id' => (string) $pointId])));

        // courier off, id AND a typed address: placed as today, no snapshot
        $this->postJson('/api/checkout', $this->payload($cartId, $this->quote(), $withCourier, ['pickup_point_id' => (string) $pointId, 'pickup_point_address' => 'Typed instead']))
            ->assertStatus(201)->assertJsonPath('order.pickup_point_address', 'Typed instead')->assertJsonPath('order.pickup_point_reference', null);
        $this->assertSame(0, DB::table('order_pickup_points')->count());
    }

    public function test_a_method_without_a_courier_a_disabled_courier_or_no_points_keeps_the_free_text_field(): void
    {
        $withoutCourier = $this->method('No courier', null);
        $withCourier = $this->method('With courier', 'boxnow');

        // 1) a method with no courier
        $cartId = $this->cart();
        $this->point();
        $this->postJson('/api/checkout', $this->payload($cartId, $this->quote(), $withoutCourier, ['pickup_point_address' => 'Typed 1']))->assertStatus(201)->assertJsonPath('order.pickup_point_address', 'Typed 1');

        // 2) the courier is off: no list, the typed text is accepted, an id is ignored (never stored)
        app(SiteSettingsRepository::class)->set('couriers.boxnow.enabled', '0');
        $cartId = $this->cart();
        $this->postJson('/api/checkout', $this->payload($cartId, $this->quote(), $withCourier, ['pickup_point_address' => 'Typed 2', 'pickup_point_id' => '1']))->assertStatus(201)->assertJsonPath('order.pickup_point_address', 'Typed 2');

        // 3) the courier is on but has no point at all
        $this->enableBoxNow();
        DB::table('shipping_pickup_points')->delete();
        $cartId = $this->cart();
        $this->postJson('/api/checkout', $this->payload($cartId, $this->quote(), $withCourier, ['pickup_point_address' => 'Typed 3']))->assertStatus(201)->assertJsonPath('order.pickup_point_address', 'Typed 3');

        $this->assertSame(0, DB::table('order_pickup_points')->count());
        $this->assertSame(3, DB::table('orders')->count());
    }
}
