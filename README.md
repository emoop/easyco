<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

# EasyCo

EasyCo is a modular Laravel commerce platform. Rather than one monolithic application, each business domain — Catalog, Pricing, Account, Address, Inventory, Cart, Promotions, Payment, Order — lives in its own independently developed, independently testable package under `packages/EasyCo/`, communicating through small, explicit contracts rather than shared internal state. Cross-domain references are plain ids: no package depends on another package's classes.

Checkout itself is deliberately **not** a domain package. It is an orchestration layer in `app/Services/`, since it owns no aggregate of its own and exists only to coordinate the packages that do — see [checkout-domain-design.md](packages/EasyCo/documents/checkout-domain-design.md) §1.

## Not deployable yet

Every merchant-facing endpoint is now protected — `routes/api.php` sits
entirely behind the `staff` auth guard plus a declared `staff.can:*`
permission per route (see
[staff-access-domain-design.md](packages/EasyCo/documents/staff-access-domain-design.md)),
audited by a test that scans the real route table so a future
unprotected route fails the suite rather than shipping. What's still
missing before a real deployment:

- **No staff login/logout HTTP endpoint yet.** The
  `staff:create-administrator` artisan command bootstraps the first
  administrator directly on the server, and the guard itself is fully
  functional — but there's no browser-session login endpoint yet for
  staff, only for the customer-facing `Account` guard.
- **No admin UI or storefront UI yet.** See
  [production-requirements.md](packages/EasyCo/documents/production-requirements.md)
  for what a server needs to actually run EasyCo once those exist, and
  `performance-and-channel-strategy.md` for the planned direction
  (Filament/Livewire for admin, server-rendered Blade+Alpine for the
  storefront).

The customer-facing surfaces (cart, checkout, account) are, and always
have been, correctly scoped by session token or the `customer` guard.

## Architecture note

This project was originally scaffolded on top of [Bagisto](https://bagisto.com/). It was rebuilt from a clean Laravel installation instead: integrating our domain packages deeply enough into Bagisto would have required either modifying Bagisto's core directly or duplicating large portions of its admin Blade views into our own packages. Both options work against this project's core philosophy — well-isolated, independently testable domain packages with a small, explicit public surface — so the Bagisto scaffold was set aside in favor of building that architecture directly on a plain Laravel app.

## Packages

| Package | Description | Design doc |
|---|---|---|
| [`packages/EasyCo/Pricing`](packages/EasyCo/Pricing) | Currency-aware, tax-aware `Money`/`Price` value objects and price resolution across scoped, prioritised price lists. Also owns `ProductCost`/`CostPriceProvider` — internal-only cost data, deliberately never bound to any storefront-facing service. | [pricing-domain-design.md](packages/EasyCo/documents/pricing-domain-design.md), [pricing-persistence-domain-design.md](packages/EasyCo/documents/pricing-persistence-domain-design.md) |
| [`packages/EasyCo/Catalog`](packages/EasyCo/Catalog) | The Product/Variation aggregate — attributes, variation axes, media and size-guide references — shared across Web, POS, Social, and AI channels. Also owns Brand/Category/Tag (categories nest via `parent_id`), a Product's brand assignment, and the Product↔Category/Product↔Tag pivot associations — all with full HTTP create/list/attach/detach surfaces. | [catalog-domain-design.md](packages/EasyCo/documents/catalog-domain-design.md) |
| [`packages/EasyCo/Extensibility`](packages/EasyCo/Extensibility) | A WordPress-style hooks system (actions/filters). Foundational and framework-agnostic — no dependency on Catalog, Pricing, or Laravel in its core logic; consumed only by the `app/` layer, never by domain packages directly. `order.placed` is the first hook fired by real production code. | [extensibility-design-and-hooks.md](packages/EasyCo/documents/extensibility-design-and-hooks.md) |
| [`packages/EasyCo/OperationalSales`](packages/EasyCo/OperationalSales) | The record-keeping side of a sale — `Client`, `Transaction`, immutable `SaleLine`, `InstallmentPlan` — shared identically by POS and Web. A `Client` may link to an `Account` (`account_id`), set once at first checkout and never re-synced afterwards. | [operational-sales-domain-design.md](packages/EasyCo/documents/operational-sales-domain-design.md) |
| [`packages/EasyCo/Account`](packages/EasyCo/Account) | Customer registration, login (rate-limited), logout, and session — a separate `customer` auth guard from Laravel's default. | [account-domain-design.md](packages/EasyCo/documents/account-domain-design.md) |
| [`packages/EasyCo/Staff`](packages/EasyCo/Staff) | Merchant-surface identities (`Staff`) and role-based permissions (`Role`, the `Permission` vocabulary) — structurally separate from `Account`, backed by its own `staff` auth guard. Three shipped roles (Administrator, Manager, Product Entry) ship via an idempotent seeder; `php artisan staff:create-administrator` bootstraps the first one. Every existing merchant route in `routes/api.php` is enforced by `staff.can:*` middleware, audited by a route-table test. | [staff-access-domain-design.md](packages/EasyCo/documents/staff-access-domain-design.md) |
| [`packages/EasyCo/Address`](packages/EasyCo/Address) | Delivery addresses in two mutually exclusive shapes — a street address, or a courier pickup point (`carrierCode`/`pickupPointReference`/`settlement`, deliberately carrier-agnostic). Belongs to an Account or to nobody (a guest's one-off address). | [address-domain-design.md](packages/EasyCo/documents/address-domain-design.md) |
| [`packages/EasyCo/Inventory`](packages/EasyCo/Inventory) | A single stock quantity per Variation, atomic increase/decrease at the repository layer, and a soft availability check only — no reservation, by deliberate decision. Stock is committed hard at checkout finalization. | [inventory-domain-design.md](packages/EasyCo/documents/inventory-domain-design.md) |
| [`packages/EasyCo/Cart`](packages/EasyCo/Cart) | Guest and logged-in shopping carts (session-token vs. `account_id`), pricing resolved live on every read (never snapshotted), a soft stock check at add-time, merge-on-login, and expiry with a `cart:prune` command. Also carries `order_id`, the atomic claim that makes checkout idempotent. | [cart-domain-design.md](packages/EasyCo/documents/cart-domain-design.md) |
| [`packages/EasyCo/Media`](packages/EasyCo/Media) | `MediaAsset` (image/video) with a queued image-processing pipeline (thumbnail/medium/large/admin_grid WebP variants), plus the full HTTP surface for upload and attach/list/reorder/detach on both Product and Variation media. | [media-domain-design.md](packages/EasyCo/documents/media-domain-design.md) |
| [`packages/EasyCo/Promotions`](packages/EasyCo/Promotions) | Customer-entered discount codes (percentage or fixed-amount) with extensible eligibility scoping (brand/category/tag/attribute/product/account, include or exclude), validity windows and usage limits. `PromotionRedemption` records which order consumed a code, enforcing the total/per-customer limits. Fully wired end to end: a customer can apply a code to a cart and have it honoured — or rejected with a reason — at checkout. | [promotions-domain-design.md](packages/EasyCo/documents/promotions-domain-design.md) |
| [`packages/EasyCo/Payment`](packages/EasyCo/Payment) | A payment attempt against an order, with two offline V1 adapters (cash on delivery, bank transfer) behind one contract a real online provider can later implement unchanged. Double-capture prevention is DB-enforced, not an application-level check. `PaymentRefund` is modelled as a new record referencing the original, never an in-place rewrite. | [payment-domain-design.md](packages/EasyCo/documents/payment-domain-design.md) |
| [`packages/EasyCo/Order`](packages/EasyCo/Order) | A thin order envelope wrapping `OperationalSales.Transaction` — it does **not** own line items; the Transaction/SaleLine ledger remains the record of what was sold. Order carries what fulfilling a web order needs: an immutable address snapshot, contact email, snapshotted totals, and status. | [checkout-domain-design.md](packages/EasyCo/documents/checkout-domain-design.md) |

## Checkout

Order placement runs as one transaction plus a deliberate second phase:

**Phase 1, inside a single DB transaction** — load the cart, re-price every line live, re-validate any applied promotion, resolve the Address and Client, decrement stock atomically, write the Transaction/SaleLines and the Order, write a `PENDING` Payment row, claim the cart, and record the promotion redemption under a row lock.

**Phase 2, after commit** — call the payment adapter, record its answer onto the Payment row already written, and fire the `order.placed` hook. A database transaction is never held open across a call to an external system, even though V1's adapters are offline: the shape has to be correct the day a real provider replaces them.

Idempotency is two-layered: a cheap pre-transaction check for the common double-submitted form, plus an atomic `carts.order_id` claim as the real race guard. A replayed checkout returns the original order and never charges twice.

Two decisions worth knowing before reading the code: an invalid promotion code **aborts** the order rather than being silently dropped, because a customer must never click "Pay" seeing one total and be charged another; and `Order.total` is exactly subtotal minus discount, with **no shipping component** — delivery destinations are modelled, delivery pricing isn't.

## Other documents

Beyond the per-package design docs above, `packages/EasyCo/documents/` holds the project's decision record: [ai-collaboration-protocol.md](packages/EasyCo/documents/ai-collaboration-protocol.md) (how changes are proposed, reviewed and verified), [channel-native-commerce-vision.md](packages/EasyCo/documents/channel-native-commerce-vision.md), [performance-and-channel-strategy.md](packages/EasyCo/documents/performance-and-channel-strategy.md), [production-requirements.md](packages/EasyCo/documents/production-requirements.md) (what a real server needs beyond local dev — required and recommended, host-agnostic), and a set of `*-note.md` files acting as the deferred-work queue.

**Site Settings** — a generic, admin-editable key-value store — lives in `app/Settings/` rather than under `packages/EasyCo/`, since it's app-level infrastructure rather than its own business domain. See [site-settings-design.md](packages/EasyCo/documents/site-settings-design.md). It's infrastructure only right now: nothing in the app actually reads or writes through it yet.

## Setup

MySQL/MariaDB is required. Create an empty database on your server first; `migrate` creates the tables, not the database itself.

```bash
composer install

cp .env.example .env
php artisan key:generate
# .env.example defaults to DB_CONNECTION=sqlite — change it to mysql and fill in
# DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD

php artisan migrate

php artisan storage:link
# required for uploaded media URLs to actually resolve — see
# ai-installation-assistant.md if this is skipped, an upload still
# looks like it succeeded but the returned URL 404s

# A queue worker is also needed for image variant processing to
# actually run (or set QUEUE_CONNECTION=sync in .env for local
# trying-out without one). Full platform-specific setup — NSSM on
# Windows, Supervisor on Linux — is in
# packages/EasyCo/documents/ai-installation-assistant.md.

npm install && npm run build
# compiles frontend assets — required for the default homepage to load (uses Vite)
```

## Production deployment

The steps above get EasyCo running for local development. Actually
serving real traffic needs more — a persistent queue worker as a
system service, Redis, and a full-page cache layer, none of which are
optional for anything beyond a first local try-out. None of this ties
EasyCo to a specific hosting provider — see
[production-requirements.md](packages/EasyCo/documents/production-requirements.md)
for the full, host-agnostic checklist.

## Tests

The Feature suite runs against a **real MySQL database**, not an in-memory one — deliberately, since much of what it verifies is database-level behaviour: foreign key delete rules, unique constraints, atomic conditional updates, and row locks. SQLite would silently not exercise any of it.

```bash
php artisan test
```

Point your test database at a throwaway schema before running it — `RefreshDatabase` will drop and recreate every table. Copy [`.env.testing.example`](.env.testing.example) to `.env.testing`, give it its own throwaway database name (e.g. `easyco_testing_sandbox`) and create that database once — the file's own comments explain why the name is per-worktree and why `php artisan config:clear` must run first (`composer test` does it).

The domain packages also carry their own pure PHPUnit suites (no database, no Laravel) under `packages/EasyCo/*/tests/`, runnable independently.
