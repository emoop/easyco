<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Contracts\TagRepository;
use EasyCo\Catalog\Persistence\Eloquent\ProductTagModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\ProductTag;
use EasyCo\Catalog\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTagControllerTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    private function productId(): string
    {
        self::$counter++;
        $suffix = (string) self::$counter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->id();
    }

    private function tagId(): string
    {
        self::$counter++;
        $suffix = (string) self::$counter;

        $tag = new Tag(id: null, name: "Tag {$suffix}", slug: "tag-{$suffix}");
        app(TagRepository::class)->save($tag);

        return $tag->id();
    }

    private function assignTag(string $productId, ?string $tagId = null): string
    {
        $productTag = new ProductTag(null, $productId, $tagId ?? $this->tagId());
        app(ProductTagRepository::class)->save($productTag);

        return $productTag->id();
    }

    public function test_happy_path_attach_creates_a_row_and_returns_201(): void
    {
        $productId = $this->productId();
        $tagId = $this->tagId();

        $response = $this->postJson("/api/products/{$productId}/tags", [
            'tag_id' => $tagId,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('product_id', $productId);
        $response->assertJsonPath('tag_id', $tagId);

        $this->assertSame(1, ProductTagModel::count());
        $row = ProductTagModel::first();
        $this->assertSame($productId, (string) $row->product_id);
        $this->assertSame($tagId, (string) $row->tag_id);
    }

    public function test_attaching_the_same_tag_twice_returns_a_clean_422_and_creates_no_duplicate_row(): void
    {
        $productId = $this->productId();
        $tagId = $this->tagId();

        $first = $this->postJson("/api/products/{$productId}/tags", ['tag_id' => $tagId]);
        $first->assertStatus(201);

        $second = $this->postJson("/api/products/{$productId}/tags", ['tag_id' => $tagId]);
        $second->assertStatus(422);

        $this->assertSame(1, ProductTagModel::count());
    }

    public function test_attaching_to_a_nonexistent_product_id_returns_422(): void
    {
        $tagId = $this->tagId();

        $response = $this->postJson('/api/products/999999/tags', ['tag_id' => $tagId]);

        $response->assertStatus(422);
        $this->assertSame(0, ProductTagModel::count());
    }

    public function test_attaching_a_nonexistent_tag_id_returns_422(): void
    {
        $productId = $this->productId();

        $response = $this->postJson("/api/products/{$productId}/tags", ['tag_id' => 999999]);

        $response->assertStatus(422);
        $this->assertSame(0, ProductTagModel::count());
    }

    public function test_index_on_a_product_with_no_tags_returns_an_empty_data_array(): void
    {
        $productId = $this->productId();

        $response = $this->getJson("/api/products/{$productId}/tags");

        $response->assertStatus(200);
        $response->assertExactJson(['data' => []]);
    }

    public function test_index_returns_the_hydrated_shape(): void
    {
        $productId = $this->productId();
        $tag = new Tag(id: null, name: 'Summer', slug: 'summer');
        app(TagRepository::class)->save($tag);
        $this->assignTag($productId, $tag->id());

        $response = $this->getJson("/api/products/{$productId}/tags");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($tag->id(), $data[0]['tag_id']);
        $this->assertSame('Summer', $data[0]['name']);
        $this->assertSame('summer', $data[0]['slug']);
        $this->assertArrayHasKey('id', $data[0]);
    }

    public function test_index_for_a_nonexistent_product_id_returns_422(): void
    {
        $response = $this->getJson('/api/products/999999/tags');

        $response->assertStatus(422);
    }

    public function test_destroy_happy_path_returns_204_and_removes_the_row(): void
    {
        $productId = $this->productId();
        $tagId = $this->tagId();
        $this->assignTag($productId, $tagId);

        $response = $this->deleteJson("/api/products/{$productId}/tags/{$tagId}");

        $response->assertStatus(204);
        $this->assertSame(0, ProductTagModel::count());
    }

    public function test_destroy_an_assignment_belonging_to_a_different_product_returns_404_and_does_not_delete(): void
    {
        $productId = $this->productId();
        $otherProductId = $this->productId();
        $tagId = $this->tagId();
        $this->assignTag($productId, $tagId);

        $response = $this->deleteJson("/api/products/{$otherProductId}/tags/{$tagId}");

        $response->assertStatus(404);
        $this->assertSame(1, ProductTagModel::count());
    }

    public function test_destroy_a_nonexistent_tag_id_on_a_real_product_returns_404(): void
    {
        $productId = $this->productId();

        $response = $this->deleteJson("/api/products/{$productId}/tags/999999");

        $response->assertStatus(404);
    }
}
