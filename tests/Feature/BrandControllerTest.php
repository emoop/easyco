<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * First HTTP surface for the global, reusable Brand set —
 * App\Http\Controllers\Api\BrandController.
 */
class BrandControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_brand_succeeds_and_persists(): void
    {
        $response = $this->postJson('/api/brands', [
            'name' => 'Nike',
            'slug' => 'nike',
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'name' => 'Nike',
            'slug' => 'nike',
        ]);
        $this->assertNotNull($response->json('id'));

        $this->assertDatabaseHas('catalog_brands', [
            'id' => $response->json('id'),
            'name' => 'Nike',
            'slug' => 'nike',
        ]);
    }

    public function test_a_missing_name_is_rejected_with_422(): void
    {
        $response = $this->postJson('/api/brands', [
            'slug' => 'nike',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);

        $this->assertDatabaseMissing('catalog_brands', ['slug' => 'nike']);
    }

    public function test_a_missing_slug_is_rejected_with_422(): void
    {
        $response = $this->postJson('/api/brands', [
            'name' => 'Nike',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['slug']);

        $this->assertDatabaseMissing('catalog_brands', ['name' => 'Nike']);
    }

    public function test_index_returns_previously_created_brands(): void
    {
        $this->postJson('/api/brands', ['name' => 'Nike', 'slug' => 'nike'])->assertStatus(201);
        $this->postJson('/api/brands', ['name' => 'Adidas', 'slug' => 'adidas'])->assertStatus(201);

        $response = $this->getJson('/api/brands');

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $slugs = array_column($response->json(), 'slug');
        sort($slugs);
        $this->assertSame(['adidas', 'nike'], $slugs);
    }
}
