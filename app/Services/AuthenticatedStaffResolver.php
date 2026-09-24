<?php

namespace App\Services;

use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Staff;

/**
 * Memoizes the currently-authenticated Staff aggregate for the lifetime
 * of one request — the fix for the "reload Staff via the repository on
 * every permission check" cost flagged three times over (Staff's own
 * class docblock, EnsureStaffHasPermission's docblock,
 * AuthorizesViaStaffPermission's docblock) and deliberately left
 * unsolved at the time each of those was written: the admin panel had
 * few, short lists back then, so N reloads for N rows was real but
 * small. It stopped being small once a Filament Table started calling
 * canView()/canEdit()-style checks PER ROW (confirmed by a real
 * query-count test on the Orders list — see admin-panel-design.md §14's
 * own note on this) — every row on a 25-row page was reloading the
 * full Staff + Role pair independently.
 *
 * BOUND scoped(), NOT singleton() (AppServiceProvider) — same reasoning
 * as every other scoped() binding in this codebase
 * (ProductPriceRangeProvider/PriceDisplayFormatter/OrderAdminReader):
 * one instance per request/job is exactly the right lifetime. A
 * singleton() would leak the FIRST request's authenticated Staff into
 * every later, unrelated request in a long-running worker (queue
 * worker, Octane) — the identical hazard those classes' own docblocks
 * already document for a resolved price or a resolved order. A staff
 * member's permission change (a role edit, a deactivation) is
 * therefore visible starting the NEXT request, never mid-request —
 * the same, already-accepted tradeoff every other scoped() binding
 * here carries, and explicitly fine per EnsureStaffHasPermission's own
 * rule 3 (a deactivated staff member is denied "without waiting for
 * their session to expire" — a session, not a single in-flight
 * request).
 *
 * MEMOIZED PER ID (a small map), NOT A SINGLE SCALAR WITH A
 * MISMATCH-GUARD — a real, single production request only ever has one
 * authenticated staff member, so this map holds exactly one entry
 * there; the per-id shape (mirroring OrderAdminReader::$orderViewCache's
 * own established pattern) is what makes this class ALSO correct
 * inside a single PHPUnit test method that calls actingAs() as several
 * different staff members in turn to check each role's own boundary
 * (an established, common pattern throughout this test suite) — the
 * container/scoped instance is not torn down between those calls the
 * way it would be between two real requests, so a single-scalar cache
 * would incorrectly serve one test-simulated "request"'s Staff to the
 * next. A throw-on-mismatch design was tried first and rejected: it
 * broke exactly this pattern in real, already-passing tests.
 *
 * DOES NOT change WHO is authenticated or HOW (Filament's own auth
 * guard resolution, `$request->user('staff')`) — both call sites still
 * resolve their own Eloquent `StaffModel` exactly as before and pass
 * only its id in here. This class only caches the one expensive step
 * that followed: turning that id into the real domain `Staff` (still a
 * real 2-query load inside StaffRepository — Role hydration is a
 * separate concern, Staff's own class docblock, Part 1 — just paid at
 * most once per id per request now, not once per check).
 *
 * THE "NEXT REQUEST SEES IT" GUARANTEE, BACKED BY REAL INSTALLED
 * SOURCE, NOT ASSUMED — a genuine new PHP-FPM-style request needs no
 * reset at all (it gets a brand-new container for free), and the two
 * long-running execution models where a single process really does
 * serve more than one "request" in place both reset scoped bindings
 * between them: vendor/laravel/framework/src/Illuminate/Queue/
 * QueueServiceProvider.php, registerWorker() (~line 247) builds a
 * $resetScope closure that calls `$app->forgetScopedInstances();`
 * (line 263) between every processed job, and passes that closure
 * straight into the `Worker` it constructs (~line 275), which invokes
 * it after each job in its daemon loop. Laravel Octane is NOT
 * installed in this project (confirmed: absent from composer.json,
 * composer.lock, and vendor/laravel/) — it registers an equivalent
 * reset via its own service provider listening for its own
 * between-request event, but that source is not present here to quote
 * directly. Illuminate\Foundation\Http\Kernel never calls
 * forgetScopedInstances() anywhere in its installed source, confirming
 * a plain HTTP request needs no such call. NOTE: a single PHPUnit test
 * method reusing one Application instance across several
 * `$this->get()` calls is neither of these two reset points, so it is
 * NOT a valid stand-in for "the next request" — see
 * AuthorizesViaStaffPermissionTest's own test for how this codebase
 * proves the guarantee instead (a real forgetScopedInstances() call,
 * simulating the boundary these real reset points provide).
 */
final class AuthenticatedStaffResolver
{
    /** @var array<string, ?Staff> */
    private array $resolved = [];

    public function __construct(
        private readonly StaffRepository $staffRepository,
    ) {
    }

    public function resolveById(string $staffId): ?Staff
    {
        if (array_key_exists($staffId, $this->resolved)) {
            return $this->resolved[$staffId];
        }

        return $this->resolved[$staffId] = $this->staffRepository->findById($staffId);
    }
}
