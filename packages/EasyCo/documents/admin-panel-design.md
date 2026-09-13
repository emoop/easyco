# Admin Panel Design

**Status:** Draft — architecture and decisions only, nothing implemented
yet.
**Builds on:** `performance-and-channel-strategy.md` §2 (the Filament/TALL
stack recommendation itself, and its explicit warning about the
raw-Eloquent-bypass risk), `staff-access-domain-design.md` (the `staff`
guard and `Permission` vocabulary this panel authenticates and
authorizes against), `catalog-domain-design.md` §3.11/§5,
`checkout-domain-design.md` §12, `site-settings-design.md`.

---

## §1. Scope

The admin panel merchant staff use to manage Catalog (products,
variations, brands, categories, tags, attributes), Media, Staff/Role
assignment, Orders (view), and Site Settings. Built on Filament — already
decided, not re-litigated here. Scoped to the highest-priority slice
first (§10), matching how every other domain in this project has shipped
in staged parts rather than all at once.

---

## §2. Where this lives architecturally

Admin panel resources live in `app/Filament/` (Filament's own
convention — `Resources/`, `Pages/`), never in `packages/EasyCo/`. This
is the same reasoning already established twice in this project:
Checkout lives in `app/Services/` (`checkout-domain-design.md` §1),
the storefront lives in `app/Http/Controllers/Web/`
(`storefront-frontend-design.md` §1) — because neither owns an
aggregate of its own. A Filament Resource is a *consumer* of domain
repositories, exactly like a storefront controller or Checkout
orchestrator; it never becomes a place where domain logic gets
reimplemented for the convenience of a form.

---

## §3. Panel and guard wiring — closes a real, already-flagged gap

The Filament Panel is configured against the **existing `staff` guard**
(`staff-access-domain-design.md` Part 2) — not a new guard, not
Filament's own default. `StaffModel` (already `Authenticatable`) is the
model Filament's panel login resolves against.

**Worth stating explicitly, not rediscovering later:** Filament ships
its own login page tied to whichever guard the panel is configured
against. Building this panel is what closes README's still-open "no
staff login/logout HTTP endpoint yet" gap — not a separate, later task.

Verify Filament's exact panel-guard configuration method/signature
against the real installed v4/v5 version before writing the
implementation prompt — this session already confirmed the v3→v4 API
changed significantly (Schema-based forms/tables replacing the old
`Forms\Form`/`Tables\Table` classes), so a v3-era method name is not
safe to assume carries over unchanged.

---

## §4. Authorization — one Permission system, not two

**Decision:** Filament's authorization hooks (`canViewAny()`,
`canCreate()`, `canEdit()`, `canDelete()` on each Resource class)
delegate to the **existing** `Staff::can(Permission)` domain method —
never Filament's own separate policy/permission conventions (e.g. the
popular `filament-shield` plugin, which generates its own permission
tables), and never a second, parallel permission system.

Concretely: a shared trait resolves the authenticated Filament user
(`Filament::auth()->user()`, a `StaffModel`) into the real domain
`Staff` via `StaffRepository::findById()` — exactly what
`EnsureStaffHasPermission` middleware already does — then calls
`$staff->can(Permission::X)`. Each Resource declares which `Permission`
case gates which action (`ProductResource::canViewAny()` checks
`Permission::PRODUCT_VIEW`; `canCreate()`/`canEdit()`/`canDelete()`
check `Permission::PRODUCT_MANAGE`).

This means Manager/Product Entry/Administrator's already-seeded,
already-tested permission boundaries are enforced **identically**
whether a request comes through the JSON API (`staff.can:*`
middleware) or the admin panel (these Filament hooks) — one source of
truth, two enforcement points calling the same domain method.
Reinforces `staff-access-domain-design.md` §5's own rule directly: "the
check is on the permission, never on the role's name."

**Known cost, flagged not hidden:** this is a third call site repeating
the "reload Staff via repository on every check" cost already flagged
twice (Part 1's `Staff` docblock, Part 2's middleware docblock). Still
not a problem to solve in this task.

---

## §5. The core tension: Filament's Eloquent-first design vs. domain-owned writes

This is the central architectural decision the whole document turns on.

Confirmed directly against current (5.x) Filament docs: a Resource's
Create/Edit pages have a documented override point —
`handleRecordCreation(array $data): Model` (and its update-side
equivalent — verify the exact current method name against the real
installed version before implementation) — replacing Filament's default
`static::getModel()::create($data)`/`$record->update($data)` with
whatever the override returns.

**Decision:** every Resource's table/listing reads directly through its
Eloquent model (`ProductModel`, `BrandModel`, etc.) — safe, since
listing/filtering has no domain invariant to violate, and Catalog's own
`EloquentProductRepository` ultimately queries the same Eloquent models
anyway. Every Resource's **write** path (create/update/delete) is
overridden to call the real domain repository instead:

```php
protected function handleRecordCreation(array $data): Model
{
    $product = Product::createSimple($data['name'], $data['base_sku'] ?? '', ...);
    app(ProductRepository::class)->save($product);

    return ProductModel::find($product->id()); // Filament needs a real Eloquent instance back
}
```

This is not a workaround bolted onto Filament — it is Filament's own
documented extension point, used exactly as intended. No fork, no
monkey-patching.

**Consequence:** every domain invariant Catalog/Order/Staff already
enforce (unique slug generation via hooks, variation axis validation,
`UnsafeAxisRedeclarationException`, `StaffEmailAlreadyRegisteredException`,
everything) is enforced identically whether a write comes from the
JSON API or the admin panel — because it is the same repository, the
same domain class, doing the actual work either way. A bug fixed once
in `Product::declareVariationAxes()` is fixed for both surfaces
simultaneously, never two divergent implementations to keep in sync.

---

## §6. Resource classification — simple CRUD vs. custom flows

Not every Catalog concept maps cleanly onto Filament's standard
Resource CRUD pattern. Splitting explicitly so nobody assumes uniform
treatment:

**Simple — standard Filament Resource, write-intercepted per §5:**
- Brand, Category, Tag, `AttributeDefinition` — each a flat entity with
  a single `create()`/repository `save()` call; a standard Resource
  form + table with write-interception is a clean fit.
- `Role` — same shape (`Role::create()`), **but** no mutators exist yet
  per `staff-access-domain-design.md` Part 1's own deliberate "don't
  add mutators until a consumer needs one" decision — an Edit page for
  Role, if built at all in this phase, is read-only or deferred
  entirely (§11).
- `Staff` — same shape, **but** the same gap applies: no
  `deactivate()`/role-reassignment mutator exists yet either. An Edit
  page letting an admin toggle `isActive` or change `roleId` needs
  those domain methods to exist first — flagged as real, small, *new*
  Staff domain scope this document surfaces, not silently worked
  around with a raw Eloquent update (§11).

**Custom — a purpose-built Filament Page, not a standard Resource form:**
- **Product (SIMPLE type)** — close to standard CRUD, but the
  descriptive-attributes UI (§7) makes the form genuinely dynamic per
  request (which fields render depends on which `AttributeDefinition`s
  exist) — still a Resource, but with a programmatically-built form
  schema, not a static field list.
- **Product (VARIABLE type)** — not a single `create()` call at all:
  `VariableProductController::store()`'s real logic is declare axes →
  generate the combination matrix → persist Product + N Variations
  together. Needs its own custom Filament Page (or a multi-step
  `Wizard` layout — evaluated during implementation) mirroring that
  exact orchestration, not a form naively mapped onto one Eloquent
  `create()`.
- **Order** — view/manage only in this phase (orders originate from
  checkout, never from admin). A read-focused Resource (table +
  view/infolist page) — no Create page at all for V1.

---

## §7. The concrete flexibility requirements — mapped one by one

Answering the original question directly, now that the mechanism
(§5/§6) is settled:

- **"Visible on site" vs. "sellable at POS" as two separate table
  columns/toggles** — `catalog_visibility` and `Variation.is_purchasable`
  (already resolved, `catalog-domain-design.md` §5) map onto two
  independent `ToggleColumn`s in `ProductResource`'s table — never
  merged into one control, matching the domain's own "three
  independent signals, never conflated" framing.
- **Easy filtering** — `TernaryFilter` on `catalog_visibility` and on
  `is_purchasable`, `SelectFilter` on brand/category — native Filament
  filter types, no custom code.
- **Bulk edit (visibility/purchasability)** — a `BulkAction` toggling
  `catalog_visibility`/`is_purchasable` across a selection. Still goes
  through §5's write-interception per record (a loop calling the real
  domain method per selected Product/Variation, not a raw `UPDATE ...
  WHERE IN (...)` query) — bulk convenience for the *user*, never a
  bypass of domain validation per record.
- **Bulk add/remove categories and tags across a filtered selection**
  (e.g. filter by tag "Summer-2026", bulk-add category "Promo" to every
  matching product) — two new `BulkAction`s, using the existing
  `ProductCategoryRepository`/`ProductTagRepository` (`save()`/`remove()`/
  `findByProductId()`, already tested, already throw
  `CategoryAlreadyAssignedException`/`TagAlreadyAssignedException` on a
  duplicate). **Idempotent by design, not fail-fast:** a bulk-add
  catches the already-assigned exception *per product* and skips it
  rather than aborting the whole batch, reporting a summary ("added to
  42 products, 3 already had it") — the natural expectation for a bulk
  operation someone might reasonably run twice. A bulk-remove first
  resolves the specific assignment id via `findByProductId()` (`remove()`
  takes the assignment's own id, not a product+category pair), skipping
  products that never had the tag/category in the first place.

  **Filtering by category/tag requires one small, deliberate addition:**
  `ProductModel` (the Eloquent infrastructure class, not the
  `EasyCo\Catalog\Product` domain entity) gains read-only
  `categories()`/`tags()` `BelongsToMany` relationships purely so
  Filament's table filters have something to query against — the
  domain layer's own category/tag *writes* still go exclusively through
  the repositories (§5); this relationship is never used for writing,
  only for `SelectFilter`s and a filtered listing.
- **Selections larger than one batch are queued, not processed inline.**
  Confirmed against the domain owner's own real-world precedent (a
  WooCommerce bulk-edit plugin already in use, batching at 50 products
  per save — "a bit slow for 200-300 products, two-three minutes, but the
  alternative is days of one-by-one editing"): any bulk action (toggle,
  category/tag add or remove) selecting **50 or fewer** records processes
  synchronously, inline, in the same request. Selections **above 50** are
  split into batches of 50 and dispatched as `Bus::batch()` queued jobs —
  Laravel's own batch-job primitive, not a third-party Filament plugin,
  reusing the same queue-worker infrastructure `ProcessMediaAssetJob`
  already requires in production (`production-requirements.md`). Each
  batch's job still calls the real domain repository once per record
  within it (§5, this section's own idempotent-skip rule unchanged) —
  batching changes only how many records are processed per queue-worker
  execution, never how a single record is validated or saved. A
  `->finally()` callback on the batch notifies the initiating staff
  member via Filament's own (persisted, not ephemeral) notification
  system once every batch completes, summarizing the aggregate result
  (e.g. "added to 342 products across 7 batches; 12 already had it").
- **Cost price field** — a real form field bound to
  `EasyCo\Pricing\ProductCost`/`CostPriceProvider` (already exists,
  `checkout-domain-design.md` §9) via the write-interception hook — not
  a new column anywhere.
- **Consignment/silver/future-unknown flags** — rendered via
  `Product::descriptiveAttributes()` (`catalog-domain-design.md` §3.11):
  the Product form iterates every `AttributeDefinition` not used as
  this Product's variation axis, and renders a `Checkbox`/`Radio`/
  `Select`/`TextInput` field per `AttributeType` — genuinely dynamic,
  driven by data, not a hardcoded field list. Adding a new flag going
  forward means creating a new `AttributeDefinition` through its own
  Resource — zero new Filament code, matching "add later without a
  developer" directly.
- **Extra tab/section on the product edit page** — native `Tabs`/
  `Section` Schema layout components (confirmed current, Filament
  5.x). A natural split: General (name, slug, brand), Pricing (price
  lists, cost), Attributes (the dynamic fields above), Media.
- **Order's terms-accepted/phone-call fields** — read-only display on
  the Order view/infolist page (§6). `requires_phone_call`'s column
  only appears at all if `checkout.phone_call_field_enabled`
  (`site-settings-design.md`, `checkout-domain-design.md` §12.3) is
  on — the admin UI honors the same Site Setting the storefront
  checkout does, one source of truth, not two independently-configured
  toggles.

---

## §8. Extensibility for future merchants/developers

Two complementary layers, deliberately not one:

1. **Filament Resource classes are plain Laravel classes.** A developer
   extends/overrides `ProductResource` directly for panel-level
   customization. Full programmatic control, requires PHP/Laravel
   competency — this project doesn't pretend Filament gives
   WooCommerce's install-a-zip experience, and shouldn't oversell that
   it does.
2. **`EasyCo\Extensibility` hooks fire regardless of which UI triggered
   them** — `catalog.product.base_sku`, `catalog.product.slug`, the new
   `checkout.form.fields`/`checkout.request.data`
   (`checkout-domain-design.md` §12.2) all fire from the app/ layer
   code the admin panel *also* calls through (§5's write-interception),
   not from Filament-specific code paths. A merchant/developer hooking
   into `catalog.product.slug` gets identical behavior whether a
   product was created via the JSON API, a future import script, or
   the admin panel — one hook, three callers, never three things to
   keep in sync.

---

## §9. Testing strategy — new territory again

Livewire component testing, not HTTP JSON assertions — establishing the
convention here rather than improvising per resource, mirroring how
`storefront-frontend-design.md` §8 did this for Blade:

- Filament ships Livewire/Pest-based testing helpers
  (`livewire(ProductResource\Pages\CreateProduct::class)->fillForm([...])->call('create')->assertHasNoFormErrors()`)
  — use these for form-level assertions.
- **Every Resource's write path gets a real Feature test asserting the
  domain invariant still holds when triggered through Filament, not
  just through the API** — e.g. a duplicate slug submitted through
  `ProductResource`'s create form must trigger the exact same
  dedup/retry behavior `EloquentProductRepository::save()` already
  guarantees (proven once via the API in
  `EloquentProductRepositorySlugCollisionTest`) — independent proof
  that §5's write-interception didn't silently create a second,
  divergent code path.
- **Authorization tests**: mirror `EnsureStaffHasPermissionTest`'s
  "real matrix, not a spot check" (`staff-access-domain-design.md`
  §11) — each of the three shipped roles against each Resource's
  `canViewAny()`/`canCreate()`/`canEdit()`/`canDelete()`, confirmed to
  match the exact same grants already tested for the API.
- **Bulk-action idempotency tests** (§7): a bulk-add-category run
  against a mixed selection (some products already have the category,
  some don't) succeeds for all of them, with the already-assigned ones
  left unchanged and no duplicate row created for any of them.
- **Batching threshold tests**: a selection of exactly 50 processes
  inline (no job dispatched); a selection of 51 dispatches real
  `Bus::batch()` jobs (confirmed via `Bus::fake()`, not assumed); a
  batch's `->finally()` notification fires exactly once per bulk
  action, not once per batch.

---

## §10. Implementation order

Confirmed by the domain owner to match how a merchant actually operates
the software, not an arbitrary technical ordering: install the project,
create the first administrator, create roles, configure settings, *then*
begin migrating or entering products.

1. Panel scaffold + guard + base authorization trait (§3/§4) — nothing
   else works without this.
2. Staff/Role management — makes the permission system usable by an
   actual admin, not just tests/the bootstrap command. (Blocked in part
   by §11's mutator gap — see the confirmed decision below.)
3. Site Settings screen — configure the store before entering inventory.
4. Brand/Category/Tag/`AttributeDefinition` (simple Resources, §6) —
   needed before Product can reference any of them meaningfully.
5. Product (SIMPLE), including the dynamic descriptive-attributes form
   (§7) and Media attachment.
6. Product (VARIABLE) — the custom multi-step flow (§6), once SIMPLE is
   proven.
7. Order (view-only).

---

## §11. Explicitly deferred

- **Role editing (rename/change permissions) and Staff
  deactivation/role-reassignment** — blocked on the still-absent domain
  mutators (§6). Building the admin form before the domain method
  exists would mean either a raw Eloquent bypass (rejected, §5) or
  inventing the mutator here as a side effect of UI work rather than a
  considered domain decision — neither acceptable. See §12 for the
  confirmed resolution.
- Order creation/editing from admin — orders originate from checkout
  only, per `checkout-domain-design.md`'s own model.
- Any bulk import (CSV/spreadsheet) tooling for onboarding raf.bg's
  existing catalog — a real, near-term need, but a separate tool/task,
  not part of the panel itself.
- Site Settings' own admin edit screen's exact field layout — the
  mechanism (`SiteSettingsRepository`) is designed; a generic
  key-value editor UI is simple enough to leave to the implementation
  prompt rather than design here.
- `filament-shield` or any other third-party permission plugin —
  explicitly not used, per §4's "one Permission system" decision.

---

## §12. Decisions confirmed by the domain owner

1. **§10's implementation order** — Staff/Role management comes right
   after the panel scaffold, before any Catalog resource: install the
   project, create the first administrator, create roles, configure
   settings, *then* begin migrating or entering products.
2. **§11's Role/Staff mutator gap** — confirmed as its own small design
   addendum to `staff-access-domain-design.md` (mirroring the
   `catalog-domain-design.md` §3.11 / `checkout-domain-design.md` §12
   precedent), to be written and reviewed before implementation reaches
   Part 2 of §10 — not solved ad hoc when the admin panel work gets
   there.
3. Filament's exact panel `authGuard` configuration API (§3) remains to
   be verified against the real installed v4/v5 version at
   implementation time — a version-specific detail, not a design
   decision to make now.
