<?php

namespace Tests\Feature;

use App\Filament\Resources\AttributeValueResource;
use App\Filament\Resources\AttributeValueResource\Pages\CreateAttributeValue;
use App\Filament\Resources\AttributeValueResource\Pages\EditAttributeValue;
use App\Filament\Resources\AttributeValueResource\Pages\ListAttributeValues;
use App\Filament\Resources\AttributeValueResource\Pages\RelatedProductsAxis;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    private function attachDescriptively(AttributeValue $value, Product $product): void
    {
        DB::table('catalog_product_attributes')->insert([
            'product_id' => $product->id(),
            'attribute_definition_id' => $value->attributeDefinitionId(),
            'is_variation_axis' => false,
            'text_value' => null,
            'attribute_value_id' => $value->id(),
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_count_columns_show_the_real_independent_descriptive_and_axis_numbers(): void
    {
        $this->actingAsPanelAdministrator();
        $definition = $this->persistedSelectDefinition();

        $descriptiveValue = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($descriptiveValue);

        for ($i = 1; $i <= 3; $i++) {
            $product = Product::createSimple("Descriptive {$i}", "SKU-D{$i}", "descriptive-{$i}");
            app(ProductRepository::class)->save($product);
            $this->attachDescriptively($descriptiveValue, $product);
        }

        $axisValue = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Red');
        app(AttributeValueRepository::class)->save($axisValue);

        for ($i = 1; $i <= 3; $i++) {
            $product = Product::createVariable("Axis {$i}", "SKU-A{$i}", "axis-{$i}");
            $product->declareVariationAxes([new VariationAxis($definition, [$axisValue])]);
            $product->addStandardVariation([$definition->id() => $axisValue->id()], "SKU-A{$i}-RED");
            app(ProductRepository::class)->save($product);
        }

        $component = Livewire::test(ListAttributeValues::class);
        $component->assertTableColumnStateSet('descriptive_count', 3, record: AttributeValueModel::find($descriptiveValue->id()));
        $component->assertTableColumnStateSet('axis_count', 3, record: AttributeValueModel::find($axisValue->id()));
        $component->assertTableColumnStateSet('descriptive_count', 0, record: AttributeValueModel::find($axisValue->id()));
        $component->assertTableColumnStateSet('axis_count', 0, record: AttributeValueModel::find($descriptiveValue->id()));
    }

    public function test_delete_is_blocked_when_only_axis_usage_is_nonzero(): void
    {
        $this->actingAsPanelAdministrator();
        $definition = $this->persistedSelectDefinition();

        $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($value);

        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$value])]);
        $product->addStandardVariation([$definition->id() => $value->id()], 'SKU-1-BLACK');
        app(ProductRepository::class)->save($product);

        Livewire::test(ListAttributeValues::class)
            ->callTableAction('delete', AttributeValueModel::find($value->id()))
            ->assertNotified();

        $this->assertNotNull(app(AttributeValueRepository::class)->findById($value->id()));
    }

    public function test_delete_is_blocked_when_only_descriptive_usage_is_nonzero(): void
    {
        $this->actingAsPanelAdministrator();
        $definition = $this->persistedSelectDefinition();

        $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($value);

        $product = Product::createSimple('Plain Shirt', 'SKU-1', 'plain-shirt');
        app(ProductRepository::class)->save($product);
        $this->attachDescriptively($value, $product);

        Livewire::test(ListAttributeValues::class)
            ->callTableAction('delete', AttributeValueModel::find($value->id()))
            ->assertNotified();

        $this->assertNotNull(app(AttributeValueRepository::class)->findById($value->id()));
    }

    public function test_delete_succeeds_when_the_value_is_genuinely_unused(): void
    {
        $this->actingAsPanelAdministrator();
        $definition = $this->persistedSelectDefinition();

        $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($value);

        Livewire::test(ListAttributeValues::class)
            ->callTableAction('delete', AttributeValueModel::find($value->id()));

        $this->assertNull(app(AttributeValueRepository::class)->findById($value->id()));
    }

    /**
     * The descriptive drill-down is retired — mirrors
     * AttributeDefinitionResourceTest's identical replacement test, plus
     * the one thing specific to AttributeValueResource's own link:
     * attribute_value_id further narrows a definition shared by TWO
     * different values down to exactly the one value's own products.
     */
    public function test_the_descriptive_count_link_redirects_into_products_filtered_to_that_specific_values_usage_only(): void
    {
        $this->actingAsPanelAdministrator();
        $definition = $this->persistedSelectDefinition();

        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);
        $white = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'White');
        app(AttributeValueRepository::class)->save($white);

        $usingBlack = Product::createSimple('Using Black', 'SKU-BLACK', 'using-black');
        app(ProductRepository::class)->save($usingBlack);
        $this->attachDescriptively($black, $usingBlack);

        $usingWhite = Product::createSimple('Using White', 'SKU-WHITE', 'using-white');
        app(ProductRepository::class)->save($usingWhite);
        $this->attachDescriptively($white, $usingWhite);

        $blackModel = AttributeValueModel::find($black->id());
        $component = Livewire::test(ListAttributeValues::class);
        $component->assertTableColumnStateSet('descriptive_count', 1, record: $blackModel);

        $column = $component->instance()->getTable()->getColumn('descriptive_count')->record($blackModel);
        $generatedUrl = $column->getUrl($column->getState());
        $this->assertNotNull($generatedUrl);
        $this->assertStringContainsString(ProductResource::getUrl('index'), $generatedUrl);

        // The SAME definition, but narrowed to Black's own products only
        // — White's product (a different value, same definition) must
        // stay excluded, confirming attribute_value_id genuinely narrows
        // beyond what attribute_definition_id alone would return.
        Livewire::test(ListProducts::class)
            ->set('statusView', 'all')
            ->filterTable('attribute_usage', [
                'attribute_definition_id' => $definition->id(),
                'attribute_value_id' => $black->id(),
            ])
            ->assertCanSeeTableRecords([ProductModel::find($usingBlack->id())])
            ->assertCanNotSeeTableRecords([ProductModel::find($usingWhite->id())]);

        $this->get($generatedUrl)
            ->assertOk()
            ->assertSee('Using Black')
            ->assertDontSee('Using White');
    }

    /** Mirrors AttributeDefinitionResourceTest's identical route-retirement confirmation. */
    public function test_the_old_products_descriptive_route_no_longer_exists(): void
    {
        $this->actingAsPanelAdministrator();
        $definition = $this->persistedSelectDefinition();

        $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($value);

        $this->get('/admin/attribute-values/'.$value->id().'/products-descriptive')
            ->assertNotFound();
    }

    public function test_the_axis_drill_down_shows_the_product_with_no_selection_or_bulk_action_available(): void
    {
        $this->actingAsPanelAdministrator();
        $definition = $this->persistedSelectDefinition();

        $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($value);

        $axisProduct = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $axisProduct->declareVariationAxes([new VariationAxis($definition, [$value])]);
        $axisProduct->addStandardVariation([$definition->id() => $value->id()], 'SKU-1-BLACK');
        app(ProductRepository::class)->save($axisProduct);

        $component = Livewire::test(RelatedProductsAxis::class, ['record' => $value->id()]);

        $productModel = ProductModel::find($axisProduct->id());
        $this->assertFalse($component->instance()->getTable()->isRecordSelectable($productModel));
        $this->assertSame([], $component->instance()->getTable()->getToolbarActions());
    }
}
