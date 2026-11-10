<?php

namespace Tests\Feature;

use App\Services\CatalogScopeResolver;
use App\Services\PriceListWriter;
use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceRangeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * P6b, the resolver: (a) the scope of a campaign matches like the storefront filters — AND between the KINDS, OR inside one kind — with the new SEASON kind; (b) a campaign below «Manual Sale» applies to a variation
 * WITHOUT a manual price, and with «replaces the manual sale price» ON gives the LOWER of the two. The examples of the owner: regular 100 €, Manual Sale 85 € → −30% on = 70 €, −10% on = 85 €, −30% off = 85 €.
 */
class PriceListCampaignResolutionTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private static int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();
    }

    /** @param array<string, mixed> $over */
    private function campaign(array $over = []): string
    {
        $list = app(PriceListWriter::class)->create($over + [
            'name' => 'Campaign '.(++self::$n),
            'percentage' => '30',
            'is_active' => true,
            'replaces_manual_sale' => false,
            'confirm_all_products' => true,
            'scopes' => [],
        ]);

        return (string) $list->id();
    }

    /** The final price of a variation in minor units, resolved the way the card resolves it (the scope resolver + the range resolver); null = unpriced. */
    private function finalMinor(int $variationId): ?int
    {
        $data = app(CatalogScopeResolver::class)->forVariations([(string) $variationId])[(string) $variationId];
        $quotes = app(PriceRangeResolver::class)->resolveQuotes([new PriceContext(priceableId: (string) $variationId, quantity: 1, currency: 'EUR', productId: $data['productId'], matchingScopeReferenceIds: $data['matchingScopeReferenceIds'])]);

        return isset($quotes[(string) $variationId]) ? $quotes[(string) $variationId]->final->gross()->minorValue() : null;
    }

    // ---- the Manual Sale switch --------------------------------------------------------------------------------------

    /** ★ the owner's three examples: regular 100, Manual Sale 85 */
    public function test_the_three_examples_of_the_replace_switch(): void
    {
        $this->makeProduct(1, ['slug' => 'manual', 'price' => '100.00', 'sale' => '85.00', 'stock' => 1]);

        $on30 = $this->campaign(['percentage' => '30', 'replaces_manual_sale' => true]);
        $this->assertSame(7000, $this->finalMinor(101), '−30% ON: the campaign is lower than 85 → 70');

        app(PriceListWriter::class)->update($on30, ['name' => 'c', 'percentage' => '10', 'replaces_manual_sale' => true, 'is_active' => true, 'confirm_all_products' => true, 'scopes' => []]);
        $this->assertSame(8500, $this->finalMinor(101), '−10% ON: the campaign (90) is higher than 85 → the Manual Sale price stays: it can never raise a price');

        app(PriceListWriter::class)->update($on30, ['name' => 'c', 'percentage' => '30', 'replaces_manual_sale' => false, 'is_active' => true, 'confirm_all_products' => true, 'scopes' => []]);
        $this->assertSame(8500, $this->finalMinor(101), '−30% OFF: the Manual Sale price is kept');
    }

    /** ★ a variation WITHOUT a Manual Sale price gets the campaign price, whatever the switch says */
    public function test_a_variation_without_a_manual_price_gets_the_campaign_price(): void
    {
        $this->makeProduct(1, ['slug' => 'plain', 'price' => '100.00', 'stock' => 1]);

        $id = $this->campaign(['percentage' => '30', 'replaces_manual_sale' => false]);
        $this->assertSame(7000, $this->finalMinor(101), 'OFF');

        app(PriceListWriter::class)->update($id, ['name' => 'c', 'percentage' => '30', 'replaces_manual_sale' => true, 'is_active' => true, 'confirm_all_products' => true, 'scopes' => []]);
        $this->assertSame(7000, $this->finalMinor(101), 'ON');
    }

    /** ★ an inactive, a not-yet-started and an expired campaign change nothing */
    public function test_an_inactive_planned_or_expired_campaign_changes_nothing(): void
    {
        $this->catalog();
        $this->makeProduct(6, ['slug' => 'manual', 'price' => '100.00', 'sale' => '85.00', 'stock' => 1, 'brand' => 1, 'tags' => [1]]);
        DB::table('catalog_products')->where('id', 6)->update(['season_id' => 1]);

        // three campaigns with three different scopes (two with an identical scope at one priority cannot exist), each one out of play
        $this->campaign(['is_active' => false, 'replaces_manual_sale' => true, 'scopes' => [['type' => 'brand', 'reference' => '1']], 'confirm_all_products' => false]);
        $this->campaign(['valid_from' => '2037-01-01 10:00', 'replaces_manual_sale' => true, 'percentage' => '31', 'scopes' => [['type' => 'tag', 'reference' => '1']], 'confirm_all_products' => false]);
        $this->campaign(['valid_from' => '2020-01-01 10:00', 'valid_until' => '2020-02-01 10:00', 'replaces_manual_sale' => true, 'percentage' => '32', 'scopes' => [['type' => 'season', 'reference' => '1']], 'confirm_all_products' => false]);

        $this->assertSame(10000, $this->finalMinor(101));
        $this->assertSame(8500, $this->finalMinor(601), 'the Manual Sale price is untouched');
    }

    /** two campaigns that match the same variation: ONE wins, the newest of equal priority (deterministic) */
    public function test_two_matching_campaigns_the_newest_wins(): void
    {
        $this->makeProduct(1, ['slug' => 'plain', 'price' => '100.00', 'stock' => 1]);
        $this->makeBrand(1, 'Acme', 'acme');
        DB::table('catalog_products')->where('id', 1)->update(['brand_id' => 1]);

        $this->campaign(['percentage' => '10']);                                                    // everything, older
        $this->campaign(['percentage' => '20', 'scopes' => [['type' => 'brand', 'reference' => '1']]]); // newer, brand

        $this->assertSame(8000, $this->finalMinor(101), 'the newest list (the brand one) wins outright: no stacking');
    }

    // ---- the scopes: AND between kinds, OR inside a kind, the season ---------------------------------------------------

    private function catalog(): void
    {
        $this->makeBrand(1, 'Acme', 'acme');
        $this->makeBrand(2, 'Zed', 'zed');
        $this->makeTag(1, 'A', 'a');
        $this->makeTag(2, 'B', 'b');
        DB::table('catalog_seasons')->insert([['id' => 1, 'name' => 'Autumn-26', 'slug' => 'autumn-26', 'created_at' => self::STAMP, 'updated_at' => self::STAMP], ['id' => 2, 'name' => 'Spring-26', 'slug' => 'spring-26', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]]);

        // 1: Acme + autumn + tag A | 2: Acme + spring + tag B | 3: Zed + autumn + tag A | 4: Zed + spring, no tag | 5: no brand, no season
        foreach ([1 => [1, 1, [1]], 2 => [1, 2, [2]], 3 => [2, 1, [1]], 4 => [2, 2, []], 5 => [null, null, []]] as $id => [$brand, $season, $tags]) {
            $this->makeProduct($id, ['slug' => 'p'.$id, 'price' => '100.00', 'stock' => 1, 'brand' => $brand, 'tags' => $tags]);
            DB::table('catalog_products')->where('id', $id)->update(['season_id' => $season]);
        }
    }

    /** @param list<array{type: string, reference: string}> $scopes @return list<int> the ids of the products the campaign discounts */
    private function discounted(array $scopes): array
    {
        $id = $this->campaign(['scopes' => $scopes, 'confirm_all_products' => false]);
        $hit = [];

        foreach ([1, 2, 3, 4, 5] as $product) {
            $this->finalMinor($product * 100 + 1) === 7000 && $hit[] = $product;
        }

        app(PriceListWriter::class)->setActive($id, false);

        return $hit;
    }

    /** ★ «brand X AND season S» matches only the products that have both */
    public function test_brand_and_season_need_both(): void
    {
        $this->catalog();

        $this->assertSame([1], $this->discounted([['type' => 'brand', 'reference' => '1'], ['type' => 'season', 'reference' => '1']]));
    }

    /** ★ «tags A, B AND brand X» is (A or B) and X */
    public function test_two_tags_and_a_brand_is_a_or_b_and_x(): void
    {
        $this->catalog();

        $this->assertSame([1, 2], $this->discounted([['type' => 'tag', 'reference' => '1'], ['type' => 'tag', 'reference' => '2'], ['type' => 'brand', 'reference' => '1']]));
    }

    /** ★ two brands alone match EITHER brand (they used to match nothing: every row was AND-ed) */
    public function test_two_brands_alone_match_either_brand(): void
    {
        $this->catalog();

        $this->assertSame([1, 2, 3, 4], $this->discounted([['type' => 'brand', 'reference' => '1'], ['type' => 'brand', 'reference' => '2']]));
    }

    /** ★ the SEASON kind matches by the product's own season */
    public function test_the_season_kind_matches_by_the_products_season(): void
    {
        $this->catalog();

        $this->assertSame([1, 3], $this->discounted([['type' => 'season', 'reference' => '1']]));
        $this->assertSame([2, 4], $this->discounted([['type' => 'season', 'reference' => '2']]));
    }

    /** a list with ONE row per kind behaves exactly as when every row was AND-ed */
    public function test_one_row_per_kind_is_the_old_and(): void
    {
        $this->catalog();

        $this->assertSame([3], $this->discounted([['type' => 'brand', 'reference' => '2'], ['type' => 'tag', 'reference' => '1']]));
    }

    /** the season costs no query of its own: the scope resolver reads it with the products read it already makes */
    public function test_the_season_adds_no_query_to_the_scope_resolver(): void
    {
        $this->catalog();
        app(CatalogScopeResolver::class)->forVariations(['101']); // warm-up

        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $data = app(CatalogScopeResolver::class)->forVariations(['101', '201', '301']);
        $three = $n;

        $n = 0;
        app(CatalogScopeResolver::class)->forVariations(['101']);
        $one = $n;

        $this->assertSame(['1'], $data['101']['matchingScopeReferenceIds']['season']);
        $this->assertSame($one, $three, 'the same number of queries for one and for three variations');
    }
}
