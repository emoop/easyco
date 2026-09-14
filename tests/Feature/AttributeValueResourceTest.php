<?php

namespace Tests\Feature;

use App\Filament\Resources\AttributeValueResource;
use App\Filament\Resources\AttributeValueResource\Pages\CreateAttributeValue;
use App\Filament\Resources\AttributeValueResource\Pages\EditAttributeValue;
use App\Filament\Resources\AttributeValueResource\Pages\ListAttributeValues;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AttributeValueResourceTest extends TestCase
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

    private function persistedSelectDefinition(): AttributeDefinition
    {
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        return $definition;
    }

    public function test_creating_an_attribute_value_persists_it_through_the_domain_layer(): void
    {
        $this->actingAsPanelAdministrator();
        $definition = $this->persistedSelectDefinition();

        Livewire::test(CreateAttributeValue::class)
            ->fillForm([
                'attribute_definition_id' => $definition->id(),
                'value' => 'Black',
                'sort_order' => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $value = app(AttributeValueRepository::class)->findByAttributeDefinitionId($definition->id())[0] ?? null;

        $this->assertNotNull($value);
        $this->assertSame($definition->id(), $value->attributeDefinitionId());
        $this->assertSame('Black', $value->value());
        $this->assertSame(3, $value->sortOrder());
    }

    public function test_editing_an_attribute_value_updates_it_through_the_domain_layer(): void
    {
        $this->actingAsPanelAdministrator();
        $definition = $this->persistedSelectDefinition();

        $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($value);

        Livewire::test(EditAttributeValue::class, ['record' => $value->id()])
            ->fillForm(['value' => 'Jet Black', 'sort_order' => 5])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(AttributeValueRepository::class)->findById($value->id());

        $this->assertSame('Jet Black', $reloaded->value());
        $this->assertSame(5, $reloaded->sortOrder());
    }

    /**
     * attribute_definition_id is ->disabledOn('edit') — confirms it
     * stays unchanged after an edit attempt that tries to submit a
     * different definition id for it.
     */
    public function test_attribute_definition_id_stays_unchanged_after_an_edit_attempt_that_tries_to_change_it(): void
    {
        $this->actingAsPanelAdministrator();
        $original = $this->persistedSelectDefinition();

        $otherDefinition = new AttributeDefinition(id: null, code: 'material', name: 'Material', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($otherDefinition);

        $value = new AttributeValue(id: null, attributeDefinitionId: $original->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($value);

        $component = Livewire::test(EditAttributeValue::class, ['record' => $value->id()]);
        $component->assertFormFieldDisabled('attribute_definition_id');

        $component->fillForm(['value' => 'Jet Black', 'attribute_definition_id' => $otherDefinition->id()])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(AttributeValueRepository::class)->findById($value->id());

        $this->assertSame($original->id(), $reloaded->attributeDefinitionId());
        $this->assertSame('Jet Black', $reloaded->value());
    }

    /**
     * The attribute_definition_id Select is filtered to SELECT/
     * MULTISELECT-typed definitions only — a UI convenience, not a
     * domain rule. Creates one of each of the 5 real AttributeTypes and
     * confirms exactly 2 are offered.
     */
    public function test_the_attribute_definition_select_only_offers_select_and_multiselect_typed_definitions(): void
    {
        $this->actingAsPanelAdministrator();

        foreach (AttributeType::cases() as $type) {
            $definition = new AttributeDefinition(id: null, code: $type->value, name: ucfirst($type->value), type: $type);
            app(AttributeDefinitionRepository::class)->save($definition);
        }

        $component = Livewire::test(CreateAttributeValue::class);

        $options = $component->instance()->form->getComponent('attribute_definition_id')->getOptions();

        $this->assertCount(2, $options);
    }

    public function test_the_real_permission_matrix_across_all_three_shipped_roles(): void
    {
        $this->actingAsPanelAdministrator();
        $this->get(AttributeValueResource::getUrl('index'))->assertOk();
        $this->get(AttributeValueResource::getUrl('create'))->assertOk();

        $manager = $this->staffWithRole('Manager');
        $this->actingAs($manager, 'staff');
        session()->forget('password_hash_staff');
        $this->get(AttributeValueResource::getUrl('index'))->assertOk();
        $this->get(AttributeValueResource::getUrl('create'))->assertOk();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');
        $this->get(AttributeValueResource::getUrl('index'))->assertOk();
        $this->get(AttributeValueResource::getUrl('create'))->assertForbidden();
    }

    public function test_an_attribute_values_row_navigates_to_view_and_edit_button_visibility_matches_permission(): void
    {
        $this->actingAsPanelAdministrator();
        $definition = $this->persistedSelectDefinition();

        $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($value);
        $valueModel = AttributeValueModel::find($value->id());

        $component = Livewire::test(ListAttributeValues::class);

        $component->assertTableActionVisible('edit', $valueModel);

        $recordUrl = $component->instance()->getTable()->getRecordUrl($valueModel);
        $this->assertSame(AttributeValueResource::getUrl('view', ['record' => $valueModel]), $recordUrl);

        $this->get($recordUrl)->assertOk();
        $this->get(AttributeValueResource::getUrl('edit', ['record' => $valueModel]))->assertOk();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');

        $componentAsProductEntry = Livewire::test(ListAttributeValues::class);
        $componentAsProductEntry->assertTableActionHidden('edit', $valueModel);

        $this->get(AttributeValueResource::getUrl('view', ['record' => $valueModel]))->assertOk();
        $this->get(AttributeValueResource::getUrl('edit', ['record' => $valueModel]))->assertForbidden();
    }
}
