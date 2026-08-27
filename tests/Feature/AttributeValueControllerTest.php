<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Second HTTP surface in the VARIABLE-product chain — AttributeValue,
 * mirroring AttributeDefinitionControllerTest's style. See
 * App\Http\Controllers\Api\AttributeValueController.
 */
class AttributeValueControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createDefinition(string $code, string $type = 'select'): string
    {
        $response = $this->postJson('/api/attribute-definitions', [
            'code' => $code,
            'name' => ucfirst($code),
            'type' => $type,
        ]);

        return (string) $response->json('id');
    }

    public function test_creating_a_value_for_a_valid_definition_succeeds(): void
    {
        $definitionId = $this->createDefinition('color');

        $response = $this->postJson('/api/attribute-values', [
            'attribute_definition_id' => $definitionId,
            'value' => 'Black',
            'sort_order' => 1,
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'attribute_definition_id' => $definitionId,
            'value' => 'Black',
            'sort_order' => 1,
        ]);
        $this->assertNotNull($response->json('id'));

        $this->assertDatabaseHas('catalog_attribute_values', [
            'id' => $response->json('id'),
            'attribute_definition_id' => $definitionId,
            'value' => 'Black',
            'sort_order' => 1,
        ]);
    }

    public function test_creating_a_value_for_a_nonexistent_definition_id_is_rejected_with_422(): void
    {
        $response = $this->postJson('/api/attribute-values', [
            'attribute_definition_id' => '999999',
            'value' => 'Black',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['attribute_definition_id']);

        $this->assertDatabaseMissing('catalog_attribute_values', [
            'value' => 'Black',
        ]);
    }

    public function test_listing_values_filters_correctly_by_definition(): void
    {
        $colorId = $this->createDefinition('color');
        $materialId = $this->createDefinition('material', 'text');

        $this->postJson('/api/attribute-values', [
            'attribute_definition_id' => $colorId,
            'value' => 'Black',
        ])->assertStatus(201);

        $this->postJson('/api/attribute-values', [
            'attribute_definition_id' => $colorId,
            'value' => 'White',
        ])->assertStatus(201);

        $this->postJson('/api/attribute-values', [
            'attribute_definition_id' => $materialId,
            'value' => 'Cotton',
        ])->assertStatus(201);

        $colorValues = $this->getJson("/api/attribute-definitions/{$colorId}/values");
        $colorValues->assertStatus(200);
        $colorValues->assertJsonCount(2);
        $colorLabels = array_column($colorValues->json(), 'value');
        sort($colorLabels);
        $this->assertSame(['Black', 'White'], $colorLabels);

        $materialValues = $this->getJson("/api/attribute-definitions/{$materialId}/values");
        $materialValues->assertStatus(200);
        $materialValues->assertJsonCount(1);
        $this->assertSame('Cotton', $materialValues->json('0.value'));
    }
}
