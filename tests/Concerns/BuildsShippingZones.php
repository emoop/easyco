<?php

namespace Tests\Concerns;

use App\Filament\StaffPanelUser;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Extensibility\Hook;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingMethod;
use EasyCo\Shipping\ShippingZone;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Fixtures for the shipping-zone admin tests (stage 5c): staff, zones, methods, the audit setting and hook spies. */
trait BuildsShippingZones
{
    /** @var list<array{string, array, int, bool}> [hook name, arguments, transaction level, the zone row existed] */
    private array $hookCalls = [];

    private function actingAsStaff(string $roleName): StaffPanelUser
    {
        $roles = app(RoleRepository::class);
        $role = $roles->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roles);
            $role = $roles->findSystemRoleByName($roleName);
        }

        $staff = Staff::create(Str::slug($roleName).'-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), $roleName.' Tester', $role);
        app(StaffRepository::class)->save($staff);

        return $this->actingAsPanelUser($staff->id());
    }

    /** @param list<\EasyCo\Staff\Enums\Permission> $permissions */
    private function actingAsCustomRole(array $permissions): StaffPanelUser
    {
        $role = Role::create('Custom '.Str::uuid(), $permissions);
        app(RoleRepository::class)->save($role);
        $staff = Staff::create('custom-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), 'Custom Tester', $role);
        app(StaffRepository::class)->save($staff);

        return $this->actingAsPanelUser($staff->id());
    }

    private function actingAsPanelUser(string $staffId): StaffPanelUser
    {
        $model = StaffPanelUser::find($staffId);
        $this->actingAs($model, 'staff');
        session()->forget('password_hash_staff');

        return $model;
    }

    /** Field changes are written only while the activity log is on; a test of the audit turns it on. */
    private function activityLogOn(): void
    {
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
    }

    private function zone(string $name, int $sortOrder, array $countries = ['BG'], ?array $settlements = null, ?array $postcodes = null): ShippingZone
    {
        $zone = ShippingZone::create($name, $sortOrder, $countries, $settlements, $postcodes);
        app(ShippingZoneRepository::class)->save($zone);

        return $zone;
    }

    private function method(string $zoneId, string $name = 'Courier', int $amount = 500): ShippingMethod
    {
        $method = ShippingMethod::create($zoneId, $name, ShippingMethodKind::FLAT, 0, true, $amount, [], null, null, false);
        app(ShippingMethodRepository::class)->save($method);

        return $method;
    }

    /** The zone names in match order, read through the repository. */
    private function zoneNames(): array
    {
        return array_map(static fn (ShippingZone $zone): string => $zone->name(), app(ShippingZoneRepository::class)->allOrdered());
    }

    /** The stored sort orders in match order. */
    private function sortOrders(): array
    {
        return array_map(static fn (ShippingZone $zone): int => $zone->sortOrder(), app(ShippingZoneRepository::class)->allOrdered());
    }

    /**
     * Spies on the four zone hooks. Each call records the arguments, the transaction depth at the moment the listener
     * ran, and — for a listener that needs it — whether the zone row was already visible.
     */
    private function spyOnZoneHooks(): int
    {
        $baseline = DB::transactionLevel();

        foreach (['shipping.zone.created', 'shipping.zone.updated', 'shipping.zone.deleted', 'shipping.zone.reordered'] as $name) {
            Hook::action($name, function (...$arguments) use ($name): void {
                $this->hookCalls[] = [$name, $arguments, DB::transactionLevel(), true];
            });
        }

        return $baseline;
    }

    private function auditRows(): array
    {
        return DB::table('activity_log')->where('entity_type', 'shipping_zone')->orderBy('id')->get()->all();
    }
}
