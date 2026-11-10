<?php

namespace Tests\Feature;

use App\Services\PriceListWriter;
use App\Services\PriceSort\ProductSortPriceRefresher;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\ReadModels\ListingQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * P6b: the card, the product page, the cart line and the stored sort price (`price_from_minor`) are the SAME price, because they all go through the resolver: regular 100 €, Manual Sale 85 € under the owner's three
 * campaigns (−30% replacing → 70 €, −10% replacing → 85 €, −30% not replacing → 85 €); and a campaign made in the admin with a start in the future becomes effective when `prices:refresh-boundaries` runs.
 */
class PriceListConsistencyTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->withHeader('Referer', 'http://localhost/');
        $this->seedPricing();
        $this->makeProduct(1, ['slug' => 'manual', 'price' => '100.00', 'sale' => '85.00', 'stock' => 5]);
    }

    /** @return array{card: ?int, page: ?int, cart: ?int, stored: ?int} */
    private function prices(): array
    {
        $this->app->forgetScopedInstances();
        $reader = app(CatalogReader::class);
        $card = $reader->listing(ListingQuery::fromRequestArray([]))->items[0]->price?->fromMinor;
        $page = $reader->product('manual')->price?->fromMinor;

        $this->flushSession();
        $this->postJson('/api/cart/lines', ['variation_id' => '101', 'quantity' => 1])->assertStatus(201);
        $cart = $this->getJson('/api/cart')->assertOk()->json('lines.0.unit_price.minor');

        app(ProductSortPriceRefresher::class)->refresh([1]);
        $stored = DB::table('catalog_products')->where('id', 1)->value('price_from_minor');

        return ['card' => $card, 'page' => $page, 'cart' => $cart, 'stored' => $stored === null ? null : (int) $stored];
    }

    private function campaign(string $percentage, bool $replaces, ?string $id = null): string
    {
        $data = ['name' => 'Campaign', 'percentage' => $percentage, 'is_active' => true, 'replaces_manual_sale' => $replaces, 'confirm_all_products' => true, 'scopes' => []];

        return $id === null ? (string) app(PriceListWriter::class)->create($data)->id() : (string) app(PriceListWriter::class)->update($id, $data)->id();
    }

    /** ★ ONE test: card, product page, cart line and the stored sort price agree for the three examples */
    public function test_card_page_cart_and_stored_price_agree_for_the_three_examples(): void
    {
        $this->assertSame(['card' => 8500, 'page' => 8500, 'cart' => 8500, 'stored' => 8500], $this->prices(), 'no campaign: the Manual Sale price');

        $id = $this->campaign('30', true);
        $this->assertSame(['card' => 7000, 'page' => 7000, 'cart' => 7000, 'stored' => 7000], $this->prices(), '−30% replacing the manual sale price: 70 €');

        $this->campaign('10', true, $id);
        $this->assertSame(['card' => 8500, 'page' => 8500, 'cart' => 8500, 'stored' => 8500], $this->prices(), '−10% replacing: the lower is the manual sale price, 85 €');

        $this->campaign('30', false, $id);
        $this->assertSame(['card' => 8500, 'page' => 8500, 'cart' => 8500, 'stored' => 8500], $this->prices(), '−30% NOT replacing: the manual sale price is kept, 85 €');
    }

    /** ★ the stored sort price follows the campaign at once when it is saved / switched (the fast path), without a manual refresh */
    public function test_the_stored_price_follows_the_campaign_without_a_manual_refresh(): void
    {
        $id = $this->campaign('30', true);
        $stored = fn () => (int) DB::table('catalog_products')->where('id', 1)->value('price_from_minor');

        $this->assertSame(7000, $stored(), 'saved: the queue is sync, the refresh ran after the commit');

        app(PriceListWriter::class)->setActive($id, false);
        $this->assertSame(8500, $stored(), 'switched off: back to the manual sale price');

        app(PriceListWriter::class)->setActive($id, true);
        $this->assertSame(7000, $stored());
    }

    /** ★ a campaign made in the admin with a start in the FUTURE is planned; when its start passes the boundary command makes it effective (the clock is moved by moving the list's own start behind «now») */
    public function test_a_campaign_with_a_future_start_becomes_effective_when_the_boundary_command_runs(): void
    {
        $now = CarbonImmutable::now();
        $list = app(PriceListWriter::class)->create([
            'name' => 'Starts soon', 'percentage' => '30', 'is_active' => true, 'replaces_manual_sale' => true, 'confirm_all_products' => true, 'scopes' => [],
            'valid_from' => $now->addMinutes(2)->setTimezone(app(\App\Settings\StoreTimezone::class)->zone())->format('Y-m-d H:i'), // typed in the SHOP time zone
        ]);
        app(ProductSortPriceRefresher::class)->refresh([1]);
        $stored = fn () => (int) DB::table('catalog_products')->where('id', 1)->value('price_from_minor');

        $this->assertSame(8500, $stored(), 'planned: it changes nothing yet');

        // two minutes pass: the list's start is now behind «now» (the resolver reads the real clock, so the start is moved, not the clock); the last processed moment is just before it
        $start = $now->subSeconds(20);
        DB::table('pricing_price_lists')->where('id', $list->id())->update(['valid_from' => $start]);
        Cache::forever('prices.boundaries.last_processed', $now->subSeconds(60)->toIso8601String());

        Artisan::call('prices:refresh-boundaries');

        $this->assertSame(7000, $stored(), 'the boundary command refreshed the product: the campaign is effective');
    }
}
