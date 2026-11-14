<?php

namespace Tests\Feature;

use App\Services\MeasureWriter;
use App\Services\MeasurementTemplateWriter;
use App\Services\ProductMeasurementsWriter;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\MeasureRepository;
use EasyCo\Catalog\Contracts\MeasurementTemplateRepository;
use EasyCo\Catalog\Contracts\MeasurementValueRepository;
use EasyCo\Catalog\Contracts\ProductMeasurementTemplateRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\SizeAxisResolver;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Exceptions\InvalidMeasurementValueException;
use EasyCo\Catalog\Exceptions\MeasurementRefusedException;
use EasyCo\Catalog\Measure;
use EasyCo\Catalog\MeasurementTemplate;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Extensibility\Hook;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * MEAS-2: the data layer of per-size measurements - every repository operation, the refusals, the grid replace, the database constraints, the size-axis resolver (rule + statement counts), the
 * `is_size` flag and the hooks (after commit, nothing on a refusal, a throwing listener cannot undo the write). No UI exists yet.
 */
class MeasurementDataLayerTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    /** @var list<array{0: string, 1: array<int, mixed>, 2: int}> */
    private array $hookCalls = [];

    private function measures(): MeasureRepository
    {
        return app(MeasureRepository::class);
    }

    private function templates(): MeasurementTemplateRepository
    {
        return app(MeasurementTemplateRepository::class);
    }

    private function assignments(): ProductMeasurementTemplateRepository
    {
        return app(ProductMeasurementTemplateRepository::class);
    }

    private function values(): MeasurementValueRepository
    {
        return app(MeasurementValueRepository::class);
    }

    private function resolver(): SizeAxisResolver
    {
        return app(SizeAxisResolver::class);
    }

    private function measure(string $name, string $unit = 'cm', int $sort = 0): Measure
    {
        $measure = Measure::create($name, $unit, $sort);
        $this->measures()->save($measure);

        return $measure;
    }

    /** @param Measure[] $measures */
    private function template(string $name, array $measures): MeasurementTemplate
    {
        $template = MeasurementTemplate::create($name, array_map(fn (Measure $m) => (string) $m->id(), $measures));
        $this->templates()->save($template);

        return $template;
    }

    private function definition(string $code, bool $isSize = false): AttributeDefinition
    {
        $definition = new AttributeDefinition(id: null, code: $code, name: ucfirst($code), type: AttributeType::SELECT, isSize: $isSize);
        app(AttributeDefinitionRepository::class)->save($definition);

        return $definition;
    }

    /** @return AttributeValue[] in the given order (sortOrder = position) */
    private function attributeValues(AttributeDefinition $definition, array $names): array
    {
        $out = [];

        foreach (array_values($names) as $position => $name) {
            $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: $name, sortOrder: $position);
            app(AttributeValueRepository::class)->save($value);
            $out[] = $value;
        }

        return $out;
    }

    /**
     * A VARIABLE product with the given axes ([definition, values] pairs, declared in this order) and one variation per combination.
     *
     * @param  list<array{0: AttributeDefinition, 1: AttributeValue[]}>  $axes
     */
    private function variableProduct(array $axes): string
    {
        $n = ++$this->counter;
        $product = Product::createVariable("Shirt {$n}", "SKU-{$n}", "shirt-{$n}");
        $product->declareVariationAxes(array_map(fn (array $axis) => new VariationAxis($axis[0], $axis[1]), $axes));

        $combinations = [[]];
        foreach ($axes as [$definition, $values]) {
            $next = [];
            foreach ($combinations as $combination) {
                foreach ($values as $value) {
                    $next[] = $combination + [$definition->id() => $value->id()];
                }
            }
            $combinations = $next;
        }

        foreach ($combinations as $i => $combination) {
            $product->addStandardVariation($combination, "SKU-{$n}-{$i}");
        }

        app(ProductRepository::class)->save($product);

        return (string) $product->id();
    }

    private function simpleProduct(): string
    {
        $n = ++$this->counter;
        $product = Product::createSimple("Simple {$n}", "SIMPLE-{$n}", "simple-{$n}");
        app(ProductRepository::class)->save($product);

        return (string) $product->id();
    }

    /**
     * A product with a flagged size axis of $sizes sizes (plus a colour axis of 2), a template of $measureCount measures, assigned.
     *
     * @return array{product: string, sizes: string[], measures: string[], template: string}
     */
    private function gridProduct(int $sizes = 3, int $measureCount = 2): array
    {
        $size = $this->definition('size'.++$this->counter, true);
        $colour = $this->definition('colour'.$this->counter);
        $sizeValues = $this->attributeValues($size, array_map(fn (int $i) => "S{$i}", range(1, $sizes)));
        $colourValues = $this->attributeValues($colour, ['Black', 'White']);
        $productId = $this->variableProduct([[$colour, $colourValues], [$size, $sizeValues]]);

        $measures = [];
        for ($i = 1; $i <= $measureCount; $i++) {
            $measures[] = $this->measure("Measure {$this->counter}-{$i}");
        }
        $template = $this->template("Template {$this->counter}", $measures);
        $this->assignments()->assign($productId, (string) $template->id());

        return [
            'product' => $productId,
            'sizes' => array_map(fn (AttributeValue $v) => (string) $v->id(), $sizeValues),
            'measures' => array_map(fn (Measure $m) => (string) $m->id(), $measures),
            'template' => (string) $template->id(),
        ];
    }

    private function countStatements(callable $work): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $work();

        return $count;
    }

    private function refusedWith(string $class, string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail("expected {$class} ({$reason})");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e, $e->getMessage());
            $this->assertSame($reason, $e->reason);
        }
    }

    // ---------------------------------------------------------------- measures

    public function test_a_measure_is_created_found_renamed_resorted_and_archived(): void
    {
        $measure = $this->measure('Chest', 'cm', 5);
        $id = (string) $measure->id();

        $found = $this->measures()->findById($id);
        $this->assertSame('Chest', $found->name());
        $this->assertSame('cm', $found->unit()->value);
        $this->assertSame(5, $found->sort());
        $this->assertTrue($found->isActive());

        $found->rename('Bust');
        $found->changeSort(1);
        $this->measures()->save($found);
        $reloaded = $this->measures()->findById($id);
        $this->assertSame('Bust', $reloaded->name());
        $this->assertSame(1, $reloaded->sort());

        $reloaded->archive();
        $this->measures()->save($reloaded);
        $this->assertFalse($this->measures()->findById($id)->isActive());

        $reloaded->unarchive();
        $this->measures()->save($reloaded);
        $this->assertTrue($this->measures()->findById($id)->isActive());

        $this->assertNull($this->measures()->findById('999999'));
    }

    public function test_all_and_active_are_ordered_by_sort_then_id_and_active_hides_the_archived(): void
    {
        $c = $this->measure('C', 'cm', 2);
        $a = $this->measure('A', 'cm', 1);
        $b = $this->measure('B', 'cm', 1);
        $b->archive();
        $this->measures()->save($b);

        $this->assertSame(['A', 'B', 'C'], array_map(fn (Measure $m) => $m->name(), $this->measures()->all()));
        $this->assertSame(['A', 'C'], array_map(fn (Measure $m) => $m->name(), $this->measures()->active()));
    }

    public function test_a_duplicate_measure_name_is_refused_as_a_typed_exception(): void
    {
        $this->measure('Chest');

        $this->refusedWith(MeasurementRefusedException::class, 'name_taken', fn () => $this->measure('Chest', 'in'));
        $this->assertCount(1, $this->measures()->all());
    }

    public function test_the_unit_can_change_until_a_number_exists_then_it_is_locked(): void
    {
        $grid = $this->gridProduct();
        $measureId = $grid['measures'][0];

        $measure = $this->measures()->findById($measureId);
        $measure->changeUnit('in');
        $this->measures()->save($measure);
        $this->assertSame('in', $this->measures()->findById($measureId)->unit()->value);

        $this->values()->saveForProduct($grid['product'], [$grid['sizes'][0] => [$measureId => 3600]]);

        $measure->changeUnit('mm');
        $this->refusedWith(MeasurementRefusedException::class, 'unit_locked', fn () => $this->measures()->save($measure));
        $this->assertSame('in', $this->measures()->findById($measureId)->unit()->value);

        // a rename (the same unit) is still fine while numbers exist
        $same = $this->measures()->findById($measureId);
        $same->rename('Renamed');
        $this->measures()->save($same);
        $this->assertSame('Renamed', $this->measures()->findById($measureId)->name());
    }

    public function test_an_unused_measure_is_deleted_but_a_used_one_is_refused_and_archiving_keeps_the_numbers(): void
    {
        $lonely = $this->measure('Lonely');
        $this->measures()->delete((string) $lonely->id());
        $this->assertNull($this->measures()->findById((string) $lonely->id()));

        $grid = $this->gridProduct();
        $inTemplate = $grid['measures'][0];
        $this->refusedWith(MeasurementRefusedException::class, 'measure_in_use', fn () => $this->measures()->delete($inTemplate));
        $this->assertNotNull($this->measures()->findById($inTemplate));

        // used only by a number (the template no longer lists it)
        $this->values()->saveForProduct($grid['product'], [$grid['sizes'][0] => [$inTemplate => 5000]]);
        $template = $this->templates()->findById($grid['template']);
        $template->setMeasureIds([$grid['measures'][1]]);
        $this->templates()->save($template);
        $this->assertSame(['templates' => 0, 'values' => 1], $this->measures()->usage($inTemplate));
        $this->refusedWith(MeasurementRefusedException::class, 'measure_in_use', fn () => $this->measures()->delete($inTemplate));

        $measure = $this->measures()->findById($inTemplate);
        $measure->archive();
        $this->measures()->save($measure);
        $this->assertSame([$grid['sizes'][0] => [$inTemplate => 5000]], $this->values()->forProduct($grid['product']));
    }

    // --------------------------------------------------------------- templates

    public function test_a_template_keeps_its_ordered_measures_and_reorders_them(): void
    {
        $a = $this->measure('A');
        $b = $this->measure('B');
        $c = $this->measure('C');
        $ids = fn (Measure ...$ms) => array_map(fn (Measure $m) => (string) $m->id(), $ms);

        $template = $this->template('Blouses', [$c, $a, $b]);
        $this->assertSame($ids($c, $a, $b), $this->templates()->findById((string) $template->id())->measureIds());

        $template->setMeasureIds($ids($b, $c));
        $template->rename('Shirts');
        $this->templates()->save($template);

        $reloaded = $this->templates()->findById((string) $template->id());
        $this->assertSame('Shirts', $reloaded->name());
        $this->assertSame($ids($b, $c), $reloaded->measureIds());
    }

    public function test_all_templates_are_listed_in_order_with_their_measures_in_two_statements(): void
    {
        $m1 = $this->measure('M1');
        $m2 = $this->measure('M2');
        $second = MeasurementTemplate::create('Second', [(string) $m2->id()], 2);
        $first = MeasurementTemplate::create('First', [(string) $m2->id(), (string) $m1->id()], 1);
        $this->templates()->save($second);
        $this->templates()->save($first);

        $listed = [];
        $statements = $this->countStatements(function () use (&$listed): void {
            $listed = $this->templates()->all();
        });

        $this->assertSame(2, $statements);
        $this->assertSame(['First', 'Second'], array_map(fn (MeasurementTemplate $t) => $t->name(), $listed));
        $this->assertSame([(string) $m2->id(), (string) $m1->id()], $listed[0]->measureIds());
    }

    public function test_a_template_refuses_an_unknown_measure_and_a_taken_name_and_writes_nothing(): void
    {
        $m = $this->measure('M');
        $this->template('Taken', [$m]);

        $this->refusedWith(InvalidMeasurementValueException::class, 'unknown_reference', fn () => $this->templates()->save(MeasurementTemplate::create('Ghost', ['999999'])));
        $this->assertCount(1, $this->templates()->all(), 'the template row was rolled back with the refused list');

        $this->refusedWith(MeasurementRefusedException::class, 'name_taken', fn () => $this->templates()->save(MeasurementTemplate::create('Taken', [])));
        $this->assertCount(1, $this->templates()->all());
    }

    public function test_a_template_is_archived_and_deleted_only_while_no_product_uses_it(): void
    {
        $grid = $this->gridProduct();

        $template = $this->templates()->findById($grid['template']);
        $template->archive();
        $this->templates()->save($template);
        $this->assertFalse($this->templates()->findById($grid['template'])->isActive());

        $this->assertSame(1, $this->templates()->countProductsUsing($grid['template']));
        $this->refusedWith(MeasurementRefusedException::class, 'template_in_use', fn () => $this->templates()->delete($grid['template']));
        $this->assertNotNull($this->templates()->findById($grid['template']));

        $this->assignments()->clear($grid['product']);
        $this->templates()->delete($grid['template']);
        $this->assertNull($this->templates()->findById($grid['template']));
        $this->assertSame(0, DB::table('catalog_measurement_template_measures')->where('template_id', $grid['template'])->count(), 'the list cascades');
        $this->assertNotNull($this->measures()->findById($grid['measures'][0]), 'the measures stay');
    }

    // ------------------------------------------------------------- assignment

    public function test_a_template_is_assigned_switched_and_cleared_and_numbers_survive_a_switch(): void
    {
        $grid = $this->gridProduct();
        $product = $grid['product'];

        $this->assertSame($grid['template'], $this->assignments()->templateIdFor($product));
        $this->values()->saveForProduct($product, [$grid['sizes'][0] => [$grid['measures'][0] => 9550]]);

        $other = $this->template('Other', [$this->measure('Elsewhere')]);
        $this->assignments()->assign($product, (string) $other->id());
        $this->assertSame((string) $other->id(), $this->assignments()->templateIdFor($product));
        $this->assertSame(1, DB::table('catalog_product_measurement_templates')->where('product_id', $product)->count(), 'one row per product');
        $this->assertSame([$grid['sizes'][0] => [$grid['measures'][0] => 9550]], $this->values()->forProduct($product));

        $this->assignments()->clear($product);
        $this->assertNull($this->assignments()->templateIdFor($product));
        $this->assertSame([$grid['sizes'][0] => [$grid['measures'][0] => 9550]], $this->values()->forProduct($product), 'clearing keeps the numbers');
    }

    public function test_assigning_an_unknown_product_or_template_is_refused(): void
    {
        $grid = $this->gridProduct();

        $this->refusedWith(InvalidMeasurementValueException::class, 'unknown_reference', fn () => $this->assignments()->assign('999999', $grid['template']));
        $this->refusedWith(InvalidMeasurementValueException::class, 'unknown_reference', fn () => $this->assignments()->assign($grid['product'], '999999'));
        $this->assertSame($grid['template'], $this->assignments()->templateIdFor($grid['product']));
    }

    // ------------------------------------------------------------------ values

    public function test_a_grid_round_trips_and_a_second_save_replaces_it(): void
    {
        $g = $this->gridProduct();
        [$s1, $s2, $s3] = $g['sizes'];
        [$m1, $m2] = $g['measures'];

        $this->assertSame([], $this->values()->forProduct($g['product']));

        $this->values()->saveForProduct($g['product'], [
            $s1 => [$m1 => 9000, $m2 => 6000],
            $s2 => [$m1 => 9550, $m2 => null],
        ]);
        $this->assertSame([$s1 => [$m1 => 9000, $m2 => 6000], $s2 => [$m1 => 9550]], $this->values()->forProduct($g['product']));

        // replace: s1/m1 changed, s1/m2 removed (absent), s2 removed completely, s3 new
        $this->values()->saveForProduct($g['product'], [
            $s1 => [$m1 => 9100],
            $s3 => [$m2 => 7000],
        ]);
        $this->assertEquals([$s1 => [$m1 => 9100], $s3 => [$m2 => 7000]], $this->values()->forProduct($g['product']));
        $this->assertSame(2, DB::table('catalog_measurement_values')->count());
    }

    public function test_an_unchanged_cell_is_not_rewritten(): void
    {
        $g = $this->gridProduct();
        $cell = [$g['sizes'][0] => [$g['measures'][0] => 9000]];
        $this->values()->saveForProduct($g['product'], $cell);
        $id = DB::table('catalog_measurement_values')->value('id');
        DB::table('catalog_measurement_values')->update(['updated_at' => '2020-01-01 00:00:00']);

        $this->values()->saveForProduct($g['product'], $cell);

        $this->assertSame($id, DB::table('catalog_measurement_values')->value('id'));
        $this->assertSame('2020-01-01 00:00:00', (string) DB::table('catalog_measurement_values')->value('updated_at'));
    }

    public function test_the_refusals_write_nothing_and_leave_the_previous_grid_intact(): void
    {
        $g = $this->gridProduct();
        [$s1, $s2] = $g['sizes'];
        [$m1, $m2] = $g['measures'];
        $before = [$s1 => [$m1 => 9000]];
        $this->values()->saveForProduct($g['product'], $before);

        $foreign = $this->gridProduct();
        $outsideMeasure = $this->measure('Outside the template');

        // a valid change first, then a bad cell: the whole call is refused, the valid change is NOT applied (atomic)
        $bad = [
            'measure not in the template' => ['measure_not_in_template', [$s1 => [$m1 => 1111], $s2 => [(string) $outsideMeasure->id() => 5000]]],
            'a measure of another template' => ['measure_not_in_template', [$s1 => [$m1 => 1111, $foreign['measures'][0] => 5000]]],
            'size value of another product' => ['size_value_not_on_axis', [$s1 => [$m1 => 1111], $foreign['sizes'][0] => [$m2 => 5000]]],
            'a value id that does not exist' => ['size_value_not_on_axis', ['999999' => [$m1 => 5000]]],
            'a colour value of this product' => ['size_value_not_on_axis', [(string) DB::table('catalog_product_axis_values')->where('product_id', $g['product'])->whereNotIn('attribute_value_id', $g['sizes'])->value('attribute_value_id') => [$m1 => 5000]]],
            'zero' => ['out_of_range', [$s1 => [$m1 => 1111, $m2 => 0]]],
            'negative' => ['out_of_range', [$s1 => [$m1 => -5]]],
            'too big' => ['out_of_range', [$s1 => [$m1 => 100_000_000]]],
        ];

        foreach ($bad as $label => [$reason, $rows]) {
            $this->refusedWith(InvalidMeasurementValueException::class, $reason, fn () => $this->values()->saveForProduct($g['product'], $rows));
            $this->assertEquals($before, $this->values()->forProduct($g['product']), "after: {$label}");
        }
    }

    public function test_a_product_without_a_template_accepts_no_numbers(): void
    {
        $g = $this->gridProduct();
        $this->assignments()->clear($g['product']);

        $this->refusedWith(InvalidMeasurementValueException::class, 'no_template', fn () => $this->values()->saveForProduct($g['product'], [$g['sizes'][0] => [$g['measures'][0] => 5000]]));
        $this->assertSame([], $this->values()->forProduct($g['product']));

        $this->values()->saveForProduct($g['product'], []);
        $this->assertSame([], $this->values()->forProduct($g['product']));
    }

    public function test_numbers_outside_the_current_grid_are_hidden_data_that_a_replace_keeps(): void
    {
        $g = $this->gridProduct(3, 2);
        [$s1, $s2] = $g['sizes'];
        [$m1, $m2] = $g['measures'];
        $this->values()->saveForProduct($g['product'], [$s1 => [$m1 => 1000, $m2 => 2000], $s2 => [$m1 => 3000]]);

        // switch to a template that has m2 and a NEW measure m3, but not m1
        $m3 = $this->measure('Third');
        $other = $this->template('Other', [$this->measures()->findById($m2), $m3]);
        $this->assignments()->assign($g['product'], (string) $other->id());

        $this->values()->saveForProduct($g['product'], [$s1 => [$m2 => 2500, (string) $m3->id() => 4000]]);
        $this->assertEquals([
            $s1 => [$m1 => 1000, $m2 => 2500, (string) $m3->id() => 4000],
            $s2 => [$m1 => 3000],
        ], $this->values()->forProduct($g['product']), 'm1 is hidden, not deleted');

        // an empty replace clears only the visible grid
        $this->values()->saveForProduct($g['product'], []);
        $this->assertEquals([$s1 => [$m1 => 1000], $s2 => [$m1 => 3000]], $this->values()->forProduct($g['product']));
    }

    public function test_a_size_value_that_left_the_axis_is_ignored_by_the_resolver_and_never_fatal(): void
    {
        $g = $this->gridProduct();
        [$s1, $s2] = $g['sizes'];
        $m1 = $g['measures'][0];
        $this->values()->saveForProduct($g['product'], [$s1 => [$m1 => 1000], $s2 => [$m1 => 2000]]);

        DB::table('catalog_product_axis_values')->where('product_id', $g['product'])->where('attribute_value_id', $s2)->delete();

        $this->assertSame([$s1, $g['sizes'][2]], $this->resolver()->forProduct($g['product']));
        $this->assertEquals([$s1 => [$m1 => 1000], $s2 => [$m1 => 2000]], $this->values()->forProduct($g['product']), 'still stored, readers intersect with the axis');

        $this->values()->saveForProduct($g['product'], [$s1 => [$m1 => 1500]]);
        $this->assertEquals([$s1 => [$m1 => 1500], $s2 => [$m1 => 2000]], $this->values()->forProduct($g['product']), 'the orphan is kept, not fatal');

        $this->refusedWith(InvalidMeasurementValueException::class, 'size_value_not_on_axis', fn () => $this->values()->saveForProduct($g['product'], [$s2 => [$m1 => 1]]));
    }

    // -------------------------------------------------------------- constraints

    public function test_the_cell_is_unique_in_the_database(): void
    {
        $g = $this->gridProduct();
        $row = ['product_id' => $g['product'], 'size_value_id' => $g['sizes'][0], 'measure_id' => $g['measures'][0], 'value_hundredths' => 100, 'created_at' => now(), 'updated_at' => now()];
        DB::table('catalog_measurement_values')->insert($row);

        try {
            DB::table('catalog_measurement_values')->insert($row);
            $this->fail('the unique key should refuse a second cell');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0]);
            $this->assertContains((int) $e->errorInfo[1], [1062, 19]);
        }
    }

    public function test_the_foreign_keys_protect_the_references_and_a_product_delete_cascades_its_numbers(): void
    {
        $g = $this->gridProduct();
        $this->values()->saveForProduct($g['product'], [$g['sizes'][0] => [$g['measures'][0] => 100]]);

        foreach ([
            ['catalog_measures', $g['measures'][0]],
            ['catalog_attribute_values', $g['sizes'][0]],
            ['catalog_measurement_templates', $g['template']],
        ] as [$table, $id]) {
            try {
                DB::table($table)->where('id', $id)->delete();
                $this->fail("{$table} row {$id} is referenced and must not be deletable");
            } catch (QueryException $e) {
                $this->assertSame('23000', $e->errorInfo[0], $table);
            }
        }

        // the cascade: the product's numbers, and its template assignment, go with it
        $this->assertSame(1, DB::table('catalog_measurement_values')->where('product_id', $g['product'])->count());
        // (a product with variations is itself restricted from deletion; the sanctioned deletion removes the variations first)
        DB::table('catalog_variations')->where('product_id', $g['product'])->delete();
        DB::table('catalog_products')->where('id', $g['product'])->delete();
        $this->assertSame(0, DB::table('catalog_measurement_values')->where('product_id', $g['product'])->count());
        $this->assertSame(0, DB::table('catalog_product_measurement_templates')->where('product_id', $g['product'])->count());
    }

    public function test_the_tables_columns_and_short_names_are_as_designed(): void
    {
        $this->assertSame(['id', 'name', 'unit', 'sort', 'is_active', 'created_at', 'updated_at'], \Schema::getColumnListing('catalog_measures'));
        $this->assertSame(['product_id', 'template_id', 'created_at', 'updated_at'], \Schema::getColumnListing('catalog_product_measurement_templates'));
        $this->assertSame(['id', 'product_id', 'size_value_id', 'measure_id', 'value_hundredths', 'created_at', 'updated_at', 'row_slot'], \Schema::getColumnListing('catalog_measurement_values'));
        $this->assertFalse(\Schema::hasColumn('catalog_products', 'measurement_template_id'), 'the assignment is a separate table (Product.php is frozen)');
    }

    // ----------------------------------------------------------------- is_size

    public function test_is_size_defaults_to_false_and_round_trips_through_the_repository(): void
    {
        $plain = $this->definition('material');
        $this->assertFalse(app(AttributeDefinitionRepository::class)->findById((string) $plain->id())->isSize());

        $flagged = $this->definition('sz', true);
        $this->assertTrue(app(AttributeDefinitionRepository::class)->findById((string) $flagged->id())->isSize());

        $flagged->setSize(false);
        app(AttributeDefinitionRepository::class)->save($flagged);
        $this->assertFalse(app(AttributeDefinitionRepository::class)->findById((string) $flagged->id())->isSize());

        $this->assertSame(0, (int) DB::table('catalog_attribute_definitions')->where('id', $plain->id())->value('is_size'));
    }

    // ---------------------------------------------------------------- resolver

    public function test_the_flagged_axis_wins_even_when_it_is_declared_second(): void
    {
        $colour = $this->definition('colour');
        $size = $this->definition('size', true);
        $colours = $this->attributeValues($colour, ['Black', 'White']);
        $sizes = $this->attributeValues($size, ['M', 'L', 'S']);
        $product = $this->variableProduct([[$colour, $colours], [$size, $sizes]]);

        $this->assertSame(array_map(fn (AttributeValue $v) => (string) $v->id(), $sizes), $this->resolver()->forProduct($product));
        $this->assertSame((string) $size->id(), $this->resolver()->axisDefinitionIdFor($product));
    }

    public function test_without_a_flag_the_first_declared_axis_is_the_rows(): void
    {
        $first = $this->definition('first');
        $second = $this->definition('second');
        $firstValues = $this->attributeValues($first, ['A', 'B']);
        $secondValues = $this->attributeValues($second, ['X', 'Y', 'Z']);
        $product = $this->variableProduct([[$first, $firstValues], [$second, $secondValues]]);

        $this->assertSame(array_map(fn (AttributeValue $v) => (string) $v->id(), $firstValues), $this->resolver()->forProduct($product));
    }

    public function test_a_colour_only_product_uses_its_colour_as_the_rows(): void
    {
        $colour = $this->definition('colour');
        $colours = $this->attributeValues($colour, ['Black', 'White']);
        $product = $this->variableProduct([[$colour, $colours]]);

        $this->assertSame(array_map(fn (AttributeValue $v) => (string) $v->id(), $colours), $this->resolver()->forProduct($product));
    }

    public function test_a_simple_product_has_no_size_axis_and_one_statement(): void
    {
        $product = $this->simpleProduct();
        $result = null;

        $statements = $this->countStatements(function () use ($product, &$result): void {
            $result = $this->resolver()->forProduct($product);
        });

        $this->assertSame([], $result);
        $this->assertSame(1, $statements);
        $this->assertNull($this->resolver()->axisDefinitionIdFor($product));
    }

    public function test_an_axis_that_is_not_flagged_on_this_product_does_not_leak_from_another(): void
    {
        $size = $this->definition('size', true);
        $sizes = $this->attributeValues($size, ['S', 'M']);
        $withSize = $this->variableProduct([[$size, $sizes]]);

        $colour = $this->definition('colour');
        $colours = $this->attributeValues($colour, ['Black']);
        $colourOnly = $this->variableProduct([[$colour, $colours]]);

        $this->assertSame([(string) $colours[0]->id()], $this->resolver()->forProduct($colourOnly));
        $this->assertCount(2, $this->resolver()->forProduct($withSize));
    }

    public function test_the_resolver_and_for_product_cost_the_same_statements_for_3_and_12_sizes(): void
    {
        $counts = [];

        foreach ([3, 12] as $sizes) {
            $g = $this->gridProduct($sizes, $sizes === 3 ? 3 : 12);
            $rows = [];
            foreach ($g['sizes'] as $sizeId) {
                foreach ($g['measures'] as $measureId) {
                    $rows[$sizeId][$measureId] = 5000;
                }
            }
            $this->values()->saveForProduct($g['product'], $rows);

            $resolved = [];
            $resolverStatements = $this->countStatements(function () use ($g, &$resolved): void {
                $resolved = $this->resolver()->forProduct($g['product']);
            });
            $loaded = [];
            $forProductStatements = $this->countStatements(function () use ($g, &$loaded): void {
                $loaded = $this->values()->forProduct($g['product']);
            });

            $this->assertCount($sizes, $resolved);
            $this->assertCount($sizes, $loaded);
            $this->assertCount(count($g['measures']), reset($loaded));
            $counts[$sizes] = [$resolverStatements, $forProductStatements];
        }

        $this->assertSame($counts[3], $counts[12], 'independent of the number of sizes and measures');
        $this->assertLessThanOrEqual(3, $counts[12][0]);
        $this->assertSame(1, $counts[12][1]);
    }

    // ------------------------------------------------------------------- hooks

    private function spyOnHooks(): int
    {
        $baseline = DB::transactionLevel();

        foreach ([
            'catalog.measure.saved', 'catalog.measure.archived', 'catalog.measure.deleted',
            'catalog.measurement_template.saved', 'catalog.measurement_template.archived', 'catalog.measurement_template.deleted',
            'catalog.product.measurements.saved',
        ] as $name) {
            Hook::action($name, function (...$arguments) use ($name): void {
                $this->hookCalls[] = [$name, $arguments, DB::transactionLevel()];
            });
        }

        return $baseline;
    }

    /** @return list<string> */
    private function firedNames(): array
    {
        return array_map(fn (array $call) => $call[0], $this->hookCalls);
    }

    public function test_the_measure_writer_fires_its_hooks_after_the_commit_with_the_payloads(): void
    {
        $baseline = $this->spyOnHooks();
        $writer = app(MeasureWriter::class);

        $measure = $writer->save(Measure::create('Chest', 'cm'));
        $writer->archive((string) $measure->id());
        $writer->archive((string) $measure->id());
        $writer->unarchive((string) $measure->id());
        $writer->delete((string) $measure->id());

        $this->assertSame(['catalog.measure.saved', 'catalog.measure.archived', 'catalog.measure.saved', 'catalog.measure.deleted'], $this->firedNames());
        $this->assertTrue($this->hookCalls[0][1][1], 'created');
        $this->assertFalse($this->hookCalls[2][1][1], 'an un-archive is not a create');
        $this->assertSame('Chest', $this->hookCalls[1][1][0]->name());
        $this->assertSame(['id' => (string) $measure->id(), 'name' => 'Chest', 'unit' => 'cm'], $this->hookCalls[3][1][0]);

        foreach ($this->hookCalls as $call) {
            $this->assertSame($baseline, $call[2], "{$call[0]} fired inside a transaction");
        }
    }

    public function test_the_template_writer_fires_its_hooks_after_the_commit(): void
    {
        $baseline = $this->spyOnHooks();
        $m = $this->measure('M');
        $writer = app(MeasurementTemplateWriter::class);

        $template = $writer->save(MeasurementTemplate::create('Blouses', [(string) $m->id()]));
        $writer->archive((string) $template->id());
        $writer->unarchive((string) $template->id());
        $writer->delete((string) $template->id());

        $this->assertSame([
            'catalog.measurement_template.saved', 'catalog.measurement_template.archived',
            'catalog.measurement_template.saved', 'catalog.measurement_template.deleted',
        ], $this->firedNames());
        $this->assertSame(['id' => (string) $template->id(), 'name' => 'Blouses', 'measure_ids' => [(string) $m->id()]], $this->hookCalls[3][1][0]);

        foreach ($this->hookCalls as $call) {
            $this->assertSame($baseline, $call[2], "{$call[0]} fired inside a transaction");
        }
    }

    public function test_the_product_writer_fires_one_hook_per_successful_write_with_the_product_id(): void
    {
        $g = $this->gridProduct();
        $baseline = $this->spyOnHooks();
        $writer = app(ProductMeasurementsWriter::class);

        $writer->saveGrid($g['product'], [$g['sizes'][0] => [$g['measures'][0] => 5000]]);
        $writer->clearTemplate($g['product']);
        $writer->assignTemplate($g['product'], $g['template']);

        $this->assertSame(array_fill(0, 3, 'catalog.product.measurements.saved'), $this->firedNames());
        foreach ($this->hookCalls as $call) {
            $this->assertSame([$g['product']], $call[1]);
            $this->assertSame($baseline, $call[2]);
        }
    }

    public function test_a_refused_write_fires_nothing(): void
    {
        $g = $this->gridProduct();
        $used = $this->measures()->findById($g['measures'][0]);
        $this->spyOnHooks();

        $attempts = [
            fn () => app(MeasureWriter::class)->delete($g['measures'][0]),
            fn () => app(MeasureWriter::class)->save(Measure::create($used->name(), 'in')),
            fn () => app(MeasurementTemplateWriter::class)->delete($g['template']),
            fn () => app(ProductMeasurementsWriter::class)->saveGrid($g['product'], ['999999' => [$g['measures'][0] => 5000]]),
            fn () => app(ProductMeasurementsWriter::class)->saveGrid($g['product'], [$g['sizes'][0] => [$g['measures'][0] => 0]]),
            fn () => app(ProductMeasurementsWriter::class)->assignTemplate($g['product'], '999999'),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('the write should have been refused');
            } catch (InvalidMeasurementValueException|MeasurementRefusedException) {
                // expected
            }
        }

        $this->assertSame([], $this->hookCalls);
    }

    public function test_a_throwing_listener_cannot_roll_back_or_corrupt_the_committed_write(): void
    {
        Hook::action('catalog.measure.saved', function (): void {
            throw new RuntimeException('listener blew up');
        });
        Hook::action('catalog.product.measurements.saved', function (): void {
            throw new RuntimeException('listener blew up');
        });

        try {
            app(MeasureWriter::class)->save(Measure::create('Persisted', 'cm'));
            $this->fail('the listener exception propagates, like every existing writer');
        } catch (RuntimeException $e) {
            $this->assertSame('listener blew up', $e->getMessage());
        }
        $this->assertSame(1, DB::table('catalog_measures')->where('name', 'Persisted')->count(), 'the write is committed');

        $g = $this->gridProduct();
        try {
            app(ProductMeasurementsWriter::class)->saveGrid($g['product'], [$g['sizes'][0] => [$g['measures'][0] => 5000]]);
            $this->fail('expected the listener exception');
        } catch (RuntimeException) {
        }
        $this->assertSame([$g['sizes'][0] => [$g['measures'][0] => 5000]], $this->values()->forProduct($g['product']));
    }
}
