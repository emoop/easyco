<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * Stage D2: the header script on the REAL rendered home page, driven in the fake DOM (tests/Feature/Storefront/Js/header-flow.mjs): the drawer (focus moves in, is
 * trapped, Esc / overlay / close button close it and focus returns to the toggle, aria-expanded, scroll lock), the category accordion and the search row (rendered
 * here because a `storefront.search` route is registered, as it will be when search is built). No browser, no new dependency; needs `node` (skipped without).
 */
class StorefrontHeaderFlowTest extends TestCase
{
    use BuildsMenus;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.name' => 'Raf Shop']);
        $this->seedPricing();
        $parent = $this->makeCategory(1, 'Clothing', 'clothing');
        $child = $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [$parent, $child], 'price' => '10.00', 'stock' => 3, 'name' => 'Summer dress']);
        // The menu of the shop: a plain link, a dropdown (Clothing, with its sub-categories), a mega panel with an HTML block holding a picture (Footwear) and one more link.
        $block = \App\Content\HtmlBlocks\HtmlBlockModel::create(['title' => 'Boots', 'body' => '<figure><img src="/storage/html-blocks/boot.jpg" alt="A boot" width="900" height="675"></figure>']);
        $this->saveMenu('main', [
            $this->menuItem('link', ['label' => 'Home', 'url' => '/']),
            $this->menuItem('category', ['target_id' => $parent, 'submenu' => 'dropdown', 'children' => [$this->menuItem('category', ['target_id' => $child])]]),
            $this->menuItem('link', ['label' => 'Footwear', 'url' => '/footwear', 'submenu' => 'mega', 'html_block_id' => $block->id, 'mega_columns' => 1, 'children' => [
                $this->columnItem([$this->menuItem('link', ['label' => 'Winter', 'url' => '/winter'])], 'Boots'),
            ]]),
            $this->menuItem('link', ['label' => 'Contacts', 'url' => '/contacts']),
        ]);
        Route::get('/search', fn () => 'search')->name('storefront.search');
        app('router')->getRoutes()->refreshNameLookups();
    }

    /** @return array<string, array{0: string}> */
    public static function scenarios(): array
    {
        $names = [
            'the_drawer_starts_closed', 'opening_moves_focus_in_locks_scroll_and_sets_aria', 'the_focus_is_trapped_while_open', 'escape_closes_and_returns_focus_to_the_toggle',
            'the_overlay_the_close_button_and_the_toggle_close_it', 'the_category_accordion_expands_and_collapses', 'the_drawer_chevron_toggles_only_its_own_list_and_the_link_is_not_intercepted', 'the_search_row_opens_focuses_its_field_and_escape_returns_focus',
            'escape_with_nothing_open_is_harmless', 'the_chevron_toggles_one_panel_at_a_time', 'escape_closes_the_panel_and_returns_focus_to_the_chevron',
            'hover_opens_after_150ms_and_closes_300ms_after_leaving', 'the_picture_of_a_mega_panel_is_a_native_lazy_image', 'items_that_do_not_fit_move_into_more_and_come_back',
        ];

        return array_combine($names, array_map(fn (string $name): array => [$name], $names));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scenarios')]
    public function test_the_script_flow(string $scenario): void
    {
        $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node'));

        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }

        $file = tempnam(sys_get_temp_dir(), 'sfhdr').'.html';
        file_put_contents($file, $this->get('/')->assertOk()->getContent());

        try {
            $output = [];
            exec('node '.escapeshellarg(__DIR__.'/Js/header-flow.mjs').' '.escapeshellarg($file).' '.escapeshellarg($scenario).' 2>&1', $output, $code);
        } finally {
            @unlink($file);
        }

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertSame("OK {$scenario}", trim(implode("\n", $output)));
    }
}
