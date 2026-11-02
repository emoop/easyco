<?php

namespace Tests\Feature\Admin\Design;

use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * CD-1d: the «Ширина на страничния панел» control of the «Каталог» zone: it opens at «Wide», is shown only with the side-panel layout, offers the closed list of three, goes through apply → publish like the
 * other catalog settings, and a value outside the list is refused under its field.
 */
class DesignCatalogSideWidthTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        Storage::fake('public');
    }

    private function screen(): Testable
    {
        $this->actingAsStaff('Administrator');

        return Livewire::test(DesignScreen::class)->call('selectZone', 'catalog');
    }

    private function stored(string $key): array
    {
        return (array) json_decode((string) app(SiteSettingsRepository::class)->get($key), true);
    }

    public function test_the_control_opens_at_wide_and_is_shown_only_with_the_side_panel_layout(): void
    {
        $screen = $this->screen();

        $screen->assertSet('props.sideWidth', 'wide');
        $this->assertStringNotContainsString('Side panel width', $screen->html(), 'hidden with the bar layout');

        $html = $screen->set('props.layout', 'sidebar')->html();

        $this->assertStringContainsString('Side panel width', $html);
        $this->assertStringContainsString('«Wide» is the current width', $html, 'the hint says what it does');

        foreach (['Wide', 'Medium', 'Narrow'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    public function test_apply_writes_the_draft_and_publish_makes_the_width_live(): void
    {
        $screen = $this->screen()->set('props.layout', 'sidebar')->set('props.sideWidth', 'narrow')->call('apply')->assertHasNoErrors();

        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::CATALOG_DESIGN_KEY), 'the shop is not touched by «Apply»');
        $this->assertSame('narrow', $this->stored(AppearanceSettings::CATALOG_DESIGN_DRAFT_KEY)['sideWidth']);

        $screen->call('publish')->assertHasNoErrors();

        $this->assertSame('narrow', $this->stored(AppearanceSettings::CATALOG_DESIGN_KEY)['sideWidth']);
        $this->assertSame('sidebar', $this->stored(AppearanceSettings::CATALOG_DESIGN_KEY)['layout']);
    }

    public function test_a_value_outside_the_list_is_refused_under_its_field_and_nothing_is_written(): void
    {
        $screen = $this->screen()->set('props.layout', 'sidebar')->set('props.sideWidth', '20rem')->call('apply');

        $screen->assertHasErrors(['props.sideWidth']);
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::CATALOG_DESIGN_DRAFT_KEY));
    }
}
