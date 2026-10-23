<?php

namespace Tests\Feature\Admin\Design;

use App\Filament\Pages\Design\DesignScreen;
use App\Filament\Pages\Settings\AppearanceSettings as AppearancePage;
use App\Settings\AppearanceSettings as Appearance;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\AppearanceWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * DS-2b, the «Топбанер» zone: a form zone with no draft («Запази» saves live, like «Облик» always did). It stores through the SAME code as the «Облик» page, with the same rules, and the colours
 * and fonts the zone does not show stay as they are.
 */
class DesignTopbarZoneTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    private const INPUT = ['enabled' => true, 'text' => 'Free delivery over 100', 'url' => '/sale', 'link_label' => 'Shop now', 'text_color' => '#ffffff', 'bg_color' => '#aa0000', 'starts_at' => null, 'ends_at' => null];

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

        return Livewire::test(DesignScreen::class)->call('selectZone', 'topbar');
    }

    /** @param  array<string, mixed>  $values */
    private function fill(Testable $screen, array $values): Testable
    {
        foreach ($values as $key => $value) {
            $screen->set('props.'.$key, $value);
        }

        return $screen;
    }

    /** @return array<string, ?string> every banner value and the colour overrides, as stored */
    private function stored(): array
    {
        $keys = [Appearance::BANNER_ENABLED, Appearance::BANNER_TEXT, Appearance::BANNER_URL, Appearance::BANNER_LINK_LABEL, Appearance::BANNER_STARTS_AT, Appearance::BANNER_ENDS_AT, Appearance::BANNER_COLOR_TEXT, Appearance::BANNER_COLOR_BG, Appearance::COMPILED_KEY];

        return $this->settings()->getMany($keys);
    }

    // ---- the zone --------------------------------------------------------------------------------------------------------------------------

    public function test_it_shows_what_is_stored_now_and_has_no_palette_no_publish_and_a_save_button(): void
    {
        AppearanceWriter::save(array_replace(AppearanceWriter::current(), ['banner_enabled' => true, 'banner_text' => 'Stored text', 'c_banner_bg' => '#112233']));
        $screen = $this->screen();

        $screen->assertSet('props.text', 'Stored text')->assertSet('props.bg_color', '#112233')->assertSet('props.enabled', true);
        $html = $screen->html();
        $this->assertStringContainsString('Stored text', $html);
        $this->assertStringContainsString('Home page preview', $html);
        $this->assertStringNotContainsString('ds-palette', $html);
        $this->assertStringContainsString('Save', $html);
        $this->assertStringContainsString('Changes show in the shop at once.', $html);
        $screen->assertActionHidden('publish')->assertActionHidden('discard');
    }

    public function test_publish_and_discard_do_nothing_in_this_zone_even_when_called_directly(): void
    {
        $screen = $this->screen();
        $before = $this->stored();

        $screen->call('publish')->call('discard');

        $this->assertSame($before, $this->stored());
    }

    // ---- «Запази» = «Облик» ----------------------------------------------------------------------------------------------------------------

    public function test_save_stores_the_same_values_the_appearance_page_stores_for_the_same_input(): void
    {
        $this->fill($this->screen(), self::INPUT)->call('apply')->assertHasNoErrors();
        $fromScreen = $this->stored();

        foreach (array_keys($fromScreen) as $key) {
            $this->settings()->forget($key);
        }

        $this->assertNull($this->settings()->get(Appearance::BANNER_TEXT));
        Livewire::test(AppearancePage::class)->fillForm([
            'banner_enabled' => true, 'banner_text' => 'Free delivery over 100', 'banner_url' => '/sale', 'banner_link_label' => 'Shop now', 'c_banner_text' => '#ffffff', 'c_banner_bg' => '#aa0000',
        ])->call('save')->assertHasNoFormErrors();

        $this->assertSame($fromScreen, $this->stored());
        $this->assertSame('1', $fromScreen[Appearance::BANNER_ENABLED]);
        $this->assertSame('#aa0000', $fromScreen[Appearance::BANNER_COLOR_BG]);
        $this->assertNotNull($fromScreen[Appearance::COMPILED_KEY], 'the stylesheet is compiled as «Облик» does');
    }

    public function test_the_banner_shows_on_the_shop_at_once_and_the_stylesheet_holds_its_colour(): void
    {
        $this->fill($this->screen(), self::INPUT)->call('apply')->assertHasNoErrors();

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Free delivery over 100', $html);
        $this->assertStringContainsString('Shop now', $html);

        $compiled = json_decode((string) $this->settings()->get(Appearance::COMPILED_KEY), true);
        $this->assertStringContainsString('--sf-banner-bg: #aa0000;', Storage::disk('public')->get('appearance/tokens-'.$compiled['hash'].'.css'));
    }

    public function test_the_colours_and_fonts_the_zone_does_not_show_stay_as_they_are(): void
    {
        AppearanceWriter::save(array_replace(AppearanceWriter::current(), ['c_primary' => '#123456', 'radius' => 'lg']));
        $before = AppearanceWriter::current();

        $this->fill($this->screen(), self::INPUT)->call('apply')->assertHasNoErrors();

        $after = AppearanceWriter::current();
        $this->assertSame('#123456', $after['c_primary']);
        $this->assertSame($before['radius'], $after['radius']);
        $this->assertSame($before['font_body'], $after['font_body']);
        $this->assertSame('Free delivery over 100', $after['banner_text']);
    }

    public function test_the_dates_are_stored_and_an_end_before_the_start_is_refused(): void
    {
        $screen = $this->fill($this->screen(), self::INPUT + []);
        $screen->set('props.starts_at', '2026-11-01 10:00')->set('props.ends_at', '2026-11-30 23:59')->call('apply')->assertHasNoErrors();
        $this->assertSame('2026-11-01 10:00', $this->settings()->get(Appearance::BANNER_STARTS_AT));
        $this->assertSame('2026-11-30 23:59', $this->settings()->get(Appearance::BANNER_ENDS_AT));

        $screen->set('props.ends_at', '2026-10-01 10:00')->call('apply')->assertHasErrors(['props.ends_at']);
        $this->assertSame('2026-11-30 23:59', $this->settings()->get(Appearance::BANNER_ENDS_AT), 'the refused value is not stored');
    }

    // ---- refusals --------------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badInputs(): array
    {
        return [
            'an unsafe link' => [['url' => 'javascript:alert(1)'], 'url'],
            'a link with http' => [['url' => 'http://example.test/x'], 'url'],
            'a colour that is not hex' => [['bg_color' => 'red'], 'bg_color'],
            'a short hex' => [['text_color' => '#fff'], 'text_color'],
            'a text over 160 characters' => [['text' => 'x'.str_repeat('y', 160)], 'text'],
            'a text with a control character' => [['text' => "a\x07b"], 'text'],
            'no text while the banner is on' => [['text' => ''], 'text'],
            'a link label over 40 characters' => [['link_label' => str_repeat('l', 41)], 'link_label'],
            'a start that is not a date' => [['starts_at' => 'tomorrow'], 'starts_at'],
        ];
    }

    /** @param  array<string, mixed>  $change */
    #[\PHPUnit\Framework\Attributes\DataProvider('badInputs')]
    public function test_a_bad_value_is_refused_on_its_own_field_and_nothing_is_stored(array $change, string $field): void
    {
        $before = $this->stored();

        $this->fill($this->screen(), array_replace(self::INPUT, $change))->call('apply')->assertHasErrors(['props.'.$field]);

        $this->assertSame($before, $this->stored());
    }

    public function test_the_boundaries_are_accepted(): void
    {
        $this->fill($this->screen(), array_replace(self::INPUT, ['text' => str_repeat('t', 160), 'link_label' => str_repeat('l', 40), 'url' => 'https://example.test/x']))->call('apply')->assertHasNoErrors();

        $this->assertSame(160, mb_strlen((string) $this->settings()->get(Appearance::BANNER_TEXT)));
    }

    public function test_a_low_contrast_pair_is_a_notice_and_never_blocks_the_save(): void
    {
        $this->fill($this->screen(), array_replace(self::INPUT, ['text_color' => '#fefefe', 'bg_color' => '#ffffff']))->call('apply')->assertHasNoErrors()->assertNotified();

        $this->assertSame('#ffffff', $this->settings()->get(Appearance::BANNER_COLOR_BG));
    }

    // ---- journal and access ----------------------------------------------------------------------------------------------------------------

    public function test_the_save_is_journalled_like_the_appearance_pages_with_no_value(): void
    {
        $this->settings()->set('admin.activity_log_enabled', '1');
        $this->fill($this->screen(), self::INPUT)->call('apply');

        $rows = DB::table('activity_log')->where('entity_type', 'storefront_appearance')->orderBy('id')->get();

        $this->assertContains('banner', $rows->pluck('field')->all());
        $this->assertSame(['saved'], $rows->pluck('new_value')->unique()->values()->all());
    }

    public function test_a_user_without_the_permission_cannot_save(): void
    {
        $screen = $this->screen();
        $this->app->forgetScopedInstances();
        $this->actingAsStaff('Product Entry');

        $screen->call('apply')->assertForbidden();

        $this->assertNull($this->settings()->get(Appearance::BANNER_TEXT));
    }
}
