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
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * The purely in-memory half of this guard (no save/reload involved at
     * all) is tested in packages/EasyCo/Catalog/tests/ProductAxisRedeclarationGuardTest.php
     * — it needs no DB, so it lives in the Catalog package's own fast
     * suite, consistent with how every other pure domain rule is tested
     * there. This test covers the other half named in the acceptance
     * criteria: the guard must still hold after a real save/reload round
     * trip, which does need a real repository and DB.
     */
    public function test_declare_variation_axes_is_rejected_after_a_save_reload_round_trip_too(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-7', 't-shirt-7');
        $product->declareVariationAxes([$this->colorAxis()]);
        $product->addStandardVariation([(int) $this->colorDefinitionModel->id => (int) $this->black->id], 'SKU-BLACK');
        $this->repository()->save($product);

        $reloaded = $this->repository()->findByIdWithVariations($product->id());

        $this->expectException(UnsafeAxisRedeclarationException::class);
        $reloaded->declareVariationAxes([$this->colorAxis()]);
    }
}
