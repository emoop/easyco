<?php

namespace Tests\Feature;

use App\Content\Models\PageModel;
use App\Content\PageWriter;
use App\Filament\Resources\PageResource;
use App\Filament\Resources\PageResource\Pages\CreatePage;
use App\Filament\Resources\PageResource\Pages\EditPage;
use App\Filament\Resources\PageResource\Pages\ListPages;
use App\Storefront\Pages\PageReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\RestoresSchemaWithoutRemigrating;
use Tests\Feature\Storefront\BuildsMenus;
use Tests\TestCase;

/**
 * DS-3.1: `pages.in_footer` and `pages.footer_order` (and the index `pages_status_footer_index` that held them) are gone. The migration is tested with real DDL (up, down, up again); the pages
 * admin works without the columns; a page that had `in_footer = 1` before the migration is still published and reachable; the page reader costs the same one query.
 */
class PageFooterColumnsMigrationTest extends TestCase
{
    use BuildsMenus;
    use BuildsShippingZones;
    use RefreshDatabase;
    use RestoresSchemaWithoutRemigrating;

    private const FILE = 'database/migrations/2026_10_27_000001_drop_in_footer_and_footer_order_from_pages.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureDatabaseFingerprint();
    }

    protected function tearDown(): void
    {
        Schema::hasColumn('pages', 'in_footer') && $this->migration()->up();

        $this->restoreFingerprintedState();
        $this->databaseMatchesFingerprint();

        parent::tearDown();

        $this->skipRemigrationIfClean();
    }

    private function migration(): object
    {
        return require base_path(self::FILE);
    }

    private function index(): int
    {
        return (int) DB::selectOne("select count(distinct index_name) c from information_schema.statistics where table_schema = database() and table_name = 'pages' and index_name = 'pages_status_footer_index'")->c;
    }

    public function test_the_columns_and_their_index_are_gone(): void
    {
        $this->assertFalse(Schema::hasColumn('pages', 'in_footer'));
        $this->assertFalse(Schema::hasColumn('pages', 'footer_order'));
        $this->assertSame(0, $this->index());
    }

    public function test_down_brings_back_the_original_definitions_and_up_drops_them_again(): void
    {
        $this->migration()->down();

        $in = collect(DB::select("show columns from pages where Field = 'in_footer'"))->first();
        $order = collect(DB::select("show columns from pages where Field = 'footer_order'"))->first();
        $this->assertSame(['tinyint(1)', 'NO', '0'], [$in->Type, $in->Null, $in->Default]);
        $this->assertMatchesRegularExpression('/^smallint(\(5\))? unsigned$/', $order->Type);
        $this->assertSame(['NO', '0'], [$order->Null, $order->Default]);
        $this->assertSame(1, $this->index());
        $this->assertSame(['status', 'in_footer', 'footer_order'], DB::table('information_schema.statistics')->where('table_schema', DB::getDatabaseName())->where('table_name', 'pages')->where('index_name', 'pages_status_footer_index')->orderBy('seq_in_index')->selectRaw('column_name as c')->pluck('c')->all());
        $this->assertSame(['noindex', 'in_footer', 'footer_order', 'published_at'], array_slice(array_column(DB::select('show columns from pages'), 'Field'), 8, 4), 'the original order of the columns');

        $this->migration()->up();

        $this->assertFalse(Schema::hasColumn('pages', 'in_footer'));
        $this->assertFalse(Schema::hasColumn('pages', 'footer_order'));
        $this->assertSame(0, $this->index());

        $this->migration()->up(); // idempotent
        $this->assertFalse(Schema::hasColumn('pages', 'footer_order'));
    }

    public function test_a_page_that_was_in_the_footer_before_the_migration_is_still_published_and_reachable(): void
    {
        $this->migration()->down();
        $id = DB::table('pages')->insertGetId([
            'slug' => 'terms', 'title' => 'Terms', 'body' => '<p>Rules.</p>', 'status' => 'published', 'revision' => 1, 'in_footer' => 1, 'footer_order' => 3,
            'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertSame('published', PageModel::query()->findOrFail($id)->status);
        $this->get('/info/terms')->assertOk()->assertSee('Rules.', false);
    }

    public function test_the_footer_menu_with_a_page_renders_as_before(): void
    {
        $page = app(PageWriter::class)->create(['title' => 'Terms', 'slug' => 'terms', 'body' => '<p>Rules.</p>', 'status' => 'published']);
        $this->saveMenu('footer', [$this->menuItem('page', ['target_id' => $page->id])]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('href="/info/terms"', substr($html, (int) strpos($html, '<footer')));
    }

    public function test_the_reader_lists_published_pages_by_title_in_one_query_with_no_footer_columns(): void
    {
        app(PageWriter::class)->create(['title' => 'Zeta', 'slug' => 'zeta', 'body' => '<p>z</p>', 'status' => 'published']);
        app(PageWriter::class)->create(['title' => 'Alpha', 'slug' => 'alpha', 'body' => '<p>a</p>', 'status' => 'published']);
        app(PageWriter::class)->create(['title' => 'Draft', 'slug' => 'draft', 'body' => '', 'status' => 'draft']);
        $reader = new PageReader;

        DB::flushQueryLog();
        DB::enableQueryLog();
        $pages = $reader->published();
        $reader->publishedOptions();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(['Alpha', 'Zeta'], array_column($pages, 'title'));
        $this->assertSame(['id', 'slug', 'title', 'revision'], array_keys((array) $pages[0]));
        $this->assertCount(1, $queries, 'one query per request, as before');
        $this->assertStringNotContainsString('footer', $queries[0]['query']);
    }

    public function test_pages_create_edit_and_list_work_without_the_columns(): void
    {
        $this->actingAsStaff('Administrator');

        Livewire::test(CreatePage::class)
            ->fillForm(['title' => 'Delivery', 'slug' => 'delivery', 'body' => '<p>Two days.</p>', 'status' => 'published'])
            ->call('create')->assertHasNoFormErrors();
        $page = PageModel::query()->where('slug', 'delivery')->firstOrFail();

        Livewire::test(EditPage::class, ['record' => $page->id])
            ->assertFormExists()->assertFormFieldDoesNotExist('in_footer')->assertFormFieldDoesNotExist('footer_order')
            ->fillForm(['title' => 'Delivery times'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Delivery times', $page->fresh()->title);

        Livewire::test(ListPages::class)->assertCanSeeTableRecords([$page])->assertTableColumnDoesNotExist('in_footer');
        $this->get(PageResource::getUrl('index'))->assertOk()->assertDontSee('Покажи във футъра')->assertDontSee('Show in the footer');
    }
}
