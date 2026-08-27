<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Final step of the VARIABLE-product HTTP chain —
 * App\Http\Controllers\Api\VariableProductController — tying together
 * AttributeDefinition/AttributeValue (previous two steps) with
 * Product::createVariable()/declareVariationAxes() and
 * VariationCombinationGenerator.
 */
class VariableProductControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createDefinition(string $code): string
    {
        return (string) $this->postJson('/api/attribute-definitions', [
            'code' => $code,
            'name' => ucfirst($code),
            'type' => 'select',
        ])->json('id');
    }

    private function createValue(string $definitionId, string $value): string
    {
        return (string) $this->postJson('/api/attribute-values', [
            'attribute_definition_id' => $definitionId,
            'value' => $value,
        ])->json('id');
    }

    public function test_happy_path_creates_all_combinations_with_correct_skus_and_assignments(): void
    {
        $colorId = $this->createDefinition('color');
        $black = $this->createValue($colorId, 'Black');
        $white = $this->createValue($colorId, 'White');

        $sizeId = $this->createDefinition('size');
        $small = $this->createValue($sizeId, 'Small');
        $large = $this->createValue($sizeId, 'Large');

        $response = $this->postJson('/api/products/variable', [
            'name' => 'T-Shirt',
            'base_sku' => 'TSHIRT',
            'axes' => [
                ['attribute_definition_id' => $colorId, 'value_ids' => [$black, $white]],
                ['attribute_definition_id' => $sizeId, 'value_ids' => [$small, $large]],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'base_sku' => 'TSHIRT',
        ]);

        $productId = $response->json('product_id');
        $variations = $response->json('variations');

        $this->assertCount(4, $variations);

        $skus = array_column($variations, 'sku');
        $this->assertSame($skus, array_unique($skus), 'SKUs must all be distinct');
        foreach ($skus as $sku) {
            $this->assertStringStartsWith('TSHIRT-', $sku);
        }

        $this->assertDatabaseHas('catalog_products', [
            'id' => $productId,
            'type' => 'variable',
            'base_sku' => 'TSHIRT',
        ]);

        foreach ($variations as $variation) {
            $this->assertDatabaseHas('catalog_variations', [
                'id' => $variation['id'],
                'product_id' => $productId,
                'sku' => $variation['sku'],
                'type' => 'standard',
            ]);

            $assignments = $variation['attribute_assignments'];
            $this->assertArrayHasKey($colorId, $assignments);
            $this->assertArrayHasKey($sizeId, $assignments);
            $this->assertContains((string) $assignments[$colorId], [$black, $white]);
            $this->assertContains((string) $assignments[$sizeId], [$small, $large]);

            $this->assertDatabaseHas('catalog_variation_attribute_values', [
                'variation_id' => $variation['id'],
                'attribute_definition_id' => $colorId,
                'attribute_value_id' => $assignments[$colorId],
            ]);
            $this->assertDatabaseHas('catalog_variation_attribute_values', [
                'variation_id' => $variation['id'],
                'attribute_definition_id' => $sizeId,
                'attribute_value_id' => $assignments[$sizeId],
            ]);
        }

        // Every (color, size) combination is actually distinct.
        $combos = array_map(
            static fn ($v) => $v['attribute_assignments'][$colorId].'/'.$v['attribute_assignments'][$sizeId],
            $variations
        );
        $this->assertCount(4, array_unique($combos));
    }

    public function test_a_nonexistent_attribute_definition_id_is_rejected_with_422(): void
    {
        $colorId = $this->createDefinition('color');
        $black = $this->createValue($colorId, 'Black');

        $response = $this->postJson('/api/products/variable', [
            'name' => 'Ghost Product',
            'axes' => [
                ['attribute_definition_id' => '999999', 'value_ids' => [$black]],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['axes.0.attribute_definition_id']);
    }

    public function test_repeating_the_same_attribute_definition_id_across_two_axes_is_rejected_with_422(): void
    {
        $colorId = $this->createDefinition('color');
        $black = $this->createValue($colorId, 'Black');
        $white = $this->createValue($colorId, 'White');

        // Declaring the same attribute_definition_id as two separate
        // 'axes' entries — Product::declareVariationAxes() rejects this
        // with a plain \LogicException, now caught by
        // VariableProductController and turned into a 422.
        $response = $this->postJson('/api/products/variable', [
            'name' => 'Duplicate Axis Product',
            'axes' => [
                ['attribute_definition_id' => $colorId, 'value_ids' => [$black]],
                ['attribute_definition_id' => $colorId, 'value_ids' => [$white]],
            ],
        ]);

        $response->assertStatus(422);
        $message = $response->json('message');
        $this->assertStringContainsString('declared as a variation axis more than once', $message);
        $this->assertStringContainsString($colorId, $message);

        $this->assertDatabaseMissing('catalog_products', [
            'name' => 'Duplicate Axis Product',
        ]);
    }

    public function test_a_value_id_belonging_to_a_different_attribute_definition_is_rejected(): void
    {
        $colorId = $this->createDefinition('color');
        $materialId = $this->createDefinition('material');
        $cotton = $this->createValue($materialId, 'Cotton');

        // $cotton is a real, existing catalog_attribute_values row, so it
        // passes the controller's plain 'exists' validation rule — but it
        // belongs to $materialId, not $colorId. This is the cross-field
        // check VariationAxis's constructor enforces, not the validator.
        $response = $this->postJson('/api/products/variable', [
            'name' => 'Mismatched Axis Product',
            'axes' => [
                ['attribute_definition_id' => $colorId, 'value_ids' => [$cotton]],
            ],
        ]);

        // VariationAxis's constructor throws InvalidVariationAxisException
        // (extends InvalidArgumentException) for this — VariableProductController
        // now catches it explicitly and returns a 422 with the exception's
        // own message, instead of letting it propagate to Laravel's
        // default handler as a raw 500.
        $response->assertStatus(422);
        $message = $response->json('message');
        $this->assertStringContainsString('belongs to attribute definition', $message);
        $this->assertStringContainsString($materialId, $message);
        $this->assertStringContainsString($colorId, $message);

        $this->assertDatabaseMissing('catalog_products', [
            'name' => 'Mismatched Axis Product',
        ]);
    }

    public function test_omitting_base_sku_and_slug_still_produces_sensible_generated_values(): void
    {
        $colorId = $this->createDefinition('color');
        $black = $this->createValue($colorId, 'Black');

        $response = $this->postJson('/api/products/variable', [
            'name' => 'Auto Generated Variable Product',
            'axes' => [
                ['attribute_definition_id' => $colorId, 'value_ids' => [$black]],
            ],
        ]);

        $response->assertStatus(201);

        $productId = $response->json('product_id');
        $baseSku = $response->json('base_sku');
        $slug = $response->json('slug');

        $this->assertNotSame('', $baseSku);
        $this->assertSame('auto-generated-variable-product', $slug);

        $this->assertDatabaseHas('catalog_products', [
            'id' => $productId,
            'base_sku' => $baseSku,
            'slug' => $slug,
        ]);

        $variations = $response->json('variations');
        $this->assertCount(1, $variations);
        $this->assertSame("{$baseSku}-1", $variations[0]['sku']);
    }
}
