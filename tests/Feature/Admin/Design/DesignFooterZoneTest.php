<?php

namespace Tests\Feature\Admin\Design;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockUsage;
use App\Content\Menus\MenuModel;
use App\Filament\Pages\Design\DesignScreen;
use App\Filament\Pages\Design\Footer;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Design\ZoneStore\FooterAdapter;
use App\Storefront\Footer\FooterLayout;
use App\Storefront\Footer\FooterValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * DS-2b, the «Футър» zone of the Design screen: the footer as a tree (levels, rows, columns, blocks), the structure actions and their limits, the palette, the properties, «Приложи» = the
 * draft the old page writes, errors on the field of the selected thing, publish / discard / reset to default, and the protection of what the footer uses.
 */
class DesignFooterZoneTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        Storage::fake('public');
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function screen(): Testable
    {
        $this->actingAsStaff('Administrator');

        return Livewire::test(DesignScreen::class)->call('selectZone', 'footer');
    }

    /** @return array<string, mixed> the stored footer draft */
    private function draft(): array
    {
        return json_decode((string) $this->settings()->get(AppearanceSettings::FOOTER_DRAFT_KEY), true);
    }

    /** @return list<string> the ids of the columns of a row of the upper level */
    private function columns(Testable $screen, int $row = 0): array
    {
        return array_column($screen->get('tree')['upper']['rows'][$row]['cells'], 'id');
    }

    private function blocks(Testable $screen, int $row = 0, int $column = 0): array
    {
        return array_column($screen->get('tree')['upper']['rows'][$row]['cells'][$column]['items'], 'type');
    }

    // ---- the zone ------------------------------------------------------------------------------------------------------------------------

    public function test_the_footer_zone_starts_from_the_default_the_published_or_the_draft_like_the_old_page(): void
    {
        $this->assertSame(FooterAdapter::toTree(FooterLayout::default()), $this->screen()->get('tree'));

        $published = FooterLayout::default();
        $published['lower']['text_template'] = 'Published line';
        $this->settings()->set(AppearanceSettings::FOOTER_PUBLISHED_KEY, json_encode($published));
        $this->assertSame('Published line', $this->screen()->get('tree')['lower']['rows'][0]['cells'][0]['items'][0]['settings']['text_template']);

        $draft = $published;
        $draft['lower']['text_template'] = 'Draft line';
        $this->settings()->set(AppearanceSettings::FOOTER_DRAFT_KEY, json_encode($draft));
        $this->assertSame('Draft line', $this->screen()->get('tree')['lower']['rows'][0]['cells'][0]['items'][0]['settings']['text_template']);
    }

    public function test_the_zone_list_enables_the_four_zones_and_refuses_the_others(): void
    {
        $screen = $this->screen();
        $screen->assertSet('zone', 'footer');

        $screen->call('selectZone', 'content')->assertSet('zone', 'footer');
        $screen->call('selectZone', '../x')->assertSet('zone', 'footer');
        $screen->call('selectZone', 'header')->assertSet('zone', 'header');
        $this->assertStringContainsString('wire:click="selectZone(\'footer\')"', $screen->html());
        $this->assertStringContainsString('disabled', $screen->html());
    }

    public function test_the_schema_shows_both_levels_the_columns_and_the_preview_scrolls_to_the_footer(): void
    {
        $html = $this->screen()->html();

        foreach (['Upper level', 'Lower level', 'Row 1', 'Column 1', 'Add a column', 'Add a row', 'Footer preview'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }

        $this->assertStringContainsString("querySelector('.sf-footer')", $html);
        $this->assertStringContainsString('/footer-preview?', $html);
        $this->assertStringNotContainsString('/header-preview', $html);
    }

    // ---- rows and columns ----------------------------------------------------------------------------------------------------------------

    public function test_a_second_row_is_added_and_a_third_is_refused(): void
    {
        $screen = $this->screen()->call('addRow');
        $this->assertCount(2, $screen->get('tree')['upper']['rows']);
        // DS-5: a structural change is stored as the draft at once (autosave): nothing is left unsaved, and the draft already has the second row
        $screen->assertSet('dirty', false);
        $this->assertCount(2, $this->draft()['upper']['rows']);

        $before = $screen->get('tree');
        $screen->call('addRow');
        $this->assertSame($before, $screen->get('tree'), 'a third row');
    }

    public function test_columns_are_added_up_to_five_and_the_sixth_is_refused(): void
    {
        $screen = $this->screen();

        for ($i = 0; $i < 4; $i++) {
            $screen->call('addColumn', 'f-u-r0');
        }

        $this->assertCount(5, $this->columns($screen));
        $before = $screen->get('tree');
        $screen->call('addColumn', 'f-u-r0');
        $this->assertSame($before, $screen->get('tree'), 'a sixth column');
    }

    public function test_an_empty_row_and_an_empty_column_go_at_once_and_the_last_ones_stay(): void
    {
        $screen = $this->screen()->call('addColumn', 'f-u-r0')->call('addRow');
        [, $second] = $this->columns($screen);
        $rowId = $screen->get('tree')['upper']['rows'][1]['id'];

        $screen->call('removeColumn', $second);
        $this->assertCount(1, $this->columns($screen));
        $screen->call('removeRow', $rowId);
        $this->assertCount(1, $screen->get('tree')['upper']['rows']);

        $before = $screen->get('tree');
        $screen->call('removeRow', 'f-u-r0')->call('removeColumn', 'f-u-r0-c0', true);
        $this->assertSame($before, $screen->get('tree'), 'the last row and the last column stay');
    }

    public function test_a_column_that_holds_blocks_is_removed_only_after_the_confirmation(): void
    {
        $screen = $this->screen()->call('addColumn', 'f-u-r0');
        [$first, $second] = $this->columns($screen);
        $screen->call('selectCell', $second)->call('addNode', 'spacer');
        $before = $screen->get('tree');

        $screen->call('removeColumn', $second);
        $this->assertSame($before, $screen->get('tree'), 'not confirmed: nothing happens');

        $screen->mountAction('removeColumn', ['id' => $second])->assertActionMounted('removeColumn');
        $this->assertSame($before, $screen->get('tree'), 'the question is open: nothing happened yet');
        $this->assertStringContainsString('Space', $screen->html(), 'the question names the blocks');
        $screen->callMountedAction();
        $this->assertSame([$first], $this->columns($screen));
    }

    public function test_a_row_that_holds_blocks_is_removed_only_after_the_confirmation(): void
    {
        $screen = $this->screen()->call('addRow');
        $rowId = $screen->get('tree')['upper']['rows'][1]['id'];
        $cell = $screen->get('tree')['upper']['rows'][1]['cells'][0]['id'];
        $screen->call('selectCell', $cell)->call('addNode', 'text');

        $before = $screen->get('tree');
        $screen->call('removeRow', $rowId);
        $this->assertSame($before, $screen->get('tree'));

        $screen->call('removeRow', $rowId, true);
        $this->assertCount(1, $screen->get('tree')['upper']['rows']);
    }

    public function test_a_column_moves_left_and_right_inside_its_row_only(): void
    {
        $screen = $this->screen()->call('addColumn', 'f-u-r0');
        [$first, $second] = $this->columns($screen);

        $screen->call('moveColumn', $second, 'left');
        $this->assertSame([$second, $first], $this->columns($screen));
        $before = $screen->get('tree');
        $screen->call('moveColumn', $second, 'left')->call('moveColumn', $second, 'up')->call('moveColumn', 'nope', 'right');
        $this->assertSame($before, $screen->get('tree'));
        $this->assertFalse($screen->instance()->canMoveColumn($second, 'left'));
        $this->assertTrue($screen->instance()->canMoveColumn($second, 'right'));
    }

    // ---- the palette and the blocks -------------------------------------------------------------------------------------------------------

    public function test_the_palette_offers_every_block_type_and_the_newsletter_even_without_its_route(): void
    {
        $screen = $this->screen();
        $palette = collect($screen->instance()->palette())->keyBy('type');

        $this->assertSame(['menu', 'html_block', 'text', 'newsletter', 'social', 'payments', 'contacts', 'spacer'], $palette->keys()->all());
        $this->assertNotNull($palette['text']['reason'], 'select a column first');

        $screen->call('selectCell', 'f-u-r0-c0');
        $palette = collect($screen->instance()->palette())->keyBy('type');
        $this->assertNull($palette['newsletter']['reason']);
        $this->assertSame('will show when the newsletter is ready', $palette['newsletter']['hint']);
        $this->assertStringContainsString('will show when the newsletter is ready', $screen->html());

        $screen->call('addNode', 'newsletter')->assertSet('selected.kind', 'node');
        $this->assertSame(['menu', 'newsletter'], $this->blocks($screen));
    }

    public function test_a_column_takes_six_blocks_and_the_seventh_is_refused(): void
    {
        $screen = $this->screen();

        for ($i = 0; $i < 6; $i++) {
            $screen->call('selectCell', 'f-u-r0-c0')->call('addNode', 'spacer');
        }

        $this->assertCount(6, $this->blocks($screen));
        $before = $screen->get('tree');
        $screen->call('selectCell', 'f-u-r0-c0');
        $this->assertStringContainsString('full', strtolower((string) collect($screen->instance()->palette())->firstWhere('type', 'text')['reason']));
        $screen->call('addNode', 'text');
        $this->assertSame($before, $screen->get('tree'));
    }

    public function test_the_forty_first_block_is_refused(): void
    {
        $screen = $this->screen();
        $screen->call('addRow');

        for ($i = 0; $i < 4; $i++) {
            $screen->call('addColumn', 'f-u-r0')->call('addColumn', $screen->get('tree')['upper']['rows'][1]['id']);
        }

        $cells = [];

        foreach ($screen->get('tree')['upper']['rows'] as $row) {
            foreach ($row['cells'] as $cell) {
                $cells[] = $cell['id'];
            }
        }

        $count = 1;

        foreach ($cells as $cell) {
            while ($count < 40 && count(collect($screen->get('tree')['upper']['rows'])->flatMap(fn ($r) => $r['cells'])->firstWhere('id', $cell)['items']) < 4) {
                $screen->call('selectCell', $cell)->call('addNode', 'spacer');
                $count++;
            }
        }

        $this->assertSame(40, $count);
        $total = fn () => collect($screen->get('tree')['upper']['rows'])->flatMap(fn ($r) => $r['cells'])->sum(fn ($c) => count($c['items']));
        $this->assertSame(40, $total());
        $screen->call('selectCell', $cells[0]);
        $screen->call('addNode', 'text');
        $this->assertSame(40, $total(), 'the forty-first block');
    }

    public function test_a_block_moves_up_down_and_to_the_neighbouring_column_of_its_row(): void
    {
        $screen = $this->screen()->call('addColumn', 'f-u-r0');
        [$first, $second] = $this->columns($screen);
        $screen->call('selectCell', $first)->call('addNode', 'spacer');
        $this->assertSame(['menu', 'spacer'], $this->blocks($screen));
        $spacer = $screen->get('selected')['id'];

        $screen->call('moveNode', $spacer, 'up');
        $this->assertSame(['spacer', 'menu'], $this->blocks($screen));
        $screen->call('moveNode', $spacer, 'right');
        $this->assertSame(['menu'], $this->blocks($screen));
        $this->assertSame(['spacer'], $this->blocks($screen, 0, 1));
        $before = $screen->get('tree');
        $screen->call('moveNode', $spacer, 'right');
        $this->assertSame($before, $screen->get('tree'), 'no column to the right');
    }

    // ---- the properties --------------------------------------------------------------------------------------------------------------------

    public function test_a_menu_block_has_the_menu_picker_with_the_footer_place_and_a_block_has_its_own_fields(): void
    {
        MenuModel::create(['key' => 'promo', 'name' => 'Promo menu']);
        $html = $this->screen()->call('selectNode', 'f-u-r0-c0-0')->html();

        $this->assertStringContainsString('The menu of the Footer place', $html);
        $this->assertStringContainsString('Promo menu', $html);
        $this->assertStringContainsString('Heading', $html);
        $this->assertStringContainsString('/admin/menus', $html);
    }

    public function test_an_html_block_is_a_select_of_the_existing_blocks_with_a_link_and_apply_stores_its_id(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Banner block', 'body' => '<p>Hello</p>']);
        $screen = $this->screen()->call('selectCell', 'f-u-r0-c0')->call('addNode', 'html_block');
        $this->assertStringContainsString('Banner block', $screen->html());
        $this->assertStringContainsString('Edit the HTML blocks', $screen->html());

        $screen->set('props.settings.html_block', $block->id)->call('apply')->assertHasNoErrors();

        $this->assertSame($block->id, $this->draft()['upper']['rows'][0]['columns'][0]['blocks'][1]['settings']['html_block']);
    }

    public function test_the_social_block_keeps_its_stored_shape_one_address_per_network(): void
    {
        $screen = $this->screen()->call('selectCell', 'f-u-r0-c0')->call('addNode', 'social');
        $screen->set('props.settings.n_facebook', 'https://example.test/fb')->set('props.settings.n_instagram', 'https://example.test/ig')->call('apply')->assertHasNoErrors();

        $this->assertSame([['network' => 'facebook', 'url' => 'https://example.test/fb'], ['network' => 'instagram', 'url' => 'https://example.test/ig']], $this->draft()['upper']['rows'][0]['columns'][0]['blocks'][1]['settings']['items']);
        $screen->call('selectNode', $screen->get('selected')['id'])->assertSet('props.settings.n_facebook', 'https://example.test/fb');
    }

    public function test_payments_are_checkboxes_in_the_catalog_order_and_a_column_has_span_and_align(): void
    {
        $screen = $this->screen()->call('selectCell', 'f-u-r0-c0')->call('addNode', 'payments');
        $screen->set('props.settings.methods', ['paypal', 'visa'])->call('apply')->assertHasNoErrors();
        $this->assertSame(['paypal', 'visa'], $this->draft()['upper']['rows'][0]['columns'][0]['blocks'][1]['settings']['methods']);

        $screen->call('selectCell', 'f-u-r0-c0')->assertSet('props.span', '1')->assertSet('props.align', 'start');
        $screen->set('props.span', '2')->set('props.align', 'center')->call('apply')->assertHasNoErrors();
        $column = $this->draft()['upper']['rows'][0]['columns'][0];
        $this->assertSame([2, 'center'], [$column['span'], $column['align']]);
    }

    public function test_the_upper_level_has_padding_colours_a_border_and_the_lower_level_a_line(): void
    {
        $screen = $this->screen()->call('selectLayout', 'upper');
        $screen->assertSet('props.enabled', true)->assertSet('props.padding', 'normal')->assertSet('props.border_width', '1');
        $screen->set('props.padding', 'spacious')->set('props.colors.bg', '#112233')->set('props.colors.fg', '#eeeeee')->set('props.border_color', '#c0392b')->set('props.border_width', '3');
        $screen->call('selectLayout', 'lower')->assertSet('props.align', 'center');
        $screen->set('props.text_template', '© [year] Test')->set('props.align', 'end')->call('apply')->assertHasNoErrors();

        $draft = $this->draft();
        $this->assertSame(['spacious', '#112233', '#eeeeee', '#c0392b', 3], [$draft['upper']['padding'], $draft['upper']['bg'], $draft['upper']['text'], $draft['upper']['border_color'], $draft['upper']['border_width']]);
        $this->assertSame(['© [year] Test', 'end'], [$draft['lower']['text_template'], $draft['lower']['align']]);
        $this->assertStringContainsString('[year]', $screen->html(), 'the line is shown as written');
    }

    public function test_a_low_contrast_pair_is_a_hint_in_the_panel_and_a_notice_after_the_save(): void
    {
        $screen = $this->screen()->call('selectLayout', 'upper')->set('props.colors.bg', '#ffffff')->set('props.colors.fg', '#f5f5f5');
        $this->assertStringContainsString('Low contrast', $screen->html());

        $screen->call('apply')->assertHasNoErrors()->assertNotified();
        $this->assertNotNull($this->settings()->get(AppearanceSettings::FOOTER_DRAFT_KEY), 'a warning never blocks the save');
    }

 

    public function test_what_the_old_page_saved_is_what_the_screen_shows_after_a_reload(): void
    {
        $this->actingAsStaff('Administrator');
        $layout = FooterLayout::default();
        $layout['upper']['rows'][0]['columns'][] = ['span' => 2, 'align' => 'center', 'blocks' => [['type' => 'text', 'settings' => ['heading' => 'Hello', 'body' => 'World']]]];
        $this->settings()->set(AppearanceSettings::FOOTER_DRAFT_KEY, json_encode(FooterValidator::validate($layout)->layout));

        $tree = Livewire::test(DesignScreen::class)->call('selectZone', 'footer')->get('tree');

        $this->assertSame('Hello', $tree['upper']['rows'][0]['cells'][1]['items'][0]['settings']['heading']);
        $this->assertSame(2, $tree['upper']['rows'][0]['cells'][1]['props']['span']);
    }

    // ---- errors ----------------------------------------------------------------------------------------------------------------------------

    public function test_an_unsafe_social_address_writes_nothing_selects_the_block_and_marks_its_field(): void
    {
        $screen = $this->screen()->call('selectCell', 'f-u-r0-c0')->call('addNode', 'social');
        $id = $screen->get('selected')['id'];
        // DS-5: adding the block is a structural change, stored as the draft at once; the REFUSED apply below must leave that draft exactly as it was
        $afterAdd = $this->settings()->get(AppearanceSettings::FOOTER_DRAFT_KEY);
        $this->assertNotNull($afterAdd);
        $screen->set('props.settings.n_facebook', 'javascript:alert(1)');
        $screen->call('selectLayout', 'upper');

        $screen->call('apply')->assertHasErrors(['props.settings.n_facebook']);

        $this->assertSame($afterAdd, $this->settings()->get(AppearanceSettings::FOOTER_DRAFT_KEY), 'nothing is written');
        $screen->assertSet('selected', ['kind' => 'node', 'id' => $id])->assertSet('invalid', [$id]);
    }

    public function test_a_bad_colour_selects_the_level_and_marks_its_field(): void
    {
        $screen = $this->screen()->call('selectLayout', 'lower')->set('props.colors.bg', 'red');
        $screen->call('selectCell', 'f-u-r0-c0');

        $screen->call('apply')->assertHasErrors(['props.colors.bg']);

        $screen->assertSet('selected', ['kind' => 'layout', 'id' => 'lower'])->assertSet('device', 'lower');
        $this->assertNull($this->settings()->get(AppearanceSettings::FOOTER_DRAFT_KEY));
    }

    public function test_an_overlong_line_is_refused_on_the_line_field(): void
    {
        $screen = $this->screen()->call('selectLayout', 'lower')->set('props.text_template', str_repeat('x', 301));

        $screen->call('apply')->assertHasErrors(['props.text_template']);
    }

    // ---- publish, discard, default ---------------------------------------------------------------------------------------------------------

    public function test_publish_compiles_the_footer_and_the_home_page_shows_it_then_discard_restores(): void
    {
        $screen = $this->screen()->call('selectLayout', 'lower')->set('props.text_template', 'Screen footer line')->call('apply')->callAction('publish')->assertHasNoErrors();

        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::FOOTER_COMPILED_KEY), true);
        $this->assertSame('Screen footer line', $compiled['layout']['lower']['text_template']);
        $this->assertSame($compiled['layout'], json_decode((string) $this->settings()->get(AppearanceSettings::FOOTER_PUBLISHED_KEY), true));

        $screen->call('selectLayout', 'lower')->set('props.text_template', 'Only a draft')->call('apply');
        $this->assertSame('Only a draft', $this->draft()['lower']['text_template']);

        $screen->callAction('discard');
        $this->assertNull($this->settings()->get(AppearanceSettings::FOOTER_DRAFT_KEY));
        $this->assertSame('Screen footer line', $screen->get('tree')['lower']['rows'][0]['cells'][0]['items'][0]['settings']['text_template']);
    }

    public function test_reset_to_default_changes_only_the_screen_until_apply(): void
    {
        $this->actingAsStaff('Administrator');
        $layout = FooterLayout::default();
        $layout['lower']['text_template'] = 'Custom';
        $this->settings()->set(AppearanceSettings::FOOTER_DRAFT_KEY, json_encode($layout));
        $screen = Livewire::test(DesignScreen::class)->call('selectZone', 'footer');

        $screen->callAction('reset');

        // DS-5: «Начални настройки» puts the initial values into the WORKING COPY (the draft, stored at once); the published footer is untouched until «Публикувай»
        $this->assertSame(FooterAdapter::toTree(FooterLayout::default()), $screen->get('tree'));
        $screen->assertSet('dirty', false);
        $this->assertSame(FooterLayout::default()['lower']['text_template'], $this->draft()['lower']['text_template']);
        $this->assertNull($this->settings()->get(AppearanceSettings::FOOTER_PUBLISHED_KEY), 'nothing is published');
    }

    public function test_the_actions_are_journalled_with_the_old_pages_names(): void
    {
        $this->settings()->set('admin.activity_log_enabled', '1');
        $screen = $this->screen();
        $screen->call('apply')->call('publish')->call('discard');

        $this->assertSame(['draft', 'published', 'discarded'], DB::table('activity_log')->where('entity_type', 'storefront_footer')->orderBy('id')->pluck('field')->all());
    }

    // ---- what the footer uses stays protected ---------------------------------------------------------------------------------------------

    public function test_an_html_block_a_footer_draft_uses_cannot_be_deleted(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Banner block', 'body' => '<p>Hello</p>']);
        $screen = $this->screen()->call('selectCell', 'f-u-r0-c0')->call('addNode', 'html_block');
        $screen->set('props.settings.html_block', $block->id)->call('apply')->assertHasNoErrors();

        $this->assertTrue(HtmlBlockUsage::footer($block->id));
        $this->assertNotNull(HtmlBlockUsage::refusal($block));
        $this->assertStringContainsString('footer', strtolower((string) HtmlBlockUsage::refusal($block)));
    }

    // ---- access ----------------------------------------------------------------------------------------------------------------------------

    public function test_every_footer_action_refuses_a_user_without_the_permission(): void
    {
        $calls = [['addRow'], ['addColumn', 'f-u-r0'], ['removeColumn', 'f-u-r0-c0'], ['removeRow', 'f-u-r0'], ['moveColumn', 'f-u-r0-c0', 'right'], ['resetToDefault'], ['selectLayout', 'lower'], ['selectZone', 'header']];

        foreach ($calls as $call) {
            $screen = $this->screen();
            $this->app->forgetScopedInstances();
            $this->actingAsStaff('Product Entry');
            $screen->call(...$call)->assertForbidden();
        }

        $this->assertNull($this->settings()->get(AppearanceSettings::FOOTER_DRAFT_KEY));
        $this->assertSame(0, MenuModel::query()->where('key', 'promo')->count());
    }
}
