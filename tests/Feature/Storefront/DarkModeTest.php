<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\Storefront\CookiePreviewController;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\StoreTimezone;
use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\Appearance\AppearanceWriter;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Cache\CachePurgeCollector;
use App\Storefront\Cache\CachePurger;
use App\Storefront\Cache\RecordingPurger;
use App\Storefront\Cookies\Contrast;
use App\Storefront\Header\AppearanceRecompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * DM-1 (dark-mode.md): the foundation of the storefront's dark mode. The settings, the dev flag, the payload, the `data-theme` attribute, the compiler rule (which colours win) and the contrast rules. NO selector
 * is migrated in DM-1: what these tests pin is that LIGHT mode is byte-identical to what it was (fixtures/dm1-light-css.json was taken from the code BEFORE the change) and that dark mode is switched by
 * the merchant alone, site-wide, for everyone, only while the dev flag is on.
 */
class DarkModeTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_VARIABLES = ['--sf-bg:', '--sf-text:', '--sf-muted:', '--sf-primary:', '--sf-on-primary:', '--sf-accent:', '--sf-border:', '--sf-header-bg:', '--sf-header-text:', '--sf-footer-bg:', '--sf-footer-text:', '--sf-banner-text:', '--sf-banner-bg:'];

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        Storage::fake('public');
        app()->setLocale('en');
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function flag(bool $on): void
    {
        config(['storefront.dark_mode' => $on]);
    }

    private function css(array $overrides): ?string
    {
        return app(AppearanceCompiler::class)->css($overrides);
    }

    /** every light-mode sample taken from the code before DM-1 */
    private function fixtures(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/dm1-light-css.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function cssOf(array $sample, array $extra = []): ?string
    {
        $zones = $sample['zones'];

        return app(AppearanceCompiler::class)->css($extra + $sample['overrides'], [], [], $zones['footer'] ?? [], $zones['header'] ?? [], [], [], [], $zones['buy'] ?? []);
    }

    private function darkSet(array $extra = []): array
    {
        return [AppearanceSettings::MODE => 'dark'] + $extra;
    }

    // ---- LIGHT mode is byte-identical --------------------------------------------------------------------------------------------------

    public function test_light_mode_output_is_byte_identical_to_the_snapshot_taken_before_the_change_with_the_flag_on_or_off(): void
    {
        $fixtures = $this->fixtures();
        $this->assertCount(5, $fixtures);

        foreach ([false, true] as $flag) {
            $this->flag($flag);

            foreach ($fixtures as $name => $sample) {
                $this->assertSame($sample['css'], $this->cssOf($sample), "{$name}, flag ".json_encode($flag));
                // an explicit `light`, and a dark button colour that is set but unused, change nothing
                $this->assertSame($sample['css'], $this->cssOf($sample, [AppearanceSettings::MODE => 'light', AppearanceSettings::DARK_BUTTON => '#ffffff']), "{$name} explicit light, flag ".json_encode($flag));
            }
        }
    }

    public function test_an_untouched_light_shop_compiles_to_nothing(): void
    {
        $this->flag(true);

        $this->assertNull($this->css([]));
        $this->assertNull($this->css([AppearanceSettings::MODE => 'light']));
        $this->assertNull($this->css([AppearanceSettings::DARK_BUTTON => '#ffffff']), 'a button colour alone is nothing while the mode is light');
        $this->assertNull(app(AppearanceCompiler::class)->compile([]));
    }

    // ---- DARK mode: which colours win ------------------------------------------------------------------------------------------------------

    public function test_in_dark_mode_the_13_base_tokens_are_never_emitted_but_fonts_radius_and_every_zone_list_are_as_before(): void
    {
        $this->flag(true);
        $sample = $this->fixtures()['all_colors'];
        $light = (string) $this->cssOf($sample);
        $this->assertNotNull($light);

        foreach (array_diff(self::BASE_VARIABLES, ['--sf-banner-text:']) as $variable) {   // the sample's banner text is the default (#ffffff): never written
            $this->assertStringContainsString($variable, $light, 'light emits '.$variable);
        }

        $dark = (string) $this->cssOf($sample, $this->darkSet());

        foreach (self::BASE_VARIABLES as $variable) {
            $this->assertStringNotContainsString($variable, $dark, 'dark must not emit '.$variable);
        }

        // fonts + radius + button style + zones are compiled exactly as in light mode
        $mixed = $this->fixtures()['fonts_radius_outline'];
        $zones = $this->fixtures()['zones'];
        $lightMixed = (string) $this->cssOf($mixed);
        $darkMixed = (string) $this->cssOf($mixed, $this->darkSet());

        foreach (['--sf-font-body:', '--sf-font-heading:', '--sf-r-sm:', '--sf-btn-bg: transparent;', '--sf-btn-bw: 2px;', '@font-face'] as $needle) {
            $this->assertStringContainsString($needle, $lightMixed);
            $this->assertStringContainsString($needle, $darkMixed, $needle.' survives in dark mode');
        }

        $this->assertStringNotContainsString('--sf-primary: #0f766e', $darkMixed, 'the merchant\'s light primary is not compiled in dark mode');
        $this->assertStringNotContainsString('--sf-primary-hover', $darkMixed);

        $darkZones = (string) $this->cssOf($zones, $this->darkSet());
        $this->assertStringContainsString('--sf-ftu-bg: #abcdef;', $darkZones, 'a footer colour the merchant set stays');
        $this->assertStringContainsString('--sf-buy-bg: #112233;', $darkZones, 'the buy button stays');
        $this->assertStringContainsString('--sf-hdr-d-main-bg: #445566;', $darkZones, 'a header row colour stays');
        $this->assertStringContainsString('--sf-r-btn: 9999px;', $darkZones);
    }

    public function test_the_dark_button_override_is_written_only_when_it_differs_from_the_default_with_the_computed_text_and_hover(): void
    {
        $this->flag(true);

        $this->assertNull($this->css($this->darkSet()), 'dark with the default button and nothing else: the dark palette is in storefront.css, no stylesheet');
        $this->assertNull($this->css($this->darkSet([AppearanceSettings::DARK_BUTTON => AppearanceSettings::DEFAULT_DARK_BUTTON])));

        $white = (string) $this->css($this->darkSet([AppearanceSettings::DARK_BUTTON => '#FFFFFF']));
        $this->assertStringContainsString("html[data-theme='dark'] {", $white);
        $this->assertStringContainsString('--sf-primary: #ffffff;', $white, 'lower case');
        $this->assertStringContainsString('--sf-on-primary: '.Contrast::bestText('#ffffff')['color'].';', $white, 'the text colour is computed');
        $this->assertStringContainsString('--sf-primary-hover: #ffffff;', $white);
        $this->assertStringNotContainsString(':root', $white);

        $blue = (string) $this->css($this->darkSet([AppearanceSettings::DARK_BUTTON => '#3b82f6']));
        $this->assertStringContainsString('--sf-on-primary: '.Contrast::bestText('#3b82f6')['color'].';', $blue);
        $this->assertMatchesRegularExpression('/--sf-primary-hover: #[0-9a-f]{6};/', $blue);
    }

    public function test_a_dark_button_colour_that_fails_the_contrast_rules_or_is_not_a_colour_is_dropped(): void
    {
        $this->flag(true);

        $this->assertNull($this->css($this->darkSet([AppearanceSettings::DARK_BUTTON => '#333333'])), 'a dark grey fails 3:1 against the black page: dropped, the default stays');

        // the stored path (the recompiler) drops a value that is not a colour instead of failing the whole stylesheet
        $this->settings()->set(AppearanceSettings::MODE, 'dark');
        $this->settings()->set(AppearanceSettings::DARK_BUTTON, 'orange; } body { display:none');
        app(AppearanceRecompiler::class)->run($this->settings());
        $this->assertNull($this->settings()->get(AppearanceSettings::COMPILED_KEY), 'nothing compiled: dark, default button');

        $this->expectException(InvalidArgumentException::class);
        $this->css($this->darkSet([AppearanceSettings::DARK_BUTTON => 'orange']));
    }

    public function test_a_stored_dark_mode_has_no_effect_on_the_stylesheet_while_the_flag_is_off(): void
    {
        $this->flag(false);
        $sample = $this->fixtures()['all_colors'];

        $this->assertSame($sample['css'], $this->cssOf($sample, $this->darkSet([AppearanceSettings::DARK_BUTTON => '#ffffff'])));
    }

    public function test_a_flip_of_the_mode_or_of_the_button_colour_makes_a_new_stylesheet_name(): void
    {
        $this->flag(true);
        $fonts = [AppearanceSettings::FONT_BODY => 'inter'];

        $light = app(AppearanceCompiler::class)->compile($fonts)['hash'];
        $dark = app(AppearanceCompiler::class)->compile($fonts + $this->darkSet([AppearanceSettings::DARK_BUTTON => '#ffffff']))['hash'];
        $other = app(AppearanceCompiler::class)->compile($fonts + $this->darkSet([AppearanceSettings::DARK_BUTTON => '#3b82f6']))['hash'];

        $this->assertCount(3, array_unique([$light, $dark, $other]));
        $this->assertSame($dark, app(AppearanceCompiler::class)->compile($fonts + $this->darkSet([AppearanceSettings::DARK_BUTTON => '#FFFFFF']))['hash'], 'a content hash');
    }

    // ---- the contrast rules --------------------------------------------------------------------------------------------------------------

    public function test_the_shipped_dark_defaults_in_the_stylesheet_match_what_the_compiler_computes(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/storefront.css'));
        $this->assertSame(1, preg_match("/html\\[data-theme='dark'\\] \\{(.*?)\\n\\}/s", $css, $block));
        $default = AppearanceSettings::DEFAULT_DARK_BUTTON;

        $this->assertStringContainsString("--sf-primary: {$default};", $block[1]);
        $this->assertStringContainsString('--sf-on-primary: '.Contrast::bestText($default)['color'].';', $block[1], 'the shipped text colour is the computed one');
        $this->assertStringContainsString('--sf-bg: '.Contrast::DARK_BACKGROUND.';', $block[1]);

        // the hover the compiler would write for the default colour is the one shipped

        $reflection = new \ReflectionMethod(AppearanceCompiler::class, 'towardsWhite');
        $hover = $reflection->invoke(new AppearanceCompiler, $default, 0.1767);
        $this->assertStringContainsString("--sf-primary-hover: {$hover};", $block[1]);
    }

    public function test_the_light_fallbacks_of_the_new_tokens_are_the_documented_values(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/storefront.css'));
        $this->assertSame(1, preg_match('/@theme static \{(.*?)\n\}/s', $css, $block));

        $expected = [
            'surface' => '#ffffff', 'surface-2' => '#f5f5f5', 'border-strong' => '#d4d4d4', 'focus' => '#0369a1', 'error' => '#b91c1c',
            'overlay' => 'rgb(0 0 0 / 0.45)', 'shadow' => 'rgb(0 0 0 / 0.12)',
            'status-error-bg' => 'oklch(97.1% 0.013 17.38)', 'status-error-text' => 'oklch(39.6% 0.141 25.723)', 'status-error-border' => 'oklch(80.8% 0.114 19.571)',
            'status-warn-bg' => 'oklch(98.7% 0.022 95.277)', 'status-warn-text' => 'oklch(41.4% 0.112 45.904)', 'status-warn-border' => 'oklch(87.9% 0.169 91.605)',
            'status-info-bg' => 'oklch(97.7% 0.013 236.62)', 'status-info-text' => 'oklch(39.1% 0.09 240.876)', 'status-info-border' => 'oklch(82.8% 0.111 230.318)',
            'status-ok-bg' => 'oklch(98.2% 0.018 155.826)', 'status-ok-text' => 'oklch(44.8% 0.119 151.328)', 'status-ok-border' => 'oklch(92.5% 0.084 155.995)',
        ];

        foreach ($expected as $name => $value) {
            $this->assertStringContainsString("--color-{$name}: var(--sf-{$name}, {$value});", $block[1], "the light fallback of {$name}");
        }

        $this->assertSame(count($expected), substr_count($block[1], '--color-'), 'no token without a pinned fallback');
    }

    // ---- the payload -------------------------------------------------------------------------------------------------------------------------

    public function test_the_payload_carries_the_validated_stored_mode(): void
    {
        $this->assertSame('light', StorefrontAppearance::build([])['mode']);
        $this->assertSame('dark', StorefrontAppearance::build([AppearanceSettings::MODE => 'dark'])['mode']);

        foreach (['DARK', 'blue', '', ' dark', '1', null] as $bad) {
            $this->assertSame('light', StorefrontAppearance::build([AppearanceSettings::MODE => $bad])['mode'], json_encode($bad));
        }
    }

    public function test_the_layout_gets_dark_only_for_a_stored_dark_with_the_flag_on(): void
    {
        $this->settings()->set(AppearanceSettings::MODE, 'dark');
        app(StorefrontAppearance::class)->refresh();

        $this->flag(true);
        $this->assertSame('dark', app(StorefrontAppearance::class)->forLayout()['mode']);
        $this->assertSame('dark', app(StorefrontAppearance::class)->mode());

        $this->flag(false);
        $this->assertNull(app(StorefrontAppearance::class)->forLayout()['mode'], 'a stored dark has zero effect while the flag is off, even from a payload cached while it was on');

        $this->flag(true);
        $this->settings()->set(AppearanceSettings::MODE, 'blue');
        app(StorefrontAppearance::class)->refresh();
        $this->assertNull(app(StorefrontAppearance::class)->forLayout()['mode'], 'an invalid stored value is light');
    }

    public function test_an_old_cached_payload_without_a_mode_is_rebuilt(): void
    {
        $this->flag(true);
        app(StorefrontAppearance::class)->refresh();
        $old = Cache::get(StorefrontAppearance::CACHE_KEY);
        $this->assertArrayHasKey('mode', $old);
        unset($old['mode']);
        Cache::forever(StorefrontAppearance::CACHE_KEY, $old);

        $this->settings()->set(AppearanceSettings::MODE, 'dark');
        $fresh = new StorefrontAppearance($this->settings(), app(StoreTimezone::class));

        $this->assertSame('dark', $fresh->data()['mode'], 'the entry without `mode` was not trusted');
        $this->assertSame('dark', $fresh->mode());
    }

    // ---- the page ------------------------------------------------------------------------------------------------------------------------

    private function storeDark(): void
    {
        AppearanceWriter::save(array_replace(AppearanceWriter::current(), ['dark_mode' => true]));
        $this->app->forgetScopedInstances();
    }

    public function test_the_html_tag_is_exactly_what_it_was_when_light_or_the_flag_is_off_and_dark_only_when_both_are_on(): void
    {
        $this->flag(true);
        $this->storeDark();

        $this->flag(false);
        $off = $this->get('/')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#^<!DOCTYPE html>\s*<html lang="[a-z-]+">\n#', (string) $off, 'byte for byte today\'s tag');
        $this->assertStringNotContainsString('data-theme', (string) $off);

        $this->flag(true);
        $on = $this->get('/')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<html lang="[a-z-]+" data-theme="dark">\n#', (string) $on);
        $this->assertSame(1, substr_count((string) $on, 'data-theme="dark"'));

        AppearanceWriter::save(array_replace(AppearanceWriter::current(), ['dark_mode' => false]));
        $this->app->forgetScopedInstances();
        $light = $this->get('/')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<html lang="[a-z-]+">\n#', (string) $light);
        $this->assertStringNotContainsString('data-theme', (string) $light);
    }

    public function test_the_page_is_identical_for_two_visitors_and_sets_no_cookie(): void
    {
        $this->flag(true);
        $this->storeDark();

        $first = $this->get('/', ['Cookie' => 'easyco_consent=x'])->assertOk();
        $this->app->forgetScopedInstances();
        $second = $this->withHeaders(['Accept-Language' => 'bg', 'User-Agent' => 'other'])->get('/')->assertOk();

        $this->assertSame($first->getContent(), $second->getContent(), 'no per-visitor variation');
        $this->assertSame([], $first->headers->getCookies());
        $this->assertStringContainsString('s-maxage=604800', (string) $first->headers->get('Cache-Control'), 'still a cacheable page');
    }

    public function test_the_cookie_preview_follows_the_same_rule(): void
    {
        $url = CookiePreviewController::previewUrl(['position' => 'bottom', 'view' => 'summary', 'color' => '#1d4ed8', 'page' => null, 'layer' => 'summary', 'texts' => ['bg' => ['title' => 'T']]]);
        $this->storeDarkWithFlag();

        $this->flag(false);
        $this->assertDoesNotMatchRegularExpression('#data-theme#', (string) $this->get($url)->assertOk()->getContent());
        $this->assertMatchesRegularExpression('#<html lang="[a-z]+">#', (string) $this->get($url)->getContent());

        $this->flag(true);
        $this->app->forgetScopedInstances();
        $this->assertMatchesRegularExpression('#<html lang="[a-z]+" data-theme="dark">#', (string) $this->get($url)->assertOk()->getContent());
    }

    private function storeDarkWithFlag(): void
    {
        $this->flag(true);
        $this->storeDark();
    }

    // ---- the cache ---------------------------------------------------------------------------------------------------------------------------

    public function test_a_flip_of_the_mode_and_a_change_of_the_button_colour_each_collect_all(): void
    {
        $this->flag(true);

        foreach ([['dark_mode' => true], ['dark_button' => '#ffffff'], ['dark_mode' => false]] as $change) {
            $purger = new RecordingPurger;
            $this->app->instance(CachePurger::class, $purger);
            app(CachePurgeCollector::class)->reset();

            AppearanceWriter::save(array_replace(AppearanceWriter::current(), $change));
            app(CachePurgeCollector::class)->flush();

            $this->assertContains('all', $purger->keys(), json_encode($change));
        }
    }

    public function test_the_mode_is_a_visible_setting_so_writing_it_directly_collects_all_too(): void
    {
        $purger = new RecordingPurger;
        $this->app->instance(CachePurger::class, $purger);
        app(CachePurgeCollector::class)->reset();

        $this->settings()->set(AppearanceSettings::MODE, 'dark');
        app(CachePurgeCollector::class)->flush();

        $this->assertContains('all', $purger->keys());
    }

    // ---- the writer ----------------------------------------------------------------------------------------------------------------------------

    public function test_the_writer_stores_the_two_values_only_when_they_differ_and_forgets_them_back_to_the_defaults(): void
    {
        $this->flag(true);

        AppearanceWriter::save(array_replace(AppearanceWriter::current(), ['dark_mode' => true, 'dark_button' => '#FFFFFF']));
        $this->assertSame('dark', $this->settings()->get(AppearanceSettings::MODE));
        $this->assertSame('#ffffff', $this->settings()->get(AppearanceSettings::DARK_BUTTON));
        $this->assertTrue(AppearanceWriter::current()['dark_mode']);
        $this->assertSame('#ffffff', AppearanceWriter::current()['dark_button']);

        AppearanceWriter::save(array_replace(AppearanceWriter::current(), ['dark_mode' => false, 'dark_button' => AppearanceSettings::DEFAULT_DARK_BUTTON]));
        $this->assertNull($this->settings()->get(AppearanceSettings::MODE));
        $this->assertNull($this->settings()->get(AppearanceSettings::DARK_BUTTON));
    }

    public function test_a_save_that_does_not_carry_the_dark_values_keeps_them_as_stored(): void
    {
        $this->flag(true);
        AppearanceWriter::save(array_replace(AppearanceWriter::current(), ['dark_mode' => true, 'dark_button' => '#ffffff']));

        $data = AppearanceWriter::current();
        unset($data['dark_mode'], $data['dark_button']);
        $data['c_primary'] = '#123456';
        AppearanceWriter::save($data);

        $this->assertSame('dark', $this->settings()->get(AppearanceSettings::MODE), 'the flag off hides the fields: a save of the other fields must not reset the mode');
        $this->assertSame('#ffffff', $this->settings()->get(AppearanceSettings::DARK_BUTTON));
        $this->assertSame('#123456', $this->settings()->get('appearance.color.primary'), 'and the light colour is stored untouched while dark mode is on');
    }

    public function test_the_merchants_light_colours_stay_stored_while_dark_and_come_back_in_the_stylesheet_when_light_again(): void
    {
        $this->flag(true);
        AppearanceWriter::save(array_replace(AppearanceWriter::current(), ['c_primary' => '#123456', 'dark_mode' => true]));
        $this->assertSame('#123456', $this->settings()->get('appearance.color.primary'));
        $this->assertNull($this->settings()->get(AppearanceSettings::COMPILED_KEY), 'dark: nothing to compile');

        AppearanceWriter::save(array_replace(AppearanceWriter::current(), ['dark_mode' => false]));
        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::COMPILED_KEY), true);
        $this->assertStringContainsString('--sf-primary: #123456;', Storage::disk('public')->get('appearance/tokens-'.$compiled['hash'].'.css'));
    }

    public function test_the_writer_refuses_a_button_colour_that_fails_the_contrast_rules_and_writes_nothing(): void
    {
        $this->flag(true);
        $before = AppearanceWriter::current();

        try {
            AppearanceWriter::save(array_replace($before, ['c_primary' => '#654321', 'dark_mode' => true, 'dark_button' => '#333333']));
            $this->fail('a refused colour must stop the save');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame($before, AppearanceWriter::current(), 'nothing at all was written');
        $this->assertNull($this->settings()->get('appearance.color.primary'));
    }
}
