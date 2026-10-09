<?php

namespace Tests\Feature\Mail;

use App\Filament\Pages\Settings\BankTransferSettings;
use App\Filament\StaffPanelUser;
use App\Mail\BankTransferDetails;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BankTransferSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithRole(string $roleName): StaffPanelUser
    {
        $roles = app(RoleRepository::class);
        $role = $roles->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roles);
            $role = $roles->findSystemRoleByName($roleName);
        }

        $staff = Staff::create(strtolower(str_replace(' ', '.', $roleName)).'@example.com', app(PasswordHasher::class)->hash('password123'), $roleName, $role);
        app(StaffRepository::class)->save($staff);

        return StaffPanelUser::find($staff->id());
    }

    private function actAsPanelAdministrator(): void
    {
        $this->actingAs($this->staffWithRole('Administrator'), 'staff');
    }

    private function stored(string $key): ?string
    {
        return app(SiteSettingsRepository::class)->get($key);
    }

    public function test_saving_valid_details_stores_them_normalised(): void
    {
        $this->actAsPanelAdministrator();

        Livewire::test(BankTransferSettings::class)
            ->fillForm([
                'account_holder' => ' Raf Ltd ',
                'bank_name' => 'Test Bank',
                'iban' => 'bg80 bnbg 9661 1020 3456 78',
                'bic' => 'bnbg bgsd',
                'deadline_days' => '5',
                'instructions' => "Line one\r\nLine two",
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Raf Ltd', $this->stored(BankTransferDetails::HOLDER));
        $this->assertSame('BG80BNBG96611020345678', $this->stored(BankTransferDetails::IBAN));
        $this->assertSame('BNBGBGSD', $this->stored(BankTransferDetails::BIC));
        $this->assertSame('5', $this->stored(BankTransferDetails::DEADLINE_DAYS));
        $this->assertSame("Line one\nLine two", $this->stored(BankTransferDetails::INSTRUCTIONS));

        $details = BankTransferDetails::fromSettings(app(SiteSettingsRepository::class));
        $this->assertFalse($details->isEmpty());
        $this->assertSame(5, $details->deadlineDays);
    }

    public function test_an_empty_field_forgets_its_key_and_all_empty_means_an_empty_block(): void
    {
        $this->actAsPanelAdministrator();
        app(SiteSettingsRepository::class)->set(BankTransferDetails::IBAN, 'BG80BNBG96611020345678');

        Livewire::test(BankTransferSettings::class)
            ->fillForm(['account_holder' => '', 'bank_name' => '', 'iban' => '', 'bic' => '', 'deadline_days' => null, 'instructions' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($this->stored(BankTransferDetails::IBAN));
        $this->assertTrue(BankTransferDetails::fromSettings(app(SiteSettingsRepository::class))->isEmpty());
    }

    public function test_invalid_values_are_refused_without_echoing_the_iban(): void
    {
        $this->actAsPanelAdministrator();

        $component = Livewire::test(BankTransferSettings::class)
            ->fillForm([
                'account_holder' => str_repeat('a', 121),
                'bank_name' => "Bank\r\nBcc: x@y.z",
                'iban' => 'BG80BNBG96611020345679',
                'bic' => 'NOTABIC',
                'deadline_days' => '61',
                'instructions' => "bad\0byte",
            ])
            ->call('save');

        $component->assertHasFormErrors(['account_holder', 'bank_name', 'iban', 'bic', 'deadline_days', 'instructions']);
        $this->assertNull($this->stored(BankTransferDetails::IBAN));

        $messages = collect($component->errors()->all())->implode(' ');
        $this->assertStringNotContainsString('BG80BNBG96611020345679', $messages);
    }

    public function test_an_iban_with_a_wrong_check_digit_and_an_instructions_text_over_the_limit_are_refused(): void
    {
        $this->actAsPanelAdministrator();

        Livewire::test(BankTransferSettings::class)
            ->fillForm(['iban' => 'BG81BNBG96611020345678', 'instructions' => str_repeat('x', BankTransferDetails::MAX_INSTRUCTIONS + 1)])
            ->call('save')
            ->assertHasFormErrors(['iban', 'instructions']);
    }

    public function test_only_staff_with_settings_manage_can_open_the_page(): void
    {
        $this->actingAs($this->staffWithRole('Manager'), 'staff');
        $manager = BankTransferSettings::canAccess();

        $this->actAsPanelAdministrator();
        $this->assertTrue(BankTransferSettings::canAccess());
        $this->assertFalse($manager, 'the Manager role does not hold settings_manage');
    }
}
