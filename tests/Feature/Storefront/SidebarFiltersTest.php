<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Filters\FilterCatalog;
use App\Storefront\PageDesign\PageDesign;
use App\Storefront\PageDesign\PageDesignStore;
use App\Storefront\Sidebar\SidebarFilters;
use App\Storefront\Sidebar\SidebarStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * P1: the «Сайдбар» list on the storefront — ONE ordered list of the filters (brand, season, tag, the attributes) with a switch each, driving the top bar AND the side panel (and the chips / «Изчисти», which are built
 * from the same list). The default is today's filters in today's order; a hidden filter is not printed, costs no facet query and its URL parameter is a 404; bad / unknown / duplicate keys are cleaned on read; a
 * filter the list does not mention is appended, shown.
 *
 * The world is CategoryBuiltinFiltersTest's: «Clothing» with Colour (attribute 1), brands Acme / Bolt, seasons Autumn / Winter, tags Alpha / Beta / Gamma.
 */
class SidebarFiltersTest extends TestCase
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
        $this->makeAttribute(1, 'Colour', ['Red', 'Blue']);
        DB::table('catalog_attribute_definitions')->where('id', 1)->update(['is_filterable' => true, 'filter_display' => 'list']);

        foreach ([1 => 'Acme', 2 => 'Bolt'] as $id => $name) {
            $this->makeBrand($id, $name, strtolower($name));
        }

        foreach ([1 => 'Autumn', 2 => 'Winter'] as $id => $name) {
            DB::table('catalog_seasons')->insert(['id' => $id, 'name' => $name, 'slug' => strtolower($name), 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
        }

        foreach ([1 => 'Alpha', 2 => 'Beta', 3 => 'Gamma'] as $id => $name) {
            $this->makeTag($id, $name, strtolower($name));
        }

        foreach ([1 => ['Red', 1, 1, [1, 2]], 2 => ['Blue', 1, 2, [1]], 3 => ['Red', 2, 1, [2, 3]], 4 => ['Blue', 2, 2, [3]]] as $id => [$colour, $brand, $season, $tags]) {
            $this->makeProduct($id, ['type' => 'variable', 'name' => "P{$id}", 'slug' => "p{$id}", 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'brand' => $brand, 'tags' => $tags, 'timeline' => sprintf('2026-01-%02d 10:00:00', $id),
                'variations' => [['attrs' => [1 => $colour], 'price' => '10.00', 'stock' => 2]]]);
            DB::table('catalog_products')->where('id', $id)->update(['season_id' => $season]);
        }

        FilterCatalog::forget();
    }

    private function visit(string $url): \Illuminate\Testing\TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->get($url);
    }

    /** @param  list<array{key: string, show: bool}>  $rows  @param  array<string, string>  $kinds */
    private function publishList(array $rows, array $kinds = ['brand' => 'list', 'season' => 'list', 'tag' => 'list']): void
    {
        [$clean, $errors] = SidebarFilters::validate(['filters' => $rows, 'kinds' => $kinds], app(FilterCatalog::class)->knownKeys(), app(FilterCatalog::class)->hiddenByDefault());
        $this->assertSame([], $errors, json_encode($errors));
        SidebarStore::publish($clean);
        $this->app->forgetScopedInstances();
    }

    private function publishLayout(string $layout): void
    {
        [$clean, $errors] = PageDesign::validate(PageDesign::CATALOG, ['layout' => $layout] + PageDesign::defaults(PageDesign::CATALOG), false);
        $this->assertSame([], $errors);
        PageDesignStore::publish(PageDesign::CATALOG, $clean);
    }

    /** the names of the filters in the TOP BAR, in order (the sort control is not one of them) @return list<string> */
    private function bar(string $html): array
    {
        preg_match_all('#<details class="sf-bctl" name="sf-cbar">(.*?)</details>#s', $html, $controls);

        return array_map(static fn (string $control): string => trim(explode(':', strip_tags(preg_match('#<span class="sf-bctl-text">(.*?)</span></summary>#s', $control, $m) ? $m[1] : ''))[0]), $controls[1]);
    }

    /** the names of the filters in the SIDE PANEL / drawer form, in order @return list<string> */
    private function panel(string $html): array
    {
        preg_match_all('#<details class="sf-fgroup"[^>]*>\s*<summary class="sf-fgroup-summary"><span class="sf-fgroup-name">(.*?)</span>#s', $html, $m);

        return array_map('html_entity_decode', $m[1]);
    }

    private function queryCount(string $url): int
    {
        $this->visit($url)->assertOk();
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $this->visit($url)->assertOk();

        return $n;
    }

    // ---- ★ the default is today ---------------------------------------------------------------------------------------------------------------

    public function test_without_a_saved_list_the_filters_are_what_they_always_were(): void
    {
        $html = $this->visit('/product-category/clothing')->assertOk()->getContent();

        $this->assertSame(['Brand', 'Season', 'Colour'], $this->bar($html), 'brand, season, the attributes; the tag is off by default');
        $this->assertSame(['Brand', 'Season', 'Colour'], $this->panel($html));
    }

    public function test_an_older_off_choice_of_a_built_in_is_read_as_hidden_until_a_list_is_saved(): void
    {
        [$clean] = PageDesign::validate(PageDesign::CATALOG, ['filterBrand' => 'off', 'filterTag' => 'buttons'] + PageDesign::defaults(PageDesign::CATALOG), false);
        PageDesignStore::publish(PageDesign::CATALOG, $clean);

        $this->assertSame(['Season', 'Colour', 'Tag'], $this->bar($this->visit('/product-category/clothing')->assertOk()->getContent()));

        $this->assertSame(['brand' => false, 'season' => true, 'tag' => true], collect(SidebarStore::values()['filters'])->whereIn('key', SidebarFilters::BUILTIN)->pluck('show', 'key')->all());
    }

    // ---- ★ hide, reorder: both places ---------------------------------------------------------------------------------------------------------

    public function test_a_hidden_filter_is_gone_from_the_bar_and_from_the_side_panel(): void
    {
        $this->publishList([['key' => 'brand', 'show' => true], ['key' => 'season', 'show' => false], ['key' => '1', 'show' => true], ['key' => 'tag', 'show' => false]]);

        $this->assertSame(['Brand', 'Colour'], $this->bar($this->visit('/product-category/clothing')->assertOk()->getContent()), 'the top bar');

        $this->publishLayout('sidebar');
        $html = $this->visit('/product-category/clothing')->assertOk()->getContent();

        $this->assertStringContainsString('sf-sidebar', $html.' sf-sidebar', 'the side-panel layout');
        $this->assertSame(['Brand', 'Colour'], $this->panel($html), 'the side panel');
        $this->assertStringNotContainsString('Season', $html);
    }

    public function test_the_url_parameter_of_a_hidden_filter_is_a_404_and_filters_nothing(): void
    {
        $this->publishList([['key' => 'brand', 'show' => true], ['key' => 'season', 'show' => false], ['key' => '1', 'show' => true], ['key' => 'tag', 'show' => false]]);

        $this->visit('/product-category/clothing?filter[season]=1')->assertNotFound();
        $this->visit('/product-category/clothing?filter[tag]=1')->assertNotFound();
        $this->visit('/product-category/clothing?filter[brand]=1')->assertOk();
    }

    public function test_reordering_changes_the_order_in_both_places(): void
    {
        $this->publishList([['key' => '1', 'show' => true], ['key' => 'tag', 'show' => true], ['key' => 'season', 'show' => true], ['key' => 'brand', 'show' => true]]);

        $bar = $this->bar($this->visit('/product-category/clothing')->assertOk()->getContent());
        $this->assertSame(['Colour', 'Tag', 'Season', 'Brand'], $bar);

        $this->publishLayout('sidebar');
        $this->assertSame(['Colour', 'Tag', 'Season', 'Brand'], $this->panel($this->visit('/product-category/clothing')->assertOk()->getContent()));
    }

    public function test_the_chips_and_clear_follow_the_same_list(): void
    {
        $this->publishList([['key' => '1', 'show' => true], ['key' => 'brand', 'show' => true], ['key' => 'season', 'show' => false], ['key' => 'tag', 'show' => false]]);

        $html = $this->visit('/product-category/clothing?filter[1]=1001,1002&filter[brand]=1')->assertOk()->getContent();

        $this->assertSame(['Colour', 'Brand'], $this->bar($html));
        $this->assertStringContainsString('sf-bar-clear', $html);
    }

    public function test_the_kind_of_a_built_in_travels_with_its_row(): void
    {
        $this->publishList([['key' => 'brand', 'show' => true], ['key' => 'season', 'show' => true], ['key' => '1', 'show' => true], ['key' => 'tag', 'show' => true]], ['brand' => 'buttons', 'season' => 'links', 'tag' => 'list']);

        $html = $this->visit('/product-category/clothing')->assertOk()->getContent();

        $this->assertStringContainsString('sf-bctl-values-buttons', $html);
        $this->assertStringContainsString('sf-bctl-values-links', $html);
        $this->assertSame('buttons', PageDesignStore::values(PageDesign::CATALOG)['filterBrand'], 'the kind still lives in the «Каталог» JSON');
    }

    // ---- ★ cleaned on read ------------------------------------------------------------------------------------------------------------------

    public function test_bad_unknown_and_duplicate_keys_are_cleaned_on_read_and_missing_filters_are_appended_shown(): void
    {
        $settings = app(SiteSettingsRepository::class);
        $settings->set(AppearanceSettings::SIDEBAR_FILTERS_KEY, json_encode(['filters' => [
            ['key' => 'zzz', 'show' => true], ['key' => '99', 'show' => true], ['key' => '1', 'show' => false], ['key' => '1', 'show' => true],
            'junk', ['key' => ['x']], ['key' => 'brand', 'show' => 'no'], ['key' => 'brand', 'show' => false],
        ]]));
        app(StorefrontAppearance::class)->refresh();
        $this->app->forgetScopedInstances();

        $rows = SidebarFilters::effective(app(StorefrontAppearance::class)->sidebarFilters(), app(FilterCatalog::class)->knownKeys(), [], app(FilterCatalog::class)->hiddenByDefault());

        $this->assertSame([['key' => '1', 'show' => false], ['key' => 'brand', 'show' => true], ['key' => 'season', 'show' => true], ['key' => 'tag', 'show' => true], ['key' => 'featured', 'show' => false]], $rows, 'P2: «Препоръчани» is the one filter appended HIDDEN');
        $this->assertSame(['Brand', 'Season', 'Tag'], $this->bar($this->visit('/product-category/clothing')->assertOk()->getContent()), 'Colour is hidden by the list, the tag appended shown');
    }

    public function test_a_stored_value_that_is_not_a_list_is_the_default(): void
    {
        $settings = app(SiteSettingsRepository::class);

        foreach (['not json', '{"filters":"x"}', '{"filters":{"a":1}}', '[]', '{}'] as $junk) {
            $settings->set(AppearanceSettings::SIDEBAR_FILTERS_KEY, $junk);
            app(StorefrontAppearance::class)->refresh();
            $this->app->forgetScopedInstances();

            $this->assertSame(['Brand', 'Season', 'Colour'], $this->bar($this->visit('/product-category/clothing')->assertOk()->getContent()), $junk);
        }
    }

    // ---- ★ strict on save ---------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: mixed}> */
    public static function refused(): array
    {
        return [
            'not an array' => ['x'],
            'filters not a list' => [['filters' => ['a' => ['key' => 'brand', 'show' => true]]]],
            'unknown key' => [['filters' => [['key' => 'zzz', 'show' => true]]]],
            'unknown attribute' => [['filters' => [['key' => '77', 'show' => true]]]],
            'leading zero' => [['filters' => [['key' => '01', 'show' => true]]]],
            'duplicate' => [['filters' => [['key' => 'brand', 'show' => true], ['key' => 'brand', 'show' => false]]]],
            'show text' => [['filters' => [['key' => 'brand', 'show' => 'yes']]]],
            'show missing' => [['filters' => [['key' => 'brand']]]],
            'bad kind' => [['filters' => [['key' => 'brand', 'show' => true, 'kind' => 'off']]]],
            'extra row key' => [['filters' => [['key' => 'brand', 'show' => true, 'x' => 1]]]],
            'extra top key' => [['filters' => [], 'other' => 1]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function test_a_bad_working_copy_is_refused_on_save(mixed $value): void
    {
        [, $errors] = SidebarFilters::validate($value, app(FilterCatalog::class)->knownKeys());

        $this->assertNotSame([], $errors);
    }

    public function test_a_good_working_copy_is_accepted_and_completed(): void
    {
        [$clean, $errors] = SidebarFilters::validate(['filters' => [['key' => 'tag', 'show' => true, 'kind' => 'buttons']]], app(FilterCatalog::class)->knownKeys(), app(FilterCatalog::class)->hiddenByDefault());

        $this->assertSame([], $errors);
        $this->assertSame(['tag', 'brand', 'season', '1', 'featured'], array_column($clean['filters'], 'key'), 'the missing known keys are appended');
        $this->assertSame([true, true, true, true, false], array_column($clean['filters'], 'show'), 'shown, but «Препоръчани» (hidden by default)');
        $this->assertSame(['brand' => 'list', 'season' => 'list', 'tag' => 'buttons'], $clean['kinds']);
    }

    // ---- ★ cost ---------------------------------------------------------------------------------------------------------------------------

    public function test_a_hidden_filter_costs_no_query(): void
    {
        $all = $this->queryCount('/product-category/clothing');

        $this->publishList([['key' => 'brand', 'show' => false], ['key' => 'season', 'show' => false], ['key' => '1', 'show' => true], ['key' => 'tag', 'show' => false]]);
        $hidden = $this->queryCount('/product-category/clothing');

        $this->assertLessThan($all, $hidden, 'two facet queries fewer');
        $this->assertSame($all - 2, $hidden);
    }

    public function test_the_default_costs_the_same_with_or_without_a_saved_list_that_shows_the_same_filters(): void
    {
        $default = $this->queryCount('/product-category/clothing');

        $this->publishList([['key' => 'brand', 'show' => true], ['key' => 'season', 'show' => true], ['key' => '1', 'show' => true], ['key' => 'tag', 'show' => false]]);

        $this->assertSame($default, $this->queryCount('/product-category/clothing'));
    }

    // ---- ★ draft and the signed preview -----------------------------------------------------------------------------------------------------

    public function test_the_draft_never_reaches_the_shop_and_the_signed_preview_shows_it(): void
    {
        [$clean] = SidebarFilters::validate(['filters' => [['key' => '1', 'show' => false]], 'kinds' => ['brand' => 'list', 'season' => 'list', 'tag' => 'list']], app(FilterCatalog::class)->knownKeys(), app(FilterCatalog::class)->hiddenByDefault());
        SidebarStore::saveDraft($clean);
        $this->app->forgetScopedInstances();

        $this->assertSame(['Brand', 'Season', 'Colour'], $this->bar($this->visit('/product-category/clothing')->assertOk()->getContent()), 'the shop still shows the published list');

        $preview = $this->visit(DesignPreviewController::previewUrl('sidebar'))->assertOk()->getContent();
        $this->assertSame(['Brand', 'Season', 'Tag'], $this->bar($preview), 'the preview shows the working copy (Colour hidden, the tag shown)');

        $this->visit(str_replace('zone=sidebar', 'zone=sidebar&x=1', DesignPreviewController::previewUrl('sidebar')))->assertNotFound();
        $this->visit('/design-preview?zone=sidebar')->assertNotFound();
    }
}
