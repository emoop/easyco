<?php

namespace Tests\Feature;

use App\Filament\Resources\MeasureResource;
use App\Filament\Resources\MeasureResource\Pages\CreateMeasure;
use App\Filament\Resources\MeasureResource\Pages\EditMeasure;
use App\Filament\Resources\MeasureResource\Pages\ListMeasures;
use App\Filament\Resources\MeasurementTemplateResource;
use App\Filament\Resources\MeasurementTemplateResource\Pages\CreateMeasurementTemplate;
use App\Filament\Resources\MeasurementTemplateResource\Pages\EditMeasurementTemplate;
use App\Filament\Resources\MeasurementTemplateResource\Pages\ListMeasurementTemplates;
use App\Filament\StaffPanelUser;
use App\Models\ActivityLogModel;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\MeasureRepository;
use EasyCo\Catalog\Contracts\MeasurementTemplateRepository;
use EasyCo\Catalog\Contracts\ProductMeasurementTemplateRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Measure;
use EasyCo\Catalog\MeasurementTemplate;
use EasyCo\Catalog\Persistence\Eloquent\MeasureModel;
use EasyCo\Catalog\Persistence\Eloquent\MeasurementTemplateModel;
use EasyCo\Catalog\Product;
use EasyCo\Extensibility\Hook;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MEAS-3: the «Показатели» and «Шаблони за размери» screens — list (columns, archived filter, usage numbers, statement counts), create / edit / refusals, archive / restore, delete, reorder, an archived measure
 * kept on save, translated refusals (every reason has a key), permission 403, audit entries (and none on a refusal), hooks once after the commit.
 */
class MeasurementAdminTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    /** @var list<string> */
    private array $fired = [];

    private function staff(string $roleName): StaffPanelUser
    {
        $roles = app(RoleRepository::class);
        $role = $roles->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roles);
            $role = $roles->findSystemRoleByName($roleName);
        }

        $staff = Staff::create(strtolower(str_replace(' ', '.', $roleName)).'@example.com', app(PasswordHasher::class)->hash('password123'), $roleName, $role);
        app(StaffRepository::class)->save($staff);

        return StaffPanelUser::find($staff->id());
    }

    private function admin(): StaffPanelUser
    {
        $staff = $this->staff('Administrator');
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    private function auditOn(): void
    {
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
    }

    private function measure(string $name, string $unit = 'cm', int $sort = 0): Measure
    {
        $measure = Measure::create($name, $unit, $sort);
        app(MeasureRepository::class)->save($measure);

        return $measure;
    }

    /** @param Measure[] $measures */
    private function template(string $name, array $measures = [], int $sort = 0): MeasurementTemplate
    {
        $template = MeasurementTemplate::create($name, array_map(fn (Measure $m) => (string) $m->id(), $measures), $sort);
        app(MeasurementTemplateRepository::class)->save($template);

        return $template;
    }

    /** One stored number for $measure (a real product, a real size value): locks the unit and counts as «стойност». */
    private function storeNumber(Measure $measure): void
    {
        $n = ++$this->counter;
        $definition = new AttributeDefinition(id: null, code: 'size'.$n, name: 'Size'.$n, type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'M', sortOrder: 0);
        app(AttributeValueRepository::class)->save($value);
        $product = Product::createSimple('P'.$n, 'P-'.$n, 'p-'.$n);
        app(ProductRepository::class)->save($product);

        DB::table('catalog_measurement_values')->insert([
            'product_id' => $product->id(), 'size_value_id' => $value->id(), 'measure_id' => $measure->id(), 'value_hundredths' => 9500, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assignProduct(MeasurementTemplate $template): void
    {
        $n = ++$this->counter;
        $product = Product::createSimple('Q'.$n, 'Q-'.$n, 'q-'.$n);
        app(ProductRepository::class)->save($product);
        app(ProductMeasurementTemplateRepository::class)->assign((string) $product->id(), (string) $template->id());
    }

    /** The simple repeater's form state: one ['measure_id' => id] row per measure. */
    private function rows(array $ids): array
    {
        return array_map(fn ($id) => ['measure_id' => (string) $id], array_values($ids));
    }

    private function spyOnHooks(): void
    {
        foreach ([
            'catalog.measure.saved', 'catalog.measure.archived', 'catalog.measure.deleted',
            'catalog.measurement_template.saved', 'catalog.measurement_template.archived', 'catalog.measurement_template.deleted',
        ] as $name) {
            Hook::action($name, function () use ($name): void {
                $this->fired[] = $name;
            });
        }
    }

    private function audit(string $entity): array
    {
        return ActivityLogModel::query()->where('entity_type', $entity)->orderBy('id')->get()
            ->map(fn ($row) => [$row->action, $row->field, $row->old_value, $row->new_value])->all();
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

    // ================================================================ A. measures

    public function test_the_measure_list_shows_units_usage_and_hides_archived_until_asked(): void
    {
        $this->admin();
        $chest = $this->measure('Chest', 'in', 1);
        $this->measure('Waist', 'cm', 2);
        $old = $this->measure('Old', 'mm', 3);
        $this->template('Tops', [$chest]);
        $this->storeNumber($chest);
        app(MeasureRepository::class)->save(tap(app(MeasureRepository::class)->findById((string) $old->id()), fn (Measure $m) => $m->archive()));

        $page = Livewire::test(ListMeasures::class)
            ->assertSee('Chest')->assertSee('Waist')->assertSee(trans_choice('measurements.used_by_templates', 1, ['count' => 1]).' · '.trans_choice('measurements.used_by_values', 1, ['count' => 1]))->assertSee(trans_choice('measurements.used_by_templates', 0, ['count' => 0]).' · '.trans_choice('measurements.used_by_values', 0, ['count' => 0]))
            ->assertDontSee('Old');

        $page->filterTable('show_archived', true)->assertSee('Old')->assertSee(__('measurements.status.archived'));
    }

    public function test_the_measure_list_statement_count_does_not_grow_with_the_rows(): void
    {
        $this->admin();
        $render = function (): int {
            return $this->countStatements(fn () => Livewire::test(ListMeasures::class)->assertOk());
        };

        for ($i = 1; $i <= 3; $i++) {
            $this->measure("M{$i}");
        }
        $render(); // warm-up (settings, staff)
        $three = $render();

        for ($i = 4; $i <= 12; $i++) {
            $this->measure("M{$i}");
        }
        $this->assertSame($three, $render(), 'the list must read a bounded number of statements');
    }

    public function test_creating_a_measure_goes_through_the_writer_with_audit_and_one_hook(): void
    {
        $this->admin();
        $this->auditOn();
        $this->spyOnHooks();

        Livewire::test(CreateMeasure::class)
            ->assertFormSet(['sort' => 1])
            ->fillForm(['name' => 'Chest', 'unit' => 'in', 'sort' => 4])
            ->call('create')->assertHasNoFormErrors();

        $stored = MeasureModel::firstOrFail();
        $this->assertSame(['Chest', 'in', 4, true], [$stored->name, $stored->unit, $stored->sort, $stored->is_active]);
        $this->assertSame(['catalog.measure.saved'], $this->fired);
        $this->assertSame([['created', null, null, null]], $this->audit('measure'));
    }

    public function test_a_duplicate_name_is_a_translated_field_error_and_writes_nothing(): void
    {
        $this->admin();
        $this->auditOn();
        $this->measure('Chest');
        $this->spyOnHooks();

        Livewire::test(CreateMeasure::class)
            ->fillForm(['name' => 'chest', 'unit' => 'cm', 'sort' => 1])
            ->call('create')->assertHasFormErrors(['name']);

        $this->assertSame(1, MeasureModel::count());
        $this->assertSame([], $this->fired);
        $this->assertSame([], $this->audit('measure'));
    }

    public function test_editing_a_measure_logs_old_to_new_per_field_and_an_unchanged_save_logs_nothing(): void
    {
        $this->admin();
        $measure = $this->measure('Chest', 'cm', 1);
        $this->auditOn();
        $this->spyOnHooks();

        Livewire::test(EditMeasure::class, ['record' => $measure->id()])
            ->fillForm(['name' => 'Bust', 'unit' => 'in', 'sort' => 7])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame([['updated', 'name', 'Chest', 'Bust'], ['updated', 'unit', 'cm', 'in'], ['updated', 'sort', '1', '7']], $this->audit('measure'));
        $this->assertSame(['catalog.measure.saved'], $this->fired);

        Livewire::test(EditMeasure::class, ['record' => $measure->id()])->call('save');
        $this->assertCount(3, $this->audit('measure'));
        $this->assertSame(['catalog.measure.saved'], $this->fired, 'an unchanged save fires nothing');
    }

    public function test_the_unit_select_is_disabled_when_numbers_exist_and_a_stale_form_is_refused_with_a_notice(): void
    {
        $this->admin();
        $this->auditOn();
        $measure = $this->measure('Chest', 'cm');
        $this->storeNumber($measure);

        Livewire::test(EditMeasure::class, ['record' => $measure->id()])
            ->assertSee(__('measurements.measure.unit_locked'))
            ->assertFormFieldIsDisabled('unit');

        // a form opened BEFORE the number was stored (or a tampered request): the repository refusal is a translated notice
        $free = $this->measure('Waist', 'cm');
        $page = Livewire::test(EditMeasure::class, ['record' => $free->id()])->assertFormFieldIsEnabled('unit');
        $this->storeNumber($free);

        $page->fillForm(['unit' => 'in'])->call('save')->assertNotified(__('measurements.measure.notice.refused'));

        $this->assertSame('cm', MeasureModel::find($free->id())->unit);
        $this->assertSame([], $this->audit('measure'));
    }

    public function test_archive_and_restore_ask_for_confirmation_and_keep_the_numbers(): void
    {
        $this->admin();
        $this->auditOn();
        $this->spyOnHooks();
        $measure = $this->measure('Chest');
        $this->storeNumber($measure);

        Livewire::test(ListMeasures::class)
            ->callTableAction('archive_measure', $measure->id())
            ->assertNotified(__('measurements.measure.notice.archived'));

        $this->assertFalse((bool) MeasureModel::find($measure->id())->is_active);
        $this->assertSame(1, DB::table('catalog_measurement_values')->count());

        Livewire::test(ListMeasures::class)->filterTable('show_archived', true)
            ->callTableAction('restore_measure', $measure->id());

        $this->assertTrue((bool) MeasureModel::find($measure->id())->is_active);
        $this->assertSame(['catalog.measure.archived', 'catalog.measure.saved'], $this->fired);
        $this->assertSame([['updated', 'is_active', '1', '0'], ['updated', 'is_active', '0', '1']], $this->audit('measure'));
    }

    public function test_an_unused_measure_is_deleted_and_a_used_one_is_hidden_from_delete_and_refused_by_the_server(): void
    {
        $this->admin();
        $this->spyOnHooks();
        $free = $this->measure('Free');
        $used = $this->measure('Used');
        $this->template('Tops', [$used]);

        Livewire::test(ListMeasures::class)
            ->assertTableActionHidden('delete_measure', $used->id())
            ->assertTableActionVisible('delete_measure', $free->id())
            ->callTableAction('delete_measure', $free->id())
            ->assertNotified(__('measurements.measure.notice.deleted'));

        $this->assertNull(MeasureModel::find($free->id()));
        $this->assertSame(['catalog.measure.deleted'], $this->fired);
        // the delete audit is written even with the activity log off (the deletion convention)
        $this->assertSame('deleted', $this->audit('measure')[0][0]);

        // the used one: the writer (the server) refuses, nothing is deleted or logged, and the refusal is shown as the translated text
        try {
            app(\App\Services\MeasureWriter::class)->delete((string) $used->id());
            $this->fail('a used measure must not be deleted');
        } catch (\EasyCo\Catalog\Exceptions\MeasurementRefusedException $e) {
            $this->assertSame(__('measurements.refusals.measure_in_use'), \App\Filament\Support\MeasurementRefusals::message($e));
        }
        $this->assertNotNull(MeasureModel::find($used->id()));
        $this->assertCount(1, $this->audit('measure'), 'a refusal writes no audit entry');
    }

    public function test_every_reason_the_two_exception_classes_can_produce_has_a_translation_in_both_languages(): void
    {
        $reasons = [];

        foreach (['InvalidMeasurementValueException', 'MeasurementRefusedException'] as $class) {
            $source = file_get_contents(base_path("packages/EasyCo/Catalog/src/Exceptions/{$class}.php"));
            preg_match_all("/new self\\(.*?,\\s*'([a-z_]+)'\\);/s", $source, $matches);
            $reasons = array_merge($reasons, $matches[1]);
        }

        $this->assertGreaterThanOrEqual(13, count(array_unique($reasons)), 'the reason scan found too few reasons (did the class change shape?)');

        foreach (['bg', 'en'] as $locale) {
            foreach (array_unique($reasons) as $reason) {
                $key = "measurements.refusals.{$reason}";
                $this->assertNotSame($key, __($key, [], $locale), "reason {$reason} has no {$locale} text");
            }
        }
    }

    public function test_a_staff_member_without_the_permission_gets_403_and_does_not_see_the_pages(): void
    {
        $viewOnly = Role::create('Measures view only', [\EasyCo\Staff\Enums\Permission::ORDER_MANAGE]);
        app(RoleRepository::class)->save($viewOnly);
        $staff = Staff::create('nobody@example.com', app(PasswordHasher::class)->hash('password123'), 'Nobody', $viewOnly);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');
        $measure = $this->measure('Chest');
        $template = $this->template('Tops');

        $this->get(MeasureResource::getUrl('index'))->assertForbidden();
        $this->get(MeasureResource::getUrl('create'))->assertForbidden();
        $this->get(MeasureResource::getUrl('edit', ['record' => $measure->id()]))->assertForbidden();
        $this->get(MeasurementTemplateResource::getUrl('index'))->assertForbidden();
        $this->get(MeasurementTemplateResource::getUrl('create'))->assertForbidden();
        $this->get(MeasurementTemplateResource::getUrl('edit', ['record' => $template->id()]))->assertForbidden();
        $this->assertFalse(MeasureResource::canViewAny());
        $this->assertFalse(MeasurementTemplateResource::canViewAny());
    }

    public function test_an_administrator_opens_the_screens_and_they_sit_in_the_catalog_group_after_the_attributes(): void
    {
        $this->admin();

        $this->get(MeasureResource::getUrl('index'))->assertOk();
        $this->get(MeasurementTemplateResource::getUrl('index'))->assertOk();
        $this->assertSame(\App\Filament\NavigationGroup::CATALOG, MeasureResource::getNavigationGroup());
        $this->assertSame(\App\Filament\NavigationGroup::CATALOG, MeasurementTemplateResource::getNavigationGroup());
        $this->assertGreaterThan(50, MeasureResource::getNavigationSort());
        $this->assertGreaterThan(MeasureResource::getNavigationSort(), MeasurementTemplateResource::getNavigationSort());
    }

    // ================================================================ B. templates

    public function test_the_template_list_shows_ordered_measures_products_draft_chip_and_hides_archived(): void
    {
        $this->admin();
        $chest = $this->measure('Chest');
        $waist = $this->measure('Waist');
        $tops = $this->template('Tops', [$waist, $chest]);
        $this->template('Draft');
        $gone = $this->template('Gone', [$chest]);
        app(MeasurementTemplateRepository::class)->save(tap(app(MeasurementTemplateRepository::class)->findById((string) $gone->id()), fn (MeasurementTemplate $t) => $t->archive()));
        $this->assignProduct($tops);
        $this->assignProduct($tops);

        $page = Livewire::test(ListMeasurementTemplates::class)
            ->assertSee('Waist · Chest')->assertSee(trans_choice('measurements.template.products_count', 2, ['count' => 2]))->assertSee(__('measurements.template.no_measures'))->assertDontSee('Gone');

        $page->filterTable('show_archived', true)->assertSee('Gone');
    }

    public function test_the_template_list_statement_count_does_not_grow_with_the_rows(): void
    {
        $this->admin();
        $m = $this->measure('Chest');
        $render = fn (): int => $this->countStatements(fn () => Livewire::test(ListMeasurementTemplates::class)->assertOk());

        for ($i = 1; $i <= 3; $i++) {
            $this->template("T{$i}", [$m]);
        }
        $render();
        $three = $render();

        for ($i = 4; $i <= 12; $i++) {
            $this->template("T{$i}", [$m]);
        }
        $this->assertSame($three, $render());
    }

    public function test_creating_a_template_keeps_the_order_and_a_template_without_measures_is_a_draft(): void
    {
        $this->admin();
        $this->auditOn();
        $this->spyOnHooks();
        $a = $this->measure('A');
        $b = $this->measure('B');
        $c = $this->measure('C');

        Livewire::test(CreateMeasurementTemplate::class)
            ->fillForm(['name' => 'Tops', 'sort' => 1, 'measures' => $this->rows([$c->id(), $a->id(), $b->id()])])
            ->call('create')->assertHasNoFormErrors();

        $stored = app(MeasurementTemplateRepository::class)->all()[0];
        $this->assertSame([(string) $c->id(), (string) $a->id(), (string) $b->id()], $stored->measureIds());

        Livewire::test(CreateMeasurementTemplate::class)
            ->fillForm(['name' => 'Draft', 'sort' => 2, 'measures' => []])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame(['catalog.measurement_template.saved', 'catalog.measurement_template.saved'], $this->fired);
        $this->assertSame([['created', null, null, null], ['created', null, null, null]], $this->audit('measurement_template'));
    }

    public function test_a_duplicate_measure_in_a_template_or_a_duplicate_name_is_refused_and_writes_nothing(): void
    {
        $this->admin();
        $this->auditOn();
        $a = $this->measure('A');
        $this->template('Tops', [$a]);
        $this->spyOnHooks();

        Livewire::test(CreateMeasurementTemplate::class)
            ->fillForm(['name' => 'Another', 'sort' => 1, 'measures' => $this->rows([$a->id(), $a->id()])])
            ->call('create')->assertHasFormErrors(['measures.1.measure_id']);

        // the data layer refuses it too (a request that bypasses the select): the form's own error on the list
        try {
            MeasurementTemplateResource::fieldError(\EasyCo\Catalog\Exceptions\InvalidMeasurementValueException::invalidMeasureList('a measure can be in a template only once'));
            $this->fail('expected a validation error');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('data.measures', $e->errors());
        }

        Livewire::test(CreateMeasurementTemplate::class)
            ->fillForm(['name' => 'tops', 'sort' => 1, 'measures' => []])
            ->call('create')->assertHasFormErrors(['name']);

        $this->assertSame(1, MeasurementTemplateModel::count());
        $this->assertSame([], $this->fired);
        $this->assertSame([], $this->audit('measurement_template'));
    }

    public function test_more_than_twenty_measures_are_refused(): void
    {
        $this->admin();
        $ids = [];
        for ($i = 1; $i <= 21; $i++) {
            $ids[] = (string) $this->measure("M{$i}")->id();
        }

        Livewire::test(CreateMeasurementTemplate::class)
            ->fillForm(['name' => 'Big', 'sort' => 1, 'measures' => $this->rows($ids)])
            ->call('create')->assertHasFormErrors(['measures']);

        $this->assertSame(0, MeasurementTemplateModel::count());
    }

    public function test_reordering_a_template_logs_the_old_and_new_list(): void
    {
        $this->admin();
        $a = $this->measure('A');
        $b = $this->measure('B');
        $template = $this->template('Tops', [$a, $b]);
        $this->auditOn();
        $this->spyOnHooks();

        Livewire::test(EditMeasurementTemplate::class, ['record' => $template->id()])
            ->assertFormSet(fn (array $state) => array_column(array_values($state['measures']), 'measure_id') === [(string) $a->id(), (string) $b->id()])
            ->fillForm(['measures' => $this->rows([$b->id(), $a->id()])])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame([(string) $b->id(), (string) $a->id()], app(MeasurementTemplateRepository::class)->findById((string) $template->id())->measureIds());
        $this->assertSame([['updated', 'measures', $a->id().','.$b->id(), $b->id().','.$a->id()]], $this->audit('measurement_template'));
        $this->assertSame(['catalog.measurement_template.saved'], $this->fired);
    }

    public function test_a_template_with_an_archived_measure_keeps_it_on_save_and_a_new_archived_one_is_refused(): void
    {
        $this->admin();
        $a = $this->measure('A');
        $old = $this->measure('Old');
        $template = $this->template('Tops', [$a, $old]);
        app(MeasureRepository::class)->save(tap(app(MeasureRepository::class)->findById((string) $old->id()), fn (Measure $m) => $m->archive()));
        $other = $this->measure('Other');
        app(MeasureRepository::class)->save(tap(app(MeasureRepository::class)->findById((string) $other->id()), fn (Measure $m) => $m->archive()));

        // the archived measure the template already has is shown (muted) and survives a rename
        Livewire::test(EditMeasurementTemplate::class, ['record' => $template->id()])
            ->assertSee(__('measurements.template.archived_marker'))
            ->fillForm(['name' => 'Tops 2'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame([(string) $a->id(), (string) $old->id()], app(MeasurementTemplateRepository::class)->findById((string) $template->id())->measureIds());

        // adding an archived measure the template does NOT have is refused (a tampered request)
        Livewire::test(EditMeasurementTemplate::class, ['record' => $template->id()])
            ->fillForm(['measures' => $this->rows([$a->id(), $old->id(), $other->id()])])
            ->call('save')->assertHasFormErrors(['measures.2.measure_id']);

        $this->assertCount(2, app(MeasurementTemplateRepository::class)->findById((string) $template->id())->measureIds());

        // and the page's own server rule (a request that bypasses the select)
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        MeasurementTemplateResource::measureIds([(string) $a->id(), (string) $old->id(), (string) $other->id()], [(string) $a->id(), (string) $old->id()]);
    }

    public function test_archive_restore_and_delete_of_templates_with_the_in_use_rule(): void
    {
        $this->admin();
        $this->auditOn();
        $this->spyOnHooks();
        $used = $this->template('Used');
        $free = $this->template('Free');
        $this->assignProduct($used);

        Livewire::test(ListMeasurementTemplates::class)
            ->assertTableActionHidden('delete_template', $used->id())
            ->assertTableActionVisible('delete_template', $free->id())
            ->callTableAction('archive_template', $used->id())->assertNotified(__('measurements.template.notice.archived'))
            ->callTableAction('delete_template', $free->id())->assertNotified(__('measurements.template.notice.deleted'));

        $this->assertNull(MeasurementTemplateModel::find($free->id()));
        $this->assertFalse((bool) MeasurementTemplateModel::find($used->id())->is_active);
        $this->assertSame(1, DB::table('catalog_product_measurement_templates')->count(), 'archiving never touches the products');

        Livewire::test(ListMeasurementTemplates::class)->filterTable('show_archived', true)
            ->callTableAction('restore_template', $used->id());
        $this->assertTrue((bool) MeasurementTemplateModel::find($used->id())->is_active);

        $this->assertSame(['catalog.measurement_template.archived', 'catalog.measurement_template.deleted', 'catalog.measurement_template.saved'], $this->fired);
        $this->assertSame(
            [['updated', 'is_active', '1', '0'], ['deleted', null, '{"id":"'.$free->id().'","name":"Free","measure_ids":[]}', null], ['updated', 'is_active', '0', '1']],
            $this->audit('measurement_template'),
        );

        // the used one: the server refuses, nothing is deleted, and the refusal is the translated text
        try {
            app(\App\Services\MeasurementTemplateWriter::class)->delete((string) $used->id());
            $this->fail('a used template must not be deleted');
        } catch (\EasyCo\Catalog\Exceptions\MeasurementRefusedException $e) {
            $this->assertSame(__('measurements.refusals.template_in_use'), \App\Filament\Support\MeasurementRefusals::message($e));
        }
        $this->assertNotNull(MeasurementTemplateModel::find($used->id()));
    }

    // ================================================================ MEAS-4: the template KIND

    public function test_the_kind_select_creates_a_single_template_shows_a_badge_and_the_two_examples_in_the_hint(): void
    {
        $this->admin();
        $this->auditOn();
        $thickness = $this->measure('Thickness', 'mm');

        Livewire::test(CreateMeasurementTemplate::class)
            ->assertFormSet(['kind' => 'by_size'])
            ->assertSee(__('measurements.template.help.kind'))
            ->fillForm(['name' => 'Phones', 'sort' => 1, 'kind' => 'single', 'measures' => $this->rows([$thickness->id()])])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame('single', MeasurementTemplateModel::where('name', 'Phones')->value('kind'));

        $this->template('Tops', [$thickness]);
        Livewire::test(ListMeasurementTemplates::class)
            ->assertSee(__('measurements.kinds.single'))
            ->assertSee(__('measurements.kinds.by_size'));
    }

    public function test_a_required_kind_cannot_be_empty_or_unknown(): void
    {
        $this->admin();

        Livewire::test(CreateMeasurementTemplate::class)
            ->fillForm(['name' => 'X', 'sort' => 1, 'kind' => null])
            ->call('create')->assertHasFormErrors(['kind' => 'required']);

        Livewire::test(CreateMeasurementTemplate::class)
            ->fillForm(['name' => 'X', 'sort' => 1, 'kind' => 'nonsense'])
            ->call('create')->assertHasFormErrors(['kind']);

        $this->assertSame(0, MeasurementTemplateModel::count());
    }

    public function test_the_kind_changes_while_no_number_exists_and_is_audited(): void
    {
        $this->admin();
        $this->auditOn();
        $template = $this->template('Tops', [$this->measure('Chest')]);
        $this->assignProduct($template);

        Livewire::test(EditMeasurementTemplate::class, ['record' => $template->id()])
            ->assertFormFieldIsEnabled('kind')
            ->fillForm(['kind' => 'single'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('single', MeasurementTemplateModel::find($template->id())->kind);
        $this->assertSame([['updated', 'kind', 'by_size', 'single']], $this->audit('measurement_template'));
    }

    public function test_the_kind_select_is_disabled_with_the_reason_when_a_product_holds_numbers_and_a_stale_form_is_refused(): void
    {
        $this->admin();
        $this->auditOn();
        $chest = $this->measure('Chest');
        $template = $this->template('Tops', [$chest]);
        $this->assignProduct($template);
        $this->storeNumberFor($template, $chest);

        Livewire::test(EditMeasurementTemplate::class, ['record' => $template->id()])
            ->assertSee(__('measurements.template.kind_locked'))
            ->assertFormFieldIsDisabled('kind');

        // a form opened BEFORE the first number was stored: the repository refusal is a translated notice and nothing is saved
        $free = $this->template('Dresses', [$chest]);
        $this->assignProduct($free);
        $page = Livewire::test(EditMeasurementTemplate::class, ['record' => $free->id()])->assertFormFieldIsEnabled('kind');
        $this->storeNumberFor($free, $chest);

        $page->fillForm(['kind' => 'single'])->call('save')->assertNotified(__('measurements.template.notice.refused'));

        $this->assertSame('by_size', MeasurementTemplateModel::find($free->id())->kind);
        $this->assertSame([], $this->audit('measurement_template'));
    }

    /** One stored number for a product that USES $template (the kind lock looks at products that use the template). */
    private function storeNumberFor(MeasurementTemplate $template, Measure $measure): void
    {
        $productId = (string) DB::table('catalog_product_measurement_templates')->where('template_id', $template->id())->value('product_id');
        $n = ++$this->counter;
        $definition = new AttributeDefinition(id: null, code: 'ksize'.$n, name: 'Size'.$n, type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'M', sortOrder: 0);
        app(AttributeValueRepository::class)->save($value);

        DB::table('catalog_measurement_values')->insert([
            'product_id' => $productId, 'size_value_id' => $value->id(), 'measure_id' => $measure->id(), 'value_hundredths' => 9500, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_grams_and_kilograms_are_offered_by_the_measure_screen(): void
    {
        $this->admin();

        Livewire::test(CreateMeasure::class)
            ->fillForm(['name' => 'Weight', 'unit' => 'g', 'sort' => 1])->call('create')->assertHasNoFormErrors();
        Livewire::test(CreateMeasure::class)
            ->fillForm(['name' => 'Heavy', 'unit' => 'kg', 'sort' => 2])->call('create')->assertHasNoFormErrors();

        $this->assertSame(['g', 'kg'], MeasureModel::orderBy('id')->pluck('unit')->all());
        Livewire::test(ListMeasures::class)->assertSee(__('measurements.units.g'))->assertSee(__('measurements.units.kg'));
    }
}
