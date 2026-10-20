<?php

namespace Tests\Feature\Admin;

use App\Filament\NavigationGroup;
use App\Filament\Pages\Settings\HeaderSettings;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Header\HeaderLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Stage D2: the admin page «Хедър» / "Header": reached by the content permission, saves a DRAFT, publishes (compiles, replaces the reader's cache entry),
 * discards the draft, maps the validator's messages onto form fields, journals the action (never a value) and links to its help anchor.
 */
class HeaderSettingsPageTest extends TestCase
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
        return Livewire::test(HeaderSettings::class);
    }

    public function test_the_content_roles_reach_the_page_and_a_role_without_the_permission_does_not(): void
    {
        foreach (['Administrator', 'Manager'] as $role) {
            $this->actingAsStaff($role);
            $this->get(HeaderSettings::getUrl())->assertOk();
        }

        $this->actingAsStaff('Product Entry');
        $this->get(HeaderSettings::getUrl())->assertForbidden();
    }

    public function test_it_sits_in_the_design_group_with_its_own_label(): void
    {
        $this->assertSame(NavigationGroup::DESIGN, HeaderSettings::getNavigationGroup());
        $this->assertSame('Header', HeaderSettings::getNavigationLabel());
        app()->setLocale('bg');
        $this->assertSame('Хедър', HeaderSettings::getNavigationLabel());
    }

    public function test_it_starts_from_the_seeded_default_and_saves_a_draft_that_is_not_public(): void
    {
        $this->actingAsStaff('Administrator');

        $this->page()->fillForm(['desktop.rows.main.height' => 52])->callAction('save_draft')->assertHasNoFormErrors();

        $draft = json_decode((string) $this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY), true);
        $this->assertSame(52, $draft['desktop']['rows']['main']['height']);
        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY), 'a draft is not published');
        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_PUBLISHED_KEY));
    }

    public function test_publishing_stores_compiles_and_replaces_the_cache_entry(): void
    {
        $this->actingAsStaff('Administrator');
        \Illuminate\Support\Facades\Cache::forever(StorefrontAppearance::CACHE_KEY, ['stale' => true]);

        $this->page()->fillForm(['desktop.rows.main.height' => 52])->callAction('publish')->assertHasNoFormErrors();

        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY), true);
        $this->assertSame(52, $compiled['layout']['desktop']['rows']['main']['height']);
        $this->assertNotNull($this->settings()->get(AppearanceSettings::HEADER_PUBLISHED_KEY));
        $this->assertArrayNotHasKey('stale', \Illuminate\Support\Facades\Cache::get(StorefrontAppearance::CACHE_KEY) ?? []);
        $this->assertNotNull($this->settings()->get(AppearanceSettings::COMPILED_KEY), 'the 52px is a variable in the token stylesheet');
    }

    public function test_a_range_error_is_shown_on_its_own_field_and_nothing_is_stored(): void
    {
        $this->actingAsStaff('Administrator');

        $this->page()->fillForm(['desktop.rows.main.height' => 5])->call('publish')->assertHasErrors(['data.desktop.rows.main.height']);

        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY));
        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_PUBLISHED_KEY));
    }

    public function test_discard_forgets_the_draft_and_goes_back_to_the_published_layout(): void
    {
        $this->actingAsStaff('Administrator');
        $component = $this->page();
        $component->fillForm(['desktop.rows.main.height' => 52])->callAction('publish');
        $component->fillForm(['desktop.rows.main.height' => 60])->callAction('save_draft');
        $this->assertNotNull($this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY));

        $component->callAction('discard');

        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY));
        $component->assertFormSet(['desktop.rows.main.height' => 52]);
        $this->assertSame(64, HeaderLayout::default()['desktop']['rows']['main']['height']);
    }

    public function test_every_action_is_journalled_without_a_value(): void
    {
        $this->settings()->set('admin.activity_log_enabled', '1');
        $staff = $this->actingAsStaff('Administrator');

        $component = $this->page();
        $component->fillForm(['desktop.rows.main.height' => 53])->callAction('save_draft');
        $component->callAction('publish');

        $rows = DB::table('activity_log')->where('entity_type', 'storefront_header')->orderBy('id')->get();

        $this->assertSame(['draft', 'published'], $rows->pluck('field')->all());
        $this->assertSame([$staff->name, $staff->name], $rows->pluck('staff_name')->all());
        // The value must not be journalled. Only the value columns are looked at: the row also holds a timestamp, and a clock that reads ...:53 must not fail the test.
        $this->assertSame([null, null], [$rows->pluck('old_value')->filter(fn ($v) => $v !== null && str_contains((string) $v, '53'))->first(), $rows->pluck('new_value')->filter(fn ($v) => str_contains((string) $v, '53'))->first()]);
        $this->assertSame(['saved', 'saved'], $rows->pluck('new_value')->all());
    }

    public function test_the_help_anchor_exists_in_both_languages_and_the_page_links_to_it(): void
    {
        foreach (['bg', 'en'] as $locale) {
            $this->assertStringContainsString('{#action-appearance-header}', (string) file_get_contents(resource_path("help/{$locale}/appearance.md")));
        }

        $this->actingAsStaff('Administrator');
        $html = $this->get(HeaderSettings::getUrl())->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<a href="[^"]*/admin/help/appearance\#action-appearance-header"#', $html);
    }

    // UX-0: a value the person typed is the value they get back: rows, heights, elements in every slot, in the order they were given, the drawer and its side.
    public function test_every_field_survives_a_save_and_a_reload(): void
    {
        $this->actingAsStaff('Administrator');
        $block = fn (string $type, array $data): array => ['type' => $type, 'data' => $data];
        $row = fn (bool $enabled, int $height, array $left = [], array $center = [], array $right = []): array => ['enabled' => $enabled, 'height' => $height, 'left' => $left, 'center' => $center, 'right' => $right];
        $state = [
            'desktop' => ['sticky' => false, 'rows' => [
                'top' => $row(true, 40, ['t1' => $block('button', ['label' => 'Виж', 'url' => '/sale', 'style' => 'outline'])], ['t2' => $block('spacer', ['width' => 'lg'])], ['t3' => $block('text', ['text' => '0888 123 456', 'url' => '/contacts'])]),
                'main' => $row(true, 52, ['m1' => $block('menu', ['style' => 'dropdown'])], ['m2' => $block('logo', ['height' => 24])], ['m3' => $block('cart', ['style' => 'icon_label']), 'm4' => $block('search', ['mode' => 'field'])]),
                'bottom' => $row(true, 48, ['b1' => $block('social', ['items' => [['network' => 'facebook', 'url' => 'https://example.test/fb'], ['network' => 'instagram', 'url' => 'https://example.test/ig']]])], ['b2' => $block('html', ['html' => '<p>Одит <b>блок</b></p>'])], ['b3' => $block('text', ['text' => 'Долен текст', 'url' => ''])]),
            ]],
            'mobile' => ['sticky' => false, 'rows' => [
                'top' => $row(true, 36, ['p1' => $block('text', ['text' => 'Горе', 'url' => ''])]),
                'main' => $row(true, 50, ['p2' => $block('menu_toggle', ['label_visible' => true])], ['p3' => $block('logo', ['height' => 30])], ['p4' => $block('cart', ['style' => 'icon'])]),
            ], 'drawer' => ['d1' => $block('text', ['text' => 'Чекмедже', 'url' => '']), 'd2' => $block('menu', ['style' => 'links'])], 'drawer_side' => 'right'],
        ];

        Livewire::test(HeaderSettings::class)->set('data.desktop', $state['desktop'])->set('data.mobile', $state['mobile'])->callAction('save_draft')->assertHasNoFormErrors();

        $normalise = function (array $slots): array {
            $out = [];

            foreach ($slots as $name => $blocks) {
                $out[$name] = is_array($blocks) ? array_map(fn (array $b): array => [$b['type'], isset($b['data']['items']) ? ['items' => array_values($b['data']['items'])] : $b['data']], array_values($blocks)) : $blocks;
            }

            return $out;
        };
        $expected = fn (array $layout): array => [
            'sticky' => $layout['sticky'],
            'rows' => array_map(fn (array $r): array => ['enabled' => $r['enabled'], 'height' => $r['height']] + $normalise(array_intersect_key($r, array_flip(['left', 'center', 'right']))), $layout['rows']),
        ];

        Livewire::test(HeaderSettings::class)->assertFormSet(function (array $reloaded) use ($state, $normalise, $expected): void {
            foreach (['desktop', 'mobile'] as $name) {
                $got = ['sticky' => $reloaded[$name]['sticky'], 'rows' => array_map(fn (array $r): array => ['enabled' => $r['enabled'], 'height' => $r['height']] + $normalise(array_intersect_key($r, array_flip(['left', 'center', 'right']))), $reloaded[$name]['rows'])];
                $this->assertEquals($expected($state[$name]), $got, $name.' (elements and heights, in order)');
            }

            $this->assertEquals($normalise(['d' => $state['mobile']['drawer']])['d'], $normalise(['d' => $reloaded['mobile']['drawer']])['d'], 'the drawer, in order');
            $this->assertSame('right', $reloaded['mobile']['drawer_side']);
        });

        // PUBLISH: the shop shows it at once.
        Livewire::test(HeaderSettings::class)->callAction('publish')->assertHasNoFormErrors();
        $this->app->forgetScopedInstances();
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('0888 123 456', $html);
        $this->assertStringContainsString('Одит <b>блок</b>', $html);
        $this->assertStringContainsString('Чекмедже', $html);
    }
}
