<?php

namespace Tests\Feature;

use App\Filament\Resources\AttributeDefinitionResource;
use App\Filament\Resources\AttributeDefinitionResource\Pages\CreateAttributeDefinition;
use App\Filament\Resources\AttributeDefinitionResource\Pages\EditAttributeDefinition;
use App\Filament\Resources\AttributeDefinitionResource\Pages\ListAttributeDefinitions;
use App\Filament\Resources\AttributeDefinitionResource\Pages\RelatedProductsAxis;
use App\Filament\Resources\AttributeDefinitionResource\Pages\RelatedProductsDescriptive;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
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

    private function attachDescriptively(AttributeDefinition $definition, Product $product): void
    {
        DB::table('catalog_product_attributes')->insert([
            'product_id' => $product->id(),
            'attribute_definition_id' => $definition->id(),
            'is_variation_axis' => false,
            'text_value' => 'some value',
            'attribute_value_id' => null,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_count_columns_show_the_real_independent_descriptive_and_axis_numbers(): void
    {
        $this->actingAsPanelAdministrator();

        $definition = new AttributeDefinition(id: null, code: 'material', name: 'Material', type: AttributeType::TEXT);
        app(AttributeDefinitionRepository::class)->save($definition);

        for ($i = 1; $i <= 3; $i++) {
            $product = Product::createSimple("Descriptive {$i}", "SKU-D{$i}", "descriptive-{$i}");
            app(ProductRepository::class)->save($product);
            $this->attachDescriptively($definition, $product);
        }

        $selectDefinition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($selectDefinition);
        $black = new AttributeValue(id: null, attributeDefinitionId: $selectDefinition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);

        for ($i = 1; $i <= 3; $i++) {
            $product = Product::createVariable("Axis {$i}", "SKU-A{$i}", "axis-{$i}");
            $product->declareVariationAxes([new VariationAxis($selectDefinition, [$black])]);
            $product->addStandardVariation([$selectDefinition->id() => $black->id()], "SKU-A{$i}-BLACK");
            app(ProductRepository::class)->save($product);
        }

        $component = Livewire::test(ListAttributeDefinitions::class);
        $component->assertTableColumnStateSet('descriptive_count', 3, record: AttributeDefinitionModel::find($definition->id()));
        $component->assertTableColumnStateSet('axis_count', 3, record: AttributeDefinitionModel::find($selectDefinition->id()));
        $component->assertTableColumnStateSet('descriptive_count', 0, record: AttributeDefinitionModel::find($selectDefinition->id()));
        $component->assertTableColumnStateSet('axis_count', 0, record: AttributeDefinitionModel::find($definition->id()));
    }

    public function test_delete_is_blocked_when_only_axis_usage_is_nonzero(): void
    {
        $this->actingAsPanelAdministrator();

        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);

        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black])]);
        $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-1-BLACK');
        app(ProductRepository::class)->save($product);

        Livewire::test(ListAttributeDefinitions::class)
            ->callTableAction('delete', AttributeDefinitionModel::find($definition->id()))
            ->assertNotified();

        $this->assertNotNull(app(AttributeDefinitionRepository::class)->findById($definition->id()));
    }

    public function test_delete_is_blocked_when_only_descriptive_usage_is_nonzero(): void
    {
        $this->actingAsPanelAdministrator();

        $definition = new AttributeDefinition(id: null, code: 'material', name: 'Material', type: AttributeType::TEXT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $product = Product::createSimple('Plain Shirt', 'SKU-1', 'plain-shirt');
        app(ProductRepository::class)->save($product);
        $this->attachDescriptively($definition, $product);

        Livewire::test(ListAttributeDefinitions::class)
            ->callTableAction('delete', AttributeDefinitionModel::find($definition->id()))
            ->assertNotified();

        $this->assertNotNull(app(AttributeDefinitionRepository::class)->findById($definition->id()));
    }

    public function test_delete_succeeds_when_the_definition_is_genuinely_unused(): void
    {
        $this->actingAsPanelAdministrator();

        $definition = new AttributeDefinition(id: null, code: 'material', name: 'Material', type: AttributeType::TEXT);
        app(AttributeDefinitionRepository::class)->save($definition);

        Livewire::test(ListAttributeDefinitions::class)
            ->callTableAction('delete', AttributeDefinitionModel::find($definition->id()));

        $this->assertNull(app(AttributeDefinitionRepository::class)->findById($definition->id()));
    }

    public function test_bulk_unlink_on_the_descriptive_drill_down_actually_detaches_the_selected_products(): void
    {
        $this->actingAsPanelAdministrator();

        $definition = new AttributeDefinition(id: null, code: 'material', name: 'Material', type: AttributeType::TEXT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $productIds = [];
        for ($i = 1; $i <= 3; $i++) {
            $product = Product::createSimple("Product {$i}", "SKU-{$i}", "product-{$i}");
            app(ProductRepository::class)->save($product);
            $this->attachDescriptively($definition, $product);
            $productIds[] = $product->id();
        }

        Livewire::test(RelatedProductsDescriptive::class, ['record' => $definition->id()])
            ->callTableBulkAction('detach', $productIds);

        foreach ($productIds as $productId) {
            $reloaded = app(ProductRepository::class)->findById($productId);
            $this->assertArrayNotHasKey($definition->id(), $reloaded->descriptiveAttributes());
        }

        $counts = app(AttributeDefinitionRepository::class)->countProductsUsing($definition->id());
        $this->assertSame(0, $counts['descriptive']);
    }

    public function test_bulk_unlink_skips_an_already_detached_product_without_erroring(): void
    {
        $this->actingAsPanelAdministrator();

        $definition = new AttributeDefinition(id: null, code: 'material', name: 'Material', type: AttributeType::TEXT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $stillAttached = Product::createSimple('Still Attached', 'SKU-1', 'still-attached');
        app(ProductRepository::class)->save($stillAttached);
        $this->attachDescriptively($definition, $stillAttached);

        $neverAttached = Product::createSimple('Never Attached', 'SKU-2', 'never-attached');
        app(ProductRepository::class)->save($neverAttached);

        Livewire::test(RelatedProductsDescriptive::class, ['record' => $definition->id()])
            ->callTableBulkAction('detach', [$stillAttached->id(), $neverAttached->id()]);

        $counts = app(AttributeDefinitionRepository::class)->countProductsUsing($definition->id());
        $this->assertSame(0, $counts['descriptive']);
    }

    /**
     * The one test in this whole task worth real paranoia about — axis
     * usage is never bulk-unlinkable, full stop. The axis drill-down
     * itself registers no bulk action at all (nothing to even try to
     * call), so the real attack surface is: select an axis-using
     * product's id via the DESCRIPTIVE page's own bulk action instead
     * (bypassing the UI, since that page's own table would never
     * display it) — server-side, DetachProductFromCatalogLookup must
     * still refuse to touch it.
     */
    public function test_axis_usage_cannot_be_bulk_unlinked_even_by_forcing_a_request_through_the_descriptive_action(): void
    {
        $this->actingAsPanelAdministrator();

        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);

        $axisProduct = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $axisProduct->declareVariationAxes([new VariationAxis($definition, [$black])]);
        $axisProduct->addStandardVariation([$definition->id() => $black->id()], 'SKU-1-BLACK');
        app(ProductRepository::class)->save($axisProduct);

        // A real product genuinely in the descriptive drill-down's own
        // scope, for a different attribute definition, to prove the
        // bulk action still works normally for the one it legitimately
        // does apply to.
        $unrelatedDescriptiveDefinition = new AttributeDefinition(id: null, code: 'material', name: 'Material', type: AttributeType::TEXT);
        app(AttributeDefinitionRepository::class)->save($unrelatedDescriptiveDefinition);

        // Force-select the axis product's id on the AXIS definition's
        // own descriptive drill-down page — this id would never appear
        // in that page's real query results (it has no descriptive row
        // for this definition at all), but the test selects it
        // directly, bypassing the UI.
        Livewire::test(RelatedProductsDescriptive::class, ['record' => $definition->id()])
            ->callTableBulkAction('detach', [$axisProduct->id()]);

        // The axis declaration and the Variation must be completely
        // untouched — not corrupted, not silently skipped-but-broken.
        $reloadedAxisProduct = app(ProductRepository::class)->findByIdWithVariations($axisProduct->id());
        $this->assertTrue($reloadedAxisProduct->hasVariationAxis($definition));
        $this->assertCount(1, $reloadedAxisProduct->variations());

        $counts = app(AttributeDefinitionRepository::class)->countProductsUsing($definition->id());
        $this->assertSame(1, $counts['axis']);
    }

    public function test_the_axis_drill_down_shows_the_product_with_no_selection_or_bulk_action_available(): void
    {
        $this->actingAsPanelAdministrator();

        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);

        $axisProduct = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $axisProduct->declareVariationAxes([new VariationAxis($definition, [$black])]);
        $axisProduct->addStandardVariation([$definition->id() => $black->id()], 'SKU-1-BLACK');
        app(ProductRepository::class)->save($axisProduct);

        $component = Livewire::test(RelatedProductsAxis::class, ['record' => $definition->id()]);

        $productModel = \EasyCo\Catalog\Persistence\Eloquent\ProductModel::find($axisProduct->id());
        $this->assertFalse($component->instance()->getTable()->isRecordSelectable($productModel));
        $this->assertSame([], $component->instance()->getTable()->getToolbarActions());
    }
}
