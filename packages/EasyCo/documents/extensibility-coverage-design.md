# Extensibility Coverage — Hooks and Admin/API Extension Points

**Status:** Draft v1 — design only, nothing implemented. This document is the
merchant-facing, WooCommerce-style extensibility layer's coverage map: every
hook that exists, every hook that is designed but not built, every hook this
pass proposes, one admin-UI extension mechanism, the API/storefront contract,
the extension packaging/lifecycle story, and the enforcement that keeps the
Hook Reference from drifting again. No code, no migration, no UI change is
part of this pass.

**Builds on:** `extensibility-design-and-hooks.md` (the mechanism itself —
`Hook::fire`/`Hook::action`/`Hook::apply`/`Hook::filter`, the Hook Reference
table, the uncaught-exception policy, the three deferred items this pass
revisits in §6) — this document does not re-argue the mechanism, only maps
its coverage and closes its own gaps. `order-lifecycle-design.md` §12 (the
five designed-not-built order hooks §2 inventories as-is) and §5.2/§8 (the
admin-action shape §3's registry sits beside, in the same surface). `order-
context-design.md` §3.3 (the resolver's own explicit refusal to be a filter
— independent confirmation, arrived at from a different problem, of the same
"a filter must never decide a fact" principle §1 generalizes). `checkout-
domain-design.md` §12 (`checkout.form.fields`/`checkout.request.data`/
`checkout.phone_call_field_enabled` — §2's checkout row, §4's model for any
future API extension point). `admin-panel-design.md` (§5's write-interception
pattern, §8's "two complementary layers," the eleven Resources §3 retrofits).
`site-settings-design.md` (the per-key mechanism §5 reuses for per-extension
enable/disable). `storefront-frontend-design.md` §6 (consuming the existing
API, not duplicating it — the contract §4 extends to extensions).
`channel-native-commerce-vision.md` §6/§13/§18 (channel adapters, "AI-readable
by default," the `InventoryChanged → feeds` example §2's Inventory row cites
as its concrete use case — a vision this document keeps in view without
building toward it, per the Non-goals below). CLAUDE.md rules 2/3/4/9/10.

**Origin:** the owner's own instruction (X1): the platform must be extensible
the WooCommerce way — every important action fires a hook, every important
view offers an extension point — because many merchants worldwide will
self-host and install different extensions. The architect's own defaults
(X2, offered as overridable, engaged with rather than rubber-stamped
throughout this document): Composer-package extensions with auto-discovered
service providers; actions fire after commit; filters transform display/
optional data, never status/money/stock/invariants; UI extension points are
one dedicated registry, not `Hook::apply()` scattered through Filament
classes; extensions never bypass a permission check or a query-count rule.

---

## §0. What is true today (verified in this pass)

Every claim below was checked against the installed source and a real grep of
`app/` and `packages/EasyCo/` (excluding `vendor/`, `documents/`, `storage/`),
not recalled from the earlier documents. `git rev-parse HEAD` at the start of
this pass: `1a99159c095ee0e4d440f6cc7cd0bfc4745a037b`, clean tree. Baseline
`composer test`: 1428 tests, 6508 assertions, all passing. Standalone package
suites: Order 52/52, Payment 66/66, Promotions 59/59, OperationalSales
102/102, all passing; **Inventory has no `phpunit.xml`** — its own tests, if
any, only run as part of the full suite, a pre-existing fact unrelated to
this pass and not something this pass changes.

### 0.1 The mechanism itself — confirmed exactly as documented

`Hook::action()`/`Hook::filter()` **are** the registration calls — there is no
separate `Hook::listen()`. Both delegate to `HookRegistry::addAction()`/
`addFilter()` (`packages/EasyCo/Extensibility/src/Hook.php:18-26`,
`HookRegistry.php:28-36`); `Hook::fire()`→`doAction()`,
`Hook::apply()`→`applyFilters()` are the separate firing calls. No listener
removal beyond `HookRegistry::clear()` (removes everything for a hook, or
everything), no introspection beyond `hasListeners()`, no queued dispatch —
`extensibility-design-and-hooks.md` §5 is accurate as written. Listener
exceptions propagate uncaught, proven by `HookRegistryTest` — also accurate.

### 0.2 Every production `Hook::` call site and every listener registration

**Firing sites (25 total) in `app/`:**

| Hook | Call sites |
|---|---|
| `catalog.product.base_sku` | `ProductController.php:45`, `VariableProductController.php:65`, `DuplicateProduct.php:71`, `CreateProduct.php:74`, `EditProduct.php:197`, `CreateVariableProduct.php:389` (`generateVariationPreview()`), `CreateVariableProduct.php:457` (`createProduct()`), `EditVariableProduct.php:2510` |
| `catalog.product.slug` | `ProductController.php:51`, `VariableProductController.php:66`, `DuplicateProduct.php:70`, `CreateProduct.php:73`, `EditProduct.php:187`, `CreateVariableProduct.php:390` (`generateVariationPreview()`), `CreateVariableProduct.php:458` (`createProduct()`), `EditVariableProduct.php:2496` |
| `catalog.variation.barcode` | `ProductController.php:63`, `ProductResource.php:2329`, `EditProduct.php:274`, `EditVariableProduct.php:3014`, `CreateProduct.php:95` |
| `catalog.variation.sku` | `CatalogSkuGeneratorServiceProvider.php:120` (the factory closure `variationSkuStrategy()` returns — no HTTP/application caller invokes it yet, only `tests/Feature/CatalogSkuGeneratorTest.php`, matching the Hook Reference row's own accurate statement) |
| `account.registered` | `AccountRegistrationController.php:57` |
| `order.placed` | `CheckoutOrchestrator.php:197` |
| `order.payment_confirmed` | `OrderPaymentConfirmer.php:181` |

**Registration sites (3 total, all in `app/Providers/`):** `catalog.product.slug`
(`CatalogSlugGeneratorServiceProvider.php:38`), `catalog.product.base_sku` and
`catalog.variation.sku` (`CatalogSkuGeneratorServiceProvider.php:46,70`) — both
providers wired via `bootstrap/providers.php:6-7`. **Zero production listeners
exist for `catalog.variation.barcode`, `account.registered`, `order.placed`,
`order.payment_confirmed`** — confirmed extension points only, exactly as
`extensibility-design-and-hooks.md` §3 already documents for the latter three
(and, correctly, for barcode too).

**Domain-package violation check: none found.** A repo-wide grep of
`packages/EasyCo/*/src` (excluding `documents/`) for `Hook::(fire|action|apply|
filter)\(` returns matches only inside docblock prose and an exception message
in `VariationCombinationGenerator.php:54,59,104,106` — text *about* the
architectural boundary, never a real call. The rule holds without exception.

**Not-yet-built hook names — confirmed absent from all code**, `app/` and
`packages/EasyCo/` alike: `order.status_changed`, `order.cancelled`,
`order.returned`, `order.refund_recorded`, `order.refund_paid_out`, `order.refund_cancelled`, `checkout.form.fields`,
`checkout.request.data`, and any `pricing.*` name. Docs only.

### 0.3 The Hook Reference table vs. reality — the mismatch this pass found

`extensibility-design-and-hooks.md` §3's table lists exactly the seven hooks
that exist in code, with matching signatures. **But its "Fired from" column is
stale for three of the seven**, and this is a live instance of the exact
failure mode its own rule ("a row here in the same commit") exists to
prevent — not hypothetical, not fixed by §6 in the abstract, actually true
today:

- `catalog.product.base_sku` and `catalog.product.slug` each name only
  `ProductController::store()` — in reality each fires from **eight** call
  sites (§0.2's table above): the original controller plus `DuplicateProduct`,
  `VariableProductController`, and four Filament page classes — one of which
  (`CreateVariableProduct`) calls the hook twice, once for the wizard's live
  preview and once for the actual save — none of which existed, or at least
  weren't wired to this hook, when that row was last written.
- `catalog.variation.barcode` names only `ProductController::store()` —
  in reality it fires from **five** sites, the same pattern.
- `catalog.variation.sku`, `account.registered`, `order.placed`,
  `order.payment_confirmed` remain accurate as written — one real call site
  each, matching the table exactly.

This mismatch is reported, not silently fixed (scope discipline) — §8's
landing table schedules the correction as a pure documentation edit with no
code dependency.

### 0.4 What is designed but not built

`order-lifecycle-design.md` §12's five hooks (`order.status_changed`,
`order.cancelled`, `order.returned`, `order.refund_recorded`, `order.refund_paid_out`, `order.refund_cancelled` — Actions; that section
also states "No filter hooks in this design, deliberately"). `checkout-
domain-design.md` §12.2's two hooks (`checkout.form.fields`,
`checkout.request.data` — Filters) plus its one Site Settings key
(`checkout.phone_call_field_enabled`). `pricing.line_total` is **not** a
designed-but-unbuilt hook — it appears exactly once, in
`extensibility-design-and-hooks.md` §1's own prose, as an illustrative name
contrasting actions and filters ("a developer reading `Hook::fire('order.
placed', ...)` versus `Hook::apply('pricing.line_total', ...)`"). It was
never proposed as a real hook, and — per §1's own policy below — a line total
is exactly the kind of value a filter must never touch, so this pass records
its absence as a deliberate non-gap, not an oversight to close.

### 0.5 Filament v5.8.1's real extension surface

`composer.lock` resolves `filament/filament` to `v5.8.1` across all nine
satellite packages. Reading the installed source directly (not recalled):

- **Render hooks** — `Filament\Support\Facades\FilamentView::registerRenderHook()`
  (`vendor/filament/support/src/Facades/FilamentView.php:28`), invoked via
  named slot constants in `Filament\View\PanelsRenderHook`
  (`vendor/filament/filament/src/View/PanelsRenderHook.php` — ~50 slots
  including `PAGE_HEADER_ACTIONS_BEFORE/AFTER`, `RESOURCE_PAGES_LIST_RECORDS_
  TABLE_BEFORE/AFTER`, `SIDEBAR_NAV_START/END`) and `Filament\Tables\View\
  TablesRenderHook` (`HEADER_BEFORE/AFTER`, `TOOLBAR_START/END`,
  `FILTER_INDICATORS`, `HEADER_CELL`), each rendered from real Blade call
  sites (e.g. `vendor/filament/tables/resources/views/index.blade.php:286-848`).
  **These add adjacent markup only — they cannot inject a real `Column`/
  `Filter`/`Action` object into a table's own definition.**
- **Panel plugins** — `Filament\Contracts\Plugin { getId(); register(Panel);
  boot(Panel); }` (`vendor/filament/filament/src/Contracts/Plugin.php:7-14`),
  registered via `Panel::plugin()`/`plugins()`
  (`HasPlugins.php:15-33`) — `register()` receives the **live** `Panel`
  instance, so a plugin can call any Panel fluent method (`->resources()`,
  `->pages()`, `->widgets()`, `->navigationItems()`,
  `FilamentView::registerRenderHook()`) without editing an existing class.
- **Table/form contents are class-edit/subclass-only.** `Resource::table()`/
  `form()` (`Resource.php:58,68`) are plain `public static` methods meant to
  be overridden by subclassing; `Table`/`Schema` support `Macroable`
  (`Component.php:7,16`), which adds new fluent *methods*, not a way to
  retroactively inject a column/filter/action into an already-defined
  resource from outside. **This is why §3 designs a registry rather than
  relying on stock Filament alone.**
  - **Navigation is genuinely dynamic**: `Panel::navigationItems(array $items)`
    (`HasNavigation.php:82`) is directly callable by any provider, not only by
    a Resource's own static declaration.
  - **Widgets are stock-registrable** too: `Panel::widgets()`/
    `Resource::getWidgets()`/`Page::getHeaderWidgets()`/`getFooterWidgets()` —
    all plain arrays a plugin's `register(Panel $panel)` can append to.

**This project uses none of the dynamic mechanisms today.**
`app/Providers/Filament/AdminPanelProvider.php:61-70` calls only
`discoverResources()`, `discoverPages()`, `pages([Dashboard::class])`,
`discoverWidgets()`, `widgets([...])` — no `->plugin()` call anywhere, no
`registerRenderHook()` anywhere in `app/Filament`. No Resource and no Page
under `app/Filament` is `final` (confirmed by grep across all eleven
Resources and every Page class). **The eleven Resources that exist today:**
`AttributeDefinitionResource`, `AttributeValueResource`, `BrandResource`,
`CategoryResource`, `OrderResource`, `ProductGroupResource`, `ProductResource`,
`RoleResource`, `SeasonResource`, `StaffResource`, `TagResource`. There is
**no** dedicated Promotions resource yet, and Settings are standalone `Page`s
(`app/Filament/Pages/Settings/{CatalogSettings,LocaleSettings}.php`), not a
Resource.

### 0.6 Service-provider discovery, and the compatibility gap it leaves open

EasyCo packages are Laravel-auto-discovered: each package's own
`composer.json` declares `extra.laravel.providers` (e.g. `EasyCo\Catalog\
Providers\CatalogServiceProvider`), compiled by `artisan package:discover`
into `bootstrap/cache/packages.php`, which lists all thirteen `easyco/*`
providers alongside third-party ones. **None of the thirteen is manually
listed in `bootstrap/providers.php`** — that file holds only eight `App\*`
providers. The root `composer.json`'s `repositories` entry
(`{"type": "path", "url": "packages/EasyCo/*"}`) is what makes any folder
under `packages/EasyCo/` installable at all; `extra.laravel.dont-discover` is
present but empty, so nothing is excluded. **No package declares a minimum
core-app version or a hook-API version anywhere** — compatibility between a
package and the core app is entirely implicit and unenforced today. This is a
real, load-bearing gap for a genuine third-party extension, and §5 proposes
closing it minimally rather than ignoring it.

---

## §1. Policy

### What counts as an "important action" that MUST fire a hook

An event after which an aggregate's durable state changed in a way another
system might reasonably care about — a create/update/status-change/delete on
an aggregate root — or a customer-facing lifecycle milestone already named in
that domain's own design doc (order placed, payment confirmed/refunded,
account registered). Concretely: if a `*-domain-design.md` already narrates
"X happened" in its own prose as a fact worth a merchant knowing, it is a
hook candidate; a purely internal bookkeeping step (a repository's own retry,
a cache warm) is not.

### When a filter is allowed — and the rule that generalizes across two
independent precedents

**A filter transforms display or optional-generation data. It never decides
a status, an amount, a stock quantity, or anything a DB constraint already
enforces.** This is not a new rule invented here — it is the same conclusion
reached twice, independently, by two different documents solving two
different problems: `order-lifecycle-design.md` §12 refuses a filter over
the transition target ("a filter over the target status would make §2.1's
matrix decorative — the whole argument of §5.1 is that the refusal is the
domain's, not a listener's"), and `order-context-design.md` §3.3 refuses a
filter over the attribution classification for the identical reason ("a
`Hook::filter` over the *classification* would let a listener contradict the
rules and make 'where did this order come from' have two answers"). Two
authors, two problems, one answer — this pass states it once, as the
standing policy, so a third case does not have to re-derive it.

### Naming

`{domain}.{entity}.{event|filter}` — the live exception is `order.*`
(two segments, domain *is* entity), which `order-lifecycle-design.md` §12
already justifies and this pass does not re-litigate: consistency with the
shipped name beats consistency with the diagram.

### Payload rules

The aggregate plus scalars — never a DTO, never a model. Already the rule
for every built and designed hook (`extensibility-design-and-hooks.md` §2,
`order-lifecycle-design.md` §12); this pass extends it explicitly to any
future API-response filter (§4): the payload there is a plain array, never
an Eloquent Resource or model.

### Before/after-commit

Every action hook fires **after** the writing transaction commits — proven by
every built example (`CheckoutOrchestrator`'s Phase 2, `OrderPaymentConfirmer`
§4.3 step 6, order-lifecycle §12's own closing note) and by the reasoning
behind it: a listener must never be able to roll back a fact that already
happened, and no listener may hold a row lock while doing external work. A
filter has no commit concept — it fires synchronously, inline, at the exact
point the value is being computed, and its result is used immediately by the
same request.

### Stability and deprecation — a hook signature is a public API

No version negotiation exists per-hook today (§0.6), and with twelve real
hooks total, per-hook versioning would be premature. This pass adopts one
project-wide rule instead:

- **Adding a new, trailing, defaulted parameter to an existing signature is
  non-breaking** — the same convention `Payment::confirm()`'s own
  `$confirmedAt` parameter already uses (added last, defaulted, every
  existing call site keeps working unchanged), and the same category CLAUDE.md
  rule 7 already carves out for structural fields.
- **Removing, reordering, or changing the meaning of an existing parameter is
  breaking, and requires a NEW hook name** — the old one deprecated (documented
  as such in the Hook Reference, kept firing for one full stage/release), the
  new one added alongside it. This mirrors how `order-lifecycle-design.md`
  itself handled `FULFILLED`'s removal: a guarded migration that refuses
  rather than silently reinterpreting a value in place, never an in-place
  meaning change to something already shipped.
- A project-wide `hook_api_version` (not per-hook) is what §5 has an
  extension declare compatibility against.

### What is deliberately NOT hookable, and why

- **Anything a DB constraint already enforces** (a unique index, an FK, the
  SQLSTATE-driven collision retries CLAUDE.md rule 3 requires) — a hook here
  would let an extension race or bypass an invariant the database is the
  authority for.
- **A domain's own transition matrix** (`OrderStatus::canTransitionTo()`,
  `Product`'s SIMPLE/VARIABLE guards) — same reasoning as the filter rule
  above, restated as a hard boundary rather than a filter-type restriction:
  no hook of either kind touches these.
- **Anything inside a CLAUDE.md "do not touch without explicit instruction"
  file** (`Product.php`, `Variation.php`, `VariationSignature.php`) — a hook
  requiring an edit there carries the same extra-confirmation bar as any
  other change to those files; §2's inventory flags every row that would
  touch one, rather than proposing it as routine.

---

## §2. Coverage inventory

Status is one of **built** (fires in production code today), **designed**
(a real doc decided it, no code yet), or **PROPOSED** (this pass's own
suggestion — no owner brief or prior document named it, each with a stated,
concrete use case per the brief's own "do not invent hooks nobody could use"
rule). Rows already covered in full by §0.2/§0.3/§0.4 above are not repeated
in the "why" column.

### Catalog

| Hook | Type | Signature | Fired from | Timing | Status |
|---|---|---|---|---|---|
| `catalog.product.base_sku` | Filter | `(string $baseSku): string` | 8 sites, §0.2 | Inline, before `Product::createSimple()`/analogous write | Built |
| `catalog.product.slug` | Filter | `(string $value, string $name): string` | 8 sites, §0.2 | Inline | Built |
| `catalog.variation.sku` | Filter | `(string $value, string $baseSku, Product $product): string` | Factory closure only | Inline | Built, unwired |
| `catalog.variation.barcode` | Filter | `(string $value, ?Variation $variation): string` | 5 sites, §0.2 | Inline | Built, zero listeners |
| `catalog.product.created` | Action | `(Product $product): void` | PROPOSED — `ProductController::store()`, Create pages, after commit | After commit | PROPOSED |
| `catalog.product.updated` | Action | `(Product $product): void` | PROPOSED — Edit pages, after save | After commit | PROPOSED |
| `catalog.product.published` / `catalog.product.archived` | Action | `(Product $product): void` | PROPOSED — `ProductStatusChanger::publish()`/`archive()` | After commit | PROPOSED |
| `catalog.product.deleted` | Action | `(string $productId): void` | PROPOSED — `CatalogDeletion::deleteProduct()`, after the §3.19 deletability check passes and commits | After commit | PROPOSED |
| `catalog.variation.created`/`updated`/`archived` | Action | `(Variation $variation): void` | PROPOSED — variation-level equivalents | After commit | PROPOSED |

**Use case for the five PROPOSED Catalog actions:** syncing a product's
lifecycle to an external PIM, marketplace feed, or search index without
polling — the concrete first attachment point for
`channel-native-commerce-vision.md` §6's "channel adapters" idea (a Google/
Meta feed adapter listens on `catalog.product.published`/`archived` to list/
delist an item) — named here as the use case, not as a commitment to build
the adapter.

### Pricing

**No hook exists, and none is proposed.** `pricing.line_total` (§0.4) is
prose, not a gap. Per §1's policy, an amount is exactly the category a filter
must never touch — this is a stated decision, not an oversight.

### Inventory

| Hook | Type | Signature | Fired from | Timing | Status |
|---|---|---|---|---|---|
| `inventory.stock.changed` | Action | `(string $variationId, int $delta, int $newQuantity): void` | PROPOSED — the one app-layer caller of `StockLevelRepository::increase()`/`decrease()` (Checkout for decrease; a future restock admin action for increase), after the atomic conditional `UPDATE` commits | After commit | PROPOSED |

**Use case:** real-time availability pushed to an external feed —
`channel-native-commerce-vision.md` §18's own worked example
("`InventoryChanged` → Storefront cache / Google feed / AI feed / Search
index"), the one item in that document concrete enough to become a real,
narrowly-scoped hook rather than a whole event-stream architecture.

### Cart

| Hook | Type | Signature | Fired from | Timing | Status |
|---|---|---|---|---|---|
| `cart.line.added` / `cart.line.removed` | Action | `(Cart $cart, CartLine $line): void` | PROPOSED — `CartController`'s add/remove endpoints | After commit | PROPOSED |
| `cart.promotion.applied` | Action | `(Cart $cart, string $code): void` | PROPOSED — the endpoint `PromotionValidator` already backs | After commit | PROPOSED |

**Use case:** session-level analytics on cart behavior and mid-session code
usage — distinct from the already-designed, separately-owned
`cart.abandoned` hook (`cart-abandoned-recovery-note.md`), which this pass
does not redesign, only cross-references.

### Checkout

| Hook | Type | Signature | Fired from | Timing | Status |
|---|---|---|---|---|---|
| `checkout.form.fields` | Filter | `(array $fields): array` | The storefront checkout page controller | Inline, at render | Designed, not built |
| `checkout.request.data` | Filter | `(array $data): array` | `CheckoutController`, before the payload reaches `CheckoutOrchestrator` | Inline | Designed, not built |

Already fully designed by `checkout-domain-design.md` §12.2 — this pass adds
no new decision here, only cites it as §4's model.

### Promotions

| Hook | Type | Signature | Fired from | Timing | Status |
|---|---|---|---|---|---|
| `promotion.validated` | Action | `(string $code, bool $valid, ?string $refusalReason): void` | PROPOSED — `PromotionValidator`'s own caller, after validation | Inline (a read, not a write — "after commit" does not apply) | PROPOSED |
| `promotion.redeemed` | Action | `(PromotionRedemption $redemption): void` | PROPOSED — alongside `order.placed`, `CheckoutOrchestrator` Phase 2, after the redemption row commits (checkout-domain-design.md §7) | After commit | PROPOSED |
| `promotion.released` | Action | `(PromotionRedemption $redemption): void` | PROPOSED — alongside order-lifecycle §7.4/R11's release, when an order becomes `cancelled` | After commit | PROPOSED, depends on lifecycle stage 6 |

`promotion.validated` is deliberately an **Action reporting an outcome**, not
a Filter deciding one — per §1's policy, validity is derived from real usage
limits, and a filter that could flip it would let an extension grant a
discount the limits refuse.

### Order lifecycle

| Hook | Type | Signature | Fired from | Timing | Status |
|---|---|---|---|---|---|
| `order.placed` | Action | `(Order $order): void` | `CheckoutOrchestrator.php:197` | After commit | Built, zero listeners |
| `order.payment_confirmed` | Action | `(Payment $payment): void` | `OrderPaymentConfirmer.php:181` | After commit | Built, zero listeners |
| `order.status_changed` | Action | `(Order $order, OrderStatus $from, OrderStatus $to): void` | `OrderStatusChanger`, every public transition method | After commit | Designed, lifecycle stage 8 |
| `order.cancelled` | Action | `(Order $order, ?string $reason): void` | `OrderStatusChanger::cancel()` | After commit | Designed, lifecycle stage 8 |
| `order.returned` | Action | `(Order $order, array $returnedLines): void` | `OrderStatusChanger::cancel()`/`recordReturn()` | After commit | Designed, lifecycle stage 8 |
| `order.refund_recorded` | Action | `(Order $order, PaymentRefund $refund): void` | `OrderStatusChanger::cancel()`/`recordReturn()`, and `MoneyOnlyRefunder::record()` (refunds R3: a refund with goods 0) | After commit | Built (refunds R2a); replaces the designed `order.refunded` |
| `order.refund_paid_out` | Action | `(Order $order, PaymentRefund $refund): void` | `RefundStatusChanger::markPaidOut()` | After commit | Built (refunds R2a) |
| `order.refund_cancelled` | Action | `(Order $order, PaymentRefund $refund): void` | `RefundStatusChanger::cancelOwed()` | After commit | Built (refunds R2a) |
| `order.payment_receipt_recorded` | Action | `(Order $order, Payment $payment, PaymentReceipt $receipt): void` | `PaymentReceiptRecorder::record()` (refunds R4a-2; bank transfer only) | After commit; never on a replay or a refusal | Built (refunds R4a-2). Fires for a matching receipt AND a short/over one; a matching one is followed by `order.payment_confirmed`. The two resolution hooks below arrive with R4a-3 and are built |
| `order.payment_receipt_corrected` | Action | `(Order $order, Payment $payment, PaymentReceipt $new, PaymentReceipt $superseded): void` | `PaymentReceiptRecorder::correct()` (refunds R4a-3) | After commit; never on a replay or a refusal | Built (refunds R4a-3). Fires for BOTH kinds of correction. After settlement it is the **only** hook a correction fires (the payment is not touched: no `order.payment_confirmed`); before settlement a correction that makes the receipts add up to the expected amount also fires `order.payment_confirmed` |
| `order.payment_mismatch_accepted` | Action | `(Order $order, Payment $payment, string $reason): void` | `PaymentReceiptRecorder::acceptMismatch()` (refunds R4a-3; needs `payment_reconcile`) | After commit; never on a replay or a refusal | Built (refunds R4a-3). Fired when a short or over transfer is accepted as the payment's settled amount; `$payment->settledAmount()` is the accepted sum and `$reason` the merchant's. `order.payment_confirmed` follows |
| `admin.help.topics` | Filter | `(array $topics): array` | `App\Filament\Support\HelpTopics::all()` (Help 1) | On every help page render | **RESERVED — not built.** Would let a merchant extension add a help topic (a key, its label and its Markdown files). Today the topic list is the one registry class `HelpTopics`; nothing calls this hook |
| `shipping.free_hint` | Filter | `(array $hint, array $context): ?array` | `App\Services\FreeShippingHintReader` (shipping stage 3e, `shipping-domain-design.md` §5.1) — the single place the sentence is produced for `GET /api/cart` and `POST /api/shipping/quote` | On every cart/quote hint that is produced | **RESERVED — not built.** Would let a merchant extension adjust or suppress the "add X more for free shipping" sentence (`$hint` is the reader's output array or null; returning null would hide it). Today the reader produces the sentence and nothing calls this hook |
| `shipping.zone.created` | Action | `(ShippingZone $zone): void` | `App\Services\ShippingZoneWriter::create()` (shipping stage 5c, `shipping-domain-design.md` §12.6) | After commit — never inside the transaction, never for a refused or failed write | Built (stage 5c); **no listener is registered** — a zone was created (appended at the END of the match order); `$zone` is the saved entity |
| `shipping.zone.updated` | Action | `(ShippingZone $zone, array $before): void` | `App\Services\ShippingZoneWriter::update()` (shipping stage 5c, `shipping-domain-design.md` §12.6) | After commit — never inside the transaction, never for a refused or failed write | Built (stage 5c); **no listener is registered** — a zone was changed (never its order); `$zone` is the saved entity and `$before` the compact snapshot (`id`, `name`, `sort_order`, `countries`, `settlements`, `postcodes`) from before. Not fired when the update changed nothing |
| `shipping.zone.deleted` | Action | `(array $snapshot): void` | `App\Services\ShippingZoneWriter::delete()` (shipping stage 5c, `shipping-domain-design.md` §12.6) | After commit — never inside the transaction, never for a refused or failed write | Built (stage 5c); **no listener is registered** — a zone with no methods was deleted; `$snapshot` is the same compact snapshot of what it was |
| `shipping.zone.reordered` | Action | `(array $change): void` | `App\Services\ShippingZoneReorderer::moveUp()` / `::moveDown()` (shipping stage 5c, `shipping-domain-design.md` §12.6) | After commit — never inside the transaction, never for a refused or failed write | Built (stage 5c); **no listener is registered** — two zones swapped places and the order was rewritten dense (0..n-1); `$change` is `[direction => up|down, zones => [[id, name, position_before, position_after] x 2]]`. Not fired for a move that changes nothing (the first zone up, the last down) |
| `shipping.method.created` | Action | `(ShippingMethod $method, ?string $copiedFrom): void` | `App\Services\ShippingMethodWriter::create()` and `ShippingMethodCopier::copyToZones()` (shipping stage 5d, `shipping-domain-design.md` §12.6) | After commit — never inside the transaction, never for a refused or failed write | Built (stage 5d); **no listener is registered** — a method was created, appended at the END of its zone's order; `$copiedFrom` is the source method's id when it is a copy (one hook per created copy), null otherwise |
| `shipping.method.updated` | Action | `(ShippingMethod $method, array $before): void` | `App\Services\ShippingMethodWriter::update()` (shipping stage 5d, `shipping-domain-design.md` §12.6) | After commit — never inside the transaction, never for a refused or failed write | Built (stage 5d); **no listener is registered** — a method was changed (never its zone or its order); `$method` is the saved entity and `$before` the compact snapshot (`id`, `zone_id`, `name`, `kind`, `sort_order`, `active`, `price_minor`, `free_above_minor`, `class_mode`, `class_rates`, `requires_pickup_point`, `carrier_code`, and — from stage 5f — `courier` and `delivery_type`, both null when not set) from before. Not fired when the update changed nothing |
| `shipping.method.deleted` | Action | `(array $snapshot): void` | `App\Services\ShippingMethodWriter::delete()` (shipping stage 5d, `shipping-domain-design.md` §12.6) | After commit — never inside the transaction, never for a refused or failed write | Built (stage 5d); **no listener is registered** — a method was deleted with its class amounts; `$snapshot` is the same compact snapshot of what it was |
| `shipping.method.activated` | Action | `(ShippingMethod $method): void` | `App\Services\ShippingMethodWriter::setActive()` (shipping stage 5d, `shipping-domain-design.md` §12.6) | After commit — never inside the transaction, never for a refused or failed write | Built (stage 5d); **no listener is registered** — a method was switched ON through the list's toggle. Not fired when it already was on |
| `shipping.method.deactivated` | Action | `(ShippingMethod $method): void` | `App\Services\ShippingMethodWriter::setActive()` (shipping stage 5d, `shipping-domain-design.md` §12.6) | After commit — never inside the transaction, never for a refused or failed write | Built (stage 5d); **no listener is registered** — a method was switched OFF through the list's toggle. Not fired when it already was off |
| `shipping.method.reordered` | Action | `(array $change): void` | `App\Services\ShippingMethodReorderer::moveUp()` / `::moveDown()` (shipping stage 5d, `shipping-domain-design.md` §12.6) | After commit — never inside the transaction, never for a refused or failed write | Built (stage 5d); **no listener is registered** — two methods of one zone swapped places and the zone's order was rewritten dense (0..n-1); `$change` is `[direction => up|down, zone_id, methods => [[id, name, position_before, position_after] x 2]]`. Not fired for a move that changes nothing (the first method up, the last down) |
| `shipping.class.created` | Action | `(ShippingClass $class): void` | `App\Services\ShippingClassWriter::create()` (shipping stage 5b, `shipping-domain-design.md` §12.6) | After commit — never inside the transaction, never for a refused or failed write | Built (stage 5b); **no listener is registered** — a shipping class was created; `$class` is the saved entity |
| `shipping.class.updated` | Action | `(ShippingClass $class, array $before): void` | `App\Services\ShippingClassWriter::update()` (shipping stage 5b, `shipping-domain-design.md` §12.6) | After commit — never inside the transaction, never for a refused or failed write | Built (stage 5b); **no listener is registered** — a class's name or description was changed (never its code); `$before` is the compact snapshot (`id`, `code`, `name`, `description`) from before. Not fired when the update changed nothing |
| `shipping.class.deleted` | Action | `(array $snapshot): void` | `App\Services\ShippingClassWriter::delete()` (shipping stage 5b, `shipping-domain-design.md` §12.6) | After commit — never inside the transaction, never for a refused or failed write | Built (stage 5b); **no listener is registered** — an unused class was deleted; `$snapshot` is the same compact snapshot of what it was |
| `shipping.class.assigned` | Action | `(string $target, string $targetId, ?string $oldCode, ?string $newCode): void` | `App\Services\ShippingClassAssigner::setForProduct()` / `::setForVariation()` (shipping stage 5b, `shipping-domain-design.md` §12.6) | After commit — never inside the transaction, never for a refused or failed write | Built (stage 5b); **no listener is registered** — a class was assigned to or cleared from a product (`$target` = `product`, a SIMPLE product's single variation) or a variation (`variation`); `$oldCode` / `$newCode` are class codes, null = no class. Not fired when nothing changed |
| `shipping.class.default_changed` | Action | `(?string $oldCode, ?string $newCode): void` | `App\Services\ShippingClassWriter::setDefault()` / `::clearDefault()` (shipping stage 5e) | After commit — never inside the transaction, never for a refused or failed write | Built (stage 5e); **no listener is registered** — the store's default class changed (the previous default is cleared in the same transaction); codes, null = none. Not fired when nothing changed |
| `shipping.class.assigned_bulk` | Action | `(string $classCode, int $count): void` | the `shipping-classes:assign-missing` command (shipping stage 5e) | After the run — once, never per variation, never for a dry run or a run that changed nothing | Built (stage 5e); **no listener is registered** — `$count` variations that had no usable class were given `$classCode`. The per-variation `shipping.class.assigned` hook is NOT fired for them |

### Payment

| Hook | Type | Signature | Fired from | Timing | Status |
|---|---|---|---|---|---|
| `payment.attempt_result` | Action | `(Payment $payment, PaymentAttemptResult $result): void` | PROPOSED — `CheckoutOrchestrator` Phase 2 step 14, right after `Payment::recordAttemptResult()` | After commit | PROPOSED |
| `payment.voided` | Action | `(Payment $payment, PaymentRefund $void): void` | PROPOSED — §7.3's void path, currently only readable via `order_events`' `payment_voided` type | After commit | PROPOSED |

**Use case for `payment.attempt_result`:** a fraud-signal listener watching
repeated `FAILED` attempts, or a follow-up email nudging an unpaid
bank-transfer order. **`payment.voided` is a genuine gap this pass
surfaces**, not a contradiction of order-lifecycle §12's "a void fires
nothing" note — that note is scoped to the refund hooks (`order.refund_recorded`, `order.refund_paid_out`) specifically (no
money moved, so no *money* hook), and does not rule out a dedicated
`payment.voided` hook for an accounting-export listener that wants the void
fact itself, distinct from a completed refund.

### Account / Staff

| Hook | Type | Signature | Fired from | Status |
|---|---|---|---|---|
| `account.registered` | Action | `(Account $account): void` | `AccountRegistrationController.php:57` | Built, zero listeners |

No Staff-lifecycle hook (login, permission change) is proposed: no concrete
extension scenario is on record from the owner, and inventing one would
violate the brief's own rule against speculative hooks.

### Media

No hook proposed. Media's extension surface today is Site Settings toggles
(Hero Slider on/off, aspect ratio) — configuration, not an event stream — and
no concrete "something happened in Media" scenario is on record.

### Site Settings

| Hook | Type | Signature | Fired from | Status |
|---|---|---|---|---|
| `site_settings.changed` | Action | `(string $key, ?string $oldValue, string $newValue): void` | PROPOSED — `EloquentSiteSettingsRepository::set()`, after write | PROPOSED |

**Use case:** a future settings-value cache's invalidation (`site-settings-
design.md` §8 explicitly defers caching — this is what a cache layer, or any
extension reacting to a merchant toggling a feature, attaches to).

### Hook Reference rows this inventory found stale or missing

Restated from §0.3: the "Fired from" cells for `catalog.product.base_sku`,
`catalog.product.slug`, and `catalog.variation.barcode` in
`extensibility-design-and-hooks.md` §3 each name one call site where eight
(or five) now exist. §8's landing table schedules the correction.

---

## §3. Admin UI extension points

**Decision: one registry service, `App\Services\AdminExtensionRegistry`, in
`app/`, consulted by each core Resource/Page — not `Hook::apply()` calls
scattered through Filament classes (X2's own default, engaged with below
rather than assumed).**

### Why a registry rather than routing this through `Hook::` directly

A render hook or `Hook::apply()` call answers one question at one point in a
render. A registry can also answer "what extensions exist for Resource X" as
a **queryable list** — exactly what §6's `hooks:list`-style command and any
future "installed extensions" admin screen need, and what `Hook::` (a fire-
and-collect mechanism, not an index) cannot answer without a parallel
bookkeeping layer anyway. It also keeps `EasyCo\Extensibility` itself
Filament-agnostic: the registry is its own small class in `app/`, not a
Filament-flavored addition to a domain-agnostic package. And it is cheaper:
extension registration happens once, at boot, in each extension's own
`ServiceProvider::boot()`; forcing that through `Hook::apply()` would mean
every Resource calls `Hook::apply('admin.product.sections', [])` on every
single request a human loads that page, for a value boot-time registration
can compute once.

### The mechanism, per admin surface that exists today

Because stock Filament v5.8.1 has no dynamic column/filter/action injection
API (§0.5), each of the following is **new code added inside each core
Resource/Page's own method** — a one-line call at the end of `table()`/
`form()`/the infolist builder — not a Filament-native mechanism being merely
exposed:

| Surface | Registry call | Merged into |
|---|---|---|
| Infolist section (View page) | `registerInfolistSection(string $resourceClass, Closure $factory, Permission $gate)` | The core Page's own infolist-building method |
| Table column | `registerTableColumn(string $resourceClass, Closure $factory, Permission $gate)` | The core Resource's `table()` |
| Table filter | `registerTableFilter(...)` | Same |
| Row / header / bulk action | `registerRowAction`/`registerHeaderAction`/`registerBulkAction` | Same, or the Page's own actions array |
| Tab (a `Tabs`-laid-out page) | `registerTab(...)` | The page's own `Tabs::make()` call — the `ProductResource` Axes/Variations precedent (`admin-panel-design.md` §13.1/§13.6) is the shape this follows |
| Navigation item | Forwards directly to stock `Panel::navigationItems()` | No registry-side bookkeeping needed — already dynamic |
| Dashboard widget | Forwards directly to stock `Panel::widgets()` / a plugin's own `register(Panel)` | Same |
| Settings page | The extension registers its own `Page` class; the registry only calls `Panel::pages()` to add it to navigation | No different in kind from a core settings page |

Retrofit surfaces today: `OrderResource` (View infolist, List table) and
`ProductResource` (List table, View/Edit infolists) — §8 stages this.

### Permission gating — structural, not left to author discipline

Every `register*` call **requires** a `Permission` case alongside the
closure. The registry itself wraps invocation with
`$staff->can($permission)` before ever calling the extension's closure — an
extension cannot register something that only *looks* gated, because the
gate is the registry's own code, not the extension's. This structurally
enforces the same rule `admin-panel-design.md` §13.9 states for core code
("every handler... re-checks `PRODUCT_MANAGE` as its first statement"),
extended to third-party authors who cannot be trusted with that discipline
the way this project's own code can.

**No public Livewire method taking an id.** A registered row-action closure
receives the record Filament's own table row already resolved — never a bare
id an extension would look up itself — closing the exact injection surface
`admin-panel-design.md` §13.9 names for core actions.

### Escaping / XSS

Any label or text an extension supplies goes through Filament's own
`Action::label()`/`TextColumn::label()`-style APIs, Blade-escaped by default;
a genuine HTML need uses Filament's own explicit `->html()` opt-in, the same
rule a core Resource already follows. No bespoke escaping layer.

### Performance — the existing query-count rules stay valid

The registry's `*For()` lookups are read-only array reads against a
boot-time-populated in-memory map (populated once per request, the same
lifecycle every `scoped()` service in this codebase already uses) — the
registry itself adds zero queries. Any query cost an *extension's own*
closure introduces is the extension's responsibility, a hard requirement in
the packaging contract (§5) and the thing §7's proof of concept exists to
demonstrate cleanly (its column proven batched, the `ProductPriceRangeProvider`
way — `admin-panel-design.md` §13.7). The existing 5-vs-25-row query-count
tests stay valid unmodified as long as no *installed* extension violates
that contract.

### Error isolation — one narrow, named exception to extensibility §4

**Recommendation: fail loud in local/testing; isolate-and-log in production,
scoped only to admin-UI rendering.** The registry wraps each individual
extension closure's invocation in its own `try`/`catch`, logs to a dedicated
`extensions` channel, and renders a small "an installed extension failed to
render this section: `<extension id>`" placeholder in its place — never
silently swallowed, never blocking the rest of the page. The justification
for deviating from extensibility §4's blanket uncaught-propagation policy:
an admin page is a single Livewire component serving a human waiting on it
*right now* — one broken third-party section must not take the whole
Order/Product view down for a staff member who needs the rest of the page to
do their job. **This exception is scoped strictly to admin-UI rendering
extension points.** Every domain hook (`order.placed`, `order.cancelled`,
etc.) keeps propagating uncaught exactly as designed — that failure means a
business fact was not reliably communicated to an app-layer caller, never a
human staring at a screen, and needs to surface synchronously. Flagged in
§8's open questions as the owner's call, not assumed settled.

### Translation

Extension labels follow the project's own `lang/{bg,en}/...` convention if
the extension author chooses to ship EasyCo-style translations — the
registry cannot enforce this on a third party; it is a documented
expectation in the packaging contract (§5), not a structural guarantee, the
same way core's own translated labels are a project convention rather than
something Filament itself enforces.

---

## §4. API and storefront extension

Contract only — the storefront does not exist yet (`order-context-design.md`
§0 item 6: `routes/web.php` has exactly one route).

- **Response-shaping filters — PROPOSED, not built.** `Hook::apply('api.
  {resource}.response', $payload, ...)` at the point each existing JSON API
  controller (Cart, Account, Catalog) builds its response array. Payload is
  a plain array, per §1's rule — never an Eloquent Resource or model.
- **Checkout's extra fields are this contract's first, already-decided
  instance.** `checkout.form.fields` (what renders) and `checkout.request.
  data` (the value, seen just before domain code) together form a **two-
  filter sandwich** — a render-time filter plus a pre-persist filter — that
  this document recommends reusing verbatim for any future storefront
  extension point needing to add form data, rather than inventing a new
  shape per feature.
- **EasyCo persists nothing on an extension's behalf, as a standing rule, not
  only for checkout.** Already decided there ("no generic `order_meta` table
  is introduced") — this pass generalizes it: an extension that needs to
  keep a value writes it to its **own** table, keyed by the relevant
  aggregate's id, from its **own** listener on the nearest Action hook
  (`order.placed` today; the equivalent future hook for whatever aggregate
  the extension cares about).
- **The contract the future storefront gets:** `storefront-frontend-design.md`
  §6 already commits it to consuming the existing API and adding new *read*
  endpoints on the relevant domain's own controller when a need arises — this
  pass's only addition is that any such new read endpoint should carry the
  `api.{resource}.response` filter from day one, so a storefront-specific
  extension never waits for a retrofit.

---

## §5. Extension packaging and lifecycle (V1, kept minimal)

**Package skeleton:** an ordinary Composer package with `extra.laravel.
providers`, auto-discovered exactly as every EasyCo package already is
(§0.6) — nothing new to teach. Recommended, unenforced convention: a
third-party extension's package name should not start with `easyco/`, to
keep first-party and third-party packages visually distinct in
`composer.json`.

**Auto-discovery:** already a proven mechanism (§0.6) — `composer require`
plus `artisan package:discover` (already wired into `composer.json`'s
`post-autoload-dump` script) is the whole install story.

**Declared compatibility — the gap §0.6 found, closed minimally.** One
project-wide `extra.easyco.hook_api_version` string (e.g. `"1.0"`) in the
extension's `composer.json`, checked at boot by a small
`ExtensionCompatibilityChecker` that **logs a warning, never hard-fails** —
an incompatible extension should degrade visibly, not take the whole site
down. No per-hook versioning in V1 (§1) — the number changes only on a
breaking change to the payload/error-handling conventions themselves, never
on an additive new hook.

**Enable/disable per installation — reuses Site Settings exactly, no new
domain.** One key per extension, `extensions.<id>.enabled` (boolean, default
`true` once installed) — the same plain key-value mechanism `site-settings-
design.md` already built. **Checked at invocation time, not only at boot**,
since Site Settings is itself runtime-editable without a redeploy: the
recommended shape is for the Hook facade and the registry (§3) to each accept
an optional `extensionId` tag at registration time and centralize the
enabled-check there, rather than trusting every extension author to check it
themselves inside every listener.

**Extension-owned tables/migrations/settings namespacing.** An extension's
migrations live in its own package, auto-loaded the same way an EasyCo
package's migrations are — an already-proven pattern, not new design. Table
names and Site Settings keys must be prefixed with the extension's own short
id (`ext_<id>_...` / `extensions.<id>.*`) — a documented convention, **not
code-enforced in V1**, flagged as a real collision risk left to a future
pass rather than silently assumed away.

**Uninstall and data ownership.** V1's whole story is `composer remove` plus
a manual migration rollback — no automated "uninstall hook" exists, matching
§6's own "queued work is the listener's job, not a core feature" precedent:
an uninstall routine is the extension's own artisan command. **When an
extension is disabled but its data remains** (the Site Settings toggle,
without an uninstall): its tables and rows simply stay, inert, until
re-enabled or genuinely uninstalled — the expected V1 state, stated
explicitly rather than left as an implicit surprise.

**Deferred, per the brief:** marketplace, licensing, auto-update — none
designed, none implied by anything above.

---

## §6. Enforcement — so the rule cannot rot again

- **A scan test** over `app/` (expecting matches) and `packages/EasyCo/*/src`
  (expecting **zero**, proving the architectural boundary continuously, not
  just at audit time) for every `Hook::(fire|action|apply|filter)\(` call,
  asserting each distinct hook name has a row in `extensibility-design-and-
  hooks.md` §3 — and the reverse: every table row has either a real call
  site or an explicit "designed, not built" cross-reference this test also
  parses. This directly targets the drift §0.3 already found live — proof
  the rule needs a test, not just a restatement, since the rule already
  failed once in shipped documentation.
- **`hooks:list`**, an artisan command backed by a small, generated JSON
  manifest the same scan test emits and CI diffs — giving a live "what can I
  hook into on this installation" view. This resolves extensibility §5's
  deferred introspection gap narrowly: hook **names and signatures**, not
  "what's currently registered on each," which stays deferred (no concrete
  need surfaced this pass).
- **New hook, same commit as its call site** — already extensibility §3's
  rule; the scan test is what turns a violation into a CI failure instead of
  a silently-discovered gap, the `order.placed` precedent this document's own
  origin already cites.
- **Deferred items, revisited:** listener removal and registry introspection
  beyond `hasListeners()` **stay deferred** — no concrete use case surfaced
  this pass either. Queued dispatch **stays deferred as a core feature** —
  confirmed recommendation: a listener wanting async behavior dispatches its
  own job (`dispatch(new SomeJob(...))`, Laravel's own primitive, already
  used for `Bus::batch()` bulk admin actions per `admin-panel-design.md` §7),
  never a change to `HookRegistry::doAction()` itself.

---

## §7. Proof of concept (described, not built)

A tiny sample package (e.g. `sample/order-extension-demo`), used later as the
acceptance test for §3's mechanism and §6's enforcement tooling:

- `SampleOrderExtensionServiceProvider::boot()` registers:
  - `AdminExtensionRegistry::registerInfolistSection(OrderResource::class,
    fn () => Section::make('Sample Extension')->schema([...]),
    Permission::ORDER_VIEW)` — one section on the Order View page.
  - `AdminExtensionRegistry::registerTableColumn(OrderResource::class,
    fn () => TextColumn::make('sample_note'), Permission::ORDER_VIEW)` — one
    column on the Orders list, **proven batched** (no per-row query — the
    `ProductPriceRangeProvider` shape, `admin-panel-design.md` §13.7).
  - `AdminExtensionRegistry::registerRowAction(OrderResource::class,
    fn () => Action::make('sample_flag')->action(...),
    Permission::ORDER_MANAGE)` — one row action.
  - `Hook::action('order.placed', function (Order $order) { ... })` — writes
    to the extension's own table, keyed by `$order->id()`, per §4's "extensions
    persist nothing through core" rule.
- Its own migration: `ext_sample_order_notes` (`id`, `order_id`, `note`,
  timestamps).
- Its listener's write proven idempotent-safe against a re-fired event —
  relevant once queued dispatch exists anywhere downstream of a listener's
  own choice (§6).

---

## §8. Retrofit plan

**Surfaces to touch, in priority order:** `OrderResource` first (View
infolist, List table) — the highest-priority surface, since it is also where
order-lifecycle stage 8 and order-context stage 6's own new sections land;
`ProductResource` second (List table, View/Edit infolists) — already
Catalog's highest hook-coverage surface. No Promotions resource exists yet
(§0.5) — nothing to retrofit there; a future `PromotionResource` should be
**built with the registry from day one**, never retrofitted. Settings pages:
lowest priority, deferred until a real extension needs one.

**Ordering relative to other staged work.** The registry and its
`OrderResource` wiring should land in the **same stage** as order-lifecycle
§12's five hooks and order-context's own admin work (both already staged as
lifecycle stage 8 / order-context stage 6 in their own documents) — building
the extension points alongside the new Order View sections (Origin, Payment,
etc.) means those sections are registry-consulting from birth, directly
matching X2's own instruction that admin UI extension points be "built WITH
the admin work," not retrofitted a second time.

**Staged build plan, one review gate per stage (mirrors `order-lifecycle-
design.md` §10's own staging convention):**

1. `AdminExtensionRegistry` skeleton, consulted nowhere yet, plus §6's scan/
   manifest test proven against today's zero-extension baseline. Review
   gate: the test passes trivially, proving the harness works before
   anything depends on it.
2. Wire `OrderResource`'s View/List to consult the registry (empty results,
   unchanged behavior). Review gate: a real query-count test proves the
   consultation call itself costs zero extra queries with nothing installed.
3. Wire `ProductResource` the same way. Same review gate.
4. Build and install §7's sample extension against the now-wired registry.
   Review gate: section/column/action/listener all work; the column's query
   cost is measured and proven batched; the listener's write is proven
   idempotent-safe.
5. `hooks:list` + the Site Settings enable/disable wiring + the
   compatibility-version check. Review gate: disabling the sample extension
   via Site Settings makes its section/column/action disappear and its
   listener stop firing, proven by a real test toggling the setting.

**Landing table — cross-reference edits to other documents, each written out
in full, NONE applied in this pass:**

| Document | Edit | Lands |
|---|---|---|
| `extensibility-design-and-hooks.md` §3 | Update the "Fired from" cell for `catalog.product.base_sku`, `catalog.product.slug`, and `catalog.variation.barcode` to list every real call site found in §0.2 of this document, not only `ProductController::store()`. | Stage 1 — pure documentation correction, no code dependency. |
| `extensibility-design-and-hooks.md` §5 | Add: "Hook API versioning and per-extension enable/disable are now designed — see `extensibility-coverage-design.md` §5." | On approval of this document. |
| `order-lifecycle-design.md` §12 | Add a closing sentence: "Admin UI extension points for these five hooks' surfaces are designed in `extensibility-coverage-design.md` §3/§8." | Stage 2, once `OrderResource` is wired. |
| `admin-panel-design.md` §8 | Expand "Two complementary layers" to name the registry explicitly as the mechanism behind layer 1's Filament-class extension path, so it no longer implies direct subclassing is the only option. | Stage 1. |
| `checkout-domain-design.md` §12.2 | Add: "This is the first instance of the general checkout/API extension contract — see `extensibility-coverage-design.md` §4." | On approval of this document. |
| `site-settings-design.md` §1 | Add `extensions.<id>.enabled` as a confirmed future consumer *family* (not a single key). | Stage 5. |

**Open owner questions — only real decisions:**

- **Q1.** Error isolation for admin-UI extensions (§3): this document
  recommends isolate-and-log in production, fail-loud in local/testing, as a
  narrow named exception to extensibility §4. Confirm or override.
- **Q2.** The soft "extension package name must not start with `easyco/`"
  convention (§5): worth enforcing in code (the compatibility checker could
  refuse/flag a violator), or leave as documentation only?
- **Q3.** Whether `api.{resource}.response` filters (§4) should ship ahead of
  the storefront existing, or wait for a real consumer to name its first
  concrete need. This document defaults to "wait" (no filter without a real
  caller, per §2's own "don't invent hooks nobody could use" rule) but flags
  it as the owner's call, since X1's "many merchants, many extensions"
  framing could argue for shipping the seam early.
- **Q4.** Whether `payment.attempt_result`/`payment.voided` (§2) are wanted
  at all — neither was named explicitly in the owner brief the way the
  order/checkout hooks were; both were extrapolated from "payment attempt
  result" in the task brief. Confirm before treating them as more than
  proposed.

---

## Non-goals (unchanged from the brief)

No code, no marketplace/licensing, no theme system, no storefront
implementation, no payment-gateway extension design beyond noting
`PaymentMethodAdapter` is the existing seam, no removal of the fail-loud
listener policy for domain actions (§3's isolate-and-log recommendation is
scoped to admin-UI rendering only, never to a domain hook).

## Tests

None. This pass adds one file: `packages/EasyCo/documents/
extensibility-coverage-design.md`. No migration was added.
