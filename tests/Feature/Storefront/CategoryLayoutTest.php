<?php

namespace Tests\Feature\Storefront;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockUsage;
use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Http\Middleware\LimitFilteredCategoryRequests;
use App\Storefront\Filters\FilterCatalog;
use App\Storefront\PageDesign\PageDesign;
use App\Storefront\PageDesign\PageDesignStore;
use App\Storefront\ReadModels\ListingSort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * CD-1b: the category page's layout — the published layout / side (classes of the wrapper), the filters as ONE GET form, the form's array form and empty submission (301s), the sort (the ONE list of the
 * options on offer), the count, the «Блок отгоре» banner and its deletion guard.
 *
 * The world: Colour (attribute 1: Red 1001, Blue 1002), Size (2: S 2001, M 2002), 14 products in «Clothing» (1): 8 red-S, 4 blue-M, 2 plain.
 */
class CategoryLayoutTest extends TestCase
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
        $this->makeAttribute(2, 'Size', ['S', 'M']);
        DB::table('catalog_attribute_definitions')->whereIn('id', [1, 2])->update(['is_filterable' => true, 'filter_display' => 'list']);

        for ($i = 1; $i <= 14; $i++) {
            $attrs = $i <= 8 ? [1 => 'Red', 2 => 'S'] : ($i <= 12 ? [1 => 'Blue', 2 => 'M'] : null);
            $this->makeProduct($i, ['type' => $attrs ? 'variable' : 'simple', 'name' => "P{$i}", 'slug' => "p{$i}", 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'timeline' => sprintf('2026-01-%02d 10:00:00', $i),
                'variations' => $attrs ? [['attrs' => $attrs, 'price' => '10.00', 'stock' => 2]] : null]);
        }

        FilterCatalog::forget();
    }

    private function visit(string $url): \Illuminate\Testing\TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->get($url);
    }

    private function publish(array $values): void
    {
        [$clean, $errors] = PageDesign::validate(PageDesign::CATALOG, $values + PageDesign::defaults(PageDesign::CATALOG), false);
        $this->assertSame([], $errors);
        PageDesignStore::publish(PageDesign::CATALOG, $clean);
    }

    private function block(string $body = '<p>Spring sale</p>'): int
    {
        return (int) DB::table('html_blocks')->insertGetId(['title' => 'Banner', 'body' => $body, 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
    }

    // ---- the layout and the side: the PUBLISHED copy decides ---------------------------------------------------------------------------

    public function test_the_defaults_print_the_full_width_left_classes(): void
    {
        $html = $this->visit('/product-category/clothing')->assertOk()->getContent();

        $this->assertStringContainsString('<section class="sf-listing sf-category sf-cat-layout-full sf-cat-side-left">', $html);
    }

    public function test_the_published_layout_and_side_are_classes_and_a_draft_never_shows(): void
    {
        $this->publish(['layout' => 'sidebar', 'side' => 'right']);

        $this->assertStringContainsString('sf-cat-layout-sidebar sf-cat-side-right', $this->visit('/product-category/clothing')->getContent());

        [$draft] = PageDesign::validate(PageDesign::CATALOG, ['layout' => 'full', 'side' => 'left'] + PageDesign::defaults(PageDesign::CATALOG), false);
        PageDesignStore::saveDraft(PageDesign::CATALOG, $draft);

        $this->assertStringContainsString('sf-cat-layout-sidebar sf-cat-side-right', $this->visit('/product-category/clothing')->getContent(), 'the draft changes nothing on the shop');
    }

    public function test_the_signed_preview_shows_the_drafts_layout_and_the_shop_does_not(): void
    {
        [$draft] = PageDesign::validate(PageDesign::CATALOG, ['layout' => 'sidebar', 'side' => 'right'] + PageDesign::defaults(PageDesign::CATALOG), false);
        PageDesignStore::saveDraft(PageDesign::CATALOG, $draft);

        $this->assertStringContainsString('sf-cat-layout-sidebar sf-cat-side-right', $this->visit(DesignPreviewController::previewUrl(PageDesign::CATALOG))->assertOk()->getContent());
        $this->assertStringContainsString('sf-cat-layout-full sf-cat-side-left', $this->visit('/product-category/clothing')->getContent());
    }

    // ---- the form -----------------------------------------------------------------------------------------------------------------------------

    public function test_one_get_form_holds_the_filters_before_the_list_for_both_sides_and_open_rules(): void
    {
        foreach (['left', 'right'] as $side) {
            $this->publish(['layout' => 'sidebar', 'side' => $side]);
            $html = $this->visit('/product-category/clothing')->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, '<form class="sf-filters-form" method="get" action="/product-category/clothing">'), 'one form, no duplicated filter markup');
            $this->assertSame(1, substr_count($html, 'name="filter[1][]" value="1001"'));
            $this->assertLessThan(strpos($html, 'class="sf-cat-main"'), strpos($html, 'class="sf-fdrawer"'), "the filters come first in the DOM ({$side})");
            $this->assertSame(2, substr_count($html, '<details class="sf-fgroup" open>'), 'with the side panel every group is open');
        }

        $this->publish([]);
        $plain = $this->visit('/product-category/clothing')->getContent();
        $this->assertSame(0, substr_count($plain, '<details class="sf-fgroup" open>'), 'a bar: closed with no choice');
        $chosen = $this->visit('/product-category/clothing?filter[1]=1001')->getContent();
        $this->assertSame(1, substr_count($chosen, '<details class="sf-fgroup" open>'), 'a group with a choice is open');
        $this->assertStringContainsString('(1)', $chosen);
    }

    public function test_the_per_page_hidden_input_exists_only_for_a_size_that_is_not_the_published_one(): void
    {
        $this->assertStringNotContainsString('name="per_page"', $this->visit('/product-category/clothing')->getContent());
        $this->assertStringContainsString('<input type="hidden" name="per_page" value="12">', $this->visit('/product-category/clothing?per_page=12')->getContent());

        $this->publish(['per_page' => '12']);
        $this->assertStringNotContainsString('name="per_page"', $this->visit('/product-category/clothing')->getContent());
        $this->assertStringContainsString('<input type="hidden" name="per_page" value="24">', $this->visit('/product-category/clothing?per_page=24')->getContent());
    }

    public function test_the_form_has_the_buttons_the_trigger_the_drawer_and_no_style_or_script(): void
    {
        $html = $this->visit('/product-category/clothing')->assertOk()->getContent();

        $this->assertStringContainsString('data-sf-fdrawer-open', $html);
        $this->assertStringContainsString('Filter and sort', $html);
        $this->assertStringContainsString('<button type="submit" class="sf-fbtn sf-fbtn-primary">Apply</button>', $html);
        $this->assertStringContainsString('class="sf-fbtn sf-fbtn-secondary" href="/product-category/clothing"', $html);
        $this->assertSame(1, substr_count($html, 'data-sf-fdrawer>'));
        $this->assertDoesNotMatchRegularExpression('/\sstyle=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $html);

        $clear = $this->visit('/product-category/clothing?per_page=12')->getContent();
        $this->assertStringContainsString('class="sf-fbtn sf-fbtn-secondary" href="/product-category/clothing?per_page=12"', $clear, '«Clear» keeps only a non-default size');
    }

    public function test_nothing_to_show_prints_no_trigger_and_no_drawer(): void
    {
        $html = view('storefront.partials.filters', ['filters' => null, 'sorts' => [], 'perPageHidden' => null, 'clearUrl' => '/x', 'actionUrl' => '/x', 'layout' => 'full'])->render();
        $toolbar = view('storefront.partials.category-toolbar', ['total' => 3, 'filters' => null, 'sorts' => []])->render();

        $this->assertSame('', trim($html));
        $this->assertStringNotContainsString('data-sf-fdrawer-open', $toolbar);
        $this->assertStringContainsString('3 products', $toolbar);
    }

    // ---- the array form, the empty submission ---------------------------------------------------------------------------------------------

    public function test_the_array_form_is_a_301_to_the_canonical_comma_form(): void
    {
        $this->visit('/product-category/clothing?filter[1][]=1001')->assertStatus(301)->assertRedirect(url('/product-category/clothing?filter[1]=1001'));
        $this->visit('/product-category/clothing?filter[1][]=1002&filter[1][]=1001&filter[2][]=2001')->assertStatus(301)->assertRedirect(url('/product-category/clothing?filter[1]=1001,1002&filter[2]=2001'));
        $this->visit('/product-category/clothing?filter[2][]=2001&filter[1][]=1001&sort=name_asc&per_page=12')->assertStatus(301)->assertRedirect(url('/product-category/clothing?filter[1]=1001&filter[2]=2001&per_page=12&sort=name_asc'));
        $this->visit('/product-category/clothing?filter[1]=1001,1002')->assertOk();
    }

    /** @return array<string, array{0: string}> */
    public static function badArrayForms(): array
    {
        return [
            'an unknown value' => ['filter[1][]=9999'], 'another attribute\'s value' => ['filter[1][]=2001'], 'a nested array' => ['filter[1][0][]=1001'], 'an associative array' => ['filter[1][a]=1001'],
            'a number with a sign' => ['filter[1][]=%2B1001'], 'a duplicate' => ['filter[1][]=1001&filter[1][]=1001'], 'an empty element' => ['filter[1][]='], 'a script' => ['filter[1][]=%3Cscript%3E'],
            'an unknown attribute' => ['filter[9][]=1001'], 'a non-numeric key' => ['filter[colour][]=1001'], 'a leading zero' => ['filter[1][]=01001'], 'a long value' => ['filter[1][]='.str_repeat('1', 65)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badArrayForms')]
    public function test_a_bad_array_form_is_the_storefront_404(string $query): void
    {
        $this->visit('/product-category/clothing?'.$query)->assertNotFound();
    }

    public function test_the_caps_hold_for_the_array_form_too(): void
    {
        for ($attribute = 10; $attribute <= 16; $attribute++) {
            $this->makeAttribute($attribute, "Extra {$attribute}", ['a', 'b']);
            DB::table('catalog_attribute_definitions')->where('id', $attribute)->update(['is_filterable' => true]);
        }

        $this->makeAttribute(20, 'Many', array_map(fn (int $i): string => "v{$i}", range(1, 12)));
        DB::table('catalog_attribute_definitions')->where('id', 20)->update(['is_filterable' => true]);
        FilterCatalog::forget();

        $this->visit('/product-category/clothing?filter[1][]=1001&filter[2][]=2001&filter[10][]=10001&filter[11][]=11001&filter[12][]=12001&filter[13][]=13001')->assertStatus(301);
        $this->visit('/product-category/clothing?filter[1][]=1001&filter[2][]=2001&filter[10][]=10001&filter[11][]=11001&filter[12][]=12001&filter[13][]=13001&filter[14][]=14001')->assertNotFound();
        $this->visit('/product-category/clothing?'.implode('&', array_map(fn (int $i): string => 'filter[20][]='.(20000 + $i), range(1, 10))))->assertStatus(301);
        $this->visit('/product-category/clothing?'.implode('&', array_map(fn (int $i): string => 'filter[20][]='.(20000 + $i), range(1, 11))))->assertNotFound();
    }

    public function test_an_empty_submission_is_a_301_to_the_plain_url(): void
    {
        $this->visit('/product-category/clothing?sort=')->assertStatus(301)->assertRedirect(url('/product-category/clothing'));
        $this->visit('/product-category/clothing?sort=&per_page=12')->assertStatus(301)->assertRedirect(url('/product-category/clothing?per_page=12'));
        $this->visit('/product-category/clothing?per_page=12&sort=')->assertStatus(301)->assertRedirect(url('/product-category/clothing?per_page=12'));
        $this->visit('/product-category/clothing?filter[1][]=1001&sort=')->assertStatus(301)->assertRedirect(url('/product-category/clothing?filter[1]=1001'));
        $this->visit('/product-category/clothing?filter[1]=1001&sort=')->assertStatus(301)->assertRedirect(url('/product-category/clothing?filter[1]=1001'));
    }

    public function test_the_array_form_counts_toward_the_rate_limit(): void
    {
        RateLimiter::clear('filtered-category:198.51.100.40');

        for ($i = 0; $i < LimitFilteredCategoryRequests::LIMIT; $i++) {
            $this->app->forgetScopedInstances();
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.40'])->get('/product-category/clothing?filter[1][]=1001')->assertStatus(301);
        }

        $this->app->forgetScopedInstances();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.40'])->get('/product-category/clothing?filter[1][]=1001')->assertStatus(429);
    }

    // ---- the sort -------------------------------------------------------------------------------------------------------------------------------

    public function test_the_sort_options_are_the_one_list_and_the_default_is_empty(): void
    {
        $this->assertSame([ListingSort::NEWEST, ListingSort::NAME_ASC], ListingSort::storefrontOptions());

        $html = $this->visit('/product-category/clothing?filter[1]=1001&per_page=12')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'class="sf-sortdd-link"'), 'the dropdown links = the options');
        $this->assertSame(2, substr_count($html, 'type="radio" name="sort"'), 'the radios = the options');
        $this->assertStringContainsString('<input class="sf-fv-input" type="radio" name="sort" value="" checked>', $html, 'the default option has an EMPTY value');
        $this->assertStringContainsString('type="radio" name="sort" value="name_asc"', $html);
        $this->assertStringNotContainsString('value="price_', $html, 'no price sort is offered');
        $this->assertStringNotContainsString('sort=price', $html);
        $this->assertStringContainsString('href="/product-category/clothing?filter[1]=1001&amp;per_page=12" rel="nofollow" aria-current="true">Newest', $html, 'the default link has no sort and keeps the filter and the size');
        $this->assertStringContainsString('href="/product-category/clothing?filter[1]=1001&amp;per_page=12&amp;sort=name_asc" rel="nofollow">Name (A–Z)', $html);
        $this->assertStringContainsString('Sort by:', $html);
    }

    public function test_the_current_sort_is_marked_and_a_price_sort_is_still_a_404(): void
    {
        $html = $this->visit('/product-category/clothing?sort=name_asc')->assertOk()->getContent();

        $this->assertStringContainsString('type="radio" name="sort" value="name_asc" checked', $html);
        $this->assertStringContainsString('aria-current="true">Name (A–Z)', $html);

        foreach (['price_asc', 'price_desc', 'nope'] as $sort) {
            $this->visit('/product-category/clothing?sort='.$sort)->assertNotFound();
        }

        $this->visit('/product-category/clothing?sort=newest')->assertOk(); // today's behaviour: the default's own name is accepted (no redirect)
    }

    // ---- the count ------------------------------------------------------------------------------------------------------------------------------

    public function test_the_count_of_products(): void
    {
        $this->assertStringContainsString('<p class="sf-count">14 products</p>', $this->visit('/product-category/clothing')->getContent());
        $this->assertStringContainsString('<p class="sf-count">8 products</p>', $this->visit('/product-category/clothing?filter[1]=1001')->getContent());
        $this->assertStringContainsString('<p class="sf-count">0 products</p>', $this->visit('/product-category/clothing?filter[1]=1001&filter[2]=2002')->getContent());

        DB::table('catalog_products')->where('id', '>', 1)->update(['catalog_visibility' => 'hidden']);
        $this->assertStringContainsString('<p class="sf-count">1 product</p>', $this->visit('/product-category/clothing')->getContent());

        app()->setLocale('bg');
        $this->assertStringContainsString('<p class="sf-count">1 продукт</p>', $this->visit('/product-category/clothing')->getContent());
    }

    // ---- «Блок отгоре» ----------------------------------------------------------------------------------------------------------------------------

    public function test_the_banner_is_shown_on_page_1_without_a_filter_and_the_heading_stays_for_search_engines(): void
    {
        DB::table('catalog_categories')->where('id', 1)->update(['header_block_id' => $this->block('<p>Spring sale</p>')]);

        $html = $this->visit('/product-category/clothing')->assertOk()->getContent();

        $this->assertStringContainsString('<div class="sf-cat-banner"><p>Spring sale</p>', $html);
        $this->assertStringContainsString('<h1 class="sf-title sf-sr">Clothing</h1>', $html, 'the heading is printed and visually hidden');
        $this->assertLessThan(strpos($html, 'class="sf-cat-body"'), strpos($html, 'sf-cat-banner'), 'above the filters and the list');

        $this->assertStringNotContainsString('Spring sale', $this->visit('/product-category/clothing?per_page=12&page=2')->assertOk()->getContent(), 'not on page 2');
        $filtered = $this->visit('/product-category/clothing?filter[1]=1001')->assertOk()->getContent();
        $this->assertStringNotContainsString('Spring sale', $filtered, 'not with a filter');
        $this->assertStringContainsString('<h1 class="sf-title">Clothing</h1>', $filtered, 'and then the heading is visible');
    }

    public function test_a_gone_or_empty_banner_prints_nothing_and_the_heading_is_visible(): void
    {
        DB::table('catalog_categories')->where('id', 1)->update(['header_block_id' => $this->block('')]);
        $html = $this->visit('/product-category/clothing')->assertOk()->getContent();

        $this->assertStringNotContainsString('sf-cat-banner', $html);
        $this->assertStringContainsString('<h1 class="sf-title">Clothing</h1>', $html);

        $id = $this->block('<p>Gone</p>');
        DB::table('catalog_categories')->where('id', 1)->update(['header_block_id' => $id]);
        Schema::disableForeignKeyConstraints();
        DB::table('html_blocks')->where('id', $id)->delete();
        Schema::enableForeignKeyConstraints();

        $gone = $this->visit('/product-category/clothing')->assertOk()->getContent();
        $this->assertStringNotContainsString('sf-cat-banner', $gone);
        $this->assertStringContainsString('<h1 class="sf-title">Clothing</h1>', $gone);
    }

    public function test_a_block_that_is_a_banner_cannot_be_deleted_and_the_message_names_the_category(): void
    {
        $id = $this->block();
        $model = HtmlBlockModel::query()->findOrFail($id);
        $this->assertNull(HtmlBlockUsage::refusal($model));

        DB::table('catalog_categories')->where('id', 1)->update(['header_block_id' => $id]);

        $this->assertSame(['Clothing'], HtmlBlockUsage::categories($id));
        $this->assertTrue(HtmlBlockUsage::isUsed($id));
        $this->assertSame('The block "Banner" is shown as the banner of the category "Clothing" and cannot be deleted.', HtmlBlockUsage::refusal($model));
        $this->assertSame('Блокът „Banner“ се показва като банер на категория „Clothing“ и не може да се изтрие.', (function () use ($model): string { app()->setLocale('bg'); return (string) HtmlBlockUsage::refusal($model); })());
    }

    public function test_the_database_refuses_to_delete_a_block_that_is_a_banner(): void
    {
        $id = $this->block();
        DB::table('catalog_categories')->where('id', 1)->update(['header_block_id' => $id]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('html_blocks')->where('id', $id)->delete();
    }

    public function test_the_category_form_saves_the_block_and_clears_it(): void
    {
        $this->actingAsStaff('Administrator');
        $id = $this->block();

        Livewire::test(EditCategory::class, ['record' => 1])->fillForm(['header_block_id' => $id])->call('save')->assertHasNoFormErrors();
        $this->assertSame($id, (int) DB::table('catalog_categories')->where('id', 1)->value('header_block_id'));

        Livewire::test(EditCategory::class, ['record' => 1])->assertFormSet(['header_block_id' => $id])->fillForm(['header_block_id' => null])->call('save')->assertHasNoFormErrors();
        $this->assertNull(DB::table('catalog_categories')->where('id', 1)->value('header_block_id'));

        Livewire::test(EditCategory::class, ['record' => 1])->fillForm(['header_block_id' => 99999])->call('save')->assertHasFormErrors(['header_block_id']);
    }

    public function test_the_migration_adds_and_drops_the_column(): void
    {
        $migration = require base_path('database/migrations/2026_10_31_000001_add_header_block_id_to_catalog_categories_table.php');

        $this->assertTrue(Schema::hasColumn('catalog_categories', 'header_block_id'));
        $migration->down();
        $this->assertFalse(Schema::hasColumn('catalog_categories', 'header_block_id'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('catalog_categories', 'header_block_id'));
    }

    public function test_the_query_count_with_defaults_does_not_grow(): void
    {
        $count = function (): int {
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            $this->visit('/product-category/clothing')->assertOk();

            return $n;
        };

        $count();
        $warm = $count();
        DB::table('catalog_attribute_definitions')->update(['is_filterable' => false]);
        FilterCatalog::forget();
        $count();
        $none = $count();

        fwrite(STDERR, "\n[query-count] category page CD-1b: with 2 filters {$warm}, no filters {$none}\n");

        $this->assertSame($warm - 2, $none, 'the toolbar and the form add no query of their own (one facet query per filter, as before)');
    }
}
