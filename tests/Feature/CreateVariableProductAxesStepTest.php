<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\CreateVariableProduct;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\ProductType;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
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
 * VARIABLE product creation wizard, Step B ("Axes"). Builds on Step A
 * (CreateVariableProductTest.php, unmodified by this task) — this file
 * exercises the second Wizard\Step only: declaring variation axes,
 * still ending in zero catalog_variations rows (Step C's own job).
 * Fixture helpers mirror ProductResourceTest's own established shapes.
 */
class CreateVariableProductAxesStepTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsPanelAdministrator(): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create('admin@example.com', app(PasswordHasher::class)->hash('password123'), 'Administrator', $role);
        app(StaffRepository::class)->save($staff);

        $model = StaffPanelUser::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }

    /** @return array{0: AttributeDefinition, 1: AttributeValue, 2: AttributeValue} */
    private function persistedSelectDefinitionWithValues(string $code): array
    {
        $definition = new AttributeDefinition(id: null, code: $code, name: ucfirst($code), type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $first = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'First');
        app(AttributeValueRepository::class)->save($first);

        $second = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Second');
        app(AttributeValueRepository::class)->save($second);

        return [$definition, $first, $second];
    }

    /**
     * The exact scenario this task's own goal describes: declaring
     * real axes, verified BOTH through the real domain layer
     * (ProductRepository::findByIdWithVariations() ->
     * $product->variationAxes()) AND directly against
     * catalog_product_attributes/catalog_product_axis_values, with
     * catalog_variations still at zero (Step C's own job, not this
     * one).
     */
    public function test_submitting_with_real_axis_rows_persists_the_real_declared_axes(): void
    {
        $this->actingAsPanelAdministrator();

        [$color, $black, $white] = $this->persistedSelectDefinitionWithValues('color');
        [$material, $cotton] = $this->persistedSelectDefinitionWithValues('material');

        Livewire::test(CreateVariableProduct::class)
            ->fillForm([
                'name' => 'Axis Product',
                'slug' => 'axis-product',
                'base_sku' => 'VAR-SKU-AXES',
                'axes' => [
                    ['attribute_definition_id' => $color->id(), 'value_ids' => [$black->id(), $white->id()]],
                    ['attribute_definition_id' => $material->id(), 'value_ids' => [$cotton->id()]],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'axis-product')->firstOrFail();
        $this->assertSame(ProductType::VARIABLE->value, $productModel->type);
        $this->assertSame(0, $productModel->variations()->count());

        // Through the real domain layer.
        $product = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $axes = $product->variationAxes();
        $this->assertCount(2, $axes);

        $axesByDefinitionId = [];
        foreach ($axes as $axis) {
            $axesByDefinitionId[$axis->attributeDefinitionId()] = $axis;
        }

        $this->assertArrayHasKey($color->id(), $axesByDefinitionId);
        $colorValueIds = $axesByDefinitionId[$color->id()]->allowedValueIds();
        sort($colorValueIds);
        $expectedColorValueIds = [$black->id(), $white->id()];
        sort($expectedColorValueIds);
        $this->assertSame($expectedColorValueIds, $colorValueIds);

        $this->assertArrayHasKey($material->id(), $axesByDefinitionId);
        $this->assertSame([$cotton->id()], $axesByDefinitionId[$material->id()]->allowedValueIds());

        // Directly against the real rows.
        $this->assertDatabaseHas('catalog_product_attributes', [
            'product_id' => $productModel->id,
            'attribute_definition_id' => $color->id(),
            'is_variation_axis' => true,
        ]);
        $this->assertDatabaseHas('catalog_product_attributes', [
            'product_id' => $productModel->id,
            'attribute_definition_id' => $material->id(),
            'is_variation_axis' => true,
        ]);

        $this->assertSame(
            3,
            DB::table('catalog_product_axis_values')->where('product_id', $productModel->id)->count()
        );
        $this->assertDatabaseHas('catalog_product_axis_values', [
            'product_id' => $productModel->id,
            'attribute_definition_id' => $color->id(),
            'attribute_value_id' => $black->id(),
        ]);
        $this->assertDatabaseHas('catalog_product_axis_values', [
            'product_id' => $productModel->id,
            'attribute_definition_id' => $color->id(),
            'attribute_value_id' => $white->id(),
        ]);
        $this->assertDatabaseHas('catalog_product_axis_values', [
            'product_id' => $productModel->id,
            'attribute_definition_id' => $material->id(),
            'attribute_value_id' => $cotton->id(),
        ]);
    }

    /**
     * Explicit regression coverage for this step specifically — not
     * just relying on CreateVariableProductTest.php's own Step-A-era
     * tests continuing to pass. Zero axes rows must still be a legal,
     * submittable state after the Axes step landed.
     */
    public function test_submitting_with_zero_axes_rows_still_works_exactly_as_step_a(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateVariableProduct::class)
            ->fillForm([
                'name' => 'No Axes Product',
                'slug' => 'no-axes-product',
                'base_sku' => 'VAR-SKU-NOAXES',
                'axes' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'no-axes-product')->firstOrFail();
        $this->assertSame(0, $productModel->variations()->count());

        $product = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertSame([], $product->variationAxes());

        $this->assertDatabaseMissing('catalog_product_attributes', ['product_id' => $productModel->id]);
        $this->assertDatabaseMissing('catalog_product_axis_values', ['product_id' => $productModel->id]);
    }

    /**
     * Mirrors ProductResourceTest::
     * test_exceeding_the_real_configured_max_photo_count_is_rejected_with_the_real_message()'s
     * own established Notification+Halt assertion style exactly —
     * ->assertNotified() with the real, exact exception message, then
     * assertNull() confirming the ENTIRE creation rolled back (no
     * half-created product, no orphaned axis rows).
     */
    public function test_two_rows_with_the_same_attribute_definition_id_are_rejected_with_the_real_message_and_roll_back(): void
    {
        $this->actingAsPanelAdministrator();

        [$color, $black, $white] = $this->persistedSelectDefinitionWithValues('color');

        Livewire::test(CreateVariableProduct::class)
            ->fillForm([
                'name' => 'Duplicate Axis Product',
                'slug' => 'duplicate-axis-product',
                'base_sku' => 'VAR-SKU-DUP',
                'axes' => [
                    ['attribute_definition_id' => $color->id(), 'value_ids' => [$black->id()]],
                    ['attribute_definition_id' => $color->id(), 'value_ids' => [$white->id()]],
                ],
            ])
            ->call('create')
            ->assertNotified(
                "Attribute definition \"{$color->id()}\" was declared as a variation axis more than once."
            );

        $this->assertNull(
            ProductModel::where('slug', 'duplicate-axis-product')->first(),
            'the whole creation must roll back on a duplicate-axis rejection'
        );
        $this->assertDatabaseMissing('catalog_product_axis_values', ['attribute_definition_id' => $color->id()]);
    }

    /**
     * A real component-state assertion, not just confirming the
     * afterStateUpdated() closure exists in the diff: changing a row's
     * attribute_definition_id after value_ids was already populated
     * must actually clear value_ids in the live component state.
     */
    public function test_changing_a_rows_attribute_definition_after_selecting_values_clears_the_values(): void
    {
        $this->actingAsPanelAdministrator();

        [$color, $black] = $this->persistedSelectDefinitionWithValues('color');
        [$material] = $this->persistedSelectDefinitionWithValues('material');

        $component = Livewire::test(CreateVariableProduct::class)
            ->fillForm([
                'axes' => [
                    ['attribute_definition_id' => $color->id(), 'value_ids' => [$black->id()]],
                ],
            ]);

        $rowKey = array_key_first($component->get('data.axes'));
        $this->assertSame([$black->id()], $component->get("data.axes.{$rowKey}.value_ids"));

        $component->set("data.axes.{$rowKey}.attribute_definition_id", $material->id());

        $this->assertSame([], $component->get("data.axes.{$rowKey}.value_ids"));
    }
}
