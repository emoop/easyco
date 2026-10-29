<?php

namespace Tests\Feature\Storefront;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Storefront\Menu\MenuRecompiler;
use App\Storefront\ProductSummary\ProductSummaryCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * SC-1.1: editing an HTML block that the right column shows refreshes the column's compiled copy through the SAME rebuild that refreshes the footer, so the product page prints the new HTML
 * without any action on the Design screen. Two paths of the rebuild are covered: the menus unchanged (the first branch of MenuCompiler::compileAndStore) and the menus changed (the second).
 */
class ProductSummaryBlockEditTest extends TestCase
{
    use BuildsMenus;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeProduct(1, ['name' => 'Summer dress', 'slug' => 'summer-dress', 'price' => '100.00', 'stock' => 4]);
        $this->app->forgetScopedInstances();
    }

    /** A published right column of title, the block and the purchase box. */
    private function publishWithBlock(int $blockId): void
    {
        app(ProductSummaryCompiler::class)->publish(['components' => [
            ['type' => 'title', 'settings' => []],
            ['type' => 'html_block', 'settings' => ['html_block' => $blockId]],
            ['type' => 'purchase', 'settings' => []],
        ]], app(\App\Settings\Contracts\SiteSettingsRepository::class));
        $this->app->forgetScopedInstances();
    }

    private function page(): string
    {
        $this->app->forgetScopedInstances();

        return $this->get('/product/summer-dress')->assertOk()->getContent();
    }

    public function test_an_edited_block_the_right_column_shows_is_printed_with_its_new_html_at_once(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Club', 'body' => '<p>Old club text</p>']);
        $this->publishWithBlock($block->id);
        $this->assertStringContainsString('Old club text', $this->page());

        $block->update(['body' => '<p>New club text</p>']);

        $html = $this->page();
        $this->assertStringContainsString('New club text', $html);
        $this->assertStringNotContainsString('Old club text', $html);
    }

    public function test_a_block_edit_asks_for_the_rebuild_only_while_a_published_right_column_holds_a_block(): void
    {
        $recompiler = app(MenuRecompiler::class);
        $block = HtmlBlockModel::create(['title' => 'Club', 'body' => '<p>a</p>']);

        // nothing shows the block yet: the edit asks for nothing
        $block->update(['body' => '<p>a2</p>']);
        $this->assertFalse($recompiler->hasPending(), 'no menu, footer or right column holds a block: no rebuild');

        // the published right column holds it: the edit asks for the rebuild (the same rule as the footer's: any block of the layout)
        $this->publishWithBlock($block->id);
        $block->update(['body' => '<p>a3</p>']);
        $this->assertTrue($recompiler->hasPending(), 'a block the published right column shows asks for the rebuild');
    }

    public function test_a_menu_change_in_the_same_rebuild_also_refreshes_the_right_column(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Club', 'body' => '<p>Before</p>']);
        $this->publishWithBlock($block->id);

        $block->update(['body' => '<p>After</p>']);

        // a menu with a link CHANGES the compiled menus (the second branch of compileAndStore runs; an empty menu would take the first one)
        $this->saveMenu('main', [$this->menuItem('link', ['label' => 'Boys', 'url' => '/boys'])]);
        $this->assertStringContainsString('After', $this->page());
    }
}
