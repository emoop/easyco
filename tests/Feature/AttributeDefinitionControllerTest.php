<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * First HTTP surface for the global, reusable AttributeDefinition set
 * (catalog-domain-design.md §3.3) — App\Http\Controllers\Api\AttributeDefinitionController.
 */
class AttributeDefinitionControllerTest extends TestCase
{
    use RefreshDatabase;

    public static function validTypeProvider(): array
    {
        return [
            'text' => ['text'],
            'number' => ['number'],
            'boolean' => ['boolean'],
            'select' => ['select'],
            'multiselect' => ['multiselect'],
        ];
    }

    #[DataProvider('validTypeProvider')]
    public function test_creating_a_definition_of_each_valid_type_succeeds_and_persists(string $type): void
    {
        $response = $this->postJson('/api/attribute-definitions', [
            'code' => "code-$type",
            'name' => "Name for $type",
            'type' => $type,
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'code' => "code-$type",
            'name' => "Name for $type",
            'type' => $type,
        ]);
        $this->assertNotNull($response->json('id'));

        $this->assertDatabaseHas('catalog_attribute_definitions', [
            'id' => $response->json('id'),
            'code' => "code-$type",
            'name' => "Name for $type",
            'type' => $type,
        ]);
    }

    public function test_an_invalid_type_is_rejected_with_422(): void
    {
        $response = $this->postJson('/api/attribute-definitions', [
            'code' => 'color',
            'name' => 'Color',
            'type' => 'not-a-real-type',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type']);

        $this->assertDatabaseMissing('catalog_attribute_definitions', [
            'code' => 'color',
        ]);
    }

    public function test_index_returns_previously_created_definitions(): void
    {
        $this->postJson('/api/attribute-definitions', [
            'code' => 'color',
            'name' => 'Color',
            'type' => 'select',
        ])->assertStatus(201);

        $this->postJson('/api/attribute-definitions', [
            'code' => 'material',
            'name' => 'Material',
            'type' => 'text',
        ])->assertStatus(201);

        $response = $this->getJson('/api/attribute-definitions');

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $codes = array_column($response->json(), 'code');
        sort($codes);
        $this->assertSame(['color', 'material'], $codes);
    }
}
