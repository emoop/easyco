<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\SeasonRepository;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests Product::seasonId()/assignSeason() round-tripping through
 * EloquentProductRepository against real MySQL — mirrors
 * CatalogProductBrandRepositoryTest exactly. catalog_products.season_id
 * is a real FK to catalog_seasons (nullOnDelete), so a saved season_id
 * must reference a real, persisted Season row.
 */
class CatalogProductSeasonRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_product_with_a_season_round_trips_the_season_id(): void
    {
        $seasonRepository = app(SeasonRepository::class);
        $productRepository = app(ProductRepository::class);

        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');
        $seasonRepository->save($season);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $product->assignSeason($season->id());
        $productRepository->save($product);

        $found = $productRepository->findById($product->id());

        $this->assertSame($season->id(), $found->seasonId());
    }

    public function test_saving_a_product_with_no_season_round_trips_a_null_season_id(): void
    {
        $productRepository = app(ProductRepository::class);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $productRepository->save($product);

        $found = $productRepository->findById($product->id());

        $this->assertNull($found->seasonId());
    }

    public function test_changing_a_products_season_and_saving_again_persists_the_new_value(): void
    {
        $seasonRepository = app(SeasonRepository::class);
        $productRepository = app(ProductRepository::class);

        $springSummer = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');
        $seasonRepository->save($springSummer);

        $fallWinter = new Season(id: null, name: 'Fall/Winter 2026', slug: 'fall-winter-2026');
        $seasonRepository->save($fallWinter);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $product->assignSeason($springSummer->id());
        $productRepository->save($product);

        $product->assignSeason($fallWinter->id());
        $productRepository->save($product);

        $found = $productRepository->findById($product->id());

        $this->assertSame($fallWinter->id(), $found->seasonId());
    }
}
