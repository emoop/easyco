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

    public function test_it_sits_in_the_design_group_after_the_menus_and_is_named_in_both_languages(): void
    {
        $this->assertSame(NavigationGroup::DESIGN, HtmlBlockResource::getNavigationGroup());
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
        $this->saveMenu('main', [$this->menuItem('link', ['label' => 'Boys', 'url' => '/boys', 'submenu' => 'mega', 'html_block_id' => $block->id, 'mega_columns' => 1, 'children' => [
            $this->columnItem([$this->menuItem('link', ['label' => 'Jackets', 'url' => '/j'])], 'Clothes'),
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

    /** @return array<string, array{0: ?string, 1: ?string, 2: bool}> [the published footer holds it, the draft footer holds it, deleted] */
    public static function footerUses(): array
    {
        return ['only in the published footer' => ['published', null, false], 'only in the draft footer' => [null, 'draft', false], 'in both' => ['published', 'draft', false], 'in neither' => [null, null, true]];
    }

    /** HDR-3: a block the PUBLISHED or the DRAFT footer layout shows is never deleted silently; the footer is one more name in the message. */
    #[\PHPUnit\Framework\Attributes\DataProvider('footerUses')]
    public function test_a_block_the_footer_shows_is_refused_and_a_free_one_is_deleted(?string $published, ?string $draft, bool $deleted): void
    {
        $this->actingAsStaff('Administrator');
        $block = HtmlBlockModel::create(['title' => 'Banner', 'body' => '<p>x</p>']);
        $other = HtmlBlockModel::create(['title' => 'Other', 'body' => '<p>y</p>']);
        $layoutWith = fn (int $id): string => (string) json_encode(['upper' => ['rows' => [['columns' => [['blocks' => [['type' => 'text', 'settings' => []], ['type' => 'html_block', 'settings' => ['html_block' => $id]]]]]]]]]);
        $settings = app(\App\Settings\Contracts\SiteSettingsRepository::class);
        $published === null || $settings->set(\App\Settings\AppearanceSettings::FOOTER_PUBLISHED_KEY, $layoutWith($block->id));
        $draft === null || $settings->set(\App\Settings\AppearanceSettings::FOOTER_DRAFT_KEY, $layoutWith($block->id));
        // another block in the footer never protects this one
        $settings->forget('x');

        $this->assertSame(! $deleted, HtmlBlockUsage::footer($block->id));
        Livewire::test(ListHtmlBlocks::class)->callTableAction('delete', $block);

        $deleted ? $this->assertNull(HtmlBlockModel::query()->find($block->id)) : $this->assertNotNull(HtmlBlockModel::query()->find($block->id), 'refused');

        if (! $deleted) {
            $this->assertStringContainsString('footer', (string) HtmlBlockUsage::refusal($block));
            $this->assertFalse($block->delete(), 'the model is the last line of defence');
            $this->assertNotNull(HtmlBlockModel::query()->find($other->id));
            $this->assertFalse(HtmlBlockUsage::footer($other->id));
        }
    }

    public function test_the_message_keeps_the_menu_format_and_adds_the_footer_as_one_more_name(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Boys picture', 'body' => '<p>x</p>']);
        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set(\App\Settings\AppearanceSettings::FOOTER_PUBLISHED_KEY, (string) json_encode(['upper' => ['rows' => [['columns' => [['blocks' => [['type' => 'html_block', 'settings' => ['html_block' => $block->id]]]]]]]]]));

        $this->assertSame('The block "Boys picture" is shown by the footer and cannot be deleted.', HtmlBlockUsage::refusal($block));

        $this->saveMenu('main', [$this->menuItem('link', ['label' => 'Boys', 'url' => '/boys', 'submenu' => 'mega', 'html_block_id' => $block->id, 'mega_columns' => 1, 'children' => [$this->columnItem([$this->menuItem('link', ['label' => 'Jackets', 'url' => '/j'])])]])]);
        $this->assertSame('The block "Boys picture" is shown by the menu Main menu and by the footer and cannot be deleted.', HtmlBlockUsage::refusal($block));

        app(\App\Settings\Contracts\SiteSettingsRepository::class)->forget(\App\Settings\AppearanceSettings::FOOTER_PUBLISHED_KEY);
        $this->assertSame('The block "Boys picture" is shown by the menu Main menu and cannot be deleted.', HtmlBlockUsage::refusal($block), 'the old menu message, unchanged');
    }

    /** HDR-3: config/livewire.php payload.max_nesting_depth is bounded but deep enough for a link inside a list inside a list of a rich-text editor. */
    /** @return array<string, array{0: ?string, 1: ?string, 2: bool}> [the published right column holds it, the draft right column holds it, deletable] */
    public static function summaryUses(): array
    {
        return ['only in the published right column' => ['published', null, false], 'only in the draft right column' => [null, 'draft', false], 'in both' => ['published', 'draft', false], 'in neither' => [null, null, true]];
    }

    /** SC-1.1: a block the PUBLISHED or the DRAFT right column shows is never deleted silently, exactly like a footer block; the right column is named in the message. */
    #[\PHPUnit\Framework\Attributes\DataProvider('summaryUses')]
    public function test_a_block_the_right_column_shows_is_refused_and_a_free_one_is_deleted(?string $published, ?string $draft, bool $deleted): void
    {
        $this->actingAsStaff('Administrator');
        $block = HtmlBlockModel::create(['title' => 'Club', 'body' => '<p>x</p>']);
        $other = HtmlBlockModel::create(['title' => 'Other', 'body' => '<p>y</p>']);
        $layoutWith = fn (int $id): string => (string) json_encode(['components' => [['type' => 'title', 'settings' => []], ['type' => 'html_block', 'settings' => ['html_block' => $id]]]]);
        $settings = app(\App\Settings\Contracts\SiteSettingsRepository::class);
        $published === null || $settings->set(\App\Settings\AppearanceSettings::SUMMARY_PUBLISHED_KEY, $layoutWith($block->id));
        $draft === null || $settings->set(\App\Settings\AppearanceSettings::SUMMARY_DRAFT_KEY, $layoutWith($block->id));

        $this->assertSame(! $deleted, HtmlBlockUsage::summary($block->id));
        $this->assertFalse(HtmlBlockUsage::summary($other->id), 'another block is not protected by it');
        Livewire::test(ListHtmlBlocks::class)->callTableAction('delete', $block);

        $deleted ? $this->assertNull(HtmlBlockModel::query()->find($block->id)) : $this->assertNotNull(HtmlBlockModel::query()->find($block->id), 'refused');

        if (! $deleted) {
            $this->assertSame('The block "Club" is shown by the right column and cannot be deleted.', HtmlBlockUsage::refusal($block));
            $this->assertFalse($block->delete(), 'the model is the last line of defence');
            $this->assertNotNull(HtmlBlockModel::query()->find($other->id));
        }
    }

    /** SC-1.1: the right column is one more name in the message, after the menu and the footer, and the old messages are unchanged. */
    public function test_the_message_names_the_right_column_with_the_others_and_the_old_messages_stay(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Banner', 'body' => '<p>x</p>']);
        $settings = app(\App\Settings\Contracts\SiteSettingsRepository::class);
        $summary = (string) json_encode(['components' => [['type' => 'html_block', 'settings' => ['html_block' => $block->id]]]]);
        $footer = (string) json_encode(['upper' => ['rows' => [['columns' => [['blocks' => [['type' => 'html_block', 'settings' => ['html_block' => $block->id]]]]]]]]]);

        $settings->set(\App\Settings\AppearanceSettings::SUMMARY_PUBLISHED_KEY, $summary);
        $this->assertSame('The block "Banner" is shown by the right column and cannot be deleted.', HtmlBlockUsage::refusal($block));

        $settings->set(\App\Settings\AppearanceSettings::FOOTER_PUBLISHED_KEY, $footer);
        $this->assertSame('The block "Banner" is shown by the footer and the right column and cannot be deleted.', HtmlBlockUsage::refusal($block));

        $this->saveMenu('main', [$this->menuItem('link', ['label' => 'Boys', 'url' => '/boys', 'submenu' => 'mega', 'html_block_id' => $block->id, 'mega_columns' => 1, 'children' => [$this->columnItem([$this->menuItem('link', ['label' => 'Jackets', 'url' => '/j'])])]])]);
        $this->assertSame('The block "Banner" is shown by the menu Main menu and the footer and the right column and cannot be deleted.', HtmlBlockUsage::refusal($block));

        $settings->forget(\App\Settings\AppearanceSettings::SUMMARY_PUBLISHED_KEY);
        $settings->forget(\App\Settings\AppearanceSettings::FOOTER_PUBLISHED_KEY);
        // the settings repository is SCOPED: an instance made during the menu save still holds the old values (one request = one instance in the shop)
        $this->app->forgetScopedInstances();
        $this->assertSame('The block "Banner" is shown by the menu Main menu and cannot be deleted.', HtmlBlockUsage::refusal($block), 'the old menu message, unchanged');
    }

    public function test_livewires_nesting_limit_allows_a_link_inside_a_nested_list(): void
    {
        $depth = (int) config('livewire.payload.max_nesting_depth');

        $this->assertGreaterThanOrEqual(24, $depth);
        $this->assertLessThan(100, $depth, 'bounded, not disabled');

        // the path of the error the owner saw, counted the way Livewire counts it
        $path = 'data.body.content.0.content.2.content.0.content.0.marks.0.attrs.href';
        $this->assertGreaterThan(10, count(explode('.', $path)), 'deeper than the old limit');
        $this->assertLessThanOrEqual($depth, count(explode('.', $path)));
    }

    /** HDR-3: the SAME round trip as the browser's: a rich body with a bullet list inside a bullet list and a link on the inner item (the update path is deeper than 10 levels). */
    public function test_a_link_on_the_inner_item_of_a_nested_list_survives_the_livewire_round_trip(): void
    {
        $this->actingAsStaff('Administrator');
        $doc = ['type' => 'doc', 'content' => [['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Outer']]]]],
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Outer two']]], ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Inner', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/old']]]]]]]],
            ]]]],
        ]]]];

        $page = Livewire::test(CreateHtmlBlock::class)->fillForm(['title' => 'Nested', 'body' => $doc]);
        // the browser sends the changed attribute with its whole dot path: bulletList > listItem > bulletList > listItem > paragraph > text > mark > attrs > href
        $page->set('data.body.content.0.content.1.content.1.content.0.content.0.content.0.marks.0.attrs.href', 'https://example.com/new');
        $page->call('create')->assertHasNoFormErrors();

        $this->assertStringContainsString('href="https://example.com/new"', HtmlBlockModel::query()->firstOrFail()->body);
    }

    /** HB-3: the «Параграф» button is in the toolbar with its Bulgarian name, and a heading followed by a paragraph is saved as written. */
    public function test_the_paragraph_button_is_in_the_toolbar_and_a_heading_then_a_paragraph_is_saved_as_written(): void
    {
        $this->actingAsStaff('Administrator');
        app()->setLocale('bg');
        $html = $this->get(HtmlBlockResource::getUrl('create'))->assertOk()->getContent();

        // the tool is in the editor's toolbar: its active state is read from the editor, under its own name
        $this->assertStringContainsString("isActive('paragraph'", $html);
        $this->assertStringContainsString('Параграф', $html);

        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Промокод Есен -10%']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Важи до края на месеца.']]],
        ]];

        Livewire::test(CreateHtmlBlock::class)->fillForm(['title' => 'Промо', 'body' => $doc])->call('create')->assertHasNoFormErrors();

        $this->assertSame('<h2>Промокод Есен -10%</h2><p>Важи до края на месеца.</p>', HtmlBlockModel::query()->firstOrFail()->body);
        app()->setLocale('en');
    }

    public function test_the_editor_offers_exactly_what_the_filter_keeps(): void
    {
        $this->actingAsStaff('Administrator');
        $html = $this->get(HtmlBlockResource::getUrl('create'))->assertOk()->getContent();

        $this->assertStringContainsString('html-blocks', $html, 'pictures are attached into the html-blocks folder of the public disk');
        $this->assertStringNotContainsString('data-tool="table"', $html);
    }
}
