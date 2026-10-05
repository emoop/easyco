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

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdministrator();
    }

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

    // --- Input hardening pass 2: the sort_order ceiling ---------------------------------

    /**
     * catalog_attribute_values.sort_order is an unsignedInteger: 4294967295 is
     * the widest value the column can hold. `nullable|integer` alone let one
     * above it reach the column (a 500) and let a negative through to a column
     * that cannot hold it — both are 422 field errors that write nothing now.
     */
    public function test_a_sort_order_of_the_columns_own_maximum_is_accepted_and_stored_unchanged(): void
    {
        $definitionId = $this->createDefinition('size');

        $response = $this->postJson('/api/attribute-values', [
            'attribute_definition_id' => $definitionId,
            'value' => 'XL',
            'sort_order' => 4294967295,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('sort_order', 4294967295);

        $this->assertDatabaseHas('catalog_attribute_values', [
            'attribute_definition_id' => $definitionId,
            'value' => 'XL',
            'sort_order' => 4294967295,
        ]);
    }

    public function test_a_sort_order_one_above_the_columns_own_maximum_is_a_422_field_error_and_writes_nothing(): void
    {
        $definitionId = $this->createDefinition('size');

        $response = $this->postJson('/api/attribute-values', [
            'attribute_definition_id' => $definitionId,
            'value' => 'XL',
            'sort_order' => 4294967296,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['sort_order']);

        $this->assertDatabaseMissing('catalog_attribute_values', ['value' => 'XL']);
    }

    public function test_a_negative_sort_order_is_a_422_field_error_and_writes_nothing(): void
    {
        $definitionId = $this->createDefinition('size');

        $response = $this->postJson('/api/attribute-values', [
            'attribute_definition_id' => $definitionId,
            'value' => 'XL',
            'sort_order' => -1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['sort_order']);

        $this->assertDatabaseMissing('catalog_attribute_values', ['value' => 'XL']);
    }
}
