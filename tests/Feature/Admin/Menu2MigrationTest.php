<?php

namespace Tests\Feature\Admin;

use App\Content\Menus\Menu2Migrator;
use App\Content\Menus\Menu3Migrator;
use App\Content\Menus\MenuLocations;
use App\Content\Menus\MenuModel;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Header\HeaderLayout;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use RuntimeException;
use Tests\Concerns\RestoresSchemaWithoutRemigrating;
use Tests\Feature\Storefront\BuildsMenus;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * MENU-2, the data step (menu-editor-v2-design.md 3): a D3 menu becomes the structure model without losing a link.
 *
 * The D3 columns exist only in a database that has not run the last two MENU-2 migrations, so the main test puts the D3 shape back (columns, the wide type CHECK), plants an
 * owner-like menu through DB::table() (the rows no PHP code can write any more), runs the migrator and the column-dropping migration, and checks what the shop prints. That
 * is real DDL: the class wears RestoresSchemaWithoutRemigrating like every DDL test of this project (a database that does not match afterwards makes the next test re-migrate).
 */
class Menu2MigrationTest extends TestCase
{
    use BuildsMenus;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;
    use RestoresSchemaWithoutRemigrating;

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureDatabaseFingerprint();
        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        app()->setLocale('en');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        $this->restoreFinalShape();
        $this->restoreFingerprintedState();
        $this->databaseMatchesFingerprint();

        parent::tearDown();

        $this->skipRemigrationIfClean();
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function migration(string $file): object
    {
        return require database_path("migrations/{$file}.php");
    }

    /** Back to the D3 shape: the legacy columns and the type CHECK that still allows `column` and `image`. */
    private function legacyShape(): void
    {
        Schema::table('menu_items', function (Blueprint $table): void {
            $table->boolean('with_children')->default(false);
            $table->unsignedSmallInteger('children_limit')->default(12);
            $table->unsignedBigInteger('media_asset_id')->nullable();
            $table->string('image_alt', 200)->nullable();
            $table->string('caption', 80)->nullable();
        });
        DB::statement('ALTER TABLE menu_items DROP CHECK menu_items_type_check');
        DB::statement("ALTER TABLE menu_items ADD CONSTRAINT menu_items_type_check CHECK (type IN ('link','category','page','product','heading','column','image'))");
        // The DDL committed the test's transaction; the migrator opens its own (a savepoint to Laravel), which needs a real one to sit in.
        DB::unprepared('START TRANSACTION');
    }

    /** Whatever the test left: the legacy columns go, the CHECK is the final one. */
    private function restoreFinalShape(): void
    {
        if (Schema::hasColumn('menu_items', 'with_children')) {
            DB::table('menu_items')->whereIn('type', ['heading', 'column', 'image'])->delete();
            DB::table('menu_items')->update(['with_children' => 0]);
            $this->migration('2026_10_23_000003_drop_legacy_menu_item_columns')->up();
        }

        $check = DB::selectOne("SELECT check_clause c FROM information_schema.check_constraints WHERE constraint_schema = DATABASE() AND constraint_name = 'menu_items_type_check'");

        if ($check !== null && (str_contains((string) $check->c, 'image') || str_contains((string) $check->c, 'heading'))) {
            DB::table('menu_items')->whereIn('type', ['heading', 'image'])->delete();
            DB::statement('ALTER TABLE menu_items DROP CHECK menu_items_type_check');
            DB::statement("ALTER TABLE menu_items ADD CONSTRAINT menu_items_type_check CHECK (type IN ('link','category','page','product','column'))");
        }
    }

    /** @param  array<string, mixed>  $more */
    private function legacyItem(int $menu, ?int $parent, int $sort, string $type, array $more = []): int
    {
        return (int) DB::table('menu_items')->insertGetId($more + [
            'menu_id' => $menu, 'parent_id' => $parent, 'sort' => $sort, 'type' => $type, 'label' => null, 'url' => null, 'target_id' => null, 'submenu' => 'none',
            'with_children' => 0, 'children_limit' => 12, 'media_asset_id' => null, 'image_alt' => null, 'caption' => null, 'new_tab' => 0, 'visible' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> everything the migration may touch, to compare before and after */
    private function snapshot(): array
    {
        return [
            'items' => DB::table('menu_items')->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all(),
            'menus' => DB::table('menus')->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all(),
            'blocks' => DB::table('html_blocks')->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all(),
            'settings' => DB::table('site_settings')->orderBy('key')->get()->map(fn (object $r): array => (array) $r)->all(),
        ];
    }

    // ---- columns, pictures and sub-categories ------------------------------------------------------------------------------------------------

    public function test_a_d3_menu_keeps_every_link_and_the_column_pictures_become_one_html_block(): void
    {
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->makeCategory(5, 'Tops', 'tops', 1);
        $this->makeCategory(6, 'Hidden sub', 'hidden-sub', 1);
        $this->makeCategory(3, 'Shoes', 'shoes');
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [2], 'price' => '10.00', 'stock' => 3]);
        $this->makeProduct(2, ['slug' => 'top', 'categories' => [5], 'price' => '10.00', 'stock' => 3]);
        $this->makeProduct(3, ['slug' => 'boot', 'categories' => [3], 'price' => '10.00', 'stock' => 3]);
        $this->makeMenuImage(940);
        $this->makeMenuImage(941, 'processing');
        $terms = app(\App\Content\PageWriter::class)->create(['title' => 'Terms', 'slug' => 'terms', 'body' => '<p>x</p>', 'status' => 'published'])->id;
        $this->legacyShape();

        $main = (int) MenuModel::query()->where('key', 'main')->value('id');
        DB::table('menu_items')->where('menu_id', $main)->delete();
        // "Girls": a mega item with two columns and two pictures (the second is still processing: the old compile dropped it, so does the move)
        $girls = $this->legacyItem($main, null, 0, 'link', ['label' => 'Girls', 'url' => '/girls', 'submenu' => 'mega']);
        $c1 = $this->legacyItem($main, $girls, 0, 'column', ['label' => 'Clothes']);
        $this->legacyItem($main, $c1, 0, 'link', ['label' => 'Dresses sale', 'url' => '/dresses-sale']);
        $this->legacyItem($main, $c1, 1, 'category', ['target_id' => 2]);
        $this->legacyItem($main, $c1, 2, 'page', ['target_id' => $terms]);
        $c2 = $this->legacyItem($main, $girls, 1, 'column', ['label' => null]);
        $this->legacyItem($main, $c2, 0, 'category', ['target_id' => 3, 'with_children' => 1]); // depth 3: a sub-category list would not fit the new depth
        $this->legacyItem($main, $c2, 1, 'image', ['media_asset_id' => 940, 'url' => '/promo', 'caption' => 'Promo <b>', 'image_alt' => 'A boot']);
        $this->legacyItem($main, $c2, 2, 'image', ['media_asset_id' => 941]);
        // "Clothing": a dropdown that listed its sub-categories by itself, cut at one
        $this->legacyItem($main, null, 1, 'category', ['target_id' => 1, 'submenu' => 'dropdown', 'with_children' => 1, 'children_limit' => 1]);
        $this->legacyItem($main, null, 2, 'link', ['label' => 'About', 'url' => '/about']);

        // the data step refuses to be skipped: the column-dropping migration will not run over D3 rows
        try {
            $this->migration('2026_10_23_000003_drop_legacy_menu_item_columns')->up();
            $this->fail('the columns must not be dropped before the data step');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('run the previous migration first', $exception->getMessage());
        }

        $counts = Menu2Migrator::run();
        Menu3Migrator::run(); // the D3 columns became headings (MENU-2); MENU-3 turns every mega item's first level into columns
        DB::unprepared('START TRANSACTION'); // the type CHECK of MENU-3 is DDL: it committed; the next run needs a real transaction to put its savepoint in

        $this->assertSame(['columns' => 2, 'images' => 2, 'blocks' => 1, 'expanded' => 1, 'items' => 2, 'skipped' => 1], array_intersect_key($counts, array_flip(['columns', 'images', 'blocks', 'expanded', 'items', 'skipped'])));

        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::MENUS_COMPILED_KEY), true)['menus']['main'];

        $this->assertSame(['Girls', 'Clothing', 'About'], array_column($compiled, 'label'));
        $this->assertSame('mega', $compiled[0]['submenu']);
        $this->assertSame(['column', 'column'], array_column($compiled[0]['children'], 'type'), 'the D3 columns are columns (MENU-3)');
        $this->assertSame('Clothes', $compiled[0]['children'][0]['label']);
        $this->assertSame(['Dresses sale', 'Dresses', 'Terms'], array_column($compiled[0]['children'][0]['children'], 'label'));
        $this->assertSame(['/dresses-sale', '/product-category/clothing/dresses', '/info/terms'], array_column($compiled[0]['children'][0]['children'], 'url'));
        $this->assertNull($compiled[0]['children'][1]['label'], 'a column with no heading has none (since MENU-3 a heading is optional)');
        $this->assertSame(['Shoes'], array_column($compiled[0]['children'][1]['children'], 'label'));
        $this->assertStringContainsString('/storage/m/940-medium.webp', $compiled[0]['html']);
        $this->assertStringContainsString('<a href="/promo" rel="noopener nofollow">', $compiled[0]['html']);
        $this->assertStringContainsString('<figcaption>Promo &lt;b&gt;</figcaption>', $compiled[0]['html'], 'the caption stays text');
        $this->assertSame(1, substr_count($compiled[0]['html'], '<figure>'), 'the processing picture was dropped, as the old compile did');
        $this->assertSame('dropdown', $compiled[1]['submenu']);
        $this->assertSame(['Dresses', __('storefront.see_all')], array_column($compiled[1]['children'], 'label'), 'the sub-categories of that moment, cut at the old limit, then "see all"');
        $this->assertSame(['/product-category/clothing/dresses', '/product-category/clothing'], array_column($compiled[1]['children'], 'url'));

        $block = DB::table('html_blocks')->first();
        $this->assertSame('Снимка: Girls', $block->title);
        $this->assertSame((int) $block->id, (int) DB::table('menu_items')->where('label', 'Girls')->value('html_block_id'));
        $this->assertSame(0, DB::table('menu_items')->whereIn('type', ['heading', 'image'])->count());
        $this->assertSame(2, (int) DB::table('menu_items')->where('label', 'Girls')->value('mega_columns'));
        $this->assertSame(0, DB::table('menu_items')->where('with_children', 1)->count());

        // idempotent: a second run changes nothing at all
        $before = $this->snapshot();
        $again = Menu2Migrator::run();
        $this->assertSame(['columns' => 0, 'images' => 0, 'blocks' => 0, 'expanded' => 0, 'items' => 0, 'skipped' => 0, 'locations' => 0, 'layouts' => 0], $again);
        $this->assertSame(0, array_sum(Menu3Migrator::run()), 'and neither does the MENU-3 step');
        $this->assertSame($before, $this->snapshot());

        // and the last migration may now drop the D3 columns, once, and again without harm
        $this->migration('2026_10_23_000003_drop_legacy_menu_item_columns')->up();
        $this->migration('2026_10_23_000003_drop_legacy_menu_item_columns')->up();
        foreach (['with_children', 'children_limit', 'media_asset_id', 'image_alt', 'caption'] as $column) {
            $this->assertFalse(Schema::hasColumn('menu_items', $column), $column);
        }

        // the type CHECK is the final one: a `heading` cannot be written any more
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('menu_items')->insert(['menu_id' => $main, 'sort' => 9, 'type' => 'heading', 'submenu' => 'none', 'new_tab' => 0, 'visible' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---- locations and layouts ------------------------------------------------------------------------------------------------------------------

    public function test_locations_are_seeded_from_what_the_shop_showed_and_the_layouts_lose_their_source(): void
    {
        $main = (int) MenuModel::query()->where('key', 'main')->value('id');
        $footer = (int) MenuModel::query()->where('key', 'footer')->value('id');
        $this->settings()->forget(AppearanceSettings::MENUS_LOCATIONS_KEY);

        // a header as D2/D3 stored it: the bar showed `main`, the drawer showed the `footer` menu
        $layout = HeaderLayout::default();
        $layout['desktop']['rows']['main']['left'] = [['type' => 'menu', 'settings' => ['source' => 'main', 'style' => 'links']]];
        $layout['mobile']['drawer'] = [['type' => 'menu', 'settings' => ['source' => 'footer', 'style' => 'links']]];
        $this->settings()->set(AppearanceSettings::HEADER_DRAFT_KEY, json_encode($layout));
        $this->settings()->set(AppearanceSettings::HEADER_PUBLISHED_KEY, json_encode($layout));
        $this->settings()->set(AppearanceSettings::HEADER_COMPILED_KEY, json_encode(['layout' => $layout, 'css' => []]));

        $counts = Menu2Migrator::run();

        $this->assertSame(['header' => $main, 'drawer' => $footer, 'footer' => $footer], app(MenuLocations::class)->all());
        $this->assertSame(3, $counts['locations']);
        $this->assertSame(2, $counts['layouts'], 'the draft and the published layout');

        foreach ([AppearanceSettings::HEADER_DRAFT_KEY, AppearanceSettings::HEADER_PUBLISHED_KEY, AppearanceSettings::HEADER_COMPILED_KEY] as $key) {
            $stored = (string) $this->settings()->get($key);
            $this->assertStringNotContainsString('"source"', $stored, $key.': no source in the stored layout');
            $this->assertStringContainsString('"menu"', $stored);
        }

        $before = $this->snapshot();
        $this->assertSame(0, Menu2Migrator::run()['layouts'], 'a second run strips nothing more');
        $this->assertSame($before, $this->snapshot(), 'and changes nothing');
    }

    public function test_a_header_without_a_menu_element_still_gets_the_main_menu_in_its_location(): void
    {
        $this->settings()->forget(AppearanceSettings::MENUS_LOCATIONS_KEY);
        $this->settings()->forget(AppearanceSettings::HEADER_PUBLISHED_KEY);
        $this->settings()->forget(AppearanceSettings::HEADER_COMPILED_KEY);

        Menu2Migrator::run();

        $locations = app(MenuLocations::class)->all();
        $this->assertSame((int) MenuModel::query()->where('key', 'main')->value('id'), $locations['header']);
        $this->assertNull($locations['drawer'], 'empty drawer location = the header menu');
    }

    public function test_a_database_that_is_already_converted_is_left_alone(): void
    {
        $before = $this->snapshot();

        $counts = Menu2Migrator::run();

        $this->assertSame(0, array_sum($counts), 'nothing to convert, nothing to seed, nothing to strip: '.json_encode($counts));
        $this->assertSame($before, $this->snapshot());
    }
}
