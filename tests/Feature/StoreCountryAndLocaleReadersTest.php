<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings\LocaleSettings;
use App\Filament\StaffPanelUser;
use App\Http\Middleware\ApplyStoreLocale;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\CountryNames;
use App\Settings\Exceptions\CountryDataUnavailableException;
use App\Settings\Exceptions\StoreCountryNotConfiguredException;
use App\Settings\StoreCountry;
use App\Settings\StoreLocale;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * shipping stage 3.0a: the store country (`site.country`) and the ONE store
 * locale reader (`StoreLocale`).
 */
class StoreCountryAndLocaleReadersTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function signInAsAdministrator(): void
    {
        $roleRepository = app(RoleRepository::class);
        app(StaffSystemRolesSeeder::class)->run($roleRepository);
        $role = $roleRepository->findSystemRoleByName('Administrator');
        $staff = Staff::create('country.admin@example.com', app(PasswordHasher::class)->hash('password123'), 'Administrator', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');
    }

    // --- StoreCountry ---------------------------------------------------------

    public function test_an_unset_store_country_throws_a_named_exception(): void
    {
        $this->expectException(StoreCountryNotConfiguredException::class);

        app(StoreCountry::class)->current();
    }

    public function test_an_unset_store_country_is_null_for_ui_defaults_only(): void
    {
        $this->assertNull(app(StoreCountry::class)->currentOrNull());
    }

    public function test_a_valid_store_country_reads_back(): void
    {
        $this->settings()->set('site.country', 'BG');

        $this->assertSame('BG', app(StoreCountry::class)->current());
        $this->assertSame('BG', app(StoreCountry::class)->currentOrNull());
    }

    public function test_a_malformed_stored_country_is_not_trusted(): void
    {
        foreach (['bg', 'BGR', 'XX1', 'ZZ', 'EU', 'UN'] as $bad) {
            $this->settings()->set('site.country', $bad);

            try {
                app(StoreCountry::class)->current();
                $this->fail("\"{$bad}\" must be refused");
            } catch (StoreCountryNotConfiguredException) {
                $this->addToAssertionCount(1);
            }

            $this->assertNull(app(StoreCountry::class)->currentOrNull());
        }
    }

    public function test_the_country_list_has_the_known_codes_and_not_the_pseudo_regions_in_every_locale(): void
    {
        $bg = CountryNames::forLocale('bg');
        $en = CountryNames::forLocale('en');

        // A lower bound, never an exact count: an ICU/CLDR update may add or
        // retire a code without making this code wrong.
        $this->assertGreaterThanOrEqual(249, count($bg));
        $this->assertGreaterThanOrEqual(249, count($en));

        foreach (['BG', 'GR', 'RO', 'DE', 'XK'] as $code) {
            $this->assertArrayHasKey($code, $en, "{$code} must be offered");
            $this->assertArrayHasKey($code, $bg);
        }

        foreach (['EU', 'ZZ', 'UN'] as $code) {
            $this->assertArrayNotHasKey($code, $en, "{$code} is not a country");
            $this->assertArrayNotHasKey($code, $bg);
        }

        $this->assertEqualsCanonicalizing(array_keys($bg), array_keys($en), 'the same codes in every locale');
        $this->assertSame('България', $bg['BG']);
        $this->assertSame('Bulgaria', $en['BG']);
        $this->assertSame('Kosovo', $en['XK']);
        $this->assertSame('Косово', $bg['XK'], 'named from CLDR like the others');
    }

    public function test_kosovo_is_an_accepted_store_country(): void
    {
        $this->settings()->set('site.country', 'XK');

        $this->assertSame('XK', app(StoreCountry::class)->current());
    }

    public function test_missing_icu_region_data_throws_a_named_exception_not_a_type_error(): void
    {
        // A bundle name ICU does not have: neither the locale nor `en` yields a table.
        $this->expectException(CountryDataUnavailableException::class);

        CountryNames::forLocale('bg', 'ICUDATA-no-such-bundle');
    }

    public function test_the_settings_page_saves_a_valid_country_and_offers_names_in_the_store_locale(): void
    {
        $this->signInAsAdministrator();
        $this->settings()->set('site.locale', 'bg');

        $page = Livewire::test(LocaleSettings::class);

        $this->assertSame('България', $page->instance()->form->getComponent('country')->getOptions()['BG']);

        $page->fillForm(['country' => 'BG'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('BG', $this->settings()->get('site.country'));
    }

    public function test_the_settings_page_refuses_an_invalid_country_and_saves_nothing(): void
    {
        $this->signInAsAdministrator();

        Livewire::test(LocaleSettings::class)
            ->fillForm(['country' => 'ZZ'])
            ->call('save')
            ->assertHasFormErrors(['country']);

        $this->assertNull($this->settings()->get('site.country'));
    }

    public function test_clearing_the_country_on_the_settings_page_unsets_it(): void
    {
        $this->signInAsAdministrator();
        $this->settings()->set('site.country', 'BG');

        Livewire::test(LocaleSettings::class)
            ->assertSchemaStateSet(['country' => 'BG'])
            ->fillForm(['country' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($this->settings()->get('site.country'));
    }

    public function test_the_country_error_message_exists_in_both_shipped_locales(): void
    {
        foreach (['en', 'bg'] as $locale) {
            foreach (['field_label', 'field_help', 'placeholder', 'invalid'] as $key) {
                $this->assertNotSame("settings.country.{$key}", __("settings.country.{$key}", [], $locale), "{$key} missing in {$locale}");
            }
        }
    }

    // --- StoreLocale ------------------------------------------------------------

    public function test_with_no_stored_row_the_middleware_and_the_page_see_the_same_locale_the_config_one(): void
    {
        $this->signInAsAdministrator();
        config(['app.locale' => 'en']);

        $this->assertNull($this->settings()->get('site.locale'));
        $this->assertSame('en', app(StoreLocale::class)->current());

        app(ApplyStoreLocale::class)->handle(request(), fn ($request) => response(''));
        $this->assertSame('en', App::getLocale());

        Livewire::test(LocaleSettings::class)->assertSchemaStateSet(['locale' => 'en']);
    }

    public function test_the_one_fallback_is_the_config_locale_not_a_hard_coded_language(): void
    {
        $this->signInAsAdministrator();
        config(['app.locale' => 'bg']);

        $this->assertSame('bg', app(StoreLocale::class)->current());

        Livewire::test(LocaleSettings::class)->assertSchemaStateSet(['locale' => 'bg']);
    }

    public function test_with_a_stored_row_the_middleware_and_the_page_both_see_it(): void
    {
        $this->signInAsAdministrator();
        config(['app.locale' => 'en']);
        $this->settings()->set('site.locale', 'bg');

        $this->assertSame('bg', app(StoreLocale::class)->current());

        app(ApplyStoreLocale::class)->handle(request(), fn ($request) => response(''));
        $this->assertSame('bg', App::getLocale());

        Livewire::test(LocaleSettings::class)->assertSchemaStateSet(['locale' => 'bg']);
    }
}
