<?php

namespace Tests\Feature;

use App\Filament\Resources\AttributeDefinitionResource;
use App\Filament\Resources\AttributeDefinitionResource\Pages\CreateAttributeDefinition;
use App\Filament\Resources\AttributeDefinitionResource\Pages\EditAttributeDefinition;
use App\Filament\Resources\AttributeDefinitionResource\Pages\ListAttributeDefinitions;
use App\Filament\Resources\AttributeDefinitionResource\Pages\RelatedProductsAxis;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
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

    /**
     * The descriptive drill-down is retired — this now confirms the
     * real replacement: descriptive_count's ->url() redirects into
     * ProductResource's own real list, pre-filtered via its
     * 'attribute_usage' Filter, showing exactly the products using this
     * definition descriptively and nothing else (a product using an
     * unrelated definition, and a VARIABLE product using the SAME
     * definition as an AXIS — not descriptively — must both stay
     * excluded, confirming the is_variation_axis=false branch of that
     * Filter's own query).
     */
    public function test_the_descriptive_count_link_redirects_into_products_filtered_to_real_descriptive_usage_only(): void
    {
        $this->actingAsPanelAdministrator();

        $definition = new AttributeDefinition(id: null, code: 'material', name: 'Material', type: AttributeType::TEXT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $usingDescriptively = Product::createSimple('Using Descriptively', 'SKU-USING', 'using-descriptively');
        app(ProductRepository::class)->save($usingDescriptively);
        $this->attachDescriptively($definition, $usingDescriptively);

        $notUsing = Product::createSimple('Not Using', 'SKU-NOT-USING', 'not-using');
        app(ProductRepository::class)->save($notUsing);

        $axisDefinition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($axisDefinition);
        $black = new AttributeValue(id: null, attributeDefinitionId: $axisDefinition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);
        $axisProduct = Product::createVariable('Axis Product', 'SKU-AXIS', 'axis-product');
        $axisProduct->declareVariationAxes([new VariationAxis($axisDefinition, [$black])]);
        $axisProduct->addStandardVariation([$axisDefinition->id() => $black->id()], 'SKU-AXIS-BLACK');
        app(ProductRepository::class)->save($axisProduct);

        // The real column ->url() callback, bound to its real record and
        // evaluated exactly as Filament does when rendering the row —
        // not a hand-built URL, the actual computed one a real click
        // follows.
        $definitionModel = AttributeDefinitionModel::find($definition->id());
        $component = Livewire::test(ListAttributeDefinitions::class);
        $component->assertTableColumnStateSet('descriptive_count', 1, record: $definitionModel);

        $column = $component->instance()->getTable()->getColumn('descriptive_count')->record($definitionModel);
        $generatedUrl = $column->getUrl($column->getState());
        $this->assertNotNull($generatedUrl);
        $this->assertStringContainsString(ProductResource::getUrl('index'), $generatedUrl);
        $this->assertStringContainsString('attribute_usage', $generatedUrl);

        Livewire::test(ListProducts::class)
            ->filterTable('attribute_usage', ['attribute_definition_id' => $definition->id()])
            ->assertCanSeeTableRecords([ProductModel::find($usingDescriptively->id())])
            ->assertCanNotSeeTableRecords([
                ProductModel::find($notUsing->id()),
                ProductModel::find($axisProduct->id()),
            ]);

        // The real click-through: a genuine HTTP GET against that exact
        // generated URL, confirming Livewire's own #[Url] hydration
        // actually filters the rendered list, not just the filter's
        // logic in isolation.
        $this->get($generatedUrl)
            ->assertOk()
            ->assertSee('Using Descriptively')
            ->assertDontSee('Not Using')
            ->assertDontSee('Axis Product');
    }

    /**
     * The retired route genuinely no longer exists — not silently still
     * reachable. AttributeDefinitionResource::getPages() no longer
     * registers 'products-descriptive' at all (confirmed via a real
     * `php artisan route:list`), so hitting its old URL directly 404s.
     */
    public function test_the_old_products_descriptive_route_no_longer_exists(): void
    {
        $this->actingAsPanelAdministrator();

        $definition = new AttributeDefinition(id: null, code: 'material', name: 'Material', type: AttributeType::TEXT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $this->get('/admin/attribute-definitions/'.$definition->id().'/products-descriptive')
            ->assertNotFound();
    }

    /**
     * RelatedProductsAxis stays completely untouched by this task — the
     * only place in the admin panel a VARIABLE product's row is
     * viewable at all (see that page's own docblock). No forced-bulk-
     * unlink paranoia test is needed anymore for it: the descriptive
     * drill-down it used to be forced through no longer exists.
     */

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
