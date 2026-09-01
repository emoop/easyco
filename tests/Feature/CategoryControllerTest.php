<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * First HTTP surface for the global, reusable Category set —
 * App\Http\Controllers\Api\CategoryController.
 */
class CategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_category_with_no_parent_succeeds_and_persists(): void
    {
        $response = $this->postJson('/api/categories', [
            'name' => 'Shoes',
            'slug' => 'shoes',
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'name' => 'Shoes',
            'slug' => 'shoes',
            'parent_id' => null,
        ]);
        $this->assertNotNull($response->json('id'));

        $this->assertDatabaseHas('catalog_categories', [
            'id' => $response->json('id'),
            'name' => 'Shoes',
            'slug' => 'shoes',
            'parent_id' => null,
        ]);
    }

    public function test_creating_a_category_with_a_real_parent_id_round_trips_it(): void
    {
        $parentResponse = $this->postJson('/api/categories', ['name' => 'Shoes', 'slug' => 'shoes']);
        $parentId = $parentResponse->json('id');

        $response = $this->postJson('/api/categories', [
            'name' => 'Running Shoes',
            'slug' => 'running-shoes',
            'parent_id' => $parentId,
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'name' => 'Running Shoes',
            'slug' => 'running-shoes',
            'parent_id' => $parentId,
        ]);

        $this->assertDatabaseHas('catalog_categories', [
            'id' => $response->json('id'),
            'parent_id' => $parentId,
        ]);
    }

    public function test_creating_a_category_with_a_nonexistent_parent_id_returns_422(): void
    {
        $response = $this->postJson('/api/categories', [
            'name' => 'Running Shoes',
            'slug' => 'running-shoes',
            'parent_id' => '999999',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['parent_id']);

        $this->assertDatabaseMissing('catalog_categories', ['slug' => 'running-shoes']);
    }

    public function test_a_missing_name_is_rejected_with_422(): void
    {
        $response = $this->postJson('/api/categories', [
            'slug' => 'shoes',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);

        $this->assertDatabaseMissing('catalog_categories', ['slug' => 'shoes']);
    }

    public function test_a_missing_slug_is_rejected_with_422(): void
    {
        $response = $this->postJson('/api/categories', [
            'name' => 'Shoes',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['slug']);

        $this->assertDatabaseMissing('catalog_categories', ['name' => 'Shoes']);
    }

    public function test_index_returns_previously_created_categories(): void
    {
        $this->postJson('/api/categories', ['name' => 'Shoes', 'slug' => 'shoes'])->assertStatus(201);
        $this->postJson('/api/categories', ['name' => 'Bags', 'slug' => 'bags'])->assertStatus(201);

        $response = $this->getJson('/api/categories');

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $slugs = array_column($response->json(), 'slug');
        sort($slugs);
        $this->assertSame(['bags', 'shoes'], $slugs);
    }
}
