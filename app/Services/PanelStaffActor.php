<?php

namespace App\Services;

use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use Filament\Facades\Filament;

/**
 * The one place the panel's currently-authenticated staff member is resolved —
 * CLAUDE.md's "shared rule, no duplicates" principle applied to a resolution
 * that used to live privately inside ActivityLogger::currentStaff() and is now
 * needed twice (ActivityLogger and App\Services\OrderEventRecorder, the new
 * writer of order_events — order-lifecycle-design.md §6.2).
 *
 * THE BEHAVIOUR IS ActivityLogger's OWN, MOVED VERBATIM, NOT REINVENTED:
 * Filament::auth()->user() (the 'staff' guard the panel is configured with),
 * and null unless that user really is a StaffModel — so a console command or a
 * queued job records a null actor rather than throwing, exactly as both
 * writers' docblocks promise, and the journal's own "System" fallback keeps
 * working.
 *
 * NOT MEMOIZED, DELIBERATELY. ActivityLogger::write() resolved this on every
 * single write, and a PHPUnit method that calls actingAs() as several different
 * staff members in turn depends on that. AuthenticatedStaffResolver faces the
 * same trap and solves it per-ID because what it caches is an expensive
 * repository read; resolving the guard is not, so this class caches nothing and
 * can never serve a stale actor.
 *
 * NOT AuthenticatedStaffResolver: that class answers "what are this staff
 * member's permissions" by loading the Staff aggregate by id. This one answers
 * "who is logged into the panel right now" — an actor snapshot for a factual
 * record, never an authorization decision.
 */
final class PanelStaffActor
{
    public function current(): ?StaffModel
    {
        $authenticatedModel = Filament::auth()->user();

        return $authenticatedModel instanceof StaffModel ? $authenticatedModel : null;
    }
}
