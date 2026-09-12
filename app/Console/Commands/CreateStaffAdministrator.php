<?php

namespace App\Console\Commands;

use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Staff;
use Illuminate\Console\Command;

/**
 * `php artisan staff:create-administrator` — bootstraps the first Staff
 * (Administrator role), per staff-access-domain-design.md §8. A chicken-
 * and-egg problem answered explicitly there: STAFF_MANAGE is needed to
 * create staff, and initially no staff exist.
 *
 * NO DEFAULT CREDENTIALS EVER SHIP — this command always prompts for
 * name/email/password interactively; a seeded admin@example.com with a
 * known password is how installations get compromised in their first
 * week (design doc §8's own reasoning).
 *
 * Refuses to run if any Staff already exists, unless --force is given —
 * bootstrapping is a one-time act; a command that can silently mint
 * administrators forever on a live system is itself a hazard.
 */
class CreateStaffAdministrator extends Command
{
    protected $signature = 'staff:create-administrator {--force}';

    protected $description = 'Create the first Staff (Administrator role) — see staff-access-domain-design.md §8';

    public function handle(StaffRepository $staffRepository, RoleRepository $roleRepository, PasswordHasher $passwordHasher): int
    {
        if ($staffRepository->any() && ! $this->option('force')) {
            $this->error('A Staff member already exists. Pass --force to create another anyway.');

            return self::FAILURE;
        }

        $administratorRole = $roleRepository->findSystemRoleByName('Administrator');

        if ($administratorRole === null) {
            // Fail loud, do not fabricate a Role — mirrors
            // EloquentPriceResolver's fail-loud-on-unseeded-system-list
            // posture.
            $this->error(
                'The Administrator system role has not been seeded. Run '.
                '`php artisan db:seed --class=EasyCo\\Staff\\Seeders\\StaffSystemRolesSeeder` first.'
            );

            return self::FAILURE;
        }

        $name = $this->ask('Name');
        $email = $this->ask('Email');
        $password = $this->secret('Password');

        $passwordHash = $passwordHasher->hash($password);
        $staff = Staff::create($email, $passwordHash, $name, $administratorRole);
        $staffRepository->save($staff);

        $this->info("Administrator created: {$staff->email()}");

        return self::SUCCESS;
    }
}
