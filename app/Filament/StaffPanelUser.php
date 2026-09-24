<?php

namespace App\Filament;

use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

/**
 * A thin, app/-layer subclass of EasyCo\Staff's own StaffModel, existing
 * for exactly one reason: implementing Filament\Models\Contracts\
 * FilamentUser. The EasyCo\Staff package itself must never depend on
 * Filament (admin-panel-design.md §2 — packages/EasyCo/ never depends on
 * a specific admin UI), so this interface cannot be implemented directly
 * on the package's own StaffModel. config/auth.php's 'staff' provider is
 * pointed at this subclass instead of the package's StaffModel directly,
 * purely so `$user instanceof FilamentUser` holds true for whatever the
 * guard resolves — every other behavior (table, fillable, casts,
 * SoftDeletes) is inherited unchanged.
 *
 * WHY THIS CLASS EXISTS AT ALL (a real, confirmed gap, not a guess):
 * Filament\Http\Middleware\Authenticate falls back to
 * `config('app.env') !== 'local'` for any authenticated user that does
 * NOT implement FilamentUser — meaning a real Staff member would 403 on
 * every panel route in every non-local environment (confirmed directly
 * against the installed v5.8.1 source and against phpunit.xml's own
 * APP_ENV=testing) unless this interface is implemented somewhere.
 *
 * canAccessPanel() IS A COARSE UX GATE, NOT THE REAL PERMISSION CHECK.
 * It answers only "is this session even allowed through the panel's
 * front door" (an inactive Staff never gets in) — it is not where
 * staff-access-domain-design.md's permission enforcement lives. That
 * remains entirely App\Filament\Concerns\AuthorizesViaStaffPermission's
 * job: every Resource's canViewAny()/canCreate()/canEdit()/canDelete()
 * resolves the real domain Staff via App\Services\
 * AuthenticatedStaffResolver (memoized per request, bound scoped() — see
 * that class's own docblock for the full "reload on every check" finding
 * it fixes) and calls Staff::can($permission) on every single check,
 * exactly as EnsureStaffHasPermission already does for the JSON API —
 * that resolver is what guarantees "a deactivated staff member is denied
 * without waiting for their session to expire" (staff-access-domain-
 * design.md §5 rule 3) starting the NEXT request, not this method, and
 * not necessarily mid-request (the resolver's own accepted tradeoff).
 * Whether Filament re-invokes canAccessPanel() on every request or only
 * at login is Filament's own implementation detail, not something this
 * design relies on for correctness — treat this check as a UX bonus (a
 * deactivated staff member sees a clean 403 at the door rather than an
 * empty, everything-denied dashboard), never as the enforcement boundary
 * itself.
 */
class StaffPanelUser extends StaffModel implements FilamentUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }
}
