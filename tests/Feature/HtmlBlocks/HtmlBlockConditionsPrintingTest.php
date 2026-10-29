<?php

namespace Tests\Feature\HtmlBlocks;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Conditions\ProductContext;
use App\Storefront\Footer\FooterCompiler;
use App\Storefront\Footer\FooterLayout;
use App\Storefront\ProductSummary\ProductSummaryCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Feature\Storefront\BuildsMenus;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * HB-2: what the product page prints for an HTML block with «Показвай, когато…» conditions. The right column shows the block only on the products the conditions hold for (and prints NOTHING for the
 * others: no wrapper, no empty row); the footer and the menus always show it; a conditions-only edit reaches the page at once; the sale state is the price's own, so a sale that is switched off stops
 * matching with no page cache in between.
 */
class HtmlBlockConditionsPrintingTest extends TestCase
{
    use BuildsMenus;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const DRESSES = 2;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        ProductContext::forgetShared();

        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeCategory(self::DRESSES, 'Dresses', 'dresses', 1);
        $this->makeCategory(3, 'Shoes', 'shoes');
        $this->makeProduct(1, ['name' => 'Sale dress', 'slug' => 'sale-dress', 'price' => '100.00', 'sale' => '70.00', 'stock' => 3, 'categories' => [self::DRESSES]]);
        $this->makeProduct(2, ['name' => 'Plain dress', 'slug' => 'plain-dress', 'price' => '100.00', 'stock' => 3, 'categories' => [self::DRESSES]]);
        $this->makeProduct(3, ['name' => 'Shoes', 'slug' => 'shoes', 'price' => '80.00', 'sale' => '60.00', 'stock' => 3, 'categories' => [3]]);
        $this->app->forgetScopedInstances();
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    /** @param  array<string, mixed>|null  $conditions */
    private function block(string $text, ?array $conditions = null): HtmlBlockModel
    {
        return HtmlBlockModel::create(['title' => $text, 'body' => '<p>'.$text.'</p>', 'conditions' => $conditions]);
    }

    /** The right column: the title, the given blocks in order, the purchase box. @param  list<HtmlBlockModel>  $blocks */
    private function publish(array $blocks): void
    {
        $components = [['type' => 'title', 'settings' => []]];

        foreach ($blocks as $block) {
            $components[] = ['type' => 'html_block', 'settings' => ['html_block' => $block->id]];
        }

        $components[] = ['type' => 'purchase', 'settings' => []];
        app(ProductSummaryCompiler::class)->publish(['components' => $components], $this->settings());
        $this->app->forgetScopedInstances();
    }

    private function page(string $slug): string
    {
        $this->app->forgetScopedInstances();
        ProductContext::forgetShared();

        return $this->get('/product/'.$slug)->assertOk()->getContent();
    }

    private function blocksOn(string $slug): int
    {
        return substr_count($this->page($slug), 'class="sf-summary-html"');
    }

    // ---- the right column ----------------------------------------------------------------------------------------------------------------

    public function test_a_block_is_printed_only_on_the_products_its_conditions_hold_for(): void
    {
        $sale = $this->block('Extra 10% off', ['on_sale' => true]);
        $everywhere = $this->block('Free delivery');
        $this->publish([$sale, $everywhere]);

        $onSale = $this->page('sale-dress');
        $plain = $this->page('plain-dress');

        $this->assertStringContainsString('<div class="sf-summary-html"><p>Extra 10% off</p></div>', $onSale);
        $this->assertStringContainsString('<div class="sf-summary-html"><p>Free delivery</p></div>', $onSale);
        $this->assertStringNotContainsString('Extra 10% off', $plain, 'the block that is not for this product is not printed at all');
        $this->assertStringContainsString('<div class="sf-summary-html"><p>Free delivery</p></div>', $plain, 'a block with no conditions is on every product');
        $this->assertSame(2, substr_count($onSale, 'class="sf-summary-html"'));
        $this->assertSame(1, substr_count($plain, 'class="sf-summary-html"'), 'no empty wrapper and no empty row for the hidden block');
        $this->assertStringNotContainsString('<div class="sf-summary-html"></div>', $plain);
    }

    public function test_not_on_sale_is_the_other_way_and_two_conditions_must_both_hold(): void
    {
        $notOnSale = $this->block('Free delivery over 100', ['on_sale' => false]);
        $saleDresses = $this->block('Dress sale', ['on_sale' => true, 'categories' => [self::DRESSES]]);
        $this->publish([$notOnSale, $saleDresses]);

        $saleDress = $this->page('sale-dress');
        $plainDress = $this->page('plain-dress');
        $shoes = $this->page('shoes');

        // the sale dress: the second block only («not on sale» is false for it)
        $this->assertStringContainsString('Dress sale', $saleDress);
        $this->assertStringNotContainsString('Free delivery over 100', $saleDress);
        // the plain dress: the first block only (it is in the right category, but not on sale)
        $this->assertStringContainsString('Free delivery over 100', $plainDress);
        $this->assertStringNotContainsString('Dress sale', $plainDress);
        // the shoes are on sale (so not the first) but not a dress (so not the second): nothing at all
        $this->assertSame(0, substr_count($shoes, 'class="sf-summary-html"'));
    }

    public function test_a_conditions_only_edit_reaches_the_page_at_once_and_removing_them_shows_the_block_again(): void
    {
        $block = $this->block('Extra 10% off', ['on_sale' => true]);
        $this->publish([$block]);
        $this->assertSame([1, 0, 1], [$this->blocksOn('sale-dress'), $this->blocksOn('plain-dress'), $this->blocksOn('shoes')]);

        // the condition becomes a category: no Design-screen action, no publish
        $block->update(['conditions' => ['categories' => [self::DRESSES]]]);
        $this->assertSame([1, 1, 0], [$this->blocksOn('sale-dress'), $this->blocksOn('plain-dress'), $this->blocksOn('shoes')], 'the page follows the edit at once');

        // a parent category counts for the children
        $block->update(['conditions' => ['categories' => [1]]]);
        $this->assertSame([1, 1, 0], [$this->blocksOn('sale-dress'), $this->blocksOn('plain-dress'), $this->blocksOn('shoes')], 'the descendants of Clothing');

        $block->update(['conditions' => null]);
        $this->assertSame([1, 1, 1], [$this->blocksOn('sale-dress'), $this->blocksOn('plain-dress'), $this->blocksOn('shoes')], 'no conditions: the block is on every product again');
    }

    public function test_the_compiled_copy_carries_the_conditions_only_for_a_block_that_has_some(): void
    {
        $plain = $this->block('Free delivery');
        $this->publish([$plain]);

        $entry = json_decode((string) $this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY), true)['zones']['product_summary']['global'];
        $this->assertSame(['layout', 'blocks'], array_keys($entry), 'no block has conditions: the compiled copy is what it was');

        $conditioned = $this->block('Extra', ['on_sale' => false]);
        $this->publish([$plain, $conditioned]);

        $entry = json_decode((string) $this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY), true)['zones']['product_summary']['global'];
        $this->assertSame([(string) $conditioned->id => ['on_sale' => false]], $entry['conditions'], 'a `false` is kept');
    }

    public function test_a_corrupted_condition_in_the_compiled_copy_hides_the_block_and_the_page_still_works(): void
    {
        $block = $this->block('Extra', ['on_sale' => true]);
        $this->publish([$block]);
        $document = json_decode((string) $this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY), true);
        $document['zones']['product_summary']['global']['conditions'][(string) $block->id] = ['categories' => 'not a list', 'font' => 1];
        $this->settings()->set(AppearanceSettings::DESIGN_COMPILED_KEY, json_encode($document));
        \Illuminate\Support\Facades\Cache::forget(\App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY);

        $this->assertSame(0, $this->blocksOn('sale-dress'), 'a condition that cannot be read never shows the block');
    }

    public function test_the_sale_state_follows_the_price_with_no_page_cache_in_between(): void
    {
        $block = $this->block('Extra 10% off', ['on_sale' => true]);
        $this->publish([$block]);
        $this->assertSame(1, $this->blocksOn('sale-dress'));

        // the same event that changes the price: the sale price is removed
        app(\App\Services\ProductPricingAndStock::class)->writeSalePrice('101', null);

        $this->assertSame(0, $this->blocksOn('sale-dress'), 'the page prints the regular price and no longer the block');
        $this->assertStringNotContainsString('sf-price-regular', $this->page('sale-dress'));
    }

    // ---- the footer and the menus ---------------------------------------------------------------------------------------------------------

    public function test_the_footer_and_the_menus_show_a_block_with_conditions_always(): void
    {
        $block = $this->block('Promo for sale items', ['on_sale' => true]);

        $layout = FooterLayout::default();
        $layout['upper']['rows'] = [['columns' => [['span' => 1, 'align' => 'start', 'blocks' => [['type' => 'html_block', 'settings' => ['html_block' => $block->id]]]]]]];
        app(FooterCompiler::class)->publish($layout, $this->settings());
        $this->saveMenu('main', [$this->megaItem('Dresses', '/dresses', 1, [$this->columnItem([$this->menuItem('link', ['label' => 'Maxi', 'url' => '/maxi'])])], ['html_block_id' => $block->id])]);
        $this->app->forgetScopedInstances();

        $home = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<div class="sf-mega-media sf-mega-html"><p>Promo for sale items</p></div>', $home, 'the mega menu has no product: the conditions are ignored');
        $this->assertStringContainsString('<p>Promo for sale items</p>', substr($home, (int) strpos($home, '<footer')), 'so does the footer');
    }

    // ---- sessionless -----------------------------------------------------------------------------------------------------------------------

    public function test_pages_with_a_conditioned_block_stay_sessionless_with_no_inline_style_or_script(): void
    {
        $this->publish([$this->block('Extra', ['on_sale' => true]), $this->block('Everywhere')]);

        foreach (['sale-dress', 'plain-dress'] as $slug) {
            $this->app->forgetScopedInstances();
            $response = $this->get('/product/'.$slug)->assertOk();
            $html = $response->getContent();

            $this->assertSame([], $response->headers->getCookies(), $slug.': no cookie is set');
            $this->assertStringNotContainsString(' style=', $html, $slug);
            $this->assertSame(0, preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $html), $slug.': no inline script');
        }
    }

    /** The queries one product page costs. */
    private function queries(string $slug): int
    {
        $this->page($slug); // warm: the settings and the compiled copies are read once
        $this->app->forgetScopedInstances();
        ProductContext::forgetShared();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/product/'.$slug)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_a_block_with_no_conditions_or_only_a_sale_condition_costs_the_page_no_extra_query(): void
    {
        $none = $this->queries('sale-dress');

        $this->publish([$this->block('Everywhere')]);
        $this->assertSame($none, $this->queries('sale-dress'), 'an unconditioned block: no query is added');

        $this->publish([$this->block('Extra', ['on_sale' => true])]);
        $this->assertSame($none, $this->queries('sale-dress'), 'the sale state is the price block the page already has: no query is added');
    }

    public function test_a_category_condition_costs_two_small_queries_and_only_on_a_page_that_has_such_a_block(): void
    {
        $none = $this->queries('sale-dress');

        $this->publish([$this->block('Dresses', ['categories' => [self::DRESSES]])]);

        $this->assertSame($none + 2, $this->queries('sale-dress'), 'the product\'s categories and the category parents: two queries, once per request, however many blocks ask');
    }
}