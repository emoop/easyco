<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Filters\FilterCatalog;
use App\Storefront\PageDesign\PageDesign;
use App\Storefront\PageDesign\PageDesignStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * CD-1d: the category page prints the width class of the side panel from the PUBLISHED design only (`sf-cat-sidew-wide|medium|narrow`), only with the side-panel layout; old data and a hand-edited bad value
 * give `wide`; the page keeps no style attribute and no inline script.
 */
class CategorySideWidthTest extends TestCase
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

        for ($i = 1; $i <= 4; $i++) {
            $this->makeProduct($i, ['type' => 'variable', 'name' => "P{$i}", 'slug' => "p{$i}", 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'timeline' => sprintf('2026-01-%02d 10:00:00', $i),
                'variations' => [['attrs' => [1 => $i % 2 ? 'Red' : 'Blue'], 'price' => '10.00', 'stock' => 2]]]);
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

    /** a stored row exactly as written (old data, a hand edit): no validation on the way in */
    private function storeRaw(string $json): void
    {
        app(SiteSettingsRepository::class)->set(AppearanceSettings::CATALOG_DESIGN_KEY, $json);
        app(StorefrontAppearance::class)->refresh();
    }

    private function section(string $html): string
    {
        preg_match('#<section class="sf-listing sf-category[^"]*">#', $html, $m);

        return $m[0] ?? '';
    }

    public function test_the_published_width_is_a_class_with_the_side_panel_for_each_side(): void
    {
        foreach (['wide', 'medium', 'narrow'] as $width) {
            foreach (['left', 'right'] as $side) {
                $this->publish(['layout' => 'sidebar', 'side' => $side, 'sideWidth' => $width]);

                $this->assertSame('<section class="sf-listing sf-category sf-cat-layout-sidebar sf-cat-side-'.$side.' sf-cat-sidew-'.$width.'">', $this->section($this->visit('/product-category/clothing')->assertOk()->getContent()));
            }
        }
    }

    public function test_the_bar_layout_has_no_width_class_and_the_section_is_what_it_was(): void
    {
        $this->assertSame('<section class="sf-listing sf-category sf-cat-layout-full sf-cat-side-left">', $this->section($this->visit('/product-category/clothing')->getContent()));

        $this->publish(['layout' => 'full', 'sideWidth' => 'narrow']);

        $this->assertStringNotContainsString('sf-cat-sidew', $this->visit('/product-category/clothing')->getContent(), 'with the bar the width has no effect');
    }

    public function test_a_draft_width_never_reaches_the_shop_and_the_signed_preview_shows_it(): void
    {
        $this->publish(['layout' => 'sidebar', 'sideWidth' => 'wide']);
        [$draft] = PageDesign::validate(PageDesign::CATALOG, ['layout' => 'sidebar', 'sideWidth' => 'narrow'] + PageDesign::defaults(PageDesign::CATALOG), false);
        PageDesignStore::saveDraft(PageDesign::CATALOG, $draft);

        $this->assertStringContainsString('sf-cat-sidew-wide"', $this->visit('/product-category/clothing')->getContent());
        $this->assertStringNotContainsString('sf-cat-sidew-narrow', $this->visit('/product-category/clothing')->getContent());
        $this->assertStringContainsString('sf-cat-sidew-narrow"', $this->visit(DesignPreviewController::previewUrl(PageDesign::CATALOG))->assertOk()->getContent(), 'the preview carries the working copy');
    }

    public function test_old_data_without_the_key_prints_wide_and_is_the_same_page_as_an_explicit_wide(): void
    {
        $this->storeRaw('{"cols":"4","cols_phone":"2","per_page":"24","layout":"sidebar","side":"left"}');
        $old = $this->visit('/product-category/clothing')->assertOk()->getContent();

        $this->assertStringContainsString('<section class="sf-listing sf-category sf-cat-layout-sidebar sf-cat-side-left sf-cat-sidew-wide">', $old);

        $this->publish(['layout' => 'sidebar', 'side' => 'left', 'sideWidth' => 'wide']);

        $this->assertSame($old, $this->visit('/product-category/clothing')->getContent(), 'byte-identical: the only difference from before the setting is the new class name');
    }

    public function test_a_hand_edited_bad_value_reads_as_wide_and_keeps_the_side_panel(): void
    {
        foreach (['"20rem"', '"WIDE"', '["narrow"]', '""', '"narrow;color:red"'] as $bad) {
            $this->storeRaw('{"layout":"sidebar","side":"right","sideWidth":'.$bad.'}');

            $this->assertSame('<section class="sf-listing sf-category sf-cat-layout-sidebar sf-cat-side-right sf-cat-sidew-wide">', $this->section($this->visit('/product-category/clothing')->assertOk()->getContent()), $bad);
        }
    }

    public function test_the_page_has_no_style_attribute_and_no_inline_script_and_is_the_same_for_every_visitor(): void
    {
        $this->publish(['layout' => 'sidebar', 'sideWidth' => 'medium']);
        $html = $this->visit('/product-category/clothing')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $html);
        $this->assertSame($html, $this->visit('/product-category/clothing')->getContent());
    }
}
