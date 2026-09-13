<?php

namespace EasyCo\Staff;

use EasyCo\Staff\Enums\Permission;
use InvalidArgumentException;
use LogicException;

/**
 * A merchant-surface identity — see staff-access-domain-design.md §2 for
 * the full field list and reasoning. Structurally separate from
 * EasyCo\Account\Account: Staff never references Account and Account
 * never references Staff. If the same human is both, that is two rows
 * and neither knows about the other.
 *
 * name IS REQUIRED, UNLIKE Account (which deliberately stores no name at
 * all) — design doc §2's own reasoning, copied here verbatim because it
 * is the reason for the difference: a staff member's name is not
 * decoration, it is what an audit entry means. "Someone gave a 40%
 * discount" is useless; "Petar gave a 40% discount" is the whole point.
 *
 * DESIGN DECISION (made here, not dictated verbatim by the design doc):
 * Staff holds the actual hydrated Role object, not a bare roleId scalar
 * — see design doc §5's own usage examples ($staff->can(...),
 * $staff->role()), which only make sense against a real Role. The
 * repository is responsible for loading the real Role when
 * reconstituting a Staff from storage. Note for whoever works on Part 2:
 * this means EloquentStaffRepository issues a second query (via
 * RoleRepository) on every Staff load — acceptable for now, but the
 * permission middleware will hit this on every merchant request, so
 * it's a candidate for caching or a join later if it shows up as a real
 * cost. Not something to solve in this task.
 */
final class Staff
{
    private function __construct(
        private ?string $id,
        private string $email,
        private string $passwordHash,
        private string $name,
        private Role $role,
        private bool $isActive,
    ) {
        $this->email = self::normalizeAndValidateEmail($email);
        self::assertValidPasswordHash($passwordHash);
        self::assertNotEmpty('name', $name);
        self::assertRoleIsPersisted($role);
    }

    private static function normalizeAndValidateEmail(string $email): string
    {
        $normalized = strtolower($email);

        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("Staff email \"{$email}\" is not a valid email address.");
        }

        return $normalized;
    }

    private static function assertValidPasswordHash(string $passwordHash): void
    {
        if ($passwordHash === '') {
            throw new InvalidArgumentException('Staff passwordHash must not be empty.');
        }
    }

    private static function assertNotEmpty(string $fieldName, string $value): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("Staff {$fieldName} must not be empty.");
        }
    }

    /** A Staff can only ever be handed an already-saved Role. */
    private static function assertRoleIsPersisted(Role $role): void
    {
        if ($role->id() === null) {
            throw new InvalidArgumentException('A Staff\'s role must already be persisted (have an id) before being assigned.');
        }
    }

    /** A newly created Staff always starts active. */
    public static function create(string $email, string $passwordHash, string $name, Role $role): self
    {
        return new self(id: null, email: $email, passwordHash: $passwordHash, name: $name, role: $role, isActive: true);
    }

    /**
     * Reconstitutes a Staff exactly as it exists in storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the
     * given data is already-valid data read back from storage. This
     * method is not a business operation and application code must never
     * call it directly; only a repository implementation reconstructing
     * this entity from an already-validated row should call it.
     */
    public static function reconstituteFromStorage(
        string $id,
        string $email,
        string $passwordHash,
        string $name,
        Role $role,
        bool $isActive,
    ): self {
        return new self(id: $id, email: $email, passwordHash: $passwordHash, name: $name, role: $role, isActive: $isActive);
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('Staff already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function role(): Role
    {
        return $this->role;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * staff-access-domain-design.md §5, rules 1-2: a Staff whose role
     * grants nothing is denied everything; a deactivated Staff is denied
     * everything regardless of role. Rule 3 (an exception in the check
     * is never an implicit allow) is the permission MIDDLEWARE's
     * responsibility in Part 2, not this method's — this method either
     * returns a real bool or lets a genuine bug throw; it does not catch
     * anything itself.
     */
    public function can(Permission $permission): bool
    {
        if (! $this->isActive) {
            return false;
        }

        return $this->role->grants($permission);
    }

    /**
     * §12.2: idempotent by design — calling this on an already-inactive
     * Staff is a no-op, not an error. There is no invariant an
     * already-deactivated Staff could violate by being told to
     * deactivate again.
     *
     * DELIBERATELY NOT GUARDED against deactivating the last remaining
     * active Administrator — Staff has no visibility into other Staff
     * records to check that, and §12.2 explicitly leaves this decision
     * to admin-panel-design.md's implementation (a repository-level
     * count check, a confirmation warning, or both). Not this method's
     * job.
     */
    public function deactivate(): void
    {
        $this->isActive = false;
    }

    /** §12.2: idempotent, mirrors deactivate() above. */
    public function reactivate(): void
    {
        $this->isActive = true;
    }

    /**
     * §12.2: reuses the exact existing assertRoleIsPersisted() the
     * constructor already runs. Idempotent — reassigning to the Staff's
     * current Role is a harmless no-op, not an error.
     */
    public function changeRole(Role $newRole): void
    {
        self::assertRoleIsPersisted($newRole);
        $this->role = $newRole;
    }

    /**
     * §12.2: this is §10's own already-stated V1 answer to password
     * reset ("an Administrator resets a colleague's password through
     * `STAFF_MANAGE`") finally given something to call. Reuses the
     * exact existing assertValidPasswordHash() the constructor already
     * runs.
     */
    public function changePasswordHash(string $newPasswordHash): void
    {
        self::assertValidPasswordHash($newPasswordHash);
        $this->passwordHash = $newPasswordHash;
    }
}
