<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings\LocaleSettings;
use App\Filament\StaffPanelUser;
use App\Settings\Contracts\SiteSettingsRepository;
use DateTimeZone;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the real, production LocaleSettings page — Permission::
 * SETTINGS_MANAGE's first real consumer. Mirrors the established
 * Livewire-testing convention (Part 1) and the StaffPanelUser/
 * password_hash_staff gotchas already documented in
 * RoleResourceTest/StaffResourceTest.
 */
class LocaleSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithRole(string $roleName): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName($roleName);
        }

        $email = strtolower(str_replace(' ', '.', $roleName)).'@example.com';
        $staff = Staff::create($email, app(PasswordHasher::class)->hash('password123'), $roleName, $role);
        app(StaffRepository::class)->save($staff);

        return StaffPanelUser::find($staff->id());
    }

    private function actingAsPanelAdministrator(): StaffPanelUser
    {
        $model = $this->staffWithRole('Administrator');
        $this->actingAs($model, 'staff');

        return $model;
    }

    public function test_changing_the_locale_through_the_settings_page_persists_it(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(LocaleSettings::class)
            ->fillForm(['locale' => 'en'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('en', app(SiteSettingsRepository::class)->get('site.locale'));
    }

    public function test_the_settings_page_defaults_to_bulgarian_when_never_set(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(LocaleSettings::class)
            ->assertSchemaStateSet(['locale' => 'bg']);
    }

    public function test_the_real_permission_matrix_for_settings_access(): void
    {
        $this->actingAsPanelAdministrator();
        $this->get(LocaleSettings::getUrl())->assertOk();

        $manager = $this->staffWithRole('Manager');
        $this->actingAs($manager, 'staff');
        session()->forget('password_hash_staff');
        $this->get(LocaleSettings::getUrl())->assertForbidden();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');
        $this->get(LocaleSettings::getUrl())->assertForbidden();
    }

    public function test_the_activity_log_tab_defaults_to_off_with_a_twelve_month_retention(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(LocaleSettings::class)
            ->assertSchemaStateSet([
                'activity_log_enabled' => false,
                'activity_log_retention_months' => 12,
            ]);
    }

    public function test_enabling_the_activity_log_and_changing_retention_persists_both_settings(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(LocaleSettings::class)
            ->fillForm([
                'activity_log_enabled' => true,
                'activity_log_retention_months' => 18,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(SiteSettingsRepository::class);
        $this->assertSame('1', $settings->get('admin.activity_log_enabled'));
        $this->assertSame('18', $settings->get('admin.activity_log_retention_months'));
    }

    /**
     * A real, confirmed Filament behavior this task's own implementation
     * had to work around: a component hidden by ->visible() is not
     * dehydrated into getState() at all — so saving while the toggle is
     * off (the retention Select hidden) must not blow up, and must fall
     * back to a sane retention value rather than persisting nothing.
     */
    public function test_saving_with_the_activity_log_toggle_off_does_not_error_and_keeps_a_real_retention_value(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(LocaleSettings::class)
            ->fillForm(['locale' => 'en'])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(SiteSettingsRepository::class);
        $this->assertSame('0', $settings->get('admin.activity_log_enabled'));
        $this->assertSame('12', $settings->get('admin.activity_log_retention_months'));
    }

    /**
     * 'suffix_space' — the exact, byte-for-byte behavior
     * PriceDisplayFormatter had before this setting existed
     * ("{amount} {symbol}"). An installation that never visits the
     * Currency tab must render identically to before.
     */
    public function test_the_currency_tab_defaults_to_suffix_with_a_space_when_never_set(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(LocaleSettings::class)
            ->assertSchemaStateSet(['currency_symbol_position' => 'suffix_space']);
    }

    public function test_changing_the_currency_symbol_position_persists_it(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(LocaleSettings::class)
            ->fillForm(['currency_symbol_position' => 'prefix'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('prefix', app(SiteSettingsRepository::class)->get('site.currency_symbol_position'));
    }

    /**
     * T2, half one: the select is backed by PHP's own complete list, not by
     * an array somebody typed. Asserted against DateTimeZone::listIdentifiers()
     * itself — so the test cannot drift with the implementation — and the
     * order is checked too, because array_combine() preserving listIdentifiers()
     * order is what makes the dropdown read in PHP's own (grouped, sorted)
     * order rather than in some incidental one.
     */
    public function test_the_time_zone_options_are_phps_own_identifier_list(): void
    {
        $this->actingAsPanelAdministrator();

        $options = Livewire::test(LocaleSettings::class)
            ->instance()->form->getComponent('timezone')->getOptions();

        $this->assertSame(DateTimeZone::listIdentifiers(), array_keys($options));

        // Spot checks in the three directions that matter: this installation's
        // own zone, a zone in a completely different continent (so the list is
        // not a European shortlist), and the value's own label — an IANA
        // identifier IS what the merchant reads.
        $this->assertContains('Europe/Sofia', array_keys($options));
        $this->assertContains('Africa/Nairobi', array_keys($options));
        $this->assertSame('America/New_York', $options['America/New_York']);

        // And the real list genuinely reaches the screen, not just the
        // component object: a zone half a world away is present in the
        // rendered page's own markup. Matched on the city alone — Filament
        // hands the option list to its Select's own Alpine payload, which
        // JSON-escapes the '/' separator, so the identifier is there in
        // escaped form rather than byte-for-byte the way a label would be.
        $this->get(LocaleSettings::getUrl())->assertOk()->assertSee('Nairobi');
    }

    /**
     * The default shown before anyone has ever saved this setting is read from
     * the SAME place the middleware reads it (config/services.php), so the
     * form and the running panel cannot disagree about what zone is in effect.
     */
    public function test_the_time_zone_tab_defaults_to_the_configured_default_when_never_set(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(LocaleSettings::class)
            ->assertSchemaStateSet(['timezone' => 'Europe/Sofia']);

        config(['services.site.default_timezone' => 'America/New_York']);

        Livewire::test(LocaleSettings::class)
            ->assertSchemaStateSet(['timezone' => 'America/New_York']);
    }

    public function test_saving_a_real_time_zone_persists_it(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(LocaleSettings::class)
            ->fillForm(['timezone' => 'America/New_York'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('America/New_York', app(SiteSettingsRepository::class)->get('site.timezone'));
    }

    /**
     * T2, half two: a value that is not a real zone is refused with a
     * translatable reason AND nothing is written. The "nothing is written"
     * half is the one that matters: Filament's own getState() validates
     * before it returns (Filament\Schemas\Concerns\HasState), so save() never
     * runs its body on invalid input — this test pins that rather than
     * assuming it.
     */
    public function test_an_invalid_time_zone_is_refused_with_a_translatable_reason_and_nothing_is_saved(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(LocaleSettings::class)
            ->fillForm(['timezone' => 'Not/A/Zone'])
            ->call('save')
            ->assertHasFormErrors(['timezone' => __('settings.timezone.invalid')]);

        $this->assertNull(app(SiteSettingsRepository::class)->get('site.timezone'), 'an invalid zone must not be stored');
    }

    /**
     * The two strings PHP itself accepts that are NOT identifiers — a UTC
     * offset and a zone abbreviation — are refused too. This is what the
     * rule's second check is for: an offset is exactly what this setting must
     * never store (it is wrong for half the year in any DST zone), and PHP's
     * DateTimeZone constructor alone would happily accept it.
     */
    public function test_an_offset_or_an_abbreviation_is_refused_even_though_php_can_parse_it(): void
    {
        $this->actingAsPanelAdministrator();

        foreach (['+02:00', 'CET'] as $notAnIdentifier) {
            Livewire::test(LocaleSettings::class)
                ->fillForm(['timezone' => $notAnIdentifier])
                ->call('save')
                ->assertHasFormErrors(['timezone']);
        }

        $this->assertNull(app(SiteSettingsRepository::class)->get('site.timezone'));
    }

    /**
     * The refusal is genuinely translatable: both locales ship the message,
     * and the Bulgarian one is real Bulgarian rather than a copy of the
     * English key — which is also why this project's own lang key is used
     * instead of Laravel's built-in `timezone` rule message (that rule ships
     * English only here).
     */
    public function test_the_invalid_time_zone_message_exists_in_both_shipped_locales(): void
    {
        $this->assertSame(
            'Enter a real time zone identifier, for example Europe/Sofia.',
            __('settings.timezone.invalid', [], 'en'),
        );

        $this->assertSame(
            'Въведете валиден идентификатор на часова зона, например Europe/Sofia.',
            __('settings.timezone.invalid', [], 'bg'),
        );
    }
}
