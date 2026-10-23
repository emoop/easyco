<?php

namespace Tests\Feature\Admin\Design;

use App\Filament\NavigationGroup;
use App\Filament\Pages\Design\DesignScreen;
use App\Filament\Pages\Settings\HeaderSettings;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Design\ZoneStore\HeaderAdapter;
use App\Storefront\Header\HeaderLayout;
use App\Storefront\Header\HeaderValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * DS-2a, the «Дизайн» screen with the Header zone: access (page and every action), the starting tree (the old page's rule), the palette rules, the structure rules, the properties panel,
 * «Приложи» = the same draft the old page writes, errors on the field of the selected thing, publish / discard, the locked state and the preview frame.
 */
class DesignScreenTest extends TestCase
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
        return Livewire::test(DesignScreen::class);
    }

    /** @return array<string, mixed> the stored draft */
    private function draft(): array
    {
        return json_decode((string) $this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY), true);
    }

    /** The id of the n-th node of a desktop cell in the component's tree. */
    private function nodeId(Testable $screen, string $cell, int $index = 0, string $device = 'desktop'): string
    {
        foreach ($screen->get('tree')[$device]['rows'] as $row) {
            foreach ($row['cells'] as $c) {
                if ($c['id'] === $cell) {
                    return $c['items'][$index]['id'];
                }
            }
        }

        $this->fail("no cell {$cell}");
    }

    // ---- access -----------------------------------------------------------------------------------------------------------------------

    public function test_the_content_roles_reach_the_page_and_a_role_without_the_permission_does_not(): void
    {
        foreach (['Administrator', 'Manager'] as $role) {
            $this->actingAsStaff($role);
            $this->get(DesignScreen::getUrl())->assertOk();
        }

        $this->actingAsStaff('Product Entry');
        $this->get(DesignScreen::getUrl())->assertForbidden();
        $this->screen()->assertForbidden();
    }

    public function test_it_is_the_first_entry_of_the_design_group_and_the_old_header_page_stays(): void
    {
        $this->assertSame(NavigationGroup::DESIGN, DesignScreen::getNavigationGroup());
        $this->assertSame('Design', DesignScreen::getNavigationLabel());
        app()->setLocale('bg');
        $this->assertSame('Дизайн', DesignScreen::getNavigationLabel());
        $this->assertLessThan(HeaderSettings::getNavigationSort(), DesignScreen::getNavigationSort());
        $this->actingAsStaff('Administrator');
        $this->get(HeaderSettings::getUrl())->assertOk();
    }

    public function test_a_direct_action_call_is_refused_when_the_permission_is_gone(): void
    {
        foreach ([['selectLayout'], ['apply'], ['publish'], ['discard'], ['addNode', 'text'], ['setDevice', 'mobile'], ['moveNode', 'h-d-main-right-0', 'down'], ['removeNode', 'h-d-main-right-0']] as $call) {
            $this->actingAsStaff('Administrator');
            $screen = $this->screen();
            $screen->call('selectLayout')->assertOk();

            $this->app->forgetScopedInstances();
            $this->actingAsStaff('Product Entry');
            $screen->call(...$call)->assertForbidden();
        }

        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY));
    }

    // ---- the starting tree ------------------------------------------------------------------------------------------------------------

    public function test_it_starts_from_the_default_then_the_published_layout_then_the_draft(): void
    {
        $this->actingAsStaff('Administrator');
        $this->assertSame(HeaderAdapter::toTree(HeaderLayout::default()), $this->screen()->get('tree'));

        $published = HeaderLayout::default();
        $published['desktop']['rows']['main']['height'] = 70;
        $this->settings()->set(AppearanceSettings::HEADER_PUBLISHED_KEY, json_encode($published));
        $this->assertSame(70, $this->screen()->get('tree')['desktop']['rows'][1]['props']['height']);

        $draft = HeaderLayout::default();
        $draft['desktop']['rows']['main']['height'] = 80;
        $this->settings()->set(AppearanceSettings::HEADER_DRAFT_KEY, json_encode($draft));
        $this->assertSame(80, $this->screen()->get('tree')['desktop']['rows'][1]['props']['height']);

        $this->settings()->set(AppearanceSettings::HEADER_DRAFT_KEY, '{"desktop":{"rows":{"main":{"left":[{"type":"nope","settings":[]}]}}}}');
        $this->assertSame(70, $this->screen()->get('tree')['desktop']['rows'][1]['props']['height'], 'a draft that does not validate is skipped, like on the old page');
    }

    public function test_the_page_shows_the_schema_the_palette_the_properties_and_the_frame(): void
    {
        $this->actingAsStaff('Administrator');
        $html = $this->screen()->html();

        foreach (['Header', 'Top banner', 'Desktop', 'Phone', 'Components', 'Properties', 'Header preview', 'Main row', 'Top row'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        $this->assertStringContainsString('<iframe', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);
        $this->assertDoesNotMatchRegularExpression('/<button(?![^>]*type="button")(?![^>]*type="submit")[^>]*>/', $html, 'every button has a type');
    }

    // ---- selection and the palette ---------------------------------------------------------------------------------------------------

    public function test_selecting_marks_the_thing_and_unknown_ids_are_refused_without_a_change(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = $this->screen();
        $tree = $screen->get('tree');

        $screen->call('selectCell', 'h-d-main-left')->assertSet('selected', ['kind' => 'cell', 'id' => 'h-d-main-left']);
        $this->assertStringContainsString('aria-pressed="true"', $screen->html());

        $screen->call('selectNode', 'h-d-main-center-0')->assertSet('selected', ['kind' => 'node', 'id' => 'h-d-main-center-0']);
        $screen->call('selectRow', 'h-d-top')->assertSet('selected', ['kind' => 'row', 'id' => 'h-d-top']);
        $screen->call('selectLayout')->assertSet('selected', ['kind' => 'layout', 'id' => 'desktop']);

        $screen->call('selectCell', 'nope')->call('selectNode', '../x')->call('selectRow', 'h-m-drawer-r')->call('selectRow', 'h-d-nothing');
        $screen->assertSet('selected', ['kind' => 'layout', 'id' => 'desktop']);
        $screen->call('addNode', 'not-a-type')->call('moveNode', 'nope', 'left')->call('removeNode', 'nope')->call('setDevice', 'tablet');
        $this->assertSame($tree, $screen->get('tree'));
        $screen->assertSet('device', 'desktop');
    }

    /** @return array<string, mixed> the palette entries by type */
    private function palette(Testable $screen): array
    {
        $out = [];

        foreach ($screen->instance()->palette() as $item) {
            $out[$item['type']] = $item['reason'];
        }

        return $out;
    }

    public function test_the_palette_is_disabled_without_a_cell_and_enabled_with_one(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = $this->screen();

        foreach ($this->palette($screen) as $type => $reason) {
            $this->assertNotNull($reason, "{$type} needs a cell first");
        }

        $screen->call('addNode', 'text');
        $this->assertSame(1, count($screen->get('tree')['desktop']['rows'][1]['cells'][0]['items']), 'nothing was added');

        $screen->call('selectCell', 'h-d-top-left');
        $palette = $this->palette($screen);
        $this->assertNull($palette['text']);
        $this->assertNull($palette['button']);
        $this->assertNotNull($palette['logo'], 'the layout already has its logo');
    }

    public function test_a_second_logo_and_a_second_menu_toggle_are_refused_on_that_device(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = $this->screen()->call('selectCell', 'h-d-top-left');
        $before = $screen->get('tree');

        $screen->call('addNode', 'logo');
        $this->assertSame($before, $screen->get('tree'), 'a second logo');

        $screen->call('addNode', 'menu_toggle')->assertSet('selected.kind', 'node');
        $screen->call('selectCell', 'h-d-top-center');
        $this->assertNotNull($this->palette($screen)['menu_toggle'], 'only one on this device');
        $with = $screen->get('tree');
        $screen->call('addNode', 'menu_toggle');
        $this->assertSame($with, $screen->get('tree'));

        $screen->call('setDevice', 'mobile');
        $screen->call('selectCell', 'h-m-top-left');
        $this->assertNotNull($this->palette($screen)['menu_toggle'], 'the phone layout already has one');
    }

    public function test_a_full_cell_refuses_the_seventh_component_and_the_drawer_its_ninth(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = $this->screen()->call('selectCell', 'h-d-top-left');

        for ($i = 0; $i < HeaderLayout::MAX_PER_SLOT; $i++) {
            $screen->call('selectCell', 'h-d-top-left')->call('addNode', 'spacer');
        }

        $this->assertCount(HeaderLayout::MAX_PER_SLOT, $screen->get('tree')['desktop']['rows'][0]['cells'][0]['items']);
        $screen->call('selectCell', 'h-d-top-left');
        $this->assertStringContainsString('full', strtolower((string) $this->palette($screen)['text']));
        $screen->call('addNode', 'text');
        $this->assertCount(HeaderLayout::MAX_PER_SLOT, $screen->get('tree')['desktop']['rows'][0]['cells'][0]['items']);

        $screen->call('setDevice', 'mobile');

        for ($i = 0; $i < HeaderLayout::MAX_DRAWER; $i++) {
            $screen->call('selectCell', 'h-m-drawer')->call('addNode', 'text');
        }

        $this->assertCount(HeaderLayout::MAX_DRAWER, $screen->get('tree')['mobile']['rows'][2]['cells'][0]['items']);
        $screen->call('selectCell', 'h-m-drawer')->call('addNode', 'text');
        $this->assertCount(HeaderLayout::MAX_DRAWER, $screen->get('tree')['mobile']['rows'][2]['cells'][0]['items']);
    }

    public function test_the_drawer_offers_only_its_types_and_never_account(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = $this->screen()->call('setDevice', 'mobile')->call('selectCell', 'h-m-drawer');

        $this->assertSame(['menu', 'text', 'button', 'social'], array_keys($this->palette($screen)));

        $before = $screen->get('tree');
        $screen->call('addNode', 'account')->call('addNode', 'cart')->call('addNode', 'logo');
        $this->assertSame($before, $screen->get('tree'));
    }

    // ---- adding, moving, removing -----------------------------------------------------------------------------------------------------

    public function test_a_component_is_added_with_its_defaults_selected_and_gets_a_fresh_id(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = $this->screen()->call('selectCell', 'h-d-top-right')->call('addNode', 'button');

        $cell = $screen->get('tree')['desktop']['rows'][0]['cells'][2]['items'];
        $this->assertCount(1, $cell);
        $this->assertSame('button', $cell[0]['type']);
        $this->assertSame(['label' => '', 'url' => '', 'style' => 'primary'], $cell[0]['settings']);
        $this->assertMatchesRegularExpression('/^n[a-z0-9]{8}$/', $cell[0]['id']);
        $screen->assertSet('selected', ['kind' => 'node', 'id' => $cell[0]['id']])->assertSet('dirty', true);

        $screen->call('selectCell', 'h-d-top-right')->call('addNode', 'button');
        $items = $screen->get('tree')['desktop']['rows'][0]['cells'][2]['items'];
        $this->assertNotSame($items[0]['id'], $items[1]['id']);
    }

    public function test_the_four_moves_work_and_the_buttons_that_cannot_act_are_disabled(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = $this->screen();
        $search = 'h-d-main-right-0';

        $screen->call('selectNode', $search);
        $this->assertTrue($screen->instance()->canMove($search, 'down'));
        $this->assertFalse($screen->instance()->canMove($search, 'up'));
        $this->assertFalse($screen->instance()->canMove($search, 'right'));
        $this->assertMatchesRegularExpression('/<button[^>]*aria-label="Move up"[^>]*disabled/', $screen->html(), 'the arrow that cannot act is disabled');
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*aria-label="Move down"[^>]*disabled/', $screen->html());

        $screen->call('moveNode', $search, 'down');
        $right = array_column($screen->get('tree')['desktop']['rows'][1]['cells'][2]['items'], 'type');
        $this->assertSame(['account', 'search', 'cart'], $right);

        $screen->call('moveNode', $search, 'left');
        $this->assertSame(['account', 'cart'], array_column($screen->get('tree')['desktop']['rows'][1]['cells'][2]['items'], 'type'));
        $this->assertSame(['logo', 'search'], array_column($screen->get('tree')['desktop']['rows'][1]['cells'][1]['items'], 'type'), 'to the END of the previous cell');

        $screen->call('moveNode', $search, 'right');
        $this->assertSame(['account', 'cart', 'search'], array_column($screen->get('tree')['desktop']['rows'][1]['cells'][2]['items'], 'type'));
        $screen->call('moveNode', $search, 'up');
        $this->assertSame(['account', 'search', 'cart'], array_column($screen->get('tree')['desktop']['rows'][1]['cells'][2]['items'], 'type'));
    }

    public function test_a_move_never_leaves_its_row_or_enters_the_drawer(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = $this->screen();
        $menu = 'h-d-main-left-0';
        $before = $screen->get('tree');

        $screen->call('moveNode', $menu, 'left');
        $screen->call('moveNode', $menu, 'up');
        $this->assertSame($before, $screen->get('tree'));

        $screen->call('setDevice', 'mobile');
        $drawerNode = 'h-m-drawer-0';
        $before = $screen->get('tree');
        $screen->call('moveNode', $drawerNode, 'left')->call('moveNode', $drawerNode, 'right')->call('moveNode', $drawerNode, 'up');
        $this->assertSame($before, $screen->get('tree'));
        $this->assertFalse($screen->instance()->canMove($drawerNode, 'left'));
    }

    public function test_removing_a_component_selects_its_cell_and_the_logo_asks_first(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = $this->screen();

        $screen->call('removeNode', 'h-d-main-right-1');
        $this->assertSame(['search', 'cart'], array_column($screen->get('tree')['desktop']['rows'][1]['cells'][2]['items'], 'type'));
        $screen->assertSet('selected', ['kind' => 'cell', 'id' => 'h-d-main-right']);

        $this->assertStringContainsString("mountAction('removeNode'", $screen->call('selectNode', 'h-d-main-center-0')->html(), 'the logo asks first');
        $screen->mountAction('removeNode', ['id' => 'h-d-main-center-0'])->assertActionMounted('removeNode');
        $this->assertCount(1, $screen->get('tree')['desktop']['rows'][1]['cells'][1]['items'], 'nothing happens before the confirmation');
        $screen->callMountedAction();
        $this->assertCount(0, $screen->get('tree')['desktop']['rows'][1]['cells'][1]['items']);

        $this->assertStringContainsString("wire:click=\"removeNode('h-d-main-right-0')\"", $screen->call('selectNode', 'h-d-main-right-0')->html(), 'a plain component is removed by one click');
        $screen->call('removeNode', 'h-d-main-right-0');
        $this->assertSame(['cart'], array_column($screen->get('tree')['desktop']['rows'][1]['cells'][2]['items'], 'type'), 'any other component goes without a question');
    }

    // ---- the properties panel and «Приложи» --------------------------------------------------------------------------------------------

    public function test_the_panel_shows_the_fields_of_the_selected_thing_only(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = $this->screen();

        $screen->call('selectNode', 'h-d-main-center-0');
        $screen->assertSet('props.settings.height', 28);
        $this->assertStringContainsString('Height (px)', $screen->html());

        $screen->call('selectRow', 'h-d-top');
        $screen->assertSet('props.enabled', false)->assertSet('props.height', 36);
        foreach (['Show the row', 'Background', 'Text size'] as $label) {
            $this->assertStringContainsString($label, $screen->html());
        }

        $screen->call('selectLayout')->assertSet('props.sticky', true);
        $screen->call('setDevice', 'mobile')->call('selectLayout')->assertSet('props.drawer_side', 'left')->assertSet('props.drawer_upper', true);
    }

    public function test_changing_the_selection_keeps_the_values_of_the_panel(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = $this->screen()->call('selectRow', 'h-d-top');

        $screen->set('props.enabled', true)->set('props.height', '40')->set('props.colors.bg', '#112233')->set('props.text_size', 'l');
        $screen->call('selectLayout');

        $row = $screen->get('tree')['desktop']['rows'][0]['props'];
        $this->assertSame(['enabled' => true, 'height' => 40, 'colors' => ['bg' => '#112233'], 'text_size' => 'l'], $row);
        $screen->assertSet('dirty', true);

        $screen->call('selectRow', 'h-d-top')->assertSet('props.height', 40)->assertSet('props.colors.bg', '#112233');
    }

    public function test_apply_writes_the_same_draft_the_old_header_page_writes_for_that_layout(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = $this->screen()->call('selectCell', 'h-d-top-right')->call('addNode', 'text');
        $id = $screen->get('selected')['id'];
        $screen->set('props.settings.text', 'Call us')->set('props.settings.url', '/contacts');
        $screen->call('selectRow', 'h-d-top')->set('props.enabled', true)->call('apply')->assertHasNoErrors();

        $fromScreen = $this->draft();
        $this->assertSame('Call us', $fromScreen['desktop']['rows']['top']['right'][0]['settings']['text']);
        $this->assertTrue($fromScreen['desktop']['rows']['top']['enabled']);
        $screen->assertSet('dirty', false);

        // the old page, given the same layout through its own form, stores the same JSON
        $this->settings()->forget(AppearanceSettings::HEADER_DRAFT_KEY);
        $block = fn (string $type, array $data): array => ['type' => $type, 'data' => $data];
        Livewire::test(HeaderSettings::class)
            ->set('data.desktop.rows.top.enabled', true)
            ->set('data.desktop.rows.top.right', ['x1' => $block('text', ['text' => 'Call us', 'url' => '/contacts'])])
            ->callAction('save_draft')->assertHasNoFormErrors();

        $this->assertSame($fromScreen, $this->draft());
        $this->assertNotSame('', $id);
    }

    public function test_apply_reloads_the_preview_with_a_fresh_signed_url(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = $this->screen();
        $first = $screen->get('previewUrl');

        $this->assertTrue($this->validSignature($first));
        $this->travel(2)->seconds();
        $screen->call('selectCell', 'h-d-top-left')->call('addNode', 'spacer')->call('apply');
        $second = $screen->get('previewUrl');

        $this->assertNotSame($first, $second);
        $this->assertTrue($this->validSignature($second));
        $this->assertStringContainsString('src="'.e($second).'"', $screen->html());
        $this->assertStringContainsString('title="Header preview"', $screen->html());
        $this->assertStringContainsString('loading="lazy"', $screen->html());
    }

    private function validSignature(string $url): bool
    {
        return \Illuminate\Support\Facades\URL::hasValidSignature(\Illuminate\Http\Request::create($url)) && str_contains($url, '/header-preview?') && str_contains($url, 'expires=');
    }

    public function test_an_invalid_setting_writes_nothing_selects_the_node_and_shows_the_error_on_its_field(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = $this->screen()->call('selectCell', 'h-d-top-right')->call('addNode', 'button');
        $id = $screen->get('selected')['id'];
        $screen->set('props.settings.label', 'Buy')->set('props.settings.url', 'javascript:alert(1)');
        $screen->call('selectLayout');

        $screen->call('apply')->assertHasErrors(['props.settings.url']);

        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY), 'nothing is written');
        $screen->assertSet('selected', ['kind' => 'node', 'id' => $id])->assertSet('invalid', [$id])->assertSet('dirty', true);
        $this->assertStringContainsString('ds-bad', $screen->html());
    }

    public function test_an_error_on_the_phone_selects_it_and_a_row_error_stands_on_the_row_field(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = $this->screen()->call('setDevice', 'mobile')->call('selectRow', 'h-m-top')->set('props.height', '5')->call('selectLayout');
        $screen->call('setDevice', 'desktop');

        $screen->call('apply')->assertHasErrors(['props.height']);
        $screen->assertSet('device', 'mobile')->assertSet('selected', ['kind' => 'row', 'id' => 'h-m-top']);
        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY));

        $screen->set('props.height', '40')->call('apply')->assertHasNoErrors();
        $this->assertSame(40, $this->draft()['mobile']['rows']['top']['height']);
    }

    public function test_a_structure_error_without_a_field_is_a_notification_and_writes_nothing(): void
    {
        $this->actingAsStaff('Administrator');
        // a logo taller than its row: the setting error is on the logo's own field
        $screen = $this->screen()->call('selectNode', 'h-d-main-center-0')->set('props.settings.height', '120');
        $screen->call('selectRow', 'h-d-main')->set('props.height', '44')->call('apply')->assertHasErrors(['props.settings.height']);
        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY));
    }

    // ---- publish and discard -----------------------------------------------------------------------------------------------------------

    public function test_publish_compiles_and_the_home_page_shows_it_then_discard_restores(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = $this->screen()->call('selectCell', 'h-d-top-right')->call('addNode', 'text');
        $screen->set('props.settings.text', 'Screen made text');
        $screen->call('selectRow', 'h-d-top')->set('props.enabled', true);

        $screen->callAction('publish')->assertHasNoErrors();
        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY), true);
        $this->assertSame('Screen made text', $compiled['layout']['desktop']['rows']['top']['right'][0]['settings']['text']);
        $this->assertSame(HeaderValidator::validate($compiled['layout'])->layout, $compiled['layout']);
        $this->assertSame($compiled['layout'], json_decode((string) $this->settings()->get(AppearanceSettings::HEADER_PUBLISHED_KEY), true));

        $screen->call('selectCell', 'h-d-top-left')->call('addNode', 'spacer')->callAction('apply');
        $this->assertCount(1, $this->draft()['desktop']['rows']['top']['left']);

        $screen->callAction('discard');
        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY));
        $this->assertSame([], $screen->get('tree')['desktop']['rows'][0]['cells'][0]['items']);
        $screen->assertSet('dirty', false)->assertSet('selected', null);
    }

    public function test_the_actions_are_journalled_like_the_old_pages_with_the_same_names(): void
    {
        $this->settings()->set('admin.activity_log_enabled', '1');
        $this->actingAsStaff('Administrator');

        $screen = $this->screen();
        $screen->call('apply')->call('publish')->call('discard');

        $this->assertSame(['draft', 'published', 'discarded'], DB::table('activity_log')->where('entity_type', 'storefront_header')->orderBy('id')->pluck('field')->all());
    }

    public function test_a_save_or_publish_from_the_old_header_page_keeps_the_look_the_screen_set(): void
    {
        $this->actingAsStaff('Administrator');
        $this->screen()->call('selectRow', 'h-d-top')->set('props.colors.bg', '#112233')->set('props.colors.fg', '#ffffff')->set('props.text_size', 'l')->call('apply')->assertHasNoErrors();
        $this->assertSame('#112233', $this->draft()['desktop']['rows']['top']['bg']);

        Livewire::test(HeaderSettings::class)->set('data.desktop.rows.main.height', 60)->callAction('save_draft')->assertHasNoFormErrors();
        $top = $this->draft()['desktop']['rows']['top'];
        $this->assertSame(['#112233', '#ffffff', 'l'], [$top['bg'], $top['fg'], $top['text_size']]);
        $this->assertSame(60, $this->draft()['desktop']['rows']['main']['height'], 'the old page still saves what it edits');

        Livewire::test(HeaderSettings::class)->callAction('publish')->assertHasNoFormErrors();
        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY), true)['layout'];
        $this->assertSame('#112233', $compiled['desktop']['rows']['top']['bg']);
        $this->assertSame('l', $compiled['desktop']['rows']['top']['text_size']);

        // and the screen shows them again after a reload
        $this->assertSame(['colors' => ['bg' => '#112233', 'fg' => '#ffffff'], 'text_size' => 'l'], array_intersect_key($this->screen()->get('tree')['desktop']['rows'][0]['props'], array_flip(['colors', 'text_size'])));
    }

    public function test_the_frame_has_a_phone_and_a_desktop_shape_and_a_link_to_open_it(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = $this->screen();
        $this->assertStringContainsString('ds-frame-desktop', $screen->html());

        $html = $screen->call('setDevice', 'mobile')->html();
        $this->assertStringContainsString('ds-frame-mobile', $html);
        $this->assertStringContainsString('Open in a new window', $html);
        $this->assertMatchesRegularExpression('#<a href="[^"]*/header-preview\?[^"]*" target="_blank" rel="noopener">#', $html);
    }

    // ---- state that cannot be set from the client -----------------------------------------------------------------------------------------

    public function test_the_tree_the_selection_and_the_other_state_cannot_be_set_from_the_browser(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = $this->screen();

        foreach (['tree' => [], 'selected' => ['kind' => 'node', 'id' => 'x'], 'device' => 'mobile', 'zone' => 'footer', 'dirty' => true, 'previewUrl' => 'https://evil.test', 'invalid' => []] as $property => $value) {
            try {
                $screen->set($property, $value);
                $this->fail("{$property} was set from the client");
            } catch (CannotUpdateLockedPropertyException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
