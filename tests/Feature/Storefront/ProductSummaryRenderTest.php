<?php

namespace Tests\Feature\Storefront;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\ProductSummary\ProductSummaryCompiler;
use App\Storefront\ProductSummary\ProductSummaryDrafts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * SC-1: what the product page prints for the right column. The PUBLISHED layout decides the components and their order; a layout that is not valid (or none at all) prints the default one, which is the
 * page the shop always printed (ProductSummaryBaselineTest holds the bytes). The storefront never reads a draft; the admin's signed preview does, for one request.
 */
class ProductSummaryRenderTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $acme = $this->makeBrand(1, 'Acme', 'acme');
        $this->makeProduct(1, ['name' => 'Summer dress', 'slug' => 'summer-dress', 'brand' => $acme, 'price' => '100.00', 'stock' => 4, 'short' => 'Light and airy']);
        $this->makeProduct(2, ['name' => 'Plain thing', 'slug' => 'plain-thing', 'stock' => 2]);
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    /** @param  list<string|array{type: string, settings: array<string, mixed>}>  $components */
    private function layout(array $components): array
    {
        return ['components' => array_map(static fn (string|array $c): array => is_string($c) ? ['type' => $c, 'settings' => []] : $c, $components)];
    }

    /** @param  list<string|array{type: string, settings: array<string, mixed>}>  $components */
    private function publish(array $components): void
    {
        app(ProductSummaryCompiler::class)->publish($this->layout($components), $this->settings());
        $this->app->forgetScopedInstances();
    }

    private function page(string $slug = 'summer-dress'): string
    {
        $this->app->forgetScopedInstances();

        return $this->get('/product/'.$slug)->assertOk()->getContent();
    }

    /** The right column only: from `sf-summary` to its closing section. */
    private function summary(string $html): string
    {
        preg_match('#<section class="sf-summary">(.*?)</section>\s*</div>#s', $html, $m);

        return $m[1] ?? $this->fail('no right column');
    }

    /** @param  list<string>  $needles @return list<string> the needles in the order they appear */
    private function orderOf(string $html, array $needles): array
    {
        $at = [];

        foreach ($needles as $needle) {
            $position = strpos($html, $needle);
            $position === false || $at[$needle] = $position;
        }

        asort($at);

        return array_keys($at);
    }

    private const MARKS = ['brand' => 'sf-product-brand', 'title' => 'sf-title', 'short' => 'sf-short-description', 'price' => 'class="sf-price"', 'stock' => 'sf-stock', 'purchase' => 'sf-add-to-cart-box'];

    // ---- order -----------------------------------------------------------------------------------------------------------------------

    public function test_a_published_layout_prints_the_components_in_exactly_its_order(): void
    {
        $this->publish(['price', 'title', 'purchase', 'brand']);
        $column = $this->summary($this->page());

        $this->assertSame([self::MARKS['price'], self::MARKS['title'], self::MARKS['purchase'], self::MARKS['brand']], $this->orderOf($column, array_values(self::MARKS)));
    }

    public function test_a_component_that_is_not_in_the_layout_is_not_printed(): void
    {
        $this->publish(['title', 'purchase']);
        $column = $this->summary($this->page());

        foreach (['brand', 'short', 'price', 'stock'] as $gone) {
            $this->assertStringNotContainsString(self::MARKS[$gone], $column, $gone);
        }

        $this->assertStringContainsString('Summer dress', $column);
    }

    public function test_two_products_with_different_data_print_correctly_in_the_same_layout(): void
    {
        $this->publish(['stock', 'brand', 'title', 'short_description', 'price', 'purchase']);

        $dress = $this->summary($this->page('summer-dress'));
        $plain = $this->summary($this->page('plain-thing'));

        $this->assertSame([self::MARKS['stock'], self::MARKS['brand'], self::MARKS['title'], self::MARKS['short'], self::MARKS['price'], self::MARKS['purchase']], $this->orderOf($dress, array_values(self::MARKS)));
        $this->assertStringContainsString('Acme', $dress);
        $this->assertSame([self::MARKS['stock'], self::MARKS['title'], self::MARKS['purchase']], $this->orderOf($plain, array_values(self::MARKS)), 'no brand, short description or price: nothing printed for them');
        $this->assertStringContainsString('Plain thing', $plain);
    }

    // ---- a layout that does not validate ---------------------------------------------------------------------------------------------------

    public function test_a_stored_layout_that_does_not_validate_prints_the_default_layout_and_the_page_answers_200(): void
    {
        $default = $this->page();

        $broken = [
            'corrupted JSON' => '{not json at all',
            'an unknown type' => json_encode(['hash' => 'x', 'zones' => ['product_summary' => ['global' => ['layout' => ['components' => [['type' => 'title', 'settings' => []], ['type' => 'carousel', 'settings' => []], ['type' => 'purchase', 'settings' => []]]], 'blocks' => []]]]]),
            'no purchase' => json_encode(['hash' => 'x', 'zones' => ['product_summary' => ['global' => ['layout' => ['components' => [['type' => 'title', 'settings' => []]]], 'blocks' => []]]]]),
            'not an array' => json_encode(['hash' => 'x', 'zones' => ['product_summary' => ['global' => 'oops']]]),
        ];

        foreach ($broken as $name => $json) {
            $this->settings()->set(AppearanceSettings::DESIGN_COMPILED_KEY, $json);
            \Illuminate\Support\Facades\Cache::forget(\App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY);

            $this->assertSame($default, $this->page(), "{$name}: the default layout is printed");
        }
    }

    // ---- the draft is never read ---------------------------------------------------------------------------------------------------------

    public function test_the_storefront_never_reads_the_draft(): void
    {
        $default = $this->page();
        ProductSummaryDrafts::saveDraft($this->layout(['price', 'title', 'purchase']));
        $this->assertNotNull($this->settings()->get(AppearanceSettings::SUMMARY_DRAFT_KEY));

        $this->assertSame($default, $this->page(), 'a draft with nothing published: the default layout');

        $this->publish(['title', 'purchase']);
        $published = $this->page();
        ProductSummaryDrafts::saveDraft($this->layout(['purchase', 'title']));

        $this->assertSame($published, $this->page(), 'a draft beside a published layout: the published one');
    }

    // ---- SKU -------------------------------------------------------------------------------------------------------------------------

    public function test_the_sku_is_printed_when_the_product_has_one_and_nothing_when_it_is_empty(): void
    {
        $this->publish(['title', 'sku', 'purchase']);

        $column = $this->summary($this->page());
        $this->assertMatchesRegularExpression('#<p class="sf-sku">[^<]*BASE-1</p>#', $column);

        DB::table('catalog_products')->where('id', 1)->update(['base_sku' => '']);
        $this->assertStringNotContainsString('sf-sku', $this->summary($this->page()), 'an empty SKU prints nothing');

    }

    public function test_a_sku_with_markup_is_printed_as_text(): void
    {
        $this->publish(['title', 'sku', 'purchase']);
        DB::table('catalog_products')->where('id', 1)->update(['base_sku' => '<b>X1</b>']);

        $column = $this->summary($this->page());

        $this->assertStringContainsString('&lt;b&gt;X1&lt;/b&gt;', $column);
        $this->assertStringNotContainsString('<b>X1', $column);
    }

    // ---- the HTML block ------------------------------------------------------------------------------------------------------------------

    public function test_an_html_block_prints_its_sanitised_html_where_the_layout_puts_it(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Club', 'body' => '<p>-20% with code BDAY</p><script>alert(1)</script><p onclick="x()" style="color:red">Note <a href="javascript:alert(2)">bad</a> <a href="/club">club</a></p>']);
        $this->publish(['title', ['type' => 'html_block', 'settings' => ['html_block' => $block->id]], 'purchase']);

        $column = $this->summary($this->page());

        $this->assertStringContainsString('<div class="sf-summary-html">', $column);
        $this->assertStringContainsString('-20% with code BDAY', $column);
        $this->assertStringContainsString('href="/club"', $column);
        $this->assertSame(['sf-title', 'sf-summary-html', 'sf-add-to-cart-box'], $this->orderOf($column, ['sf-add-to-cart-box', 'sf-summary-html', 'sf-title']));

        foreach (['<script', 'onclick', 'style=', 'javascript:'] as $bad) {
            $this->assertStringNotContainsString($bad, $column, $bad);
        }
    }

    public function test_html_that_was_stored_dirty_in_the_compiled_copy_is_cleaned_again_when_printed(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Club', 'body' => '<p>ok</p>']);
        $this->publish(['title', ['type' => 'html_block', 'settings' => ['html_block' => $block->id]], 'purchase']);

        $document = json_decode((string) $this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY), true);
        $document['zones']['product_summary']['global']['blocks'][(string) $block->id] = '<p onclick="steal()">hi</p><script>alert(1)</script><a href="javascript:evil()">x</a>';
        $this->settings()->set(AppearanceSettings::DESIGN_COMPILED_KEY, json_encode($document));
        \Illuminate\Support\Facades\Cache::forget(\App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY);

        $column = $this->summary($this->page());

        $this->assertStringContainsString('hi', $column);

        foreach (['<script', 'onclick', 'javascript:'] as $bad) {
            $this->assertStringNotContainsString($bad, $column, $bad);
        }
    }

    public function test_an_html_block_that_was_deleted_prints_nothing_and_the_page_still_works(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Club', 'body' => '<p>gone soon</p>']);
        $this->publish(['title', ['type' => 'html_block', 'settings' => ['html_block' => $block->id]], 'purchase']);
        DB::table('html_blocks')->where('id', $block->id)->delete();
        app(ProductSummaryCompiler::class)->refresh();
        $this->app->forgetScopedInstances();

        $column = $this->summary($this->page());

        $this->assertStringNotContainsString('sf-summary-html', $column);
        $this->assertStringContainsString('sf-add-to-cart-box', $column);
    }

    // ---- sessionless, no inline style or script ---------------------------------------------------------------------------------------------

    public function test_the_page_with_a_custom_layout_stays_sessionless_and_has_no_inline_style_or_script(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Club', 'body' => '<p>hello</p>']);
        $this->publish(['price', 'sku', ['type' => 'html_block', 'settings' => ['html_block' => $block->id]], 'title', 'purchase']);
        $this->app->forgetScopedInstances();

        $response = $this->get('/product/summer-dress')->assertOk();
        $html = $response->getContent();

        $this->assertSame([], $response->headers->getCookies(), 'no cookie is set');
        $this->assertStringNotContainsString(' style=', $html);
        $this->assertSame(0, preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $html), 'no inline script');
    }

    // ---- the admin's preview --------------------------------------------------------------------------------------------------------------

    public function test_the_signed_preview_shows_the_draft_on_the_first_product_and_the_public_page_does_not(): void
    {
        DB::table('catalog_products')->where('id', 2)->update(['catalog_visibility' => 'hidden']);
        $this->publish(['title', 'purchase']);
        ProductSummaryDrafts::saveDraft($this->layout(['price', 'title', 'sku', 'purchase']));
        $this->app->forgetScopedInstances();

        $response = $this->get(DesignPreviewController::previewUrl('product_summary'))->assertOk();
        $preview = $this->summary($response->getContent());

        $this->assertSame([self::MARKS['price'], self::MARKS['title'], 'sf-sku', self::MARKS['purchase']], $this->orderOf($preview, [...array_values(self::MARKS), 'sf-sku']));
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $public = $this->summary($this->page());
        $this->assertStringNotContainsString('sf-sku', $public, 'the public page still prints the published layout');
    }

    public function test_the_preview_needs_its_signature_and_an_empty_shop_has_nothing_to_show(): void
    {
        $this->get('/design-preview?zone=product_summary')->assertNotFound();

        DB::table('catalog_products')->update(['catalog_visibility' => 'hidden']);
        $this->app->forgetScopedInstances();

        $this->get(DesignPreviewController::previewUrl('product_summary'))->assertNotFound();
    }
}
