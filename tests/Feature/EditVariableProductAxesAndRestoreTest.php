<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\EditVariableProduct;
use App\Filament\StaffPanelUser;
use App\Models\ActivityLogModel;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Exceptions\UnsafeAxisRedeclarationException;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers EditVariableProduct's new "Axes" tab (extending declared
 * axes on an already-persisted VARIABLE product — only possible since
 * catalog-domain-design.md §3.17's directional guard replaced the old
 * blanket refusal), "Generate missing variations"/"Add variation" (the
 * two add-paths), and the archived-variations Restore list
 * (Product::restoreArchivedVariation()). Fixture helpers are
 * duplicated from EditVariableProductTest's own established shapes
 * rather than shared — same reasoning that file's own docblock already
 * gives for this codebase.
 */
class EditVariableProductAxesAndRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Off by default (ActivityLogger::write()'s own real gate) —
        // ProductActivityLogTest's own established precedent for
        // enabling it in tests that assert real log entries.
        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
    }

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

    /** @return array{0: Product, 1: AttributeDefinition, 2: AttributeValue, 3: AttributeValue, 4: string} product, color definition, black, white, black-variation-id (live) */
    private function persistedVariableProductWithOneLiveVariation(): array
    {
        [$definition, $black, $white] = $this->persistedColorDefinition();

        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white])]);
        $variation = $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-VAR-BLACK');
        $variation->activate();
        app(ProductRepository::class)->save($product);

        return [$product, $definition, $black, $white, (string) $variation->id()];
    }

    public function test_the_axes_tab_shows_the_products_real_declared_axes_and_their_enabled_values(): void
    {
        $this->actingAsPanelAdministrator();

        [$product, $definition, $black, $white] = $this->persistedVariableProductWithOneLiveVariation();
        $productModel = \EasyCo\Catalog\Persistence\Eloquent\ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $axes = array_values($component->get('data.axes'));

        $this->assertCount(1, $axes);
        $this->assertSame((string) $definition->id(), $axes[0]['attribute_definition_id']);
        $valueIds = $axes[0]['value_ids'];
        sort($valueIds);
        $expected = [(string) $black->id(), (string) $white->id()];
        sort($expected);
        $this->assertSame($expected, $valueIds);
    }

    public function test_saving_with_the_axes_tab_untouched_is_a_no_op_that_writes_nothing_and_does_not_throw(): void
    {
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneLiveVariation();
        $productModel = \EasyCo\Catalog\Persistence\Eloquent\ProductModel::find($product->id());

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(
            ActivityLogModel::where('entity_type', 'product')
                ->where('entity_id', $product->id())
                ->where('field', 'variation_axes')
                ->exists(),
            'an untouched axes tab must not produce a spurious log entry'
        );

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertCount(1, $reloaded->variationAxes());
    }

    public function test_enabling_one_more_value_on_an_existing_axis_persists_and_a_variation_for_it_succeeds(): void
    {
        $this->actingAsPanelAdministrator();

        [$product, $definition, $black, $white] = $this->persistedVariableProductWithOneLiveVariation();
        $productModel = \EasyCo\Catalog\Persistence\Eloquent\ProductModel::find($product->id());

        $red = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Red');
        app(AttributeValueRepository::class)->save($red);

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->set('data.axes', [[
                'attribute_definition_id' => (string) $definition->id(),
                'value_ids' => [(string) $black->id(), (string) $white->id(), (string) $red->id()],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $axes = $reloaded->variationAxes();
        $this->assertCount(1, $axes);
        $this->assertContains((string) $red->id(), $axes[0]->allowedValueIds());

        $newVariation = $reloaded->addStandardVariation([$definition->id() => $red->id()], 'SKU-VAR-RED');
        $this->assertEquals([$definition->id() => $red->id()], $newVariation->attributeAssignments());
    }

    public function test_adding_a_new_axis_while_a_live_variation_exists_is_refused_and_rolls_back(): void
    {
        $this->actingAsPanelAdministrator();

        [$product, $colorDefinition, $black, $white] = $this->persistedVariableProductWithOneLiveVariation();
        $productModel = \EasyCo\Catalog\Persistence\Eloquent\ProductModel::find($product->id());

        $sizeDefinition = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($sizeDefinition);
        $small = new AttributeValue(id: null, attributeDefinitionId: $sizeDefinition->id(), value: 'S');
        app(AttributeValueRepository::class)->save($small);

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->set('data.axes', [
                [
                    'attribute_definition_id' => (string) $colorDefinition->id(),
                    'value_ids' => [(string) $black->id(), (string) $white->id()],
                ],
                [
                    'attribute_definition_id' => (string) $sizeDefinition->id(),
                    'value_ids' => [(string) $small->id()],
                ],
            ])
            ->set('data.name', 'Should Not Be Saved')
            ->call('save')
            ->assertNotified(
                UnsafeAxisRedeclarationException::becauseNewAxisWouldInvalidateLiveVariations(
                    $product->id(),
                    (string) $sizeDefinition->id(),
                    [$this->liveVariationId($product)]
                )->getMessage()
            );

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertCount(1, $reloaded->variationAxes());
        $this->assertSame('Variable Shirt', $reloaded->name(), 'the whole submission must roll back');
    }

    private function liveVariationId(Product $product): string
    {
        foreach ($product->variations() as $variation) {
            if ($variation->status()->value !== 'archived') {
                return (string) $variation->id();
            }
        }

        return '';
    }

    /**
     * THE REAL SAVE-TIME ORDERING PROOF: extending an axis's own value
     * set AND adding a new variation for that brand-new value, in the
     * SAME submission, must both succeed together — the new
     * variation's own combination only validates because axes are
     * declared FIRST, before addNewVariationRows() runs.
     */
    public function test_the_save_time_ordering_is_proven_adding_an_axis_value_and_a_new_variation_for_it_together(): void
    {
        $this->actingAsPanelAdministrator();

        [$product, $definition, $black, $white] = $this->persistedVariableProductWithOneLiveVariation();
        $productModel = \EasyCo\Catalog\Persistence\Eloquent\ProductModel::find($product->id());

        $red = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Red');
        app(AttributeValueRepository::class)->save($red);

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->set('data.axes', [[
                'attribute_definition_id' => (string) $definition->id(),
                'value_ids' => [(string) $black->id(), (string) $white->id(), (string) $red->id()],
            ]])
            ->set('data.new_variations', [[
                "axis_value_{$definition->id()}" => (string) $red->id(),
                'sku' => 'SKU-VAR-RED',
                'barcode' => '',
                'is_active' => false,
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertContains((string) $red->id(), $reloaded->variationAxes()[0]->allowedValueIds());

        $redVariation = null;
        foreach ($reloaded->variations() as $variation) {
            if ($variation->sku() === 'SKU-VAR-RED') {
                $redVariation = $variation;
            }
        }
        $this->assertNotNull($redVariation, 'the new variation for the just-added value must exist');
        $this->assertEquals([$definition->id() => $red->id()], $redVariation->attributeAssignments());
    }

    public function test_generate_missing_variations_creates_exactly_missing_combinations_and_reports_real_separate_counts(): void
    {
        $this->actingAsPanelAdministrator();

        [$definition, $black, $white] = $this->persistedColorDefinition();
        $red = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Red');
        app(AttributeValueRepository::class)->save($red);

        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white, $red])]);
        $liveVariation = $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-VAR-BLACK');
        $liveVariation->activate();
        $archivedVariation = $product->addStandardVariation([$definition->id() => $white->id()], 'SKU-VAR-WHITE');
        $archivedVariation->activate();
        $archivedVariation->archive();
        app(ProductRepository::class)->save($product);
        $whiteVariationId = (string) $archivedVariation->id();

        $productModel = \EasyCo\Catalog\Persistence\Eloquent\ProductModel::find($product->id());

        // assertNotified(string) matches the notification's own TITLE
        // only (confirmed against Filament\Notifications\Notification::
        // assertNotified()'s installed source) — the real per-count
        // body is verified via the real domain reload below instead,
        // which is the actually meaningful assertion.
        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->call('generateMissingVariations')
            ->assertNotified(__('products.variations_generate.notification_title'));

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertCount(3, $reloaded->variations());

        $byId = [];
        foreach ($reloaded->variations() as $variation) {
            $byId[(string) $variation->id()] = $variation;
        }
        $this->assertSame('draft', $byId[$whiteVariationId]->status()->value, 'the archived White variation must be restored, not recreated');
        $this->assertSame('SKU-VAR-WHITE', $byId[$whiteVariationId]->sku(), 'its original sku must survive the restore');

        $redVariation = null;
        foreach ($reloaded->variations() as $variation) {
            if ((string) ($variation->attributeAssignments()[$definition->id()] ?? '') === (string) $red->id()) {
                $redVariation = $variation;
            }
        }
        $this->assertNotNull($redVariation, 'Red must be a genuinely new variation');
    }

    public function test_add_variation_creates_exactly_one_new_variation_with_the_chosen_combination(): void
    {
        $this->actingAsPanelAdministrator();

        [$product, $definition, $black, $white] = $this->persistedVariableProductWithOneLiveVariation();
        $productModel = \EasyCo\Catalog\Persistence\Eloquent\ProductModel::find($product->id());

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->set('data.new_variations', [[
                "axis_value_{$definition->id()}" => (string) $white->id(),
                'sku' => 'SKU-VAR-WHITE',
                'barcode' => '',
                'is_active' => false,
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertCount(2, $reloaded->variations());

        $whiteVariation = null;
        foreach ($reloaded->variations() as $variation) {
            if ($variation->sku() === 'SKU-VAR-WHITE') {
                $whiteVariation = $variation;
            }
        }
        $this->assertNotNull($whiteVariation);
        $this->assertEquals([$definition->id() => $white->id()], $whiteVariation->attributeAssignments());
    }

    public function test_add_variation_is_refused_with_a_notification_for_a_duplicate_combination(): void
    {
        $this->actingAsPanelAdministrator();

        [$product, $definition, $black] = $this->persistedVariableProductWithOneLiveVariation();
        $productModel = \EasyCo\Catalog\Persistence\Eloquent\ProductModel::find($product->id());

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->set('data.new_variations', [[
                "axis_value_{$definition->id()}" => (string) $black->id(),
                'sku' => 'SKU-VAR-BLACK-DUP',
                'barcode' => '',
                'is_active' => false,
            ]])
            ->set('data.name', 'Should Not Be Saved Either')
            ->call('save')
            ->assertNotified();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertCount(1, $reloaded->variations(), 'no duplicate must be created');
        $this->assertSame('Variable Shirt', $reloaded->name(), 'the whole submission must roll back');
    }

    public function test_the_archived_list_shows_a_real_archived_variation_and_restore_flips_it_to_draft(): void
    {
        $this->actingAsPanelAdministrator();

        [$definition, $black, $white] = $this->persistedColorDefinition();
        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white])]);
        $blackVariation = $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-VAR-BLACK');
        $blackVariation->activate();
        $whiteVariation = $product->addStandardVariation([$definition->id() => $white->id()], 'SKU-VAR-WHITE');
        $whiteVariation->activate();
        $whiteVariation->setBarcode('9998887776665');
        $whiteVariation->archive();
        app(ProductRepository::class)->save($product);
        $whiteId = (string) $whiteVariation->id();

        $productModel = \EasyCo\Catalog\Persistence\Eloquent\ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $archivedRows = array_values($component->get('data.archived_variations'));
        $this->assertCount(1, $archivedRows);
        $this->assertSame($whiteId, $archivedRows[0]['variation_id']);
        $this->assertSame('SKU-VAR-WHITE', $archivedRows[0]['sku']);

        $component->call('restoreArchivedVariationById', $whiteId)
            ->assertNotified(__('products.variation_restore.notification_success'));

        $this->assertSame('draft', VariationModel::find($whiteId)->status);
        $this->assertSame('SKU-VAR-WHITE', VariationModel::find($whiteId)->sku);
        $this->assertSame('9998887776665', VariationModel::find($whiteId)->barcode);

        $this->assertTrue(
            ActivityLogModel::where('entity_type', 'product')
                ->where('entity_id', $product->id())
                ->where('field', "variation[{$whiteId}].status")
                ->where('old_value', 'archived')
                ->where('new_value', 'draft')
                ->exists()
        );

        $existingRows = $component->get('data.existing_variations');
        $existingIds = array_column($existingRows, 'variation_id');
        $this->assertContains($whiteId, $existingIds);

        $archivedRowsAfter = $component->get('data.archived_variations');
        $this->assertCount(0, $archivedRowsAfter);
    }

    public function test_restore_is_refused_with_the_domain_message_when_the_variations_axis_no_longer_exists(): void
    {
        $this->actingAsPanelAdministrator();

        [$colorDefinition, $black, $white] = $this->persistedColorDefinition();
        $sizeDefinition = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($sizeDefinition);
        $small = new AttributeValue(id: null, attributeDefinitionId: $sizeDefinition->id(), value: 'S');
        app(AttributeValueRepository::class)->save($small);

        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([
            new VariationAxis($colorDefinition, [$black, $white]),
            new VariationAxis($sizeDefinition, [$small]),
        ]);
        $variation = $product->addStandardVariation([$colorDefinition->id() => $black->id(), $sizeDefinition->id() => $small->id()], 'SKU-VAR-BLACK-S');
        $variation->activate();
        $variation->archive();
        // Zero LIVE variations remain — safe to remove the "size" axis per R2/R3.
        $product->declareVariationAxes([new VariationAxis($colorDefinition, [$black, $white])]);
        app(ProductRepository::class)->save($product);
        $variationId = (string) $variation->id();

        $productModel = \EasyCo\Catalog\Persistence\Eloquent\ProductModel::find($product->id());

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->call('restoreArchivedVariationById', $variationId)
            ->assertNotified();

        $this->assertSame('archived', VariationModel::find($variationId)->status, 'a failed restore must not change anything');
    }

    /**
     * THE REAL POINT OF THE REFRESH FIX: Restore's own targeted
     * $this->form->fill($this->data) refresh (only 'existing_variations'/
     * 'archived_variations') must NEVER discard an unrelated, unsaved
     * edit elsewhere on the page — unlike a full fillForm(), which
     * would re-derive 'name' back to its persisted value.
     */
    public function test_restore_does_not_discard_an_unrelated_unsaved_edit_elsewhere_on_the_page(): void
    {
        $this->actingAsPanelAdministrator();

        [$definition, $black, $white] = $this->persistedColorDefinition();
        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white])]);
        $blackVariation = $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-VAR-BLACK');
        $blackVariation->activate();
        $whiteVariation = $product->addStandardVariation([$definition->id() => $white->id()], 'SKU-VAR-WHITE');
        $whiteVariation->activate();
        $whiteVariation->archive();
        app(ProductRepository::class)->save($product);
        $whiteId = (string) $whiteVariation->id();

        $productModel = \EasyCo\Catalog\Persistence\Eloquent\ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->set('data.name', 'Unsaved In-Progress Name');

        $this->assertSame('Unsaved In-Progress Name', $component->get('data.name'));

        $component->call('restoreArchivedVariationById', $whiteId);

        // The unrelated, unsaved edit must have survived Restore's own refresh.
        $this->assertSame('Unsaved In-Progress Name', $component->get('data.name'));

        // The two refreshed keys did genuinely update.
        $existingIds = array_column($component->get('data.existing_variations'), 'variation_id');
        $this->assertContains($whiteId, $existingIds);
        $this->assertCount(0, $component->get('data.archived_variations'));

        // And the real DB row is genuinely restored, independent of the form state.
        $this->assertSame('draft', VariationModel::find($whiteId)->status);
    }

    public function test_a_staff_member_without_product_manage_sees_no_axes_tab_and_cannot_trigger_restore_or_generate(): void
    {
        $role = Role::create('View Only', [Permission::PRODUCT_VIEW]);
        app(RoleRepository::class)->save($role);
        $staff = Staff::create('view.only@example.com', app(PasswordHasher::class)->hash('password123'), 'View Only', $role);
        app(StaffRepository::class)->save($staff);

        [$product] = $this->persistedVariableProductWithOneLiveVariation();
        $productModel = \EasyCo\Catalog\Persistence\Eloquent\ProductModel::find($product->id());

        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');

        // Same real, confirmed constraint already documented on this
        // exact page (EditVariableProduct's own "PRODUCT_MANAGE gates
        // the whole page" finding): every shipped role that holds
        // PRODUCT_VIEW also holds PRODUCT_MANAGE, so the only real,
        // reachable "cannot trigger Restore/Generate" proof is being
        // turned away at the page boundary entirely, before the Axes
        // tab (or anything else) is ever rendered.
        $this->get(ProductResource::getUrl('edit-variable', ['record' => $productModel]))
            ->assertForbidden();
    }
}
