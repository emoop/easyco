<?php

namespace EasyCo\Staff;

use EasyCo\Staff\Enums\Permission;
use InvalidArgumentException;
use LogicException;

/**
 * A named, data-driven bundle of permissions — see
 * staff-access-domain-design.md §4 for the full field list and
 * reasoning. Mirrors EasyCo\Pricing\PriceList's shape: private
 * constructor, named assertion methods, a public create() factory,
 * reconstituteFromStorage() for the persistence layer, and a one-time
 * assignId().
 *
 * ROLES ARE DATA, NOT AN ENUM (design doc §4) — the three shipped
 * defaults (Administrator/Manager/Product Entry, seeded via
 * createSystemRole() below) are a good starting set, not a ceiling. A
 * merchant may create their own role with any subset of Permission.
 *
 * ADMINISTRATOR IS NOT A BYPASS — stated here as plainly as design doc
 * §4.1 states it, because this is the single easiest thing in this
 * class to get wrong: there is no isAdministrator()-style shortcut
 * anywhere in this class, and none should ever be added anywhere else
 * in this codebase either. Administrator holds every Permission
 * explicitly, in its own permissions array, exactly like any other
 * role — grants() checks membership the same way regardless of which
 * role is asking. The moment a shortcut exists, a new Permission added
 * later would be silently granted to Administrator without anyone
 * deciding that it should be.
 */
final class Role
{
    /** @param Permission[] $permissions */
    private function __construct(
        private ?string $id,
        private string $name,
        private readonly array $permissions,
        private readonly bool $isSystem,
    ) {
        self::assertValidName($name);
        self::assertValidPermissions($permissions);
    }

    private static function assertValidName(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Role name must not be empty.');
        }
    }

    /**
     * An empty array is valid — a role that grants nothing (design doc
     * §11 explicitly requires testing this case). What's rejected is an
     * element that isn't a real Permission case at all.
     */
    private static function assertValidPermissions(array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (! $permission instanceof Permission) {
                $value = is_scalar($permission) ? (string) $permission : get_debug_type($permission);

                throw new InvalidArgumentException(
                    "Role permissions must all be Permission instances; got \"{$value}\"."
                );
            }
        }
    }

    /**
     * The only path regular, merchant-facing code (present or future
     * admin UI) may use to create a Role — always isSystem: false. It
     * is structurally impossible to produce an isSystem=true role
     * through this factory; see createSystemRole() for the one other
     * path.
     */
    public static function create(string $name, array $permissions): self
    {
        return new self(id: null, name: $name, permissions: $permissions, isSystem: false);
    }

    /**
     * SEEDING-LAYER ONLY — mirrors PriceList::createSystemList()'s own
     * posture exactly: not a business operation regular application
     * code should ever reach for. Creates one of the three reserved
     * system Roles (Administrator/Manager/Product Entry — design doc
     * §4.1) that ship as defaults. Only the one-time seeding mechanism
     * (StaffSystemRolesSeeder) should call this.
     */
    public static function createSystemRole(string $name, array $permissions): self
    {
        return new self(id: null, name: $name, permissions: $permissions, isSystem: true);
    }

    /**
     * Reconstitutes a Role exactly as it exists in storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the
     * given data is already-valid data read back from storage. This
     * method is not a business operation and application code must never
     * call it directly; only a repository implementation reconstructing
     * this entity from an already-validated row should call it.
     */
    public static function reconstituteFromStorage(string $id, string $name, array $permissions, bool $isSystem): self
    {
        return new self(id: $id, name: $name, permissions: $permissions, isSystem: $isSystem);
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('Role already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return Permission[] */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function grants(Permission $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
