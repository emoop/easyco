<?php

namespace Tests\Feature;

use App\Content\Models\PageModel;
use App\Settings\Contracts\SiteSettingsRepository;
use Database\Seeders\ContentDraftsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pages, stage P1 (pages-design.md section 8): two EMPTY drafts, created once, never published, never in the footer, no legal text. */
class ContentDraftsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_two_empty_drafts_not_in_the_footer(): void
    {
        $this->seed(ContentDraftsSeeder::class);

        $this->assertSame(2, PageModel::query()->count());

        foreach (['obshti-usloviya', 'politika-za-poveritelnost'] as $slug) {
            $page = PageModel::query()->where('slug', $slug)->firstOrFail();
            $this->assertSame('', (string) $page->body, 'no legal text ships');
            $this->assertSame('draft', $page->status);
            $this->assertFalse((bool) $page->in_footer);
            $this->assertNull($page->published_at);
            $this->assertNotSame('', $page->title);
            $this->get('/info/'.$slug)->assertNotFound();
        }
    }

    public function test_it_creates_them_once_even_when_the_merchant_changed_or_archived_them(): void
    {
        $this->seed(ContentDraftsSeeder::class);
        PageModel::query()->where('slug', 'obshti-usloviya')->update(['slug' => 'moi-uslovia']);

        $this->seed(ContentDraftsSeeder::class);

        $this->assertSame(2, PageModel::query()->count(), 'the renamed draft is not created again');
        $this->assertSame('1', app(SiteSettingsRepository::class)->get(ContentDraftsSeeder::MARKER_KEY));
    }

    public function test_an_existing_slug_is_left_alone(): void
    {
        PageModel::query()->create(['slug' => 'obshti-usloviya', 'title' => 'Mine', 'body' => '<p>Mine</p>', 'status' => 'published', 'revision' => 1]);

        $this->seed(ContentDraftsSeeder::class);

        $mine = PageModel::query()->where('slug', 'obshti-usloviya')->firstOrFail();
        $this->assertSame('Mine', $mine->title);
        $this->assertSame('published', $mine->status);
        $this->assertSame(2, PageModel::query()->count());
    }

    public function test_the_database_seeder_runs_it(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->assertTrue(PageModel::query()->where('slug', 'politika-za-poveritelnost')->exists());
    }
}
