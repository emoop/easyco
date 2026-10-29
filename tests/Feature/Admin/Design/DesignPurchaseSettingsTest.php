<?php

namespace Tests\Feature\Admin\Design;

use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * PB-1: the settings of the «Покупка» component in the Design screen («Дясна колона»): saved NORMALISED, refused with field errors, «Начални настройки» put the defaults back, the preview of the button and the
 * non-blocking contrast warning; the authorisation is the zone's own.
 */
class DesignPurchaseSettingsTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        $this->actingAsStaff('Administrator');
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function purchaseId(Testable $screen): string
    {
        foreach ($screen->get('tree')['main']['rows'][0]['cells'][0]['items'] as $item) {
            if ($item['type'] === 'purchase') {
                return $item['id'];
            }
        }

        $this->fail('no purchase component');
    }

    private function screen(): Testable
    {
        $screen = Livewire::test(DesignScreen::class)->call('selectZone', 'summary');

        return $screen->call('selectNode', $this->purchaseId($screen));
    }

    /** @return array<string, mixed> the purchase settings of the stored draft */
    private function draft(): array
    {
        foreach (json_decode((string) $this->settings()->get(AppearanceSettings::SUMMARY_DRAFT_KEY), true)['components'] as $component) {
            if ($component['type'] === 'purchase') {
                return $component['settings'];
            }
        }

        $this->fail('no draft');
    }

    public function test_the_editor_shows_the_compact_form_with_the_three_texts_colours_border_corners_and_the_preview(): void
    {
        $html = $this->screen()->html();

        foreach (['Button text', 'Text before a choice', 'Text when sold out', 'Button background', 'Text colour', 'Border (px)', 'Border colour', 'Rounded corners', 'What the button looks like', 'Normal', 'Hover', 'Disabled'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }

        $this->assertSame(3, substr_count($html, 'class="ds-buy-state"'));
    }

    public function test_apply_stores_normalised_settings_in_the_draft(): void
    {
        $this->screen()
            ->set('props.settings.label', 'Купи сега')
            ->set('props.settings.bg', '#2E7D32')
            ->set('props.settings.color', '#FFFFFF')
            ->set('props.settings.border_w', '2')
            ->set('props.settings.rounded', false)
            ->call('apply')
            ->assertHasNoErrors();

        $this->assertSame(['label' => 'Купи сега', 'label_choose' => '', 'label_sold_out' => '', 'bg' => '#2e7d32', 'color' => '#ffffff', 'border_w' => 2, 'border_color' => null, 'rounded' => false], $this->draft());
    }

    public function test_a_refused_value_stores_nothing_and_the_error_stands_under_its_field(): void
    {
        foreach ([['bg', '#fff'], ['bg', 'red'], ['border_w', '5'], ['border_w', '1;color:red'], ['label', str_repeat('x', 31)]] as [$field, $value]) {
            $this->screen()->set('props.settings.'.$field, $value)->call('apply');

            $this->assertNull($this->settings()->get(AppearanceSettings::SUMMARY_DRAFT_KEY), "{$field} = {$value} stores nothing");
        }
    }

    public function test_the_initial_settings_put_the_defaults_back(): void
    {
        $screen = $this->screen()->set('props.settings.bg', '#2E7D32')->call('apply')->call('publish');
        $this->assertNotNull($this->settings()->get(AppearanceSettings::SUMMARY_PUBLISHED_KEY));

        $screen = Livewire::test(DesignScreen::class)->call('selectZone', 'summary')->call('resetToDefault')->call('publish');

        $this->assertNull($this->settings()->get(AppearanceSettings::SUMMARY_PUBLISHED_KEY), 'the default layout, button settings included, is not stored at all');
        $this->assertNull($this->settings()->get(AppearanceSettings::COMPILED_KEY), 'and the token stylesheet is back to what it was');
    }

    public function test_the_preview_is_built_from_validated_values_only(): void
    {
        $html = $this->screen()->set('props.settings.bg', '#2E7D32;}</style><script>x</script>')->html();

        $this->assertStringNotContainsString('<script>x', $html);
        preg_match_all('/class="ds-buy-sample" style="([^"]*)"/', $html, $m);

        $this->assertCount(3, $m[1]);

        foreach ($m[1] as $style) {
            $this->assertDoesNotMatchRegularExpression('/[;}<>]\s*[}<>]|url\(|expression\(/', $style);
        }

        $this->assertStringContainsString('background:#171717', $m[1][0], 'a value that does not validate is the default look, never the typed text');
    }

    public function test_a_low_contrast_pair_warns_without_blocking(): void
    {
        $this->screen()->set('props.settings.bg', '#ffffff')->set('props.settings.color', '#f0f0f0')->call('apply')->assertNotified();

        $this->assertSame('#ffffff', $this->draft()['bg'], 'the warning does not stop the save');

        $zone = new \App\Filament\Design\Zones\ProductSummaryZone;
        $layout = fn (array $set) => ['components' => [['type' => 'purchase', 'settings' => $set]]];

        $this->assertCount(1, $zone->warnings($layout(['bg' => '#ffffff', 'color' => '#f0f0f0'])));
        $this->assertStringContainsString('1.1', $zone->warnings($layout(['bg' => '#ffffff', 'color' => '#f0f0f0']))[0]);
        $this->assertSame([], $zone->warnings($layout(['bg' => '#2e7d32', 'color' => '#ffffff'])), 'enough contrast: no warning');
        $this->assertSame([], $zone->warnings($layout(['bg' => null, 'color' => null])), 'the shop\'s own colours: nothing to warn about here');
    }

    public function test_a_member_without_the_permission_cannot_apply_the_settings(): void
    {
        $screen = $this->screen()->set('props.settings.bg', '#2E7D32');
        $this->app->forgetScopedInstances();
        $this->actingAsStaff('Product Entry');
        $screen->call('apply')->assertForbidden();

        $this->assertNull($this->settings()->get(AppearanceSettings::SUMMARY_DRAFT_KEY));
    }
}
