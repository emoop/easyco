<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\TagRepository;
use EasyCo\Catalog\Tag;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests EloquentTagRepository against real MySQL — save/findById/all
 * round-trips, and that a duplicate slug propagates as a raw
 * QueryException (no dedicated exception wrapping), same precedent as
 * AttributeDefinition's own repository.
 */
class CatalogTagRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_assigns_an_id_to_a_new_tag(): void
    {
        $repository = app(TagRepository::class);

        $tag = new Tag(id: null, name: 'Summer', slug: 'summer');
        $repository->save($tag);

        $this->assertNotNull($tag->id());
        $this->assertDatabaseHas('catalog_tags', ['id' => $tag->id(), 'name' => 'Summer', 'slug' => 'summer']);
    }

    public function test_find_by_id_round_trips_a_saved_tag(): void
    {
        $repository = app(TagRepository::class);

        $tag = new Tag(id: null, name: 'Winter', slug: 'winter');
        $repository->save($tag);

        $found = $repository->findById($tag->id());

        $this->assertNotNull($found);
        $this->assertSame($tag->id(), $found->id());
        $this->assertSame('Winter', $found->name());
        $this->assertSame('winter', $found->slug());
    }

    public function test_find_by_id_returns_null_for_an_unknown_id(): void
    {
        $repository = app(TagRepository::class);

        $this->assertNull($repository->findById('999999'));
    }

    public function test_all_returns_every_saved_tag(): void
    {
        $repository = app(TagRepository::class);

        $repository->save(new Tag(id: null, name: 'Summer', slug: 'summer'));
        $repository->save(new Tag(id: null, name: 'Winter', slug: 'winter'));

        $all = $repository->all();
        $names = array_map(fn (Tag $tag) => $tag->name(), $all);
        sort($names);

        $this->assertCount(2, $all);
        $this->assertSame(['Summer', 'Winter'], $names);
    }

    public function test_saving_a_second_tag_with_a_colliding_slug_throws_a_raw_query_exception(): void
    {
        $repository = app(TagRepository::class);

        $repository->save(new Tag(id: null, name: 'Summer', slug: 'colliding-slug'));

        $this->expectException(QueryException::class);

        $repository->save(new Tag(id: null, name: 'Not Summer', slug: 'colliding-slug'));
    }
}
