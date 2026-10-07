<?php

namespace Tests\Feature;

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
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingMethod;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Promotion;
use EasyCo\Shipping\ShippingZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The cart's free_shipping_hint (shipping stage 3e, §5.1): the "add X more"
 * sentence computed BEFORE any address exists, from the store country's broad
 * zone. It reuses the real matcher and calculator, so these are real HTTP
 * requests through GET /api/cart with real zones and methods.
 */
final class CartFreeShippingHintTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    private ?PriceList $priceList = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The guest cart token needs a recognized Referer to engage the session.
        $this->withHeader('Referer', 'http://localhost/');
    }

    /** A priced, stocked SIMPLE variation. */
    private function variation(string $price): string
    {
        self::$counter++;
        $n = self::$counter;
        $product = Product::createSimple("Product {$n}", "SKU-{$n}", "product-{$n}");
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        app(PriceListItemRepository::class)->save(new PriceListItem(
            null, $this->priceList->id(), PriceListItemTargetType::VARIATION, $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($price, 'EUR'), 0),
        ));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 100));

        return $variationId;
    }

    private function addLine(string $price, int $quantity = 1): void
    {
        $this->postJson('/api/cart/lines', ['variation_id' => $this->variation($price), 'quantity' => $quantity])->assertStatus(201);
    }

    private function storeCountry(?string $code): void
    {
        if ($code !== null) {
            app(SiteSettingsRepository::class)->set('site.country', $code);
        }
    }

    private function zone(?array $settlements = null, ?array $postcodes = null): string
    {
        $zone = ShippingZone::create('Zone '.(++self::$counter), 0, ['BG'], $settlements, $postcodes);
        app(ShippingZoneRepository::class)->save($zone);

        return (string) $zone->id();
    }

    private function method(string $zoneId, string $name, ShippingMethodKind $kind = ShippingMethodKind::FLAT, int $amount = 500, ?int $freeAbove = null): string
    {
        $method = ShippingMethod::create($zoneId, $name, $kind, 0, true, $kind === ShippingMethodKind::FREE ? null : $amount, [], $freeAbove);
        app(ShippingMethodRepository::class)->save($method);

        return (string) $method->id();
    }

    private function hint(): ?array
    {
        return $this->getJson('/api/cart')->assertOk()->json('free_shipping_hint');
    }

    private function storeLocale(string $locale): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', $locale);
    }

    public function test_the_cart_says_how_much_more_unlocks_free_shipping(): void
    {
        $this->storeCountry('BG');
        $this->storeLocale('en');
        $this->method($this->zone(), 'Econt office', ShippingMethodKind::FLAT, 500, 10000);
        $this->addLine('76.50');

        $hint = $this->hint();

        $this->assertSame('remaining', $hint['state']);
        $this->assertSame('Econt office', $hint['method_name']);
        $this->assertSame(10000, $hint['free_above_minor']);
        $this->assertSame(2350, $hint['remaining_minor'], '100.00 - 76.50 = 23.50');
        $this->assertSame('EUR', $hint['currency']);
        $this->assertSame('Add 23.50 € more for free shipping with “Econt office”.', $hint['text']);
    }

    public function test_the_hint_is_unlocked_at_the_threshold(): void
    {
        $this->storeCountry('BG');
        $this->method($this->zone(), 'Econt office', ShippingMethodKind::FLAT, 500, 10000);
        $this->addLine('100.00');

        $hint = $this->hint();

        $this->assertSame('unlocked', $hint['state']);
        $this->assertSame(10000, $hint['free_above_minor']);
        $this->assertSame(0, $hint['remaining_minor']);
        $this->assertStringContainsString('is free', $hint['text']);
    }

    public function test_a_discount_that_drops_the_goods_below_the_threshold_changes_the_hint(): void
    {
        $this->storeCountry('BG');
        $this->method($this->zone(), 'Econt office', ShippingMethodKind::FLAT, 500, 10000);
        $this->addLine('100.00');

        // Exactly at the threshold before any discount: unlocked.
        $this->assertSame('unlocked', $this->hint()['state']);

        // A 10% code drops the customer's goods to 90.00: the basis is AFTER the discount, so 10.00 short again.
        app(PromotionRepository::class)->save(Promotion::create(
            code: 'TEN', discountType: PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 1000,
        ));
        $this->putJson('/api/cart/promotion', ['code' => 'TEN'])->assertOk();

        $hint = $this->hint();
        $this->assertSame('remaining', $hint['state']);
        $this->assertSame(1000, $hint['remaining_minor'], '1000.00 - 900.00 = 10.00');
    }

    public function test_no_store_country_yields_no_hint(): void
    {
        // The store country is unset: no zone can be chosen, so no hint — and the cart still answers.
        $this->method($this->zone(), 'Econt office', ShippingMethodKind::FLAT, 500, 10000);
        $this->addLine('76.50');

        $this->assertNull($this->hint());
    }

    public function test_no_zone_yields_no_hint(): void
    {
        $this->storeCountry('BG');
        $this->addLine('76.50'); // no zone at all

        $this->assertNull($this->hint());
    }

    public function test_an_empty_cart_yields_no_hint_and_never_errors(): void
    {
        $this->storeCountry('BG');
        $this->method($this->zone(), 'Econt office', ShippingMethodKind::FLAT, 500, 10000);

        $response = $this->getJson('/api/cart')->assertOk();
        $this->assertNull($response->json('free_shipping_hint'));
        $this->assertNull($response->json('cart_id'));
    }

    public function test_a_settlement_narrowed_zone_is_not_used_the_broad_zone_is(): void
    {
        $this->storeCountry('BG');
        $this->method($this->zone(), 'Broad', ShippingMethodKind::FLAT, 500, 10000);
        $this->method($this->zone(['Sofia']), 'Narrow', ShippingMethodKind::FLAT, 500, 20000);
        $this->addLine('76.50');

        $hint = $this->hint();

        $this->assertSame('Broad', $hint['method_name'], 'an address-less cart never picks a settlement-narrowed zone');
        $this->assertSame(2350, $hint['remaining_minor']);
    }

    public function test_a_free_method_never_produces_a_hint(): void
    {
        $this->storeCountry('BG');
        $this->method($this->zone(), 'Free delivery', ShippingMethodKind::FREE);
        $this->addLine('76.50');

        $this->assertNull($this->hint(), 'FREE has no threshold to talk about');
    }

    public function test_a_postcode_narrowed_zone_is_not_used_the_broad_zone_is(): void
    {
        $this->storeCountry('BG');
        $this->method($this->zone(), 'Broad', ShippingMethodKind::FLAT, 500, 10000);
        $this->method($this->zone(postcodes: ['1000']), 'Narrow', ShippingMethodKind::FLAT, 500, 20000);
        $this->addLine('76.50');

        $this->assertSame('Broad', $this->hint()['method_name']);
    }

    public function test_the_hint_reads_a_fixed_number_of_rows_for_one_line(): void
    {
        $this->storeCountry('BG');
        $this->method($this->zone(), 'Econt office', ShippingMethodKind::FLAT, 500, 10000);
        $this->addLine('10.00');

        $counts = $this->measureCartCold();

        fwrite(STDERR, sprintf(
            "\n[query-count] GET /api/cart (1 line, cold, with a hint): %d shipping + %d settings reads (%d total)\n",
            $counts['shipping'], $counts['settings'], $counts['total'],
        ));

        $this->assertSame(3, $counts['shipping'], 'the zones, the methods and their rates');
        $this->assertLessThanOrEqual(3, $counts['settings'], 'at most the country, the locale and the currency symbol');
    }

    public function test_the_hint_reads_the_same_fixed_number_for_ten_lines(): void
    {
        $this->storeCountry('BG');
        $this->method($this->zone(), 'Econt office', ShippingMethodKind::FLAT, 500, 10000);

        for ($i = 0; $i < 10; $i++) {
            $this->addLine('10.00');
        }

        $counts = $this->measureCartCold();

        fwrite(STDERR, sprintf(
            "\n[query-count] GET /api/cart (10 lines, cold, with a hint): %d shipping + %d settings reads (%d total)\n",
            $counts['shipping'], $counts['settings'], $counts['total'],
        ));

        $this->assertSame(3, $counts['shipping'], 'the hint never reads per line');
        $this->assertLessThanOrEqual(3, $counts['settings'], 'the settings reads do not grow with lines either');
    }

    /**
     * A COLD measured GET /api/cart (a fresh set of scoped instances, so the request reads the
     * settings memo freshly, the way a real fresh-app request does). One cold measurement per
     * test: the container's forgetScopedInstances() reliably resets the memo only once.
     *
     * @return array{shipping: int, settings: int, total: int}
     */
    private function measureCartCold(): array
    {
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

        $this->getJson('/api/cart')->assertOk();

        DB::flushQueryLog();

        return $counts;
    }
}
