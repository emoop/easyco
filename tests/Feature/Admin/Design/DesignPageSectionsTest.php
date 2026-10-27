<?php

namespace Tests\Feature\Admin\Design;

use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\GallerySettings;
use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\Appearance\AppearanceWriter;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\PageDesign\PageDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * GL-2: the «Страница и карти» and «Продуктова страница» sections of the Design screen. Two FORM zones without a draft («Запази» is live): their variables go into the token stylesheet (only what
 * differs from the default), the five gallery choices into the `storefront.gallery.*` keys. Defaults write nothing; a refused value stores nothing; saving replaces the reader's cache entry; a
 * reset brings the output back to what it was.
 */
class DesignPageSectionsTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    /** Every variable the two sections can write (so that a test can say "exactly this one"). */
    private const OWN_VARIABLES = '--sf-page-pad|--sf-card-border-width|--sf-card-radius|--sf-card-border-width-mobile|--sf-card-radius-mobile|--sf-gal-gap|--sf-gal-radius|--sf-product-col-gap|--sf-summary-position|--sf-summary-max-height';

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

    private function screen(string $zone): Testable
    {
        $this->actingAsStaff('Administrator');

        return Livewire::test(DesignScreen::class)->call('selectZone', $zone);
    }

    /** @param  array<string, mixed>  $values */
    private function fill(Testable $screen, array $values): Testable
    {
        foreach ($values as $key => $value) {
            $screen->set('props.'.$key, $value);
        }

        return $screen;
    }

    /** The compiled token stylesheet as text, or null when there is none. */
    private function css(): ?string
    {
        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::COMPILED_KEY), true);

        return is_array($compiled) ? Storage::disk('public')->get('appearance/tokens-'.$compiled['hash'].'.css') : null;
    }

    /** @return list<string> the names of OUR variables the stylesheet writes */
    private function ownVariables(?string $css): array
    {
        preg_match_all('/(?<![\w-])('.self::OWN_VARIABLES.'):/', (string) $css, $m);

        return $m[1];
    }

    /** @return array<string, ?string> everything the two sections can store */
    private function stored(): array
    {
        return $this->settings()->getMany([AppearanceSettings::PAGE_DESIGN_KEY, AppearanceSettings::PRODUCT_DESIGN_KEY, AppearanceSettings::COMPILED_KEY, ...array_values(PageDesign::GALLERY_KEYS)]);
    }

    /** Nothing is stored; `$neverCompiled` also says no stylesheet file exists (after a return to the defaults the compiler keeps ONE previous file for pages cached a moment earlier: that is its own rule). */
    private function assertNothingStored(bool $neverCompiled = true): void
    {
        $this->assertSame(array_fill_keys(array_keys($this->stored()), null), $this->stored());
        $neverCompiled && $this->assertSame([], Storage::disk('public')->files('appearance'), 'no stylesheet file');
    }

    // ---- the sections are on the screen -------------------------------------------------------------------------------------------------

    public function test_both_sections_are_in_the_zone_list_and_open_with_their_fields_at_the_defaults(): void
    {
        $page = $this->screen('page');
        $html = $page->html();

        $this->assertStringContainsString('Page and cards', $html);
        $this->assertStringContainsString('Product page', $html);
        $page->assertSet('zone', 'page')->assertSet('props.pad_min', 16)->assertSet('props.pad_vw', 4)->assertSet('props.pad_max', 160)
            ->assertSet('props.card_border', 1)->assertSet('props.card_radius', 8)->assertSet('props.card_border_m', 0)->assertSet('props.card_radius_m', 0);
        $this->assertStringContainsString('Default 160', $html, 'every field has its one line of hint');

        $this->screen('product')->assertSet('zone', 'product')->assertSet('props.gallery_desktop_mode', 'grid')->assertSet('props.gallery_mobile_mode', 'carousel')
            ->assertSet('props.gallery_width', '65')->assertSet('props.gallery_odd_last', 'same')->assertSet('props.gallery_hover_zoom', 'light')
            ->assertSet('props.gal_gap', 8)->assertSet('props.gal_radius', 4)->assertSet('props.col_gap', 32)->assertSet('props.sticky', true)
            ->assertActionVisible('default')->assertActionHidden('publish')->assertActionHidden('discard');
    }

    // ---- defaults write nothing ---------------------------------------------------------------------------------------------------------

    public function test_saving_the_defaults_stores_nothing_and_the_stylesheet_is_what_it_was(): void
    {
        $baseline = app(AppearanceCompiler::class)->css(['appearance.color.primary' => '#123456']);
        AppearanceWriter::save(array_replace(AppearanceWriter::current(), ['c_primary' => '#123456']));
        $before = $this->stored();
        $this->assertSame($baseline, $this->css());

        $this->screen('page')->call('apply')->assertHasNoErrors();
        $this->screen('product')->call('apply')->assertHasNoErrors();

        $this->assertSame($before, $this->stored(), 'nothing new is stored');
        $this->assertSame($baseline, $this->css(), 'the compiled stylesheet is byte-identical');
    }

    public function test_an_untouched_shop_compiles_to_no_stylesheet_after_both_defaults_are_saved(): void
    {
        $this->screen('page')->call('apply')->assertHasNoErrors();
        $this->screen('product')->call('apply')->assertHasNoErrors();

        $this->assertNothingStored();
    }

    // ---- the table: one setting, one output -----------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, string>, 3: array<string, string>}> zone, panel input, the css lines, the gallery keys */
    public static function table(): array
    {
        return [
            'pad min' => ['page', ['pad_min' => '24'], ['--sf-page-pad' => 'clamp(1.5rem, 4vw, 10rem)'], []],
            'pad vw' => ['page', ['pad_vw' => '5.5'], ['--sf-page-pad' => 'clamp(1rem, 5.5vw, 10rem)'], []],
            'pad max' => ['page', ['pad_max' => '200'], ['--sf-page-pad' => 'clamp(1rem, 4vw, 12.5rem)'], []],
            'card border' => ['page', ['card_border' => '2'], ['--sf-card-border-width' => '0.125rem'], []],
            'card radius' => ['page', ['card_radius' => '12'], ['--sf-card-radius' => '0.75rem'], []],
            'card border phone' => ['page', ['card_border_m' => '1'], ['--sf-card-border-width-mobile' => '0.0625rem'], []],
            'card radius phone' => ['page', ['card_radius_m' => '6'], ['--sf-card-radius-mobile' => '0.375rem'], []],
            'card without a border' => ['page', ['card_border' => '0'], ['--sf-card-border-width' => '0'], []],
            'gallery gap' => ['product', ['gal_gap' => '12'], ['--sf-gal-gap' => '0.75rem'], []],
            'gallery radius' => ['product', ['gal_radius' => '8'], ['--sf-gal-radius' => '0.5rem'], []],
            'column gap' => ['product', ['col_gap' => '48'], ['--sf-product-col-gap' => '3rem'], []],
            'sticky off' => ['product', ['sticky' => false], ['--sf-summary-position' => 'static', '--sf-summary-max-height' => 'none'], []],
            'gallery desktop mode' => ['product', ['gallery_desktop_mode' => 'thumbs-left'], [], [GallerySettings::DESKTOP_MODE_KEY => 'thumbs-left']],
            'gallery phone mode' => ['product', ['gallery_mobile_mode' => 'thumbs-below'], [], [GallerySettings::MOBILE_MODE_KEY => 'thumbs-below']],
            'gallery width' => ['product', ['gallery_width' => '70'], [], [GallerySettings::WIDTH_PERCENT_KEY => '70']],
            'odd last photo' => ['product', ['gallery_odd_last' => 'full'], [], [GallerySettings::ODD_LAST_KEY => 'full']],
            'hover zoom' => ['product', ['gallery_hover_zoom' => 'off'], [], [GallerySettings::HOVER_ZOOM_KEY => 'off']],
        ];
    }

    /** @param  array<string, mixed>  $input  @param  array<string, string>  $lines  @param  array<string, string>  $gallery */
    #[\PHPUnit\Framework\Attributes\DataProvider('table')]
    public function test_a_changed_value_changes_exactly_its_variable_or_its_key(string $zone, array $input, array $lines, array $gallery): void
    {
        $this->fill($this->screen($zone), $input)->call('apply')->assertHasNoErrors();

        $css = $this->css();

        foreach ($lines as $variable => $value) {
            $this->assertStringContainsString("{$variable}: {$value};", (string) $css);
        }

        $this->assertEqualsCanonicalizing(array_keys($lines), $this->ownVariables($css), 'no other variable of ours is written');

        $galleryStored = array_filter($this->settings()->getMany(array_values(PageDesign::GALLERY_KEYS)), static fn (?string $value): bool => $value !== null);
        $this->assertSame($gallery, $galleryStored, 'only the changed gallery key is written, through the settings the page reads');

        if ($lines === []) {
            $this->assertNull($css, 'a gallery choice is not a stylesheet variable: nothing is compiled');
            $this->assertNull($this->settings()->get($zone === 'page' ? AppearanceSettings::PAGE_DESIGN_KEY : AppearanceSettings::PRODUCT_DESIGN_KEY));
        }
    }

    public function test_the_page_padding_is_one_clamp_with_the_three_parts_together(): void
    {
        $this->fill($this->screen('page'), ['pad_min' => '12', 'pad_vw' => '3.5', 'pad_max' => '120'])->call('apply')->assertHasNoErrors();

        $this->assertStringContainsString('--sf-page-pad: clamp(0.75rem, 3.5vw, 7.5rem);', (string) $this->css());
        $this->assertSame(['--sf-page-pad'], $this->ownVariables($this->css()));
    }

    public function test_the_two_sections_keep_each_others_variables_and_the_banner_colours(): void
    {
        AppearanceWriter::save(array_replace(AppearanceWriter::current(), ['c_primary' => '#123456']));
        $this->fill($this->screen('page'), ['card_radius' => '12'])->call('apply')->assertHasNoErrors();
        $this->fill($this->screen('product'), ['col_gap' => '48'])->call('apply')->assertHasNoErrors();

        $css = (string) $this->css();
        $this->assertStringContainsString('--sf-card-radius: 0.75rem;', $css);
        $this->assertStringContainsString('--sf-product-col-gap: 3rem;', $css);
        $this->assertStringContainsString('--sf-primary: #123456;', $css);

        // an appearance save afterwards does not drop them
        AppearanceWriter::save(array_replace(AppearanceWriter::current(), ['c_primary' => '#654321']));
        $css = (string) $this->css();
        $this->assertStringContainsString('--sf-card-radius: 0.75rem;', $css);
        $this->assertStringContainsString('--sf-product-col-gap: 3rem;', $css);
        $this->assertStringContainsString('--sf-primary: #654321;', $css);
    }

    // ---- sticky ---------------------------------------------------------------------------------------------------------------------------

    public function test_sticky_off_writes_both_variables_and_on_writes_neither(): void
    {
        $screen = $this->fill($this->screen('product'), ['sticky' => false])->call('apply')->assertHasNoErrors();
        $this->assertEqualsCanonicalizing(['--sf-summary-position', '--sf-summary-max-height'], $this->ownVariables($this->css()));

        $screen->set('props.sticky', true)->call('apply')->assertHasNoErrors();
        $this->assertSame([], $this->ownVariables($this->css()));
        $this->assertNothingStored(false);
    }

    // ---- refusals -------------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: mixed}> */
    public static function badInputs(): array
    {
        return [
            'a declaration in the padding' => ['page', 'pad_min', '1;color:red'],
            'a brace in the padding' => ['page', 'pad_max', '1rem}body{x'],
            'a very long string' => ['page', 'pad_vw', str_repeat('9', 600)],
            'a comma decimal' => ['page', 'card_radius', '4,5'],
            'a unit' => ['page', 'card_border', '1px'],
            'above the range' => ['page', 'card_radius_m', '33'],
            'empty' => ['page', 'card_border_m', ''],
            'a negative gap' => ['product', 'gal_gap', '-1'],
            'a declaration in the gap' => ['product', 'col_gap', '8;color:red'],
            'a radius over the limit' => ['product', 'gal_radius', '17'],
            'a mode outside the set' => ['product', 'gallery_desktop_mode', 'masonry'],
            'a width outside the set' => ['product', 'gallery_width', '66'],
            'markup as a mode' => ['product', 'gallery_hover_zoom', '"><b>x</b>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badInputs')]
    public function test_a_bad_value_is_refused_under_its_field_and_nothing_is_stored(string $zone, string $field, mixed $value): void
    {
        $this->fill($this->screen($zone), [$field => $value])->call('apply')->assertHasErrors(['props.'.$field]);

        $this->assertNothingStored();
    }

    public function test_the_minimum_padding_above_the_maximum_is_refused_under_the_maximum(): void
    {
        $this->fill($this->screen('page'), ['pad_min' => '48', 'pad_max' => '16'])->call('apply')->assertHasErrors(['props.pad_max']);

        $this->assertNothingStored();
    }

    // ---- the cache --------------------------------------------------------------------------------------------------------------------------

    public function test_saving_replaces_the_readers_cache_entry_even_when_only_a_gallery_choice_changed(): void
    {
        foreach (['page' => ['card_radius' => '12'], 'product' => ['gallery_width' => '70']] as $zone => $input) {
            Cache::forever(StorefrontAppearance::CACHE_KEY, ['stale' => true]);

            $this->fill($this->screen($zone), $input)->call('apply')->assertHasNoErrors();

            $cached = Cache::get(StorefrontAppearance::CACHE_KEY);
            $this->assertIsArray($cached, $zone);
            $this->assertArrayNotHasKey('stale', $cached, "{$zone}: the stale entry is gone");
            $this->assertArrayHasKey('compiled', $cached, "{$zone}: the entry is the freshly read payload");
        }

        $cached = Cache::get(StorefrontAppearance::CACHE_KEY);
        $this->assertSame(json_decode((string) $this->settings()->get(AppearanceSettings::COMPILED_KEY), true)['hash'], $cached['compiled']['hash'], 'it holds the new stylesheet');
    }

    // ---- authorisation ----------------------------------------------------------------------------------------------------------------------

    public function test_a_role_without_the_permission_cannot_open_or_save_the_sections(): void
    {
        $this->actingAsStaff('Product Entry');
        $this->get(DesignScreen::getUrl().'?zone=page')->assertForbidden();
        Livewire::test(DesignScreen::class)->assertForbidden();

        foreach (['apply', 'resetToDefault'] as $call) {
            $this->actingAsStaff('Administrator');
            $screen = $this->fill(Livewire::test(DesignScreen::class)->call('selectZone', 'product'), ['gal_gap' => '12']);

            $this->app->forgetScopedInstances();
            $this->actingAsStaff('Product Entry');
            $screen->call($call)->assertForbidden();
        }

        $this->assertNothingStored();
    }

    // ---- the gallery keys reach the page ---------------------------------------------------------------------------------------------------

    public function test_the_saved_gallery_choices_are_on_the_product_page(): void
    {
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeProduct(1, ['name' => 'Summer dress', 'slug' => 'summer-dress', 'price' => '10.00', 'stock' => 1]);
        $this->makeImage(1, 1, 1);
        $this->makeImage(2, 1, 2);

        $this->fill($this->screen('product'), [
            'gallery_desktop_mode' => 'thumbs-left', 'gallery_mobile_mode' => 'thumbs-below', 'gallery_width' => '70', 'gallery_odd_last' => 'full', 'gallery_hover_zoom' => 'medium',
        ])->call('apply')->assertHasNoErrors();

        $this->app->forgetScopedInstances();
        $html = $this->get('/product/summer-dress')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<section class="sf-gallery sf-gal-w-70"[^>]*\sdata-gal-desktop="thumbs-left"\s+data-gal-mobile="thumbs-below"\s+data-gal-odd="full"\s+data-gal-zoom="medium"/s', $html);
        $this->assertSame('thumbs-left', GallerySettings::fromRepository($this->settings())->desktopMode);
    }

    // ---- reset ------------------------------------------------------------------------------------------------------------------------------

    public function test_the_reset_loads_the_defaults_into_the_panel_only_and_saving_them_brings_the_output_back(): void
    {
        foreach (['page' => ['pad_min' => '24', 'card_radius' => '12', 'card_border_m' => '1'], 'product' => ['gal_gap' => '12', 'sticky' => false, 'gallery_desktop_mode' => 'thumbs-left', 'gallery_hover_zoom' => 'off']] as $zone => $input) {
            $this->fill($this->screen($zone), $input)->call('apply')->assertHasNoErrors();
        }

        $this->assertNotNull($this->css());
        $changed = $this->stored();

        $screen = $this->screen('product');
        $screen->call('resetToDefault')->assertSet('props.gal_gap', 8)->assertSet('props.sticky', true)->assertSet('props.gallery_desktop_mode', 'grid')->assertSet('props.gallery_hover_zoom', 'light');
        $this->assertSame($changed, $this->stored(), 'nothing is stored until «Запази»');

        $screen->call('apply')->assertHasNoErrors();
        $this->assertEqualsCanonicalizing(['--sf-page-pad', '--sf-card-radius', '--sf-card-border-width-mobile'], $this->ownVariables($this->css()), 'the product section is back; the page section still holds its own');

        $page = $this->screen('page');
        $page->call('resetToDefault')->assertSet('props.pad_min', 16)->assertSet('props.card_radius', 8)->assertSet('props.card_border_m', 0);
        $page->call('apply')->assertHasNoErrors();

        $this->assertNothingStored(false);
        $this->assertNull($this->css(), 'the stylesheet is no longer linked: the compiled entry is gone');
    }

    public function test_a_reset_of_one_section_leaves_the_other_one_alone(): void
    {
        $this->fill($this->screen('page'), ['card_radius' => '12'])->call('apply')->assertHasNoErrors();
        $this->fill($this->screen('product'), ['col_gap' => '48'])->call('apply')->assertHasNoErrors();

        $this->screen('page')->call('resetToDefault')->call('apply')->assertHasNoErrors();

        $this->assertSame(['--sf-product-col-gap'], $this->ownVariables($this->css()));
        $this->assertNull($this->settings()->get(AppearanceSettings::PAGE_DESIGN_KEY));
        $this->assertNotNull($this->settings()->get(AppearanceSettings::PRODUCT_DESIGN_KEY));
    }

    // ---- bad stored data ------------------------------------------------------------------------------------------------------------------

    public function test_a_stored_value_that_does_not_validate_contributes_nothing(): void
    {
        $this->settings()->set(AppearanceSettings::PAGE_DESIGN_KEY, (string) json_encode(['card_radius' => '1;color:red', 'pad_min' => 24]));
        $this->settings()->set(AppearanceSettings::PRODUCT_DESIGN_KEY, '{not json');

        $this->screen('page')->assertSet('props.card_radius', 8)->assertSet('props.pad_min', 16);

        AppearanceWriter::save(AppearanceWriter::current());

        $this->assertNull($this->css(), 'a hand-edited row cannot reach the stylesheet');
    }
}
