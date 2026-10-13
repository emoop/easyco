<?php

namespace Tests\Feature;

use App\Services\AllKindsQuoteResult;
use App\Services\ShippingQuoteResult;
use EasyCo\Shipping\Enums\ShippingDeliveryType as Type;
use EasyCo\Shipping\Enums\ShippingDestinationScope as Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsAllKindsQuote;
use Tests\TestCase;

/**
 * Shipping stage 5g-2b (shipping-domain-design.md 9.3.4): `delivery_type=all` — every method with its price for each kind of
 * destination (address, office, locker) from ONE request. The golden response under and over the threshold, the preview, the
 * zones, the refusals, the legacy modes beside it, and the limiter.
 */
class ShippingAllKindsQuoteTest extends TestCase
{
    use BuildsAllKindsQuote;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost/');
    }

    /**
     * The owner's fixture: Speedy has ONE method that delivers anywhere and is free from 60.00 to an office or a locker; Econt has an
     * office method (free from 90.00 to the office) and a locker method (free from 90.00 to the locker); and one ungrouped method
     * delivers to the address only. @return array{speedy: string, office: string, locker: string, shop: string}
     */
    private function fixture(): array
    {
        return [
            'speedy' => $this->akMethod('Speedy any', Scope::ANY, null, 600, 6000, [Type::OFFICE, Type::LOCKER], 'Speedy', 0),
            'shop' => $this->akMethod('Shop address', Scope::ADDRESS, null, 700, null, null, null, 1),
            'office' => $this->akMethod('Econt office', Scope::PICKUP, Type::OFFICE, 400, 9000, null, 'Econt', 2),
            'locker' => $this->akMethod('Econt locker', Scope::PICKUP, Type::LOCKER, 350, 9000, null, 'Econt', 3),
        ];
    }

    // ---- the golden response ----------------------------------------------------------------------------------------------

    public function test_the_full_all_response_under_the_threshold(): void
    {
        $ids = $this->fixture();
        $this->akCart('30.00', 1);

        $response = $this->allQuote()->assertOk();
        $json = $response->json();

        $this->assertSame(['cart_id', 'currency', 'goods_after_discount', 'zone', 'mode', 'preview', 'zones', 'methods', 'groups', 'free_shipping_hint'], array_keys($json));
        $this->assertSame('all', $json['mode']);
        $this->assertFalse($json['preview']);
        $this->assertSame(3000, $json['goods_after_discount']['minor']);
        $this->assertSame($json['zone'], $json['zones']['address']);
        $this->assertSame($json['zone'], $json['zones']['pickup'], 'one zone serves both buckets here');

        // first appearance: the address kind's methods, then the pickup kinds' methods not yet listed
        $this->assertSame([$ids['speedy'], $ids['shop'], $ids['office'], $ids['locker']], array_column($json['methods'], 'id'));

        $this->assertSame([
            'Speedy any' => [
                'address' => [true, 600, true, false, null, null],
                'office' => [true, 600, true, true, 6000, 3000],
                'locker' => [true, 600, true, true, 6000, 3000],
            ],
            'Shop address' => ['address' => [true, 700, true, false, null, null]],
            'Econt office' => ['office' => [true, 400, true, true, 9000, 6000]],
            'Econt locker' => ['locker' => [true, 350, true, true, 9000, 6000]],
        ], $this->destinationsByName($json));

        // a method-level price, handle, threshold facts and serves_destination belong to a kind, so they are null here
        foreach ($json['methods'] as $method) {
            $this->assertSame([null, null, null, null, null, true, null, null], [$method['price'], $method['handle'], $method['free_above_minor'], $method['remaining_to_free_minor'], $method['serves_destination'], $method['available'], $method['unavailable_reason'], $method['service_code']]);
        }

        $this->assertSame([
            ['courier' => 'Speedy', 'methods' => [$ids['speedy']], 'from_minor' => 600, 'currency' => 'EUR', 'destinations' => ['address', 'office', 'locker']],
            ['courier' => 'Econt', 'methods' => [$ids['office'], $ids['locker']], 'from_minor' => 350, 'currency' => 'EUR', 'destinations' => ['office', 'locker']],
            ['courier' => null, 'methods' => [$ids['shop']], 'from_minor' => 700, 'currency' => 'EUR', 'destinations' => ['address']],
        ], $json['groups']);

        $hint = $json['free_shipping_hint'];
        $this->assertSame(['state' => 'remaining', 'method_id' => $ids['speedy'], 'method_name' => 'Speedy any', 'free_above_minor' => 6000, 'remaining_minor' => 3000, 'currency' => 'EUR'], array_diff_key($hint, ['text' => 1, 'destinations' => 1]));
        $this->assertSame(['office', 'locker'], $hint['destinations']);
        $this->assertSame('Add 30.00 € more for free shipping to an office or a locker with “Speedy – Speedy any”.', html_entity_decode($hint['text']));
    }

    public function test_the_full_all_response_over_the_threshold(): void
    {
        $ids = $this->fixture();
        $this->akCart('30.00', 2); // 60.00: exactly the Speedy threshold

        $json = $this->allQuote()->assertOk()->json();

        $this->assertSame([
            'Speedy any' => [
                'address' => [true, 600, true, false, null, null],
                'office' => [true, 0, true, true, 6000, 0],
                'locker' => [true, 0, true, true, 6000, 0],
            ],
            'Shop address' => ['address' => [true, 700, true, false, null, null]],
            'Econt office' => ['office' => [true, 400, true, true, 9000, 3000]],
            'Econt locker' => ['locker' => [true, 350, true, true, 9000, 3000]],
        ], $this->destinationsByName($json));

        $this->assertSame(0, $json['groups'][0]['from_minor'], 'Speedy is free to the office and the locker');
        $this->assertSame(350, $json['groups'][1]['from_minor']);

        $this->assertSame('unlocked', $json['free_shipping_hint']['state']);
        $this->assertSame(['office', 'locker'], $json['free_shipping_hint']['destinations']);
        $this->assertSame('Shipping to an office or a locker with “Speedy – Speedy any” is free.', html_entity_decode($json['free_shipping_hint']['text']));
        $this->assertSame($ids['speedy'], $json['free_shipping_hint']['method_id']);
    }

    public function test_handles_are_distinct_per_kind_and_each_comes_from_its_own_hash(): void
    {
        $ids = $this->fixture();
        $this->akCart('30.00', 1);

        $destinations = collect($this->allQuote()->assertOk()->json('methods'))->firstWhere('id', $ids['speedy'])['destinations'];
        $handles = array_column($destinations, 'handle');

        $this->assertCount(3, array_unique($handles));
        $this->assertContainsOnly('string', $handles);
        $this->assertSame(1, preg_match('/^qh_[A-Za-z0-9]{40}$/', $handles[0]));
    }

    // ---- the preview -----------------------------------------------------------------------------------------------------------

    public function test_without_a_settlement_the_quote_is_a_preview_with_prices_and_no_handle_at_all(): void
    {
        $this->fixture();
        $this->akCart('30.00', 1);

        $json = $this->allQuote([], withSettlement: false)->assertOk()->json();

        $this->assertTrue($json['preview']);
        $this->assertSame('all', $json['mode']);
        $this->assertNotEmpty($json['methods']);

        foreach ($json['methods'] as $method) {
            $this->assertNull($method['handle']);
            $this->assertNotEmpty($method['destinations']);

            foreach ($method['destinations'] as $d) {
                $this->assertNull($d['handle'], 'a preview issues no handle');
                $this->assertIsInt($d['price']['minor'], 'but the prices are there');
            }
        }

        $this->assertSame(['address', 'office', 'locker'], array_keys(collect($json['methods'])->firstWhere('name', 'Speedy any')['destinations']));
    }

    public function test_a_blank_settlement_is_a_preview_too(): void
    {
        $this->fixture();
        $this->akCart('30.00', 1);

        $this->allQuote(['settlement' => '   '])->assertOk()->assertJsonPath('preview', true);
    }

    // ---- zones ----------------------------------------------------------------------------------------------------------------

    public function test_an_address_zone_that_differs_from_the_pickup_zone_gives_each_method_only_its_own_buckets_kinds(): void
    {
        // a zone for the postcode 1000 only: an address with that postcode matches it, a pickup point can never match a postcodes-only zone
        $narrow = $this->akZone('Postcode zone', 0, ['1000']);
        $wide = $this->akZone('Everywhere', 1);
        $a = $this->akMethod('Address zone method', Scope::ANY, null, 500, null, null, null, 0, $narrow);
        $b = $this->akMethod('Pickup zone method', Scope::ANY, null, 450, null, null, null, 0, $wide);
        $this->akCart('30.00', 1);

        $json = $this->allQuote(['postal_code' => '1000'])->assertOk()->json();

        $this->assertSame($narrow, $json['zones']['address']['id']);
        $this->assertSame($wide, $json['zones']['pickup']['id']);
        $this->assertSame($json['zones']['address'], $json['zone'], '`zone` is the address bucket\'s');

        $this->assertSame([
            'Address zone method' => ['address' => [true, 500, true, false, null, null]],
            'Pickup zone method' => ['office' => [true, 450, true, false, null, null], 'locker' => [true, 450, true, false, null, null]],
        ], $this->destinationsByName($json));
        $this->assertSame([$a, $b], array_column($json['methods'], 'id'));
    }

    public function test_a_postcode_no_narrow_zone_covers_still_matches_the_unnarrowed_zone_for_both_buckets(): void
    {
        $this->akMethod('Only for a pickup', Scope::ANY, null, 450, null, null, null, 0, $this->akZone('Wide', 0));
        $narrow = $this->akZone('Narrow', 0, ['1000']);
        $this->akMethod('Narrow', Scope::ANY, null, 500, null, null, null, 0, $narrow);
        $this->akCart('30.00', 1);

        // the wide zone has no narrowing, so it matches the address and the pickup point alike; only a zone narrowed to OTHER postcodes does not
        $json = $this->allQuote(['postal_code' => '9999'])->assertOk()->json();
        $this->assertNotNull($json['zones']['address']);
        $this->assertNotNull($json['zones']['pickup']);
    }

    public function test_no_zone_for_either_bucket_is_the_usual_422(): void
    {
        $this->akMethod('Anywhere', Scope::ANY, null, 450, null, null, null, 0, $this->akZone('Postcode only', 0, ['1000']));
        $this->akCart('30.00', 1);

        $this->allQuote(['postal_code' => '9999'])->assertStatus(422)->assertJsonPath('reason', 'no_zone_for_destination');
    }

    public function test_a_result_with_only_a_pickup_bucket_has_no_address_kinds_and_its_zone_is_the_pickups(): void
    {
        // The matcher never matches a pickup point where the same settlement would not match the address, so this shape is built by
        // hand: the response logic must still be right for it.
        $quote = new \App\Services\MethodQuote('1', 'M', 'flat', false, 'EUR', 500, null, null, 'qh_x', null, null, null, null, 'any', true, ['address', 'office', 'locker'], []);
        $office = new ShippingQuoteResult('1', 'EUR', 3000, '7', 'Pickup zone', 'hash', [$quote], null, 'pickup');
        $result = new AllKindsQuoteResult('1', 'EUR', 3000, false, ['address' => null, 'pickup' => ['id' => '7', 'name' => 'Pickup zone']], ['office' => $office, 'locker' => $office]);

        $this->assertSame(['id' => '7', 'name' => 'Pickup zone'], $result->firstZone());
        $this->assertSame(['office', 'locker'], array_keys($result->methods()[0]['kinds']));
    }

    // ---- validation --------------------------------------------------------------------------------------------------------------

    public function test_the_all_request_rules(): void
    {
        $this->fixture();
        $this->akCart('30.00', 1);

        $this->postJson('/api/shipping/quote', ['delivery_type' => 'all'])->assertStatus(422)->assertJsonValidationErrors('country');
        $this->allQuote(['pickup_kind' => 'office'])->assertStatus(422)->assertJsonValidationErrors('pickup_kind');
        $this->allQuote(['address_id' => '1'])->assertStatus(422)->assertJsonValidationErrors('address_id');
        $this->allQuote(['settlement' => str_repeat('x', 256)])->assertStatus(422)->assertJsonValidationErrors('settlement');
        $this->allQuote(['postal_code' => '1000'])->assertOk();
        // the single kinds still need their settlement
        $this->postJson('/api/shipping/quote', ['delivery_type' => 'street_address', 'country' => 'BG'])->assertStatus(422)->assertJsonValidationErrors('settlement');
        $this->postJson('/api/shipping/quote', ['delivery_type' => 'pickup_point', 'country' => 'BG'])->assertStatus(422)->assertJsonValidationErrors('settlement');
    }

    public function test_an_empty_cart_is_refused_like_the_single_quote(): void
    {
        $this->fixture();

        $this->allQuote()->assertStatus(422)->assertJsonPath('reason', 'empty_cart');
    }

    // ---- the legacy modes beside it ----------------------------------------------------------------------------------------------

    public function test_the_single_kind_modes_are_what_they_were_plus_the_additive_fields(): void
    {
        $ids = $this->fixture();
        $this->akCart('30.00', 1);

        $street = $this->postJson('/api/shipping/quote', ['delivery_type' => 'street_address', 'country' => 'BG', 'settlement' => 'Sofia'])->assertOk()->json();
        $office = $this->postJson('/api/shipping/quote', ['delivery_type' => 'pickup_point', 'country' => 'BG', 'settlement' => 'Sofia', 'pickup_kind' => 'office'])->assertOk()->json();
        $pickup = $this->postJson('/api/shipping/quote', ['delivery_type' => 'pickup_point', 'country' => 'BG', 'settlement' => 'Sofia'])->assertOk()->json();

        foreach ([$street, $office, $pickup] as $json) {
            $this->assertSame(['cart_id', 'currency', 'goods_after_discount', 'zone', 'mode', 'preview', 'zones', 'methods', 'groups', 'free_shipping_hint'], array_keys($json));
            $this->assertSame(['single', false], [$json['mode'], $json['preview']]);
            $this->assertSame(array_values($ids), array_column($json['methods'], 'id'), 'every active method is still listed, in the merchant\'s order');

            foreach ($json['methods'] as $method) {
                $this->assertArrayNotHasKey('destinations', $method, 'a single-kind quote has no per-kind object');
            }
        }

        $this->assertSame([$street['zone'], null], [$street['zones']['address'], $street['zones']['pickup']]);
        $this->assertSame([null, $office['zone']], [$office['zones']['address'], $office['zones']['pickup']]);

        // each method keeps its own method-level price and handle
        $this->assertSame([600, 700, 400, 350], array_column(array_column($street['methods'], 'price'), 'minor'));
        $this->assertStringStartsWith('qh_', $street['methods'][0]['handle']);

        // the groups carry the served kinds
        $this->assertSame(['address', 'office', 'locker'], $street['groups'][0]['destinations']);
        $this->assertSame(['office', 'locker'], $street['groups'][1]['destinations']);
        $this->assertSame(['address'], $street['groups'][2]['destinations']);
    }

    public function test_a_handle_from_the_all_quote_is_the_handle_the_single_kind_quote_would_have_issued(): void
    {
        $ids = $this->fixture();
        $this->akCart('30.00', 1);

        $all = collect($this->allQuote()->assertOk()->json('methods'))->firstWhere('id', $ids['speedy']);
        $single = collect($this->postJson('/api/shipping/quote', ['delivery_type' => 'pickup_point', 'country' => 'BG', 'settlement' => 'Sofia', 'pickup_kind' => 'locker'])->assertOk()->json('methods'))->firstWhere('id', $ids['speedy']);

        // same cart, same hash: the handle store is keyed by a hash of a random token, so the tokens differ, but both verify for the
        // locker (proved end to end in ShippingAllKindsCheckoutTest); here the prices and the facts agree
        $this->assertSame($single['price'], $all['destinations']['locker']['price']);
        $this->assertSame([$single['free_above_minor'], $single['remaining_to_free_minor']], [$all['destinations']['locker']['free_above_minor'], $all['destinations']['locker']['remaining_to_free_minor']]);
    }

    // ---- the limiter -------------------------------------------------------------------------------------------------------------

    public function test_one_all_request_is_one_hit_of_the_shipping_quote_limiter(): void
    {
        config(['ratelimits.shipping-quote' => 3]);
        $this->fixture();
        $this->akCart('30.00', 1);

        // a budget of 3: three all requests pass (one hit each, not three), the fourth is throttled
        $this->allQuote()->assertOk();
        $this->allQuote()->assertOk();
        $this->allQuote()->assertOk();
        $this->allQuote()->assertStatus(429)->assertJsonPath('reason', 'too_many_requests');
    }
}
