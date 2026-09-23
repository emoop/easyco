<?php

namespace Tests\Feature;

use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Exceptions\InvalidVariationAxisException;
use EasyCo\Catalog\Exceptions\UnsafeAxisRedeclarationException;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
use EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Closes the gap documented in catalog-domain-design.md §6 and
 * vertical-slice-notes.md §2: reconstituteFromStorage() previously skipped
 * axis-declaration validation entirely because a Product's declared
 * VariationAxis set was never reloaded from storage at all. Proves,
 * against a real EloquentProductRepository (real MySQL/SQLite, not
 * mocked), that a save -> reload round trip restores those axes well
 * enough for addStandardVariation()/changeVariationCombination() to
 * validate correctly against them — the acceptance bar named in the
 * corrective-pass request.
 */
class EloquentProductRepositoryVariationAxisTest extends TestCase
{
    use RefreshDatabase;

    private AttributeDefinitionModel $colorDefinitionModel;

    private AttributeValueModel $black;

    private AttributeValueModel $white;

    protected function setUp(): void
    {
        parent::setUp();

        $this->colorDefinitionModel = AttributeDefinitionModel::create([
            'code' => 'color',
            'name' => 'Color',
            'type' => 'select',
        ]);
        $this->black = AttributeValueModel::create([
            'attribute_definition_id' => $this->colorDefinitionModel->id,
            'value' => 'Black',
            'sort_order' => 0,
        ]);
        $this->white = AttributeValueModel::create([
            'attribute_definition_id' => $this->colorDefinitionModel->id,
            'value' => 'White',
            'sort_order' => 1,
        ]);
    }

    private function colorAxis(): VariationAxis
    {
        return new VariationAxis(
            new AttributeDefinition(
                id: (string) $this->colorDefinitionModel->id,
                code: $this->colorDefinitionModel->code,
                name: $this->colorDefinitionModel->name,
                type: AttributeType::SELECT,
            ),
            [
                new AttributeValue((string) $this->black->id, (string) $this->colorDefinitionModel->id, 'Black'),
                new AttributeValue((string) $this->white->id, (string) $this->colorDefinitionModel->id, 'White'),
            ]
        );
    }

    private function repository(): ProductRepository
    {
        return app(ProductRepository::class);
    }

    public function test_reloaded_product_accepts_a_valid_combination_against_its_real_declared_axes(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt-1');
        $product->declareVariationAxes([$this->colorAxis()]);
        $this->repository()->save($product);

        $reloaded = $this->repository()->findByIdWithVariations($product->id());

        $variation = $reloaded->addStandardVariation(
            [(int) $this->colorDefinitionModel->id => (int) $this->black->id],
            'SKU-BLACK'
        );

        $this->assertSame(
            [(int) $this->colorDefinitionModel->id => (int) $this->black->id],
            $variation->attributeAssignments()
        );
    }

    public function test_reloaded_product_still_rejects_an_undeclared_axis(): void
    {
        $materialDefinition = AttributeDefinitionModel::create([
            'code' => 'material',
            'name' => 'Material',
            'type' => 'select',
        ]);

        $product = Product::createVariable('T-Shirt', 'SKU-2', 't-shirt-2');
        $product->declareVariationAxes([$this->colorAxis()]); // only Color declared, never Material
        $this->repository()->save($product);

        $reloaded = $this->repository()->findByIdWithVariations($product->id());

        $this->expectException(InvalidVariationAxisException::class);
        $reloaded->addStandardVariation(
            [
                (int) $this->colorDefinitionModel->id => (int) $this->black->id,
                (int) $materialDefinition->id => 999,
            ],
            'SKU-BAD'
        );
    }

    public function test_reloaded_product_still_rejects_a_disallowed_value(): void
    {
        $red = AttributeValueModel::create([
            'attribute_definition_id' => $this->colorDefinitionModel->id,
            'value' => 'Red',
            'sort_order' => 2,
        ]);

        $product = Product::createVariable('T-Shirt', 'SKU-3', 't-shirt-3');
        $product->declareVariationAxes([$this->colorAxis()]); // only Black/White enabled, not Red
        $this->repository()->save($product);

        $reloaded = $this->repository()->findByIdWithVariations($product->id());

        $this->expectException(InvalidVariationAxisException::class);
        $reloaded->addStandardVariation([(int) $this->colorDefinitionModel->id => (int) $red->id], 'SKU-RED');
    }

    public function test_change_variation_combination_works_against_reloaded_axes(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-4', 't-shirt-4');
        $product->declareVariationAxes([$this->colorAxis()]);
        $product->addStandardVariation([(int) $this->colorDefinitionModel->id => (int) $this->black->id], 'SKU-BLACK');
        $this->repository()->save($product);

        $reloaded = $this->repository()->findByIdWithVariations($product->id());
        $variations = $reloaded->variations();
        self::assertCount(1, $variations);
        $standard = $variations[0];

        $reloaded->changeVariationCombination(
            $standard,
            [(int) $this->colorDefinitionModel->id => (int) $this->white->id]
        );

        $this->assertSame(
            [(int) $this->colorDefinitionModel->id => (int) $this->white->id],
            $standard->attributeAssignments()
        );
    }

    public function test_reloaded_axis_declarations_match_exactly_what_was_originally_declared(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-5', 't-shirt-5');
        $product->declareVariationAxes([$this->colorAxis()]);
        $this->repository()->save($product);

        $reloaded = $this->repository()->findByIdWithVariations($product->id());

        $axes = $reloaded->variationAxes();
        self::assertCount(1, $axes, 'no data loss/corruption: exactly one declared axis');
        self::assertSame((string) $this->colorDefinitionModel->id, $axes[0]->attributeDefinitionId());
        self::assertSame('color', $axes[0]->attributeDefinitionCode());

        $expectedValueIds = [(string) $this->black->id, (string) $this->white->id];
        $actualValueIds = $axes[0]->allowedValueIds();
        sort($expectedValueIds);
        sort($actualValueIds);
        self::assertSame($expectedValueIds, $actualValueIds, 'allowed values must match exactly what was declared');
    }

    /**
     * The purely in-memory half of this directional guard (no save/
     * reload involved at all) is tested in
     * packages/EasyCo/Catalog/tests/ProductAxisRedeclarationGuardTest.php
     * and packages/EasyCo/Catalog/tests/ProductVariationRestoreTest.php —
     * neither needs a DB, so they live in the Catalog package's own fast
     * suite, consistent with how every other pure domain rule is tested
     * there. The four tests below cover the other half named in the
     * acceptance criteria: the guard (and the new restore operation)
     * must still hold, and persist correctly, after a real save/reload
     * round trip, which does need a real repository and DB.
     *
     * REPLACES test_declare_variation_axes_is_rejected_after_a_save_reload_round_trip_too
     * (deleted) — that test re-declared the IDENTICAL axis set and
     * expected a refusal, encoding the OLD coarse "any re-declaration
     * once a STANDARD variation exists is refused" rule. Under the new
     * directional guard (catalog-domain-design.md §3.17), redeclaring an
     * identical set is R1's own explicit no-op allowance, not a
     * violation — this is a specified behaviour change, not a test being
     * loosened to pass.
     */
    public function test_an_axis_value_extension_survives_a_save_reload_round_trip(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-7', 't-shirt-7');
        $product->declareVariationAxes([$this->colorAxis()]);
        $product->addStandardVariation([(int) $this->colorDefinitionModel->id => (int) $this->black->id], 'SKU-BLACK');
        $this->repository()->save($product);

        $reloaded = $this->repository()->findByIdWithVariations($product->id());

        $red = AttributeValueModel::create([
            'attribute_definition_id' => $this->colorDefinitionModel->id,
            'value' => 'Red',
            'sort_order' => 2,
        ]);

        // R5: identical axis, ONE more allowed value — safe even with the
        // live Black variation already on file.
        $extendedColorAxis = new VariationAxis(
            new AttributeDefinition(
                id: (string) $this->colorDefinitionModel->id,
                code: $this->colorDefinitionModel->code,
                name: $this->colorDefinitionModel->name,
                type: AttributeType::SELECT,
            ),
            [
                new AttributeValue((string) $this->black->id, (string) $this->colorDefinitionModel->id, 'Black'),
                new AttributeValue((string) $this->white->id, (string) $this->colorDefinitionModel->id, 'White'),
                new AttributeValue((string) $red->id, (string) $this->colorDefinitionModel->id, 'Red'),
            ]
        );
        $reloaded->declareVariationAxes([$extendedColorAxis]);
        $reloaded->addStandardVariation([(int) $this->colorDefinitionModel->id => (int) $red->id], 'SKU-RED');
        $this->repository()->save($reloaded);

        $reloadedAgain = $this->repository()->findByIdWithVariations($product->id());

        $axes = $reloadedAgain->variationAxes();
        self::assertCount(1, $axes);
        $expectedValueIds = [(string) $this->black->id, (string) $this->white->id, (string) $red->id];
        $actualValueIds = $axes[0]->allowedValueIds();
        sort($expectedValueIds);
        sort($actualValueIds);
        self::assertSame($expectedValueIds, $actualValueIds);

        $variations = $reloadedAgain->variations();
        self::assertCount(2, $variations);
        $skus = array_map(static fn ($v) => $v->sku(), $variations);
        sort($skus);
        self::assertSame(['SKU-BLACK', 'SKU-RED'], $skus);
    }

    public function test_adding_a_new_axis_after_a_save_reload_round_trip_is_still_refused_while_a_live_variation_exists(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-8', 't-shirt-8');
        $product->declareVariationAxes([$this->colorAxis()]);
        $product->addStandardVariation([(int) $this->colorDefinitionModel->id => (int) $this->black->id], 'SKU-BLACK');
        $this->repository()->save($product);

        $reloaded = $this->repository()->findByIdWithVariations($product->id());

        $this->expectException(UnsafeAxisRedeclarationException::class);
        $reloaded->declareVariationAxes([$this->colorAxis(), $this->sizeAxis()]);
    }

    public function test_adding_a_new_axis_after_archiving_every_variation_succeeds_and_persists(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-9', 't-shirt-9');
        $product->declareVariationAxes([$this->colorAxis()]);
        $variation = $product->addStandardVariation([(int) $this->colorDefinitionModel->id => (int) $this->black->id], 'SKU-BLACK');
        $variation->activate();
        $variation->archive();
        $this->repository()->save($product);

        $reloaded = $this->repository()->findByIdWithVariations($product->id());

        // Zero LIVE variations remain (the one that exists is archived) —
        // safe per R3.
        $reloaded->declareVariationAxes([$this->colorAxis(), $this->sizeAxis()]);
        $this->repository()->save($reloaded);

        $reloadedAgain = $this->repository()->findByIdWithVariations($product->id());
        self::assertCount(2, $reloadedAgain->variationAxes());
    }

    /**
     * Real persistence, verified directly against catalog_variations —
     * not just the domain read: EloquentProductRepository::saveVariation()
     * writes `status` unconditionally, so a restored variation must land
     * as 'draft', with its sku/barcode/attribute_signature and its
     * catalog_variation_attribute_values rows completely untouched by
     * the restore.
     */
    public function test_a_restored_archived_variation_persists_as_draft_after_a_save_reload_round_trip(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-10', 't-shirt-10');
        $product->declareVariationAxes([$this->colorAxis()]);
        $variation = $product->addStandardVariation([(int) $this->colorDefinitionModel->id => (int) $this->black->id], 'SKU-BLACK');
        $variation->activate();
        $variation->archive();
        $this->repository()->save($product);

        $variationId = $variation->id();
        $skuBefore = $variation->sku();
        $signatureBefore = $variation->attributeSignature()->value();
        $assignmentRowsBefore = DB::table('catalog_variation_attribute_values')
            ->where('variation_id', $variationId)
            ->orderBy('attribute_definition_id')
            ->get(['attribute_definition_id', 'attribute_value_id'])
            ->toArray();

        $this->assertSame('archived', VariationModel::find($variationId)->status);

        $reloaded = $this->repository()->findByIdWithVariations($product->id());
        $reloadedVariation = $reloaded->variations()[0];
        $reloaded->restoreArchivedVariation($reloadedVariation);
        $this->repository()->save($reloaded);

        $model = VariationModel::find($variationId);
        $this->assertSame('draft', $model->status);
        $this->assertSame($skuBefore, $model->sku);
        $this->assertSame($signatureBefore, $model->attribute_signature);

        $assignmentRowsAfter = DB::table('catalog_variation_attribute_values')
            ->where('variation_id', $variationId)
            ->orderBy('attribute_definition_id')
            ->get(['attribute_definition_id', 'attribute_value_id'])
            ->toArray();
        $this->assertEquals($assignmentRowsBefore, $assignmentRowsAfter);
    }

    private function sizeAxis(): VariationAxis
    {
        $sizeDefinitionModel = AttributeDefinitionModel::firstOrCreate(
            ['code' => 'size'],
            ['name' => 'Size', 'type' => 'select']
        );
        $small = AttributeValueModel::firstOrCreate(
            ['attribute_definition_id' => $sizeDefinitionModel->id, 'value' => 'Small'],
            ['sort_order' => 0]
        );

        return new VariationAxis(
            new AttributeDefinition(
                id: (string) $sizeDefinitionModel->id,
                code: $sizeDefinitionModel->code,
                name: $sizeDefinitionModel->name,
                type: AttributeType::SELECT,
            ),
            [new AttributeValue((string) $small->id, (string) $sizeDefinitionModel->id, 'Small')]
        );
    }
}
