<?php

namespace Tests\Feature;

use App\Filament\Resources\AttributeDefinitionResource;
use App\Filament\Resources\AttributeDefinitionResource\Pages\CreateAttributeDefinition;
use App\Filament\Resources\AttributeDefinitionResource\Pages\EditAttributeDefinition;
use App\Filament\Resources\AttributeDefinitionResource\Pages\ListAttributeDefinitions;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AttributeDefinitionResourceTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithRole(string $roleName): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName($roleName);
        }

        $email = strtolower(str_replace(' ', '.', $roleName)).'@example.com';
        $staff = Staff::create($email, app(PasswordHasher::class)->hash('password123'), $roleName, $role);
        app(StaffRepository::class)->save($staff);

        return StaffPanelUser::find($staff->id());
    }

    private function actingAsPanelAdministrator(): StaffPanelUser
    {
        $model = $this->staffWithRole('Administrator');
        $this->actingAs($model, 'staff');

        return $model;
    }

    public function test_creating_an_attribute_definition_persists_it_through_the_domain_layer(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateAttributeDefinition::class)
            ->fillForm(['code' => 'color', 'name' => 'Color', 'type' => AttributeType::SELECT->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $definition = app(AttributeDefinitionRepository::class)->all()[0] ?? null;

        $this->assertNotNull($definition);
        $this->assertSame('color', $definition->code());
        $this->assertSame('Color', $definition->name());
        $this->assertSame(AttributeType::SELECT, $definition->type());
    }

    public function test_editing_an_attribute_definition_renames_it_through_the_domain_layer(): void
    {
        $this->actingAsPanelAdministrator();

        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        Livewire::test(EditAttributeDefinition::class, ['record' => $definition->id()])
            ->fillForm(['name' => 'Colour'])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(AttributeDefinitionRepository::class)->findById($definition->id());

        $this->assertSame('Colour', $reloaded->name());
    }

    /**
     * The code/type fields are ->disabledOn('edit') — confirms they
     * stay exactly what they were before an edit attempt that tries to
     * submit different values for them, since Filament omits a disabled
     * field's key from $data entirely.
     */
    public function test_code_and_type_stay_unchanged_after_an_edit_attempt_that_tries_to_change_them(): void
    {
        $this->actingAsPanelAdministrator();

        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $component = Livewire::test(EditAttributeDefinition::class, ['record' => $definition->id()]);

        $component->assertFormFieldDisabled('code');
        $component->assertFormFieldDisabled('type');

        // fillForm still sets the raw Livewire property, but the field's
        // own disabled+not-dehydrated state means handleRecordUpdate()
        // never sees it — this is what's actually asserted below.
        $component->fillForm(['name' => 'Colour', 'code' => 'hue', 'type' => AttributeType::TEXT->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(AttributeDefinitionRepository::class)->findById($definition->id());

        $this->assertSame('color', $reloaded->code());
        $this->assertSame(AttributeType::SELECT, $reloaded->type());
        $this->assertSame('Colour', $reloaded->name());
    }

    public function test_a_duplicate_code_is_rejected_on_create(): void
    {
        $this->actingAsPanelAdministrator();

        app(AttributeDefinitionRepository::class)->save(
            new AttributeDefinition(id: null, code: 'colliding-code', name: 'Color', type: AttributeType::SELECT)
        );

        Livewire::test(CreateAttributeDefinition::class)
            ->fillForm(['code' => 'colliding-code', 'name' => 'Material', 'type' => AttributeType::SELECT->value])
            ->call('create')
            ->assertHasFormErrors(['code']);
    }

    public function test_the_real_permission_matrix_across_all_three_shipped_roles(): void
    {
        $this->actingAsPanelAdministrator();
        $this->get(AttributeDefinitionResource::getUrl('index'))->assertOk();
        $this->get(AttributeDefinitionResource::getUrl('create'))->assertOk();

        $manager = $this->staffWithRole('Manager');
        $this->actingAs($manager, 'staff');
        session()->forget('password_hash_staff');
        $this->get(AttributeDefinitionResource::getUrl('index'))->assertOk();
        $this->get(AttributeDefinitionResource::getUrl('create'))->assertOk();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');
        $this->get(AttributeDefinitionResource::getUrl('index'))->assertOk();
        $this->get(AttributeDefinitionResource::getUrl('create'))->assertForbidden();
    }

    public function test_an_attribute_definitions_row_navigates_to_view_and_edit_button_visibility_matches_permission(): void
    {
        $this->actingAsPanelAdministrator();

        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $definitionModel = AttributeDefinitionModel::find($definition->id());

        $component = Livewire::test(ListAttributeDefinitions::class);

        $component->assertTableActionVisible('edit', $definitionModel);

        $recordUrl = $component->instance()->getTable()->getRecordUrl($definitionModel);
        $this->assertSame(AttributeDefinitionResource::getUrl('view', ['record' => $definitionModel]), $recordUrl);

        $this->get($recordUrl)->assertOk();
        $this->get(AttributeDefinitionResource::getUrl('edit', ['record' => $definitionModel]))->assertOk();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');

        $componentAsProductEntry = Livewire::test(ListAttributeDefinitions::class);
        $componentAsProductEntry->assertTableActionHidden('edit', $definitionModel);

        $this->get(AttributeDefinitionResource::getUrl('view', ['record' => $definitionModel]))->assertOk();
        $this->get(AttributeDefinitionResource::getUrl('edit', ['record' => $definitionModel]))->assertForbidden();
    }
}
