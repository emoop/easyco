<?php

namespace Tests\Feature;

use App\Services\AuthenticatedStaffResolver;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Exercises AuthenticatedStaffResolver directly — the fix for the
 * "reload Staff via the repository on every permission check" cost
 * flagged three times over (see its own docblock). The countQueries()
 * helper mirrors EloquentVariationRepositoryFindByProductIdQueryCountTest's
 * own established shape, and the mid-request-vs-next-request proof
 * mirrors ProductResourcePriceColumnTest's own
 * test_a_changed_currency_position_is_invisible_mid_request_but_reaches_the_next_one
 * (the same forgetScopedInstances() convention).
 */
class AuthenticatedStaffResolverTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithRole(string $roleName): Staff
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

        return $staff;
    }

    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $callback();

        DB::flushQueryLog();

        return $count;
    }

    public function test_ten_resolves_of_the_same_id_cost_one_real_staff_load(): void
    {
        $staff = $this->staffWithRole('Administrator');
        $resolver = app(AuthenticatedStaffResolver::class);

        // Real load: Staff + its Role (Staff's own class docblock,
        // Part 1) — confirmed 2 queries, not assumed.
        $firstQueries = $this->countQueries(fn () => $resolver->resolveById($staff->id()));
        $this->assertSame(2, $firstQueries);

        $laterQueries = $this->countQueries(function () use ($resolver, $staff): void {
            for ($i = 0; $i < 9; $i++) {
                $resolver->resolveById($staff->id());
            }
        });
        $this->assertSame(0, $laterQueries, '9 further resolves of the SAME id must cost nothing');

        $this->assertSame($staff->id(), $resolver->resolveById($staff->id())->id());
    }

    public function test_two_different_ids_are_memoized_independently(): void
    {
        $admin = $this->staffWithRole('Administrator');
        $manager = $this->staffWithRole('Manager');
        $resolver = app(AuthenticatedStaffResolver::class);

        $resolver->resolveById($admin->id());
        $resolver->resolveById($manager->id());

        // Both already resolved — re-asking for either costs nothing,
        // and each still returns its own, correct Staff.
        $queries = $this->countQueries(function () use ($resolver, $admin, $manager): void {
            $this->assertSame($admin->id(), $resolver->resolveById($admin->id())->id());
            $this->assertSame($manager->id(), $resolver->resolveById($manager->id())->id());
        });
        $this->assertSame(0, $queries);
    }

    public function test_an_unknown_id_resolves_to_null_and_stays_memoized(): void
    {
        $resolver = app(AuthenticatedStaffResolver::class);

        $this->assertNull($resolver->resolveById('999999'));

        $queries = $this->countQueries(fn () => $resolver->resolveById('999999'));
        $this->assertSame(0, $queries, 'a null result must be memoized too, not re-queried every time');
    }

    /**
     * The other half of this fix: memoization is per REQUEST, not per
     * PROCESS. forgetScopedInstances() is the real mechanism Laravel
     * itself uses to end a scoped binding's lifetime between requests
     * in a long-running worker (Octane) — a fresh PHP-FPM-style process
     * gets the equivalent for free via a brand-new container.
     */
    public function test_a_staff_change_is_invisible_mid_request_but_reaches_the_next_one(): void
    {
        $staff = $this->staffWithRole('Administrator');

        $this->assertTrue(app(AuthenticatedStaffResolver::class)->resolveById($staff->id())->isActive());

        StaffModel::find($staff->id())->update(['is_active' => false]);

        $this->assertTrue(
            app(AuthenticatedStaffResolver::class)->resolveById($staff->id())->isActive(),
            'mid-request, the already-memoized Staff must not change'
        );

        $this->app->forgetScopedInstances();

        $this->assertFalse(
            app(AuthenticatedStaffResolver::class)->resolveById($staff->id())->isActive(),
            'a new scoped instance (the next request) must read the current state'
        );
    }
}
