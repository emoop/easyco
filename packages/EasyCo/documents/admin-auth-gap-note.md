# Admin/staff auth guard — RESOLVED (staff-access-domain-design.md)

**Status:** Resolved. Originally recorded because it was discovered by
directly auditing routes/api.php's middleware — every merchant-facing
endpoint was unprotected. Kept here as historical record of the gap and
its closure, not deleted, per this project's own "flag, don't fix
silently" convention for tracking what was true and when.

**What closed it:** `staff-access-domain-design.md`, implemented in three
parts:
1. The `EasyCo\Staff` domain package — `Permission` enum, `Role`,
   `Staff` entities and persistence, `StaffSystemRolesSeeder` (three
   shipped roles: Administrator, Manager, Product Entry), and the
   `staff:create-administrator` bootstrap command.
2. A dedicated `staff` auth guard (separate from both `web` and
   `customer`, mirroring the reasoning `account-domain-design.md`
   already established) plus `EnsureStaffHasPermission` — the single
   `staff.can:*` enforcement point, deny-by-default on every check.
3. Every existing merchant route in `routes/api.php` now sits behind
   `auth:staff` plus a declared permission, with
   `MerchantRoutesRequirePermissionTest` auditing the real route table
   so a future unprotected route fails the suite rather than shipping.

**What the original note got right, worth keeping for context:** the
staff-account model's shape genuinely was an open question at the time
("a separate `Staff` entity? roles/permissions from day one?") — it was
resolved as a separate `Staff` entity with roles/permissions from day
one, not a flat "is staff" flag, specifically so `PaymentRefund`
authorization (Administrator-only for bank refunds, Manager-and-above
for cash) had somewhere real to live.

**Still open, not part of this closure:** no staff login/logout HTTP
endpoint exists yet — bootstrapping and testing both work without one
(the artisan command, and `actingAs()`/`Auth::guard()->attempt()` in
tests), but a real staff member cannot yet log in through a browser.
This is a prerequisite for any admin UI work.
