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
`Staff` via `App\Services\AuthenticatedStaffResolver::resolveById()` —
the same `scoped()`, per-request-memoized resolver
`EnsureStaffHasPermission` middleware now also goes through (see the
paragraph below) — then calls `$staff->can(Permission::X)`. Each Resource declares which `Permission`
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

**RESOLVED (was: "known cost, flagged not hidden"):** this trait was a
third call site repeating the "reload Staff via repository on every
check" cost already flagged twice (Part 1's `Staff` docblock, Part 2's
middleware docblock) — accepted while the admin panel had few, short
lists. It stopped being negligible once a Filament Table started
calling `canView()`/`canEdit()`-style checks per row (confirmed by a
real query-count test on the Orders list, §14) — an N-row page reloaded
the full Staff + Role pair N times. Fixed by
`App\Services\AuthenticatedStaffResolver` (`scoped()`, memoizes per
request), which both this trait and `EnsureStaffHasPermission` now go
through instead of calling `StaffRepository::findById()` directly. A
permission change is visible starting the next request, never
mid-request — the same tradeoff already accepted for every other
`scoped()` binding in this codebase.

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

---

## §13. Product: VARIABLE wizard, Duplicate, Templates, and the product-group setting

Four related pieces of Part 6 (VARIABLE products), informed by real
ecosystem research (WooCommerce's own documented pain point: "Generate
variations, then expand every single row to type price/SKU/stock —
twelve forms, one at a time" — the proven fix, confirmed across
multiple real WooCommerce-ecosystem tools, is a single-screen grid
editor, never a per-variation form) and by the domain owner's own
daily WooCommerce workflow.

### 13.1 VARIABLE product creation — a Wizard, ending in a grid

A Filament `Wizard` (§6's own "evaluated during implementation" note,
now resolved: yes), three steps:

1. **General** — same fields as SIMPLE's General tab minus
   barcode/is_purchasable (those live per-Variation for a VARIABLE
   product, not on Product itself).
2. **Axes** — pick `AttributeDefinition`s (SELECT-type only, the
   existing constraint) as axes, then which `AttributeValue`s to
   include per axis.
3. **Variations** — `VariationCombinationGenerator::generate()` (already
   idempotent — re-running after adding one more axis value only
   creates the new combinations) produces every combination as a GRID
   ROW, not a separate form: attribute combination label, SKU
   (pre-filled via the real `catalog.variation.sku` hook, editable),
   barcode (optional), an individual Active/Draft toggle per row, plus
   an "Activate all" bulk control above the grid — the domain owner's
   own confirmed choice: DRAFT by default (a real safety default, not
   friction for its own sake), one click to activate everything, or
   toggle individually. **A likely Filament `Repeater`, not a `Table`**
   — these rows are transient, generated-but-unsaved combinations, not
   persisted Eloquent records a `Table`/`InteractsWithTable` is built
   to query; confirm the real component choice at implementation time,
   don't assume.

**Where the wizard ends, and what follows.** The wizard deliberately
creates no prices and no stock — those are configured per variation
afterward — so this flow's natural end is the price & stock screen,
which IS `EditVariableProduct`'s own Variations tab (the bulk
cost/stock fields plus each row's own cost/stock/regular/sale price).
`CreateVariableProduct::getRedirectUrl()` therefore overrides Filament's
default and lands the merchant there, not on the read-only View page:
Filament's default sends a Create page to 'view' whenever the resource
has that page and `canView()` holds (confirmed against the installed
source), which for this wizard meant a product with nothing to click on
it and a merchant walking back to the product list to find what they had
just created. The tab is activated by a query-string deep link —
`Tabs::persistTabInQueryString('tab')` on the page plus an EXPLICIT
`->id()` on each tab, because `Tab::getId()` otherwise falls back to a
label-derived key and the link would change with the panel's language
(Filament's own client-side `tabs.js` writes its own key-based value
when the merchant switches tabs — read back by the client itself — so
the redirect deliberately passes the id, which is what the server
matches for a correct first paint),
with the Variations id itself owned by a single constant
(`ProductResource::VARIATIONS_TAB_ID`) so the tab definition and the
redirect can never drift apart. Two smaller pieces serve the same end:
the created-notification is replaced with one that names the next step
and links straight to the new product's View page (the overview this
redirect skips stays one click away), and the last wizard step carries
that same expectation as its own description, so the landing page is
never a surprise. **Deliberately NOT done:** a pricing/stock step inside
the wizard itself (§13.1's own scope keeps creation and per-variation
pricing on separate screens, where the bulk fields already live), and
any notification action beyond the plain View link.

### 13.2 Duplicate — an app-layer service, not a domain method

No real domain invariant is being protected here (copying field values
into a new entity, not enforcing a business rule) — lives as
`App\Services\DuplicateProduct`, mirroring
`DetachProductFromCatalogLookup`'s own precedent for "app-layer
orchestration, not a Product method." A "Duplicate" action (row action
on the list, header action on View) immediately creates a real,
persisted new Product and redirects straight into its Edit page — not
a prefilled Create form awaiting a first save.

**Confirmed by the domain owner:**
- Name: `"{original} ({duplicate_suffix})"` (translated suffix, e.g.
  "копие"/"copy") — slug re-derived via the real `catalog.product.slug`
  hook from this new name, never copied (would collide).
- `base_sku`: cleared, triggers the real `catalog.product.base_sku`
  hook generation — identical to Create's own empty-string behavior.
- Photos: explicitly NOT copied — a duplicate is assumed to need its
  own real photos.
- Status: always starts DRAFT regardless of the source's status — a
  duplicate must never accidentally go live copying an ACTIVE source.
- Brand/season/product group/categories/tags: copied — the specific
  "tedious to re-enter" fields named directly.
- VARIABLE products: axis DECLARATIONS (which definitions/values were
  selected) are copied; the actual Variations are NOT — the merchant
  reviews/adjusts the copied axis selection, then re-runs generation
  via §13.1's own wizard step. **Explicit fallback, stated now rather
  than discovered mid-implementation:** if copying axis declarations
  cleanly turns out to be materially harder than the rest of this
  method, drop that one piece — the duplicate becomes a VARIABLE
  product with zero axes declared, and the merchant starts axis
  declaration fresh. The category/tag/brand/season/group copying (the
  actual named pain point) stands either way.

**Assumed, not explicitly confirmed — flagging rather than guessing
silently:** `description` and any set descriptive attributes are
copied too, matching "start close to identical, edit what's different"
— easy to reverse if that turns out wrong once real use surfaces it,
per the domain owner's own "most things will be adjusted in motion"
expectation for this whole section.

### 13.3 Templates — a simple, standalone Filament resource

`ProductTemplateResource`, same shape/simplicity as `SeasonResource`
(List/Create/Edit/View, `TAXONOMY_MANAGE`-gated create/edit,
`PRODUCT_VIEW`-gated browse — merchandising configuration, same
permission family as Brand/Category/Tag). Product's own Create form
(SIMPLE and VARIABLE's General step alike) gains a "Start from
template" `Select` at the top, applying the chosen template's five
default fields into the form on selection — a one-time pre-fill, not a
persisted link; every field stays independently editable afterward.

### 13.4 New Site Setting: whether ProductGroup is required

`site.catalog.product_group_required` (boolean, default `false`) — the
first real Site Settings consumer beyond locale (`site-settings-
design.md` §1's own "confirmed future consumers" list, now one item
shorter). A `LocaleSettings`-shaped small admin page (or a shared
"Catalog Settings" page if a natural second setting arrives around the
same time — not decided here). When on, `ProductResource`'s
`product_group_id` field becomes `->required()`; when off, it stays
optional exactly as it is today. This is the mechanism the domain
owner specifically wants for a group that "may never be needed, but if
used, should be enforceable."

### 13.5 Row click navigates to Edit, not View — a ProductResource-only exception

**Implemented, retroactive record** — this is not a new decision being
made here. Every other Resource in this project — `RoleResource`, `BrandResource`,
and the rest — follows one convention: a table row's `recordUrl()`
navigates to View, with Edit reachable via the row's own action menu.
`ProductResource` deliberately breaks that convention: its row
`recordUrl()` navigates straight to Edit, falling back to View only for
a staff member without edit permission (`canEdit()` — the same real
`Staff::can(Permission)` check the row's own Edit action already uses,
so this never routes a click somewhere the action menu itself would
refuse). Justified by Product being this admin panel's highest-edit-
frequency resource — Edit is overwhelmingly the action a staff member
wants on a click, not a detour through View first. **Scoped to
`ProductResource` only.** This is not a project-wide convention change
— a future Resource should still default its row click to View unless
it has this same specific justification (a resource whose real usage
pattern is dominated by immediate editing, not browsing).

### 13.6 Editing a VARIABLE product's axes, adding variations, and restoring archived ones

**Implemented, retroactive record.** Closes §13.1's own Step 4 gap
(`EditVariableProduct`'s class docblock used to list "adding new
variations or extending declared axes" as explicitly not-yet-possible,
pending `declareVariationAxes()`'s own redeclaration guard being worked
out) — now possible because `catalog-domain-design.md` §3.17 replaced
the old blanket "any axis change refused once a STANDARD variation
exists" guard with a directional compatibility check, and added
`Product::restoreArchivedVariation()`.

**A new "Axes" tab**, between Attributes and Variations, mirrors
`CreateVariableProduct`'s own Axes wizard step field-for-field (same
`attribute_definition_id`/`value_ids` Repeater shape). Its own options
exclude this product's current descriptive-attribute definitions (the
mirror image of the Attributes tab's own axis exclusion) — the
underlying `UNIQUE(product_id, attribute_definition_id)` constraint
makes a definition being both at once impossible, so the UI never
offers it. **Deliberate limit, not an oversight:** moving a definition
from descriptive to axis (or back) in one submission is not supported
— both pickers read the product's CURRENTLY PERSISTED set at render
time, not each other's unsaved edits, so a merchant who wants to move
one must remove it from the descriptive picker and save first, then
declare it as an axis in a separate, later save.

**Two add-paths inside the Variations tab, both reusing
`VariationCombinationGenerator`/`Product::addStandardVariation()`
exactly as the Create wizard already does, no reimplementation:**

- **"Generate missing variations"** — a header action (a `Section`
  wraps the existing-variations `Repeater` specifically so it has a
  real `headerActions()` mechanism to attach to; `Repeater` itself does
  not implement `HasHeaderActions`, confirmed against the installed
  source) that runs the generator against every declared axis's every
  enabled value, ->disabled() when the product has no declared axes.
  Reports two REAL, separate counts — created (a genuinely new
  variation) and restored (an archived combination whose values are
  still enabled, revived with its ORIGINAL sku via
  `addStandardVariation()`'s own already-existing §3.9 behavior, not a
  new rule) — never one combined number.
- **"Add variation"** — one row = one explicitly-chosen combination,
  a `Select` per currently-declared axis (built from a closure, so it
  reflects the product's real axis set at render time) plus sku/
  barcode/an active toggle. Processed as part of the normal Save
  submission (`updateProduct()`), not a separate action.

**The archived-variations list** — a separate `Repeater` (never the
existing-variations one) listing this product's ARCHIVED
STANDARD variations, each with a per-row Restore action
(`->extraItemActions()`) that calls `Product::restoreArchivedVariation()`
through the repository, logs the status change, and — the one real
subtlety — refreshes ONLY the two affected form keys
(`existing_variations`/`archived_variations`) via
`$this->form->fill($this->data)`, never a full `fillForm()`: the
latter re-derives EVERY field via `mutateFormDataBeforeFill()` again
(confirmed against `fillFormWithDataAndCallHooks()`'s installed
source), which would silently discard any OTHER unsaved edit the
merchant has in progress elsewhere on the page. Restoration fails loud
(`VariationNotRestorableException`, a danger notification with no data
refresh at all) when the archived variation's own combination no
longer matches the product's current declared axes — the axes may have
drifted while it sat archived, since an archived variation never
blocks an axis change itself (§3.17's own trade-off). A second,
subsequent \LogicException (the archived variation belongs to another
product, is UNIVERSAL, or was already restored/re-archived by someone
else since the page loaded) is handled the same way, PLUS a refresh of
those same two keys — unlike the not-restorable case, the page's own
row lists are genuinely stale here, so the refresh is what stops the
merchant from clicking a button that no longer applies. The whole
section is hidden entirely when the product currently has no archived
STANDARD variation (a real, scoped `count()` query, not a loaded-
collection count) — an always-visible, always-empty list was pure
noise, the same reasoning already applied to the price-override
toggles elsewhere on this page.

**Collapsed by default, with the count in its heading** (a later
revision): the section is `->collapsible()->collapsed()`, and its heading
reads the number of archived STANDARD variations from the same scoped
query that decides its own visibility — so a merchant with a long archived
history can see how much is behind a closed section, and the number can
never disagree with the existence answer. Collapsing is deliberately NOT
hiding: the rows are still rendered and still present in Livewire state,
which is what the two per-row actions read; `->visible(false)` (the
empty-list case above) is the only thing that removes them.

**The archived rows carry a DELETE action too** — the live rows' own
`deleteVariationAction()`, REUSED rather than copied, so §3.19.8 A's
impact/refusal/confirmation modal, §3.19.9's `PRODUCT_DELETE` visibility
and the private `deleteVariationById()`'s own re-checks arrive with it and
cannot drift between the two lists. It is the one way to hand an archived
variation's SKU and barcode back (catalog-domain-design.md §3.19.11):
archiving keeps both occupied forever, so an archived variation with no
history and zero stock is deletable under exactly the same rule and with
exactly the same refusals as a live one. `restoreArchivedVariationById()`
and `generateMissingVariations()` are PRIVATE for the same reason
`deleteVariationById()` is: Livewire exposes every public method of a
component to the browser, so a public routine taking an id (or none at all)
with no modal, no button and no permission check of its own is a callable
endpoint. Each re-checks `PRODUCT_MANAGE` as its first statement — the
actions' own `->disabled()`/`->visible()` are UI affordances, never
authorization (§3.19.9).

**Save-time ordering, in `updateProduct()`:** declare axes (only when
the submitted set genuinely differs from the current one — an
identical-set no-op resubmit is now allowed by the domain but still
skipped here to avoid an unnecessary write and a spurious activity-log
entry) → descriptive attributes → per-row updates → archive removed
rows → add new variations (from the "Add variation" Repeater) →
publish() re-validation. "Generate missing variations" and Restore are
their own independent side-actions, entirely outside this flow — each
reloads, mutates, and saves through the repository immediately on
click, not deferred to the page's own Save button.

**Reordering the variations list** — the existing-variations
`Repeater` is genuinely drag-and-drop reorderable (`->reorderable()`,
Filament's own `Repeater` default) — this page used to disable it with
`->reorderable(false)`, correct at the time precisely because variations
had no persisted order to reorder. The submitted row order IS the
merchant's intent, and it is now persisted as
`catalog_variations.sort_order` by `applyVariationRowOrder()` (array
index = `sort_order`, the exact shape `syncMedia()`/
`syncVariationMedia()` already use for the media pivots) through
`VariationRepository::updateSortOrders()`. The column is deliberately
**not** a `Variation` domain field — order is a merchandising concern,
the same category the media pivots' own `sort_order` already occupies —
so `Variation.php` is untouched, and both read paths
(`EloquentProductRepository::findByIdWithVariations()` and
`EloquentVariationRepository::findByProductId()`) order by
`sort_order ASC, id ASC`, which is what makes the admin list and the
domain aggregate's own `variations()` order agree (the `id` tiebreak is
also why every never-reordered product keeps exactly the order it had
before the column existed). Two deliberate limits: a variation created
*after* a reorder is **appended** (`max(sort_order) + 1`), never inserted
at position 0, and an archived variation keeps whatever `sort_order` it
already had — it is not part of the list being ordered. An ordinary save
that didn't touch the row order writes nothing and logs nothing; a
genuine reorder logs exactly one `variation_order` activity entry, with
real combination labels (`"Color: Black | Color: White"`), not ids.

**Remaining deliberate limits, unchanged from before this pass:** no
bulk variation-edit spreadsheet-style UI (still a possible future
step, not scoped here); per-variation media was already closed by an
earlier pass, unrelated to this one; `size_guide_id` remains unwired
into either admin flow.

### 13.7 Product list price column and View page — a resolver-backed price range

**Implemented, retroactive record ("Prompt B").** Closes a real,
confirmed defect: the list column's old `priceMinorSubquery()` was a
correlated, un-ordered `LIMIT 1` subquery over a product's FIRST
variation's own VARIATION-level system-list item only — no fallback to
a PRODUCT-level item, no notion of "which variation" for a VARIABLE
product at all. Any product priced at PRODUCT level, and every VARIABLE
product, therefore always showed "—", regardless of whether it was
actually priced. Both the list column and the View page's
`regular_price`/`sale_price` entries now render through
`EasyCo\Pricing\PriceRange`, resolved by `App\Services\
ProductPriceRangeProvider` — the same resolver-backed range a future
storefront will eventually reuse, one source of truth instead of two
special-cased renderers (one per product type, as before).

**List column (`price_display`), exact rule:** an empty range → `'—'`;
otherwise the range's `lowestFinalQuote()` — a plain amount, or
struck-through regular + final when that same quote `isDiscounted()`
— prefixed with `от `/`from ` (`products.price_from`) whenever
`hasUniformFinalPrice()` is false, i.e. this product's variations do
not all currently resolve to the same final price. No min-max range
display, no "Sale!" badge, no extra colouring — all deferred (see
below). The column is **not sortable**: a `PriceRange` has no single
scalar column to `ORDER BY` (its lowest-final is computed per page, in
memory, from a batched resolve).

**View page, `regular_price`/`sale_price` — two independent
DIMENSIONS of the same `PriceRange`, not the combined struck-through
rendering the list column uses:**
- `regular_price` → `lowestRegularPrice()`, prefixed the same way
  whenever `hasUniformRegularPrice()` is false; blank when nothing is
  priced.
- `sale_price` → `lowestDiscountedFinalQuote()`'s own `final`, prefixed
  whenever `hasUniformDiscountedFinalPrice()` is false; **blank when
  nothing is currently discounted** — keeps a SIMPLE product with no
  sale rendering identically to before this pass, apart from the
  currency symbol.
- **Accepted consequence:** `sale_price` now shows the effective
  discounted price regardless of *which* PriceList produced it — it may
  come from a percentage-off campaign rather than specifically the
  "Manual Sale" system list. Same "show what the customer actually
  pays" principle already governing the list column; no second,
  parallel definition was built to keep the field "Manual-Sale-only."
- `cost`/`stock_quantity` are untouched by this pass.

**Batching, not one query per row:** the list column resolves the
WHOLE current page's ranges in one `ProductPriceRangeProvider::
forProducts()` call, using two real, confirmed Filament v5.8.1
internals — a column closure's `$livewire` parameter is injected BY
NAME (`Column::resolveDefaultClosureDependencyForEvaluationByName()`),
and `HasRecords::getTableRecords()` memoizes the page's own records
(`$cachedTableRecords`) so calling it once per row never re-runs the
table's own query. Combined with `ProductPriceRangeProvider`'s own
per-request (`scoped()`) memoization, the first row on a page triggers
one real batched resolve; every later row is a pure in-memory cache
hit — proven by a real query-count test (a 5-row and a 25-row page
issue the identical number of queries). A non-table-bearing `$livewire`
(a relation manager, an export) falls back to a single-product resolve,
kept correct but unbatched.

**Currency symbol — `App\Services\PriceDisplayFormatter`, one
temporary source.** A small, hardcoded currency-code → symbol map,
suffix position (`"49.99 €"`), the symbol always taken from the
resolved `Price`'s own currency, never a fresh `DefaultCurrency::get()`
at render time. Deliberately temporary and stated as such in the
class's own docblock: suffix position is atypical for USD/GBP,
accepted for now; no settings key or settings UI exists yet.

**Deferred, explicitly, not accidental:**
- The currency-display settings task — an admin Settings toggle for
  symbol on/off and a left/right position choice (suggested keys:
  `site.currency_symbol_enabled` / `site.currency_symbol_position`),
  which will move the source of both into `SiteSettingsRepository`
  **inside `PriceDisplayFormatter` only**, with zero call-site changes.
- Storefront rendering of a `PriceRange` at all — this pass is the
  admin panel only.
- A min-max range display (`"19.99 - 29.99 €"`) — `PriceRange` itself
  has no `highest*` accessor yet (pricing-domain-design.md §4.4's own
  explicit decision); purely additive later.
- A "partially priced" badge for a product where only some variations
  resolved a price.
- NBSP between the amount and the symbol (currently one ordinary
  space) — a typography question, not decided here.
- Decimal-separator/locale formatting — the app keeps `49.99`, never
  `49,99`, in this pass.

### 13.8 Product timeline — default sort and "Избутай напред" (promote/unpromote)

**Implemented, retroactive record.** Closes a real gap: the product
list had no meaningful default order at all (no `->defaultSort()`
existed prior to this pass) — rows simply came back in whatever order
MySQL happened to return them. The list now defaults to
`catalog_products.timeline_at DESC` (catalog-domain-design.md §3.18) —
newest, or most-recently-promoted, first.

**Default sort mechanism:** `ProductResource::table()` calls
`->defaultSort('timeline_at', 'desc')` on the `Table` builder itself —
deliberately NOT folded into the existing `modifyQueryUsing()` closure,
which is reserved for the archived-only filter's own query
pre-conditioning and nothing else. Confirmed against the installed
Filament v5.8.1 source
(`Filament\Tables\Concerns\CanSortRecords::applySortingToTableQuery()`):
a user's own column sort is applied to the query FIRST; the table's
default sort is then ALSO applied afterward, guarded only by "is this
the same column the user already sorted by" — so clicking a sortable
column (e.g. `name`) becomes the PRIMARY sort key, with `timeline_at`
reduced to a secondary tie-break, never silently suppressed or
overridden outright. The stable `id DESC` tie-break required by D5
needed no code of its own: `Table::$hasDefaultKeySort` defaults to
`true`, and the same method automatically appends
`ORDER BY catalog_products.id DESC` (matching whatever direction is
active) whenever the query isn't already ordered by the key column —
exactly the `(timeline_at, id)` pair the new composite index backs.

**Two row actions**, in the existing `ActionGroup` alongside
View/Edit/Duplicate, both gated by the SAME permission as Edit
(`canEdit()`, no new dedicated permission) and hidden for an ARCHIVED
product (an archived product has no timeline position worth moving —
`ProductTimelinePromoter`'s own `CannotPromoteArchivedProductException`
is the defense-in-depth behind this UI guard, never trusted alone):
- **Promote** ("Избутай напред" / "Move to front",
  `heroicon-o-bars-arrow-up` — deliberately not an upload icon) —
  requires confirmation, calls `ProductTimelinePromoter::promote()`
  with `new DateTimeImmutable()` taken at this exact Livewire-action
  edge (never inside the service itself), success notification.
- **Undo / unpromote** (`heroicon-o-bars-arrow-down`) — visible ONLY
  when the row is actually promoted, checked directly off the already-
  loaded table row's own cast Carbon columns
  (`$record->timeline_at->gt($record->created_at)`, the identical
  strict comparison `Product::isPromoted()` itself uses) rather than a
  fresh per-row domain reload — requires confirmation, calls
  `ProductTimelinePromoter::unpromote()`, success notification.

**Duplicate needed no change at all:** `DuplicateProduct` already calls
`Product::createSimple()` with no `createdAt`/`timelineAt` override, so
the domain's own "defaults to now" construction (§3.18) already makes
every duplicate start un-promoted, even when the source was promoted —
confirmed with a real test, not merely asserted by inspection.

**Deferred, explicitly:**
- Storefront ordering by `timeline_at` — this pass is the admin panel
  only.
- A bulk "promote" action across multiple selected rows.
- Any merchant-API exposure of promote/unpromote (JSON API surface).

---

## §14. Orders (read-only)

A `List` + `View` `OrderResource` — strictly read-only, the manual
prerequisite for exercising cart → checkout → order end to end before
any order-editing UI exists. Confirmed decisions:

- **D1 — Strictly read-only.** No create/edit/delete pages, no bulk
  actions, no status transitions, no row action beyond View.
  `createPermission()`/`editPermission()`/`deletePermission()` are
  deliberately never overridden — `AuthorizesViaStaffPermission`'s own
  `staffCanForAction()` fails closed for an undeclared permission, so
  `canCreate()`/`canEdit()`/`canDelete()` are false for every role by
  construction, asserted by a real test rather than implied. Gated by
  `Permission::ORDER_VIEW` alone (Administrator and Manager hold it,
  Product Entry does not).
- **D2 — Snapshot, never live.** Every amount, product name, SKU and
  delivery field comes from `orders` / `operational_sales_sale_lines`
  directly. Nothing is re-resolved through Pricing or Catalog — a
  renamed product, a changed SKU, or a changed price after the order
  was placed changes nothing this page shows (confirmed by a real
  test: rename/re-SKU/re-price the product, the View page is
  unchanged).
- **D3 — A line shows** product name, SKU, quantity, line total, and
  unit price derived as `amount_minor / quantity` — exact by
  construction (`CheckoutLinePricer` builds `amount` as
  `unitPrice->multiply($quantity)`, plain integer multiplication), but
  still verified rather than trusted; a non-exact division would log a
  warning and render blank, never round silently. No regular/struck-
  through price and no variation attributes — neither is stored
  anywhere on a `SaleLine` snapshot (see "Snapshot gaps" below).
- **D4 — `profit_minor` is shown nowhere**, list or View — margin
  visibility needs its own future permission decision.
- **D5 — `App\Services\OrderAdminReader`** is the one place every
  cross-table read for this section lives (`orders`,
  `operational_sales_sale_lines`, `operational_sales_transactions`,
  `operational_sales_clients`, `payments`, `promotion_redemptions` —
  five packages that deliberately never reference each other's
  Eloquent models). `OrderModel` carries no relations, and none were
  added. `applyListAggregates()` layers correlated-subquery `addSelect()`
  columns onto the List page's query (same shape
  `ProductResource::table()`'s own `thumbnail_path` subquery already
  uses); `forOrder()` assembles the View page's full read, memoized per
  instance (the reader is bound `scoped()`, same pattern as
  `PriceDisplayFormatter`/`ProductPriceRangeProvider`) since the
  infolist's several independent entries all resolve it for the same
  order id.
- **D6 — Payment shown = the most recent row** (by `attempted_at`, then
  `id`), plus the attempt count when more than one exists.
  `payments.order_id` is a plain, unindexed-by-FK `VARCHAR`
  (`payment-domain-design.md` §6 — the Order domain didn't exist yet
  when Payment was built); every join casts `orders.id` to `CHAR`
  rather than touching `payments.order_id`, so that column's own real
  index (`pay_payments_order_id_index`) stays usable.
- **D7 — List query count is independent of row count**, confirmed by
  a real test (`OrderAdminReaderTest`) that isolates
  `OrderAdminReader`'s own contribution: 1 query regardless of page
  size. A SEPARATE cost used to scale with row count once a View page
  exists — Filament calls `canView($record)` per row to decide whether
  it's clickable, and
  `AuthorizesViaStaffPermission::staffCanForAction()` reloaded the full
  `Staff` aggregate on every single call. That per-row reload is now
  fixed (§4): `App\Services\AuthenticatedStaffResolver` memoizes the
  resolved `Staff` per request, so the authorization cost no longer
  grows with row count either. `OrderResourceTest`'s own query-count
  test asserts the raw, unfiltered count for exactly this reason — it
  previously had to exclude `staff`/`staff_roles` queries to stay
  meaningful.
- **D8 — Fail-soft display.** Missing optional data (no payment row, no
  promotion, a pickup-point order with no street fields) renders `'—'`,
  never an exception.

**A real, confirmed gap found while building `forOrder()`, flagged, not
fixed:** `SaleLine`'s own constructor
(`assertProductNameAndSkuMatchType()`) rejects a `null`
`product_name`/`sku` for a SALE line unconditionally, including during
`reconstituteFromStorage()`. That snapshot only exists on rows written
after the 2026-09-17 migration that added the columns — so
`TransactionRepository::findByIdWithSaleLines()` would genuinely throw
reconstructing an older row, the opposite of this page's own D2/D8
requirements. `OrderAdminReader` reads `operational_sales_sale_lines`
directly instead, sidestepping that constructor entirely (confirmed
survivable by a real test that writes `NULL` directly into the table).

**Snapshot gaps this page surfaces but does not create — flagged for a
future decision, not guessed at here:** `operational_sales_sale_lines`
stores no unit/regular price separately from the line total (only
derivable, and only when the division happens to be exact) and no
variation attributes (color/size/etc.) at all — an order for a VARIABLE
product's variation shows only whatever `product_name`/`sku` the
snapshot carries, nothing about which combination was purchased. Adding
either is a real schema change to `EasyCo\OperationalSales`, out of this
task's scope.

**Deferred, explicitly:**
- Order status transitions / any editing capability.
- A Promotions admin UI.
- Refunds UI (`PaymentRefund` already exists as a domain concept —
  payment-domain-design.md — with no admin surface yet).
- List filters (by status, channel, payment status, date range).
- Printing / invoices.
- Margin (profit) visibility — needs its own permission decision (D4).
- The terms/phone-call checkout fields §7 named — they do not exist in
  the schema yet, so there is nothing yet for this page to show.

**`Database\Seeders\DemoPromotionsSeeder`** — not part of the admin
panel itself, but this section's own manual-testing prerequisite: four
demo promotion codes (`DEMO10`, `DEMOFIX`, `DEMOONCE`, `DEMOSCOPE`),
seeded via `php artisan db:seed --class=DemoPromotionsSeeder` (never
registered in `DatabaseSeeder`, refuses in production, idempotent).
See CLAUDE.md's own "Build / test" section for the one-line usage note,
and the seeder's own docblock for why each code's specific parameters
were chosen.
- Any merchant-API exposure of promote/unpromote (JSON API surface).
