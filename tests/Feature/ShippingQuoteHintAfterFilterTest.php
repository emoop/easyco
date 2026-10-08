<?php

namespace Tests\Feature;

use App\Services\FreeShippingHintReader;
use App\Services\MethodQuote;
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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stage 4b (shipping-domain-design.md §9.1.7): the free-shipping hint is recomputed from the list the
 * `shipping.quotes` filter LEFT, so it never names a method the customer is not offered. The handle, the
 * pricing hash, the cart hint and the JSON shape are untouched.
 */
class ShippingQuoteHintAfterFilterTest extends TestCase
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
        $product = Product::createSimple("Hint Product {$n}", "HP-{$n}", "hint-product-{$n}");
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

    private function method(string $name, int $sort, int $amount, ?int $freeAbove, ?string $courier = null, ?ShippingDeliveryType $type = null): string
    {
        if ($this->zoneId === null) {
            $zone = ShippingZone::create('Zone '.(++self::$counter), 0, ['BG']);
            app(ShippingZoneRepository::class)->save($zone);
            $this->zoneId = (string) $zone->id();
        }

        $method = ShippingMethod::create($this->zoneId, $name, ShippingMethodKind::FLAT, $sort, true, $amount, [], $freeAbove, null, false, ShippingClassMode::REPLACE, $courier, $type);
        app(ShippingMethodRepository::class)->save($method);

        return (string) $method->id();
    }

    private function cartWith(string $variationId, int $quantity): void
    {
        $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => $quantity])->assertStatus(201);
    }

    private function quote(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/shipping/quote', ['delivery_type' => 'street_address', 'country' => 'BG', 'settlement' => 'Sofia'])->assertOk();
    }

    /** A `shipping.quotes` filter that drops the given method ids. */
    private function removing(string ...$ids): void
    {
        Hook::filter('shipping.quotes', fn (array $methods): array => array_values(array_filter(
            $methods,
            static fn (MethodQuote $quote): bool => ! in_array($quote->methodId, $ids, true),
        )));
    }

    public function test_a_filter_removing_the_hinted_method_moves_the_hint_to_the_next_qualifying_method(): void
    {
        $near = $this->method('Near', 0, 500, 5000);  // 20.00 goods -> remaining 30.00: the hinted one
        $far = $this->method('Far', 1, 500, 10000);   //            -> remaining 80.00
        $this->cartWith($this->variation('10.00'), 2);

        $this->assertSame($near, $this->quote()->json('free_shipping_hint.method_id'), 'unfiltered: the nearest method');

        $this->removing($near);
        $response = $this->quote();

        $response->assertJsonPath('free_shipping_hint.method_id', $far)
            ->assertJsonPath('free_shipping_hint.method_name', 'Far')
            ->assertJsonPath('free_shipping_hint.remaining_minor', 8000)
            ->assertJsonMissingPath('methods.1');
        $this->assertSame([$far], array_column($response->json('methods'), 'id'));
        $this->assertStringContainsString('Far', $response->json('free_shipping_hint.text'));
        $this->assertStringNotContainsString('Near', $response->json('free_shipping_hint.text'));
    }

    public function test_a_filter_removing_every_threshold_method_leaves_no_hint(): void
    {
        $near = $this->method('Near', 0, 500, 5000);
        $plain = $this->method('Plain', 1, 500, null);
        $this->cartWith($this->variation('10.00'), 2);

        $this->removing($near);

        $response = $this->quote();
        $response->assertJsonPath('free_shipping_hint', null);
        $this->assertSame([$plain], array_column($response->json('methods'), 'id'));
    }

    public function test_a_filter_removing_a_different_method_leaves_the_hint_unchanged(): void
    {
        $near = $this->method('Near', 0, 500, 5000);
        $this->method('Far', 1, 500, 10000);
        $plain = $this->method('Plain', 2, 500, null);
        $this->cartWith($this->variation('10.00'), 2);

        $before = $this->quote()->json('free_shipping_hint');
        $this->removing($plain);
        $after = $this->quote()->json('free_shipping_hint');

        $this->assertSame($near, $after['method_id']);
        $this->assertSame($before, $after, 'byte for byte the same hint');
    }

    public function test_a_filter_changing_an_amount_does_not_change_the_hint(): void
    {
        $near = $this->method('Near', 0, 500, 5000);
        $this->method('Far', 1, 500, 10000);
        $this->cartWith($this->variation('10.00'), 2);

        $before = $this->quote()->json('free_shipping_hint');

        // Rebuilt through MethodQuote::priced(): it carries no threshold facts, and the amount differs.
        Hook::filter('shipping.quotes', function (array $methods): array {
            $methods[0] = MethodQuote::priced($methods[0]->methodId, $methods[0]->name, $methods[0]->kind, $methods[0]->requiresPickupPoint, $methods[0]->currency, 1);

            return $methods;
        });

        $response = $this->quote();
        $response->assertJsonPath('methods.0.price.minor', 1);
        $this->assertSame($near, $response->json('free_shipping_hint.method_id'));
        $this->assertSame($before, $response->json('free_shipping_hint'));
    }

    public function test_with_no_filter_the_hint_is_exactly_what_the_unfiltered_reader_gives(): void
    {
        $this->method('Near', 0, 500, 5000);
        $this->method('Far', 1, 500, 10000);
        $this->cartWith($this->variation('10.00'), 2);

        $response = $this->quote();

        $response->assertJsonPath('free_shipping_hint', [
            'state' => 'remaining',
            'method_id' => $response->json('free_shipping_hint.method_id'),
            'method_name' => 'Near',
            'free_above_minor' => 5000,
            'remaining_minor' => 3000,
            'currency' => 'EUR',
            'text' => $response->json('free_shipping_hint.text'),
        ]);
    }

    public function test_the_courier_display_name_survives_the_recompute_and_method_name_stays_plain(): void
    {
        $near = $this->method('To office', 0, 500, 5000, 'Econt', ShippingDeliveryType::OFFICE);
        $this->method('Far', 1, 500, 10000);
        $this->cartWith($this->variation('10.00'), 2);

        $this->removing('does-not-exist-so-the-filter-runs');
        $response = $this->quote();

        $response->assertJsonPath('free_shipping_hint.method_id', $near)
            ->assertJsonPath('free_shipping_hint.method_name', 'To office');
        $this->assertStringContainsString('Econt – To office', $response->json('free_shipping_hint.text'));
    }

    public function test_the_unlocked_state_is_recomputed_too(): void
    {
        $cheap = $this->method('Cheap', 0, 500, 1000);   // 20.00 goods: already unlocked
        $dear = $this->method('Dear', 1, 500, 1500);     // also unlocked, higher threshold
        $this->cartWith($this->variation('10.00'), 2);

        $this->assertSame($cheap, $this->quote()->json('free_shipping_hint.method_id'), 'the lowest threshold');

        $this->removing($cheap);
        $response = $this->quote();
        $response->assertJsonPath('free_shipping_hint.state', 'unlocked')->assertJsonPath('free_shipping_hint.method_id', $dear);
    }

    public function test_the_text_is_in_the_store_language_in_bg_and_en(): void
    {
        $near = $this->method('Near', 0, 500, 5000);
        $this->method('Far', 1, 500, 10000);
        $this->cartWith($this->variation('10.00'), 2);
        $this->removing($near);

        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        $this->assertStringContainsString('Добавете още', $this->quote()->json('free_shipping_hint.text'));

        app(SiteSettingsRepository::class)->set('site.locale', 'en');
        $this->assertStringContainsString('more for free shipping', $this->quote()->json('free_shipping_hint.text'));
    }

    public function test_the_handle_and_the_cart_hint_are_untouched(): void
    {
        $near = $this->method('Near', 0, 500, 5000);
        $far = $this->method('Far', 1, 500, 10000);
        $this->cartWith($this->variation('10.00'), 2);
        $this->removing($near);

        $this->quote()->assertJsonPath('methods.0.id', $far);
        $this->assertStringStartsWith('qh_', $this->quote()->json('methods.0.handle'));

        // O9: the cart's hint is computed without a destination, so no quote filter applies to it.
        app(SiteSettingsRepository::class)->set('site.country', 'BG');
        $this->getJson('/api/cart')->assertOk()->assertJsonPath('free_shipping_hint.method_id', $near);
    }

    public function test_readFromQuotes_skips_unavailable_carrier_and_thresholdless_quotes(): void
    {
        $reader = app(FreeShippingHintReader::class);

        $list = [
            MethodQuote::unavailable('1', 'Down', 'carrier', false, 'EUR', 'timed_out'),
            MethodQuote::priced('2', 'Live', 'carrier', false, 'EUR', 700, 'std'),
            MethodQuote::priced('3', 'Plain', 'flat', false, 'EUR', 500),
            MethodQuote::priced('4', 'Local', 'flat', false, 'EUR', 500, null, 5000, 1200),
        ];

        $this->assertSame('4', $reader->readFromQuotes($list, 'EUR')?->methodId);
        $this->assertNull($reader->readFromQuotes(array_slice($list, 0, 3), 'EUR'));
        $this->assertNull($reader->readFromQuotes([], 'EUR'));
    }

    public function test_the_quote_with_a_filter_that_removes_the_hinted_method_gains_no_query(): void
    {
        $near = $this->method('Near', 0, 500, 5000);
        $this->method('Far', 1, 500, 10000);
        $this->cartWith($this->variation('10.00'), 2);
        $this->removing($near);

        $this->app->forgetScopedInstances();
        $counts = ['shipping' => 0, 'settings' => 0, 'total' => 0];
        DB::listen(function ($query) use (&$counts): void {
            $counts['total']++;
            if (str_contains($query->sql, 'shipping_')) {
                $counts['shipping']++;
            } elseif (str_contains($query->sql, 'site_settings')) {
                $counts['settings']++;
            }
        });

        $this->quote();

        fwrite(STDERR, sprintf(
            "\n[query-count] POST /api/shipping/quote (cold, filter removed the hinted method): %d shipping + %d settings reads (%d total)\n",
            $counts['shipping'], $counts['settings'], $counts['total'],
        ));

        $this->assertSame(3, $counts['shipping'], 'same as the 3e pin: the recompute reads nothing');
        $this->assertSame(2, $counts['settings'], 'same as the 3e pin with a hint: store locale + currency position');
    }
}
