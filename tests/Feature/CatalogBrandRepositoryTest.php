<?php

namespace Tests\Feature;

use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\MediaAsset;
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

    public function test_rename_then_save_persists_the_new_name(): void
    {
        $repository = app(BrandRepository::class);

        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');
        $repository->save($brand);

        $brand->rename('Nike Inc.');
        $repository->save($brand);

        $reloaded = $repository->findById($brand->id());

        $this->assertSame('Nike Inc.', $reloaded->name());
    }

    public function test_change_slug_then_save_persists_the_new_slug(): void
    {
        $repository = app(BrandRepository::class);

        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');
        $repository->save($brand);

        $brand->changeSlug('nike-inc');
        $repository->save($brand);

        $reloaded = $repository->findById($brand->id());

        $this->assertSame('nike-inc', $reloaded->slug());
    }

    public function test_set_logo_then_save_persists_a_real_logo_media_asset_id(): void
    {
        $brandRepository = app(BrandRepository::class);
        $mediaAssetRepository = app(MediaAssetRepository::class);

        $logo = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/09/nike-logo.png');
        $mediaAssetRepository->save($logo);

        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');
        $brandRepository->save($brand);

        $brand->setLogo($logo->id());
        $brandRepository->save($brand);

        $reloaded = $brandRepository->findById($brand->id());

        $this->assertSame($logo->id(), $reloaded->logoMediaAssetId());
    }

    public function test_remove_logo_then_save_persists_null(): void
    {
        $brandRepository = app(BrandRepository::class);
        $mediaAssetRepository = app(MediaAssetRepository::class);

        $logo = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/09/nike-logo.png');
        $mediaAssetRepository->save($logo);

        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');
        $brand->setLogo($logo->id());
        $brandRepository->save($brand);

        $brand->removeLogo();
        $brandRepository->save($brand);

        $reloaded = $brandRepository->findById($brand->id());

        $this->assertNull($reloaded->logoMediaAssetId());
    }

    public function test_count_products_using_is_zero_when_unused(): void
    {
        $repository = app(BrandRepository::class);

        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');
        $repository->save($brand);

        $this->assertSame(0, $repository->countProductsUsing($brand->id()));
    }

    public function test_count_products_using_counts_every_product_referencing_this_brand(): void
    {
        $brandRepository = app(BrandRepository::class);
        $productRepository = app(ProductRepository::class);

        $nike = new Brand(id: null, name: 'Nike', slug: 'nike');
        $brandRepository->save($nike);

        $adidas = new Brand(id: null, name: 'Adidas', slug: 'adidas');
        $brandRepository->save($adidas);

        for ($i = 1; $i <= 3; $i++) {
            $product = Product::createSimple("Product {$i}", "SKU-{$i}", "product-{$i}");
            $product->assignBrand($nike->id());
            $productRepository->save($product);
        }

        $unrelated = Product::createSimple('Unrelated', 'SKU-unrelated', 'unrelated');
        $unrelated->assignBrand($adidas->id());
        $productRepository->save($unrelated);

        $this->assertSame(3, $brandRepository->countProductsUsing($nike->id()));
    }
}
