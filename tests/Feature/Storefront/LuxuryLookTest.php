<?php

namespace Tests\Feature\Storefront;

use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\ProductSummary\ProductSummaryCatalog;
use App\Storefront\ProductSummary\ProductSummaryLayout;
use App\Storefront\ProductSummary\ProductSummaryValidator;
use App\Storefront\ProductSummary\PurchaseStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * LK-1: the right column's default look is CSS (checked in tests/Browser/lk-1.mjs); what is code is (1) the purchase button's `rounded` default, which is now SQUARE, and (2) the heading weight a font without a
 * bold of its own hands the headings (`--sf-heading-weight`, from the manifest).
 */
class LuxuryLookTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        Storage::fake('public');
        $this->withoutVite();
        app()->setLocale('en');
    }

    /** @return array<string, mixed> the purchase settings the validator returns for these */
    private function validated(array $settings): array
    {
        $layout = ProductSummaryLayout::default();

        foreach ($layout['components'] as $i => $component) {
            $component['type'] === 'purchase' && $layout['components'][$i]['settings'] = $settings;
        }

        [$clean, $errors] = ProductSummaryValidator::validate($layout);
        $this->assertSame([], $errors);

        foreach ($clean['components'] as $component) {
            if ($component['type'] === 'purchase') {
                return $component['settings'];
            }
        }

        $this->fail('no purchase component');
    }

    // 1. rounded

    public function test_square_is_the_default_everywhere_and_only_true_emits_the_shops_radius(): void
    {
        $this->assertFalse(ProductSummaryCatalog::defaultsFor('purchase')['rounded']);
        $this->assertFalse(ProductSummaryCatalog::all()['purchase']['schema']['rounded']['default']);
        $this->assertFalse(array_values(array_filter(ProductSummaryLayout::default()['components'], static fn (array $c): bool => $c['type'] === 'purchase'))[0]['settings']['rounded']);

        $this->assertFalse($this->validated([])['rounded'], 'a layout with no `rounded` key is square');
        $this->assertSame([], PurchaseStyle::vars($this->validated([])), 'the default emits nothing: the stylesheet is what it was');
        $this->assertSame([], PurchaseStyle::vars($this->validated(['rounded' => false])));
        $this->assertSame(['--sf-buy-radius' => 'var(--sf-r-btn, var(--radius-md))'], PurchaseStyle::vars($this->validated(['rounded' => true])));

        $compiler = app(AppearanceCompiler::class);
        $this->assertNull($compiler->css([], [], [], [], [], [], [], [], PurchaseStyle::vars($this->validated([]))), 'no purchase setting: no token stylesheet at all');
        $this->assertStringContainsString('--sf-buy-radius: var(--sf-r-btn, var(--radius-md));', (string) $compiler->css([], [], [], [], [], [], [], [], PurchaseStyle::vars($this->validated(['rounded' => true]))));

        foreach (['0', '9999px', 'var(--x)', 'var(--sf-r-btn, 9999px)', ''] as $bad) {
            try {
                $compiler->css([], [], [], [], [], [], [], [], ['--sf-buy-radius' => $bad]);
            } catch (InvalidArgumentException) {
                continue;
            }

            $this->fail("the radius value '{$bad}' was accepted");
        }
    }

    public function test_the_editor_starts_square_and_its_preview_follows_the_toggle(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = Livewire::test(DesignScreen::class)->call('selectZone', 'summary');

        foreach ($screen->get('tree')['main']['rows'][0]['cells'][0]['items'] as $item) {
            $item['type'] === 'purchase' && $screen->call('selectNode', $item['id']);
        }

        $screen->assertSet('props.settings.rounded', false);
        $this->assertStringContainsString('Off = square corners (the default).', $screen->html());
        preg_match_all('/class="ds-buy-sample" style="([^"]*)"/', $screen->html(), $square);
        $this->assertCount(3, $square[1]);

        foreach ($square[1] as $style) {
            $this->assertStringContainsString('border-radius:0', $style);
        }

        preg_match_all('/class="ds-buy-sample" style="([^"]*)"/', $screen->set('props.settings.rounded', true)->html(), $round);

        foreach ($round[1] as $style) {
            $this->assertStringContainsString('border-radius:8px', $style);
        }

        $screen->call('apply')->assertHasNoErrors();
        $draft = json_decode((string) app(SiteSettingsRepository::class)->get(AppearanceSettings::SUMMARY_DRAFT_KEY), true);
        $this->assertTrue(array_values(array_filter($draft['components'], static fn (array $c): bool => $c['type'] === 'purchase'))[0]['settings']['rounded'], 'true is the stored difference');
    }

    // 2. the heading weight

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return json_decode((string) file_get_contents(public_path('fonts/storefront/fonts.json')), true);
    }

    /** @param  array<string, mixed>  $manifest */
    private function compilerWith(array $manifest): AppearanceCompiler
    {
        $path = tempnam(sys_get_temp_dir(), 'lk1');
        file_put_contents($path, json_encode($manifest));

        return new AppearanceCompiler($path);
    }

    private function css(string $body, string $heading): string
    {
        return (string) app(AppearanceCompiler::class)->css([AppearanceSettings::FONT_BODY => $body, AppearanceSettings::FONT_HEADING => $heading]);
    }

    public function test_only_a_heading_font_with_a_manifest_weight_emits_the_variable(): void
    {
        $this->assertSame(600, $this->manifest()['cormorant']['heading_weight']);

        foreach (['inter', 'manrope', 'lora', 'montserrat'] as $family) {
            $this->assertArrayNotHasKey('heading_weight', $this->manifest()[$family], $family.' has real bold weights');
        }

        $this->assertStringContainsString('--sf-heading-weight: 600;', $this->css('system', 'cormorant'));
        $this->assertStringContainsString('--sf-heading-weight: 600;', $this->css('montserrat', 'cormorant'));
        $this->assertStringContainsString('--sf-heading-weight: 600;', $this->css('cormorant', 'same'), '«as the body font» is still Cormorant');

        foreach ([['system', 'same'], ['inter', 'same'], ['montserrat', 'lora'], ['cormorant', 'inter'], ['cormorant', 'system']] as [$body, $heading]) {
            $this->assertStringNotContainsString('--sf-heading-weight', $this->css($body, $heading), "{$body} / {$heading}");
        }
    }

    public function test_a_shop_without_such_a_font_gets_the_stylesheet_it_always_got_and_a_bad_weight_is_refused(): void
    {
        $manifest = $this->manifest();
        $plain = $manifest;
        unset($plain['cormorant']['heading_weight']);

        foreach ([['inter', 'lora'], ['montserrat', 'same'], ['system', 'lora']] as [$body, $heading]) {
            $set = [AppearanceSettings::FONT_BODY => $body, AppearanceSettings::FONT_HEADING => $heading, 'appearance.color.primary' => '#123456'];
            $this->assertSame($this->compilerWith($plain)->css($set), app(AppearanceCompiler::class)->css($set), "{$body} / {$heading}");
        }

        foreach ([600.5, '600', 50, 1000, [600], true] as $bad) {
            $broken = $manifest;
            $broken['cormorant']['heading_weight'] = $bad;

            try {
                $this->compilerWith($broken)->css([AppearanceSettings::FONT_HEADING => 'cormorant']);
            } catch (InvalidArgumentException) {
                continue;
            }

            $this->fail('the heading weight '.json_encode($bad).' was accepted');
        }
    }
}
