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
 * CT-4: the three controls «Филтър по марка / сезон / етикет» of the «Каталог» zone: they open at the defaults (list, list, off), are shown with EVERY layout, offer the closed list of four, go through apply → publish
 * like the other catalog settings, and a value outside the list is refused under its field.
 */
class DesignCatalogBuiltinFiltersTest extends TestCase
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

    public function test_the_controls_open_at_the_defaults_and_are_shown_with_every_layout(): void
    {
        $screen = $this->screen();

        $screen->assertSet('props.filterBrand', 'list')->assertSet('props.filterSeason', 'list')->assertSet('props.filterTag', 'off');

        foreach (['full', 'sidebar'] as $layout) {
            $html = $screen->set('props.layout', $layout)->html();

            foreach (['Brand filter', 'Season filter', 'Tag filter', 'Not shown', 'List', 'Links', 'Buttons', 'Default «Not shown».'] as $text) {
                $this->assertStringContainsString($text, $html, "{$layout}: {$text}");
            }
        }
    }

    public function test_apply_writes_the_draft_and_publish_makes_the_choices_live(): void
    {
        $screen = $this->screen()->set('props.filterBrand', 'links')->set('props.filterSeason', 'off')->set('props.filterTag', 'buttons')->call('apply')->assertHasNoErrors();

        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::CATALOG_DESIGN_KEY), 'the shop is not touched by «Apply»');
        $draft = $this->stored(AppearanceSettings::CATALOG_DESIGN_DRAFT_KEY);
        $this->assertSame(['links', 'off', 'buttons'], [$draft['filterBrand'], $draft['filterSeason'], $draft['filterTag']]);

        $screen->call('publish')->assertHasNoErrors();

        $live = $this->stored(AppearanceSettings::CATALOG_DESIGN_KEY);
        $this->assertSame(['links', 'off', 'buttons'], [$live['filterBrand'], $live['filterSeason'], $live['filterTag']]);
    }

    public function test_a_value_outside_the_list_is_refused_under_its_field_and_nothing_is_written(): void
    {
        foreach (['filterBrand', 'filterSeason', 'filterTag'] as $field) {
            $screen = $this->screen()->set('props.'.$field, 'chips')->call('apply');

            $screen->assertHasErrors(['props.'.$field]);
            $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::CATALOG_DESIGN_DRAFT_KEY), $field);
        }
    }
}
