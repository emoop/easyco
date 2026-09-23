<?php

namespace Tests\Feature;

use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use EasyCo\Staff\Role;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP coverage for POST /api/variations/{variationId}/restore
 * (VariationController::restore()) — Product::restoreArchivedVariation()'s
 * new HTTP surface. Fixture helpers duplicated from
 * EditVariableProductAxesAndRestoreTest's own established shapes rather
 * than shared, matching how these feature tests are already written.
 */
class VariationControllerRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdministrator();
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

    public function test_happy_path_restores_an_archived_variation_and_returns_200(): void
    {
        [$definition, $black, $white] = $this->persistedColorDefinition();

        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white])]);
        $liveVariation = $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-VAR-BLACK');
        $liveVariation->activate();
        $variation = $product->addStandardVariation([$definition->id() => $white->id()], 'SKU-VAR-WHITE');
        $variation->activate();
        $variation->setBarcode('9998887776665');
        $variation->archive();
        app(ProductRepository::class)->save($product);

        $variationId = (string) $variation->id();
        $productId = (string) $product->id();
        $signatureBefore = VariationModel::find($variationId)->attribute_signature;

        $response = $this->postJson("/api/variations/{$variationId}/restore");

        $response->assertStatus(200);
        $response->assertJsonPath('variation_id', $variationId);
        $response->assertJsonPath('product_id', $productId);
        $response->assertJsonPath('status', 'draft');
        $response->assertJsonPath('sku', 'SKU-VAR-WHITE');
        $response->assertJsonPath('barcode', '9998887776665');

        $row = VariationModel::find($variationId);
        $this->assertSame('draft', $row->status);
        $this->assertSame('SKU-VAR-WHITE', $row->sku);
        $this->assertSame('9998887776665', $row->barcode);
        $this->assertSame($signatureBefore, $row->attribute_signature);
    }

    public function test_a_draft_variation_returns_422_with_the_domain_message(): void
    {
        [$definition, $black, $white] = $this->persistedColorDefinition();

        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white])]);
        $variation = $product->addStandardVariation([$definition->id() => $white->id()], 'SKU-VAR-WHITE');
        app(ProductRepository::class)->save($product);

        $variationId = (string) $variation->id();

        $response = $this->postJson("/api/variations/{$variationId}/restore");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'restoreArchivedVariation() only applies to an ARCHIVED variation.');
        $this->assertSame('draft', VariationModel::find($variationId)->status);
    }

    public function test_an_active_variation_returns_422(): void
    {
        [$definition, $black, $white] = $this->persistedColorDefinition();

        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white])]);
        $variation = $product->addStandardVariation([$definition->id() => $white->id()], 'SKU-VAR-WHITE');
        $variation->activate();
        app(ProductRepository::class)->save($product);

        $variationId = (string) $variation->id();

        $response = $this->postJson("/api/variations/{$variationId}/restore");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'restoreArchivedVariation() only applies to an ARCHIVED variation.');
        $this->assertSame('active', VariationModel::find($variationId)->status);
    }

    public function test_a_simple_products_universal_variation_returns_422(): void
    {
        $product = Product::createSimple('Simple Thing', 'SKU-SIMPLE', 'simple-thing');
        app(ProductRepository::class)->save($product);

        $variationId = $product->variations()[0]->id();

        $response = $this->postJson("/api/variations/{$variationId}/restore");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Only a STANDARD variation can be restored from the archive.');
    }

    public function test_a_nonexistent_variation_id_returns_422(): void
    {
        $response = $this->postJson('/api/variations/999999/restore');

        $response->assertStatus(422);
    }

    /**
     * FIX 4: VariationModel uses SoftDeletes, and
     * EloquentVariationRepository::findById() respects that global scope
     * — the domain layer's own view is that a soft-deleted variation does
     * not exist. A plain 'exists:catalog_variations,id' rule is a raw,
     * non-scoped DB query and does NOT share that view: it used to let a
     * soft-deleted id pass validation, causing a 500 later on
     * $variation->productId() — a different, worse response than the
     * clean 422 a genuinely nonexistent id already gets, even though a
     * client cannot tell the two cases apart. Fixed by making the
     * validation rule itself soft-delete aware
     * (Rule::exists(...)->whereNull('deleted_at')), so both cases now
     * fail validation identically.
     */
    public function test_a_soft_deleted_variation_returns_422_and_leaves_the_row_untouched(): void
    {
        [$definition, $black, $white] = $this->persistedColorDefinition();

        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white])]);
        $variation = $product->addStandardVariation([$definition->id() => $white->id()], 'SKU-VAR-WHITE');
        $variation->activate();
        $variation->archive();
        app(ProductRepository::class)->save($product);

        $variationId = (string) $variation->id();
        VariationModel::find($variationId)->delete();

        $response = $this->postJson("/api/variations/{$variationId}/restore");

        $response->assertStatus(422);

        $row = VariationModel::withTrashed()->find($variationId);
        $this->assertNotNull($row, 'the row must still exist (soft-deleted), never actually removed by this request');
        $this->assertNotNull($row->deleted_at, 'the row must still be soft-deleted, untouched by this request');
    }

    public function test_a_drifted_axis_returns_422_and_leaves_the_row_archived(): void
    {
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
        $variation = $product->addStandardVariation(
            [$colorDefinition->id() => $black->id(), $sizeDefinition->id() => $small->id()],
            'SKU-VAR-BLACK-S'
        );
        $variation->activate();
        $variation->archive();
        // Zero LIVE variations remain — safe to remove the "size" axis per R2/R3.
        $product->declareVariationAxes([new VariationAxis($colorDefinition, [$black, $white])]);
        app(ProductRepository::class)->save($product);

        $variationId = (string) $variation->id();

        $response = $this->postJson("/api/variations/{$variationId}/restore");

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'cannot be restored from the archive',
            $response->json('message')
        );
        $this->assertSame('archived', VariationModel::find($variationId)->status, 'a failed restore must not change anything');
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->app['auth']->guard('staff')->logout();

        [$definition, $black, $white] = $this->persistedColorDefinition();
        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white])]);
        $variation = $product->addStandardVariation([$definition->id() => $white->id()], 'SKU-VAR-WHITE');
        $variation->activate();
        $variation->archive();
        app(ProductRepository::class)->save($product);

        $variationId = (string) $variation->id();

        $response = $this->postJson("/api/variations/{$variationId}/restore");

        $response->assertStatus(401);
    }

    public function test_a_staff_member_without_product_manage_is_forbidden(): void
    {
        $role = Role::create('View Only', [Permission::PRODUCT_VIEW]);
        app(RoleRepository::class)->save($role);
        $staff = Staff::create('view.only@example.com', app(PasswordHasher::class)->hash('password123'), 'View Only', $role);
        app(StaffRepository::class)->save($staff);

        [$definition, $black, $white] = $this->persistedColorDefinition();
        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white])]);
        $variation = $product->addStandardVariation([$definition->id() => $white->id()], 'SKU-VAR-WHITE');
        $variation->activate();
        $variation->archive();
        app(ProductRepository::class)->save($product);

        $variationId = (string) $variation->id();

        $this->app['auth']->guard('staff')->logout();
        $this->actingAs(StaffModel::find($staff->id()), 'staff');

        $response = $this->postJson("/api/variations/{$variationId}/restore");

        $response->assertStatus(403);
        $this->assertSame('archived', VariationModel::find($variationId)->status);
    }
}
