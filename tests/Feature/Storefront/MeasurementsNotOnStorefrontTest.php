<?php

namespace Tests\Feature\Storefront;

use App\Storefront\Conditions\ProductContext;
use App\Storefront\Reader\CatalogReader;
use EasyCo\Catalog\Contracts\MeasureRepository;
use EasyCo\Catalog\Contracts\MeasurementTemplateRepository;
use EasyCo\Catalog\Contracts\MeasurementValueRepository;
use EasyCo\Catalog\Contracts\ProductMeasurementTemplateRepository;
use EasyCo\Catalog\Measure;
use EasyCo\Catalog\MeasurementTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * MEAS-2: nothing reads the measurement tables yet (the storefront section is MEAS-5). A product that HAS a template and stored numbers prints exactly what it printed without them: no measure name,
 * no number, no measurement markup, in the page, the home page or the read models' JSON contract.
 */
class MeasurementsNotOnStorefrontTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const NAME = 'Zqxv Bust Circumference';

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeAttribute(1, 'Size', ['S', 'M']);
        $this->makeProduct(1, [
            'type' => 'variable', 'name' => 'Linen shirt', 'slug' => 'linen-shirt',
            'variations' => [['attrs' => [1 => 'S'], 'price' => '40.00', 'stock' => 2], ['attrs' => [1 => 'M'], 'price' => '45.00', 'stock' => 3]],
        ]);

        // the fixture helper writes the product's axis VALUES but not the axis declaration row: add it, and flag the attribute as the size
        DB::table('catalog_attribute_definitions')->where('id', 1)->update(['is_size' => true]);
        DB::table('catalog_product_attributes')->insert(['product_id' => 1, 'attribute_definition_id' => 1, 'is_variation_axis' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $sizeIds = DB::table('catalog_attribute_values')->where('attribute_definition_id', 1)->pluck('id')->all();

        $measure = Measure::create(self::NAME, 'cm');
        app(MeasureRepository::class)->save($measure);
        $template = MeasurementTemplate::create('Zqxv Template', [(string) $measure->id()]);
        app(MeasurementTemplateRepository::class)->save($template);
        app(ProductMeasurementTemplateRepository::class)->assign('1', (string) $template->id());
        app(MeasurementValueRepository::class)->saveForProduct('1', [(string) $sizeIds[0] => [(string) $measure->id() => 987654]]);

        $this->assertSame(1, DB::table('catalog_measurement_values')->count(), 'the fixture really stored a number');

        ProductContext::forgetShared();
        $this->app->forgetScopedInstances();
    }

    private function assertNoMeasurements(string $text, string $where): void
    {
        foreach (['Zqxv', '9876,54', '9876.54', '987654', '9 876', 'catalog_measure', 'measurement', 'measures', 'value_hundredths'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $text, "{$where} leaks «{$needle}»");
        }
    }

    public function test_the_product_page_and_the_home_page_print_no_measurement_markup(): void
    {
        foreach (['/product/linen-shirt', '/'] as $url) {
            $this->app->forgetScopedInstances();
            $this->assertNoMeasurements($this->get($url)->assertOk()->getContent(), $url);
        }
    }

    public function test_the_read_models_json_contract_carries_no_measurements(): void
    {
        $page = app(CatalogReader::class)->product('linen-shirt');

        $this->assertNotNull($page);
        $this->assertNoMeasurements(json_encode($page->toArray(), JSON_UNESCAPED_UNICODE), 'ProductPage::toArray()');
    }
}
