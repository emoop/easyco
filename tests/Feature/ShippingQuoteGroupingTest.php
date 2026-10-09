<?php

namespace Tests\Feature;

use App\Services\MethodQuote;
use App\Services\QuoteGroups;
use App\Settings\Contracts\SiteSettingsRepository;
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
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingDeliveryType;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingMethod;
use EasyCo\Shipping\ShippingZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Stage 5f (shipping-domain-design.md §5.1, §6.8): the quote 200 returns the method's `courier` and `delivery_type`
 * and a top-level `groups` so a client can ask for the courier first and the delivery type second. Everything else —
 * the flat `methods`, the handle, the pricing, the filter contract — is unchanged: the 5d JSON is a strict subset.
 */
class ShippingQuoteGroupingTest extends TestCase
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

    private function variation(string $price): string
    {
        self::$counter++;
        $n = self::$counter;
        $product = Product::createSimple("Grouping Product {$n}", "GP-{$n}", "grouping-product-{$n}");
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

    private function zone(): string
    {
        $zone = ShippingZone::create('Zone '.(++self::$counter), 0, ['BG']);
        app(ShippingZoneRepository::class)->save($zone);

        return $this->zoneId = (string) $zone->id();
    }

    private function method(string $name, ?string $courier, ?ShippingDeliveryType $type, int $sort, int $amount = 500, ?int $freeAbove = null, ShippingMethodKind $kind = ShippingMethodKind::FLAT, ?string $carrier = null): string
    {
        $this->zoneId ??= $this->zone();
        $method = ShippingMethod::create($this->zoneId, $name, $kind, $sort, true, $kind === ShippingMethodKind::CARRIER ? null : $amount, [], $freeAbove, $carrier, in_array($type, [ShippingDeliveryType::OFFICE, ShippingDeliveryType::LOCKER], true), ShippingClassMode::REPLACE, $courier, $type);
        app(ShippingMethodRepository::class)->save($method);

        return (string) $method->id();
    }

    private function cartWith(string $variationId, int $quantity): void
    {
        $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => $quantity])->assertStatus(201);
    }

    private function quote(): TestResponse
    {
        return $this->postJson('/api/shipping/quote', ['delivery_type' => 'street_address', 'country' => 'BG', 'settlement' => 'Sofia'])->assertOk();
    }

    /** Two couriers x three delivery types, in an interleaved method order, plus one ungrouped method. */
    private function twoCouriers(): array
    {
        return [
            'econt_office' => $this->method('To office', 'Econt', ShippingDeliveryType::OFFICE, 0, 400),
            'speedy_office' => $this->method('To office', 'Speedy', ShippingDeliveryType::OFFICE, 1, 450),
            'econt_address' => $this->method('To address', 'Econt', ShippingDeliveryType::ADDRESS, 2, 700),
            'plain' => $this->method('Pickup in the shop', null, null, 3, 0),
            'speedy_address' => $this->method('To address', ' SPEEDY ', ShippingDeliveryType::ADDRESS, 4, 750),
            'econt_locker' => $this->method('To locker', 'econt', ShippingDeliveryType::LOCKER, 5, 350),
            'speedy_locker' => $this->method('To locker', 'Speedy', ShippingDeliveryType::LOCKER, 6, 380),
        ];
    }

    // =====================================================================================================
    // The response
    // =====================================================================================================

    public function test_each_method_carries_its_courier_and_delivery_type(): void
    {
        $ids = $this->twoCouriers();
        $this->cartWith($this->variation('10.00'), 1);

        $response = $this->quote();

        $byId = [];
        foreach ($response->json('methods') as $method) {
            $byId[$method['id']] = $method;
        }

        $this->assertSame(['Econt', 'office'], [$byId[$ids['econt_office']]['courier'], $byId[$ids['econt_office']]['delivery_type']]);
        $this->assertSame(['SPEEDY', 'address'], [$byId[$ids['speedy_address']]['courier'], $byId[$ids['speedy_address']]['delivery_type']], 'stored trimmed');
        $this->assertSame([null, null], [$byId[$ids['plain']]['courier'], $byId[$ids['plain']]['delivery_type']]);
        $this->assertSame('locker', $byId[$ids['speedy_locker']]['delivery_type']);
    }

    public function test_groups_hold_one_entry_per_courier_in_order_of_the_first_method_and_the_ungrouped_last(): void
    {
        $ids = $this->twoCouriers();
        $this->cartWith($this->variation('10.00'), 1);

        $groups = $this->quote()->json('groups');

        $this->assertSame(['Econt', 'Speedy', null], array_column($groups, 'courier'), 'the display name is the first one met; "econt" and " SPEEDY " joined their groups');
        $this->assertSame([$ids['econt_office'], $ids['econt_address'], $ids['econt_locker']], $groups[0]['methods']);
        $this->assertSame([$ids['speedy_office'], $ids['speedy_address'], $ids['speedy_locker']], $groups[1]['methods']);
        $this->assertSame([$ids['plain']], $groups[2]['methods']);
        $this->assertSame([350, 450 - 70, 0], array_column($groups, 'from_minor'), 'the lowest price of each group');
        $this->assertSame(['EUR', 'EUR', 'EUR'], array_column($groups, 'currency'));
    }

    public function test_only_ungrouped_methods_give_a_single_trailing_entry(): void
    {
        $a = $this->method('Flat', null, null, 0, 500);
        $b = $this->method('Free', null, null, 1, 0);
        $this->cartWith($this->variation('10.00'), 1);

        $groups = $this->quote()->json('groups');

        $this->assertCount(1, $groups);
        $this->assertNull($groups[0]['courier']);
        $this->assertSame([$a, $b], $groups[0]['methods']);
        $this->assertSame(0, $groups[0]['from_minor']);
    }

    public function test_from_minor_ignores_unavailable_methods_and_is_null_when_none_is_available(): void
    {
        // A CARRIER method with no registered carrier is unavailable (not_configured); it has no price.
        $this->method('Carrier office', 'Econt', ShippingDeliveryType::OFFICE, 0, kind: ShippingMethodKind::CARRIER, carrier: 'econt');
        $flat = $this->method('To address', 'Econt', ShippingDeliveryType::ADDRESS, 1, 900);
        $this->method('Carrier locker', 'BoxNow', ShippingDeliveryType::LOCKER, 2, kind: ShippingMethodKind::CARRIER, carrier: 'boxnow');
        $this->cartWith($this->variation('10.00'), 1);

        $response = $this->quote();
        $groups = $response->json('groups');

        $this->assertFalse($response->json('methods.0.available'));
        $this->assertSame('Econt', $groups[0]['courier']);
        $this->assertSame(900, $groups[0]['from_minor'], 'the unavailable carrier method has no price to be the lowest');
        $this->assertCount(2, $groups[0]['methods'], 'but it is still listed in its group');
        $this->assertSame('BoxNow', $groups[1]['courier']);
        $this->assertNull($groups[1]['from_minor']);
        $this->assertNotContains($flat, $groups[1]['methods']);
    }

    public function test_a_filter_that_removes_a_method_removes_it_from_the_groups_too(): void
    {
        $ids = $this->twoCouriers();
        $this->cartWith($this->variation('10.00'), 1);

        // remove every Speedy method and the cheapest Econt one
        Hook::filter('shipping.quotes', function (array $methods) use ($ids): array {
            return array_values(array_filter($methods, fn (MethodQuote $method): bool => ! in_array($method->methodId, [
                $ids['speedy_office'], $ids['speedy_address'], $ids['speedy_locker'], $ids['econt_locker'],
            ], true)));
        });

        $response = $this->quote();
        $groups = $response->json('groups');

        $this->assertSame(['Econt', null], array_column($groups, 'courier'), 'a group with no method left is gone');
        $this->assertSame([$ids['econt_office'], $ids['econt_address']], $groups[0]['methods']);
        $this->assertSame(400, $groups[0]['from_minor'], 'from_minor is computed from what is left');
        $listed = array_merge(...array_column($groups, 'methods'));
        $this->assertEqualsCanonicalizing(array_column($response->json('methods'), 'id'), $listed, 'groups and methods agree exactly');
    }

    public function test_a_filter_that_changes_an_amount_changes_from_minor_and_keeps_the_courier(): void
    {
        $this->method('To office', 'Econt', ShippingDeliveryType::OFFICE, 0, 400);
        $this->method('To address', 'Econt', ShippingDeliveryType::ADDRESS, 1, 700);
        $this->cartWith($this->variation('10.00'), 1);

        // a filter rebuilding a method through MethodQuote::priced() knows nothing of the courier: it is the method row's
        Hook::filter('shipping.quotes', fn (array $methods): array => [
            MethodQuote::priced($methods[0]->methodId, $methods[0]->name, $methods[0]->kind, $methods[0]->requiresPickupPoint, $methods[0]->currency, 250),
            $methods[1],
        ]);

        $response = $this->quote();

        $this->assertSame(['Econt', 'office'], [$response->json('methods.0.courier'), $response->json('methods.0.delivery_type')]);
        $this->assertSame(250, $response->json('groups.0.from_minor'));
        $this->assertCount(1, $response->json('groups'));
    }

    public function test_the_5d_json_is_a_strict_subset_of_the_new_json(): void
    {
        $ids = $this->twoCouriers();
        $this->cartWith($this->variation('10.00'), 1);

        $json = $this->quote()->json();

        $this->assertSame(
            ['cart_id', 'currency', 'free_shipping_hint', 'goods_after_discount', 'groups', 'methods', 'zone'],
            $this->sorted(array_keys($json)),
            'the only new top-level key is groups',
        );

        $old = ['available', 'free_above_minor', 'handle', 'id', 'kind', 'name', 'price', 'remaining_to_free_minor', 'requires_pickup_point', 'service_code', 'unavailable_reason'];

        foreach ($json['methods'] as $method) {
            $this->assertSame($this->sorted(array_merge($old, ['courier', 'delivery_type', 'destination_scope', 'serves_destination'])), $this->sorted(array_keys($method)));
            $this->assertSame([], array_diff($old, array_keys($method)), 'nothing removed or renamed');
        }

        // the values the 5d response had are what they were: ids, order, names, prices, handles
        $this->assertSame(array_values($ids), array_column($json['methods'], 'id'));
        $this->assertSame([400, 450, 700, 0, 750, 350, 380], array_column(array_column($json['methods'], 'price'), 'minor'));
        $this->assertStringStartsWith('qh_', $json['methods'][0]['handle']);
        $this->assertSame(['id', 'name'], $this->sorted(array_keys($json['zone'])));
    }

    /** @param list<string> $values @return list<string> */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    public function test_the_grouping_builder_is_pure_over_the_final_list(): void
    {
        $priced = fn (string $id, ?string $courier, int $minor): MethodQuote => MethodQuote::priced($id, 'M'.$id, 'flat', false, 'EUR', $minor, null, null, null, $courier);
        $down = MethodQuote::unavailable('9', 'M9', 'carrier', false, 'EUR', 'provider_error', 'Econt');

        $groups = QuoteGroups::build([$priced('1', 'Econt', 900), $down, $priced('2', null, 100), $priced('3', 'Econt', 500)], 'EUR');

        $this->assertSame([
            ['courier' => 'Econt', 'methods' => ['1', '9', '3'], 'from_minor' => 500, 'currency' => 'EUR'],
            ['courier' => null, 'methods' => ['2'], 'from_minor' => 100, 'currency' => 'EUR'],
        ], $groups);
        $this->assertSame([], QuoteGroups::build([], 'EUR'));
    }

    // =====================================================================================================
    // End to end: free to the office and the locker above the threshold, always paid to the address
    // =====================================================================================================

    public function test_free_to_office_and_locker_above_the_threshold_and_paid_to_address_in_the_same_group(): void
    {
        $office = $this->method('To office', 'Econt', ShippingDeliveryType::OFFICE, 0, 400, 5000);
        $locker = $this->method('To locker', 'Econt', ShippingDeliveryType::LOCKER, 1, 350, 5000);
        $address = $this->method('To address', 'Econt', ShippingDeliveryType::ADDRESS, 2, 700);
        $variation = $this->variation('30.00');

        $this->cartWith($variation, 1); // 30.00: below the 50.00 threshold
        $below = $this->quote();
        $this->assertSame([400, 350, 700], array_column(array_column($below->json('methods'), 'price'), 'minor'));
        $this->assertSame(350, $below->json('groups.0.from_minor'));
        $this->assertSame(2000, $below->json('methods.0.remaining_to_free_minor'));
        $this->assertNull($below->json('methods.2.free_above_minor'), 'the address method has no threshold');

        $this->cartWith($variation, 1); // 60.00: at or above it
        $above = $this->quote();
        $this->assertSame([0, 0, 700], array_column(array_column($above->json('methods'), 'price'), 'minor'), 'free to office and locker, still paid to address');
        $this->assertSame(0, $above->json('groups.0.from_minor'));
        $this->assertSame([$office, $locker, $address], $above->json('groups.0.methods'));
        $this->assertCount(1, $above->json('groups'), 'one courier group, three delivery types');
    }

    // =====================================================================================================
    // The free-shipping hint names the method inside its group
    // =====================================================================================================

    public function test_the_hint_sentence_names_the_courier_and_the_method_and_stays_as_before_without_one(): void
    {
        $this->method('To office', 'Econt', ShippingDeliveryType::OFFICE, 0, 400, 5000);
        $this->cartWith($this->variation('30.00'), 1);

        $with = $this->quote();
        $this->assertSame('Add 20.00 € more for free shipping with “Econt – To office”.', html_entity_decode((string) $with->json('free_shipping_hint.text')));
        $this->assertSame('To office', $with->json('free_shipping_hint.method_name'), 'the field keeps the plain method name');
    }

    public function test_the_hint_without_a_courier_is_byte_for_byte_as_today(): void
    {
        $this->method('To office', null, ShippingDeliveryType::OFFICE, 0, 400, 5000);
        $this->cartWith($this->variation('30.00'), 1);

        $this->assertSame('Add 20.00 € more for free shipping with “To office”.', html_entity_decode((string) $this->quote()->json('free_shipping_hint.text')));
    }

    public function test_the_hint_in_bulgarian_and_when_unlocked(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        $this->method('До офис', 'Еконт', ShippingDeliveryType::OFFICE, 0, 400, 5000);
        $variation = $this->variation('30.00');
        $this->cartWith($variation, 1);

        $this->assertStringContainsString('„Еконт – До офис“', (string) $this->quote()->json('free_shipping_hint.text'));

        $this->cartWith($variation, 1);
        $unlocked = (string) $this->quote()->json('free_shipping_hint.text');
        $this->assertStringContainsString('„Еконт – До офис“', $unlocked);
        $this->assertSame('unlocked', $this->quote()->json('free_shipping_hint.state'));
    }

    public function test_the_cart_hint_uses_the_same_display_name_with_no_extra_query(): void
    {
        app(SiteSettingsRepository::class)->set('site.country', 'BG');
        $this->method('To office', 'Econt', ShippingDeliveryType::OFFICE, 0, 400, 5000);
        $this->cartWith($this->variation('30.00'), 1);

        $this->getJson('/api/cart')->assertOk(); // warm the per-request-independent caches (settings), so both measurements are alike

        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $withCourier = $this->getJson('/api/cart')->assertOk()->json('free_shipping_hint');
        $countWith = $queries;

        $this->assertStringContainsString('Econt – To office', html_entity_decode((string) $withCourier['text']));

        // the same cart, the same query count when the method has no courier
        \Illuminate\Support\Facades\DB::table('shipping_methods')->update(['courier' => null]);
        $queries = 0;
        $plain = $this->getJson('/api/cart')->assertOk()->json('free_shipping_hint');

        $this->assertSame($countWith, $queries, 'the courier is on the method row already: no extra query');
        $this->assertStringNotContainsString('–', (string) $plain['text']);
    }
}
