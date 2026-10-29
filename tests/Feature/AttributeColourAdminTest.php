<?php

namespace Tests\Feature;

use App\Filament\Resources\AttributeDefinitionResource\Pages\CreateAttributeDefinition;
use App\Filament\Resources\AttributeValueResource;
use App\Filament\Resources\AttributeValueResource\Pages\CreateAttributeValue;
use App\Filament\Resources\AttributeValueResource\Pages\EditAttributeValue;
use App\Filament\Resources\AttributeValueResource\Pages\ListAttributeValues;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Enums\AttributeDisplayType;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AT-1 (admin): the attribute's «Как се показва» select (three Bulgarian options, a hint), and the colour picker of a VALUE that shows only for a «Цвят (кръгчета)» attribute, with «Без цвят», the muted hint and
 * a dot in the list. A hidden picker keeps the stored colour.
 */
class AttributeColourAdminTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $role = 'Administrator'): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $found = $roleRepository->findSystemRoleByName($role);

        if ($found === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $found = $roleRepository->findSystemRoleByName($role);
        }

        $staff = Staff::create(strtolower(str_replace(' ', '.', $role)).'@example.com', app(PasswordHasher::class)->hash('password123'), $role, $found);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');

        return StaffPanelUser::find($staff->id());
    }

    private function definition(AttributeDisplayType $display, string $code = 'colour'): string
    {
        $definition = new AttributeDefinition(id: null, code: $code, name: ucfirst($code), type: AttributeType::SELECT, displayType: $display);
        app(AttributeDefinitionRepository::class)->save($definition);

        return (string) $definition->id();
    }

    private function value(string $definitionId, string $name, ?string $colour = null): string
    {
        $value = new AttributeValue(null, $definitionId, $name, 0, null, $colour);
        app(AttributeValueRepository::class)->save($value);

        return (string) $value->id();
    }

    private function colourOf(string $id): ?string
    {
        return DB::table('catalog_attribute_values')->where('id', $id)->value('colour');
    }

    public function test_the_attribute_form_offers_the_three_bulgarian_options_and_saves_the_choice(): void
    {
        $this->signIn();
        app()->setLocale('bg');

        $html = Livewire::test(CreateAttributeDefinition::class)->html();

        foreach (['Как се показва', 'Падащ списък', 'Бутони', 'Цвят (кръгчета)', 'подходящо за размери'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        Livewire::test(CreateAttributeDefinition::class)
            ->fillForm(['code' => 'colour', 'name' => 'Цвят', 'type' => 'select', 'display_type' => 'swatch'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('swatch', DB::table('catalog_attribute_definitions')->where('code', 'colour')->value('display_type'));
    }

    public function test_the_picker_shows_only_for_a_colour_attribute(): void
    {
        $this->signIn();
        $swatch = $this->definition(AttributeDisplayType::SWATCH, 'colour');
        $buttons = $this->definition(AttributeDisplayType::BUTTONS, 'size');

        Livewire::test(CreateAttributeValue::class)->assertFormFieldIsHidden('colour');
        Livewire::test(CreateAttributeValue::class)->fillForm(['attribute_definition_id' => $swatch])->assertFormFieldIsVisible('colour');
        Livewire::test(CreateAttributeValue::class)->fillForm(['attribute_definition_id' => $buttons])->assertFormFieldIsHidden('colour');
    }

    public function test_a_colour_is_saved_lower_case_and_the_muted_hint_shows_while_there_is_none(): void
    {
        $this->signIn();
        app()->setLocale('bg');
        $swatch = $this->definition(AttributeDisplayType::SWATCH);

        $page = Livewire::test(CreateAttributeValue::class)->fillForm(['attribute_definition_id' => $swatch, 'value' => 'Червен']);
        $this->assertStringContainsString('няма цвят — ще се покаже като текст', $page->html());

        $page->fillForm(['colour' => '#C62828'])->call('create')->assertHasNoFormErrors();

        $this->assertSame('#c62828', DB::table('catalog_attribute_values')->where('value', 'Червен')->value('colour'));
    }

    public function test_a_value_without_a_colour_can_be_saved_and_coloured_later(): void
    {
        $this->signIn();
        $swatch = $this->definition(AttributeDisplayType::SWATCH);
        $id = $this->value($swatch, 'Син');

        Livewire::test(EditAttributeValue::class, ['record' => $id])->fillForm(['colour' => '#1565c0'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('#1565c0', $this->colourOf($id));
    }

    public function test_без_цвят_clears_the_colour(): void
    {
        $this->signIn();
        $id = $this->value($this->definition(AttributeDisplayType::SWATCH), 'Червен', '#c62828');

        Livewire::test(EditAttributeValue::class, ['record' => $id])
            ->assertFormSet(['colour' => '#c62828'])
            ->callAction(TestAction::make('clear_colour')->schemaComponent('colour'))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($this->colourOf($id));
    }

    public function test_the_colour_stays_while_the_type_is_not_swatch_and_saving_does_not_touch_it(): void
    {
        $this->signIn();
        $definition = $this->definition(AttributeDisplayType::SWATCH);
        $id = $this->value($definition, 'Червен', '#c62828');

        DB::table('catalog_attribute_definitions')->where('id', $definition)->update(['display_type' => 'buttons']);

        Livewire::test(EditAttributeValue::class, ['record' => $id])
            ->assertFormFieldIsHidden('colour')
            ->fillForm(['value' => 'Тъмночервен'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('#c62828', $this->colourOf($id), 'a hidden picker never clears the colour');

        DB::table('catalog_attribute_definitions')->where('id', $definition)->update(['display_type' => 'swatch']);
        Livewire::test(EditAttributeValue::class, ['record' => $id])->assertFormSet(['colour' => '#c62828']);
    }

    public function test_a_refused_colour_shows_a_field_error_and_saves_nothing(): void
    {
        $this->signIn();
        $id = $this->value($this->definition(AttributeDisplayType::SWATCH), 'Червен', '#c62828');

        foreach (['#fff', 'red', '1;color:red', '#12345'] as $bad) {
            Livewire::test(EditAttributeValue::class, ['record' => $id])
                ->fillForm(['value' => 'Нов', 'colour' => $bad])
                ->call('save')
                ->assertHasErrors();
        }

        $this->assertSame(['Червен', '#c62828'], [DB::table('catalog_attribute_values')->where('id', $id)->value('value'), $this->colourOf($id)]);
    }

    public function test_the_list_shows_a_colour_dot_only_for_a_colour_attribute(): void
    {
        $this->signIn();
        $swatch = $this->definition(AttributeDisplayType::SWATCH, 'colour');
        $buttons = $this->definition(AttributeDisplayType::BUTTONS, 'size');
        $red = $this->value($swatch, 'Червен', '#c62828');
        $none = $this->value($swatch, 'Без');
        $hidden = $this->value($buttons, 'XL', '#123456');

        $html = Livewire::test(ListAttributeValues::class)->assertCanSeeTableRecords(\EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel::whereIn('id', [$red, $none, $hidden])->get())->html();

        $this->assertStringContainsString('#c62828', $html);
        $this->assertStringNotContainsString('#123456', $html, 'a value of a non-colour attribute shows no dot, whatever is stored');
    }

    public function test_a_view_only_member_cannot_open_the_edit_form(): void
    {
        $this->signIn('Product Entry');
        $id = $this->value($this->definition(AttributeDisplayType::SWATCH), 'Червен');

        $this->get(AttributeValueResource::getUrl('edit', ['record' => $id]))->assertForbidden();
    }
}
