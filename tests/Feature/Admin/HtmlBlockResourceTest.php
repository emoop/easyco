<?php

namespace Tests\Feature\Admin;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockUsage;
use App\Content\Menus\MenuModel;
use App\Filament\NavigationGroup;
use App\Filament\Resources\HtmlBlockResource;
use App\Filament\Resources\HtmlBlockResource\Pages\CreateHtmlBlock;
use App\Filament\Resources\HtmlBlockResource\Pages\EditHtmlBlock;
use App\Filament\Resources\HtmlBlockResource\Pages\ListHtmlBlocks;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Feature\Storefront\BuildsMenus;
use Tests\TestCase;

/**
 * MENU-2: the admin «HTML блокове». Who may open it (`content_manage`), a block is saved cleaned, a block a menu shows cannot be deleted (the message names the menu),
 * the form hands its validation to the server.
 */
class HtmlBlockResourceTest extends TestCase
{
    use BuildsMenus;
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        app()->setLocale('en');
    }

    public function test_the_content_roles_reach_the_tool_and_a_role_without_the_permission_does_not(): void
    {
        $block = HtmlBlockModel::create(['title' => 'B', 'body' => '<p>x</p>']);

        foreach (['Administrator', 'Manager'] as $role) {
            $this->actingAsStaff($role);

            $this->get(HtmlBlockResource::getUrl('index'))->assertOk();
            $this->get(HtmlBlockResource::getUrl('create'))->assertOk();
            $this->get(HtmlBlockResource::getUrl('edit', ['record' => $block]))->assertOk();
        }

        foreach ([fn () => $this->actingAsStaff('Product Entry'), fn () => $this->actingAsCustomRole([Permission::ORDER_VIEW])] as $actAs) {
            $actAs();

            $this->get(HtmlBlockResource::getUrl('index'))->assertForbidden();
            $this->get(HtmlBlockResource::getUrl('create'))->assertForbidden();
            $this->assertFalse(HtmlBlockResource::canAccess());
            Livewire::test(ListHtmlBlocks::class)->assertForbidden();
        }
    }

    public function test_it_sits_in_the_content_group_beside_the_menus_and_is_named_in_both_languages(): void
    {
        $this->assertSame(NavigationGroup::CONTENT, HtmlBlockResource::getNavigationGroup());
        $this->assertSame('HTML blocks', HtmlBlockResource::getNavigationLabel());
        app()->setLocale('bg');
        $this->assertSame('HTML блокове', HtmlBlockResource::getNavigationLabel());
    }

    public function test_a_block_is_created_edited_and_saved_clean(): void
    {
        $this->actingAsStaff('Administrator');

        Livewire::test(CreateHtmlBlock::class)
            ->fillForm(['title' => 'Boys picture', 'body' => '<p onclick="x()">Hello</p><script>alert(1)</script>'])
            ->call('create')
            ->assertHasNoFormErrors();

        $block = HtmlBlockModel::query()->firstOrFail();
        $this->assertSame('Boys picture', $block->title);
        $this->assertStringNotContainsString('script', $block->body);
        $this->assertStringNotContainsString('onclick', $block->body);
        $this->assertStringContainsString('Hello', $block->body);

        Livewire::test(EditHtmlBlock::class, ['record' => $block->id])->fillForm(['title' => 'Renamed'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Renamed', $block->fresh()->title);
    }

    public function test_the_form_asks_the_server_not_the_browser(): void
    {
        $this->actingAsStaff('Administrator');

        $html = $this->get(HtmlBlockResource::getUrl('create'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<form\b[^>]*\bnovalidate\b/', $html);

        Livewire::test(CreateHtmlBlock::class)->fillForm(['title' => '', 'body' => ''])->call('create')->assertHasFormErrors(['title' => 'required', 'body']);
        Livewire::test(CreateHtmlBlock::class)->fillForm(['title' => str_repeat('t', 121), 'body' => '<p>x</p>'])->call('create')->assertHasFormErrors(['title' => 'max']);
        $this->assertSame(0, HtmlBlockModel::query()->count());
    }

    public function test_a_block_a_menu_shows_cannot_be_deleted_and_the_message_names_the_menu(): void
    {
        $this->actingAsStaff('Administrator');
        $block = HtmlBlockModel::create(['title' => 'Boys picture', 'body' => '<p>x</p>']);
        $this->saveMenu('main', [$this->menuItem('link', ['label' => 'Boys', 'url' => '/boys', 'submenu' => 'mega', 'html_block_id' => $block->id, 'children' => [
            $this->menuItem('heading', ['label' => 'Clothes', 'children' => [$this->menuItem('link', ['label' => 'Jackets', 'url' => '/j'])]]),
        ]])]);

        $this->assertSame([MenuModel::query()->where('key', 'main')->value('name')], HtmlBlockUsage::menus($block->id));

        Livewire::test(ListHtmlBlocks::class)->callTableAction('delete', $block);
        $this->assertNotNull($block->fresh(), 'the list action is refused');

        Livewire::test(EditHtmlBlock::class, ['record' => $block->id])->callAction('delete');
        $this->assertNotNull($block->fresh(), 'the edit page action is refused');

        // the model itself is the last line of defence
        $this->assertFalse($block->delete());
        $this->assertNotNull(HtmlBlockModel::query()->find($block->id));

        // once no menu names it, it goes
        $this->saveMenu('main', [$this->menuItem('link', ['label' => 'Boys', 'url' => '/boys'])]);
        $this->assertSame([], HtmlBlockUsage::menus($block->id));
        Livewire::test(ListHtmlBlocks::class)->callTableAction('delete', $block);
        $this->assertNull(HtmlBlockModel::query()->find($block->id));
    }

    public function test_the_editor_offers_exactly_what_the_filter_keeps(): void
    {
        $this->actingAsStaff('Administrator');
        $html = $this->get(HtmlBlockResource::getUrl('create'))->assertOk()->getContent();

        $this->assertStringContainsString('html-blocks', $html, 'pictures are attached into the html-blocks folder of the public disk');
        $this->assertStringNotContainsString('data-tool="table"', $html);
    }
}
