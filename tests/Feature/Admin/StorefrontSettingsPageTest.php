<?php

namespace Tests\Feature\Admin;

use App\Filament\NavigationGroup;
use App\Filament\Pages\Settings\StorefrontSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\StorefrontHomeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Stage S3-V: the admin page «Витрина» ("Storefront"): the heading and the text of the home page. Reached by the content permission only; saving writes
 * the two keys, an empty field forgets its key (the language default), over-long or not-plain input is refused, and the help link has its anchor.
 */
class StorefrontSettingsPageTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        app()->setLocale('en');
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    public function test_the_content_roles_reach_the_page_and_a_role_without_the_permission_does_not(): void
    {
        foreach (['Administrator', 'Manager'] as $role) {
            $this->actingAsStaff($role);
            $this->get(StorefrontSettings::getUrl())->assertOk();
        }

        $this->actingAsStaff('Product Entry');
        $this->get(StorefrontSettings::getUrl())->assertForbidden();
    }

    public function test_it_is_in_the_admin_group_with_its_own_label(): void
    {
        $this->assertSame(NavigationGroup::ADMIN, StorefrontSettings::getNavigationGroup());
        $this->assertSame('Storefront', StorefrontSettings::getNavigationLabel());
        app()->setLocale('bg');
        $this->assertSame('Витрина', StorefrontSettings::getNavigationLabel());
    }

    public function test_saving_writes_the_two_keys_and_reading_shows_them(): void
    {
        $this->actingAsStaff('Administrator');

        Livewire::test(StorefrontSettings::class)
            ->fillForm(['home_title' => '  Spring is here  ', 'home_text' => 'New arrivals every week.'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('Spring is here', $this->settings()->get(StorefrontHomeSettings::TITLE_KEY), 'trimmed');
        $this->assertSame('New arrivals every week.', $this->settings()->get(StorefrontHomeSettings::TEXT_KEY));

        $this->app->forgetScopedInstances();
        Livewire::test(StorefrontSettings::class)->assertFormSet(['home_title' => 'Spring is here', 'home_text' => 'New arrivals every week.']);
    }

    public function test_an_empty_field_forgets_its_key_so_the_language_default_returns(): void
    {
        $this->actingAsStaff('Administrator');
        $this->settings()->set(StorefrontHomeSettings::TITLE_KEY, 'Old title');
        $this->settings()->set(StorefrontHomeSettings::TEXT_KEY, 'Old text');

        Livewire::test(StorefrontSettings::class)->fillForm(['home_title' => '', 'home_text' => '   '])->call('save')->assertHasNoFormErrors();

        $this->assertNull($this->settings()->get(StorefrontHomeSettings::TITLE_KEY));
        $this->assertNull($this->settings()->get(StorefrontHomeSettings::TEXT_KEY));
        $this->assertDatabaseMissing('site_settings', ['key' => StorefrontHomeSettings::TITLE_KEY]);
    }

    public function test_over_length_and_non_plain_input_is_refused_and_nothing_is_written(): void
    {
        $this->actingAsStaff('Administrator');

        Livewire::test(StorefrontSettings::class)
            ->fillForm(['home_title' => str_repeat('a', StorefrontHomeSettings::MAX_TITLE + 1), 'home_text' => 'ok'])
            ->call('save')->assertHasFormErrors(['home_title']);
        Livewire::test(StorefrontSettings::class)
            ->fillForm(['home_title' => 'ok', 'home_text' => str_repeat('b', StorefrontHomeSettings::MAX_TEXT + 1)])
            ->call('save')->assertHasFormErrors(['home_text']);
        Livewire::test(StorefrontSettings::class)
            ->fillForm(['home_title' => "Evil\u{202E}title", 'home_text' => "bell\x07"])
            ->call('save')->assertHasFormErrors(['home_title', 'home_text']);

        $this->assertNull($this->settings()->get(StorefrontHomeSettings::TITLE_KEY));
        $this->assertNull($this->settings()->get(StorefrontHomeSettings::TEXT_KEY));
    }

    public function test_the_values_at_the_limits_are_accepted(): void
    {
        $this->actingAsStaff('Administrator');

        Livewire::test(StorefrontSettings::class)
            ->fillForm(['home_title' => str_repeat('я', StorefrontHomeSettings::MAX_TITLE), 'home_text' => str_repeat('я', StorefrontHomeSettings::MAX_TEXT)])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame(StorefrontHomeSettings::MAX_TITLE, mb_strlen((string) $this->settings()->get(StorefrontHomeSettings::TITLE_KEY)), 'characters, not bytes');
    }

    public function test_the_page_carries_the_help_link_to_an_anchor_that_exists_in_both_languages(): void
    {
        $this->actingAsStaff('Administrator');
        $html = $this->get(StorefrontSettings::getUrl())->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<a href="[^"]*\/admin\/help\/pages#action-storefront-home"/', $html);

        foreach (['bg', 'en'] as $locale) {
            $this->assertStringContainsString('{#action-storefront-home}', (string) file_get_contents(resource_path("help/{$locale}/pages.md")));
        }
    }

    public function test_the_counter_hint_shows_the_limit(): void
    {
        $this->actingAsStaff('Administrator');
        $html = Livewire::test(StorefrontSettings::class)->fillForm(['home_title' => 'abc'])->html();

        $this->assertStringContainsString('3 / '.StorefrontHomeSettings::MAX_TITLE, $html);
    }
}
