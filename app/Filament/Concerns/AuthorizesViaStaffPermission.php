<?php

namespace App\Filament\Concerns;

use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use Illuminate\Database\Eloquent\Model;

/**
 * The single authorization mechanism every Filament Resource uses —
 * admin-panel-design.md §4: "one Permission system, not two." Delegates
 * to the existing `Staff::can(Permission)` domain method, exactly what
 * `App\Http\Middleware\EnsureStaffHasPermission` already does for the
 * JSON API — never Filament's own policy conventions, never a
 * third-party plugin (`filament-shield` explicitly ruled out).
 *
 * DELIBERATELY NO `canAccess()` HOOK HERE, even though a plain custom
 * Filament Page (like the Site Settings locale page) needs exactly this
 * shape of check too — a real, confirmed regression this session: a
 * `Resource` already inherits a working `canAccess(): bool { return
 * static::canViewAny(); }` from Filament's own `HasAuthorization` trait,
 * and a `canAccess()` defined here would silently override that with a
 * version keyed to `accessPermission()` — which no Resource ever
 * declares — permanently returning `false` for every Resource using
 * this trait. `accessPermission()` stays here as a hook (harmless, no
 * collision), but each consuming Page defines its own local
 * `canAccess(): bool { return static::staffCanForAction(static::
 * accessPermission()); }` directly, reusing `staffCanForAction()` below
 * (a private trait method is still callable from the consuming class's
 * own methods) without shadowing Resource's inherited one.
 *
 * THIS IS A THIRD CALL SITE reloading the full domain `Staff` via the
 * repository on every authorization check — the same "reload on every
 * check" cost already flagged twice before this: once in `Staff`'s own
 * class docblock (Part 1 of staff-access-domain-design.md) and once in
 * `EnsureStaffHasPermission`'s docblock (Part 2). admin-panel-design.md
 * §4 calls this out explicitly as a "known cost, flagged not hidden."
 * Do NOT cache or optimize this here — solving a cost flagged and
 * deliberately deferred twice already is out of scope for this trait.
 */
trait AuthorizesViaStaffPermission
{
    public static function canViewAny(): bool
    {
        return static::staffCanForAction(static::viewAnyPermission());
    }

    public static function canCreate(): bool
    {
        return static::staffCanForAction(static::createPermission());
    }

    public static function canEdit(Model $record): bool
    {
        return static::staffCanForAction(static::editPermission());
    }

    public static function canView(Model $record): bool
    {
        return static::staffCanForAction(static::viewPermission());
    }

    public static function canDelete(Model $record): bool
    {
        return static::staffCanForAction(static::deletePermission());
    }

    /**
     * Every consuming Resource overrides the ones it needs. Default
     * null — see staffCanForAction()'s fail-closed behavior below.
     * Deliberately NOT abstract: a read-only Resource (e.g. a future
     * Order Resource, per admin-panel-design.md §6) legitimately never
     * overrides createPermission()/editPermission()/deletePermission()
     * at all. viewPermission() added when RoleResource's ViewRole page
     * became this trait's first real View-page consumer — not a
     * speculative addition.
     */
    protected static function viewAnyPermission(): ?Permission
    {
        return null;
    }

    protected static function createPermission(): ?Permission
    {
        return null;
    }

    protected static function editPermission(): ?Permission
    {
        return null;
    }

    protected static function viewPermission(): ?Permission
    {
        return null;
    }

    protected static function deletePermission(): ?Permission
    {
        return null;
    }

    /** For a consuming Page's own local canAccess() — see this trait's class docblock for why it isn't defined here. */
    protected static function accessPermission(): ?Permission
    {
        return null;
    }

    /**
     * Fail closed if no permission was declared for this action —
     * mirrors EnsureStaffHasPermission's own rule 1 posture
     * (staff-access-domain-design.md §5): an undeclared permission is
     * denied to everyone, not open to everyone. A Resource that
     * genuinely has no create/edit/delete capability (read-only)
     * correctly returns false here by construction — the null default
     * above IS the "no capability" case for those three, only
     * viewAnyPermission() should realistically stay null-by-mistake
     * (flag this in review if a real Resource forgets it).
     */
    private static function staffCanForAction(?Permission $permission): bool
    {
        if ($permission === null) {
            return false;
        }

        $authenticatedModel = \Filament\Facades\Filament::auth()->user();

        if (! $authenticatedModel instanceof StaffModel) {
            return false;
        }

        $staff = app(StaffRepository::class)->findById((string) $authenticatedModel->getAuthIdentifier());

        if ($staff === null) {
            return false;
        }

        return $staff->can($permission);
    }
}
