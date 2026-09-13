<?php

namespace EasyCo\Staff\Exceptions;

use RuntimeException;

/**
 * Thrown when application code attempts to rename or change the
 * permissions of one of the three reserved system Roles
 * (Administrator/Manager/Product Entry — see
 * staff-access-domain-design.md §4.1). Mirrors
 * EasyCo\Pricing\Exceptions\CannotModifySystemPriceListException's own
 * shape exactly, for the same reasoning: Administrator specifically is
 * looked up by exact name (`findSystemRoleByName('Administrator')`) by
 * `staff:create-administrator` (§8), and its full permission set is
 * load-bearing for every other permission decision this document makes
 * (§4.1's own comparison table). Renaming it, or editing its permissions
 * down to something incomplete, would either break bootstrap silently or
 * quietly weaken the one role every other permission decision assumes is
 * complete. A merchant's own custom roles (`isSystem() === false`) have
 * no such restriction — see §12.1.
 */
final class CannotModifySystemRoleException extends RuntimeException
{
    public static function cannotRename(string $roleId): self
    {
        return new self("Role {$roleId} is a reserved system role and cannot be renamed.");
    }

    public static function cannotUpdatePermissions(string $roleId): self
    {
        return new self("Role {$roleId} is a reserved system role and its permissions cannot be changed.");
    }
}
