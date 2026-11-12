<?php

namespace Tests\Feature\Storefront;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Filament\Design\Zones\ContentZone;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Content\ContentCompiler;
use App\Storefront\Content\ContentSet;
use App\Storefront\Content\ContentStore;
use App\Storefront\Content\ContentTemplate;
use App\Storefront\Filters\FilterCatalog;
use App\Storefront\Filters\FilterConstraint;
use App\Storefront\ReadModels\CatalogSubjectKind;
use App\Storefront\ReadModels\ListingQuery;
use App\Storefront\ReadModels\ListingScope;
use App\Storefront\Sidebar\SidebarFilters;
use App\Storefront\Sidebar\SidebarStore;
use App\Storefront\Url\StorefrontUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * P6e-2: «Колекции» on the storefront — the registry of subject kinds (same SQL as the hand-written arms it replaced), the page `/collection/{slug}`, the filter, the sidebar and the «Съдържание» kind, all of
 * them present ONLY while `catalog.collections_enabled` is the literal '1'.
 *
 * The world: «Clothing» (1) with «Dresses» (2); brands Acme (1), Bolt (2); season Autumn (1); collections Wedding (1) and Summer (2) and Empty (3, no product).
 * P1 Acme / Dresses / Wedding. P2 Bolt / Clothing / Wedding + Summer. P3 Acme / Clothing / Summer. P4 Bolt / Clothing / no collection. P5 Acme / Clothing / Wedding but SOLD OUT. P6 Acme / Clothing / Wedding but HIDDEN.
 */
class CollectionStorefrontTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');

        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop']);
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeCategory(2, 'Dresses', 'dresses', 1);

        foreach ([1 => 'Acme', 2 => 'Bolt'] as $id => $name) {
            $this->makeBrand($id, $name, strtolower($name));
        }

        DB::table('catalog_seasons')->insert(['id' => 1, 'name' => 'Autumn', 'slug' => 'autumn', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);

        foreach ([1 => 'Wedding', 2 => 'Summer', 3 => 'Empty'] as $id => $name) {
            DB::table('catalog_collections')->insert(['id' => $id, 'name' => $name, 'slug' => strtolower($name), 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
        }

        $world = [
            1 => ['brand' => 1, 'categories' => [2], 'collections' => [1], 'stock' => 3],
            2 => ['brand' => 2, 'categories' => [1], 'collections' => [1, 2], 'stock' => 3],
            3 => ['brand' => 1, 'categories' => [1], 'collections' => [2], 'stock' => 3],
            4 => ['brand' => 2, 'categories' => [1], 'collections' => [], 'stock' => 3],
            5 => ['brand' => 1, 'categories' => [1], 'collections' => [1], 'stock' => 0],
            6 => ['brand' => 1, 'categories' => [1], 'collections' => [1], 'stock' => 3, 'visibility' => 'hidden'],
        ];

        foreach ($world as $id => $o) {
            $this->makeProduct($id, ['name' => "P{$id}", 'slug' => "p{$id}", 'price' => '10.00', 'timeline' => sprintf('2026-01-%02d 10:00:00', $id)] + array_diff_key($o, ['collections' => 1]));

            foreach ($o['collections'] as $collection) {
                DB::table('catalog_product_collections')->insert(['product_id' => $id, 'collection_id' => $collection]);
            }
        }

        DB::table('catalog_products')->whereIn('id', [1, 3])->update(['season_id' => 1]);
        FilterCatalog::forget();
    }

    private function visit(string $url): TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->get($url);
    }

    private function switchCollections(?string $value): void
    {
        $settings = app(SiteSettingsRepository::class);
        $value === null ? $settings->forget('catalog.collections_enabled') : $settings->set('catalog.collections_enabled', $value);
        FilterCatalog::forget();
        $this->app->forgetScopedInstances();
    }

    private function on(): void
    {
        $this->switchCollections('1');
    }

    /** @return list<int> */
    private function ids(string $html): array
    {
        preg_match_all('/data-product-id="(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }

    private function queryCount(string $url, int $status = 200): int
    {
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $this->visit($url)->assertStatus($status);

        return $n;
    }

    /** the names of the filter controls (bar) or groups (form) the page prints @return list<string> */
    private function filterNames(string $html): array
    {
        preg_match_all('#<span class="sf-fgroup-name">(.*?)</span>#s', $html, $m);

        return array_values(array_unique(array_map('trim', $m[1])));
    }

    /** the merchant shows «Колекция» in «Сайдбар» (a filter that is hidden by default) */
    private function showCollectionFilter(bool $show = true): void
    {
        $known = app(FilterCatalog::class)->knownKeys();
        $rows = array_map(static fn (string $key): array => ['key' => $key, 'show' => $key === 'collection' ? $show : ! in_array($key, ['collection', 'featured'], true) || $key === 'collection'], $known);
        [$clean, $errors] = SidebarFilters::validate(['filters' => $rows, 'kinds' => array_fill_keys(SidebarFilters::kindKeys($known), 'list')], $known, (new FilterCatalog)->hiddenByDefault());
        $this->assertSame([], $errors);
        SidebarStore::publish($clean);
        FilterCatalog::forget();
        $this->app->forgetScopedInstances();
    }

    private function publishContent(array $kinds): void
    {
        $set = ContentSet::blank();

        foreach ($kinds as $kind => $entry) {
            $set[$kind] = ['default' => $entry['default'] ?? null, 'own' => $entry['own'] ?? []];
        }

        [$clean, $errors] = ContentSet::validate($set, ContentStore::existing());
        $this->assertSame([], $errors, json_encode($errors));
        ContentStore::publish($clean);
        $this->app->forgetScopedInstances();
    }

    /** @return array<string, mixed> */
    private function template(bool $cloud = false, ?HtmlBlockModel $top = null): array
    {
        return ['cloud' => $cloud, 'title' => true, 'top' => $top?->id, 'bottom' => null, 'bottom_heading' => ''];
    }

    // ================================================================== the registry: the same SQL as the arms it replaced

    /** @return array{0: string, 1: array} */
    private static function sql($builder): array
    {
        return [$builder->toSql(), $builder->getBindings()];
    }

    /** ★ the scope of a subject page: brand / season / tag produce the SQL text the hand-written arms produced; the collection is the tag's with its own pivot */
    public function test_the_registry_builds_the_same_scope_sql_as_the_arms_it_replaced(): void
    {
        $legacy = [
            'brand' => static fn ($q) => $q->where('catalog_products.brand_id', 7),
            'season' => static fn ($q) => $q->where('catalog_products.season_id', 7),
            'tag' => static fn ($q) => $q->whereIn('catalog_products.id', DB::table('catalog_product_tags')->where('tag_id', 7)->select('product_id')),
            'collection' => static fn ($q) => $q->whereIn('catalog_products.id', DB::table('catalog_product_collections')->where('collection_id', 7)->select('product_id')),
        ];

        foreach (CatalogSubjectKind::cases() as $kind) {
            $old = DB::table('catalog_products');
            $legacy[$kind->value]($old);
            $new = DB::table('catalog_products');
            $kind->constrainScope($new, 7);

            $this->assertSame(self::sql($old), self::sql($new), $kind->value.': scope');
        }
    }

    /** ★ the visitor's filter: whereIn on the column, whereExists on the pivot (alias pt), exactly as FilterConstraint::builtin wrote them */
    public function test_the_registry_builds_the_same_filter_sql(): void
    {
        $legacy = [
            'brand' => static fn ($q) => $q->whereIn('p.brand_id', [1, 2]),
            'season' => static fn ($q) => $q->whereIn('p.season_id', [1, 2]),
            'tag' => static fn ($q) => $q->whereExists(DB::table('catalog_product_tags as pt')->selectRaw('1')->whereColumn('pt.product_id', 'p.id')->whereIn('pt.tag_id', [1, 2])),
            'collection' => static fn ($q) => $q->whereExists(DB::table('catalog_product_collections as pt')->selectRaw('1')->whereColumn('pt.product_id', 'p.id')->whereIn('pt.collection_id', [1, 2])),
        ];

        foreach (CatalogSubjectKind::cases() as $kind) {
            $old = DB::table('catalog_products as p');
            $legacy[$kind->value]($old);
            $new = DB::table('catalog_products as p');
            FilterConstraint::builtin($new, [$kind->value => [1, 2]], 'p');

            $this->assertSame(self::sql($old), self::sql($new), $kind->value.': filter');
        }
    }

    /** ★ the facet ids, the subject lookup and the «shop has any» scalar */
    public function test_the_registry_builds_the_same_facet_lookup_and_scalar_sql(): void
    {
        $facetLegacy = [
            'brand' => static fn ($q) => $q->whereNotNull('catalog_products.brand_id')->distinct()->pluck('catalog_products.brand_id'),
            'season' => static fn ($q) => $q->whereNotNull('catalog_products.season_id')->distinct()->pluck('catalog_products.season_id'),
            'tag' => static fn ($q) => $q->join('catalog_product_tags as pt', 'pt.product_id', '=', 'catalog_products.id')->distinct()->pluck('pt.tag_id'),
            'collection' => static fn ($q) => $q->join('catalog_product_collections as pt', 'pt.product_id', '=', 'catalog_products.id')->distinct()->pluck('pt.collection_id'),
        ];

        foreach (CatalogSubjectKind::cases() as $kind) {
            $old = DB::pretend(fn () => $facetLegacy[$kind->value](DB::table('catalog_products')));
            $new = DB::pretend(fn () => $kind->facetIds(DB::table('catalog_products')));
            $this->assertSame($old, $new, $kind->value.': facet');

            $table = ['brand' => 'catalog_brands', 'season' => 'catalog_seasons', 'tag' => 'catalog_tags', 'collection' => 'catalog_collections'][$kind->value];
            $this->assertSame($table, $kind->table());

            // the subject lookup: the ungated kinds are the old statement to the letter; the collection adds its switch inside the same statement
            $lookup = DB::table($table)->where('slug', 'x');
            $gated = $kind->gated(DB::table($table)->where('slug', 'x'));

            if ($kind === CatalogSubjectKind::COLLECTION) {
                $this->assertStringStartsWith($lookup->toSql().' and exists (select 1 from `site_settings` where `key` = ? and `value` = ?)', $gated->toSql());
                $this->assertSame(['x', 'catalog.collections_enabled', '1'], $gated->getBindings());
            } else {
                $this->assertSame(self::sql($lookup), self::sql($gated), $kind->value.': lookup');
            }

            $scalar = DB::table($table)->selectRaw('1')->limit(1);
            $kind === CatalogSubjectKind::COLLECTION || $this->assertSame(self::sql($scalar), self::sql($kind->hasAnySubselect()), $kind->value.': has any');
        }
    }

    /** ★ the lists that used to be literal arrays are the registry's now, with the old values in the old order */
    public function test_the_lists_of_the_old_arrays_are_the_registrys(): void
    {
        $this->assertSame(['brand', 'season', 'tag', 'collection'], FilterConstraint::BUILTIN);
        $this->assertSame(['brand', 'season', 'tag', 'collection'], SidebarFilters::BUILTIN);
        $this->assertSame(['categories', 'brands', 'seasons', 'tags', 'collections'], array_keys(ContentTemplate::KINDS));
        $this->assertSame([ListingScope::CATEGORY, ListingScope::BRAND, ListingScope::SEASON, ListingScope::TAG, ListingScope::COLLECTION], array_values(ContentTemplate::KINDS));

        $urls = new StorefrontUrls;
        $this->assertSame('/brand/a%20b', $urls->brand('a b'));
        $this->assertSame('/season/x', $urls->season('x'));
        $this->assertSame('/product-tag/x', $urls->tag('x'));
        $this->assertSame('/collection/x', $urls->collection('x'));

        foreach (['category', 'brand', 'tag', 'season', 'collection'] as $key) {
            $this->assertSame($key, ListingQuery::create(ListingScope::from($key), 5)->scope->value);
        }

        $this->assertSame(['filterBrand', 'filterSeason', 'filterTag', 'filterCollection'], CatalogSubjectKind::CATALOG_FIELDS);
        $this->assertSame('seasons', CatalogSubjectKind::SEASON->contentKind());
        $this->assertSame('storefront.crumb_collection', CatalogSubjectKind::COLLECTION->crumbKey());
    }

    // ================================================================== the page

    /** ★ ON: the page lists exactly the products of the collection (the pivot), newest first; a product in two collections is on both pages; hidden and sold-out products follow the catalog rules */
    public function test_the_page_lists_exactly_the_products_of_the_collection(): void
    {
        $this->on();

        $wedding = $this->visit('/collection/wedding')->assertOk()->getContent();
        $this->assertSame([2, 1], $this->ids($wedding), 'P5 is sold out (hidden by the P6c default), P6 is hidden from the catalog');
        $this->assertSame([3, 2], $this->ids($this->visit('/collection/summer')->assertOk()->getContent()), 'P2 is in both collections');
        $this->assertSame([], $this->ids($this->visit('/collection/empty')->assertOk()->getContent()));

        $this->assertStringContainsString('<h1', $wedding);
        $this->assertStringContainsString('Wedding', $wedding);
        $this->assertStringContainsString('href="/collection/wedding"', $wedding, 'the crumb leads to the page itself');
        $this->assertStringContainsString('Collections', $wedding, 'the crumb\'s first (plain) step');
    }

    /** ★ the sold-out setting of P6c applies to a collection page like to every list */
    public function test_sold_out_products_follow_the_p6c_setting(): void
    {
        $this->on();
        $this->assertNotContains(5, $this->ids($this->visit('/collection/wedding')->getContent()));

        $this->showSoldOutProducts();
        $this->app->forgetScopedInstances();
        $this->assertContains(5, $this->ids($this->visit('/collection/wedding')->getContent()));
    }

    /** ★ sort and pagination work on the collection page, the canonical redirect (another case) is a 301 */
    public function test_sort_pagination_and_the_canonical_slug(): void
    {
        $this->on();

        $this->assertSame([2, 1], $this->ids($this->visit('/collection/wedding?sort=price_asc')->assertOk()->getContent()));
        $paged = $this->visit('/collection/wedding?per_page=12&page=2');
        $paged->assertNotFound();
        $this->visit('/collection/WEDDING')->assertStatus(301)->assertRedirect('/collection/wedding');
        $this->visit('/collection/we dding')->assertNotFound();
        $this->visit('/collection/nope')->assertNotFound();
    }

    /** ★ OFF (the default, and after switching off): the page is the storefront's 404; ON again brings it back */
    public function test_off_is_a_404_and_on_again_restores_the_page(): void
    {
        $this->visit('/collection/wedding')->assertNotFound();

        $this->switchCollections('0');
        $this->visit('/collection/wedding')->assertNotFound();

        $this->on();
        $this->visit('/collection/wedding')->assertOk();

        $this->switchCollections(null);
        $this->visit('/collection/wedding')->assertNotFound();

        $this->switchCollections('yes');
        $this->visit('/collection/wedding')->assertNotFound('only the literal «1» is on');

        $this->on();
        $this->assertSame([2, 1], $this->ids($this->visit('/collection/wedding')->getContent()), 'the data stayed');
    }

    /** ★ the other subject pages do not care about the collections switch */
    public function test_the_other_subject_pages_work_with_the_switch_off(): void
    {
        foreach (['/brand/acme', '/season/autumn'] as $url) {
            $this->visit($url)->assertOk();
        }
    }

    // ================================================================== the cloud

    /** ★ the cloud of a collection page: the categories that hold VISIBLE products of it (a hidden product brings no category in) */
    public function test_the_cloud_lists_the_categories_of_the_collections_visible_products(): void
    {
        $this->on();
        $this->publishContent(['collections' => ['default' => $this->template(true)]]);

        $html = $this->visit('/collection/wedding')->assertOk()->getContent();
        preg_match('#<nav class="sf-subcategories sf-cloud [^"]*"[^>]*>(.*?)</nav>#s', $html, $nav);
        preg_match_all('#<a (?:class="sf-bv-chip" )?href="([^"]*)">(.*?)</a>#s', $nav[1] ?? '', $links);

        $this->assertSame(['Clothing', 'Dresses'], $links[2], 'P2 (Clothing), P1 (Dresses); P5 sold out and P6 hidden bring nothing; most products first');
        $this->assertSame(['/product-category/clothing', '/product-category/clothing/dresses'], $links[1]);
    }

    // ================================================================== the filter

    /** ★ the filter: OR inside, AND with the other filters, only offered while ON, 404 in the URL while OFF */
    public function test_the_filter_has_the_tag_semantics_and_exists_only_while_on(): void
    {
        $this->on();
        $this->showCollectionFilter();

        $category = '/product-category/clothing';
        $this->assertSame([3, 2, 1], $this->ids($this->visit($category.'?filter[collection]=2,1')->assertOk()->getContent()), 'OR inside: Wedding or Summer');
        $this->assertSame([2, 1], $this->ids($this->visit($category.'?filter[collection]=1')->assertOk()->getContent()));
        $this->assertSame([2], $this->ids($this->visit($category.'?filter[brand]=2&filter[collection]=1')->assertOk()->getContent()), 'AND with the brand');
        $this->assertSame([3], $this->ids($this->visit($category.'?filter[season]=1&filter[collection]=2')->assertOk()->getContent()), 'AND with the season');
        $this->assertContains('Collection', $this->filterNames($this->visit($category)->getContent()));

        // a collection with no listed product is offered no more (the facet), a filter value that does not exist is a 404
        $html = $this->visit($category)->getContent();
        $this->assertStringContainsString('filter[collection]=1', html_entity_decode($html));
        $this->assertStringNotContainsString('filter[collection]=3', html_entity_decode($html), 'Empty has no product');
        $this->visit($category.'?filter[collection]=99')->assertNotFound();

        $this->switchCollections('0');
        $this->visit($category.'?filter[collection]=1')->assertNotFound();
        $this->assertNotContains('Collection', $this->filterNames($this->visit($category)->assertOk()->getContent()));

        $this->on();
        $this->assertContains('Collection', $this->filterNames($this->visit($category)->getContent()), 'ON again: the stored choice is back');
    }

    /** ★ a collection page has no collection filter of its own (like a brand page has no brand filter); `filter[collection]` in its URL is ignored */
    public function test_the_collection_page_does_not_offer_its_own_filter(): void
    {
        $this->on();
        $this->showCollectionFilter();

        $html = $this->visit('/collection/wedding')->assertOk()->getContent();
        $this->assertNotContains('Collection', $this->filterNames($html));
        $this->assertSame([2, 1], $this->ids($this->visit('/collection/wedding?filter[collection]=2')->assertOk()->getContent()));
    }

    /** ★ the facet of the collection filter is read from the LISTED products only */
    public function test_facet_ids_are_those_of_listed_products(): void
    {
        $this->on();
        $reader = app(\App\Storefront\Reader\CatalogReader::class);
        $query = ListingQuery::create(ListingScope::CATEGORY, 1);

        $this->assertEqualsCanonicalizing([1, 2], $reader->filterValueIds($query, [], 'collection'), 'Empty has no product; P5 (sold out) and P6 (hidden) add nothing');
        $this->assertSame([1], $reader->filterValueIds(ListingQuery::create(ListingScope::CATEGORY, 2), [], 'collection'), 'the Dresses category: P1 only');
        $this->assertEqualsCanonicalizing([1, 2], $reader->filterValueIds($query, ['brand' => [1]], 'collection'), 'with Acme: P1 (Wedding) and P3 (Summer)');
    }

    /** ★ the filter list is cached WITH the collections only while ON, and the Catalog settings save forgets it */
    public function test_the_catalog_settings_save_forgets_the_filter_cache(): void
    {
        $this->assertNotContains('collection', (new FilterCatalog)->knownKeys());
        $this->assertNotNull(\Illuminate\Support\Facades\Cache::get(FilterCatalog::CACHE_KEY), 'cached while off');
        $this->assertSame([], \Illuminate\Support\Facades\Cache::get(FilterCatalog::CACHE_KEY)['builtin']['collection'], 'the cached data holds no collection while off, whatever is stored');
        $this->assertFalse(\Illuminate\Support\Facades\Cache::get(FilterCatalog::CACHE_KEY)['collections_on']);

        app(SiteSettingsRepository::class)->set('catalog.collections_enabled', '1');
        $this->assertNotContains('collection', (new FilterCatalog)->knownKeys(), 'the stale cache is still served until something forgets it');

        $this->actingAsStaff('Administrator');
        \Livewire\Livewire::test(\App\Filament\Pages\Settings\CatalogSettings::class)->fillForm(['collections_enabled' => true])->call('save')->assertHasNoFormErrors();
        $this->assertContains('collection', (new FilterCatalog)->knownKeys(), 'forgotten on the save');

        \Livewire\Livewire::test(\App\Filament\Pages\Settings\CatalogSettings::class)->fillForm(['collections_enabled' => false])->call('save');
        $this->assertNotContains('collection', (new FilterCatalog)->knownKeys());
    }

    /** ★ creating, renaming and deleting a collection forgets the cache like the other kinds */
    public function test_a_collection_change_forgets_the_filter_cache(): void
    {
        $this->on();
        $this->assertContains('collection', (new FilterCatalog)->knownKeys());
        $this->assertNotNull(\Illuminate\Support\Facades\Cache::get(FilterCatalog::CACHE_KEY));

        \EasyCo\Catalog\Persistence\Eloquent\CollectionModel::query()->whereKey(3)->first()->update(['name' => 'Renamed']);
        $this->assertNull(\Illuminate\Support\Facades\Cache::get(FilterCatalog::CACHE_KEY));
    }

    // ================================================================== «Сайдбар»

    /** ★ the sidebar: «Колекция» is hidden until the merchant shows it; with the switch OFF a stored entry renders nothing and the editor does not know the key; ON again restores it */
    public function test_the_sidebar_entry_is_hidden_by_default_and_renders_nothing_while_off(): void
    {
        $category = '/product-category/clothing';

        // an existing shop: the merchant saved a sidebar list long before the collections existed
        $known = (new FilterCatalog)->knownKeys();
        [$clean] = SidebarFilters::validate(['filters' => array_map(static fn (string $k): array => ['key' => $k, 'show' => true], array_values(array_diff($known, ['featured']))), 'kinds' => array_fill_keys(SidebarFilters::kindKeys($known), 'list')], $known, (new FilterCatalog)->hiddenByDefault());
        SidebarStore::publish($clean);

        $this->on();
        $this->assertNotContains('Collection', $this->filterNames($this->visit($category)->getContent()), 'a shop that switches the collections on sees no new filter until it shows it');

        $this->showCollectionFilter();
        $this->assertContains('Collection', $this->filterNames($this->visit($category)->getContent()));

        $this->switchCollections('0');
        $this->assertNotContains('collection', array_column(SidebarStore::values()['filters'], 'key'), 'the editor does not offer it');
        $this->assertNotContains('Collection', $this->filterNames($this->visit($category)->getContent()));

        // the merchant publishes the sidebar while OFF: the stored entry is carried over
        $known = (new FilterCatalog)->knownKeys();
        [$clean] = SidebarFilters::validate(SidebarStore::startingValues(), $known, (new FilterCatalog)->hiddenByDefault());
        $this->assertArrayNotHasKey('collection', $clean['kinds'], 'no kind for a filter that is off');
        SidebarStore::publish($clean);

        $this->on();
        $this->assertContains('Collection', $this->filterNames($this->visit($category)->getContent()), 'ON again: the stored entry is back');
    }

    // ================================================================== «Съдържание»

    /** ★ the collection default template applies to collections only; the season default does not touch them; a page's own template wins for that page alone */
    public function test_the_collection_template_default_and_own(): void
    {
        $this->on();
        $general = HtmlBlockModel::query()->create(['title' => 'g', 'body' => '<p>GENERALTOP</p>']);
        $own = HtmlBlockModel::query()->create(['title' => 'o', 'body' => '<p>OWNTOP</p>']);
        $season = HtmlBlockModel::query()->create(['title' => 's', 'body' => '<p>SEASONTOP</p>']);

        $this->publishContent([
            'collections' => ['default' => $this->template(false, $general), 'own' => [2 => $this->template(false, $own)]],
            'seasons' => ['default' => $this->template(false, $season)],
        ]);

        $wedding = $this->visit('/collection/wedding')->assertOk()->getContent();
        $summer = $this->visit('/collection/summer')->assertOk()->getContent();

        $this->assertStringContainsString('GENERALTOP', $wedding);
        $this->assertStringNotContainsString('OWNTOP', $wedding);
        $this->assertStringNotContainsString('SEASONTOP', $wedding, 'the season default does not affect collections');
        $this->assertStringContainsString('OWNTOP', $summer);
        $this->assertStringNotContainsString('GENERALTOP', $summer, 'a template is whole, never mixed');
        $this->assertStringContainsString('SEASONTOP', $this->visit('/season/autumn')->getContent());
        $this->assertStringNotContainsString('GENERALTOP', $this->visit('/season/autumn')->getContent());
    }

    /** ★ the editor offers the «Колекции» kind only while ON; the stored templates survive a publish while OFF and come back */
    public function test_the_content_editor_offers_the_kind_only_while_on_and_keeps_the_data(): void
    {
        $this->assertNotContains('collections', ContentZone::offeredKinds());
        $this->on();
        $this->assertContains('collections', ContentZone::offeredKinds());

        $block = HtmlBlockModel::query()->create(['title' => 'g', 'body' => '<p>KEPTTOP</p>']);
        $this->publishContent(['collections' => ['default' => $this->template(false, $block), 'own' => [1 => $this->template(true)]]]);

        $this->switchCollections('0');
        $this->assertNotContains('collections', ContentZone::offeredKinds());

        // a publish while OFF (the editor hands back the whole set it loaded) does not drop what is stored
        $this->publishContent(['collections' => ContentStore::values()['collections']]);
        $this->visit('/collection/wedding')->assertNotFound();

        $this->on();
        $this->assertStringContainsString('KEPTTOP', $this->visit('/collection/summer')->assertOk()->getContent());
    }

    /** ★ the signed design preview of «Съдържание» carries the working copy for the collection kind too */
    public function test_the_preview_template_applies_to_a_collection_page(): void
    {
        $this->on();
        $block = HtmlBlockModel::query()->create(['title' => 'p', 'body' => '<p>PREVIEWTOP</p>']);
        $set = ContentSet::blank();
        $set['collections']['default'] = $this->template(false, $block);
        [$clean] = ContentSet::validate($set, ContentStore::existing());

        $appearance = app(StorefrontAppearance::class);
        $appearance->previewContent(ContentCompiler::compiled($clean));

        $this->assertNotNull($appearance->listingContent(ListingScope::COLLECTION, 1)['template']);
        $this->assertNull($appearance->listingContent(ListingScope::SEASON, 1)['template']);
    }

    // ================================================================== the cost

    /** ★ the collection page costs what a tag page costs (≤ 27, pinned); OFF costs no more than a missing season slug; the OFF switch is no query of its own */
    public function test_the_query_counts_are_pinned(): void
    {
        $this->on();
        $this->showCollectionFilter();
        $this->visit('/product-category/clothing')->assertOk();

        $tag = $this->queryCount('/brand/acme');
        $collection = $this->queryCount('/collection/wedding');
        fwrite(STDERR, "\n[query-count] collection page {$collection} (brand page {$tag}; pinned ceiling 27)\n");

        $this->assertLessThanOrEqual(27, $collection);
        $this->assertLessThanOrEqual($tag + 1, $collection);

        $this->switchCollections('0');
        $off = $this->queryCount('/collection/wedding', 404);
        $missing = $this->queryCount('/season/nope', 404);
        fwrite(STDERR, "[query-count] OFF 404: collection {$off}, missing season slug {$missing}\n");
        $this->assertLessThanOrEqual($missing, $off);

        $this->switchCollections(null);
        $this->assertLessThanOrEqual($missing, $this->queryCount('/collection/wedding', 404));
    }

    /** ★ the category listing costs the same with the switch ON and OFF (the switch rides inside the statements) */
    public function test_the_category_listing_costs_the_same_on_and_off(): void
    {
        $this->visit('/product-category/clothing')->assertOk(); // warm
        $off = $this->queryCount('/product-category/clothing');

        $this->on();
        $this->visit('/product-category/clothing')->assertOk(); // warm
        $on = $this->queryCount('/product-category/clothing');

        $this->assertSame($off, $on);
    }
}
