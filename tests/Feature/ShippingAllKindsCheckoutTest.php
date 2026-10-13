<?php

namespace Tests\Feature;

use EasyCo\Shipping\Carrier\CarrierCapability;
use EasyCo\Shipping\Carrier\CarrierRegistration;
use EasyCo\Shipping\Carrier\CarrierRegistry;
use EasyCo\Shipping\Enums\ShippingDeliveryType as Type;
use EasyCo\Shipping\Enums\ShippingDestinationScope as Scope;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsAllKindsQuote;
use Tests\Support\Shipping\FakeRateProvider;
use Tests\Support\Shipping\Fakes;
use Tests\TestCase;

require_once __DIR__.'/../Support/Shipping/FakeCarrier.php';

/**
 * Shipping stage 5g-2b (shipping-domain-design.md 9.3.4, 9.3.6): the handles of an `all` quote are the handles checkout verifies.
 * Each kind's handle is issued from THAT kind's own pricing hash, so it is accepted for the kind it was quoted for and for no other
 * (never silently at a cheaper price). A preview issues none.
 *
 * A handle also binds its CART, so "one quote, three orders" is three carts with the same goods, each asked once: the proof that
 * counts is that every order is placed WITHOUT expected_shipping_minor (the O1 tolerance for a lost handle cannot apply), so the
 * only way in is a handle that verifies.
 */
class ShippingAllKindsCheckoutTest extends TestCase
{
    use BuildsAllKindsQuote;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost/');
    }

    /** @return array<string, mixed> */
    private function street(): array
    {
        return ['delivery_type' => 'street_address', 'country' => 'BG', 'city' => 'Sofia', 'postal_code' => '1000', 'address_line_1' => 'Vitosha Blvd 1'];
    }

    /** @return array<string, mixed> */
    private function pickup(string $kind): array
    {
        return [
            'delivery_type' => 'pickup_point', 'country' => 'BG', 'carrier_code' => 'speedy', 'pickup_point_reference' => 'office-1234',
            'pickup_point_name' => 'Speedy Center', 'pickup_point_address' => 'Vitosha Blvd 100', 'settlement' => 'Sofia', 'pickup_point_kind' => $kind,
        ];
    }

    /** @return array<string, mixed> */
    private function body(string $cartId, array $address, array $shipping): array
    {
        return array_merge(['cart_id' => $cartId, 'email' => 'guest@example.com', 'recipient_first_name' => 'Guest', 'recipient_last_name' => 'Buyer', 'phone' => '+359888000000', 'payment_method' => 'cash_on_delivery'], $address, $shipping);
    }

    /** @return array{id: string, handle: string, price: int} the method's quote for one kind, from an `all` quote of the session's cart */
    private function kindOf(string $methodId, string $kind): array
    {
        $method = collect($this->allQuote(['postal_code' => '1000'])->assertOk()->json('methods'))->firstWhere('id', $methodId);
        $d = $method['destinations'][$kind];

        return ['id' => $methodId, 'handle' => $d['handle'], 'price' => $d['price']['minor']];
    }

    /** @return array<string, mixed> */
    private function choose(array $kind, bool $withExpected = false): array
    {
        return array_filter(['shipping_method_id' => $kind['id'], 'quote_handle' => $kind['handle'], 'expected_shipping_minor' => $withExpected ? $kind['price'] : null], fn ($v) => $v !== null);
    }

    // ---- the key test ------------------------------------------------------------------------------------------------------

    public function test_each_kinds_handle_from_an_all_quote_is_accepted_at_the_quoted_price_for_that_kind(): void
    {
        $speedy = $this->akMethod('Speedy any', Scope::ANY, null, 600, 6000, [Type::OFFICE, Type::LOCKER], 'Speedy');

        // (a) the address kind, as a street address
        $cart = $this->akCart('30.00', 2);
        $address = $this->kindOf($speedy, 'address');
        $this->assertSame(600, $address['price']);
        $this->postJson('/api/checkout', $this->body($cart, $this->street(), $this->choose($address)))
            ->assertStatus(201)->assertJsonPath('shipping.amount_minor', 600)->assertJsonPath('order.total.minor', 6600)->assertJsonPath('order.pickup_point_kind', null);

        // (b) the office kind, as an office
        $cart = $this->akCart('30.00', 2);
        $office = $this->kindOf($speedy, 'office');
        $this->assertSame(0, $office['price']);
        $this->postJson('/api/checkout', $this->body($cart, $this->pickup('office'), $this->choose($office)))
            ->assertStatus(201)->assertJsonPath('shipping.amount_minor', 0)->assertJsonPath('order.total.minor', 6000)->assertJsonPath('order.pickup_point_kind', 'office');

        // (c) the locker kind, as a locker
        $cart = $this->akCart('30.00', 2);
        $locker = $this->kindOf($speedy, 'locker');
        $this->assertSame(0, $locker['price']);
        $this->postJson('/api/checkout', $this->body($cart, $this->pickup('locker'), $this->choose($locker)))
            ->assertStatus(201)->assertJsonPath('shipping.amount_minor', 0)->assertJsonPath('order.pickup_point_kind', 'locker');

        $this->assertSame(['locker', 'office', null], DB::table('orders')->orderByDesc('id')->pluck('pickup_point_kind')->all(), 'newest first');
        $this->assertSame(3, DB::table('orders')->count());
    }

    public function test_an_address_handle_does_not_verify_for_a_pickup_order_and_the_reverse(): void
    {
        $speedy = $this->akMethod('Speedy any', Scope::ANY, null, 600, 6000, [Type::OFFICE, Type::LOCKER], 'Speedy');
        $cart = $this->akCart('30.00', 2);
        $address = $this->kindOf($speedy, 'address');
        $office = $this->kindOf($speedy, 'office');

        // the dearer (address) handle on a free office: the price differs, so it is the usual price_changed, not a silent discount
        $this->postJson('/api/checkout', $this->body($cart, $this->pickup('office'), $this->choose($address, withExpected: true)))->assertStatus(409)->assertJsonPath('reason', 'shipping_price_changed')->assertJsonPath('price.minor', 0);
        // the free (office) handle on a street address: the price differs again
        $this->postJson('/api/checkout', $this->body($cart, $this->street(), $this->choose($office, withExpected: true)))->assertStatus(409)->assertJsonPath('reason', 'shipping_price_changed')->assertJsonPath('price.minor', 600);
        // without the expected amount neither verifies
        $this->postJson('/api/checkout', $this->body($cart, $this->street(), $this->choose($office)))->assertStatus(409)->assertJsonPath('reason', 'shipping_quote_expired');
        $this->postJson('/api/checkout', $this->body($cart, $this->pickup('office'), $this->choose($address)))->assertStatus(409)->assertJsonPath('reason', 'shipping_quote_expired');

        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_the_office_handle_presented_with_the_locker_is_refused_never_at_the_cheaper_price(): void
    {
        // free to the locker only: the office pays, so presenting the office handle for a locker order would be a cheaper price
        $deal = $this->akMethod('Locker deal', Scope::ANY, null, 600, 6000, [Type::LOCKER], 'Speedy');
        $cart = $this->akCart('30.00', 2);
        $office = $this->kindOf($deal, 'office');
        $locker = $this->kindOf($deal, 'locker');
        $this->assertSame([600, 0], [$office['price'], $locker['price']]);

        $this->postJson('/api/checkout', $this->body($cart, $this->pickup('locker'), $this->choose($office, withExpected: true)))
            ->assertStatus(409)->assertJsonPath('reason', 'shipping_price_changed')->assertJsonPath('price.minor', 0);
        $this->postJson('/api/checkout', $this->body($cart, $this->pickup('locker'), $this->choose($office)))
            ->assertStatus(409)->assertJsonPath('reason', 'shipping_quote_expired');
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertNull(DB::table('carts')->where('id', $cart)->value('order_id'));

        // and the locker's own handle is the one that places it, free
        $this->postJson('/api/checkout', $this->body($cart, $this->pickup('locker'), $this->choose($locker)))->assertStatus(201)->assertJsonPath('shipping.amount_minor', 0);
    }

    // ---- the preview -------------------------------------------------------------------------------------------------------------

    public function test_a_preview_has_a_carrier_method_unavailable_for_want_of_a_settlement_and_no_handle_that_checkout_would_accept(): void
    {
        $calls = 0;
        $this->app->instance(CarrierCapability::RATE->containerKey('acme'), new FakeRateProvider(function () use (&$calls) {
            $calls++;

            return [Fakes::quote(450)];
        }));
        $this->app->make(CarrierRegistry::class)->register(new CarrierRegistration('acme', 'Acme', [CarrierCapability::RATE]));
        $local = $this->akMethod('Local', Scope::ANY, null, 600, null, null, null, 0);
        $carrier = $this->akMethod('Acme', Scope::ANY, null, 0, null, null, null, 1, null, ShippingMethodKind::CARRIER, 'acme');
        $cart = $this->akCart('30.00', 2);

        $json = $this->allQuote([], withSettlement: false)->assertOk()->json();

        $this->assertTrue($json['preview']);
        $acme = collect($json['methods'])->firstWhere('id', $carrier);
        $this->assertFalse($acme['available']);
        $this->assertSame('no_settlement', $acme['unavailable_reason']);

        foreach ($acme['destinations'] as $d) {
            $this->assertSame([false, null, null, 'no_settlement'], [$d['available'], $d['price'], $d['handle'], $d['unavailable_reason']]);
        }

        $this->assertSame(0, $calls, 'a carrier is not asked without a settlement');

        // a fabricated handle: refused as ever (no expected amount: expired; a wrong one: price changed)
        $fabricated = ['shipping_method_id' => $local, 'quote_handle' => 'qh_'.str_repeat('a', 40)];
        $this->postJson('/api/checkout', $this->body($cart, $this->street(), $fabricated))->assertStatus(409)->assertJsonPath('reason', 'shipping_quote_expired');
        $this->postJson('/api/checkout', $this->body($cart, $this->street(), $fabricated + ['expected_shipping_minor' => 1]))->assertStatus(409)->assertJsonPath('reason', 'shipping_price_changed');
    }

    public function test_a_final_all_quote_asks_a_carrier_once_per_bucket_not_once_per_kind(): void
    {
        $calls = 0;
        $this->app->instance(CarrierCapability::RATE->containerKey('acme'), new FakeRateProvider(function () use (&$calls) {
            $calls++;

            return [Fakes::quote(450)];
        }));
        $this->app->make(CarrierRegistry::class)->register(new CarrierRegistration('acme', 'Acme', [CarrierCapability::RATE]));
        $carrier = $this->akMethod('Acme', Scope::ANY, null, 0, null, null, null, 0, null, ShippingMethodKind::CARRIER, 'acme');
        $this->akCart('30.00', 2);

        $acme = collect($this->allQuote()->assertOk()->json('methods'))->firstWhere('id', $carrier);

        $this->assertSame(['address', 'office', 'locker'], array_keys($acme['destinations']));
        $this->assertSame([450, 450, 450], array_column(array_column($acme['destinations'], 'price'), 'minor'));
        $this->assertSame(2, $calls, 'the address bucket once, the pickup bucket once: the office and the locker share its answer');
    }
}
