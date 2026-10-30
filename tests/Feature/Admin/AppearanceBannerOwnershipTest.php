<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\Settings\AppearanceSettings as AppearancePage;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\AppearanceWriter;
use App\Storefront\Appearance\BannerDrafts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * OB-1: the top banner is edited in the «Дизайн» screen only. «Облик» shows no banner section and a save of «Облик» leaves every stored banner value and banner colour exactly as it is; the
 * storefront prints the banner as before; the help and the lang files no longer send anybody to a banner section of «Облик».
 */
class AppearanceBannerOwnershipTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        Storage::fake('public');
        app()->setLocale('en');
        $this->actingAsStaff('Administrator');
    }

    /** @return array<string, ?string> every stored banner value: the published keys, both colours and the draft */
    private function bannerRows(): array
    {
        return app(SiteSettingsRepository::class)->getMany([
            AppearanceSettings::BANNER_ENABLED, AppearanceSettings::BANNER_TEXT, AppearanceSettings::BANNER_URL, AppearanceSettings::BANNER_LINK_LABEL,
            AppearanceSettings::BANNER_STARTS_AT, AppearanceSettings::BANNER_ENDS_AT, AppearanceSettings::BANNER_COLOR_TEXT, AppearanceSettings::BANNER_COLOR_BG,
            AppearanceSettings::BANNER_DRAFT_KEY,
        ]);
    }

    /** The banner as the «Дизайн» screen's «Топбанер» zone publishes it (the same writer call as TopbarZone::publish). */
    private function publishBannerFromDesign(array $banner): void
    {
        AppearanceWriter::save(array_replace(AppearanceWriter::current(), $banner));
        $this->app->forgetScopedInstances();
    }

    private function homePage(): string
    {
        $this->app->forgetScopedInstances();

        return $this->get('/')->assertOk()->getContent();
    }

    // 1. the page

    public function test_the_page_has_no_banner_section_and_points_to_design(): void
    {
        $html = Livewire::test(AppearancePage::class)->html();

        foreach (['name="banner_text"', 'name="banner_enabled"', 'name="c_banner_bg"', 'name="c_banner_text"', 'Show the banner', 'Top banner', 'action-appearance-banner'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, $gone);
        }

        $this->assertStringContainsString('The top banner is edited in Design.', $html);
        $this->assertStringContainsString('/admin/design?zone=topbar', $html);
    }

    // 2. a save of the page writes no banner value

    public function test_a_save_of_the_page_leaves_every_banner_value_and_colour_exactly_as_stored(): void
    {
        $this->publishBannerFromDesign(['banner_enabled' => true, 'banner_text' => 'Kept text', 'banner_url' => '/cart', 'banner_link_label' => 'Go', 'banner_starts_at' => '2026-01-01 00:00', 'banner_ends_at' => '2030-01-01 00:00', 'c_banner_text' => '#fefce8', 'c_banner_bg' => '#1e3a8a']);
        BannerDrafts::save(['enabled' => true, 'text' => 'Draft text', 'url' => '', 'link_label' => '', 'text_color' => null, 'bg_color' => null, 'starts_at' => null, 'ends_at' => null]);
        $before = $this->bannerRows();
        $this->assertSame('#1e3a8a', $before[AppearanceSettings::BANNER_COLOR_BG]);

        Livewire::test(AppearancePage::class)->fillForm(['c_primary' => '#123456'])->call('save')->assertHasNoFormErrors();

        $this->assertSame($before, $this->bannerRows(), 'every banner value, colour and the draft are byte-for-byte what they were');

        // the stylesheet still carries the banner colours, so the shop's banner looks the same
        $html = $this->homePage();
        preg_match('#appearance/(tokens-[0-9a-f]{12}\.css)#', $html, $m);
        $css = (string) Storage::disk('public')->get('appearance/'.$m[1]);
        $this->assertStringContainsString('--sf-banner-bg: #1e3a8a;', $css);
        $this->assertStringContainsString('--sf-primary: #123456;', $css, 'and the page\'s own change is there too');
    }

    // 3. the storefront prints the banner as before, and the Design zone still publishes it

    public function test_the_shop_prints_the_published_banner_and_none_when_it_is_off(): void
    {
        $this->publishBannerFromDesign(['banner_enabled' => true, 'banner_text' => 'Free delivery', 'banner_url' => '/cart', 'banner_link_label' => 'See']);

        $html = $this->homePage();

        $this->assertMatchesRegularExpression('#<span class="sf-banner-text">Free delivery</span>\s*<a class="sf-banner-link" href="/cart">See</a>#', $html);
        $this->assertSame(1, substr_count($html, 'data-sf-banner'), 'printed once');

        $this->publishBannerFromDesign(['banner_enabled' => false]);

        $this->assertStringNotContainsString('data-sf-banner', $this->homePage());
    }

    public function test_the_design_top_banner_zone_still_edits_the_same_banner(): void
    {
        $screen = Livewire::test(\App\Filament\Pages\Design\DesignScreen::class)->call('selectZone', 'topbar');
        foreach (['enabled' => true, 'text' => 'From Design', 'url' => '', 'link_label' => '', 'text_color' => null, 'bg_color' => null, 'starts_at' => null, 'ends_at' => null] as $key => $value) {
            $screen->set('props.'.$key, $value);
        }
        $screen->call('apply')->call('publish')->assertHasNoErrors();

        $this->assertSame('From Design', app(SiteSettingsRepository::class)->get(AppearanceSettings::BANNER_TEXT));
    }

    // 4. help and lang

    public function test_the_banner_help_says_where_it_is_edited_and_the_lang_has_no_banner_section_left(): void
    {
        foreach (['bg' => 'Дизайн', 'en' => 'Design'] as $locale => $word) {
            $help = (string) file_get_contents(resource_path("help/{$locale}/appearance.md"));
            preg_match('/^## [^\n]*\{#action-appearance-banner\}\n(.*?)(?=^## |\z)/ms', $help, $section);

            $this->assertNotEmpty($section, $locale);
            $this->assertStringContainsString($word, $section[1], "the banner section names Design in {$locale}");
        }

        $this->assertArrayNotHasKey('banner', __('appearance.sections'));
        $this->assertArrayNotHasKey('banner_help', __('appearance.sections'));
        $this->assertSame('The top banner is edited in Design.', __('appearance.banner_moved'));

        app()->setLocale('bg');
        $this->assertSame('Горният банер се редактира в „Дизайн“.', __('appearance.banner_moved'));
        app()->setLocale('en');

        // the field and colour labels stay: the Design zone uses them
        $this->assertNotSame('appearance.fields.banner_text', __('appearance.fields.banner_text'));
        $this->assertNotSame('appearance.colors.banner_bg', __('appearance.colors.banner_bg'));
    }
}
