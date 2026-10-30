<?php

namespace Tests\Feature;

use App\Filament\Resources\AttributeDefinitionResource\Pages\CreateAttributeDefinition;
use App\Filament\Resources\AttributeDefinitionResource\Pages\EditAttributeDefinition;
use App\Filament\Resources\AttributeDefinitionResource\Pages\ListAttributeDefinitions;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\FilterDisplay;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CT-2 (admin): the attribute form has «Може да се филтрира в магазина» (default off) and «Как се показват стойностите във филтъра» (visible only while the switch is on).
 * A filter on a text, number or yes/no attribute is refused on the field; saving without touching the two fields keeps them; the list has a «Филтър» column.
 */
class AttributeFilterAdminTest extends TestCase
{
    use RefreshDatabase;

    private function signInAdministrator(): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create('administrator@example.com', app(PasswordHasher::class)->hash('password123'), 'Administrator', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');

        return StaffPanelUser::find($staff->id());
    }

    private function stored(string $code): ?AttributeDefinition
    {
        return collect(app(AttributeDefinitionRepository::class)->all())->first(fn (AttributeDefinition $d) => $d->code() === $code);
    }

    public function test_an_administrator_creates_a_filter_with_text_links_and_it_is_saved(): void
    {
        $this->signInAdministrator();

        Livewire::test(CreateAttributeDefinition::class)
            ->fillForm(['code' => 'size', 'name' => 'Size', 'type' => AttributeType::SELECT->value, 'is_filterable' => true, 'filter_display' => 'links'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->stored('size')->isFilterable());
        $this->assertSame(FilterDisplay::LINKS, $this->stored('size')->filterDisplay());
    }

    public function test_the_form_offers_exactly_the_three_looks_of_a_filter_with_their_labels(): void
    {
        app()->setLocale('bg');
        $options = (new \ReflectionMethod(\App\Filament\Resources\AttributeDefinitionResource::class, 'filterDisplayOptions'))->invoke(null);

        $this->assertSame(['list' => 'Списък с отметки', 'links' => 'Текстови връзки', 'swatches' => 'Цветни кръгчета'], $options);
        $this->assertSame('Как изглежда филтърът във витрината', __('attribute_definitions.fields.filter_display'));
    }

    public function test_a_new_attribute_without_touching_the_fields_is_not_a_filter(): void
    {
        $this->signInAdministrator();

        Livewire::test(CreateAttributeDefinition::class)
            ->fillForm(['code' => 'material', 'name' => 'Material', 'type' => AttributeType::SELECT->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse($this->stored('material')->isFilterable());
        $this->assertSame(FilterDisplay::LIST, $this->stored('material')->filterDisplay());
    }

    public function test_a_filter_on_a_text_attribute_is_refused_on_its_field_and_nothing_is_stored(): void
    {
        $this->signInAdministrator();

        Livewire::test(CreateAttributeDefinition::class)
            ->fillForm(['code' => 'note', 'name' => 'Note', 'type' => AttributeType::TEXT->value, 'is_filterable' => true])
            ->call('create')
            ->assertHasErrors(['data.is_filterable']);

        $this->assertNull($this->stored('note'));
    }

    public function test_an_administrator_changes_the_display_to_swatches_and_it_is_saved(): void
    {
        $this->signInAdministrator();
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT, filterable: true, filterDisplay: FilterDisplay::LINKS);
        app(AttributeDefinitionRepository::class)->save($definition);

        Livewire::test(EditAttributeDefinition::class, ['record' => $definition->id()])
            ->fillForm(['filter_display' => 'swatches'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(FilterDisplay::SWATCHES, $this->stored('color')->filterDisplay());
    }

    public function test_switching_the_filter_off_keeps_the_stored_display_and_saving_without_touching_keeps_both(): void
    {
        $this->signInAdministrator();
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT, filterable: true, filterDisplay: FilterDisplay::SWATCHES);
        app(AttributeDefinitionRepository::class)->save($definition);

        Livewire::test(EditAttributeDefinition::class, ['record' => $definition->id()])
            ->fillForm(['name' => 'Colour'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame([true, FilterDisplay::SWATCHES], [$this->stored('color')->isFilterable(), $this->stored('color')->filterDisplay()], 'saving without the two fields keeps them');

        Livewire::test(EditAttributeDefinition::class, ['record' => $definition->id()])
            ->fillForm(['is_filterable' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $row = DB::table('catalog_attribute_definitions')->where('code', 'color')->first();
        $this->assertSame([0, 'swatches'], [(int) $row->is_filterable, $row->filter_display], 'off: the switch is off and the hidden display is not lost');
    }

    public function test_the_list_has_the_filter_column(): void
    {
        $this->signInAdministrator();
        app(AttributeDefinitionRepository::class)->save(new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT, filterable: true));

        Livewire::test(ListAttributeDefinitions::class)
            ->assertCanRenderTableColumn('is_filterable')
            ->assertCountTableRecords(1);
    }
}
