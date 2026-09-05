# Checkout Domain Design

**Status:** Draft v1 — for discussion, not yet approved, not yet implemented.
**Builds on:** `checkout-prerequisites-note.md` (both closed gaps this document assumes: Address, Payment), `checkout-orchestration-performance-note.md` (the performance/reliability principles applied in §8), `cart-domain-design.md` §3/§13 (live pricing, and the explicit forward statement that Checkout is what snapshots a resolved price into `OperationalSales.Transaction`/`SaleLine.amount`), `promotions-domain-design.md` §5 (redemption counting waits for Checkout — this document is what makes it real), `payment-domain-design.md` §6 (`Payment.orderId` is the forward reference this document resolves), `address-domain-design.md` §5 (order-time snapshotting is explicitly this document's job), `account-domain-design.md` §11 (the Account↔Client link, explicitly deferred to this task), `operational-sales-domain-design.md` (`Transaction`/`SaleLine` — reused directly, not duplicated — see §2), `extensibility-design-and-hooks.md` (the `order.placed` action hook this design's own worked example already names).
**Origin:** Two real-world platforms checked directly before finalizing, per protocol:
- **WooCommerce's Checkout/Store API** creates a **draft order** from the cart the moment checkout begins, holding billing/shipping addresses; payment data is only persisted when the order is updated via a further request, right before payment processing. This confirms the "order exists before payment resolves" shape §4/§7 below already needed for cash-on-delivery/bank-transfer.
- **Medusa's `POST /store/carts/:id/complete`** ties cart completion to an `Idempotency-Key`, stored on the Cart entity itself, with a multi-step "recovery point" state machine (tax lines → payment authorized → inventory confirmed/reserved → complete) so an interrupted request can resume safely. This confirms that idempotency belongs on the **cart**, not bolted onto the request layer — but Medusa's full recovery-point machinery is more than V1 needs here: our two V1 payment adapters are synchronous and deterministic (no multi-step async authorization to resume into), so §6 below uses a single-transaction atomic claim instead of a resumable state machine. Flagged explicitly as a simplification, not an oversight — if a real async provider (Stripe) is added later, this may need revisiting toward something closer to Medusa's model.
- Medusa also reserves inventory during cart completion — this project's own `checkout-prerequisites-note.md` §3 already rejected reservations on purpose. Restated in §5 below: that decision stands.

---

## 1. Scope — two things, not one

This single document covers two genuinely different things, because they were designed together and depend on each other, but they are **not the same kind of artifact** and must not be built as if they were:

1. **A new domain: `EasyCo\Order`** — a real, persisted aggregate with its own id, the canonical `orderId` that `Payment` and (as of this document) `Promotions`' `PromotionRedemption` already forward-reference. This gets its own package, `packages/EasyCo/Order`, following exactly the same domain/persistence-first staging every other package in this project has followed.
2. **Checkout: an application-layer orchestration service, not a domain.** It has no aggregate of its own and owns no table. It coordinates Cart, Inventory, Promotions, Address, Payment, `OperationalSales`, and the new `Order` package — the same "reads/writes several domains' published contracts, lives in `app/`" shape `PromotionValidator`/`PromotionDiscountCalculator` already established, not a new architectural category. `checkout-orchestration-performance-note.md` §3 already called for exactly this: "a thin, dedicated orchestration layer... coordinates," explicitly not one domain calling into every other domain directly.

Everything in §3–§7 is the `Order` domain design proper. §8 is the Checkout orchestration design. Keeping them in one document is deliberate — they were decided as one conversation and reference each other constantly — but the distinction matters for where code actually lives.

---

## 2. Why `Order` is not just `OperationalSales.Transaction` renamed

The obvious shortcut — skip a new domain, add whatever Checkout needs directly onto `Transaction` — was considered and rejected.

`Transaction`/`SaleLine` already do, and continue to do, exactly one job: recording **the financial fact of what was sold** — channel, line items, amount, profit — shared identically between POS and Web. `operational-sales-domain-design.md` §1 states this as "the one rule that matters most in this whole document." A delivery address, a fulfillment lifecycle, a contact email, and a payment-provider linkage are not financial facts about a sale; they are facts about **fulfilling a web order to a customer**, a concept POS has no use for at all. Bolting them onto `Transaction` would blur a boundary this project has drawn carefully and repeatedly (Payment kept separate from Order for the same reason; Address kept separate from Account).

So: **`Order` is a thin envelope, `Transaction`/`SaleLine` remain the ledger.** `Order` does not duplicate line items — no `OrderLine` table exists in this design. `Order.transactionId` points at the real `Transaction` that holds the actual `SaleLine`s, created together, 1:1, in the same operation (§8). Everything Payment and PromotionRedemption need to forward-reference (`orderId`) points at `Order.id`, not `Transaction.id` — a POS sale has no reason to ever need a `Payment` row or a promotion redemption in this shape, so keeping those forward references pointed at the web-order-specific envelope, not the shared ledger aggregate, keeps `OperationalSales` exactly as narrow toward Payment/Promotions as it already is toward everything else.

---

## 3. Core entity: `Order`

```
Order                                          (aggregate root, package EasyCo\Order)
├── id
├── clientId              OperationalSales.Client id — cross-domain by id (§4)
├── accountId             nullable — denormalized copy of the same Client's accountId,
│                         purely for a direct "my orders" query without a join through
│                         Client; same nullable-FK posture Address.accountId already
│                         takes. Null for a guest order.
├── transactionId         OperationalSales.Transaction id — cross-domain by id; the
│                         ledger this envelope wraps (§2)
├── email                 string, required — snapshot, taken from Account.email for a
│                         logged-in checkout or from a new checkout-form input for a
│                         guest (§8.2 — a real gap this document closes, see there).
│                         Never a live reference: Account.email may change after the
│                         order is placed, and a guest has no Account at all.
├── currency               3-letter code, matching Money's own convention
├── subtotalMinor / discountMinor / totalMinor   bigint minor units — snapshotted once,
│                         at placement, never recomputed; mirrors SaleLine.amount's own
│                         "historical fact" posture. Derivable in principle by summing
│                         SaleLines, but stored directly for the same reason WooCommerce
│                         stores order-level discount_total/cart_tax alongside line
│                         items: fast, direct read for confirmation/receipt/admin-list
│                         display without re-summing a ledger every time.
├── appliedPromotionCode   nullable string — display/audit snapshot of the code used.
│                         The actual usage-tracking fact of record is Promotions'
│                         PromotionRedemption (§7), not this field.
├── status                 OrderStatus: PLACED | FULFILLED | CANCELLED — see below
├── placedAt               datetime
│
│ Address snapshot — embedded, immutable copy, NOT a live reference. Same shape
│ as Address's own entity (address-domain-design.md §2), duplicated deliberately —
│ see below.
├── addressId              nullable — back-reference to the source Address row (set
│                          when a logged-in customer used a saved address; null for a
│                          typed-fresh guest/one-off address). Purely informational —
│                          never re-read to render the order; the embedded fields below
│                          are the only fields the order display ever uses.
├── deliveryType           STREET_ADDRESS | PICKUP_POINT
├── recipientName, phone
├── country, city, postalCode, addressLine1, addressLine2    (nullable; STREET_ADDRESS only)
└── carrierCode, pickupPointReference, settlement            (nullable; PICKUP_POINT only)
```

**Why the Address fields are duplicated onto `Order` rather than `Order` just holding `addressId`:** this is the exact `priceableId` + `amount` pattern `SaleLine` already established — keep a reference for traceability, but also freeze the fact itself, because the referenced row can change (a saved account address gets `update()`d) or, for a guest order, might reasonably be prunable/deletable later with no loss to the order. `address-domain-design.md` §5 named this precisely as "a future Order taking an immutable copy of one at checkout time is that future Checkout/Order domain's job" — this is that job.

**`status` is in V1 after all — domain-owner decision, reversing this document's own first draft.** The reasoning for cutting it (nothing consumes it yet) still stands as a fact, but the field itself is judged indispensable to a real, working store regardless — better to have the column exist and sit mostly-`PLACED` for a while than to retrofit it onto a live `orders` table later, the same "costs nothing now, costs real migration work later" reasoning `account-domain-design.md` §5 already used to justify `softDeletes()` on `accounts` before anything needed it either.

**Deliberately minimal — three values, "от хиляди опции — само три" applied literally:**
- `PLACED` — the order's entire life today; every order is created in this state, and nothing in this document transitions it out of it.
- `FULFILLED` — the merchant has prepared/handed over the order. No trigger exists yet — no Shipping domain, no admin action to set it.
- `CANCELLED` — the order was called off. No trigger exists yet either — no compensating logic (stock restock, payment refund) is designed here.

**What this document does NOT build:** any transition path between these three states, any admin endpoint to change `status`, and any side effect of `CANCELLED` (should cancelling restock the decremented `Inventory`? should it trigger a `PaymentRefund`? — genuine, real questions, deliberately not answered here). Domain-owner instruction: build the transition logic together with the future admin UI, as one coherent piece of work, rather than guessing at admin workflow needs in isolation now. Recorded as explicitly deferred in §10, not silently dropped.

---

## 4. Cross-domain contracts

- **`Order` → `OperationalSales`:** `clientId`/`transactionId` by plain id only. `Order` never depends on the `OperationalSales` package.
- **`Order` → `Account`:** `accountId` by plain id only, nullable, same convention as `Address.accountId`.
- **`Order` → `Address`:** `addressId` by plain id only, nullable, informational (§3). `Order` never depends on the `Address` package — the embedded snapshot fields are `Order`'s own columns, not a foreign read.
- **`Payment` → `Order`:** `Payment.orderId` (already shipped, forward-referencing) now resolves to a real `Order.id`. No change needed to the already-implemented `Payment` package.
- **`Promotions` → `Order`:** the new `PromotionRedemption.orderId` (§7) is the same kind of forward reference, by plain id.
- **`Order` never depends on Cart, Pricing, Inventory, Promotions, or Payment at the package level.** Checkout (the orchestrator, §8) is the only code that touches all of them together — the same discipline every other domain in this project keeps.

---

## 5. Inventory — reconfirmed, not silently changed

`checkout-prerequisites-note.md` §3 stands exactly as written: **no reservation mechanism.** Cart's soft, add-time-only check remains soft. Checkout's finalization step calls `EasyCo\Inventory\Contracts\StockLevelRepository::decrease()` once per line — already atomic (a single conditional `UPDATE ... WHERE quantity >= ?`), already race-safe, already tested, with **no caller until now** (`inventory-domain-design.md` §11 named Checkout as exactly the future caller this method was built for). If stock ran out between add-to-cart and this moment, `decrease()` throws `InsufficientStockException`, and per §6/§8 below that aborts the whole checkout transaction cleanly — a clean, explicit failure at checkout, precisely the behavior that note already specified.

---

## 6. Idempotency — a claim on the Cart, DB-enforced, not a header-based recovery machine

**New column: `carts.order_id`** — nullable, unique, `nullOnDelete()` toward `orders.id` (if an `Order` is ever deleted — no path for that exists in V1 — the cart simply loses its link; a cart is disposable working state with no historical value of its own, per `cart-domain-design.md` §10's own reasoning for its other FK choices on this same table).

**The claim, inside the single checkout transaction (§8):**

```php
// Order is inserted first, inside the transaction, so a real id exists to claim with.
$affected = CartModel::where('id', $cartId)
    ->whereNull('order_id')
    ->update(['order_id' => $order->id()]);

if ($affected === 0) {
    // Either a concurrent request already claimed this cart, or this is
    // a legitimate retry of an already-completed checkout (a double-
    // clicked "pay" button). Roll back everything just inserted in
    // this attempt and return the ALREADY-EXISTING order instead of
    // creating a second one.
    DB::rollBack();
    return OrderRepository::findByCartId($cartId); // idempotent response
}
```

This is the same "atomic conditional UPDATE, zero-affected-rows means someone else already acted" shape `Inventory::decrease()` already uses (§5) and the same shape `EloquentCartRepository`'s own SQLSTATE-1062 self-healing already uses (`cart-domain-design.md` §10) — a third application of one pattern this project keeps reaching for, not a new one invented here.

**Why not Medusa's full idempotency-key/recovery-point machine (§Origin):** that machine exists to let a *multi-step, potentially-async* completion process resume from wherever it was interrupted (tax calculation, then payment authorization, which might itself require redirect/webhook round-trips, then inventory). V1's two payment adapters are synchronous and always resolve immediately (`PENDING`, never a redirect or async callback) — there is nothing to "resume into" mid-flow. A single all-or-nothing transaction with one claim-or-return-existing check is the simplest mechanism that's actually correct for this shape, matching the project's own "boutique, not feature-for-feature" philosophy (`ai-collaboration-protocol.md`). **Flagged for revisiting** if/when a real async provider is added.

---

## 7. `PromotionRedemption` — a small addition to the existing `Promotions` package, not a new domain

```
PromotionRedemption                            (package EasyCo\Promotions — new entity)
├── id
├── promotionId
├── orderId          plain string — forward reference, same posture as Payment.orderId
├── accountId        nullable — null for a guest redemption
└── redeemedAt
```

Written exactly once, inside the same checkout transaction, only if a promotion code was actually applied and survived live revalidation (§8 step 3) — this is the moment `promotions-domain-design.md` §5 named as the only correct one: "a permanent, historical fact — order placement."

**Enforcing `usage_limit_total`/`usage_limit_per_customer` at write time — the same honest posture `payment-domain-design.md` §5.2 already took toward its own SUM-aggregate invariant.** MySQL cannot `CHECK` an aggregate `COUNT()` across rows any more than it can `SUM()` one (§5.2's own reasoning applies identically to a count). The write path locks the `Promotion` row (`SELECT ... FOR UPDATE`) inside the transaction, counts existing `PromotionRedemption`s against `usage_limit_total` and (if an `accountId` is present) against `usage_limit_per_customer`, and only inserts the new redemption if both still hold — otherwise the whole checkout transaction aborts with a clear, customer-facing "this code is no longer valid" rejection, same shape as any other checkout-time validation failure. This is a weaker guarantee than a true DB constraint (depends on every future caller using the transaction correctly) — stated plainly, not overclaimed, exactly matching the precedent this borrows from.

A guest redemption (`accountId: null`) never counts toward `usage_limit_per_customer` — there is no reliable per-guest identity to count against, the identical reasoning `new_customers_only` already uses for guests (`promotions-domain-design.md` §2).

---

## 8. The Checkout orchestration flow

Lives in `app/Services/CheckoutOrchestrator.php` (or similarly named — final naming is a coder-prompt detail, not a design decision), consumed by a future `CheckoutController` (its own, later implementation prompt, per protocol — domain/persistence first, HTTP after). One public entry point, conceptually `place(cartId, ...checkout input)`.

### 8.1 The Account↔Client link — resolved, per `account-domain-design.md` §11

**New column: `operational_sales_clients.account_id`** — nullable, unique when set, plus a new `ClientRepository::findByAccountId(string $accountId): ?Client` method. `Client` already has everything else needed: `changeName()` already exists (not a new method) for the case where a returning customer's `recipientName` differs from what's on file — a decision left open below.

- **Logged-in checkout:** look up `Client::findByAccountId($accountId)`. If none exists yet (first-ever checkout for this account), create one — `Client`'s required `name` is populated from this checkout's `recipientName` (§8.2), since `Account` itself deliberately stores no name (`account-domain-design.md`: "Email + password, nothing else"). **Open question for Емо:** on a *repeat* checkout with a different `recipientName` than what's on file, should `Client::changeName()` be called to keep it current, or should the first-ever name stick permanently? Either is a one-line decision once made; recorded here as genuinely open rather than silently picking one.
- **Guest checkout:** always create a fresh `Client` (`accountId: null`, `name` from `recipientName`) — no attempt at cross-order guest deduplication (e.g. by email) in V1. Flagged as deferred, not forgotten.

### 8.2 The email gap — a real, newly-discovered gap, resolved here

Nothing in this project currently captures a contact email for a guest. `Address` has `recipientName`/`phone`, not email. `Account` has `email`, but only for logged-in customers. Since `Order.email` (§3) is required, Checkout's request input must include an `email` field for a guest checkout; for a logged-in checkout it is taken from `Account.email` directly (no override in V1 — keeping this simple per "от хиляди опции — само три"). This needs to be explicit in whatever HTTP-layer request validation the later Checkout HTTP prompt writes.

### 8.3 The step sequence

**Phase 1 — one database transaction** (Laravel's `DB::transaction()`; nested calls into e.g. `TransactionRepository::save()`'s own internal transaction become savepoints automatically, confirmed supported by Laravel — worth the coder re-confirming this behavior with a real test rather than assuming, per protocol):

1. Load the real `Cart` with its lines. Reject an empty cart outright.
2. Live-revalidate the applied promotion code exactly as `GET /api/cart` already does (`PromotionValidator`) — get the authoritative discount to snapshot, or none.
3. For every `CartLine`, resolve the live price (`PriceResolver::resolve()`) — same call Cart already makes. A `PriceNotConfiguredException` here aborts the whole transaction with a clean rejection naming the variation; nothing partial is ever committed.
4. Compute `subtotalMinor`/`discountMinor`/`totalMinor` (`PromotionDiscountCalculator`, already built).
5. Resolve or create the `Address` row (§8.4) and hold its fields for the snapshot.
6. Resolve or create the `Client` (§8.1).
7. For every `CartLine`, call `StockLevelRepository::decrease()` (§5) — atomic, and any `InsufficientStockException` aborts the whole transaction.
8. Build a new `Transaction` (`channel: WEB`) and one `SaleLine` per line (`type: SALE`, `status: COMPLETED` — see §8.5 for why `COMPLETED` regardless of payment method — `profit`: computed per §9.3), `TransactionRepository::save()`.
9. Insert the new `Order` row (§3), referencing the just-created `transactionId`/`clientId`, with the Address snapshot from step 5.
10. Claim the cart via the atomic `order_id` update (§6). Zero-affected-rows here rolls back everything above and returns the pre-existing order instead.
11. If a promotion was applied (step 2/4), lock the `Promotion` row and write the `PromotionRedemption` (§7) — a failed usage-limit re-check here also aborts the whole transaction.
12. Commit.

**Phase 2 — outside the transaction, after commit** (per `checkout-orchestration-performance-note.md` §2: never hold a DB transaction open across a call to an external system, even one of V1's own deterministic adapters, because this is the shape that must still be correct once a real Stripe adapter replaces them):

13. Call `PaymentMethodAdapter::charge()` for the chosen method. Create the `Payment` row (`orderId` = the committed `Order.id`) from whatever `PaymentAttemptResult` comes back. Both V1 adapters always return `PENDING` (§4 of `payment-domain-design.md`) — the order exists and stock is already committed regardless of this outcome; a real online adapter's immediate-failure case (and whether that should compensate/release stock) is explicitly **not** designed here — flagged as future work for whenever a real provider is added, not a gap this document silently papers over.
14. `Hook::fire('order.placed', $order)` — the exact action-hook example `extensibility-design-and-hooks.md` §1 already uses to illustrate the whole mechanism. Fired from this app-layer orchestrator only, never from inside `Order`/`Checkout` domain code, per that document's own architectural boundary (§2 there). No listener is registered in this task — same "purely the extension point" posture already used for `account.registered` — but this is the wiring point a future confirmation-email listener attaches to, queued, never blocking this response (§2 of the performance note).

### 8.4 Resolving the Address

- **Logged-in customer, existing saved address chosen:** load it, copy its fields onto the `Order` snapshot, set `Order.addressId`.
- **Logged-in customer, new address typed and (optionally) saved:** `AddressRepository::save()` with the real `accountId`, then snapshot as above.
- **Guest checkout:** always construct and save a fresh `Address` row with `accountId: null` — one consistent path for both cases rather than a special-cased "guest addresses aren't really persisted" branch, mirroring `address-domain-design.md` §1's own "simplest structure that satisfies both cases" reasoning for the domain itself.

### 8.5 `SaleLine.status` is `COMPLETED` at placement, regardless of payment method — a decision, not a default

The goods are genuinely committed (stock decremented) the moment the order is placed, for every payment method including cash-on-delivery and bank transfer. Whether the *money* has actually arrived is `Payment.status`'s job (`PENDING` for both V1 adapters), tracked entirely separately. Modeling a parallel "pending sale" state on `SaleLine` itself would duplicate a fact `Payment` already owns precisely, cleanly, and DB-enforced (`payment-domain-design.md` §5.1).

**Three different statuses, three different jobs, deliberately not merged:** `Payment.status` (has the money arrived?), `SaleLine.status` (is this specific line's financial fact settled — always `COMPLETED` here, per above), and the new `Order.status` (§3 — has the *merchant* fulfilled the order?). None of the three is derivable from another; that's exactly why all three exist rather than picking one and overloading it.

---

## 9. `ProductCost` — built for real, not stubbed, per explicit domain-owner decision

**Superseded from an earlier draft of this document.** The first pass of this section proposed a `Money::zero()` placeholder and deferring the real thing. Домейн owner's decision, explicit: cost price is real, load-bearing data — margin, inflation-over-time comparison, real reporting — and must exist as a genuine, editable (if optional) fact from the start, not a stub. This section now designs the real thing.

### 9.1 Storage — exactly the shape already sketched, now actually built

`pricing-domain-design.md` §2.4 already sketched this, months ago, and it turns out to need no change:

```
ProductCost                                    (package EasyCo\Pricing — new entity, internal)
├── priceableId       cross-domain by id, same targeting convention as PriceListItem
├── currency
└── amountMinor
```

**No row = no cost recorded — this, not a nullable column on an always-present row, is what makes cost genuinely optional**, exactly mirroring how `StockLevel`'s absence means "zero" (`inventory-domain-design.md` §5) — except here absence means "unknown," a real, different semantic, not zero. A merchant who never opens the cost field for a given product simply has no `ProductCost` row for it, and that is a fully valid, unremarkable state, not an error.

**The one real amendment to the original sketch:** `pricing-domain-design.md` §4.2's contract, `CostPriceProvider::costFor(string $priceableId, string $currency): Money`, returns a non-nullable `Money`. That was written before "no row = optional" was worked through as an explicit requirement. Amending it now, since nothing has ever consumed the old signature (it was never implemented):

```php
interface CostPriceProvider
{
    public function costFor(string $priceableId, string $currency): ?Money;  // null = no cost recorded
}
```

This is a design amendment, recorded here rather than silently changed — the same discipline `cart-domain-design.md` §3 used when it corrected `pricing-domain-design.md` §7's earlier, wrong-for-Cart snapshot guidance.

### 9.2 Admin-only, by construction — and why the HTTP surface can't ship yet

`pricing-domain-design.md` §2.4 was already explicit that cost data "must never be reachable through any customer-facing endpoint" — `CostPriceProvider` is never bound for the storefront package, exactly like today. That much is already correctly designed and just needs implementing.

**The real problem is `admin-auth-gap-note.md`, restated here with actual teeth this time.** Every merchant-facing endpoint in this project is unprotected today — confirmed directly, not assumed: `PUT /api/variations/{id}/stock` (the closest existing precedent for what a `PUT /api/variations/{id}/cost` endpoint would look like) has no auth middleware whatsoever, and neither does anything else under `routes/api.php`. Shipping a cost-price HTTP endpoint today, "admin-only" in name, would in fact be reachable by anyone — the exact opposite of what was asked for. This isn't a future-launch concern anymore the way `admin-auth-gap-note.md` originally filed it; a real sensitive field now depends on it directly.

**Decided: (a).** `ProductCost` domain + persistence is built now, as part of this same prerequisite work; its HTTP surface waits.

**The guard itself, when it's built, is not a simple second binary guard — domain-owner requirement, recorded here for whoever picks that task up.** Not "customer vs. admin," but real roles: full-access, and at least one narrower role (an operator who may only enter/edit products, not touch cost prices, promotions, or anything else sensitive). This is a materially bigger task than the single `customer` Sanctum guard `account-domain-design.md` built — a role/permission layer, not just a second session guard — and deserves its own design conversation (and likely its own `*-domain-design.md`) when it's actually picked up, not a same-shape copy of Account's guard. Not designed here; flagged with this shape so it isn't underestimated later.

Either way, `ProductCost`'s **domain layer** (the entity, the repository, the migration) is real, useful, and buildable now, independent of this decision — Checkout's own need for `costFor()` (§9.3) only needs the domain layer, not the HTTP surface.

### 9.3 How Checkout actually uses it

`SaleLine.profit` remains a required, non-nullable `Money` on the already-shipped `SaleLine` entity — that doesn't change. Checkout's orchestrator (§8.3 step 8) computes, per line:

```
profit = amount - (CostPriceProvider::costFor($variationId, $currency) ?? Money::zero($currency))
```

**This is a known, visible distortion for any priceable with no recorded cost, not a hidden one:** such a line shows 100%-margin profit until the merchant fills in its cost — the same "visible enough to prompt a fix, not silently wrong forever" posture, rather than either blocking checkout entirely (a missing cost price should never stop a sale) or lying invisibly. Genuine per-line cost-unknown tracking (e.g. a flag distinguishing "verified zero margin" from "cost never set") would require changing `SaleLine` itself — out of scope here, and not something to slip into this prompt; flagged, not built.

---

## 10. Explicitly out of scope for V1 (deferred, not forgotten)

- **Any real online payment provider adapter** — already deferred by `payment-domain-design.md` itself; unaffected by this document.
- **Order status transitions and their side effects** — §3's `PLACED`/`FULFILLED`/`CANCELLED` column exists in V1, but no endpoint changes it and no side effect (restock on cancel, refund trigger, etc.) is built. Domain-owner instruction: build this together with the future admin UI, as one piece, not guessed at in isolation now.
- **The admin/staff auth guard's actual design** — §9.2 confirms it needs real roles (full-access, product-entry-only operator, ...), not a binary second guard; deserves its own design pass when picked up, not designed here.
- **Shipping/Tax integration** — no such domains exist yet; `checkout-orchestration-performance-note.md`'s external-API-timeout/fallback principle (§2 there) has nothing to apply to yet in V1, but the thin-orchestrator shape this document builds is what that future integration will slot into.
- **Confirmation email itself** — the `order.placed` hook point exists (§8.3 step 14); no listener is registered in this task, matching this project's own "purely the extension point" precedent.
- **Any Checkout/Order HTTP surface** — this document is domain design; HTTP is its own later, separate implementation prompt, per protocol.
- **`ProductCost`'s HTTP surface specifically** — §9.2's decided path (a); the domain+persistence layer is not deferred, only its HTTP exposure.
- **Guest cross-order deduplication** (matching a returning guest by email across separate orders) — §8.1, explicitly not attempted in V1.
- **Guest order lookup/tracking** ("check my order status" by order number + email, the way WooCommerce supports) — not designed here; `Order.email` (§3) is captured but no lookup endpoint exists yet.
- **A real online-provider immediate-failure compensation path** (releasing already-decremented stock if a real payment provider fails synchronously) — §8.3 step 13, explicitly flagged rather than assumed away.
- **`admin-auth-gap-note.md`'s existing gap** — restated for visibility per that note's own instruction, not new to this document: no merchant-facing endpoint is protected yet, Checkout's future HTTP surface included. Not blocking for this design, must be closed before any real deployment.

---

## 11. Testing plan

- `Order` domain unit tests: construction/validation mirroring `Address`'s own STREET_ADDRESS/PICKUP_POINT exclusivity rules on the embedded snapshot fields; required fields (`email`, `clientId`, `transactionId`) rejected when missing/empty.
- Repository Feature tests (real MySQL): save/round-trip; a real `SHOW CREATE TABLE` confirmation of `carts.order_id`'s unique index (§6); a real concurrent-request test proving the atomic claim behaves as designed (one request creates the order, a racing second request against the same cart gets the same order back, never a second one).
- `ClientRepository::findByAccountId()` tests: returns the right `Client`, returns `null` for an account with no orders yet, a real DB-level uniqueness violation test for the new `account_id` column.
- `PromotionRedemption` tests: a redemption within limits succeeds and increments the effective count; one that would exceed `usage_limit_total`/`usage_limit_per_customer` is rejected and the whole checkout transaction rolls back cleanly (no partial `Order`/`Transaction`/stock-decrement survives); a guest redemption never counts toward `usage_limit_per_customer`.
- Full Checkout orchestration Feature tests: a real end-to-end guest checkout and a real end-to-end logged-in checkout, each asserting: stock genuinely decremented (re-read from DB, not from the in-memory result), a real `Transaction`+`SaleLine`s exist with the right snapshotted amounts, a real `Order` exists with the right Address snapshot, a real `Payment` exists in `PENDING` with the right `orderId`, and — critically — a double-submitted request (same cart, sent twice, simulating a double-clicked "pay" button) produces exactly one `Order`, not two.
- `ProductCost`/`CostPriceProvider` tests: `costFor()` returns `null` for a priceable with no recorded row (not zero, not an exception); returns the right `Money` once one exists; a real Feature test confirming no route/binding path connects it to any storefront-facing service (mirroring `pricing-domain-design.md`'s own testing-plan language for this exact concern); Checkout's own `profit = amount - (cost ?? zero)` computation covered for both the cost-known and cost-unknown cases.
- Insufficient-stock-at-finalization test: a cart whose stock was sufficient at add-time but was sold out by another concurrent transaction before this checkout's own `decrease()` call gets a clean rejection, and — confirmed directly against the DB, not assumed — no `Order`, `Transaction`, `SaleLine`, or stock decrement survives from the failed attempt.
