<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\ProductSummary\ProductSummaryCatalog;
use App\Storefront\ProductSummary\ProductSummaryCompiler;
use App\Storefront\ProductSummary\ProductSummaryLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * SKU-1: the `sku` component of the product page's right column — the article number line under the name. A closed choice of its label (`prefix`: `sku` = «SKU:», `art` = «Art.», `art_no` = «Art. №»,
 * default `art`), the PARENT product's SKU only (never a variation's), nothing at all without a SKU, escaped text, and no style attribute or inline script on the page.
 *
 * The default layout is NOT changed: the component is placed by the layout the merchant publishes (here: after the name).
 */
class SkuComponentTest extends TestCase
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
        $this->seedPricing();
        $this->makeAttribute(1, 'Colour', ['Red', 'Blue']);
        $this->makeAttribute(2, 'Size', ['S', 'M']);

        $this->makeProduct(10, ['type' => 'variable', 'name' => 'Shirt', 'slug' => 'shirt', 'variations' => [
            ['attrs' => [1 => 'Red', 2 => 'S'], 'price' => '30.00', 'stock' => 5],
            ['attrs' => [1 => 'Blue', 2 => 'M'], 'price' => '30.00', 'stock' => 5],
        ]]);
        DB::table('catalog_products')->where('id', 10)->update(['base_sku' => '154652']);
        // each variation has its own (unique) SKU: none of them may ever appear in the line of the parent
        DB::table('catalog_variations')->where('product_id', 10)->orderBy('id')->get(['id'])->each(fn ($v, $i) => DB::table('catalog_variations')->where('id', $v->id)->update(['sku' => 'VAR-SHOULD-NOT-SHOW-'.$i]));

        $this->makeProduct(11, ['name' => 'Plain thing', 'slug' => 'plain-thing', 'price' => '9.00', 'stock' => 3]);
        // the column is NOT NULL: «no SKU» is the empty string (the reader turns an empty or blank SKU into none)
        DB::table('catalog_products')->where('id', 11)->update(['base_sku' => '']);
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    /** The default right column with the `sku` component placed after the name; its settings as given. @param array<string, mixed> $settings */
    private function publishSku(array $settings = []): void
    {
        $layout = ProductSummaryLayout::default();
        $at = array_search('title', array_column($layout['components'], 'type'), true);
        array_splice($layout['components'], $at + 1, 0, [['type' => 'sku', 'settings' => $settings]]);

        app(ProductSummaryCompiler::class)->publish($layout, $this->settings());
        $this->app->forgetScopedInstances();
    }

    private function page(string $path): string
    {
        $this->app->forgetScopedInstances();

        return $this->get($path)->assertOk()->getContent();
    }

    /** @return ?string the text of the SKU line (null when the page has none) */
    private function skuLine(string $html): ?string
    {
        preg_match('#<p class="sf-sku">(.*?)</p>#s', $html, $m);

        return isset($m[1]) ? html_entity_decode($m[1], ENT_QUOTES) : null;
    }

    // ---- the label: a closed choice --------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function labels(): array
    {
        return [
            'SKU: (sku)' => [['prefix' => 'sku'], 'SKU: 154652'],
            'Art. (art)' => [['prefix' => 'art'], 'Art. 154652'],
            'Art. № (art_no)' => [['prefix' => 'art_no'], 'Art. № 154652'],
        ];
    }

    /** @param  array<string, mixed>  $settings */
    #[\PHPUnit\Framework\Attributes\DataProvider('labels')]
    public function test_the_line_prints_the_parent_sku_with_the_chosen_label(array $settings, string $expected): void
    {
        $this->publishSku($settings);

        $this->assertSame($expected, $this->skuLine($this->page('/product/shirt')));
    }

    public function test_the_default_label_is_art_when_the_property_is_not_set(): void
    {
        $this->assertSame('art', ProductSummaryCatalog::defaultsFor('sku')['prefix']);

        $this->publishSku([]);

        $this->assertSame('Art. 154652', $this->skuLine($this->page('/product/shirt')));
    }

    public function test_the_line_sits_after_the_name_in_the_published_order(): void
    {
        $this->publishSku(['prefix' => 'sku']);
        $html = $this->page('/product/shirt');

        $this->assertLessThan(strpos($html, 'class="sf-sku"'), strpos($html, 'class="sf-title"'), 'the name comes first');
        $this->assertLessThan(strpos($html, 'class="sf-sku"'), strpos($html, 'class="sf-summary"') ?: strpos($html, 'class="sf-sku"'), 'the line is in the right column, after the name');
    }

    // ---- nothing without a SKU, and never a variation's ----------------------------------------------------------------------------------

    public function test_a_product_without_a_sku_prints_no_sku_element_and_no_empty_line(): void
    {
        $this->publishSku(['prefix' => 'art']);
        $html = $this->page('/product/plain-thing');

        $this->assertStringNotContainsString('sf-sku', $html);
    }

    public function test_a_blank_sku_prints_nothing_either(): void
    {
        DB::table('catalog_products')->where('id', 11)->update(['base_sku' => '   ']);
        $this->publishSku(['prefix' => 'art']);

        $this->assertStringNotContainsString('sf-sku', $this->page('/product/plain-thing'));
    }

    public function test_a_variable_product_prints_the_parent_sku_and_never_a_variation_sku(): void
    {
        $this->publishSku(['prefix' => 'art']);
        $html = $this->page('/product/shirt');

        $this->assertSame('Art. 154652', $this->skuLine($html));
        $this->assertStringNotContainsString('VAR-SHOULD-NOT-SHOW', $html, 'the variations are not printed in the line (the script does not change it either)');
    }

    // ---- escaping and the markup --------------------------------------------------------------------------------------------------------------

    public function test_the_sku_text_is_escaped(): void
    {
        DB::table('catalog_products')->where('id', 10)->update(['base_sku' => 'A<b>"x"']);
        $this->publishSku(['prefix' => 'sku']);
        $html = $this->page('/product/shirt');

        $this->assertStringNotContainsString('<b>"x"', $html);
        $this->assertStringContainsString('SKU: A&lt;b&gt;&quot;x&quot;', $html);
    }

    public function test_the_product_page_has_no_style_attribute_and_no_inline_script(): void
    {
        $this->publishSku(['prefix' => 'art']);
        $html = $this->page('/product/shirt');

        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $html);
    }

    public function test_the_same_page_for_every_visitor(): void
    {
        $this->publishSku(['prefix' => 'art_no']);

        $this->assertSame($this->page('/product/shirt'), $this->page('/product/shirt'));
    }
}
