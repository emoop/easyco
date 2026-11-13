<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Conditions\ProductContext;
use App\Storefront\ReadModels\ListingQuery;
use App\Storefront\Reader\CatalogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * MEAS-1 section C: the variation weight is for the courier only and is NEVER read or printed by the storefront. Every
 * variation here carries the distinctive weight 98765 g; the product page (simple and variable), the home page / listing
 * cards, and the read models' JSON contract (toArray) must not contain the value, the key or the formatted kilograms.
 */
class WeightNeverOnStorefrontTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const GRAMS = 98765;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeAttribute(1, 'Size', ['S', 'M']);
        $this->makeProduct(1, ['name' => 'Summer dress', 'slug' => 'summer-dress', 'price' => '100.00', 'stock' => 4]);
        $this->makeProduct(2, [
            'type' => 'variable', 'name' => 'Linen shirt', 'slug' => 'linen-shirt',
            'variations' => [['attrs' => [1 => 'S'], 'price' => '40.00', 'stock' => 2], ['attrs' => [1 => 'M'], 'price' => '45.00', 'stock' => 3]],
        ]);
        DB::table('catalog_variations')->update(['weight_grams' => self::GRAMS, 'length_mm' => 111, 'width_mm' => 222, 'height_mm' => 333]);
        ProductContext::forgetShared();
        $this->app->forgetScopedInstances();
    }

    /** the weight, however it could be spelled out, must not be in the text */
    private function assertNoWeight(string $text, string $where): void
    {
        foreach (['98765', '98,765', '98 765', '98.765', '98,76', '98.76', 'weight_grams', 'weightGrams', 'unit_weight', 'parcel_weight'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $text, "{$where} leaks «{$needle}»");
        }
    }

    public function test_the_product_pages_and_the_home_page_print_no_weight(): void
    {
        foreach (['/product/summer-dress', '/product/linen-shirt', '/'] as $url) {
            $this->app->forgetScopedInstances();
            $this->assertNoWeight($this->get($url)->assertOk()->getContent(), $url);
        }
    }

    public function test_the_json_contract_of_the_read_models_carries_no_weight(): void
    {
        $reader = app(CatalogReader::class);

        foreach (['summer-dress', 'linen-shirt'] as $slug) {
            $page = $reader->product($slug);
            $this->assertNotNull($page);
            $json = json_encode($page->toArray(), JSON_UNESCAPED_UNICODE);
            $this->assertNoWeight($json, "ProductPage::toArray() of {$slug}");
            $this->assertStringNotContainsString('_mm', $json);
        }

        $list = $reader->listing(ListingQuery::fromRequestArray(['per_page' => '24']));
        $this->assertNotEmpty($list->items);
        $this->assertNoWeight(json_encode($list->toArray(), JSON_UNESCAPED_UNICODE), 'the listing JSON');
    }
}
