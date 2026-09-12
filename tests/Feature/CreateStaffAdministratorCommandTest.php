<?php

namespace Tests\Feature;

use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateStaffAdministratorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_happy_path_creates_a_working_administrator(): void
    {
        $this->seed(StaffSystemRolesSeeder::class);

        $this->artisan('staff:create-administrator')
            ->expectsQuestion('Name', 'Ivan Ivanov')
            ->expectsQuestion('Email', 'ivan@example.com')
            ->expectsQuestion('Password', 'a-strong-password')
            ->assertExitCode(0);

        $this->assertSame(1, StaffModel::count());

        $staff = app(StaffRepository::class)->findByEmail('ivan@example.com');
        $this->assertNotNull($staff);
        $this->assertSame('Administrator', $staff->role()->name());
        $this->assertTrue($staff->isActive());

        // Never compare the raw hash string — verify via the hasher.
        $this->assertTrue(app(PasswordHasher::class)->verify('a-strong-password', $staff->passwordHash()));
    }

    public function test_refuses_to_run_again_without_force_when_a_staff_already_exists(): void
    {
        $this->seed(StaffSystemRolesSeeder::class);

        $this->artisan('staff:create-administrator')
            ->expectsQuestion('Name', 'Ivan Ivanov')
            ->expectsQuestion('Email', 'ivan@example.com')
            ->expectsQuestion('Password', 'a-strong-password')
            ->assertExitCode(0);

        $this->artisan('staff:create-administrator')
            ->assertExitCode(1);

        $this->assertSame(1, StaffModel::count());
    }

    public function test_runs_again_with_force_when_a_staff_already_exists(): void
    {
        $this->seed(StaffSystemRolesSeeder::class);

        $this->artisan('staff:create-administrator')
            ->expectsQuestion('Name', 'Ivan Ivanov')
            ->expectsQuestion('Email', 'ivan@example.com')
            ->expectsQuestion('Password', 'a-strong-password')
            ->assertExitCode(0);

        $this->artisan('staff:create-administrator --force')
            ->expectsQuestion('Name', 'Maria Petrova')
            ->expectsQuestion('Email', 'maria@example.com')
            ->expectsQuestion('Password', 'another-strong-password')
            ->assertExitCode(0);

        $this->assertSame(2, StaffModel::count());
    }

    public function test_fails_with_a_clear_message_when_the_administrator_role_has_not_been_seeded(): void
    {
        $this->artisan('staff:create-administrator')
            ->assertExitCode(1);

        $this->assertSame(0, StaffModel::count());
    }
}
