<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\SeasonRepository;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Season;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests EloquentSeasonRepository against real MySQL — mirrors
 * CatalogBrandRepositoryTest's identical shape (save/findById/all
 * round-trips, duplicate slug propagates as a raw QueryException,
 * rename()/changeSlug() persistence), plus countProductsUsing().
 */
class CatalogSeasonRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_assigns_an_id_to_a_new_season(): void
    {
        $repository = app(SeasonRepository::class);

        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');
        $repository->save($season);

        $this->assertNotNull($season->id());
        $this->assertDatabaseHas('catalog_seasons', ['id' => $season->id(), 'name' => 'Spring/Summer 2026', 'slug' => 'spring-summer-2026']);
    }

    public function test_find_by_id_round_trips_a_saved_season(): void
    {
        $repository = app(SeasonRepository::class);

        $season = new Season(id: null, name: 'Fall/Winter 2026', slug: 'fall-winter-2026');
        $repository->save($season);

        $found = $repository->findById($season->id());

        $this->assertNotNull($found);
        $this->assertSame($season->id(), $found->id());
        $this->assertSame('Fall/Winter 2026', $found->name());
        $this->assertSame('fall-winter-2026', $found->slug());
    }

    public function test_find_by_id_returns_null_for_an_unknown_id(): void
    {
        $repository = app(SeasonRepository::class);

        $this->assertNull($repository->findById('999999'));
    }

    public function test_all_returns_every_saved_season(): void
    {
        $repository = app(SeasonRepository::class);

        $repository->save(new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026'));
        $repository->save(new Season(id: null, name: 'Fall/Winter 2026', slug: 'fall-winter-2026'));

        $all = $repository->all();
        $names = array_map(fn (Season $season) => $season->name(), $all);
        sort($names);

        $this->assertCount(2, $all);
        $this->assertSame(['Fall/Winter 2026', 'Spring/Summer 2026'], $names);
    }

    public function test_saving_a_second_season_with_a_colliding_slug_throws_a_raw_query_exception(): void
    {
        $repository = app(SeasonRepository::class);

        $repository->save(new Season(id: null, name: 'Spring/Summer 2026', slug: 'colliding-slug'));

        $this->expectException(QueryException::class);

        $repository->save(new Season(id: null, name: 'Fall/Winter 2026', slug: 'colliding-slug'));
    }

    public function test_rename_then_save_persists_the_new_name(): void
    {
        $repository = app(SeasonRepository::class);

        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');
        $repository->save($season);

        $season->rename('Spring/Summer 2027');
        $repository->save($season);

        $reloaded = $repository->findById($season->id());

        $this->assertSame('Spring/Summer 2027', $reloaded->name());
    }

    public function test_change_slug_then_save_persists_the_new_slug(): void
    {
        $repository = app(SeasonRepository::class);

        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');
        $repository->save($season);

        $season->changeSlug('spring-summer-2027');
        $repository->save($season);

        $reloaded = $repository->findById($season->id());

        $this->assertSame('spring-summer-2027', $reloaded->slug());
    }

    public function test_count_products_using_is_zero_when_unused(): void
    {
        $repository = app(SeasonRepository::class);

        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');
        $repository->save($season);

        $this->assertSame(0, $repository->countProductsUsing($season->id()));
    }

    public function test_count_products_using_counts_every_product_referencing_this_season(): void
    {
        $seasonRepository = app(SeasonRepository::class);
        $productRepository = app(ProductRepository::class);

        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');
        $seasonRepository->save($season);

        $otherSeason = new Season(id: null, name: 'Fall/Winter 2026', slug: 'fall-winter-2026');
        $seasonRepository->save($otherSeason);

        for ($i = 1; $i <= 3; $i++) {
            $product = Product::createSimple("Product {$i}", "SKU-{$i}", "product-{$i}");
            $product->assignSeason($season->id());
            $productRepository->save($product);
        }

        $unrelated = Product::createSimple('Unrelated', 'SKU-unrelated', 'unrelated');
        $unrelated->assignSeason($otherSeason->id());
        $productRepository->save($unrelated);

        $this->assertSame(3, $seasonRepository->countProductsUsing($season->id()));
    }
}
