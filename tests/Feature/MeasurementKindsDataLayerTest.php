<?php

namespace Tests\Feature;

use App\Models\ActivityLogModel;
use App\Services\MeasurementTemplateWriter;
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
use EasyCo\Catalog\Exceptions\InvalidMeasurementValueException;
use EasyCo\Catalog\Exceptions\MeasurementRefusedException;
use EasyCo\Catalog\Measure;
use EasyCo\Catalog\MeasurementTemplate;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Services\MeasurementRows;
use EasyCo\Catalog\VariationAxis;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MEAS-4 section 0: the two template KINDS («По размер» / «Общи размери»), the units g / kg, the nullable single row with its generated `row_slot` unique, PRODUCT_ROW, `kind_locked`,
 * `single_row_only` and MeasurementRows. The MEAS-2 behaviour of `by_size` is covered unchanged by MeasurementDataLayerTest.
 */
class MeasurementKindsDataLayerTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function values(): MeasurementValueRepository
    {
        return app(MeasurementValueRepository::class);
    }

    private function templates(): MeasurementTemplateRepository
    {
        return app(MeasurementTemplateRepository::class);
    }

    private function assignments(): ProductMeasurementTemplateRepository
    {
        return app(ProductMeasurementTemplateRepository::class);
    }

    private function measure(string $name, string $unit = 'cm'): string
    {
        $measure = Measure::create($name, $unit);
        app(MeasureRepository::class)->save($measure);

        return (string) $measure->id();
    }

    private function template(string $name, array $measureIds, MeasurementTemplateKind $kind): string
    {
        $template = MeasurementTemplate::create($name, $measureIds, 0, $kind);
        $this->templates()->save($template);

        return (string) $template->id();
    }

    /**
     * A variable product with a colour axis and, optionally, a flagged size axis.
     *
     * @return array{product: string, colours: string[], sizes: string[]}
     */
    private function variable(bool $withSize = false): array
    {
        $n = ++$this->n;
        $colour = new AttributeDefinition(id: null, code: "colour{$n}", name: 'Colour', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($colour);
        $colours = [];
        foreach (['Black', 'White', 'Red'] as $i => $name) {
            $v = new AttributeValue(id: null, attributeDefinitionId: $colour->id(), value: $name, sortOrder: $i);
            app(AttributeValueRepository::class)->save($v);
            $colours[] = $v;
        }
        $axes = [new VariationAxis($colour, $colours)];
        $sizes = [];
        $sizeDefinition = null;

        if ($withSize) {
            $sizeDefinition = new AttributeDefinition(id: null, code: "size{$n}", name: 'Size', type: AttributeType::SELECT, isSize: true);
            app(AttributeDefinitionRepository::class)->save($sizeDefinition);
            foreach (['S', 'M'] as $i => $name) {
                $v = new AttributeValue(id: null, attributeDefinitionId: $sizeDefinition->id(), value: $name, sortOrder: $i);
                app(AttributeValueRepository::class)->save($v);
                $sizes[] = $v;
            }
            $axes[] = new VariationAxis($sizeDefinition, $sizes);
        }

        $product = Product::createVariable("Phone {$n}", "PH-{$n}", "phone-{$n}");
        $product->declareVariationAxes($axes);
        $i = 0;
        foreach ($colours as $c) {
            foreach ($withSize ? $sizes : [null] as $s) {
                $combo = [$colour->id() => $c->id()] + ($s !== null ? [$sizeDefinition->id() => $s->id()] : []);
                $product->addStandardVariation($combo, "PH-{$n}-".$i++);
            }
        }
        app(ProductRepository::class)->save($product);

        return [
            'product' => (string) $product->id(),
            'colours' => array_map(fn ($v) => (string) $v->id(), $colours),
            'sizes' => array_map(fn ($v) => (string) $v->id(), $sizes),
        ];
    }

    private function simple(): string
    {
        $n = ++$this->n;
        $product = Product::createSimple("Simple {$n}", "SM-{$n}", "simple-{$n}");
        app(ProductRepository::class)->save($product);

        return (string) $product->id();
    }

    public function test_grams_and_kilograms_are_units_and_the_closed_list_stays_closed(): void
    {
        foreach (['g', 'kg'] as $unit) {
            $measure = Measure::create("Weight {$unit}", $unit);
            app(MeasureRepository::class)->save($measure);
            $this->assertSame($unit, app(MeasureRepository::class)->findById((string) $measure->id())->unit()->value);
        }

        $this->expectException(InvalidMeasurementValueException::class);
        Measure::create('Stone', 'st');
    }

    public function test_the_kind_defaults_to_by_size_round_trips_and_changes_while_no_number_exists(): void
    {
        $m = $this->measure('Thickness', 'mm');
        $default = MeasurementTemplate::create('Plain', [$m]);
        $this->templates()->save($default);
        $this->assertSame(MeasurementTemplateKind::BY_SIZE, $this->templates()->findById((string) $default->id())->kind());
        $this->assertSame('by_size', DB::table('catalog_measurement_templates')->where('id', $default->id())->value('kind'));

        $single = $this->template('Phones', [$m], MeasurementTemplateKind::SINGLE);
        $this->assertSame(MeasurementTemplateKind::SINGLE, $this->templates()->findById($single)->kind());
        $listed = array_values(array_filter($this->templates()->all(), fn ($t) => $t->id() === $single));
        $this->assertSame(MeasurementTemplateKind::SINGLE, $listed[0]->kind());

        // a product uses it but holds no number yet: the kind may change
        $product = $this->simple();
        $this->assignments()->assign($product, $single);
        $template = $this->templates()->findById($single);
        $template->changeKind(MeasurementTemplateKind::BY_SIZE);
        $this->templates()->save($template);
        $this->assertSame(MeasurementTemplateKind::BY_SIZE, $this->templates()->findById($single)->kind());
    }

    public function test_the_kind_is_locked_once_a_product_using_the_template_has_a_number(): void
    {
        $m = $this->measure('Thickness', 'mm');
        $single = $this->template('Phones', [$m], MeasurementTemplateKind::SINGLE);
        $product = $this->simple();
        $this->assignments()->assign($product, $single);
        $this->values()->saveForProduct($product, [MeasurementValueRepository::PRODUCT_ROW => [$m => 780]]);

        $template = $this->templates()->findById($single);
        $template->changeKind(MeasurementTemplateKind::BY_SIZE);

        try {
            $this->templates()->save($template);
            $this->fail('kind_locked expected');
        } catch (MeasurementRefusedException $e) {
            $this->assertSame('kind_locked', $e->reason);
        }
        $this->assertSame('single', DB::table('catalog_measurement_templates')->where('id', $single)->value('kind'), 'nothing written');

        // a rename with the SAME kind is not a kind change
        $same = $this->templates()->findById($single);
        $same->rename('Phones 2');
        $this->templates()->save($same);

        // the number is removed: unlocked again
        $this->values()->saveForProduct($product, [MeasurementValueRepository::PRODUCT_ROW => [$m => null]]);
        $this->templates()->save($template);
        $this->assertSame('by_size', DB::table('catalog_measurement_templates')->where('id', $single)->value('kind'));
    }

    public function test_the_database_itself_refuses_a_second_single_row_cell_and_the_generated_slot_is_stored(): void
    {
        $m = $this->measure('Thickness', 'mm');
        $single = $this->template('Phones', [$m], MeasurementTemplateKind::SINGLE);
        $product = $this->simple();
        $this->assignments()->assign($product, $single);
        $this->values()->saveForProduct($product, [MeasurementValueRepository::PRODUCT_ROW => [$m => 780]]);

        $row = DB::table('catalog_measurement_values')->where('product_id', $product)->first();
        $this->assertNull($row->size_value_id);
        $this->assertSame(0, (int) $row->row_slot);

        try {
            DB::table('catalog_measurement_values')->insert(['product_id' => $product, 'size_value_id' => null, 'measure_id' => $m, 'value_hundredths' => 900, 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('the unique constraint must refuse the second NULL-row cell');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0]);
            $this->assertContains((int) $e->errorInfo[1], [1062, 19]);
            $this->assertStringContainsString('cmv_cell_slot_unique', (string) $e->errorInfo[2]);
        }

        $create = (array) DB::selectOne('SHOW CREATE TABLE catalog_measurement_values');
        $create = (string) ($create['Create Table'] ?? '');
        $this->assertStringContainsString('`row_slot`', $create);
        $this->assertStringContainsString('STORED', $create);
        $this->assertStringContainsString('cmv_cell_slot_unique', $create);
        $this->assertStringNotContainsString('`cmv_cell_unique`', $create);
        $this->assertMatchesRegularExpression('/`size_value_id` bigint(\(20\))? unsigned DEFAULT NULL/', $create);
        $this->assertStringContainsString('cmv_size_value_fk', $create, 'the FK on size_value_id stays');
    }

    public function test_a_single_template_stores_one_row_for_the_whole_product_even_with_variants_and_reads_it_back(): void
    {
        $thickness = $this->measure('Thickness', 'mm');
        $weight = $this->measure('Weight', 'g');
        $single = $this->template('Phones', [$thickness, $weight], MeasurementTemplateKind::SINGLE);
        $phone = $this->variable();
        $this->assignments()->assign($phone['product'], $single);

        $row = MeasurementValueRepository::PRODUCT_ROW;
        $this->values()->saveForProduct($phone['product'], [$row => [$thickness => 780, $weight => 17200]]);

        $this->assertEquals([$row => [$thickness => 780, $weight => 17200]], $this->values()->forProduct($phone['product']));
        $this->assertSame(2, DB::table('catalog_measurement_values')->where('product_id', $phone['product'])->count(), 'three colours, still one row');

        // update + removal inside the one row
        $this->values()->saveForProduct($phone['product'], [$row => [$thickness => 790]]);
        $this->assertEquals([$row => [$thickness => 790]], $this->values()->forProduct($phone['product']));
    }

    public function test_a_single_template_accepts_only_the_product_row_and_by_size_never_accepts_it(): void
    {
        $m = $this->measure('Thickness', 'mm');
        $single = $this->template('Phones', [$m], MeasurementTemplateKind::SINGLE);
        $bySize = $this->template('Shirts', [$m], MeasurementTemplateKind::BY_SIZE);
        $shirt = $this->variable(true);

        $this->assignments()->assign($shirt['product'], $single);
        foreach ([$shirt['sizes'][0], $shirt['colours'][0], '999'] as $key) {
            try {
                $this->values()->saveForProduct($shirt['product'], [$key => [$m => 100]]);
                $this->fail('single_row_only expected');
            } catch (InvalidMeasurementValueException $e) {
                $this->assertSame('single_row_only', $e->reason);
            }
        }
        $this->assertSame([], $this->values()->forProduct($shirt['product']));

        $this->assignments()->assign($shirt['product'], $bySize);
        try {
            $this->values()->saveForProduct($shirt['product'], [MeasurementValueRepository::PRODUCT_ROW => [$m => 100]]);
            $this->fail('size_value_not_on_axis expected');
        } catch (InvalidMeasurementValueException $e) {
            $this->assertSame('size_value_not_on_axis', $e->reason);
        }
        $this->assertSame([], $this->values()->forProduct($shirt['product']));
    }

    public function test_switching_kinds_keeps_the_other_kinds_numbers_hidden_and_brings_them_back(): void
    {
        $m = $this->measure('Chest');
        $bySize = $this->template('Shirts', [$m], MeasurementTemplateKind::BY_SIZE);
        $single = $this->template('Whole', [$m], MeasurementTemplateKind::SINGLE);
        $shirt = $this->variable(true);
        $row = MeasurementValueRepository::PRODUCT_ROW;

        $this->assignments()->assign($shirt['product'], $bySize);
        $this->values()->saveForProduct($shirt['product'], [$shirt['sizes'][0] => [$m => 9500], $shirt['sizes'][1] => [$m => 10000]]);

        $this->assignments()->assign($shirt['product'], $single);
        $this->values()->saveForProduct($shirt['product'], [$row => [$m => 5000]]);
        // the by_size cells are outside the single grid: kept
        $this->assertSame(3, DB::table('catalog_measurement_values')->where('product_id', $shirt['product'])->count());

        $this->values()->saveForProduct($shirt['product'], [$row => []]);
        $this->assertSame(2, DB::table('catalog_measurement_values')->where('product_id', $shirt['product'])->count(), 'only the single row cell is removed');

        $this->assignments()->assign($shirt['product'], $bySize);
        $this->assertSame(9500, $this->values()->forProduct($shirt['product'])[$shirt['sizes'][0]][$m]);

        // the other way round: a by_size save leaves the single row alone
        $this->assignments()->assign($shirt['product'], $single);
        $this->values()->saveForProduct($shirt['product'], [$row => [$m => 5100]]);
        $this->assignments()->assign($shirt['product'], $bySize);
        $this->values()->saveForProduct($shirt['product'], []);
        $this->assertSame(5100, $this->values()->forProduct($shirt['product'])[$row][$m], 'the single row is outside the by_size grid: kept');
        $this->assertArrayNotHasKey($shirt['sizes'][0], $this->values()->forProduct($shirt['product']), 'the by_size cells were inside it: removed by the empty save');
    }

    public function test_measurement_rows_gives_the_product_row_for_single_and_the_axis_for_by_size(): void
    {
        $rows = app(MeasurementRows::class);
        $phone = $this->variable();
        $shirt = $this->variable(true);
        $simple = $this->simple();

        $this->assertSame(['product'], $rows->forProduct($phone['product'], MeasurementTemplateKind::SINGLE));
        $this->assertSame(['product'], $rows->forProduct($simple, MeasurementTemplateKind::SINGLE));
        $this->assertSame($shirt['sizes'], $rows->forProduct($shirt['product'], MeasurementTemplateKind::BY_SIZE));
        $this->assertSame($phone['colours'], $rows->forProduct($phone['product'], MeasurementTemplateKind::BY_SIZE), 'the fallback axis: the colour');
        $this->assertSame([], $rows->forProduct($simple, MeasurementTemplateKind::BY_SIZE), 'a simple product has no size rows');
    }

    public function test_the_template_writer_audits_a_kind_change(): void
    {
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');

        $m = $this->measure('Thickness', 'mm');
        $id = $this->template('Phones', [$m], MeasurementTemplateKind::BY_SIZE);
        $template = $this->templates()->findById($id);
        $template->changeKind(MeasurementTemplateKind::SINGLE);
        app(MeasurementTemplateWriter::class)->save($template);

        $entry = ActivityLogModel::query()->where('entity_type', 'measurement_template')->where('entity_id', $id)->where('field', 'kind')->first();
        $this->assertNotNull($entry);
        $this->assertSame('by_size', $entry->old_value);
        $this->assertSame('single', $entry->new_value);
    }
}
