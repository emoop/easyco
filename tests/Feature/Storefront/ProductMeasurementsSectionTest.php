<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Below\BelowSlots;
use App\Storefront\Below\BelowStore;
use App\Storefront\ProductSummary\ProductSummaryCompiler;
use App\Storefront\ProductSummary\ProductSummaryLayout;
use App\Storefront\Sections\SectionSet;
use App\Storefront\Sections\SectionStore;
use EasyCo\Catalog\Contracts\MeasureRepository;
use EasyCo\Catalog\Contracts\MeasurementTemplateRepository;
use EasyCo\Catalog\Contracts\MeasurementValueRepository;
use EasyCo\Catalog\Contracts\ProductMeasurementTemplateRepository;
use EasyCo\Catalog\Enums\MeasurementTemplateKind;
use EasyCo\Catalog\Measure;
use EasyCo\Catalog\MeasurementTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * MEAS-5: the section source «Таблица с размери» (`measurements`) on the product page: what prints (table for `by_size`, list for `single`), the intersection with the CURRENT template and rows, the dropped empties, archived
 * measures, hidden data, the per-product switch, escaping, the locale, and the statement cost (pinned for «section on, template on»; +0 while no section uses the source).
 *
 * World: a variable shirt (product 1) with a flagged Size attribute S / M / L (value ids 1001-1003), a simple phone (2), a plain product without a template (3), an unflagged-colour shirt (4, axis «Colour»).
 */
class ProductMeasurementsSectionTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const KEY = '77777777-7777-4777-8777-777777777777';

    private const OTHER = '88888888-8888-4888-8888-888888888888';

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop']);
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeCategory(1, 'Dresses', 'dresses');
        $this->makeAttribute(1, 'Size', ['S', 'M', 'L']);
        $this->makeAttribute(2, 'Colour', ['Black', 'White']);
        DB::table('catalog_attribute_definitions')->where('id', 1)->update(['is_size' => true]);

        $this->makeProduct(1, ['type' => 'variable', 'name' => 'Linen shirt', 'slug' => 'shirt', 'categories' => [1], 'variations' => [
            ['attrs' => [1 => 'S'], 'price' => '40.00', 'stock' => 2], ['attrs' => [1 => 'M'], 'price' => '40.00', 'stock' => 2], ['attrs' => [1 => 'L'], 'price' => '40.00', 'stock' => 2],
        ]]);
        DB::table('catalog_product_attributes')->insert(['product_id' => 1, 'attribute_definition_id' => 1, 'is_variation_axis' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->makeProduct(2, ['name' => 'Phone', 'slug' => 'phone', 'categories' => [1], 'price' => '500.00', 'stock' => 3]);
        $this->makeProduct(3, ['name' => 'Plain', 'slug' => 'plain', 'categories' => [1], 'price' => '10.00', 'stock' => 3]);
        $this->makeProduct(4, ['type' => 'variable', 'name' => 'Colour shirt', 'slug' => 'colour-shirt', 'categories' => [1], 'variations' => [
            ['attrs' => [2 => 'Black'], 'price' => '40.00', 'stock' => 2], ['attrs' => [2 => 'White'], 'price' => '40.00', 'stock' => 2],
        ]]);
        DB::table('catalog_product_attributes')->insert(['product_id' => 4, 'attribute_definition_id' => 2, 'is_variation_axis' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ------------------------------------------------------------------------------------------ helpers

    private function visit(string $slug): TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->get('/product/'.$slug);
    }

    private function section(string $key = self::KEY): array
    {
        return ['key' => $key, 'title' => 'Sizes', 'source' => 'measurements'];
    }

    /** @param list<array<string, mixed>> $sections */
    private function publish(array $sections): void
    {
        [$clean, $errors] = SectionSet::validate(['sections' => $sections]);
        $this->assertSame([], $errors, json_encode($errors));
        SectionStore::publish($clean);

        $layout = ProductSummaryLayout::default();
        $layout['components'][] = ['type' => 'accordion', 'settings' => ['first_open' => false]];
        app(ProductSummaryCompiler::class)->publish($layout, app(SiteSettingsRepository::class));
        $this->app->forgetScopedInstances();
    }

    private function measure(string $name, string $unit = 'cm', bool $archived = false): string
    {
        $measure = Measure::create($name.++$this->n, $unit);
        $archived && $measure->archive();
        app(MeasureRepository::class)->save($measure);
        DB::table('catalog_measures')->where('id', $measure->id())->update(['name' => $name]);

        return (string) $measure->id();
    }

    /** @param string[] $measures */
    private function template(string $name, array $measures, MeasurementTemplateKind $kind = MeasurementTemplateKind::BY_SIZE, bool $archived = false): string
    {
        $template = MeasurementTemplate::create($name.++$this->n, $measures, 0, $kind);
        $archived && $template->archive();
        app(MeasurementTemplateRepository::class)->save($template);

        return (string) $template->id();
    }

    private function use(int $product, string $template): void
    {
        app(ProductMeasurementTemplateRepository::class)->assign((string) $product, $template);
    }

    /** @param array<string, array<string, int>> $rows */
    private function store(int $product, array $rows): void
    {
        app(MeasurementValueRepository::class)->saveForProduct((string) $product, $rows);
    }

    /** A shirt with Chest + Waist (cm) and numbers for S and M only (L empty), Waist for M only. */
    private function shirt(): array
    {
        $chest = $this->measure('Chest');
        $waist = $this->measure('Waist');
        $template = $this->template('Tops', [$chest, $waist]);
        $this->use(1, $template);
        $this->store(1, ['1001' => [$chest => 9550], '1002' => [$chest => 10000, $waist => 8025]]);

        return compact('chest', 'waist', 'template');
    }

    private function html(string $slug): string
    {
        return $this->visit($slug)->assertOk()->getContent();
    }

    // ------------------------------------------------------------------------------------------ render

    public function test_a_by_size_template_prints_a_table_in_the_tbl_1_wrapper_with_the_right_order_format_and_units(): void
    {
        $this->shirt();
        $this->publish([$this->section()]);

        $html = $this->html('shirt');

        $this->assertStringContainsString('<summary class="sf-psec-title">Sizes</summary>', $html);
        $this->assertStringContainsString('<div class="sf-table-wrap"><table class="sf-meas"><caption class="sf-sr-only">Size table: Linen shirt</caption>', $html);
        $this->assertStringContainsString('<thead><tr><th scope="col">Size</th><th scope="col">Chest, cm</th><th scope="col">Waist, cm</th></tr></thead>', $html);
        $this->assertStringContainsString('<tr><th scope="row">S</th><td>95.5</td><td></td></tr>', $html, 'en: a dot; an empty cell of a kept column is an empty td');
        $this->assertStringContainsString('<tr><th scope="row">M</th><td>100</td><td>80.25</td></tr>', $html);
        $this->assertStringNotContainsString('<th scope="row">L</th>', $html, 'a size with no number is dropped');
    }

    public function test_the_bg_locale_prints_a_decimal_comma_and_the_localised_unit(): void
    {
        $this->shirt();
        $this->publish([$this->section()]);
        app()->setLocale('bg');

        $html = $this->html('shirt');

        $this->assertStringContainsString('<td>95,5</td>', $html);
        $this->assertStringContainsString('Chest, см', $html);
    }

    public function test_a_single_template_prints_a_definition_list_in_the_templates_order(): void
    {
        $thickness = $this->measure('Thickness', 'mm');
        $screen = $this->measure('Screen', 'in');
        $weight = $this->measure('Weight', 'g');
        $this->use(2, $this->template('Phones', [$thickness, $screen, $weight], MeasurementTemplateKind::SINGLE));
        $this->store(2, ['product' => [$thickness => 780, $screen => 610, $weight => 17200]]);
        $this->publish([$this->section()]);

        $html = $this->html('phone');

        $this->assertStringContainsString('<dl class="sf-meas-list"><div><dt>Thickness</dt><dd>7.8&nbsp;mm</dd></div><div><dt>Screen</dt><dd>6.1&nbsp;in</dd></div><div><dt>Weight</dt><dd>172&nbsp;g</dd></div></dl>', $html);
        $this->assertStringNotContainsString('sf-meas"', $html, 'no table for a single template');
    }

    public function test_a_single_template_on_a_variable_product_is_one_list_not_a_row_per_variant(): void
    {
        $m = $this->measure('Height');
        $this->use(1, $this->template('Whole', [$m], MeasurementTemplateKind::SINGLE));
        $this->store(1, ['product' => [$m => 5950]]);
        $this->publish([$this->section()]);

        $html = $this->html('shirt');

        $this->assertSame(1, substr_count($html, '<dt>Height</dt>'));
        $this->assertStringContainsString('<dd>59.5&nbsp;cm</dd>', $html);
    }

    public function test_nothing_to_print_means_no_section_at_all(): void
    {
        $chest = $this->measure('Chest');
        $this->use(1, $this->template('Tops', [$chest]));     // a template, no numbers
        $this->publish([$this->section()]);

        foreach (['shirt', 'plain', 'phone'] as $slug) {      // no numbers / no template / no template
            $html = $this->html($slug);
            $this->assertStringNotContainsString('>Sizes<', $html, $slug);
            $this->assertStringNotContainsString('sf-meas', $html, $slug);
        }
    }

    public function test_an_archived_measure_is_not_printed_but_its_numbers_stay_and_an_archived_template_still_prints(): void
    {
        $chest = $this->measure('Chest');
        $old = $this->measure('Oldmeasure');
        $template = $this->template('Tops', [$chest, $old], MeasurementTemplateKind::BY_SIZE, true);   // an ARCHIVED template
        $this->use(1, $template);
        $this->store(1, ['1001' => [$chest => 9500, $old => 1234]]);
        $this->publish([$this->section()]);

        $this->assertStringContainsString('Oldmeasure, cm', $this->html('shirt'));

        DB::table('catalog_measures')->where('id', $old)->update(['is_active' => false]);
        $html = $this->html('shirt');

        $this->assertStringContainsString('Chest, cm', $html, 'the archived template prints');
        $this->assertStringNotContainsString('Oldmeasure', $html);
        $this->assertStringNotContainsString('12.34', $html);
        $this->assertSame(1234, app(MeasurementValueRepository::class)->forProduct('1')['1001'][$old], 'the number is still stored');
    }

    public function test_hidden_data_is_never_printed(): void
    {
        $chest = $this->measure('Chest');
        $waist = $this->measure('Waist');
        $tops = $this->template('Tops', [$chest, $waist]);
        $dresses = $this->template('Dresses', [$waist]);
        $whole = $this->template('Whole', [$chest], MeasurementTemplateKind::SINGLE);
        $this->use(1, $tops);
        $this->store(1, ['1001' => [$chest => 9500, $waist => 7000]]);
        $this->publish([$this->section()]);

        // a template that dropped «Chest»: its number is hidden, not printed
        $this->use(1, $dresses);
        $html = $this->html('shirt');
        $this->assertStringContainsString('Waist, cm', $html);
        $this->assertStringNotContainsString('Chest', $html);
        $this->assertStringNotContainsString('95', $html);

        // the other kind: the by_size rows are not the single row
        $this->use(1, $whole);
        $this->assertStringNotContainsString('sf-meas', $this->html('shirt'), 'a single template has no number of its own: nothing prints');

        // a size that left the axis
        $this->use(1, $tops);
        DB::table('catalog_product_axis_values')->where('product_id', 1)->where('attribute_value_id', 1001)->delete();
        $this->assertStringNotContainsString('sf-meas', $this->html('shirt'));
    }

    public function test_the_fallback_axis_prints_as_is(): void
    {
        $m = $this->measure('Chest');
        $this->use(4, $this->template('Tops', [$m]));
        $this->store(4, ['2001' => [$m => 9000]]);
        $this->publish([$this->section()]);

        $html = $this->html('colour-shirt');

        $this->assertStringContainsString('<th scope="col">Colour</th>', $html);
        $this->assertStringContainsString('<tr><th scope="row">Black</th><td>90</td></tr>', $html);
    }

    public function test_the_per_product_switch_hides_the_section(): void
    {
        $this->shirt();
        $this->publish([$this->section(), array_merge($this->section(self::OTHER), ['title' => 'Second'])]);
        DB::table('product_section_texts')->insert(['product_id' => 1, 'section_key' => self::KEY, 'body' => '', 'hidden' => true, 'created_at' => now(), 'updated_at' => now()]);

        $html = $this->html('shirt');

        $this->assertStringNotContainsString('>Sizes<', $html);
        $this->assertStringContainsString('>Second<', $html, 'the other section is not switched off');
    }

    public function test_every_text_is_escaped(): void
    {
        $m = $this->measure('<b>x</b> & "q"');
        DB::table('catalog_attribute_values')->where('id', 1001)->update(['value' => 'S & <i>M</i>']);
        $this->use(1, $this->template('Tops', [$m]));
        $this->store(1, ['1001' => [$m => 9000]]);
        $this->publish([$this->section()]);

        $html = $this->html('shirt');

        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt; &amp; &quot;q&quot;, cm', $html);
        $this->assertStringContainsString('<th scope="row">S &amp; &lt;i&gt;M&lt;/i&gt;</th>', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function test_the_section_also_prints_in_a_tab(): void
    {
        $this->shirt();
        $this->publish([$this->section()]);
        [$clean, $errors] = BelowSlots::validate(['slots' => [['type' => 'tabs', 'heading' => '', 'sections' => [self::KEY]]]]);
        $this->assertSame([], $errors);
        BelowStore::publish($clean);

        $this->assertStringContainsString('sf-ptabs', $this->html('shirt'));
    }

    public function test_the_courier_weight_is_never_printed(): void
    {
        $this->shirt();
        DB::table('catalog_variations')->where('product_id', 1)->update(['weight_grams' => 987654]);
        $this->publish([$this->section()]);

        $this->assertStringNotContainsString('987654', $this->html('shirt'));
    }

    public function test_the_closed_source_list_accepts_the_new_source_and_refuses_a_made_up_one(): void
    {
        $this->assertContains('measurements', SectionSet::SOURCES);
        [, $errors] = SectionSet::validate(['sections' => [['key' => self::KEY, 'title' => 'X', 'source' => 'measurementz']]]);
        $this->assertNotSame([], $errors);
    }

    // ------------------------------------------------------------------------------------------ statements

    private function queries(string $slug): int
    {
        $this->visit($slug);   // warm-up
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $this->visit($slug)->assertOk();

        return $n;
    }

    public function test_off_costs_nothing_and_prints_the_same_page(): void
    {
        $this->shirt();
        $this->publish([['key' => self::KEY, 'title' => 'About', 'source' => 'global', 'text' => '<p>General</p>']]);
        $with = $this->queries('shirt');
        $withHtml = $this->html('shirt');

        // the template and the numbers are removed: the page must cost the same and print the same
        DB::table('catalog_measurement_values')->delete();
        DB::table('catalog_product_measurement_templates')->delete();
        $this->assertSame($with, $this->queries('shirt'), 'no published section uses the source: +0');
        $this->assertSame($withHtml, $this->html('shirt'));
    }

    public function test_the_statement_cost_with_the_source_on(): void
    {
        $this->publish([['key' => self::KEY, 'title' => 'About', 'source' => 'global', 'text' => '<p>General</p>']]);
        $off = ['shirt' => $this->queries('shirt'), 'plain' => $this->queries('plain'), 'phone' => $this->queries('phone')];

        $this->publish([$this->section()]);
        $this->publish([$this->section(), ['key' => self::OTHER, 'title' => 'About', 'source' => 'global', 'text' => '<p>General</p>']]);
        $noTemplate = $this->queries('plain') - $off['plain'];

        $this->shirt();
        $byThree = $this->queries('shirt') - $off['shirt'];

        $m = $this->measure('Height');
        $this->use(2, $this->template('Whole', [$m], MeasurementTemplateKind::SINGLE));
        $this->store(2, ['product' => [$m => 5950]]);
        $single = $this->queries('phone') - $off['phone'];

        fwrite(STDERR, "\n[meas-5 statements] section on: no template +{$noTemplate}, single +{$single}, by_size +{$byThree}\n");

        $this->assertSame(1, $noTemplate, 'no template: the one join');
        $this->assertSame(2, $single, 'single: the join + the numbers');
        $this->assertSame(4, $byThree, 'by_size: the join + the axis with names (2) + the numbers');
    }

    public function test_the_cost_does_not_grow_with_the_grid_or_the_variations(): void
    {
        $this->publish([$this->section()]);
        $small = $this->shirt();
        $threeByTwo = $this->queries('shirt');

        // many more sizes, measures and variations
        $names = [];
        for ($i = 4; $i <= 12; $i++) {
            DB::table('catalog_attribute_values')->insert(['id' => 1000 + $i, 'attribute_definition_id' => 1, 'value' => "X{$i}", 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('catalog_product_axis_values')->insert(['product_id' => 1, 'attribute_definition_id' => 1, 'attribute_value_id' => 1000 + $i, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('catalog_variations')->insert(['id' => 5000 + $i, 'product_id' => 1, 'sort_order' => $i, 'type' => 'standard', 'status' => 'active', 'attribute_signature' => 'sig'.$i, 'sku' => 'XSKU'.$i, 'is_visible' => true, 'is_purchasable' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('catalog_variation_attribute_values')->insert(['variation_id' => 5000 + $i, 'attribute_definition_id' => 1, 'attribute_value_id' => 1000 + $i, 'created_at' => now(), 'updated_at' => now()]);
        }
        $measures = [$small['chest'], $small['waist']];
        for ($i = 0; $i < 10; $i++) {
            $measures[] = $this->measure("Extra{$i}");
        }
        $this->use(1, $this->template('Big', $measures));
        $rows = [];
        for ($size = 1001; $size <= 1012; $size++) {
            foreach ($measures as $measure) {
                $rows[(string) $size][$measure] = 5000;
            }
        }
        $this->store(1, $rows);

        $this->assertSame($threeByTwo, $this->queries('shirt'), 'the same statements for 3 x 2 and 12 x 12');
        $this->assertSame(12 * 12, substr_count($this->html('shirt'), '<td>50</td>'));
    }
}
