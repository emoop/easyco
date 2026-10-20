<?php

namespace Tests\Feature\Admin;

use App\Content\Menus\Menu3Migrator;
use App\Content\Menus\MenuModel;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\RestoresSchemaWithoutRemigrating;
use Tests\Feature\Storefront\BuildsMenus;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * MENU-3, the data step: a mega item's first level becomes COLUMNS, so one model serves the 3-level menu (a heading on every column) and the 2-level one (no heading) and every link
 * that showed before still shows, in the same order. The `heading` type exists only in a database that has not run the last MENU-3 migration, so the tests put the wide type CHECK back
 * (real DDL: the class wears RestoresSchemaWithoutRemigrating like every DDL test of this project), plant MENU-2 shaped rows through DB::table(), run the migrator and read what the
 * shop would print.
 */
class Menu3MigrationTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        $this->restoreFinalShape();
        $this->databaseMatchesFingerprint();

        parent::tearDown();

        $this->skipRemigrationIfClean();
    }

    /** The MENU-2 shape: `heading` is a valid type again. */
    private function menu2Shape(): void
    {
        DB::statement('ALTER TABLE menu_items DROP CHECK menu_items_type_check');
        DB::statement("ALTER TABLE menu_items ADD CONSTRAINT menu_items_type_check CHECK (type IN ('link','category','page','product','heading','column'))");
        // The DDL committed the test's transaction; the migrator opens its own (a savepoint to Laravel), which needs a real one to sit in.
        DB::unprepared('START TRANSACTION');
    }

    private function restoreFinalShape(): void
    {
        $check = DB::selectOne("SELECT check_clause c FROM information_schema.check_constraints WHERE constraint_schema = DATABASE() AND constraint_name = 'menu_items_type_check'");

        if ($check !== null && str_contains((string) $check->c, 'heading')) {
            DB::table('menu_items')->where('type', 'heading')->delete();
            DB::statement('ALTER TABLE menu_items DROP CHECK menu_items_type_check');
            DB::statement("ALTER TABLE menu_items ADD CONSTRAINT menu_items_type_check CHECK (type IN ('link','category','page','product','column'))");
        }
    }

    /** @param  array<string, mixed>  $more */
    private function row(int $menu, ?int $parent, int $sort, string $type, array $more = []): int
    {
        return (int) DB::table('menu_items')->insertGetId($more + [
            'menu_id' => $menu, 'parent_id' => $parent, 'sort' => $sort, 'type' => $type, 'label' => null, 'url' => null, 'target_id' => null, 'submenu' => 'none',
            'html_block_id' => null, 'mega_columns' => null, 'badge_text' => null, 'badge_color' => null, 'new_tab' => 0, 'visible' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function link(int $menu, int $parent, int $sort, string $label): int
    {
        return $this->row($menu, $parent, $sort, 'link', ['label' => $label, 'url' => '/'.strtolower($label)]);
    }

    /** @return array<string, mixed> everything the migration may touch, to compare before and after */
    private function snapshot(): array
    {
        return [
            'items' => DB::table('menu_items')->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all(),
            'settings' => DB::table('site_settings')->orderBy('key')->get()->map(fn (object $r): array => (array) $r)->all(),
        ];
    }

    /** @return list<string> the labels of what a mega item shows, column by column ("|" between columns), in order */
    private function megaLinks(string $label): array
    {
        $menus = json_decode((string) app(SiteSettingsRepository::class)->get(AppearanceSettings::MENUS_COMPILED_KEY), true)['menus'];

        foreach ($menus as $items) {
            foreach ($items as $item) {
                if ($item['label'] === $label) {
                    return array_map(fn (array $column): string => ($column['label'] ?? '-').':'.implode(',', array_column($column['children'], 'label')), $item['children']);
                }
            }
        }

        return [];
    }

    private function catalog(): void
    {
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeCategory(2, 'Shoes', 'shoes');
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [1], 'price' => '10.00', 'stock' => 3]);
        $this->makeProduct(2, ['slug' => 'boot', 'categories' => [2], 'price' => '10.00', 'stock' => 3]);
    }

    public function test_headings_become_columns_and_a_level_one_item_becomes_a_column_with_that_item_as_its_first_link(): void
    {
        $this->catalog();
        $this->menu2Shape();
        $main = (int) MenuModel::query()->where('key', 'main')->value('id');
        DB::table('menu_items')->where('menu_id', $main)->delete();
        // "Boys" (children's shop, MENU-2): two headed columns, then a category and a labelled link used as column headings (the "link as heading" of MENU-2)
        $boys = $this->row($main, null, 0, 'link', ['label' => 'Boys', 'url' => '/boys', 'submenu' => 'mega']);
        $h1 = $this->row($main, $boys, 0, 'heading', ['label' => 'Clothes']);
        $this->link($main, $h1, 0, 'Jackets');
        $this->link($main, $h1, 1, 'Trousers');
        $this->row($main, $boys, 1, 'heading', ['label' => 'Empty heading']);
        $cat = $this->row($main, $boys, 2, 'category', ['target_id' => 2]);
        $this->link($main, $cat, 0, 'Winter');
        $this->link($main, $cat, 1, 'Summer');
        $own = $this->row($main, $boys, 3, 'link', ['label' => 'Brands', 'url' => '/brands']);
        $this->link($main, $own, 0, 'Nike');
        // a plain dropdown and a plain link stay as they are
        $drop = $this->row($main, null, 1, 'link', ['label' => 'Drop', 'url' => '/drop', 'submenu' => 'dropdown']);
        $this->link($main, $drop, 0, 'D1');
        $this->row($main, null, 2, 'link', ['label' => 'Plain', 'url' => '/plain']);

        $counts = Menu3Migrator::run();

        $this->assertSame(['megas' => 1, 'headings' => 2, 'promoted' => 2, 'merged_columns' => 0, 'merged_links' => 0, 'empty' => 0, 'strays' => 0], $counts);
        $this->assertSame(4, (int) DB::table('menu_items')->where('id', $boys)->value('mega_columns'));
        $this->assertSame(
            ['Clothes:Jackets,Trousers', 'Empty heading:', 'Shoes:Shoes,Winter,Summer', 'Brands:Brands,Nike'],
            $this->megaLinks('Boys'),
            'a heading: same label, same links; a category / link at level 1: its name as the heading, ITSELF as the first link, then its former children; an empty column keeps its place',
        );
        $this->assertSame(0, DB::table('menu_items')->where('type', 'heading')->count());
        $this->assertSame(['link', 'link', 'link'], DB::table('menu_items')->whereNull('parent_id')->orderBy('sort')->pluck('type')->all());
        $this->assertNull(DB::table('menu_items')->where('label', 'Plain')->value('mega_columns'));
        $this->assertNull(DB::table('menu_items')->where('label', 'Drop')->value('mega_columns'));

        // idempotent: a second run changes nothing at all
        $before = $this->snapshot();
        $this->assertSame(0, array_sum(Menu3Migrator::run()));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_more_than_four_columns_are_merged_into_the_fourth_and_no_link_is_lost(): void
    {
        $this->catalog();
        $this->menu2Shape();
        $main = (int) MenuModel::query()->where('key', 'main')->value('id');
        DB::table('menu_items')->where('menu_id', $main)->delete();
        $mega = $this->row($main, null, 0, 'link', ['label' => 'Big', 'url' => '/big', 'submenu' => 'mega']);

        foreach (range(1, 6) as $i) {
            $heading = $this->row($main, $mega, $i - 1, 'heading', ['label' => "H{$i}"]);
            $this->link($main, $heading, 0, "a{$i}");
            $this->link($main, $heading, 1, "b{$i}");
        }

        $counts = Menu3Migrator::run();

        $this->assertSame([6, 2, 4], [$counts['headings'], $counts['merged_columns'], $counts['merged_links']]);
        $this->assertSame(['H1:a1,b1', 'H2:a2,b2', 'H3:a3,b3', 'H4:a4,b4,a5,b5,a6,b6'], $this->megaLinks('Big'), 'columns 5 and 6 end up at the end of column 4, in order');
        $this->assertSame(4, (int) DB::table('menu_items')->where('id', $mega)->value('mega_columns'));
        $this->assertSame(4, DB::table('menu_items')->where('parent_id', $mega)->count());
    }

    public function test_a_mega_item_with_nothing_under_it_gets_three_empty_columns_and_a_stray_heading_is_dissolved(): void
    {
        $this->catalog();
        $this->menu2Shape();
        $main = (int) MenuModel::query()->where('key', 'main')->value('id');
        DB::table('menu_items')->where('menu_id', $main)->delete();
        $this->row($main, null, 0, 'link', ['label' => 'Empty mega', 'url' => '/e', 'submenu' => 'mega']);
        $drop = $this->row($main, null, 1, 'link', ['label' => 'Drop', 'url' => '/drop', 'submenu' => 'dropdown']);
        $stray = $this->row($main, $drop, 0, 'heading', ['label' => 'Stray']);
        $this->link($main, $stray, 0, 's1');
        $this->link($main, $drop, 1, 'after');

        $counts = Menu3Migrator::run();

        $this->assertSame([1, 1], [$counts['empty'], $counts['strays']]);
        $this->assertSame(3, (int) DB::table('menu_items')->where('label', 'Empty mega')->value('mega_columns'));
        $this->assertSame(3, DB::table('menu_items')->where('parent_id', DB::table('menu_items')->where('label', 'Empty mega')->value('id'))->where('type', 'column')->count());
        $this->assertSame(['s1', 'after'], DB::table('menu_items')->where('parent_id', $drop)->orderBy('sort')->pluck('label')->all(), 'a heading outside a mega item is dissolved: its links take its place, in order');
    }

    public function test_the_type_check_is_narrowed_and_the_mega_columns_check_holds(): void
    {
        $this->catalog();
        $this->menu2Shape();
        Menu3Migrator::run();
        $main = (int) MenuModel::query()->where('key', 'main')->value('id');

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('menu_items')->insert(['menu_id' => $main, 'sort' => 9, 'type' => 'heading', 'submenu' => 'none', 'new_tab' => 0, 'visible' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_database_refuses_a_column_count_outside_one_to_four(): void
    {
        $main = (int) MenuModel::query()->where('key', 'main')->value('id');

        foreach ([0, 5] as $bad) {
            try {
                DB::table('menu_items')->insert(['menu_id' => $main, 'sort' => 9, 'type' => 'link', 'label' => 'X', 'url' => '/x', 'submenu' => 'mega', 'mega_columns' => $bad, 'new_tab' => 0, 'visible' => 1, 'created_at' => now(), 'updated_at' => now()]);
                $this->fail('the CHECK must refuse '.$bad);
            } catch (\Illuminate\Database\QueryException $exception) {
                $this->assertSame(3819, $exception->errorInfo[1], 'MySQL: a CHECK constraint is violated');
            }
        }
    }
}
