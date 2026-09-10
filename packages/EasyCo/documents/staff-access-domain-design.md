# Staff Access Domain Design — authentication and role-based authorization for the merchant surface

**Status:** Draft v1 — approved scope, not yet implemented.
**Closes:** `admin-auth-gap-note.md` in full. That note recorded the gap and deliberately left the shape open ("a separate `Staff` entity? roles/permissions from day one, or a single flat 'is staff' flag for V1? — an open question for whenever this is actually designed"). This document answers it.
**Builds on:** `account-domain-design.md` §2 (the separate-guard precedent this mirrors, and the reasoning for why a customer session must never double as anything else), `checkout-domain-design.md` §9.2 (which raised this with new urgency once `ProductCost` made genuinely sensitive data depend on it, and recorded the domain owner's requirement for real roles rather than a binary guard), `payment-domain-design.md` (whose refund model this document's permission split is shaped around).
**Origin:** the role model was worked out with the domain owner in a dedicated conversation, from his own experience running a physical boutique, before any of it was written down. Two real platforms were then checked against it:

- **WooCommerce** ships only two roles, Administrator and Shop Manager. Shop Manager can manage products, orders and reports but not themes, plugins or WordPress core — which means it *can* reach every WooCommerce setting, **including payment gateways**. A merchant delegating day-to-day work therefore hands over the ability to change where money is sent. The role model below is deliberately stronger than WooCommerce's default on exactly this point.
- **Shopify** started with one coarse permission per area and had to split them under real-world pressure: a single "Products" permission became ten, among them separate *View cost* and *Edit cost*; a single "Orders" permission became twelve, separating cancelling, capturing payment and applying discounts. POS is a separate permission set again, with its own log of high-risk register actions. That evolution is direct evidence for two choices below: cost visibility is its own permission (§3), and refunds are split from ordinary order handling (§3).

The domain owner's own three roles, arrived at independently, land close to where Shopify ended up after years in production. That is treated below as corroboration, not coincidence.

---

## 1. Scope, and what this is not

This document covers **who may act on the merchant surface, and what they may do**. It does not cover the storefront: `/cart/*`, `/checkout`, `/addresses` and the `account` routes stay exactly as they are, scoped by session token or the `customer` guard. Nothing here changes customer-facing behaviour.

Three things are built:

1. **`EasyCo\Staff`** — a new domain package: the `Staff` entity, the `Role` entity, and the `Permission` vocabulary.
2. **A `staff` auth guard** — separate from both `web` and `customer`, backed by its own table.
3. **A permission middleware** — the single enforcement point every merchant route passes through.

**Deliberately NOT built here** (§10 has the full list, but these two are worth naming up front because their absence shapes the rest): no admin UI for managing staff or roles, and no audit log implementation. Both are designed for, neither is built.

---

## 2. `Staff` is a separate entity from `Account`, and a separate guard

**Decision: a `Staff` is not an `Account` with a flag.** `account-domain-design.md` §2 already established a dedicated `customer` guard so that logging in as a customer never doubles as logging in anywhere else. The same reasoning applies in the other direction and with more force: a staff session must never double as a customer session, and a staff member must not be discoverable, enumerable or password-resettable through the storefront's own customer endpoints. One shared table with a role column would make every future customer-facing feature a potential staff-account exposure.

Practically this also keeps a real-world case clean: the shop's own owner may well also be a customer of the shop. Two identities, two logins, two passwords — which is correct, not redundant.

```
Staff                                          (aggregate root, package EasyCo\Staff)
├── id
├── email                 unique; the login identifier
├── passwordHash
├── name                  required — unlike Account, which deliberately stores no name.
│                         A staff member's name is not decoration: it is what an audit
│                         entry means (§7). "Someone gave a 40% discount" is useless;
│                         "Petar gave a 40% discount" is the whole point.
├── roleId                the Role this staff member holds — exactly one (§4)
├── isActive              boolean — a staff member who has left is deactivated, never
│                         deleted, because their name must survive in historical audit
│                         entries and in whatever they touched
└── (timestamps, softDeletes — same posture accounts already takes)
```

`Staff` never references `Account` and `Account` never references `Staff`. If the same human is both, that is two rows and neither knows about the other.

**Guard configuration**, mirroring the shape `customer` already uses in `config/auth.php`: a `staff` session guard backed by a `staff` provider over `StaffModel`. The existing comment on the `customer` guard already anticipates this ("reserved for a possible future staff/admin login") — that comment should be updated when this lands, since the future arrived.

---

## 3. `Permission` — the vocabulary, fixed in code

Permissions are a PHP enum, not database rows. They are the vocabulary the code itself checks against, so a permission that no code enforces would be a lie, and a permission the code enforces but the enum lacks would not compile. Roles (§4) are data; permissions are code.

Grouped by what they gate:

**Catalog**
- `PRODUCT_VIEW` — see products and variations at all
- `PRODUCT_MANAGE` — create and edit products, variations, media
- `TAXONOMY_MANAGE` — brands, categories, tags, seasons, attribute definitions and values

**Cost and pricing**
- `COST_VIEW` — see a variation's cost price, anywhere it appears
- `COST_MANAGE` — set or change it
- `PRICE_MANAGE` — price lists and price list items

**Orders**
- `ORDER_VIEW`
- `ORDER_MANAGE` — status transitions, fulfilment, editing order information
- `REFUND_CASH` — return money from the register, in cash
- `REFUND_BANK` — a refund that goes through a bank: card reversals, transfers

**Point of sale**
- `POS_OPERATE` — take sales at the register
- `POS_DISCOUNT` — apply a discretionary discount at the register

**Marketing**
- `PROMOTION_MANAGE` — promotion codes and their scopes

**Reporting**
- `REPORT_VIEW` — any report, including anything showing revenue or margin

**System**
- `SETTINGS_MANAGE` — site settings, including payment configuration
- `STAFF_MANAGE` — create, edit, deactivate staff and assign their roles
- `AI_MANAGE` — configuration of the AI-facing functionality

### 3.1 Two splits that exist for specific, stated reasons

**`REFUND_CASH` vs `REFUND_BANK` — split by method, not by action.** Domain-owner decision, from the shop floor rather than from theory: a trusted manager returns cash from the register on the spot, in front of the customer, and the matter is closed the moment the money leaves the drawer. A refund that goes through a bank is not closed — it is a request to a provider that can fail, be delayed for days, or need reconciling against a statement. Different work, different exposure, different authority. This is why the split is by *method* rather than "may refund / may not".

**`COST_VIEW` separate from `PRODUCT_VIEW`.** Cost price is what the merchant paid; it is the merchant's margin laid bare. A remote contractor entering products has no business seeing it, and Shopify reached the same conclusion after shipping the coarse version first. Note that this permission is only meaningful if enforced *everywhere* — see §6.

---

## 4. `Role` — a named bundle, stored as data

```
Role                                           (package EasyCo\Staff)
├── id
├── name                  e.g. "Administrator", "Manager", "Product Entry"
├── permissions           the set of Permission values this role grants
└── isSystem              boolean — true for the three shipped defaults, which cannot be
                          deleted; a merchant may still create their own
```

**Decision: roles are data, not an enum.** The domain owner's three roles are a good default set, not a ceiling. The first merchant who wants "the manager role, but without POS" or "product entry, but allowed to see orders" would otherwise need a code change and a release. Shopify learned this the expensive way; WooCommerce still has not, which is why its ecosystem carries a small industry of role-editing plugins.

**Decision: one role per staff member, not many.** Multiple roles per user is where RBAC systems become impossible to reason about — the effective permission set of someone holding three overlapping roles is nobody's mental model. One role, one answer to "what is this person trusted to do". If a merchant needs a blend, they create a role that *is* that blend, which stays inspectable.

### 4.1 The three shipped roles

Each role is defined below as an explicit list, granted and withheld, rather than only as a matrix — the matrix at the end is for comparing them, but the lists are the definition. A role is exactly the permissions named under it; anything not named is not granted, per §5's deny-by-default.

---

#### Administrator — the owner, or someone trusted as the owner

**Granted: every permission in §3, without exception.**

`PRODUCT_VIEW`, `PRODUCT_MANAGE`, `TAXONOMY_MANAGE`, `COST_VIEW`, `COST_MANAGE`, `PRICE_MANAGE`, `ORDER_VIEW`, `ORDER_MANAGE`, `REFUND_CASH`, `REFUND_BANK`, `POS_OPERATE`, `POS_DISCOUNT`, `PROMOTION_MANAGE`, `REPORT_VIEW`, `SETTINGS_MANAGE`, `STAFF_MANAGE`, `AI_MANAGE`.

This is the only role that can create and manage other staff, change site and payment settings, run promotion codes, configure the AI functionality, and send money back through a bank.

A note for whoever implements this: Administrator is **not** a bypass. It holds every permission explicitly, and the middleware checks it exactly like any other role. There must be no `if (isAdministrator) return true` shortcut anywhere — the moment one exists, a new permission added later is silently granted to Administrator without anyone deciding that it should be.

---

#### Manager — a trusted, authorised employee running the shop

**Granted:**
- `PRODUCT_VIEW`, `PRODUCT_MANAGE` — full work on products, variations and media
- `TAXONOMY_MANAGE` — may create brands, categories, tags, seasons, attributes
- `COST_VIEW`, `COST_MANAGE` — sees and sets cost price; sees margin
- `PRICE_MANAGE` — price lists and prices
- `ORDER_VIEW`, `ORDER_MANAGE` — handles orders end to end
- `REFUND_CASH` — returns money from the register, in cash, on their own authority
- `POS_OPERATE`, `POS_DISCOUNT` — works the register, and may grant a discount there at their own judgement
- `REPORT_VIEW` — sees reports, including revenue and margin

**Withheld, each for a stated reason:**
- `REFUND_BANK` — money going back through a bank is the owner's responsibility (§3.1). Cash at the register is closed the moment it leaves the drawer; a bank refund is an open-ended request against a provider.
- `PROMOTION_MANAGE` — a discount code is a standing, unattended giveaway that anyone who learns it can use, unlike a one-off discount at the register in front of a specific customer. Different exposure entirely.
- `SETTINGS_MANAGE` — includes where the shop's money is sent. This is the single most valuable target in the system (§7) and the reason this role model is deliberately stronger than WooCommerce's Shop Manager, which does have it.
- `STAFF_MANAGE` — a role that can create roles can grant itself anything, which would make every other withholding above meaningless.
- `AI_MANAGE` — configuration of how the shop presents itself to AI channels; an owner-level concern.

---

#### Product Entry — a paid contributor entering products to a specification

Deliberately the narrowest useful role, and the one to get exactly right, because this is the person most likely to be remote, temporary, and not employed by the shop at all.

**Granted:**
- `PRODUCT_VIEW`, `PRODUCT_MANAGE` — creates and edits products, variations, media

That is the entire list.

**Withheld — and this list matters more than the granted one:**
- `TAXONOMY_MANAGE` — they work with the brands, categories, tags and seasons that already exist; they cannot invent new ones. This keeps the catalog's structure the shop's decision rather than drifting with whoever is entering data that week.
- `COST_VIEW`, `COST_MANAGE` — they never see what the shop paid for anything. §6 is what makes this real rather than cosmetic: the field must be absent from API responses, not merely hidden on a screen.
- `PRICE_MANAGE` — they enter what a product *is*, not what it costs the customer.
- `ORDER_VIEW`, `ORDER_MANAGE`, `REFUND_CASH`, `REFUND_BANK` — no access to orders or to money in any direction.
- `POS_OPERATE`, `POS_DISCOUNT` — no register.
- `REPORT_VIEW` — no reports of any kind. Stated explicitly by the domain owner: this role sees no reports, no margins, nothing. Without this, cost could leak through a margin report even with `COST_VIEW` withheld — the two withholdings only work together.
- `PROMOTION_MANAGE`, `SETTINGS_MANAGE`, `STAFF_MANAGE`, `AI_MANAGE` — nothing administrative.

Their entire world is the catalog. Nothing about money is visible to them anywhere in the system.

---

#### The three side by side

| Permission | Administrator | Manager | Product Entry |
|---|---|---|---|
| `PRODUCT_VIEW` / `PRODUCT_MANAGE` | ✅ | ✅ | ✅ |
| `TAXONOMY_MANAGE` | ✅ | ✅ | ❌ |
| `COST_VIEW` / `COST_MANAGE` | ✅ | ✅ | ❌ |
| `PRICE_MANAGE` | ✅ | ✅ | ❌ |
| `ORDER_VIEW` / `ORDER_MANAGE` | ✅ | ✅ | ❌ |
| `REFUND_CASH` | ✅ | ✅ | ❌ |
| `REFUND_BANK` | ✅ | ❌ | ❌ |
| `POS_OPERATE` / `POS_DISCOUNT` | ✅ | ✅ | ❌ |
| `REPORT_VIEW` | ✅ | ✅ | ❌ |
| `PROMOTION_MANAGE` | ✅ | ❌ | ❌ |
| `SETTINGS_MANAGE` | ✅ | ❌ | ❌ |
| `STAFF_MANAGE` | ✅ | ❌ | ❌ |
| `AI_MANAGE` | ✅ | ❌ | ❌ |

---

## 5. Enforcement: deny by default, at one point, on the server

**Every merchant route declares the permission it requires**, via middleware:

```php
Route::middleware(['auth:staff', 'staff.can:product_manage'])->group(...)
```

**Deny by default, in the OWASP C1 sense**, and specifically in these four cases, each of which is a real way access-control layers leak:

1. A route under the merchant surface that declares **no** permission is denied to everyone, not open to everyone. The middleware treats a missing declaration as a configuration error and fails closed. This is the case that matters most: it means adding a new admin endpoint and forgetting to protect it produces a broken feature, which gets noticed, rather than an open door, which does not.
2. A `Staff` whose role grants nothing is denied everything.
3. A deactivated `Staff` (`isActive === false`) is denied everything, regardless of role — checked on every request, not only at login, so revoking access takes effect immediately rather than whenever their session happens to expire.
4. If the permission check itself throws, the request is denied. An exception is never an implicit allow.

**The check is on the permission, never on the role's name.** `$staff->can(Permission::COST_VIEW)` — never `if ($staff->role()->name() === 'Administrator')`. Role names are merchant-editable data; the moment code branches on them, a merchant renaming a role breaks authorization silently. This rule has no exceptions.

**A denied request returns 403 and names the missing permission.** Not 404: unlike the address-ownership case in `AddressResolver`, where hiding existence is the point, here the caller is an authenticated staff member of this same shop. Telling them "you need `refund_bank`" is actionable and lets them ask the right person; hiding it produces a support call instead. This is a deliberate, different choice from the storefront's own 404-not-403 posture, and the two are not in conflict — the threat models differ.

---

## 6. `COST_VIEW` must be enforced at the data boundary, not the screen

This is the single easiest thing in this document to get wrong, so it is stated as a rule rather than left to care: **a permission that only hides a field in one UI is not a permission.** If `PUT /api/variations/{id}` still returns `cost` in its response body, or an export includes it, or a report totals margin from it, then Product Entry can read cost with any HTTP client despite the screen not showing it.

Concretely:
- Cost-bearing endpoints require `COST_VIEW` to be called at all.
- Any endpoint whose response *could* include cost omits the field entirely when the caller lacks `COST_VIEW` — omits, not nulls, so absence is unambiguous.
- Report endpoints exposing margin require both `REPORT_VIEW` and `COST_VIEW`.
- `pricing-domain-design.md` §2.4's existing rule — cost data must never be reachable through a customer-facing endpoint — remains separately in force. This section is about staff, not customers; both apply.

`ProductCost`'s HTTP surface, deliberately held back in `checkout-domain-design.md` §9.2 precisely because this guard did not exist, is unblocked by this document and should be built immediately after it, behind `COST_MANAGE`.

---

## 7. Audit trail — designed here, built later

Certain actions need to record **who, when, and what**, because without that they are an invisible channel for loss. From the domain owner directly: if a person at the register can grant an arbitrary discount, that is a classic source of shrinkage in physical retail, and Shopify keeps a dedicated log of high-risk register actions for exactly this reason.

The actions that must be audited when their features exist:

- Any discount applied at the register (`POS_DISCOUNT`) — who, how much, on which sale
- Any refund, both cash and bank (`REFUND_CASH` / `REFUND_BANK`)
- Any change to payment configuration — bank details above all
- Any change to a staff member's role, or creation/deactivation of a staff member

The last two are worth stating plainly, because they are how a compromised admin account does real damage: a very common e-commerce fraud is not a technical breach at all but an intruder who logs in and quietly changes the bank account that receives the money. An audit entry does not prevent that, but it makes it discoverable rather than mysterious.

**Not built in this task.** Two of the four triggers (POS discounts, refunds) have no HTTP surface yet, so building the log now would mean writing a table nothing writes to. What *is* required now is that this section exists, so that whoever builds POS or refunds finds it rather than inventing something narrower. A future `staff-audit-log-note.md` in the deferred-work queue should carry it forward.

---

## 8. Bootstrapping the first Administrator

A chicken-and-egg problem worth answering explicitly rather than discovering during a deployment: `STAFF_MANAGE` is needed to create staff, and initially no staff exist.

**Decision: an artisan command, not a seeded default account.** `php artisan staff:create-administrator`, prompting for name, email and password. No default credentials ship in the repository, ever — a seeded `admin@example.com` with a known password is how installations get compromised in their first week, and it is the single most common way a self-hosted platform leaks.

The command refuses to run if any Staff already exists, unless explicitly forced. Bootstrapping is a one-time act; a command that can silently mint administrators forever on a live system is itself a hazard.

---

## 9. Sessions, and what this document does not harden

Staff authentication uses the same session-based Sanctum stateful setup `Account` already uses, with the same rate limiting on login. That is a deliberate consistency choice, not a considered security upgrade.

Stated honestly rather than implied: this design gives a staff account **the same protection strength as a customer account**, which is not the same as saying it is enough for an account that can change where money is sent. Two-factor authentication, session-length limits, IP restrictions, and a forced password change on first login are all absent. For a boutique with three staff, that is an acceptable V1. It should be revisited before this is used by anyone larger, and §10 records it rather than leaving it to be assumed handled.

---

## 10. Explicitly out of scope for V1 (deferred, not forgotten)

- **Admin UI for staff and role management** — the API exists behind `STAFF_MANAGE`; the screens are part of the future admin UI work, which the domain owner already scheduled together with order-status transitions.
- **The audit log implementation** — §7; designed, not built, because two of its four triggers have no surface yet.
- **Two-factor authentication and session hardening** — §9.
- **Password reset for staff** — deliberately absent in V1: an Administrator resets a colleague's password through `STAFF_MANAGE`. An email-based self-service reset flow is a real attack surface and is not worth building for a three-person shop before it is needed.
- **Per-location or per-register scoping** — Shopify supports restricting a POS role to one location. Irrelevant for a single-shop V1; the shape here does not preclude it later.
- **Granular order permissions** — Shopify eventually split "Orders" into twelve. This document ships three (`ORDER_VIEW`, `ORDER_MANAGE`, plus the two refund permissions), which is the split the domain owner's actual roles need. Splitting further is easy later, since the enum is the only place it is named; splitting *before* a role needs it would be inventing distinctions nobody asked for.
- **Applying permissions to POS endpoints** — `POS_OPERATE`/`POS_DISCOUNT` are defined here because the role model needs them, but no POS HTTP surface exists to attach them to yet. They are vocabulary waiting for their consumer, and that is stated rather than hidden.

---

## 11. Testing plan

- `Staff`/`Role` domain unit tests: construction and validation; `Role::grants(Permission)` for a role that has it, one that does not, and an empty role; `Staff::can()` delegating to its role; a deactivated `Staff` returning false for every permission regardless of role.
- Guard Feature tests (real MySQL): a staff login does **not** authenticate the `customer` guard, and a customer login does not authenticate `staff` — asserted directly, both directions, since this is the whole reason for a separate guard.
- Middleware Feature tests, the core of this task:
  - Each of the three shipped roles against a representative endpoint for every permission, allowed and denied — a real matrix, not a spot check.
  - **A route registered under the merchant surface with no permission declared is denied**, not allowed. This is deny-by-default's own regression test and the most important single test in this document.
  - A deactivated staff member is denied immediately, without waiting for their session to expire.
  - An unauthenticated request to any merchant route is rejected.
  - A denied request returns 403 and names the missing permission.
- A real audit of `routes/api.php` asserting **every** merchant route sits behind `auth:staff` plus a declared permission — written as a test that enumerates the route table, so a future unprotected route fails the suite rather than shipping. `admin-auth-gap-note.md` existed because this had to be found by hand; it should not have to be found by hand twice.
- `COST_VIEW` boundary tests: a Product Entry staff member calling every cost-bearing endpoint is denied, and any endpoint that could include cost in its response genuinely omits the field for them — confirmed against the real response body, not the view layer.
- `staff:create-administrator` tests: creates a working administrator; refuses to run when staff already exist unless forced.
