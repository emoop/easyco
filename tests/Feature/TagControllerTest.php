<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * First HTTP surface for the global, reusable Tag set —
 * App\Http\Controllers\Api\TagController.
 */
class TagControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_tag_succeeds_and_persists(): void
    {
        $response = $this->postJson('/api/tags', [
            'name' => 'Summer',
            'slug' => 'summer',
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'name' => 'Summer',
            'slug' => 'summer',
        ]);
        $this->assertNotNull($response->json('id'));

        $this->assertDatabaseHas('catalog_tags', [
            'id' => $response->json('id'),
            'name' => 'Summer',
            'slug' => 'summer',
        ]);
    }

    public function test_a_missing_name_is_rejected_with_422(): void
    {
        $response = $this->postJson('/api/tags', [
            'slug' => 'summer',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);

        $this->assertDatabaseMissing('catalog_tags', ['slug' => 'summer']);
    }

    public function test_a_missing_slug_is_rejected_with_422(): void
    {
        $response = $this->postJson('/api/tags', [
            'name' => 'Summer',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['slug']);

        $this->assertDatabaseMissing('catalog_tags', ['name' => 'Summer']);
    }

    public function test_index_returns_previously_created_tags(): void
    {
        $this->postJson('/api/tags', ['name' => 'Summer', 'slug' => 'summer'])->assertStatus(201);
        $this->postJson('/api/tags', ['name' => 'Winter', 'slug' => 'winter'])->assertStatus(201);

        $response = $this->getJson('/api/tags');

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $slugs = array_column($response->json(), 'slug');
        sort($slugs);
        $this->assertSame(['summer', 'winter'], $slugs);
    }
}
