<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\Settings\AppearanceSettings as AppearancePage;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\AppearanceWriter;
use App\Storefront\Cookies\Contrast;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * DM-1: the «Тъмен режим» section of «Облик»: always there (CC-2: the dev flag is gone) (toggle, colour, two presets, the live ratios, the no-dark-logo warning) while it is on, a refused colour
 * is not saved and says why, and the save needs the page's own permission. The effect is judged (what is stored), never "an exception was thrown".
 */
class DarkModeAppearancePageTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

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

    private function page(): Testable
    {
        return Livewire::test(AppearancePage::class);
    }

    /** @return array<string, string> */
    private function stored(): array
    {
        $rows = DB::table('site_settings')->whereIn('key', [AppearanceSettings::MODE, AppearanceSettings::DARK_BUTTON])->pluck('value', 'key')->all();
        ksort($rows);

        return $rows;
    }

    public function test_the_section_and_its_fields_are_always_on_the_page(): void
    {
        $this->actingAsStaff('Administrator');

        $this->page()->assertSee('Dark mode of the site')->assertSee('Button colour (dark mode)')->assertSee('Orange')->assertSee('White');
    }

    public function test_a_save_of_the_other_fields_keeps_the_stored_dark_mode_and_colour(): void
    {
        AppearanceWriter::save(array_replace(AppearanceWriter::current(), ['dark_mode' => true, 'dark_button' => '#ffffff']));
        $this->actingAsStaff('Administrator');

        $this->page()->fillForm(['c_accent' => '#00aa00'])->call('save')->assertHasNoFormErrors();

        $this->assertSame([AppearanceSettings::DARK_BUTTON => '#ffffff', AppearanceSettings::MODE => 'dark'], $this->stored(), 'the form carries them, unchanged');
        $this->assertSame('#00aa00', $this->settings()->get('appearance.color.accent'));
    }

    public function test_the_switch_and_a_colour_are_saved_and_come_back_on_the_form(): void
    {
        $this->actingAsStaff('Administrator');

        $this->page()->fillForm(['dark_mode' => true, 'dark_button' => '#FFFFFF'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('dark', $this->settings()->get(AppearanceSettings::MODE));
        $this->assertSame('#ffffff', $this->settings()->get(AppearanceSettings::DARK_BUTTON));
        $this->app->forgetScopedInstances();
        $this->page()->assertFormSet(['dark_mode' => true, 'dark_button' => '#ffffff']);
    }

    public function test_a_preset_button_sets_the_colour(): void
    {
        $this->actingAsStaff('Administrator');

        $this->page()->fillForm(['dark_button' => '#3b82f6'])
            ->callAction(TestAction::make('dark_preset_white')->schemaComponent('dark-presets'))->assertFormSet(['dark_button' => '#ffffff'])
            ->callAction(TestAction::make('dark_preset_orange')->schemaComponent('dark-presets'))->assertFormSet(['dark_button' => '#f97316']);
    }

    public function test_a_refused_colour_shows_the_reason_and_nothing_is_saved_not_even_the_other_fields(): void
    {
        $this->actingAsStaff('Administrator');

        $this->page()->fillForm(['c_accent' => '#00aa00', 'dark_mode' => true, 'dark_button' => '#333333'])
            ->call('save')
            ->assertHasFormErrors(['dark_button']);

        $this->assertSame([], $this->stored());
        $this->assertNull($this->settings()->get('appearance.color.accent'), 'a refused validation saves nothing half-way');

        $this->page()->fillForm(['dark_mode' => true, 'dark_button' => 'orange'])->call('save')->assertHasFormErrors(['dark_button']);
        $this->assertSame([], $this->stored());
    }

    public function test_the_live_message_has_the_computed_text_colour_and_both_ratios_and_a_refusal_says_why(): void
    {
        $this->actingAsStaff('Administrator');
        $orange = Contrast::darkButton('#f97316');
        $grey = Contrast::darkButton('#333333');

        $this->page()->fillForm(['dark_mode' => true, 'dark_button' => '#f97316'])
            ->assertSee('Text on the button: black')
            ->assertSee(number_format($orange['buttonRatio'], 2).':1')
            ->assertSee(number_format($orange['textRatio'], 2).':1');

        $this->page()->fillForm(['dark_mode' => true, 'dark_button' => '#333333'])
            ->assertSee('not visible enough on the black page')
            ->assertSee(number_format($grey['buttonRatio'], 2).':1');
    }

    public function test_the_no_dark_logo_warning_shows_only_with_the_switch_on_and_no_dark_logo(): void
    {
        $this->actingAsStaff('Administrator');
        $warning = 'No dark logo is uploaded';

        $this->page()->fillForm(['dark_mode' => false])->assertDontSee($warning);
        $this->page()->fillForm(['dark_mode' => true])->assertSee($warning);
    }

    public function test_a_role_without_the_permission_cannot_open_or_save(): void
    {
        $this->actingAsStaff('Product Entry');

        $this->get(AppearancePage::getUrl())->assertForbidden();

        $component = Livewire::test(AppearancePage::class);
        $component->assertForbidden();
        $this->assertSame([], $this->stored());
        $this->assertSame(0, DB::table('site_settings')->where('key', 'like', 'appearance.%')->count(), 'nothing saved');
    }

    public function test_the_page_also_works_in_bulgarian(): void
    {
        $this->actingAsStaff('Administrator');
        app()->setLocale('bg');

        $this->page()->assertSee('Тъмен режим на сайта')->assertSee('Цвят на бутоните (тъмен режим)')->assertSee('Оранжево')->assertSee('Бяло');
    }
}
