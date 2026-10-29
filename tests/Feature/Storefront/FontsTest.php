<?php

namespace Tests\Feature\Storefront;

use App\Filament\Pages\Settings\AppearanceSettings as AppearancePage;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\Appearance\StorefrontAppearance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * FN-1: the two self-hosted STATIC families (Montserrat for text, Cormorant Garamond for headings) in the closed font list of «Облик»: the manifest, the closed list, what a shop that chose them gets (and
 * that a shop that did not gets exactly what it always got), the preload and the form. The browser side (computed fonts, the network, the Cyrillic glyphs) is tests/Browser/fn-1.mjs.
 */
class FontsTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const OLD = ['inter', 'manrope', 'lora'];

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        Storage::fake('public');
        app()->setLocale('en');
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return json_decode((string) file_get_contents(public_path('fonts/storefront/fonts.json')), true);
    }

    /** @param  array<string, mixed>  $manifest */
    private function compilerWith(array $manifest): AppearanceCompiler
    {
        $path = tempnam(sys_get_temp_dir(), 'fn1');
        file_put_contents($path, json_encode($manifest));

        return new AppearanceCompiler($path);
    }

    /** @return array<string, string> */
    private function fonts(string $body, string $heading = 'same'): array
    {
        return [AppearanceSettings::FONT_BODY => $body, AppearanceSettings::FONT_HEADING => $heading];
    }

    private function css(array $overrides): string
    {
        return (string) app(AppearanceCompiler::class)->css($overrides);
    }

    // 1. byte identity

    public function test_a_shop_that_chose_none_of_the_new_fonts_gets_exactly_what_it_always_got(): void
    {
        $old = array_intersect_key($this->manifest(), array_flip(self::OLD));
        $before = $this->compilerWith($old);

        $this->assertNull(app(AppearanceCompiler::class)->css([]), 'no font chosen: no stylesheet at all');

        foreach ([$this->fonts('inter'), $this->fonts('manrope', 'lora'), $this->fonts('system', 'lora'), $this->fonts('lora', 'inter') + ['appearance.color.primary' => '#123456']] as $set) {
            $this->assertSame($before->css($set), app(AppearanceCompiler::class)->css($set), json_encode($set));
            $this->assertStringNotContainsString('Montserrat', $this->css($set));
            $this->assertStringNotContainsString('Cormorant', $this->css($set));
        }

        $this->assertSame(['hash' => app(AppearanceCompiler::class)->compile($this->fonts('inter'))['hash'], 'fonts' => ['inter'], 'body' => 'inter'], app(AppearanceCompiler::class)->compile($this->fonts('inter')), 'a variable body font stores no preload file: the stored value is what it was');
    }

    // 2. the manifest

    public function test_each_new_family_has_the_expected_weights_subsets_and_real_woff2_files_inside_the_project(): void
    {
        $manifest = $this->manifest();
        $expected = [
            'montserrat' => ['family' => 'Montserrat', 'serif' => false, 'faces' => [['normal', 300], ['normal', 400], ['normal', 500], ['normal', 600]]],
            'cormorant' => ['family' => 'Cormorant Garamond', 'serif' => true, 'faces' => [['normal', 400], ['normal', 500], ['normal', 600], ['italic', 500]]],
        ];

        foreach ($expected as $key => $spec) {
            $this->assertSame($spec['family'], $manifest[$key]['family']);
            $this->assertSame($spec['serif'], $manifest[$key]['serif']);
            $this->assertCount(count($spec['faces']) * 3, $manifest[$key]['faces'], 'every style/weight in three subsets');

            $seen = [];

            foreach ($manifest[$key]['faces'] as $face) {
                $seen[$face['style'].'/'.$face['weight']][] = $face['subset'];
                $path = public_path("fonts/storefront/{$key}/{$face['file']}");

                $this->assertMatchesRegularExpression('/\A[a-z0-9-]{1,80}\.woff2\z/', $face['file']);
                $this->assertFileExists($path);
                $this->assertGreaterThan(1000, filesize($path), $face['file']);
                $this->assertSame('wOF2', file_get_contents($path, false, null, 0, 4), $face['file'].' is a woff2');
                $this->assertMatchesRegularExpression('/\AU\+[0-9A-Fa-f,U+\- ]{1,400}\z/', $face['range']);
                $this->assertSame(realpath(public_path("fonts/storefront/{$key}")), realpath(dirname($path)), 'inside the family folder');
            }

            foreach ($spec['faces'] as [$style, $weight]) {
                $this->assertSame(['latin', 'latin-ext', 'cyrillic'], $seen["{$style}/{$weight}"], "{$key} {$style} {$weight}");
            }

            $this->assertStringContainsString('SIL OPEN FONT LICENSE', strtoupper((string) file_get_contents(public_path("fonts/storefront/{$key}/OFL-LICENSE.txt"))));
        }

        $cyrillic = array_values(array_filter($manifest['montserrat']['faces'], static fn (array $f): bool => $f['subset'] === 'cyrillic'))[0];
        $this->assertStringContainsString('U+0400-045F', $cyrillic['range'], 'the cyrillic file covers the basic Cyrillic block (Bulgarian)');
    }

    public function test_the_generated_rules_are_swap_with_one_range_per_subset_and_no_foreign_host(): void
    {
        $css = $this->css($this->fonts('montserrat', 'cormorant'));

        $this->assertSame(24, substr_count($css, '@font-face'));
        $this->assertSame(24, substr_count($css, 'font-display: swap;'));
        $this->assertSame(24, substr_count($css, 'unicode-range: U+'));
        $this->assertDoesNotMatchRegularExpression('#https?://|//[a-z]#i', $css);
        $this->assertStringContainsString("font-family: 'Cormorant Garamond';\n    font-style: italic;\n    font-display: swap;\n    font-weight: 500;\n    src: url(/fonts/storefront/cormorant/cormorant-latin-500-italic.woff2) format('woff2');", $css);
    }

    // 3. the closed list

    public function test_a_key_that_is_not_in_the_list_is_refused_on_every_path_and_a_bad_manifest_entry_too(): void
    {
        $compiler = app(AppearanceCompiler::class);

        foreach ([AppearanceSettings::FONT_BODY, AppearanceSettings::FONT_HEADING] as $setting) {
            foreach (['montserrat2', 'Montserrat', 'cormorant-garamond', '../montserrat', "montserrat'; }", '', 'same_'] as $bad) {
                $this->assertThrowsInvalid(static fn () => $compiler->css([$setting => $bad]), "{$setting} = {$bad}");
            }
        }

        $this->assertArrayHasKey('montserrat', AppearanceSettings::FONTS);
        $this->assertArrayHasKey('cormorant', AppearanceSettings::FONTS);

        $good = $this->manifest()['montserrat'];

        foreach ([
            ['weight' => 'bold'], ['weight' => 1000], ['weight' => 90], ['style' => 'oblique'], ['file' => '../x.woff2'], ['file' => 'x.woff'], ['range' => 'U+0000;}body{'],
        ] as $change) {
            $bad = $good;
            $bad['faces'][0] = $change + $bad['faces'][0];

            $this->assertThrowsInvalid(fn () => $this->compilerWith(['montserrat' => $bad])->css($this->fonts('montserrat')), json_encode($change));
        }

        $bad = $good;
        $bad['family'] = "Montserrat'; } body { x";
        $this->assertThrowsInvalid(fn () => $this->compilerWith(['montserrat' => $bad])->css($this->fonts('montserrat')), 'the family name');
    }

    private function assertThrowsInvalid(callable $call, string $what): void
    {
        try {
            $call();
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail($what.' was accepted');
    }

    // 4. choosing them

    public function test_only_the_chosen_family_is_emitted_and_the_heading_variable_only_when_it_is_chosen(): void
    {
        $body = $this->css($this->fonts('montserrat'));

        $this->assertSame(12, substr_count($body, '@font-face'));
        $this->assertStringNotContainsString('Cormorant', $body);
        $this->assertStringContainsString("--sf-font-body: 'Montserrat', ui-sans-serif", $body);
        $this->assertStringNotContainsString('--sf-font-heading', $body, 'heading «same as body»: nothing is emitted');

        $both = $this->css($this->fonts('montserrat', 'cormorant'));

        $this->assertSame(24, substr_count($both, '@font-face'));
        $this->assertStringContainsString("--sf-font-heading: 'Cormorant Garamond', Georgia, 'Times New Roman', serif;", $both, 'a serif family gets a serif fallback');

        $heading = $this->css($this->fonts('system', 'cormorant'));
        $this->assertSame(12, substr_count($heading, '@font-face'));
        $this->assertStringNotContainsString('--sf-font-body', $heading);
        $this->assertStringNotContainsString('Montserrat', $heading);

        $this->assertSame(['montserrat', 'cormorant'], app(AppearanceCompiler::class)->fontKeys($this->fonts('montserrat', 'cormorant')));
        $this->assertSame(['montserrat'], app(AppearanceCompiler::class)->fontKeys($this->fonts('montserrat', 'montserrat')), 'one family, one set of files');
    }

    public function test_a_static_body_font_names_its_regular_latin_file_for_the_preload_and_the_page_links_only_that(): void
    {
        $compiled = app(AppearanceCompiler::class)->compile($this->fonts('montserrat', 'cormorant'));

        $this->assertSame('montserrat-latin-400-normal.woff2', $compiled['preload']);
        $this->assertArrayNotHasKey('preload', app(AppearanceCompiler::class)->compile($this->fonts('system', 'cormorant')), 'a heading-only choice preloads nothing');

        app(SiteSettingsRepository::class)->set(AppearanceSettings::COMPILED_KEY, json_encode($compiled));
        Cache::forget(StorefrontAppearance::CACHE_KEY);
        $this->app->forgetScopedInstances();
        $this->seedPricing();
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'rel="preload"'));
        $this->assertStringContainsString('<link rel="preload" as="font" type="font/woff2" href="/fonts/storefront/montserrat/montserrat-latin-400-normal.woff2" crossorigin>', $html);
        $this->assertStringNotContainsString('cormorant-latin', $html);

        app(SiteSettingsRepository::class)->set(AppearanceSettings::COMPILED_KEY, json_encode(['hash' => $compiled['hash'], 'fonts' => ['montserrat'], 'body' => 'montserrat', 'preload' => '../../etc/x.woff2']));
        Cache::forget(StorefrontAppearance::CACHE_KEY);
        $this->app->forgetScopedInstances();
        $this->assertStringContainsString('/fonts/storefront/montserrat/montserrat-latin-wght-normal.woff2', $this->get('/')->assertOk()->getContent(), 'a hand-edited file name is never used');
    }

    // 5. the form

    public function test_the_form_offers_the_new_fonts_saves_them_and_the_initial_settings_forget_them(): void
    {
        $this->actingAsStaff('Administrator');
        $page = Livewire::test(AppearancePage::class);

        $this->assertStringContainsString('Montserrat', $page->html());
        $this->assertStringContainsString('Cormorant Garamond', $page->html());

        $page->fillForm(['font_body' => 'montserrat', 'font_heading' => 'cormorant'])->call('save')->assertHasNoFormErrors();

        $repository = app(SiteSettingsRepository::class);
        $this->assertSame('montserrat', $repository->get(AppearanceSettings::FONT_BODY));
        $this->assertSame('cormorant', $repository->get(AppearanceSettings::FONT_HEADING));

        $compiled = json_decode((string) $repository->get(AppearanceSettings::COMPILED_KEY), true);
        $this->assertSame(['montserrat', 'cormorant'], $compiled['fonts']);
        $this->assertSame('montserrat-latin-400-normal.woff2', $compiled['preload']);

        Livewire::test(AppearancePage::class)->fillForm(['font_body' => 'system', 'font_heading' => 'same'])->call('save')->assertHasNoFormErrors();

        $this->assertNull($repository->get(AppearanceSettings::FONT_BODY), 'the default is not stored');
        $this->assertNull($repository->get(AppearanceSettings::COMPILED_KEY), 'and nothing is compiled any more');
    }

    public function test_a_stored_font_that_is_no_longer_in_the_list_falls_back_to_the_default_without_an_error(): void
    {
        $this->actingAsStaff('Administrator');
        $repository = app(SiteSettingsRepository::class);
        $repository->set(AppearanceSettings::FONT_BODY, 'retired-font');
        $repository->set(AppearanceSettings::FONT_HEADING, 'cormorant');
        $repository->set(AppearanceSettings::COMPILED_KEY, json_encode(['hash' => str_repeat('a', 12), 'fonts' => ['retired-font', 'cormorant'], 'body' => 'retired-font']));
        Cache::forget(StorefrontAppearance::CACHE_KEY);

        $page = Livewire::test(AppearancePage::class);
        $page->assertSet('data.font_body', 'system')->assertSet('data.font_heading', 'cormorant');

        $this->seedPricing();
        $this->app->forgetScopedInstances();
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('retired-font', $html);
        $this->assertStringNotContainsString('rel="preload"', $html, 'the retired font is not preloaded');
    }
}
