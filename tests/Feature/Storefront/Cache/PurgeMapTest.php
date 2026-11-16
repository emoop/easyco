<?php

namespace Tests\Feature\Storefront\Cache;

use App\Content\Models\PageModel;
use App\Services\CatalogDeletion;
use App\Services\MassEdit\ProductMassEditor;
use App\Services\PriceSort\ProductSortPriceRefresher;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Cache\CachePurgeCollector;
use App\Storefront\Cache\CachePurger;
use App\Storefront\Cache\RecordingPurger;
use App\Storefront\Filters\FilterCatalog;
use App\Storefront\Home\HomeDrafts;
use App\Storefront\Home\HomeValidator;
use App\Storefront\Menu\MenuRecompiler;
use App\Storefront\Sections\SectionTexts;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Catalog\Persistence\Eloquent\CategoryModel;
use EasyCo\Catalog\Persistence\Eloquent\CollectionModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductCategoryModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\SeasonModel;
use EasyCo\Catalog\Persistence\Eloquent\TagModel;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Extensibility\Hook;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Media\Persistence\Eloquent\ProductMediaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P8b: the purge map, one test per row of the table in storefront-cache-contract.md §3. The change is made, the collector is flushed by hand (a test is not a
 * request that terminates), and the RecordingPurger says exactly which keys were sent.
 */
class PurgeMapTest extends TestCase
{
    use BuildsCacheShop;
    use RefreshDatabase;

    private RecordingPurger $purger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop();
        $this->purger = new RecordingPurger;
        $this->app->instance(CachePurger::class, $this->purger);
        $this->collector()->reset();
    }

    private function collector(): CachePurgeCollector
    {
        return app(CachePurgeCollector::class);
    }

    /** @return list<string> the keys the change purged (sorted) */
    private function purged(callable $change): array
    {
        $this->collector()->reset();
        $this->purger->batches = [];
        $change();
        $this->collector()->flush();

        return $this->purger->keys();
    }

    /** @param  list<string>  $keys  @param  list<string>  $actual */
    private function assertHasKeys(array $keys, array $actual, string $why = ''): void
    {
        foreach ($keys as $key) {
            $this->assertContains($key, $actual, "{$why}: {$key} is missing from [".implode(' ', $actual).']');
        }
    }

    /** the product rule for product 1 (its subjects: dresses c2 under c1, brand 1, season 1, tag 1, collection 1) */
    private const PRODUCT_1 = ['p1', 'home', 'sitemap', 'c1', 'c2', 'b1', 's1', 't1', 'col1'];

    public function test_a_product_save_purges_the_product_rule(): void
    {
        $keys = $this->purged(fn () => ProductModel::query()->findOrFail(1)->update(['name' => 'Alpha 2']));

        $this->assertHasKeys(self::PRODUCT_1, $keys, 'product save');
    }

    /** ★ the OLD brand and season are purged too: a product that leaves a brand shifts that brand page */
    public function test_a_brand_and_season_change_purges_the_old_and_the_new_subject(): void
    {
        DB::table('catalog_brands')->insert(['id' => 2, 'name' => 'Zed', 'slug' => 'zed', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
        DB::table('catalog_seasons')->insert(['id' => 2, 'name' => 'Winter', 'slug' => 'winter', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);

        $keys = $this->purged(fn () => ProductModel::query()->findOrFail(1)->update(['brand_id' => 2, 'season_id' => 2]));

        $this->assertHasKeys(['b1', 'b2', 's1', 's2', 'p1'], $keys, 'brand / season change: old AND new');
    }

    /** ★ a product moved from one category to another purges both (the pivot rows give the old and the new) */
    public function test_a_category_move_purges_the_old_and_the_new_category(): void
    {
        $this->makeCategory(3, 'Shoes', 'shoes');

        $keys = $this->purged(function (): void {
            ProductCategoryModel::query()->where('product_id', 1)->where('category_id', 2)->firstOrFail()->delete();
            ProductCategoryModel::query()->create(['product_id' => 1, 'category_id' => 3]);
        });

        $this->assertHasKeys(['c2', 'c1', 'c3', 'p1', 'home', 'sitemap'], $keys, 'moved from dresses to shoes');
    }

    public function test_tag_and_collection_changes_purge_their_subject(): void
    {
        $keys = $this->purged(fn () => DB::table('catalog_product_tags')->where('product_id', 1)->get()->each(fn () => \EasyCo\Catalog\Persistence\Eloquent\ProductTagModel::query()->where('product_id', 1)->firstOrFail()->delete()));
        $this->assertHasKeys(['t1', 'p1'], $keys, 'tag removed');

        $keys = $this->purged(fn () => \EasyCo\Catalog\Persistence\Eloquent\ProductCollectionModel::query()->where('product_id', 1)->firstOrFail()->delete());
        $this->assertHasKeys(['col1', 'p1'], $keys, 'collection removed');
    }

    public function test_a_variation_save_and_a_price_row_purge_the_product_rule(): void
    {
        $keys = $this->purged(fn () => VariationModel::query()->findOrFail(101)->update(['sku' => 'NEW-SKU']));
        $this->assertHasKeys(self::PRODUCT_1, $keys, 'variation save');

        $keys = $this->purged(fn () => app(\App\Services\ProductPricingAndStock::class)->writeSalePrice('101', '5.00'));
        $this->assertHasKeys(self::PRODUCT_1, $keys, 'a price row');
    }

    public function test_a_stock_that_crosses_zero_purges_the_product_rule_and_one_that_does_not_only_the_product(): void
    {
        $stock = app(StockLevelRepository::class);

        $keys = $this->purged(fn () => $stock->decrease('101', 1)); // 5 -> 4
        $this->assertSame(['p1'], $keys, 'does not cross zero: only the product');

        $keys = $this->purged(fn () => $stock->decrease('101', 4)); // 4 -> 0
        $this->assertHasKeys(self::PRODUCT_1, $keys, 'crosses zero');
    }

    public function test_media_measurements_and_section_texts_purge_only_the_product(): void
    {
        $this->makeImage(1, 1);
        $keys = $this->purged(fn () => ProductMediaModel::query()->where('product_id', 1)->firstOrFail()->delete());
        $this->assertSame(['p1'], $keys, 'product media');

        $keys = $this->purged(fn () => Hook::fire('catalog.product.measurements.saved', '1'));
        $this->assertSame(['p1'], $keys, 'measurements');

        $keys = $this->purged(fn () => SectionTexts::save(1, [], []));
        $this->assertSame(['p1'], $keys, 'section texts');
    }

    public function test_a_category_brand_season_tag_and_collection_save_purge_their_own_key_and_all(): void
    {
        // their names are printed in the sidebar filters, the menus and the tiles of EVERY page: a save AND a delete purge `all`
        $keys = $this->purged(fn () => CategoryModel::query()->findOrFail(2)->update(['name' => 'Dresses 2']));
        $this->assertHasKeys(['c2', 'c1', 'sitemap', 'home', 'all'], $keys, 'category');

        $keys = $this->purged(fn () => BrandModel::query()->findOrFail(1)->update(['name' => 'Acme 2']));
        $this->assertHasKeys(['b1', 'idx-brand', 'sitemap', 'home', 'all'], $keys, 'brand');

        $keys = $this->purged(fn () => SeasonModel::query()->findOrFail(1)->update(['name' => 'Summer 2']));
        $this->assertHasKeys(['s1', 'sitemap', 'home', 'all'], $keys, 'season');

        $keys = $this->purged(fn () => TagModel::query()->findOrFail(1)->update(['name' => 'New 2']));
        $this->assertHasKeys(['t1', 'sitemap', 'home', 'all'], $keys, 'tag');

        $keys = $this->purged(fn () => CollectionModel::query()->findOrFail(1)->update(['name' => 'Col 2']));
        $this->assertHasKeys(['col1', 'idx-collection', 'sitemap', 'home', 'all'], $keys, 'collection');
    }

    /**
     * ★ P8b review: ONE assertion per subject kind, isolated from every other listener. FilterCatalog's own listener also collects `all` for a brand, season, tag and
     * collection, so the model's listeners are cleared and ONLY the purge map is registered again: `all` can then come from the purge listener of that kind alone.
     *
     * @return array<string, array{class-string, string}>
     */
    public static function subjectKinds(): array
    {
        return [
            'category' => [CategoryModel::class, 'c1'],
            'brand' => [BrandModel::class, 'b1'],
            'season' => [SeasonModel::class, 's1'],
            'tag' => [TagModel::class, 't1'],
            'collection' => [CollectionModel::class, 'col1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('subjectKinds')]
    public function test_every_subject_kind_purges_all_through_its_own_listener(string $model, string $own): void
    {
        foreach (['saved', 'deleted'] as $event) {
            \Illuminate\Support\Facades\Event::forget("eloquent.{$event}: {$model}");
        }

        \App\Storefront\Cache\CachePurgeListeners::register();

        $keys = $this->purged(fn () => $model::query()->firstOrFail()->update(['name' => 'Renamed']));

        $this->assertContains($own, $keys, 'the own key');
        $this->assertContains('all', $keys, 'all, from the purge listener of this kind: ['.implode(' ', $keys).']');
    }

    public function test_an_info_page_publish_and_delete_purge_the_page_the_sitemap_and_all(): void
    {
        $id = (int) DB::table('pages')->where('slug', 'about')->value('id');

        $keys = $this->purged(fn () => PageModel::query()->findOrFail($id)->update(['title' => 'About 2']));
        $this->assertHasKeys(['pg'.$id, 'sitemap', 'all'], $keys, 'page save');

        $keys = $this->purged(fn () => PageModel::query()->findOrFail($id)->delete());
        $this->assertHasKeys(['pg'.$id, 'sitemap', 'all'], $keys, 'page delete');
    }

    public function test_every_design_publish_menu_filter_and_setting_change_purges_all(): void
    {
        $keys = $this->purged(fn () => app(StorefrontAppearance::class)->refresh());
        $this->assertSame(['all'], $keys, 'an appearance-affecting publish');

        [$clean] = HomeValidator::validate(['spacing' => '32', 'sections' => []]);
        $keys = $this->purged(fn () => HomeDrafts::publish($clean));
        $this->assertHasKeys(['all'], $keys, 'the home layout publish');

        $keys = $this->purged(fn () => app(MenuRecompiler::class)->now());
        $this->assertHasKeys(['all'], $keys, 'a menu compile');

        $keys = $this->purged(fn () => FilterCatalog::forget());
        $this->assertSame(['all'], $keys, 'a filter catalog change');

        $keys = $this->purged(fn () => app(SiteSettingsRepository::class)->set('catalog.collections_enabled', '0'));
        $this->assertHasKeys(['all'], $keys, 'a storefront-visible setting (the collections switch)');

        // the WRITE paths of the free-shipping threshold collect (refresh() itself never does: a page view can reach it)
        $keys = $this->purged(fn () => Hook::fire('shipping.zone.reordered'));
        $this->assertHasKeys(['all'], $keys, 'the free-shipping threshold (a shipping hook)');
    }

    public function test_a_setting_a_visitor_cannot_see_purges_nothing(): void
    {
        $keys = $this->purged(function (): void {
            app(SiteSettingsRepository::class)->set('mail.from_name', 'Shop');
            app(SiteSettingsRepository::class)->set('header.layout.draft', '{}');
        });

        $this->assertSame([], $keys);
    }

    public function test_a_price_command_purges_the_products_whose_stored_price_changed(): void
    {
        // make the stored sort price stale (the fixture's own price writes refreshed it): the command writes it again and purges exactly those products
        DB::table('catalog_products')->update(['price_from_minor' => null]);

        $keys = $this->purged(fn () => Artisan::call('prices:refresh-from'));

        $this->assertHasKeys(['p1', 'p2', 'p3', 'home', 'sitemap'], $keys, 'prices:refresh-from');

        $keys = $this->purged(fn () => app(ProductSortPriceRefresher::class)->refresh(['1', '2', '3']));
        $this->assertSame([], $keys, 'nothing changed the second time: nothing is purged');
    }

    /** ★ the writes that bypass model events */
    public function test_a_variation_reorder_purges_the_product_rule(): void
    {
        $this->makeProduct(5, ['name' => 'Eps', 'slug' => 'eps', 'categories' => [1], 'variations' => [['stock' => 1, 'price' => '1.00'], ['stock' => 1, 'price' => '2.00']]]);

        $keys = $this->purged(fn () => app(VariationRepository::class)->updateSortOrders('5', ['502', '501']));

        $this->assertHasKeys(['p5', 'home', 'sitemap', 'c1'], $keys, 'reorder (a bulk update)');
    }

    public function test_the_measurement_template_repository_purges_the_product(): void
    {
        $keys = $this->purged(fn () => app(\EasyCo\Catalog\Contracts\ProductMeasurementTemplateRepository::class)->clear('1'));

        $this->assertSame(['p1'], $keys);
    }

    public function test_the_deletion_service_purges_the_product_rule(): void
    {
        DB::table('catalog_products')->where('id', 3)->update(['status' => 'archived']);
        DB::table('stock_levels')->where('variation_id', 301)->update(['quantity' => 0]);

        $keys = $this->purged(fn () => app(CatalogDeletion::class)->deleteProduct('3'));

        $this->assertHasKeys(['p3', 'home', 'sitemap'], $keys, 'a hard delete');
    }

    /** ★ the mass editor's bulk update fires no model event: the chunk collects the season it sets */
    public function test_the_mass_editor_purges_its_chunk_and_the_season_it_sets(): void
    {
        DB::table('catalog_seasons')->insert(['id' => 2, 'name' => 'Winter', 'slug' => 'winter', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);

        $keys = $this->purged(fn () => app(ProductMassEditor::class)->applyChunk(ProductMassEditor::SET_SEASON, '2', ['2']));

        $this->assertHasKeys(['p2', 's2', 'home', 'sitemap'], $keys, 'mass: set season');
    }
}
