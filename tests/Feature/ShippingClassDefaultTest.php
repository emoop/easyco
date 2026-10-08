<?php

namespace Tests\Feature;

use App\Filament\Resources\ShippingClassResource;
use App\Filament\Resources\ShippingClassResource\Pages\CreateShippingClass;
use App\Filament\Resources\ShippingClassResource\Pages\EditShippingClass;
use App\Filament\Resources\ShippingClassResource\Pages\ListShippingClasses;
use App\Services\Exceptions\ShippingClassDefaultException;
use App\Services\Exceptions\ShippingClassInvalidException;
use App\Services\Exceptions\ShippingClassNotFoundException;
use App\Services\ShippingClassInput;
use App\Services\ShippingClassWriter;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
use EasyCo\Shipping\Persistence\Eloquent\ShippingClassModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Stage 5e: the store's DEFAULT shipping class — at most one, guaranteed by the database; swapped atomically; one
 * audit entry and one hook per change; never deleted while it is the default; created in one step on an empty store.
 */
class ShippingClassDefaultTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    private function writer(): ShippingClassWriter
    {
        return app(ShippingClassWriter::class);
    }

    private function classId(string $code, ?string $name = null): string
    {
        $this->shippingClass($code, $name ?? ucfirst($code));

        return (string) app(ShippingClassRepository::class)->findByCode($code)->id();
    }

    private function defaultCodes(): array
    {
        return DB::table('shipping_classes')->where('is_default', 1)->pluck('code')->all();
    }

    private function hookNames(): array
    {
        return array_map(static fn (array $call): string => $call[0], $this->hookCalls);
    }

    // ---- the invariant, at the database -------------------------------------------------------------------------

    public function test_no_class_is_a_default_to_begin_with(): void
    {
        $this->classId('heavy');

        $this->assertSame([], $this->defaultCodes());
        $this->assertNull(app(ShippingClassRepository::class)->findDefault());
    }

    public function test_the_database_refuses_a_second_default_with_an_unique_violation(): void
    {
        $a = $this->classId('a');
        $b = $this->classId('b');
        DB::table('shipping_classes')->where('id', $a)->update(['is_default' => 1, 'default_marker' => 1]);

        try {
            DB::table('shipping_classes')->where('id', $b)->update(['is_default' => 1, 'default_marker' => 1]);
            $this->fail('a second default must be impossible');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
            $this->assertContains((int) $exception->errorInfo[1], [1062, 19]);
        }

        $this->assertSame(['a'], $this->defaultCodes());
    }

    public function test_the_check_keeps_the_flag_and_the_marker_in_step(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('the CHECK exists on MySQL/MariaDB only');
        }

        $a = $this->classId('a');

        try {
            DB::table('shipping_classes')->where('id', $a)->update(['is_default' => 1]); // marker still NULL
            $this->fail('the CHECK should have refused it');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame(3819, (int) $exception->errorInfo[1]);
        }
    }

    // ---- the writer ---------------------------------------------------------------------------------------------

    public function test_setting_a_default_writes_one_audit_entry_and_fires_one_hook_after_the_commit(): void
    {
        $this->activityLogOn();
        $a = $this->classId('a');
        $baseline = $this->spyOnClassHooks();

        $this->assertTrue($this->writer()->setDefault($a));

        $this->assertSame(['a'], $this->defaultCodes());
        $rows = array_values(array_filter($this->classAuditRows(), fn ($row) => $row->field === 'is_default'));
        $this->assertCount(1, $rows);
        $this->assertSame($a, $rows[0]->entity_id);
        $this->assertSame([null, 'a'], [$rows[0]->old_value, $rows[0]->new_value]);
        $this->assertSame(['shipping.class.default_changed'], $this->hookNames());
        $this->assertSame([null, 'a'], $this->hookCalls[0][1]);
        $this->assertSame($baseline, $this->hookCalls[0][2]);
    }

    public function test_a_new_default_replaces_the_old_one_in_one_step(): void
    {
        $this->activityLogOn();
        $a = $this->classId('a');
        $b = $this->classId('b');
        $this->writer()->setDefault($a);
        $this->spyOnClassHooks();

        $this->writer()->setDefault($b);

        $this->assertSame(['b'], $this->defaultCodes(), 'exactly one default, the new one');
        $this->assertSame(['a', 'b'], $this->hookCalls[0][1], 'the hook names the old and the new code');
        $this->assertSame(1, DB::table('shipping_classes')->whereNotNull('default_marker')->count());
        $this->assertSame('b', app(ShippingClassRepository::class)->findDefault()->code());
    }

    public function test_the_swap_is_atomic_a_failure_leaves_the_old_default_in_place(): void
    {
        $this->activityLogOn();
        $a = $this->classId('a');
        $this->writer()->setDefault($a);
        $this->spyOnClassHooks();

        // an unknown id is refused BEFORE anything is cleared
        try {
            $this->writer()->setDefault('999999');
            $this->fail('an unknown class must be refused');
        } catch (ShippingClassNotFoundException) {
        }

        $this->assertSame(['a'], $this->defaultCodes());

        // and a failure in the middle of the swap rolls the clearing back too
        $b = $this->classId('b');
        $broken = new class(app(ShippingClassRepository::class)) implements ShippingClassRepository {
            public function __construct(private readonly ShippingClassRepository $inner)
            {
            }

            public function save(\EasyCo\Shipping\ShippingClass $class): void
            {
                $this->inner->save($class);
            }

            public function findById(string $id): ?\EasyCo\Shipping\ShippingClass
            {
                return $this->inner->findById($id);
            }

            public function findByCode(string $code): ?\EasyCo\Shipping\ShippingClass
            {
                return $this->inner->findByCode($code);
            }

            public function all(): array
            {
                return $this->inner->all();
            }

            public function findDefault(): ?\EasyCo\Shipping\ShippingClass
            {
                return $this->inner->findDefault();
            }

            public function markDefault(?string $id): void
            {
                $this->inner->markDefault(null); // the clearing happens ...
                throw new \RuntimeException('boom'); // ... and then the set fails
            }

            public function delete(string $id): void
            {
                $this->inner->delete($id);
            }
        };

        $writer = new ShippingClassWriter($broken, app(\App\Services\ShippingClassUsageReader::class), app(\App\Services\ActivityLogger::class));

        try {
            $writer->setDefault($b);
            $this->fail('the swap should have failed');
        } catch (\RuntimeException) {
        }

        $this->assertSame(['a'], $this->defaultCodes(), 'the clearing was rolled back with the failed set');
        $this->assertSame([], $this->hookCalls, 'no hook for a failed write');
    }

    public function test_setting_the_current_default_again_or_clearing_none_writes_nothing(): void
    {
        $this->activityLogOn();
        $a = $this->classId('a');
        $this->writer()->setDefault($a);
        $auditBefore = count($this->classAuditRows());
        $this->spyOnClassHooks();

        $this->assertFalse($this->writer()->setDefault($a));
        $this->writer()->clearDefault();
        $this->assertFalse($this->writer()->clearDefault(), 'nothing left to clear');

        $this->assertCount($auditBefore + 1, $this->classAuditRows(), 'only the first clear wrote');
        $this->assertSame(['shipping.class.default_changed'], $this->hookNames());
        $this->assertSame(['a', null], $this->hookCalls[0][1]);
        $this->assertSame([], $this->defaultCodes());
    }

    public function test_the_default_class_cannot_be_deleted_but_another_one_can(): void
    {
        $a = $this->classId('a', 'Alpha');
        $b = $this->classId('b');
        $this->writer()->setDefault($a);
        $this->spyOnClassHooks();

        try {
            $this->writer()->delete($a);
            $this->fail('the default class must not be deleted');
        } catch (ShippingClassDefaultException $exception) {
            $this->assertStringContainsString('Alpha', $exception->getMessage());
            $this->assertStringContainsString('Make another class the default first', $exception->getMessage());
        }

        $this->assertSame(2, DB::table('shipping_classes')->count());
        $this->assertSame([], $this->hookCalls);

        App::setLocale('bg');
        try {
            $this->writer()->delete($a);
        } catch (ShippingClassDefaultException $exception) {
            $this->assertStringContainsString('Първо направете друг клас такъв', $exception->getMessage());
        }

        $this->writer()->delete($b);
        $this->assertSame(1, DB::table('shipping_classes')->count());

        $this->writer()->clearDefault();
        $this->writer()->delete($a);
        $this->assertSame(0, DB::table('shipping_classes')->count());
    }

    // ---- the first class in one step ------------------------------------------------------------------------------

    public function test_the_default_class_is_created_in_one_step_only_on_an_empty_store(): void
    {
        $this->spyOnClassHooks();

        $class = $this->writer()->createDefault();

        $this->assertSame(['Standard', 'standard'], [$class->name(), $class->code()]);
        $this->assertSame(['standard'], $this->defaultCodes());
        $this->assertSame(['shipping.class.created', 'shipping.class.default_changed'], $this->hookNames());

        try {
            $this->writer()->createDefault();
            $this->fail('classes exist: nothing may be created');
        } catch (ShippingClassInvalidException $exception) {
            $this->assertSame(__('shipping.classes.errors.default_only_when_empty'), $exception->messageFor('code'));
        }

        $this->assertSame(1, DB::table('shipping_classes')->count());
    }

    public function test_the_created_default_is_named_in_the_store_locale(): void
    {
        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set('site.locale', 'bg');

        $class = $this->writer()->createDefault();

        $this->assertSame('Стандартен', $class->name());
    }

    public function test_a_custom_name_may_be_given_and_is_validated_like_any_name(): void
    {
        $this->assertSame('Normal parcels', $this->writer()->createDefault('Normal parcels')->name());

        DB::table('shipping_classes')->delete();

        $this->expectException(ShippingClassInvalidException::class);
        $this->writer()->createDefault("Bad\nname");
    }

    // ---- the screens --------------------------------------------------------------------------------------------

    public function test_the_class_form_has_the_default_toggle_with_a_fact_line_and_the_list_a_plain_marker(): void
    {
        $this->actingAsStaff('Administrator');
        $a = $this->classId('a', 'Alpha');
        $this->classId('b', 'Beta');
        $this->writer()->setDefault($a);

        $html = html_entity_decode(Livewire::test(CreateShippingClass::class)->assertFormFieldExists('is_default')->html());
        $this->assertStringContainsString('Use as default class', $html);
        $this->assertStringContainsString('Only one class can be the default.', $html);

        // the list marks the default with plain text (no colour), and only that class
        Livewire::test(ListShippingClasses::class)
            ->assertTableColumnFormattedStateSet('is_default', 'default', record: ShippingClassModel::query()->findOrFail($a))
            ->assertTableColumnFormattedStateSet('is_default', '', record: ShippingClassModel::query()->where('code', 'b')->firstOrFail());

        App::setLocale('bg');
        $bg = html_entity_decode(Livewire::test(CreateShippingClass::class)->html());
        $this->assertStringContainsString('Ползвай като клас по подразбиране', $bg);
    }

    public function test_the_toggle_on_the_edit_form_makes_and_unmakes_the_default_through_the_writer(): void
    {
        $this->actingAsStaff('Administrator');
        $this->activityLogOn();
        $a = $this->classId('a');
        $b = $this->classId('b');

        Livewire::test(EditShippingClass::class, ['record' => $a])->assertFormSet(['is_default' => false])->fillForm(['is_default' => true])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['a'], $this->defaultCodes());

        Livewire::test(EditShippingClass::class, ['record' => $b])->fillForm(['is_default' => true])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['b'], $this->defaultCodes(), 'a swap, not a second default');

        Livewire::test(EditShippingClass::class, ['record' => $b])->assertFormSet(['is_default' => true])->fillForm(['is_default' => false])->call('save');
        $this->assertSame([], $this->defaultCodes());

        $this->assertCount(3, array_filter($this->classAuditRows(), fn ($row) => $row->field === 'is_default'), 'one audit entry per change');
    }

    public function test_a_new_class_can_be_created_as_the_default(): void
    {
        $this->actingAsStaff('Administrator');

        Livewire::test(CreateShippingClass::class)
            ->fillForm(['name' => 'Standard', 'code' => 'standard', 'is_default' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['standard'], $this->defaultCodes());
    }

    public function test_deleting_the_default_from_the_list_is_a_translated_refusal(): void
    {
        $this->actingAsStaff('Administrator');
        $a = $this->classId('a', 'Alpha');
        $this->writer()->setDefault($a);
        $message = (new ShippingClassDefaultException('Alpha'))->getMessage();

        Livewire::test(ListShippingClasses::class)
            ->callTableAction('delete_class', ShippingClassModel::query()->findOrFail($a))
            ->assertNotified(\Filament\Notifications\Notification::make()->title(__('shipping.classes.notice.refused'))->body($message)->danger());

        $this->assertSame(1, DB::table('shipping_classes')->count());
    }

    public function test_an_empty_list_offers_the_one_step_default_class_with_explicit_buttons(): void
    {
        $this->actingAsStaff('Administrator');

        $component = Livewire::test(ListShippingClasses::class)->assertSee(__('shipping.classes.actions.create_default'));
        $modal = html_entity_decode($component->mountAction('create_default_class')->getMountedActionModalHtml());

        $this->assertStringContainsString('Create the default class', $modal);
        $this->assertStringContainsString(__('orders.modal.close'), $modal);

        $component->callMountedAction()->assertNotified(__('shipping.classes.notice.default_created'));

        $this->assertSame(['standard'], $this->defaultCodes());

        // with a class in the store the action is gone
        Livewire::test(ListShippingClasses::class)->assertActionHidden('create_default_class');

        App::setLocale('bg');
        DB::table('shipping_classes')->delete();
        Livewire::test(ListShippingClasses::class)->assertSee('Създай класа по подразбиране');
    }
}
