<?php

namespace App\Http\Middleware;

use Closure;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The single permission-enforcement point every merchant route passes
 * through — staff-access-domain-design.md §1/§5. Registered under the
 * alias `staff.can` (bootstrap/app.php), used as
 * `staff.can:product_manage` alongside `auth:staff` on a route.
 *
 * THE ONLY CHECK IS $staff->can($requiredPermission) — mirroring §4.1's
 * own "Administrator is not a bypass" language: there is no
 * `$staff->role()->name() === 'Administrator'`-style shortcut anywhere
 * in this class, and none should exist. §5: "The check is on the
 * permission, never on the role's name." This rule has no exceptions.
 *
 * DENY BY DEFAULT, §5's FOUR CASES — this class is responsible for
 * three of the four:
 * 1. (HALF of this one) A route with `staff.can` attached but no
 *    permission value given is denied — see step 1 below. The OTHER
 *    half of rule 1 — a route that omits `staff.can` ENTIRELY, so this
 *    class never runs at all — cannot be caught by this class by
 *    construction (there is nothing to intercept if the middleware was
 *    never attached). That half is Part 3's route-table audit test's
 *    job, not this middleware's; stated here explicitly so it is not
 *    assumed to be covered by this class.
 * 2. A Staff whose role grants nothing is denied everything — the
 *    ordinary `can()` check in step 5 already does this; no special
 *    case needed.
 * 3. A deactivated Staff is denied everything, regardless of role,
 *    checked on every request — see step 4's reload-from-storage
 *    reasoning.
 * 4. If the permission check itself throws, the request is denied. An
 *    exception is never an implicit allow — the entire body below runs
 *    inside one try/catch(Throwable); any caught throwable takes the
 *    same 403 deny path as a normal permission failure, and $next()
 *    is never reached once something has thrown.
 */
class EnsureStaffHasPermission
{
    public function __construct(
        private readonly StaffRepository $staffRepository,
    ) {
    }

    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        try {
            // Step 1 — §5 rule 1, the directly-testable half: a route
            // where `staff.can` was attached without specifying which
            // permission is a configuration error. This middleware
            // refuses to guess or default-allow.
            if ($permission === null || $permission === '') {
                return $this->deny('This route is misconfigured: no permission was declared.');
            }

            // Step 2 — Permission::from() throws \ValueError on a
            // typo'd/unknown permission string; deliberately NOT
            // pre-validated with tryFrom() and a separate branch — the
            // surrounding try/catch already turns that throw into the
            // same 403 deny, per rule 4, more simply than a second
            // branch would.
            $requiredPermission = Permission::from($permission);

            // Step 3 — should be unreachable in normal operation
            // (auth:staff is expected to run first in the route's
            // middleware list and already returns a 401 for a fully
            // unauthenticated request), but per rule 4's spirit this
            // class must not assume a prior middleware ran and must
            // fail closed on its own if somehow reached without one.
            $authenticatedModel = $request->user('staff');

            if ($authenticatedModel === null) {
                return $this->deny("This action requires the \"{$requiredPermission->value}\" permission.");
            }

            // Step 4 — reload the FULL domain Staff via the repository,
            // deliberately NOT just trusting the already-authenticated
            // Eloquent model. This is a real query on every single
            // request, not a redundant one to optimize away here: it is
            // what guarantees isActive and the Role's current
            // permission set are read fresh from the database every
            // time, never from anything cached in the session — the
            // actual mechanism behind §5 rule 3 ("a deactivated staff
            // member is denied immediately, without waiting for their
            // session to expire") and §11's identical requirement.
            //
            // NOTED TWICE NOW, NOT SOLVED HERE (first noted in Part 1's
            // Staff::class docblock): this is a THIRD query per merchant
            // request, on top of Laravel's own guard-authentication
            // query for StaffModel and EloquentStaffRepository's
            // Role-hydration query. A caching or join optimization is a
            // legitimate future improvement if this shows up as a real
            // cost — not something to solve in this task.
            $staff = $this->staffRepository->findById((string) $authenticatedModel->getAuthIdentifier());

            if ($staff === null) {
                // An authenticated session pointing at a Staff row that
                // has since vanished — same fail-loud posture as
                // EloquentStaffRepository::toDomainStaff()'s
                // missing-Role case (Part 1).
                return $this->deny("This action requires the \"{$requiredPermission->value}\" permission.");
            }

            if (! $staff->can($requiredPermission)) {
                return $this->deny("This action requires the \"{$requiredPermission->value}\" permission.");
            }

            return $next($request);
        } catch (Throwable) {
            // Rule 4: an exception in the check is never an implicit
            // allow. $permission may not have been resolved to a real
            // Permission yet at this point (e.g. Permission::from()
            // itself threw), so the message here is deliberately
            // generic rather than naming a permission that was never
            // confirmed to exist.
            return $this->deny('This action is not permitted.');
        }
    }

    private function deny(string $message): Response
    {
        return response()->json(['message' => $message], 403);
    }
}
