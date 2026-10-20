<?php

namespace Tests\Feature\Admin;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\Menus\MenuModel;
use App\Filament\NavigationGroup;
use App\Filament\Pages\Design\Footer;
use App\Filament\Pages\Settings\HeaderSettings;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Footer\FooterLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * FT-1, the admin page «Футър» / "Footer": reached by the content permission, saves a DRAFT, publishes (validates, compiles, replaces the reader's cache entry), discards the draft, resets
 * the form to the default, maps the validator's messages onto the form's own fields, journals every action (never a value), links to its help anchor and gives every value back after a reload.
 */
class FooterPageTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        app()->setLocale('en');
        Storage::fake('public');
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function page(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Footer::class);
    }

    /** @param  array<string, mixed>  $data */
    private function block(string $type, array $data): array
    {
        return ['type' => $type, 'data' => $data];
    }

    /** The owner's footer as the FORM holds it: 4 menu columns + a newsletter column, then social at the start and payments at the end, and a lower line. @return array<string, mixed> */
    private function ownersState(int $menuId): array
    {
        $menu = fn (string $heading): array => ['span' => '1', 'align' => 'start', 'blocks' => ['b'.$heading => $this->block('menu', ['menu' => $menuId, 'heading' => $heading])]];

        return [
            'upper' => [
                'enabled' => true, 'padding' => 'spacious', 'bg' => '#111111', 'text' => '#f5f5f5', 'border_color' => '#c0392b', 'border_width' => '2',
                'rows' => [
                    'r1' => ['columns' => [
                        'c1' => $menu('Shop'), 'c2' => $menu('Help'), 'c3' => $menu('Company'), 'c4' => $menu('Legal'),
                        'c5' => ['span' => '1', 'align' => 'start', 'blocks' => ['n1' => $this->block('newsletter', ['heading' => 'Stay in touch', 'placeholder' => 'Email', 'button' => 'Join', 'consent' => 'No spam'])]],
                    ]],
                    'r2' => ['columns' => [
                        'c6' => ['span' => '1', 'align' => 'start', 'blocks' => ['s1' => $this->block('social', ['n_instagram' => 'https://example.test/ig', 'n_facebook' => 'https://example.test/fb'])]],
                        'c7' => ['span' => '2', 'align' => 'end', 'blocks' => ['p1' => $this->block('payments', ['methods' => ['mastercard', 'visa', 'cash_on_delivery']]), 'p2' => $this->block('spacer', ['size' => 'l'])]],
                    ]],
                ],
            ],
            'lower' => ['enabled' => true, 'padding' => 'normal', 'bg' => '#000000', 'text' => '#dddddd', 'align' => 'start', 'text_template' => 'Fetish Харманли © [year] – управляван от МериНед ООД'],
        ];
    }

    public function test_the_content_roles_reach_the_page_and_a_role_without_the_permission_does_not(): void
    {
        foreach (['Administrator', 'Manager'] as $role) {
            $this->actingAsStaff($role);
            $this->get(Footer::getUrl())->assertOk();
        }

        $this->actingAsStaff('Product Entry');
        $this->get(Footer::getUrl())->assertForbidden();
    }

    public function test_it_sits_in_the_design_group_after_the_header_with_its_own_label(): void
    {
        $this->assertSame(NavigationGroup::DESIGN, Footer::getNavigationGroup());
        $this->assertGreaterThan(HeaderSettings::getNavigationSort(), Footer::getNavigationSort());
        $this->assertSame('Footer', Footer::getNavigationLabel());
        app()->setLocale('bg');
        $this->assertSame('Футър', Footer::getNavigationLabel());
    }

    public function test_it_starts_from_the_default_and_saves_a_draft_that_is_not_public(): void
    {
        $this->actingAsStaff('Administrator');

        $this->page()->assertFormSet(['lower.enabled' => true, 'upper.padding' => 'normal'])->fillForm(['lower.text_template' => 'Draft line [year]'])->callAction('save_draft')->assertHasNoFormErrors();

        $draft = json_decode((string) $this->settings()->get(AppearanceSettings::FOOTER_DRAFT_KEY), true);
        $this->assertSame('Draft line [year]', $draft['lower']['text_template']);
        $this->assertNull($this->settings()->get(AppearanceSettings::FOOTER_COMPILED_KEY), 'a draft is not published');
        $this->assertNull($this->settings()->get(AppearanceSettings::FOOTER_PUBLISHED_KEY));
    }

    public function test_publishing_stores_compiles_replaces_the_cache_entry_and_the_shop_shows_it(): void
    {
        $this->actingAsStaff('Administrator');
        Cache::forever(StorefrontAppearance::CACHE_KEY, ['stale' => true]);

        $this->page()->fillForm(['lower.text_template' => 'Published line [year]'])->callAction('publish')->assertHasNoFormErrors();

        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::FOOTER_COMPILED_KEY), true);
        $this->assertSame('Published line [year]', $compiled['layout']['lower']['text_template']);
        $this->assertNotNull($this->settings()->get(AppearanceSettings::FOOTER_PUBLISHED_KEY));
        $this->assertArrayNotHasKey('stale', Cache::get(StorefrontAppearance::CACHE_KEY) ?? []);

        $this->app->forgetScopedInstances();
        $this->assertStringContainsString('Published line '.now()->year, $this->get('/')->assertOk()->getContent());
    }

    public function test_a_six_column_row_is_refused_on_its_own_field_and_nothing_is_stored(): void
    {
        $this->actingAsStaff('Administrator');
        $menuId = (int) MenuModel::query()->where('key', 'footer')->value('id');
        $state = $this->ownersState($menuId);
        $state['upper']['rows']['r1']['columns']['c8'] = ['span' => '1', 'align' => 'start', 'blocks' => []];

        $this->page()->set('data.upper', $state['upper'])->call('publish')->assertHasErrors(['data.upper.rows.r1.columns']);

        $this->assertNull($this->settings()->get(AppearanceSettings::FOOTER_PUBLISHED_KEY));
    }

    public function test_a_bad_social_address_is_shown_on_the_address_field_of_that_network(): void
    {
        $this->actingAsStaff('Administrator');
        $menuId = (int) MenuModel::query()->where('key', 'footer')->value('id');
        $state = $this->ownersState($menuId);
        $state['upper']['rows']['r2']['columns']['c6']['blocks']['s1']['data']['n_facebook'] = 'javascript:alert(1)';

        $this->page()->set('data.upper', $state['upper'])->call('publish')->assertHasErrors(['data.upper.rows.r2.columns.c6.blocks.s1.data.n_facebook']);
    }

    /** Livewire refuses an update whose property path is deeper than 10 levels (config/livewire.php): found by the browser run, so every field of the fullest layout is checked here. */
    public function test_no_field_of_the_form_is_deeper_than_livewires_limit(): void
    {
        $this->actingAsStaff('Administrator');
        $menuId = (int) MenuModel::query()->where('key', 'footer')->value('id');
        $state = $this->ownersState($menuId);
        $state['upper']['rows']['r1']['columns']['c1']['blocks']['x'] = $this->block('contacts', ['phone' => '1', 'email' => 'a@b.test', 'address' => 'a', 'hours' => 'b']);
        $state['upper']['rows']['r1']['columns']['c2']['blocks']['y'] = $this->block('html_block', ['html_block' => 1]);
        $component = $this->page()->set('data.upper', $state['upper'])->set('data.lower', $state['lower']);
        $deepest = 0;
        $walk = function (array $node, int $depth) use (&$walk, &$deepest): void {
            foreach ($node as $value) {
                if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                    $walk($value, $depth + 1);
                } else {
                    $deepest = max($deepest, $depth);
                }
            }
        };
        $walk($component->get('data'), 1);

        $this->assertLessThanOrEqual((int) config('livewire.payload.max_nesting_depth', 10), $deepest, 'the deepest field path');
    }

    public function test_a_colour_that_is_not_hex_and_a_long_text_are_refused_on_their_fields(): void
    {
        $this->actingAsStaff('Administrator');

        $this->page()->fillForm(['upper.bg' => 'red'])->call('saveDraft')->assertHasErrors(['data.upper.bg']);
        $this->page()->fillForm(['lower.text_template' => str_repeat('a', 301)])->call('saveDraft')->assertHasErrors(['data.lower.text_template']);
    }

    public function test_discard_forgets_the_draft_and_goes_back_to_the_published_layout(): void
    {
        $this->actingAsStaff('Administrator');
        $component = $this->page();
        $component->fillForm(['lower.text_template' => 'Live [year]'])->callAction('publish');
        $component->fillForm(['lower.text_template' => 'Draft [year]'])->callAction('save_draft');
        $this->assertNotNull($this->settings()->get(AppearanceSettings::FOOTER_DRAFT_KEY));

        $component->callAction('discard');

        $this->assertNull($this->settings()->get(AppearanceSettings::FOOTER_DRAFT_KEY));
        $component->assertFormSet(['lower.text_template' => 'Live [year]']);
    }

    public function test_the_default_button_changes_only_the_form_and_stores_nothing(): void
    {
        $this->actingAsStaff('Administrator');
        $component = $this->page();
        $component->fillForm(['lower.text_template' => 'Something else', 'upper.padding' => 'spacious'])->callAction('save_draft');

        $component->callAction('default')->assertFormSet(['lower.text_template' => FooterLayout::default()['lower']['text_template'], 'upper.padding' => 'normal']);

        $stored = json_decode((string) $this->settings()->get(AppearanceSettings::FOOTER_DRAFT_KEY), true);
        $this->assertSame('Something else', $stored['lower']['text_template'], 'the stored draft is untouched until the merchant saves');
    }

    public function test_every_action_is_journalled_without_a_value(): void
    {
        $this->settings()->set('admin.activity_log_enabled', '1');
        $staff = $this->actingAsStaff('Administrator');

        $component = $this->page();
        $component->fillForm(['lower.text_template' => 'Secret 4242 [year]'])->callAction('save_draft');
        $component->callAction('publish');

        $rows = DB::table('activity_log')->where('entity_type', 'storefront_footer')->orderBy('id')->get();

        $this->assertSame(['draft', 'published'], $rows->pluck('field')->all());
        $this->assertSame([$staff->name, $staff->name], $rows->pluck('staff_name')->all());
        $this->assertSame(['saved', 'saved'], $rows->pluck('new_value')->all());
        $this->assertSame([], $rows->filter(fn ($r): bool => str_contains((string) $r->old_value.(string) $r->new_value, '4242'))->all());
    }

    public function test_a_low_contrast_pair_is_a_notice_and_never_blocks_saving(): void
    {
        $this->actingAsStaff('Administrator');

        $this->page()->fillForm(['upper.bg' => '#ffffff', 'upper.text' => '#eeeeee'])->callAction('publish')->assertHasNoFormErrors()->assertNotified();

        $this->assertNotNull($this->settings()->get(AppearanceSettings::FOOTER_PUBLISHED_KEY));
    }

    public function test_the_help_anchor_exists_in_both_languages_and_the_page_links_to_it(): void
    {
        foreach (['bg', 'en'] as $locale) {
            $this->assertStringContainsString('{#action-appearance-footer}', (string) file_get_contents(resource_path("help/{$locale}/appearance.md")));
        }

        $this->actingAsStaff('Administrator');
        $html = $this->get(Footer::getUrl())->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<a href="[^"]*/admin/help/appearance\#action-appearance-footer"#', $html);
        $this->assertStringContainsString('novalidate', $html, 'UX-0: the server validates, not the browser');
    }

    public function test_a_newsletter_block_shows_its_note_while_the_subscription_route_is_absent(): void
    {
        $this->actingAsStaff('Administrator');
        $menuId = (int) MenuModel::query()->where('key', 'footer')->value('id');
        $this->page()->set('data.upper', $this->ownersState($menuId)['upper'])->callAction('save_draft')->assertHasNoFormErrors();

        $html = $this->get(Footer::getUrl())->assertOk()->getContent();

        $this->assertStringContainsString('Shown on the site once newsletter subscription exists.', $html);
    }

    public function test_a_block_moves_to_the_previous_or_next_column_of_its_row_in_the_form_only(): void
    {
        $this->actingAsStaff('Administrator');
        $menuId = (int) MenuModel::query()->where('key', 'footer')->value('id');
        $state = $this->ownersState($menuId);
        $component = $this->page()->set('data.upper', $state['upper']);
        $base = 'data.upper.rows.r2.columns';

        // social (column c6) -> the next column c7 (payments, spacer), at its end
        $component->call('moveBlock', $base.'.c6.blocks', 's1', 1);
        $this->assertSame([], $component->get('data.upper.rows.r2.columns.c6.blocks'));
        $this->assertSame(['p1', 'p2', 's1'], array_keys($component->get('data.upper.rows.r2.columns.c7.blocks')));

        // and back to the previous one
        $component->call('moveBlock', $base.'.c7.blocks', 's1', -1);
        $this->assertSame(['s1'], array_keys($component->get('data.upper.rows.r2.columns.c6.blocks')));

        // the first column has no previous one: nothing moves
        $component->call('moveBlock', $base.'.c6.blocks', 's1', -1);
        $this->assertSame(['s1'], array_keys($component->get('data.upper.rows.r2.columns.c6.blocks')));
        $this->assertNull($this->settings()->get(AppearanceSettings::FOOTER_DRAFT_KEY), 'nothing is stored by moving');
    }

    public function test_a_full_column_does_not_take_one_more_block(): void
    {
        $this->actingAsStaff('Administrator');
        $menuId = (int) MenuModel::query()->where('key', 'footer')->value('id');
        $state = $this->ownersState($menuId);
        $full = [];

        foreach (range(1, 6) as $i) {
            $full['x'.$i] = $this->block('spacer', ['size' => 's']);
        }

        $state['upper']['rows']['r2']['columns']['c7']['blocks'] = $full;
        $component = $this->page()->set('data.upper', $state['upper']);

        $component->call('moveBlock', 'data.upper.rows.r2.columns.c6.blocks', 's1', 1);

        $this->assertSame(['s1'], array_keys($component->get('data.upper.rows.r2.columns.c6.blocks')), 'the block stays where it was');
        $this->assertCount(6, $component->get('data.upper.rows.r2.columns.c7.blocks'));
    }

    // UX-0: a value the person typed is the value they get back: rows, columns, blocks in every column, in the order they were given, both levels.
    public function test_every_field_survives_a_save_and_a_reload(): void
    {
        $this->actingAsStaff('Administrator');
        $menuId = (int) MenuModel::query()->where('key', 'footer')->value('id');
        $htmlBlock = HtmlBlockModel::create(['title' => 'Banner', 'body' => '<p>Banner</p>']);
        $state = $this->ownersState($menuId);
        // a non-default order: a text block and the HTML block before the menu in the first column, contacts in the last
        $state['upper']['rows']['r1']['columns']['c1']['blocks'] = [
            't1' => $this->block('text', ['heading' => 'Hi', 'body' => "Line one\nLine two"]),
            'h1' => $this->block('html_block', ['html_block' => $htmlBlock->id]),
            'm1' => $this->block('menu', ['menu' => null, 'heading' => 'Shop']),
        ];
        $state['upper']['rows']['r1']['columns']['c4']['blocks'] = ['k1' => $this->block('contacts', ['phone' => '+359 888 000 111', 'email' => 'a@b.test', 'address' => "Main 1\nTown", 'hours' => 'Mon-Fri'])];

        $this->page()->set('data.upper', $state['upper'])->set('data.lower', $state['lower'])->callAction('save_draft')->assertHasNoFormErrors();

        $types = fn (array $rows): array => array_map(fn (array $row): array => array_map(fn (array $column): array => [$column['span'], $column['align'], array_map(fn (array $b): string => $b['type'], array_values($column['blocks']))], array_values($row['columns'])), array_values($rows));
        $expected = $types($state['upper']['rows']);

        Livewire::test(Footer::class)->assertFormSet(function (array $reloaded) use ($state, $types, $expected, $menuId, $htmlBlock): void {
            $this->assertSame($expected, $types($reloaded['upper']['rows']), 'rows, columns and blocks in the order given');
            $this->assertEquals(['padding' => 'spacious', 'bg' => '#111111', 'text' => '#f5f5f5', 'border_color' => '#c0392b', 'border_width' => '2'], array_intersect_key($reloaded['upper'], array_flip(['padding', 'bg', 'text', 'border_color', 'border_width'])));
            $this->assertEquals($state['lower'], $reloaded['lower']);
            $firstColumn = array_values(array_values($reloaded['upper']['rows'])[0]['columns'])[0];
            $blocks = array_values($firstColumn['blocks']);
            $this->assertSame("Line one\nLine two", $blocks[0]['data']['body']);
            $this->assertEquals($htmlBlock->id, $blocks[1]['data']['html_block']);
            $this->assertNull($blocks[2]['data']['menu'], 'the menu of the Footer place');
            $payments = array_values(array_values(array_values($reloaded['upper']['rows'])[1]['columns'])[1]['blocks'])[0];
            $this->assertSame(['mastercard', 'visa', 'cash_on_delivery'], $payments['data']['methods'], 'the payment methods keep their order');
            $social = array_values(array_values(array_values($reloaded['upper']['rows'])[1]['columns'])[0]['blocks'])[0]['data'];
            $this->assertSame(['https://example.test/fb', 'https://example.test/ig', ''], [$social['n_facebook'], $social['n_instagram'], $social['n_tiktok']], 'one address field per network');
        });

        // PUBLISH: the shop shows both levels at once.
        Livewire::test(Footer::class)->callAction('publish')->assertHasNoFormErrors();
        $this->app->forgetScopedInstances();
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Fetish Харманли © '.now()->year.' – управляван от МериНед ООД', $html);
        $this->assertStringContainsString('class="sf-ft-upper sf-ft-pad-spacious"', $html);
        $this->assertStringContainsString('sf-ft-col sf-ft-span-2 sf-ft-align-end', $html);
        $this->assertStringContainsString('href="tel:+359888000111"', $html);
        $this->assertStringNotContainsString('sf-ft-newsletter', $html, 'no subscription route yet');
    }
}
