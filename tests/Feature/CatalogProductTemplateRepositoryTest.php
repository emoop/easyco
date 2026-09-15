<?php

namespace Tests\Feature;

use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Contracts\ProductGroupRepository;
use EasyCo\Catalog\Contracts\ProductTemplateRepository;
use EasyCo\Catalog\Contracts\SeasonRepository;
use EasyCo\Catalog\ProductGroup;
use EasyCo\Catalog\ProductTemplate;
use EasyCo\Catalog\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests ProductTemplate round-tripping through
 * EloquentProductTemplateRepository against real MySQL — including
 * confirming the categoryIds/tagIds JSON cast actually round-trips as
 * real arrays, not just that the write succeeds.
 */
class CatalogProductTemplateRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_then_find_by_id_round_trips_a_real_product_template(): void
    {
        $repository = app(ProductTemplateRepository::class);

        $template = new ProductTemplate(id: null, name: 'Basic Shoe Template');
        $repository->save($template);

        $found = $repository->findById($template->id());

        $this->assertSame('Basic Shoe Template', $found->name());
        $this->assertNull($found->brandId());
        $this->assertSame([], $found->categoryIds());
        $this->assertSame([], $found->tagIds());
    }

    public function test_rename_then_save_persists_the_new_name(): void
    {
        $repository = app(ProductTemplateRepository::class);

        $template = new ProductTemplate(id: null, name: 'Basic Shoe Template');
        $repository->save($template);

        $template->rename('Premium Shoe Template');
        $repository->save($template);

        $found = $repository->findById($template->id());

        $this->assertSame('Premium Shoe Template', $found->name());
    }

    public function test_category_ids_and_tag_ids_round_trip_as_real_arrays_after_save_and_reload(): void
    {
        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');
        app(BrandRepository::class)->save($brand);

        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');
        app(SeasonRepository::class)->save($season);

        $group = new ProductGroup(id: null, code: 'shoes', name: 'Обувки');
        app(ProductGroupRepository::class)->save($group);

        $repository = app(ProductTemplateRepository::class);

        $template = new ProductTemplate(id: null, name: 'Full Defaults Template');
        $template->changeDefaults(
            brandId: $brand->id(),
            seasonId: $season->id(),
            productGroupId: $group->id(),
            categoryIds: ['1', '2', '3'],
            tagIds: ['9'],
        );
        $repository->save($template);

        $found = $repository->findById($template->id());

        $this->assertSame($brand->id(), $found->brandId());
        $this->assertSame($season->id(), $found->seasonId());
        $this->assertSame($group->id(), $found->productGroupId());
        $this->assertIsArray($found->categoryIds());
        $this->assertSame(['1', '2', '3'], $found->categoryIds());
        $this->assertIsArray($found->tagIds());
        $this->assertSame(['9'], $found->tagIds());
    }
}
