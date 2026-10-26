<?php

namespace Tests\Feature;

use App\Filament\Resources\AttributeDefinitionResource\Pages\CreateAttributeDefinition;
use App\Filament\Resources\AttributeDefinitionResource\Pages\EditAttributeDefinition;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Enums\AttributeDisplayType;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CT-3 (admin): the attribute form has «Как се показва на страницата на продукта» (four labelled options, default «Падащо меню», always visible). Saving without touching it
 * keeps the stored value; an unknown value is refused on its own field.
 */
class AttributeDisplayTypeAdminTest extends TestCase
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

    public function test_a_new_attribute_without_touching_the_field_shows_its_values_as_a_dropdown(): void
    {
        $this->signInAdministrator();

        Livewire::test(CreateAttributeDefinition::class)
            ->fillForm(['code' => 'material', 'name' => 'Material', 'type' => AttributeType::SELECT->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(AttributeDisplayType::DROPDOWN, $this->stored('material')->displayType());
    }

    public function test_an_administrator_creates_an_attribute_with_buttons_and_it_is_saved(): void
    {
        $this->signInAdministrator();

        Livewire::test(CreateAttributeDefinition::class)
            ->fillForm(['code' => 'size', 'name' => 'Size', 'type' => AttributeType::SELECT->value, 'display_type' => 'buttons'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(AttributeDisplayType::BUTTONS, $this->stored('size')->displayType());
    }

    public function test_an_administrator_changes_the_display_to_swatch_and_saving_without_touching_keeps_it(): void
    {
        $this->signInAdministrator();
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT, displayType: AttributeDisplayType::BUTTONS);
        app(AttributeDefinitionRepository::class)->save($definition);

        Livewire::test(EditAttributeDefinition::class, ['record' => $definition->id()])
            ->fillForm(['name' => 'Colour'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(AttributeDisplayType::BUTTONS, $this->stored('color')->displayType(), 'saving without the field keeps it');

        Livewire::test(EditAttributeDefinition::class, ['record' => $definition->id()])
            ->fillForm(['display_type' => 'swatch'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(AttributeDisplayType::SWATCH, $this->stored('color')->displayType());
    }

    public function test_the_form_shows_the_field_with_the_four_labels(): void
    {
        $this->signInAdministrator();

        $html = Livewire::test(CreateAttributeDefinition::class)->html();

        // the test runs in English (the default locale): the four labels are the English ones
        $this->assertStringContainsString('How it is shown on the product page', $html);
        foreach (['Colour dots', 'Buttons', 'Drop-down list', 'Specifications only'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    public function test_an_unknown_display_is_refused_on_its_field_and_nothing_is_stored(): void
    {
        $this->signInAdministrator();

        Livewire::test(CreateAttributeDefinition::class)
            ->fillForm(['code' => 'bad', 'name' => 'Bad', 'type' => AttributeType::SELECT->value, 'display_type' => 'hologram'])
            ->call('create')
            ->assertHasErrors(['data.display_type']);

        $this->assertNull($this->stored('bad'));
    }
}
