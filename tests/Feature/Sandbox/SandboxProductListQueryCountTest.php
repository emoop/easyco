<?php

namespace Tests\Feature\Sandbox;

use App\Services\ProductTimelinePromoter;
use DateTimeImmutable;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Pricing\Seeders\PricingSystemListsSeeder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * D4's own measurable requirement: "the query count must not grow with the
 * number of products on the page". Measured on the real rendered page with
 * real data — 24 listed products (a full page), then 5 — and printed, not
 * summarised.
 *
 * WHY TWO MEASUREMENTS AND NOT ONE PAGE AT TWO SIZES: D4 fixes the page
 * size at 24 (SandboxProductListTest asserts that), so the only honest way
 * to vary "the number of products on the page" is to vary how many
 * products are listed. 19 of the 24 are hidden through a real column
 * update, and the SAME URL is fetched again.
 *
 * forgetScopedInstances() BETWEEN THE TWO MEASUREMENTS IS LOAD-BEARING,
 * not a workaround: ProductPriceRangeProvider is bound scoped() and
 * memoizes per instance, and this test harness keeps ONE application
 * container alive across both $this->get() calls (the confirmed,
 * documented limitation also recorded in ProductResourcePriceColumnTest),
 * so without the reset the second request would be served partly from the
 * first request's memoized prices and the comparison would be
 * meaningless. forgetScopedInstances() is exactly what Laravel itself
 * does between requests in a long-running worker — the same convention
 * ProductResourcePriceColumnTest already uses.
 */
final class SandboxProductListQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    /**
     * PUBLIC, matching Illuminate\Foundation\Testing\TestCase's own
     * (public) signature — narrowing it to protected is a fatal error.
     *
     * @return Application
     */
    public function createApplication()
    {
        SandboxTestEnvironment::enableSandboxFlag();

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        SandboxTestEnvironment::restoreSandboxFlag();
    }

    public function test_the_list_page_query_count_is_identical_for_24_and_for_5_products(): void
    {
        app(PricingSystemListsSeeder::class)->run(app(PriceListRepository::class));

        $productIds = [];

        for ($i = 0; $i < 24; $i++) {
            $productIds[] = $this->listedPricedProduct($i);
        }

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $count = 0;
        $this->get('/_sandbox')->assertOk()->assertSee('24 products visible to a customer');
        $queriesForTwentyFour = $count;

        // Down to 5 listed products, then a genuinely fresh "request" —
        // TWO resets, both required, both found by running this test rather
        // than assumed:
        //  1. Route::flushController() — Laravel caches the resolved
        //     controller ON THE ROUTE OBJECT (Route::getController():
        //     "if (! $this->controller) { $this->controller = ... }"), and
        //     the route collection is application-lifetime, so without this
        //     the second $this->get() reuses the FIRST request's controller
        //     and therefore its constructor-injected SandboxProductListPage
        //     and ProductPriceRangeProvider — measured: 18 queries for the
        //     first request, 3 for the second, purely from that stale
        //     memoization.
        //  2. Container::forgetScopedInstances() — drops the scoped
        //     instances themselves (ProductPriceRangeProvider is bound
        //     scoped()), which is what Laravel itself does between requests
        //     in a long-running worker.
        // Together they reproduce what a real second request gets for free
        // (a fresh PHP-FPM process or Octane's own reset).
        ProductModel::whereIn('id', array_slice($productIds, 0, 19))
            ->update(['catalog_visibility' => CatalogVisibility::HIDDEN->value]);

        foreach (Route::getRoutes() as $route) {
            $route->flushController();
        }

        $this->app->forgetScopedInstances();

        $count = 0;
        $this->get('/_sandbox')->assertOk()->assertSee('5 products visible to a customer');
        $queriesForFive = $count;

        DB::flushQueryLog();

        fwrite(STDERR, "\n[query-count] sandbox list page: 24 products = {$queriesForTwentyFour} queries, 5 products = {$queriesForFive} queries\n");

        $this->assertSame(
            $queriesForTwentyFour,
            $queriesForFive,
            'the sandbox list page query count must not grow with the number of products on the page'
        );
    }

    /** A listed (active + visible) product with a real price, returned by id. */
    private function listedPricedProduct(int $index): string
    {
        $suffix = (string) ++self::$counter;

        $product = Product::createSimple("Sandbox Q {$index}", "SKU-SBQ-{$suffix}", "sbq-{$suffix}");
        $product->setCatalogVisibility(CatalogVisibility::VISIBLE);
        $product->publish();
        app(ProductRepository::class)->save($product);

        app(ProductTimelinePromoter::class)->promote(
            (string) $product->id(),
            // Relative, not a fixed date: Product::promote() refuses any
            // instant before the product's own createdAt.
            (new DateTimeImmutable('+1 day'))->modify("+{$index} minutes")
        );

        $list = app(PriceListRepository::class)->findSystemListByName('Regular Prices');

        app(PriceListItemRepository::class)->save(new PriceListItem(
            id: null,
            priceListId: $list->id(),
            targetType: PriceListItemTargetType::VARIATION,
            targetId: (string) $product->universalVariation()->priceableId(),
            price: Price::inclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 0),
        ));

        return (string) $product->id();
    }
}
