<?php

namespace Tests\Feature\Admin;

use App\Filament\NavigationGroup;
use App\Filament\Pages\Settings\AppearanceSettings as AppearancePage;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\AppearanceWriter;
use App\Storefront\Appearance\BannerDrafts;
use App\Storefront\Appearance\StorefrontAppearance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Stage D1: the admin page «Облик» / "Appearance": reached by the content permission, validates every value, stores only what differs from the defaults,
 * compiles the token stylesheet, REPLACES the reader's cache entry, warns about low contrast without blocking, and journals the section (never a value).
 */
class AppearanceSettingsPageTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    private const HELP_ANCHORS = ['action-appearance-colors', 'action-appearance-fonts', 'action-appearance-banner', 'action-appearance-themes', 'action-appearance-header', 'action-appearance-footer', 'action-appearance-menus', 'action-appearance-html-blocks', 'action-appearance-page', 'action-appearance-product-page', 'action-appearance-catalog', 'action-appearance-filters', 'action-appearance-summary', 'action-appearance-design-buttons'];

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
        return Livewire::test(AppearancePage::class);
    }

    // ---- access ----------------------------------------------------------------------------------------------------------------------

    public function test_the_content_roles_reach_the_page_and_a_role_without_the_permission_does_not(): void
    {
        foreach (['Administrator', 'Manager'] as $role) {
            $this->actingAsStaff($role);
            $this->get(AppearancePage::getUrl())->assertOk();
        }

        $this->actingAsStaff('Product Entry');
        $this->get(AppearancePage::getUrl())->assertForbidden();
    }

    public function test_it_sits_in_the_design_group_with_its_own_label(): void
    {
        $this->assertSame(NavigationGroup::DESIGN, AppearancePage::getNavigationGroup());
        $this->assertSame('Appearance', AppearancePage::getNavigationLabel());
        app()->setLocale('bg');
        $this->assertSame('Облик', AppearancePage::getNavigationLabel());
    }

    // ---- saving ----------------------------------------------------------------------------------------------------------------------

    public function test_saving_the_defaults_stores_nothing_compiles_nothing_and_replaces_the_cache_entry(): void
    {
        $this->actingAsStaff('Administrator');
        Cache::forever(StorefrontAppearance::CACHE_KEY, ['stale' => true]);

        $this->page()->call('save')->assertHasNoFormErrors();

        $this->assertSame(0, DB::table('site_settings')->where('key', 'like', 'appearance.%')->count());
        $this->assertNull($this->settings()->get(AppearanceSettings::COMPILED_KEY));
        $this->assertSame([], Storage::disk('public')->files('appearance'));
        $entry = Cache::get(StorefrontAppearance::CACHE_KEY);
        $this->assertArrayNotHasKey('stale', $entry, 'the old entry was REPLACED');
        $this->assertNull($entry['compiled']);
    }

    public function test_saving_changes_stores_only_what_differs_compiles_and_replaces_the_cache_entry(): void
    {
        $this->actingAsStaff('Administrator');

        $this->page()
            ->fillForm(['c_primary' => '#1A2B3C', 'c_header_bg' => '#112233', 'font_body' => 'inter', 'font_heading' => 'lora', 'radius' => 'lg'])
            ->call('save')->assertHasNoFormErrors();

        $stored = DB::table('site_settings')->where('key', 'like', 'appearance.%')->pluck('value', 'key')->all();
        ksort($stored);
        $compiled = json_decode((string) ($stored['appearance.compiled'] ?? ''), true);
        unset($stored['appearance.compiled']);

        $this->assertSame([
            'appearance.color.header_bg' => '#112233', 'appearance.color.primary' => '#1a2b3c', 'appearance.font.body' => 'inter', 'appearance.font.heading' => 'lora', 'appearance.radius' => 'lg',
        ], $stored, 'a value that holds its default is not stored');

        $this->assertSame(['inter', 'lora'], $compiled['fonts']);
        $this->assertTrue(Storage::disk('public')->exists('appearance/tokens-'.$compiled['hash'].'.css'));
        $this->assertStringContainsString('--sf-primary: #1a2b3c;', Storage::disk('public')->get('appearance/tokens-'.$compiled['hash'].'.css'));
        $this->assertSame($compiled['hash'], Cache::get(StorefrontAppearance::CACHE_KEY)['compiled']['hash'], 'the cache entry already shows the new stylesheet');
    }

    public function test_the_saved_values_come_back_on_the_form_and_going_back_to_a_default_forgets_the_key(): void
    {
        $this->actingAsStaff('Administrator');

        $this->page()->fillForm(['c_accent' => '#00aa00', 'button' => 'outline'])->call('save')->assertHasNoFormErrors();
        $this->app->forgetScopedInstances();
        $this->page()->assertFormSet(['c_accent' => '#00aa00', 'button' => 'outline', 'c_primary' => '#171717', 'radius' => 'md'])
            ->fillForm(['c_accent' => '#b91c1c', 'button' => 'solid'])->call('save')->assertHasNoFormErrors();

        $this->assertSame(0, DB::table('site_settings')->where('key', 'like', 'appearance.%')->count());
    }

    public function test_the_page_the_customer_sees_follows_the_save_at_once(): void
    {
        $this->actingAsStaff('Administrator');
        $this->page()->fillForm(['c_primary' => '#123456'])->call('save')->assertHasNoFormErrors();
        // OB-1: the banner is saved by the «Дизайн» screen's «Топбанер» zone, the way it is published there
        AppearanceWriter::save(array_replace(AppearanceWriter::current(), ['banner_enabled' => true, 'banner_text' => 'Saved today']));
        $this->app->forgetScopedInstances();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="/storage/appearance/tokens-[0-9a-f]{12}\.css">#', $html);
        $this->assertStringContainsString('<span class="sf-banner-text">Saved today</span>', $html);
    }

    // ---- validation ------------------------------------------------------------------------------------------------------------------

    public function test_a_colour_that_is_not_a_six_digit_hex_is_refused_and_nothing_is_written(): void
    {
        $this->actingAsStaff('Administrator');

        foreach (['red', '#fff', '#ffff;}', 'url(x)'] as $bad) {
            $this->page()->fillForm(['c_primary' => $bad])->call('save')->assertHasFormErrors(['c_primary']);
        }

        $this->assertSame(0, DB::table('site_settings')->where('key', 'like', 'appearance.%')->count());
    }

    // OB-1: the banner's draft and its validation moved to the «Дизайн» screen with the banner (DesignTopbarZoneTest); this page has no banner fields any more.
    public function test_a_save_of_the_page_leaves_a_banner_draft_of_the_design_screen_alone(): void
    {
        $this->actingAsStaff('Administrator');
        BannerDrafts::save(['enabled' => true, 'text' => 'Draft text', 'url' => '', 'link_label' => '', 'text_color' => null, 'bg_color' => null, 'starts_at' => null, 'ends_at' => null]);

        $this->page()->fillForm(['c_primary' => '#123456'])->call('save')->assertHasNoFormErrors();

        $this->assertNotNull($this->settings()->get(AppearanceSettings::BANNER_DRAFT_KEY), 'the draft is the Design screen\'s and stays');
    }

    // ---- contrast -------------------------------------------------------------------------------------------------------------------

    public function test_a_low_contrast_pair_shows_a_warning_and_a_good_one_does_not_and_saving_is_still_allowed(): void
    {
        $this->actingAsStaff('Administrator');

        $low = $this->page()->fillForm(['c_text' => '#777777', 'c_background' => '#ffffff']);
        $low->assertSee('Low contrast (4.48:1)');

        $good = $this->page()->fillForm(['c_text' => '#171717', 'c_background' => '#ffffff']);
        $good->assertDontSee('Low contrast');

        $low->call('save')->assertHasNoFormErrors();
        $this->assertSame('#777777', $this->settings()->get('appearance.color.text'), 'a warning, not a refusal');
        $this->assertEqualsWithDelta(4.48, AppearanceSettings::contrastRatio('#777777', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(21.0, AppearanceSettings::contrastRatio('#000000', '#ffffff'), 0.01);
    }

    public function test_the_default_page_has_no_contrast_warning_at_all(): void
    {
        $this->actingAsStaff('Administrator');

        $this->page()->assertDontSee('Low contrast');
    }

    // ---- the activity log -------------------------------------------------------------------------------------------------------------

    public function test_every_save_is_journalled_by_section_and_never_with_a_value(): void
    {
        $this->settings()->set('admin.activity_log_enabled', '1');
        $staff = $this->actingAsStaff('Administrator');

        $this->page()->fillForm(['c_primary' => '#1a2b3c'])->call('save')->assertHasNoFormErrors();

        $rows = DB::table('activity_log')->where('entity_type', 'storefront_appearance')->orderBy('id')->get();

        $this->assertSame(['colors'], $rows->pluck('field')->all(), 'one line for the section that changed; the font section did not');
        $this->assertSame(['saved'], $rows->pluck('new_value')->all());
        $this->assertSame([null], $rows->pluck('old_value')->all());
        $this->assertSame([$staff->name], $rows->pluck('staff_name')->all(), 'who');

        $dump = json_encode($rows->all());
        $this->assertStringNotContainsString('1a2b3c', (string) $dump);

        // A save that changes nothing writes nothing.
        $this->page()->call('save');
        $this->assertSame(1, DB::table('activity_log')->where('entity_type', 'storefront_appearance')->count());
    }

    // ---- UX-0: a value the person typed is the value they get back --------------------------------------------------------------------

    public function test_every_field_survives_a_save_and_a_reload(): void
    {
        $this->actingAsStaff('Administrator');
        $values = [
            'c_background' => '#f8fafc', 'c_text' => '#0f172a', 'c_muted' => '#475569', 'c_primary' => '#1d4ed8', 'c_on_primary' => '#f0f9ff', 'c_accent' => '#be123c',
            'c_border' => '#cbd5e1', 'c_header_bg' => '#f1f5f9', 'c_header_text' => '#111827', 'c_footer_bg' => '#e2e8f0', 'c_footer_text' => '#334155',
            'font_body' => 'inter', 'font_heading' => 'lora', 'radius' => 'lg', 'button' => 'outline',
        ];

        $this->page()->fillForm($values)->call('save')->assertHasNoFormErrors();
        $this->app->forgetScopedInstances();

        $this->page()->assertFormSet($values);

        // ...and the shop shows it at once (the cache entry was replaced).
        $html = $this->get('/')->assertOk()->getContent();
        preg_match('#appearance/(tokens-[0-9a-f]{12}\.css)#', $html, $m);
        $this->assertStringContainsString('--sf-primary: #1d4ed8;', Storage::disk('public')->get('appearance/'.$m[1]));
    }

    // ---- help -------------------------------------------------------------------------------------------------------------------------

    public function test_the_help_files_have_the_four_anchors_in_both_languages_and_the_page_links_to_three(): void
    {
        foreach (['bg', 'en'] as $locale) {
            $this->assertFileExists(resource_path("help/{$locale}/appearance.md"));
            preg_match_all('/^#{2,4} .*\{#([a-z0-9-]+)\} *$/m', (string) file_get_contents(resource_path("help/{$locale}/appearance.md")), $m);
            $this->assertSame(self::HELP_ANCHORS, $m[1], $locale);
        }

        $this->assertSame('Облик и хедър', trans('help.topics.appearance', [], 'bg'));
        $this->assertSame('Appearance and header', trans('help.topics.appearance', [], 'en'));

        $this->actingAsStaff('Administrator');
        $html = $this->get(AppearancePage::getUrl())->assertOk()->getContent();
        preg_match_all('#<a href="[^"]*/admin/help/appearance\#(action-[a-z-]+)"#', $html, $links);

        // OB-1: the page no longer links to the banner's help (the banner is edited in «Дизайн»)
        $this->assertSame(['action-appearance-colors', 'action-appearance-fonts'], $links[1]);

        foreach ($links[1] as $anchor) {
            $this->assertContains($anchor, self::HELP_ANCHORS);
        }
    }

    public function test_the_help_page_renders_the_new_topic_in_both_languages(): void
    {
        $this->actingAsStaff('Administrator');

        foreach (['bg', 'en'] as $locale) {
            app()->setLocale($locale);
            $html = $this->get(url('/admin/help/appearance'))->assertOk()->getContent();

            foreach (self::HELP_ANCHORS as $anchor) {
                $this->assertStringContainsString('id="'.$anchor.'"', $html, "{$locale}: {$anchor}");
            }
        }
    }
}
