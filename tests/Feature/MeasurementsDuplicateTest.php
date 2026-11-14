<?php

namespace Tests\Feature;

use App\Models\ActivityLogModel;
use App\Services\DuplicateProduct;
use App\Services\ProductMeasurementsWriter;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\MeasureRepository;
use EasyCo\Catalog\Contracts\MeasurementTemplateRepository;
use EasyCo\Catalog\Contracts\MeasurementValueRepository;
use EasyCo\Catalog\Contracts\ProductMeasurementTemplateRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\MeasurementTemplateKind;
use EasyCo\Catalog\Measure;
use EasyCo\Catalog\MeasurementTemplate;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Extensibility\Hook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\TestCase;

/**
 * MEAS-5: a product DUPLICATE keeps the specs of its source (the template assignment and EVERY stored number), through one insert-select, with ONE audit entry and ONE hook after the commit. It supersedes the MEAS-2
 * «nothing is copied» decision. A variable duplicate starts without axes, so the copied `by_size` numbers stay hidden until it declares the same sizes.
 */
class MeasurementsDuplicateTest extends TestCase
{
    use GivesProductsAShippingClass;
    use RefreshDatabase;

    private int $n = 0;

    private int $hooks = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->giveProductsAShippingClass();
        app()->setLocale('en');
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
        Hook::action('catalog.product.measurements.saved', function (): void {
            $this->hooks++;
        });
    }

    private function measure(string $name, string $unit = 'cm'): string
    {
        $m = Measure::create($name.++$this->n, $unit);
        app(MeasureRepository::class)->save($m);

        return (string) $m->id();
    }

    private function template(array $measures, MeasurementTemplateKind $kind): string
    {
        $t = MeasurementTemplate::create('T'.++$this->n, $measures, 0, $kind);
        app(MeasurementTemplateRepository::class)->save($t);

        return (string) $t->id();
    }

    private function simple(string $name = 'Phone'): string
    {
        $p = Product::createSimple($name, 'SKU-'.++$this->n, 'p-'.$this->n);
        app(ProductRepository::class)->save($p);

        return (string) $p->id();
    }

    /** @return array{product: string, sizes: string[]} */
    private function shirt(int $sizes): array
    {
        $n = ++$this->n;
        $def = new AttributeDefinition(id: null, code: 'size'.$n, name: 'Size', type: AttributeType::SELECT, isSize: true);
        app(AttributeDefinitionRepository::class)->save($def);
        $values = [];
        for ($i = 1; $i <= $sizes; $i++) {
            $v = new AttributeValue(id: null, attributeDefinitionId: $def->id(), value: "S{$i}", sortOrder: $i);
            app(AttributeValueRepository::class)->save($v);
            $values[] = $v;
        }
        $p = Product::createVariable('Shirt', 'SHIRT-'.$n, 'shirt-'.$n);
        $p->declareVariationAxes([new VariationAxis($def, $values)]);
        foreach ($values as $v) {
            $p->addStandardVariation([$def->id() => $v->id()], 'SHIRT-'.$n.'-'.$v->value());
        }
        app(ProductRepository::class)->save($p);

        return ['product' => (string) $p->id(), 'sizes' => array_map(fn ($v) => (string) $v->id(), $values)];
    }

    private function audit(string $product)
    {
        return ActivityLogModel::query()->where('entity_type', 'product')->where('entity_id', $product)->where('field', 'measurements')->get();
    }

    public function test_a_simple_phone_duplicate_keeps_the_template_and_the_numbers_with_one_audit_entry_and_one_hook(): void
    {
        $thickness = $this->measure('Thickness', 'mm');
        $weight = $this->measure('Weight', 'g');
        $template = $this->template([$thickness, $weight], MeasurementTemplateKind::SINGLE);
        $source = $this->simple();
        app(ProductMeasurementTemplateRepository::class)->assign($source, $template);
        app(MeasurementValueRepository::class)->saveForProduct($source, ['product' => [$thickness => 780, $weight => 17200]]);
        $this->hooks = 0;

        $copy = (string) app(DuplicateProduct::class)->duplicate($source)->id();

        $this->assertSame($template, app(ProductMeasurementTemplateRepository::class)->templateIdFor($copy));
        $this->assertEquals(['product' => [$thickness => 780, $weight => 17200]], app(MeasurementValueRepository::class)->forProduct($copy));
        $this->assertEquals(['product' => [$thickness => 780, $weight => 17200]], app(MeasurementValueRepository::class)->forProduct($source), 'the source is untouched');
        $this->assertSame(1, $this->hooks);
        $this->assertCount(1, $this->audit($copy));
        $this->assertStringStartsWith('copied from SKU-', (string) $this->audit($copy)->first()->new_value);
    }

    public function test_a_variable_shirt_duplicate_keeps_its_by_size_numbers_hidden_until_the_sizes_are_declared(): void
    {
        $chest = $this->measure('Chest');
        $template = $this->template([$chest], MeasurementTemplateKind::BY_SIZE);
        $shirt = $this->shirt(2);
        app(ProductMeasurementTemplateRepository::class)->assign($shirt['product'], $template);
        app(MeasurementValueRepository::class)->saveForProduct($shirt['product'], [$shirt['sizes'][0] => [$chest => 9500], $shirt['sizes'][1] => [$chest => 10000]]);
        $this->hooks = 0;

        $copy = (string) app(DuplicateProduct::class)->duplicate($shirt['product'])->id();

        $this->assertSame($template, app(ProductMeasurementTemplateRepository::class)->templateIdFor($copy));
        $this->assertSame(2, DB::table('catalog_measurement_values')->where('product_id', $copy)->count());
        $this->assertSame([], app(\EasyCo\Catalog\Contracts\SizeAxisResolver::class)->forProduct($copy), 'the duplicate has no axes yet: the numbers are hidden');
        $this->assertSame(1, $this->hooks);
        $this->assertCount(1, $this->audit($copy));
    }

    public function test_a_source_without_measurements_copies_nothing_no_hook_no_audit(): void
    {
        $this->hooks = 0;

        $copy = (string) app(DuplicateProduct::class)->duplicate($this->simple('Plain'))->id();

        $this->assertNull(app(ProductMeasurementTemplateRepository::class)->templateIdFor($copy));
        $this->assertSame(0, DB::table('catalog_measurement_values')->where('product_id', $copy)->count());
        $this->assertSame(0, $this->hooks);
        $this->assertCount(0, $this->audit($copy));
    }

    public function test_the_copy_costs_the_same_statements_for_3_and_144_cells(): void
    {
        $measures = [];
        for ($i = 0; $i < 12; $i++) {
            $measures[] = $this->measure("M{$i}");
        }
        $template = $this->template($measures, MeasurementTemplateKind::BY_SIZE);
        $small = $this->shirt(12);
        $big = $this->shirt(12);
        foreach ([$small, $big] as $shirt) {
            app(ProductMeasurementTemplateRepository::class)->assign($shirt['product'], $template);
        }
        $rows = fn (array $sizes, array $ms) => array_fill_keys($sizes, array_fill_keys($ms, 5000));
        app(MeasurementValueRepository::class)->saveForProduct($small['product'], $rows(array_slice($small['sizes'], 0, 1), array_slice($measures, 0, 3)));
        app(MeasurementValueRepository::class)->saveForProduct($big['product'], $rows($big['sizes'], $measures));

        $targets = [$this->simple('T1'), $this->simple('T2'), $this->simple('T3')];
        $writer = app(ProductMeasurementsWriter::class);
        $writer->copyFromProduct($small['product'], $targets[0], 'warm');   // warm-up

        $count = function (string $from, string $to): int {
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            app(ProductMeasurementsWriter::class)->copyFromProduct($from, $to, 'x');

            return $n;
        };

        $three = $count($small['product'], $targets[1]);
        $many = $count($big['product'], $targets[2]);

        fwrite(STDERR, "\n[meas-5 statements] duplicate copy: {$three} (3 cells) / {$many} (144 cells)\n");
        $this->assertSame($three, $many);
        $this->assertSame(144, DB::table('catalog_measurement_values')->where('product_id', $targets[2])->count());
    }
}
