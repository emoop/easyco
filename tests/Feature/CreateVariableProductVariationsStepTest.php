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
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * VARIABLE product creation wizard, Step C ("Variations" — preview
 * generation AND final persistence). Builds on Steps A+B, unmodified
 * except for one deliberate, task-required change (see
 * CreateVariableProductTest's own docblock on that one test) — the
 * wizard is now functionally complete per admin-panel-design.md
 * §13.1. Fixture helpers mirror the established shapes across this
 * whole feature's test files.
 */
class CreateVariableProductVariationsStepTest extends TestCase
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
    private function persistedColorDefinition(): array
    {
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);

        $white = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'White');
        app(AttributeValueRepository::class)->save($white);

        return [$definition, $black, $white];
    }

    /**
     * (a) The real end-to-end generation path: fillForm() the General
     * step, then two REAL ->goToNextWizardStep() calls (General->Axes,
     * Axes->Variations) — the second one is what actually triggers
     * generateVariationPreview() via the Axes step's own
     * ->afterValidation(). Confirms the generated 'variations' state
     * matches the real cartesian product: right count, right
     * combination_json per row, right prefilled sku from the real
     * 'catalog.variation.sku' hook, right label text.
     */
    public function test_navigating_through_the_wizard_generates_the_real_cartesian_product_preview(): void
    {
        $this->actingAsPanelAdministrator();

        [$color, $black, $white] = $this->persistedColorDefinition();

        $component = Livewire::test(CreateVariableProduct::class)
            ->fillForm([
                'name' => 'T-Shirt',
                'slug' => 't-shirt',
                'base_sku' => 'TSHIRT',
                'axes' => [
                    ['attribute_definition_id' => $color->id(), 'value_ids' => [$black->id(), $white->id()]],
                ],
            ])
            ->goToNextWizardStep()
            ->goToNextWizardStep();

        $rows = array_values($component->get('data.variations'));
        $this->assertCount(2, $rows);

        $this->assertSame(json_encode([(int) $color->id() => $black->id()]), $rows[0]['combination_json']);
        $this->assertSame('Color: Black', $rows[0]['label']);
        $this->assertSame('TSHIRT-1', $rows[0]['sku']);
        $this->assertSame('', $rows[0]['barcode']);
        $this->assertFalse($rows[0]['is_active']);

        $this->assertSame(json_encode([(int) $color->id() => $white->id()]), $rows[1]['combination_json']);
        $this->assertSame('Color: White', $rows[1]['label']);
        $this->assertSame('TSHIRT-2', $rows[1]['sku']);

        // base_sku/slug were locked in by the Axes step's own
        // afterValidation() too.
        $this->assertSame('TSHIRT', $component->get('data.base_sku'));
        $this->assertSame('t-shirt', $component->get('data.slug'));
    }

    /**
     * (b) Full submit from the real generation flow: navigate through
     * the wizard, edit a couple of rows' sku/barcode/is_active via a
     * real ->set() on the live component, then ->call('create').
     * Persisted Variations must reflect the EDITED values, verified
     * both through the real domain layer and directly against
     * catalog_variations.
     */
    public function test_submitting_after_editing_generated_rows_persists_the_edited_values(): void
    {
        $this->actingAsPanelAdministrator();

        [$color, $black, $white] = $this->persistedColorDefinition();

        $component = Livewire::test(CreateVariableProduct::class)
            ->fillForm([
                'name' => 'Edited T-Shirt',
                'slug' => 'edited-t-shirt',
                'base_sku' => 'EDITSKU',
                'axes' => [
                    ['attribute_definition_id' => $color->id(), 'value_ids' => [$black->id(), $white->id()]],
                ],
            ])
            ->goToNextWizardStep()
            ->goToNextWizardStep();

        $rowKeys = array_keys($component->get('data.variations'));
        $this->assertCount(2, $rowKeys);

        $component->set("data.variations.{$rowKeys[0]}.sku", 'CUSTOM-BLACK-SKU');
        $component->set("data.variations.{$rowKeys[0]}.barcode", '1111111111111');
        $component->set("data.variations.{$rowKeys[0]}.is_active", true);
        // Row 1 (White) deliberately left at its generated default —
        // proving only the edited row changed, the other kept its
        // generated sku and stayed inactive.

        $component->call('create')->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'edited-t-shirt')->firstOrFail();
        $this->assertSame(2, $productModel->variations()->count());

        $product = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $variationsBySku = [];
        foreach ($product->variations() as $variation) {
            $variationsBySku[$variation->sku()] = $variation;
        }

        // Reloaded via a real save/reload round trip, attributeAssignments()
        // comes back with int value ids (the domain layer's own real
        // normalization — confirmed by running this test), unlike the
        // string ids AttributeValue::id() returns pre-save.
        $this->assertArrayHasKey('CUSTOM-BLACK-SKU', $variationsBySku);
        $blackVariation = $variationsBySku['CUSTOM-BLACK-SKU'];
        $this->assertSame([(int) $color->id() => (int) $black->id()], $blackVariation->attributeAssignments());
        $this->assertSame('1111111111111', $blackVariation->barcode());
        $this->assertTrue($blackVariation->status()->value === 'active');

        $this->assertArrayHasKey('EDITSKU-2', $variationsBySku);
        $whiteVariation = $variationsBySku['EDITSKU-2'];
        $this->assertSame([(int) $color->id() => (int) $white->id()], $whiteVariation->attributeAssignments());
        $this->assertNull($whiteVariation->barcode());
        $this->assertSame('draft', $whiteVariation->status()->value);

        // Directly against the real rows.
        $this->assertDatabaseHas('catalog_variations', [
            'product_id' => $productModel->id,
            'sku' => 'CUSTOM-BLACK-SKU',
            'barcode' => '1111111111111',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('catalog_variations', [
            'product_id' => $productModel->id,
            'sku' => 'EDITSKU-2',
            'barcode' => null,
            'status' => 'draft',
        ]);
    }

    /**
     * (c) A real ->set()-based assertion, not just confirming the
     * closure exists: toggling "activate all" really sets every row's
     * is_active in the live component state, and toggling it back off
     * really clears them again.
     */
    public function test_the_activate_all_toggle_sets_and_clears_every_rows_is_active_in_real_component_state(): void
    {
        $this->actingAsPanelAdministrator();

        [$color, $black, $white] = $this->persistedColorDefinition();

        $component = Livewire::test(CreateVariableProduct::class)
            ->fillForm([
                'name' => 'Toggle T-Shirt',
                'slug' => 'toggle-t-shirt',
                'base_sku' => 'TOGGLESKU',
                'axes' => [
                    ['attribute_definition_id' => $color->id(), 'value_ids' => [$black->id(), $white->id()]],
                ],
            ])
            ->goToNextWizardStep()
            ->goToNextWizardStep();

        $rowKeys = array_keys($component->get('data.variations'));
        $this->assertCount(2, $rowKeys);
        foreach ($rowKeys as $key) {
            $this->assertFalse($component->get("data.variations.{$key}.is_active"));
        }

        $component->set('data.activate_all', true);
        foreach ($rowKeys as $key) {
            $this->assertTrue($component->get("data.variations.{$key}.is_active"), "row {$key} must be active after activate_all=true");
        }

        $component->set('data.activate_all', false);
        foreach ($rowKeys as $key) {
            $this->assertFalse($component->get("data.variations.{$key}.is_active"), "row {$key} must be inactive after activate_all=false");
        }
    }

    /**
     * (e) The real fix this reorder delivers, proven, not assumed:
     * selecting ACTIVE status succeeds once at least one Variation is
     * being persisted alongside it — publish()'s own guard is
     * satisfied because addStandardVariations() now runs before the
     * status match(). (d)'s own "zero variations still rejected" case
     * is covered by CreateVariableProductTest's own updated test — see
     * that file, not duplicated here.
     */
    public function test_selecting_active_status_with_at_least_one_variation_succeeds(): void
    {
        $this->actingAsPanelAdministrator();

        [$color, $black, $white] = $this->persistedColorDefinition();

        $component = Livewire::test(CreateVariableProduct::class)
            ->fillForm([
                'name' => 'Publishable T-Shirt',
                'slug' => 'publishable-t-shirt',
                'base_sku' => 'PUBSKU',
                'status' => ProductStatus::ACTIVE->value,
                'axes' => [
                    ['attribute_definition_id' => $color->id(), 'value_ids' => [$black->id(), $white->id()]],
                ],
            ])
            ->goToNextWizardStep()
            ->goToNextWizardStep();

        $rowKeys = array_keys($component->get('data.variations'));
        $component->set("data.variations.{$rowKeys[0]}.is_active", true);

        $component->call('create')->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'publishable-t-shirt')->firstOrFail();
        $this->assertSame(ProductStatus::ACTIVE->value, $productModel->status);
        $this->assertSame(2, $productModel->variations()->count());
    }

    /**
     * (f) Bypasses wizard navigation entirely, mirroring Step B's own
     * axes tests' established style — proves the final-submit
     * persistence logic (addStandardVariations()) works correctly
     * independent of the generation mechanism, by fillForm()-ing a
     * 'variations' row directly.
     */
    public function test_directly_filling_variations_without_wizard_navigation_persists_correctly(): void
    {
        $this->actingAsPanelAdministrator();

        [$color, $black] = $this->persistedColorDefinition();

        Livewire::test(CreateVariableProduct::class)
            ->fillForm([
                'name' => 'Direct Fill T-Shirt',
                'slug' => 'direct-fill-t-shirt',
                'base_sku' => 'DIRECTSKU',
                'axes' => [
                    ['attribute_definition_id' => $color->id(), 'value_ids' => [$black->id()]],
                ],
                'variations' => [
                    [
                        'combination_json' => json_encode([(int) $color->id() => $black->id()]),
                        'label' => 'Color: Black',
                        'sku' => 'DIRECT-BLACK-SKU',
                        'barcode' => '2222222222222',
                        'is_active' => true,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'direct-fill-t-shirt')->firstOrFail();
        $this->assertSame(1, $productModel->variations()->count());

        $this->assertDatabaseHas('catalog_variations', [
            'product_id' => $productModel->id,
            'sku' => 'DIRECT-BLACK-SKU',
            'barcode' => '2222222222222',
            'status' => 'active',
        ]);

        $product = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $variations = $product->variations();
        $this->assertCount(1, $variations);
        $this->assertSame([(int) $color->id() => (int) $black->id()], $variations[0]->attributeAssignments());
    }

    /**
     * Explicit regression coverage for THIS Repeater specifically
     * (Step B's ->defaultItems(0) fix was for a DIFFERENT Repeater
     * instance — verified fresh here, not assumed): a product with no
     * declared axes at all must still submit cleanly with zero
     * variations, no phantom row tripping ->required() on 'sku'.
     */
    public function test_a_product_with_no_axes_still_submits_with_zero_variations(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateVariableProduct::class)
            ->fillForm([
                'name' => 'No Axes No Variations',
                'slug' => 'no-axes-no-variations',
                'base_sku' => 'NOAXESSKU',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'no-axes-no-variations')->firstOrFail();
        $this->assertSame(0, $productModel->variations()->count());
    }
}
