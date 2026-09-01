<?php

namespace Tests\Feature;

use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Contracts\BrandRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests EloquentBrandRepository against real MySQL — save/findById/all
 * round-trips, and that a duplicate slug propagates as a raw
 * QueryException (no dedicated exception wrapping), same precedent as
 * AttributeDefinition's own repository.
 */
class CatalogBrandRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_assigns_an_id_to_a_new_brand(): void
    {
        $repository = app(BrandRepository::class);

        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');
        $repository->save($brand);

        $this->assertNotNull($brand->id());
        $this->assertDatabaseHas('catalog_brands', ['id' => $brand->id(), 'name' => 'Nike', 'slug' => 'nike']);
    }

    public function test_find_by_id_round_trips_a_saved_brand(): void
    {
        $repository = app(BrandRepository::class);

        $brand = new Brand(id: null, name: 'Adidas', slug: 'adidas');
        $repository->save($brand);

        $found = $repository->findById($brand->id());

        $this->assertNotNull($found);
        $this->assertSame($brand->id(), $found->id());
        $this->assertSame('Adidas', $found->name());
        $this->assertSame('adidas', $found->slug());
    }

    public function test_find_by_id_returns_null_for_an_unknown_id(): void
    {
        $repository = app(BrandRepository::class);

        $this->assertNull($repository->findById('999999'));
    }

    public function test_all_returns_every_saved_brand(): void
    {
        $repository = app(BrandRepository::class);

        $repository->save(new Brand(id: null, name: 'Nike', slug: 'nike'));
        $repository->save(new Brand(id: null, name: 'Adidas', slug: 'adidas'));

        $all = $repository->all();
        $names = array_map(fn (Brand $brand) => $brand->name(), $all);
        sort($names);

        $this->assertCount(2, $all);
        $this->assertSame(['Adidas', 'Nike'], $names);
    }

    public function test_saving_a_second_brand_with_a_colliding_slug_throws_a_raw_query_exception(): void
    {
        $repository = app(BrandRepository::class);

        $repository->save(new Brand(id: null, name: 'Nike', slug: 'colliding-slug'));

        $this->expectException(QueryException::class);

        $repository->save(new Brand(id: null, name: 'Not Nike', slug: 'colliding-slug'));
    }
}
