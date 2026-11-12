<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Below\BelowSlots;
use App\Storefront\Below\BelowStore;
use App\Storefront\ProductSummary\ProductSummaryCompiler;
use App\Storefront\ProductSummary\ProductSummaryLayout;
use App\Storefront\Sections\SectionSet;
use App\Storefront\Sections\SectionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * P6e-3 Part C: the «Мета информация» section lists the product's collections as links to `/collection/{slug}` — a third member of the SAME union (no extra query), and ONLY while the collections are ON
 * (the member carries the switch inside the statement). The product page read model gets no new field (the golden stays).
 */
class CollectionMetaSectionTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const META = '55555555-5555-4555-8555-555555555555';

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop']);
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeCategory(1, 'Dresses', 'dresses');
        $this->makeTag(1, 'Hot', 'hot');
        DB::table('catalog_seasons')->insert(['id' => 1, 'name' => 'Summer', 'slug' => 'summer', 'created_at' => now(), 'updated_at' => now()]);

        foreach ([1 => 'Wedding', 2 => '<b>Evil</b> & co'] as $id => $name) {
            DB::table('catalog_collections')->insert(['id' => $id, 'name' => $name, 'slug' => $id === 1 ? 'wedding' : 'evil', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->makeProduct(1, ['name' => 'P1', 'slug' => 'p1', 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'tags' => [1]]);
        DB::table('catalog_products')->where('id', 1)->update(['season_id' => 1]);
        $this->makeProduct(2, ['name' => 'P2', 'slug' => 'p2', 'categories' => [1], 'price' => '10.00', 'stock' => 3]);
        DB::table('catalog_product_collections')->insert([['product_id' => 1, 'collection_id' => 1], ['product_id' => 1, 'collection_id' => 2]]);

        $layout = ProductSummaryLayout::default();
        $layout['components'][] = ['type' => 'accordion', 'settings' => ['first_open' => false]];
        app(ProductSummaryCompiler::class)->publish($layout, app(SiteSettingsRepository::class));
        $this->publish([['key' => self::META, 'title' => 'Meta', 'source' => 'meta']]);
    }

    /** @param list<array<string, mixed>> $sections */
    private function publish(array $sections): void
    {
        [$clean, $errors] = SectionSet::validate(['sections' => $sections]);
        $this->assertSame([], $errors, json_encode($errors));
        SectionStore::publish($clean);
        $this->app->forgetScopedInstances();
    }

    private function switchTo(bool $on): void
    {
        $on ? app(SiteSettingsRepository::class)->set('catalog.collections_enabled', '1') : app(SiteSettingsRepository::class)->forget('catalog.collections_enabled');
        $this->app->forgetScopedInstances();
    }

    private function visit(string $slug): TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->get('/product/'.$slug);
    }

    private function queryCount(string $slug): int
    {
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $this->visit($slug)->assertOk();

        return $n;
    }

    public function test_the_collections_print_as_escaped_links_only_while_on(): void
    {
        $this->switchTo(true);
        $html = $this->visit('p1')->assertOk()->getContent();

        $this->assertStringContainsString('<span class="sf-psec-meta-label">Collections:</span> <a href="/collection/evil">&lt;b&gt;Evil&lt;/b&gt; &amp; co</a>, <a href="/collection/wedding">Wedding</a></p>', $html);
        $this->assertStringContainsString('<span class="sf-psec-meta-label">Season:</span> <a href="/season/summer">Summer</a>', $html, 'the season and the tags are unchanged');
        $this->assertStringContainsString('<a href="/product-tag/hot">Hot</a>', $html);
        $this->assertStringNotContainsString('<b>Evil</b>', $html);

        // (the links are ordered by name, like the tags) a product with no collection: no line
        $this->assertStringNotContainsString('Collections:', $this->visit('p2')->getContent());

        // OFF: nothing about collections, the rest stays
        $this->switchTo(false);
        $html = $this->visit('p1')->getContent();
        $this->assertStringNotContainsString('Collections:', $html);
        $this->assertStringNotContainsString('/collection/', $html);
        $this->assertStringContainsString('<a href="/season/summer">Summer</a>', $html);

        // ON again restores it
        $this->switchTo(true);
        $this->assertStringContainsString('/collection/wedding', $this->visit('p1')->getContent());
    }

    public function test_the_collections_line_can_be_switched_off_in_the_section_and_the_old_sets_are_unchanged(): void
    {
        $this->switchTo(true);
        $this->publish([['key' => self::META, 'title' => 'Meta', 'source' => 'meta', 'meta' => ['seasons' => true, 'tags' => true, 'collections' => false]]]);
        $html = $this->visit('p1')->getContent();
        $this->assertStringNotContainsString('Collections:', $html);
        $this->assertStringContainsString('Season:', $html);

        // a set stored before P6e-3 (no `collections`) validates to the very same shape
        [$clean] = SectionSet::validate(['sections' => [['key' => self::META, 'title' => 'M', 'source' => 'meta', 'meta' => ['seasons' => true, 'tags' => false]]]]);
        $this->assertSame(['seasons' => true, 'tags' => false], $clean['sections'][0]['meta']);

        [, $errors] = SectionSet::validate(['sections' => [['key' => self::META, 'title' => 'M', 'source' => 'meta', 'meta' => ['collections' => 'yes']]]]);
        $this->assertNotSame([], $errors);
    }

    /** ★ no extra query: the union has a third member, and the switch is not a query of its own */
    public function test_the_collections_cost_no_extra_query_and_the_switch_none(): void
    {
        $this->switchTo(false);
        $this->visit('p1');
        $off = $this->queryCount('p1');

        $this->switchTo(true);
        $this->visit('p1');
        $on = $this->queryCount('p1');

        fwrite(STDERR, "\n[query-count] P6e-3 meta: collections OFF {$off}, ON {$on}\n");
        $this->assertSame($off, $on, 'ON = OFF: the collections ride inside the existing meta union');
    }

    public function test_tabs_carry_the_line_too(): void
    {
        $this->switchTo(true);
        [$clean, $errors] = BelowSlots::validate(['slots' => [['type' => 'tabs', 'heading' => '', 'sections' => [self::META]]]]);
        $this->assertSame([], $errors, json_encode($errors));
        BelowStore::publish($clean);
        $this->app->forgetScopedInstances();

        $this->assertStringContainsString('/collection/wedding', $this->visit('p1')->getContent());
    }
}
