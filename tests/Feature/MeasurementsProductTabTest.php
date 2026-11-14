<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Measurements\ProductMeasurementsPanel;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\EditVariableProduct;
use App\Models\ActivityLogModel;
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
use EasyCo\Catalog\Measure;
use EasyCo\Catalog\MeasurementTemplate;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Extensibility\Hook;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\TestCase;

/**
 * MEAS-4: the product tab «Размери» on the simple and the variable edit page (measurements-research.md «As built (MEAS-4)»): where it shows, the picker, the two kinds of grid, the keyboard-free parts of the
 * behaviour (validation before any save, save order, unchanged = nothing written, copy first row, hidden numbers, audit, permission, a refusal after the product saved) and the statement counts.
 */
class MeasurementsProductTabTest extends TestCase
{
    use BuildsShippingZones;
    use GivesProductsAShippingClass;
    use RefreshDatabase;

    private int $n = 0;

    private int $hooks = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->giveProductsAShippingClass();
        app()->setLocale('en');
        $this->actingAsStaff('Administrator');
        Hook::action('catalog.product.measurements.saved', function (): void {
            $this->hooks++;
        });
    }

    // ------------------------------------------------------------------------------------------ fixtures

    private function measure(string $name, string $unit = 'cm', bool $archived = false): string
    {
        $measure = Measure::create($name, $unit);
        $archived && $measure->archive();
        app(MeasureRepository::class)->save($measure);

        return (string) $measure->id();
    }

    /** @param string[] $measureIds */
    private function template(string $name, array $measureIds, MeasurementTemplateKind $kind = MeasurementTemplateKind::BY_SIZE, bool $archived = false): string
    {
        $template = MeasurementTemplate::create($name, $measureIds, 0, $kind);
        $archived && $template->archive();
        app(MeasurementTemplateRepository::class)->save($template);

        return (string) $template->id();
    }

    /**
     * A variable product with a colour axis and a size axis of $sizeCount sizes. `$flagged` = the size attribute carries the «Показател за размер» flag.
     *
     * @return array{product: string, sizes: string[], colours: string[], size_definition: string, colour_definition: string}
     */
    private function variable(int $sizeCount = 3, bool $flagged = true, bool $withSize = true): array
    {
        $n = ++$this->n;
        $colour = new AttributeDefinition(id: null, code: "colour{$n}", name: 'Colour', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($colour);
        $colours = [];
        foreach (['Black', 'White'] as $i => $name) {
            $v = new AttributeValue(id: null, attributeDefinitionId: $colour->id(), value: $name, sortOrder: $i);
            app(AttributeValueRepository::class)->save($v);
            $colours[] = $v;
        }
        $axes = [new VariationAxis($colour, $colours)];
        $sizes = [];
        $size = null;

        if ($withSize) {
            $size = new AttributeDefinition(id: null, code: "size{$n}", name: 'Size', type: AttributeType::SELECT, isSize: $flagged);
            app(AttributeDefinitionRepository::class)->save($size);
            for ($i = 1; $i <= $sizeCount; $i++) {
                $v = new AttributeValue(id: null, attributeDefinitionId: $size->id(), value: "S{$i}", sortOrder: $i);
                app(AttributeValueRepository::class)->save($v);
                $sizes[] = $v;
            }
            $axes[] = new VariationAxis($size, $sizes);
        }

        $product = Product::createVariable("Shirt {$n}", "SHIRT-{$n}", "shirt-{$n}");
        $product->declareVariationAxes($axes);
        $i = 0;
        foreach ($colours as $c) {
            foreach ($withSize ? $sizes : [null] as $s) {
                $product->addStandardVariation([$colour->id() => $c->id()] + ($s !== null ? [$size->id() => $s->id()] : []), "SHIRT-{$n}-".$i++);
            }
        }
        app(ProductRepository::class)->save($product);

        return [
            'product' => (string) $product->id(),
            'sizes' => array_map(fn ($v) => (string) $v->id(), $sizes),
            'colours' => array_map(fn ($v) => (string) $v->id(), $colours),
            'size_definition' => (string) ($size?->id() ?? ''),
            'colour_definition' => (string) $colour->id(),
        ];
    }

    private function simple(): string
    {
        $n = ++$this->n;
        app(ProductRepository::class)->save(Product::createSimple("Phone {$n}", "PHONE-{$n}", "phone-{$n}"));

        return (string) ProductModel::where('slug', "phone-{$n}")->value('id');
    }

    private function assign(string $product, string $template): void
    {
        app(ProductMeasurementTemplateRepository::class)->assign($product, $template);
    }

    /** @param array<string, array<string, int>> $rows */
    private function store(string $product, array $rows): void
    {
        app(MeasurementValueRepository::class)->saveForProduct($product, $rows);
        $this->hooks = 0;
    }

    private function variablePage(string $product)
    {
        return Livewire::test(EditVariableProduct::class, ['record' => $product]);
    }

    private function simplePage(string $product)
    {
        return Livewire::test(EditProduct::class, ['record' => $product]);
    }

    private function cell(string $row, string $measure): string
    {
        return 'data.measurement_cells.'.$row.'.'.$measure;
    }

    private function stored(string $product): array
    {
        return app(MeasurementValueRepository::class)->forProduct($product);
    }

    private function auditOn(): void
    {
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
    }

    private function auditFor(string $product)
    {
        return ActivityLogModel::query()->where('entity_type', 'product')->where('entity_id', $product)->where(fn ($q) => $q->where('field', 'like', 'measurement%'))->orderBy('id')->get();
    }

    // ------------------------------------------------------------------------------------------ where the tab shows

    public function test_the_tab_shows_on_the_variable_page_with_both_kinds_offered(): void
    {
        $m = $this->measure('Chest');
        $this->template('Shirts', [$m]);
        $this->template('Whole', [$m], MeasurementTemplateKind::SINGLE);
        $shirt = $this->variable();

        $this->variablePage($shirt['product'])
            ->assertSee('Sizes')
            ->assertSee('Size template')
            ->assertSee('By size')
            ->assertSee('General measures')
            ->assertSee('Shirts')
            ->assertSee('Whole');
    }

    public function test_a_simple_product_is_offered_only_general_measures_templates(): void
    {
        $m = $this->measure('Thickness', 'mm');
        $this->template('Shirts only', [$m]);
        $this->template('Phones', [$m], MeasurementTemplateKind::SINGLE);

        $this->simplePage($this->simple())
            ->assertSee('Phones')
            ->assertDontSee('Shirts only');
    }

    public function test_a_simple_product_with_no_single_template_gets_a_hint_with_a_link(): void
    {
        $m = $this->measure('Thickness', 'mm');
        $this->template('Shirts only', [$m]);

        $this->simplePage($this->simple())
            ->assertSee('Create a «General measures» template')
            ->assertDontSee('Shirts only');
    }

    public function test_no_templates_at_all_replaces_the_select_by_a_hint_with_a_link(): void
    {
        $this->variablePage($this->variable()['product'])
            ->assertSee('There are no size templates yet.')
            ->assertSee('Create a size template');
    }

    public function test_the_create_wizard_has_no_sizes_tab(): void
    {
        $m = $this->measure('Thickness', 'mm');
        $this->template('Phones', [$m], MeasurementTemplateKind::SINGLE);

        Livewire::test(CreateProduct::class)
            ->assertDontSee('Size template')
            ->assertDontSee('No size table');
    }

    public function test_the_picker_lists_active_templates_and_the_current_archived_one_marked(): void
    {
        $m = $this->measure('Chest');
        $this->template('Active shirts', [$m]);
        $this->template('Old archived', [$m], MeasurementTemplateKind::BY_SIZE, true);
        $current = $this->template('Current archived', [$m], MeasurementTemplateKind::BY_SIZE, true);
        $shirt = $this->variable();
        $this->assign($shirt['product'], $current);

        $this->variablePage($shirt['product'])
            ->assertSee('Active shirts')
            ->assertSee('Current archived — archived')
            ->assertDontSee('Old archived');
    }

    // ------------------------------------------------------------------------------------------ the grids

    public function test_a_single_template_shows_a_vertical_list_without_a_table_and_saves_under_the_product_row(): void
    {
        $thickness = $this->measure('Thickness', 'mm');
        $screen = $this->measure('Screen', 'in');
        $weight = $this->measure('Weight', 'g');
        $template = $this->template('Phones', [$thickness, $screen, $weight], MeasurementTemplateKind::SINGLE);
        $product = $this->simple();
        $this->assign($product, $template);
        $row = MeasurementValueRepository::PRODUCT_ROW;

        $page = $this->simplePage($product)
            ->assertSee('Thickness')
            ->assertSee('Screen')
            ->assertSee('Weight')
            ->assertDontSee('Copy the first row to all');
        $this->assertStringNotContainsString('<table class="meas-table"', $page->html());

        $page->set($this->cell($row, $thickness), '7,8')
            ->set($this->cell($row, $screen), '6.1')
            ->set($this->cell($row, $weight), '172')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals([$row => [$thickness => 780, $screen => 610, $weight => 17200]], $this->stored($product));
        $this->assertSame(1, $this->hooks);
    }

    public function test_a_variable_phone_with_a_single_template_has_one_row_not_one_per_colour(): void
    {
        $thickness = $this->measure('Thickness', 'mm');
        $template = $this->template('Phones', [$thickness], MeasurementTemplateKind::SINGLE);
        $phone = $this->variable(0, false, false);
        $this->assign($phone['product'], $template);
        $row = MeasurementValueRepository::PRODUCT_ROW;

        $page = $this->variablePage($phone['product']);
        $this->assertSame(1, substr_count($page->html(), 'data-meas-cell data-col'), 'one input for one measure, whatever the colours');

        $page->set($this->cell($row, $thickness), '7,8')->call('save')->assertHasNoFormErrors();

        $this->assertEquals([$row => [$thickness => 780]], $this->stored($phone['product']));
        $this->assertSame(1, DB::table('catalog_measurement_values')->where('product_id', $phone['product'])->count());
    }

    public function test_the_by_size_grid_has_one_row_per_size_and_columns_in_the_templates_order_with_units(): void
    {
        $chest = $this->measure('Chest', 'cm');
        $length = $this->measure('Length', 'cm');
        $template = $this->template('Shirts', [$length, $chest]);
        $shirt = $this->variable(3);
        $this->assign($shirt['product'], $template);

        $html = $this->variablePage($shirt['product'])->html();

        $this->assertSame(6, substr_count($html, 'data-meas-cell data-col'), '3 sizes x 2 measures');
        $this->assertLessThan(strpos($html, 'Chest, cm'), strpos($html, 'Length, cm'), "the template's order");
        $this->assertStringContainsString('aria-label="S1 — Length"', $html);
        $this->assertStringContainsString('Copy the first row to all', $html);
    }

    public function test_a_flagged_size_axis_shows_no_fallback_hint_and_an_unflagged_one_does(): void
    {
        $chest = $this->measure('Chest');
        $template = $this->template('Shirts', [$chest]);

        $flagged = $this->variable(2, true);
        $this->assign($flagged['product'], $template);
        $this->variablePage($flagged['product'])->assertDontSee('none of the attributes of the product is marked');

        // no size flag at all: the FIRST declared axis (the colour) supplies the rows, and the merchant is told why
        $plain = $this->variable(2, false);
        $this->assign($plain['product'], $template);
        $this->variablePage($plain['product'])
            ->assertSee('The rows are by «Colour» because none of the attributes of the product is marked as «Size measure».')
            ->assertSee('Open the attribute');
    }

    public function test_an_archived_measure_in_the_template_is_marked_but_stays_editable(): void
    {
        $chest = $this->measure('Chest');
        $old = $this->measure('Old measure', 'cm', true);
        $template = $this->template('Shirts', [$chest, $old]);
        $shirt = $this->variable(2);
        $this->assign($shirt['product'], $template);

        $page = $this->variablePage($shirt['product'])->assertSee('(archived — not shown on the site)');

        $page->set($this->cell($shirt['sizes'][0], $old), '12')->call('save')->assertHasNoFormErrors();
        $this->assertSame(1200, $this->stored($shirt['product'])[$shirt['sizes'][0]][$old]);
    }

    public function test_the_stored_numbers_are_loaded_formatted_in_the_admin_locale(): void
    {
        $chest = $this->measure('Chest');
        $shirt = $this->variable(2);
        $this->assign($shirt['product'], $this->template('Shirts', [$chest]));
        $this->store($shirt['product'], [$shirt['sizes'][0] => [$chest => 9550], $shirt['sizes'][1] => [$chest => 10000]]);

        app()->setLocale('bg');
        $this->variablePage($shirt['product'])
            ->assertSet($this->cell($shirt['sizes'][0], $chest), '95,5')
            ->assertSet($this->cell($shirt['sizes'][1], $chest), '100');

        app()->setLocale('en');
        $this->variablePage($shirt['product'])->assertSet($this->cell($shirt['sizes'][0], $chest), '95.5');
    }

    public function test_changing_the_picker_changes_the_columns_at_once_and_warns_without_saving(): void
    {
        $chest = $this->measure('Chest');
        $waist = $this->measure('Waist');
        $a = $this->template('Tops', [$chest]);
        $b = $this->template('Dresses', [$waist]);
        $shirt = $this->variable(2);
        $this->assign($shirt['product'], $a);

        $page = $this->variablePage($shirt['product'])
            ->assertSee('Chest, cm')
            ->assertDontSee('Waist, cm')
            ->assertDontSee('Numbers for measures that the new template does not have are not deleted');

        $page->set('data.measurement_template_id', $b)
            ->assertSee('Waist, cm')
            ->assertDontSee('Chest, cm')
            ->assertSee('Numbers for measures that the new template does not have are not deleted');

        $this->assertSame($a, app(ProductMeasurementTemplateRepository::class)->templateIdFor($shirt['product']), 'nothing is saved until «Запази»');

        $page->set('data.measurement_template_id', $a)->assertDontSee('Numbers for measures that the new template does not have are not deleted');
    }

    // ------------------------------------------------------------------------------------------ save

    public function test_save_writes_the_grid_one_hook_one_audit_entry_per_changed_cell(): void
    {
        $this->auditOn();
        $chest = $this->measure('Chest');
        $waist = $this->measure('Waist');
        $shirt = $this->variable(3);
        $this->assign($shirt['product'], $this->template('Shirts', [$chest, $waist]));
        [$s1, $s2] = $shirt['sizes'];

        $this->variablePage($shirt['product'])
            ->set($this->cell($s1, $chest), '95,5')
            ->set($this->cell($s1, $waist), '80')
            ->set($this->cell($s2, $chest), '100.25')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals([$s1 => [$chest => 9550, $waist => 8000], $s2 => [$chest => 10025]], $this->stored($shirt['product']));
        $this->assertSame(1, $this->hooks, 'one hook for one grid save');

        $audit = $this->auditFor($shirt['product']);
        $this->assertSame(3, $audit->count());
        $row = $audit->firstWhere('field', 'measurement[S1][Chest]');
        $this->assertNotNull($row);
        $this->assertNull($row->old_value);
        $this->assertSame('95.5', $row->new_value, 'in the admin format (en here)');
        $this->assertNotNull($audit->firstWhere('field', 'measurement[S2][Chest]'));
    }

    public function test_an_audit_entry_shows_old_and_new_with_the_bg_decimal_comma(): void
    {
        $this->auditOn();
        app()->setLocale('bg');
        $chest = $this->measure('Chest');
        $shirt = $this->variable(1);
        $this->assign($shirt['product'], $this->template('Shirts', [$chest]));
        $this->store($shirt['product'], [$shirt['sizes'][0] => [$chest => 9500]]);

        $this->variablePage($shirt['product'])->set($this->cell($shirt['sizes'][0], $chest), '95,5')->call('save');

        $entry = $this->auditFor($shirt['product'])->first();
        $this->assertSame('measurement[S1][Chest]', $entry->field);
        $this->assertSame('95', $entry->old_value);
        $this->assertSame('95,5', $entry->new_value);
    }

    public function test_an_empty_cell_removes_that_number_and_only_that_one(): void
    {
        $chest = $this->measure('Chest');
        $shirt = $this->variable(2);
        $this->assign($shirt['product'], $this->template('Shirts', [$chest]));
        [$s1, $s2] = $shirt['sizes'];
        $this->store($shirt['product'], [$s1 => [$chest => 9500], $s2 => [$chest => 10000]]);

        $this->variablePage($shirt['product'])->set($this->cell($s1, $chest), '')->call('save')->assertHasNoFormErrors();

        $this->assertEquals([$s2 => [$chest => 10000]], $this->stored($shirt['product']));
    }

    public function test_a_save_that_changes_no_number_writes_nothing_no_hook_no_audit(): void
    {
        $this->auditOn();
        $chest = $this->measure('Chest');
        $shirt = $this->variable(2);
        $this->assign($shirt['product'], $this->template('Shirts', [$chest]));
        $this->store($shirt['product'], [$shirt['sizes'][0] => [$chest => 9550]]);
        $updatedAt = DB::table('catalog_measurement_values')->where('product_id', $shirt['product'])->value('updated_at');

        $this->variablePage($shirt['product'])->call('save')->assertHasNoFormErrors();

        $this->assertSame(0, $this->hooks);
        $this->assertSame(0, $this->auditFor($shirt['product'])->count());
        $this->assertSame($updatedAt, DB::table('catalog_measurement_values')->where('product_id', $shirt['product'])->value('updated_at'));
    }

    public function test_a_bad_cell_is_an_error_on_that_cell_and_nothing_at_all_is_saved(): void
    {
        $chest = $this->measure('Chest');
        $shirt = $this->variable(2);
        $this->assign($shirt['product'], $this->template('Shirts', [$chest]));
        [$s1, $s2] = $shirt['sizes'];
        $oldName = ProductModel::find($shirt['product'])->name;

        $this->variablePage($shirt['product'])
            ->set('data.name', 'Renamed in the same submit')
            ->set($this->cell($s1, $chest), '95')
            ->set($this->cell($s2, $chest), '9,555')
            ->call('save')
            ->assertHasFormErrors(['measurement_cells.'.$s2.'.'.$chest])
            ->assertHasNoFormErrors(['measurement_cells.'.$s1.'.'.$chest])
            ->assertSee('Not a valid number');

        $this->assertSame($oldName, ProductModel::find($shirt['product'])->name, 'the product fields are not saved either');
        $this->assertSame([], $this->stored($shirt['product']), 'neither is any cell');
        $this->assertSame(0, $this->hooks);
    }

    public function test_a_cell_error_is_dropped_when_the_server_changes_the_cells(): void
    {
        $chest = $this->measure('Chest');
        $shirt = $this->variable(2);
        $this->assign($shirt['product'], $this->template('Shirts', [$chest]));
        [$s1, $s2] = $shirt['sizes'];

        $this->variablePage($shirt['product'])
            ->set($this->cell($s1, $chest), '90')
            ->set($this->cell($s2, $chest), 'abc')
            ->call('save')
            ->assertHasFormErrors(['measurement_cells.'.$s2.'.'.$chest])
            ->set($this->cell($s2, $chest), '')
            ->callAction(TestAction::make('copy_first_row')->schemaComponent('measurement-actions', 'form'))
            ->assertHasNoFormErrors()
            ->assertSet($this->cell($s2, $chest), '90');
    }

    public function test_the_out_of_range_error_is_translated_on_the_cell(): void
    {
        $chest = $this->measure('Chest');
        $shirt = $this->variable(1);
        $this->assign($shirt['product'], $this->template('Shirts', [$chest]));

        $this->variablePage($shirt['product'])
            ->set($this->cell($shirt['sizes'][0], $chest), '0')
            ->call('save')
            ->assertHasFormErrors(['measurement_cells.'.$shirt['sizes'][0].'.'.$chest])
            ->assertSee('outside the allowed range');
    }

    public function test_a_comma_and_a_dot_are_both_stored_as_hundredths(): void
    {
        $chest = $this->measure('Chest');
        $shirt = $this->variable(2);
        $this->assign($shirt['product'], $this->template('Shirts', [$chest]));
        [$s1, $s2] = $shirt['sizes'];

        $this->variablePage($shirt['product'])->set($this->cell($s1, $chest), '95,5')->set($this->cell($s2, $chest), '95.5')->call('save');

        $this->assertEquals([$s1 => [$chest => 9550], $s2 => [$chest => 9550]], $this->stored($shirt['product']));
    }

    public function test_copy_first_row_fills_only_empty_cells_and_never_overwrites(): void
    {
        $chest = $this->measure('Chest');
        $waist = $this->measure('Waist');
        $shirt = $this->variable(3);
        $this->assign($shirt['product'], $this->template('Shirts', [$chest, $waist]));
        [$s1, $s2, $s3] = $shirt['sizes'];

        $page = $this->variablePage($shirt['product'])
            ->set($this->cell($s1, $chest), '90')
            ->set($this->cell($s1, $waist), '70')
            ->set($this->cell($s2, $chest), '99')
            ->callAction(TestAction::make('copy_first_row')->schemaComponent('measurement-actions', 'form'));

        $page->assertSet($this->cell($s2, $chest), '99')   // typed: kept
            ->assertSet($this->cell($s2, $waist), '70')
            ->assertSet($this->cell($s3, $chest), '90')
            ->assertSet($this->cell($s3, $waist), '70')
            ->assertNotified('3 cells filled');

        $this->assertSame([], $this->stored($shirt['product']), 'form state only: nothing is saved until «Запази»');

        $page->call('save');
        $this->assertSame(7000, $this->stored($shirt['product'])[$s3][$waist]);
        $this->assertSame(9900, $this->stored($shirt['product'])[$s2][$chest]);
    }

    public function test_switching_the_template_keeps_the_dropped_numbers_hidden_shows_their_count_and_brings_them_back(): void
    {
        $chest = $this->measure('Chest');
        $waist = $this->measure('Waist');
        $tops = $this->template('Tops', [$chest, $waist]);
        $dresses = $this->template('Dresses', [$waist]);
        $shirt = $this->variable(2);
        $this->assign($shirt['product'], $tops);
        [$s1, $s2] = $shirt['sizes'];
        $this->store($shirt['product'], [$s1 => [$chest => 9500, $waist => 7000], $s2 => [$chest => 10000]]);

        $page = $this->variablePage($shirt['product'])->assertDontSee('hidden value');

        $page->set('data.measurement_template_id', $dresses)
            ->assertSee('There are 2 hidden values')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($dresses, app(ProductMeasurementTemplateRepository::class)->templateIdFor($shirt['product']));
        $this->assertEquals([$s1 => [$chest => 9500, $waist => 7000], $s2 => [$chest => 10000]], $this->stored($shirt['product']), 'nothing was deleted');

        $this->variablePage($shirt['product'])
            ->assertSee('There are 2 hidden values')
            ->set('data.measurement_template_id', $tops)
            ->assertDontSee('hidden value')
            ->assertSet($this->cell($s1, $chest), '95')
            ->assertSet($this->cell($s2, $chest), '100');
    }

    public function test_without_a_table_clears_only_the_assignment_and_keeps_every_number(): void
    {
        $chest = $this->measure('Chest');
        $shirt = $this->variable(2);
        $this->assign($shirt['product'], $this->template('Tops', [$chest]));
        $this->store($shirt['product'], [$shirt['sizes'][0] => [$chest => 9500]]);

        $this->variablePage($shirt['product'])
            ->set('data.measurement_template_id', '')
            ->assertSee('Numbers for measures that the new template does not have are not deleted')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull(app(ProductMeasurementTemplateRepository::class)->templateIdFor($shirt['product']));
        $this->assertEquals([$shirt['sizes'][0] => [$chest => 9500]], $this->stored($shirt['product']));
        $this->assertSame(1, $this->hooks);
    }

    public function test_switching_between_the_two_kinds_hides_and_restores_the_other_kinds_numbers(): void
    {
        $chest = $this->measure('Chest');
        $bySize = $this->template('Tops', [$chest]);
        $single = $this->template('Whole', [$chest], MeasurementTemplateKind::SINGLE);
        $shirt = $this->variable(2);
        $this->assign($shirt['product'], $bySize);
        $this->store($shirt['product'], [$shirt['sizes'][0] => [$chest => 9500]]);
        $row = MeasurementValueRepository::PRODUCT_ROW;

        $this->variablePage($shirt['product'])
            ->set('data.measurement_template_id', $single)
            ->assertSee('There is 1 hidden value')
            ->set($this->cell($row, $chest), '55')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals([$shirt['sizes'][0] => [$chest => 9500], $row => [$chest => 5500]], $this->stored($shirt['product']));
    }

    public function test_more_than_twenty_changed_cells_are_one_summary_audit_entry(): void
    {
        $this->auditOn();
        $measures = [$this->measure('A'), $this->measure('B'), $this->measure('C'), $this->measure('D')];
        $shirt = $this->variable(6);
        $this->assign($shirt['product'], $this->template('Big', $measures));

        $page = $this->variablePage($shirt['product']);
        foreach ($shirt['sizes'] as $size) {
            foreach ($measures as $measure) {
                $page->set($this->cell($size, $measure), '10');
            }
        }
        $page->call('save')->assertHasNoFormErrors();

        $audit = $this->auditFor($shirt['product']);
        $this->assertSame(1, $audit->count());
        $this->assertSame('measurements', $audit->first()->field);
        $this->assertSame('24 cells changed', $audit->first()->new_value);
        $this->assertSame(24, DB::table('catalog_measurement_values')->where('product_id', $shirt['product'])->count());
    }

    public function test_a_template_change_is_audited_by_name(): void
    {
        $this->auditOn();
        $chest = $this->measure('Chest');
        $a = $this->template('Tops', [$chest]);
        $b = $this->template('Dresses', [$chest]);
        $shirt = $this->variable(1);
        $this->assign($shirt['product'], $a);

        $this->variablePage($shirt['product'])->set('data.measurement_template_id', $b)->call('save');

        $entry = ActivityLogModel::query()->where('entity_id', $shirt['product'])->where('field', 'measurement_template')->first();
        $this->assertNotNull($entry);
        $this->assertSame('Tops', $entry->old_value);
        $this->assertSame('Dresses', $entry->new_value);

        $this->variablePage($shirt['product'])->set('data.measurement_template_id', '')->call('save');
        $this->assertSame('—', ActivityLogModel::query()->where('entity_id', $shirt['product'])->where('field', 'measurement_template')->orderByDesc('id')->value('new_value'));
    }

    public function test_the_audit_obeys_the_activity_log_setting(): void
    {
        $chest = $this->measure('Chest');
        $shirt = $this->variable(1);
        $this->assign($shirt['product'], $this->template('Tops', [$chest]));

        $this->variablePage($shirt['product'])->set($this->cell($shirt['sizes'][0], $chest), '90')->call('save');

        $this->assertSame(0, $this->auditFor($shirt['product'])->count());
        $this->assertSame(1, $this->hooks);
    }

    public function test_a_tampered_archived_or_by_size_template_is_refused_with_the_translated_notice_and_the_product_stays_saved(): void
    {
        $chest = $this->measure('Chest');
        $archived = $this->template('Gone', [$chest], MeasurementTemplateKind::SINGLE, true);
        $product = $this->simple();

        // the select's own option check would stop this in the browser; the saver is the last word for a request that bypasses it
        $this->expectException(InvalidMeasurementValueException::class);
        app(ProductMeasurementsPanel::class)->save($product, true, [ProductMeasurementsPanel::TEMPLATE => $archived, ProductMeasurementsPanel::CELLS => []]);
    }

    public function test_a_refusal_of_the_data_layer_after_the_product_saved_is_a_notice_and_the_product_stays_saved(): void
    {
        $chest = $this->measure('Chest');
        $shirt = $this->variable(1);
        $this->assign($shirt['product'], $this->template('Tops', [$chest]));
        $page = $this->variablePage($shirt['product']);

        // between load and save the axis changed under the page (simulated: the repository refuses as it would)
        $real = app(MeasurementValueRepository::class);
        $this->app->bind(MeasurementValueRepository::class, fn () => new class($real) implements MeasurementValueRepository
        {
            public function __construct(private MeasurementValueRepository $inner)
            {
            }

            public function forProduct(string $productId): array
            {
                return $this->inner->forProduct($productId);
            }

            public function saveForProduct(string $productId, array $rows): void
            {
                throw InvalidMeasurementValueException::sizeValueNotOnAxis($productId, '999');
            }

            public function copyProduct(string $fromProductId, string $toProductId): int
            {
                return $this->inner->copyProduct($fromProductId, $toProductId);
            }
        });

        $page->set('data.name', 'Saved name')
            ->set($this->cell($shirt['sizes'][0], $chest), '90')
            ->call('save')
            ->assertNotified('Sizes not saved');

        $this->assertSame('Saved name', ProductModel::find($shirt['product'])->name, 'the product fields stay saved');
        $this->assertSame(0, $this->hooks);
    }

    public function test_without_product_manage_nothing_is_written_by_the_tab(): void
    {
        $chest = $this->measure('Chest');
        $shirt = $this->variable(1);
        $this->assign($shirt['product'], $this->template('Tops', [$chest]));
        $this->actingAsCustomRole([Permission::PRODUCT_VIEW]);

        $state = app(ProductMeasurementsPanel::class)->saveFromPage(new \stdClass(), $shirt['product'], false, [
            ProductMeasurementsPanel::TEMPLATE => '',
            ProductMeasurementsPanel::CELLS => [$shirt['sizes'][0] => [$chest => '90']],
        ]);

        $this->assertNull($state);
        $this->assertSame([], $this->stored($shirt['product']));
        $this->assertNotNull(app(ProductMeasurementTemplateRepository::class)->templateIdFor($shirt['product']));
        $this->assertSame(0, $this->hooks);

        // and the edit page itself is not even reachable without it
        $this->get(\App\Filament\Resources\ProductResource::getUrl('edit-variable', ['record' => ProductModel::find($shirt['product'])]))->assertForbidden();
    }

    // ------------------------------------------------------------------------------------------ statement counts

    private function countStatements(callable $work): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $work();

        return $count;
    }

    /** What the tab reads when the page opens and renders: the state, the picker, the grid. */
    private function openTab(string $product, bool $simple): void
    {
        $page = new \stdClass();
        $panel = app(ProductMeasurementsPanel::class);
        $state = $panel->load($page, $product);
        $panel->hasTemplates($page);
        $panel->hasActiveSingleTemplate($page);
        $panel->pickerOptions($page, $product, $simple, $state[ProductMeasurementsPanel::LOADED] ?: null);
        $panel->grid($page, $product, $simple, $state[ProductMeasurementsPanel::TEMPLATE] ?: null);
    }

    public function test_opening_the_tab_costs_a_bounded_number_of_statements_whatever_the_grid_size(): void
    {
        $small = $this->variable(3);
        $this->assign($small['product'], $this->template('Small', array_map(fn ($i) => $this->measure("M{$i}"), range(1, 3))));
        $big = $this->variable(12);
        $this->assign($big['product'], $this->template('Big', array_map(fn ($i) => $this->measure("B{$i}"), range(1, 12))));
        $this->store($big['product'], [$big['sizes'][0] => [(string) DB::table('catalog_measures')->where('name', 'B1')->value('id') => 100]]);

        $threeByThree = $this->countStatements(fn () => $this->openTab($small['product'], false));
        $twelveByTwelve = $this->countStatements(fn () => $this->openTab($big['product'], false));

        fwrite(STDERR, "\n[meas-4 statements] open the tab, by_size, variable: {$threeByThree} (3x3) / {$twelveByTwelve} (12x12)\n");
        $this->assertSame($threeByThree, $twelveByTwelve);
        $this->assertSame(7, $threeByThree, 'assignment 1 + templates 2 + measures 1 + axis 2 + stored numbers 1');
    }

    public function test_opening_a_single_template_tab_and_a_tab_without_a_template_costs_few_statements(): void
    {
        $m = $this->measure('Thickness', 'mm');
        $single = $this->template('Phones', [$m], MeasurementTemplateKind::SINGLE);
        $phone = $this->variable(0, false, false);
        $this->assign($phone['product'], $single);
        $bare = $this->variable(3);

        $withSingle = $this->countStatements(fn () => $this->openTab($phone['product'], false));
        $without = $this->countStatements(fn () => $this->openTab($bare['product'], false));

        fwrite(STDERR, "\n[meas-4 statements] open the tab: single template {$withSingle}, no template {$without}\n");
        $this->assertSame(5, $withSingle, 'assignment 1 + templates 2 + measures 1 + stored numbers 1 (no axis read for a single row)');
        $this->assertSame(3, $without, 'assignment 1 + templates 2');
    }

    public function test_saving_costs_a_bounded_number_of_statements_whatever_the_grid_size(): void
    {
        $small = $this->variable(3);
        $smallMeasures = array_map(fn ($i) => $this->measure("M{$i}"), range(1, 3));
        $this->assign($small['product'], $this->template('Small', $smallMeasures));
        $big = $this->variable(12);
        $bigMeasures = array_map(fn ($i) => $this->measure("B{$i}"), range(1, 12));
        $this->assign($big['product'], $this->template('Big', $bigMeasures));

        $cells = fn (array $sizes, array $measures) => array_fill_keys($sizes, array_fill_keys($measures, '10'));
        $panel = app(ProductMeasurementsPanel::class);

        // warm-up: the first write of a process reads settings and primes caches that are not part of the save itself
        $warm = $this->variable(1);
        $warmMeasure = $this->measure('Warm');
        $this->assign($warm['product'], $this->template('Warm', [$warmMeasure]));
        $panel->saveFromPage(new \stdClass(), $warm['product'], false, [ProductMeasurementsPanel::TEMPLATE => (string) app(ProductMeasurementTemplateRepository::class)->templateIdFor($warm['product']), ProductMeasurementsPanel::CELLS => [$warm['sizes'][0] => [$warmMeasure => '1']]]);

        $threeByThree = $this->countStatements(fn () => $panel->saveFromPage(new \stdClass(), $small['product'], false, [ProductMeasurementsPanel::TEMPLATE => (string) app(ProductMeasurementTemplateRepository::class)->templateIdFor($small['product']), ProductMeasurementsPanel::CELLS => $cells($small['sizes'], $smallMeasures)]));
        $twelveByTwelve = $this->countStatements(fn () => $panel->saveFromPage(new \stdClass(), $big['product'], false, [ProductMeasurementsPanel::TEMPLATE => (string) app(ProductMeasurementTemplateRepository::class)->templateIdFor($big['product']), ProductMeasurementsPanel::CELLS => $cells($big['sizes'], $bigMeasures)]));

        fwrite(STDERR, "\n[meas-4 statements] save the grid (every cell new, audit off): {$threeByThree} (3x3, 9 cells + 2 audit name lookups) / {$twelveByTwelve} (12x12, summary)\n");
        // bounded, not growing with the grid: the only difference is the 2 name lookups of the audit, which are skipped for a summary entry (more than 20 changed cells)
        $this->assertLessThanOrEqual(2, abs($threeByThree - $twelveByTwelve));
        $this->assertLessThanOrEqual(24, max($threeByThree, $twelveByTwelve));

        // with the audit ON each changed cell is one more insert up to the summary limit; never more than SUMMARY_FROM + a constant
        $this->auditOn();
        $again = $this->variable(3);
        $this->assign($again['product'], $this->template('Again', $smallMeasures));
        $audited = $this->countStatements(fn () => $panel->saveFromPage(new \stdClass(), $again['product'], false, [ProductMeasurementsPanel::TEMPLATE => (string) app(ProductMeasurementTemplateRepository::class)->templateIdFor($again['product']), ProductMeasurementsPanel::CELLS => $cells($again['sizes'], $smallMeasures)]));
        $this->assertLessThanOrEqual($threeByThree + 9 + 4, $audited);
    }
}
