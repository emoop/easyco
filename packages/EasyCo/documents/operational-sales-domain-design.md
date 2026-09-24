# Operational Sales Domain Design

**Status:** v1.2 — domain layer implemented (`Client`, `Transaction`, `SaleLine`, `InstallmentPlan`); persistence layer implemented (migrations, Eloquent repositories for Client/Transaction/InstallmentPlan — see be107f5), verified against a real MySQL database (SHOW CREATE TABLE, rollback/re-migrate round trip). 61 tests passing (`packages/EasyCo/OperationalSales`).
**Builds on:** `catalog-domain-design.md` (Product/Variation identity, `priceableId()`), `pricing-domain-design.md` (Money value object, reused here directly to eliminate a real float bug found in the source system; also `DefaultCurrency`, added during this implementation — see §3.11).
**Origin:** derived from a line-by-line review of an existing, years-in-production WooCommerce POS plugin (internal codename `raf_pos`) — used as a **functional reference only, not architecture to copy**, same posture this project already takes toward WooCommerce/Bagisto/Aimeos elsewhere. Two real bugs found in that review directly shaped two of this domain's core decisions (§3.3, §3.5).

**Implementation update (v1.0 → v1.1):** the domain layer described in §2/§3 below has now been built exactly as designed, across four focused implementation passes (one per aggregate) plus one corrective fix. §3.9–§3.11 document three things the original design left unspecified and that implementation had to resolve: `SaleLine`'s narrow `transactionId` backfill (§3.9), `InstallmentPlan`'s actual exception/settlement behavior (§3.10), and the `EasyCo\Pricing\DefaultCurrency` mechanism added to give `InstallmentPlan` a safe, non-hardcoded currency fallback for a zero-line plan (§3.11). See §5 for what's still deferred (persistence, migrations) and §6 for the updated next-steps state.

---

## 1. Scope & the Pricing boundary

This domain owns **what actually happened** in a sale, reservation, refund, or installment payment — a historical, factual record. It does **not** own "what something costs right now" — that remains Pricing's exclusive responsibility, referenced here only by `priceableId` (the existing Catalog↔Pricing contract, unchanged).

**The one rule that matters most in this whole document:** Operational Sales never writes back into Catalog or Pricing as a side effect of a sales operation. The legacy system's refund flow mutated a product's `sale_price` directly from inside the refund handler — convenient, but it meant a refund action silently changed what every other customer sees as the current price. This domain captures the price **as a fact at the moment of the transaction**, and never reaches backward into Pricing to "correct" or "refresh" anything.

---

## 2. The core model

```
Client
├── id
└── name (free text — no imposed script, case, or format; that's an
          operational/UI convention, not a domain rule)

Transaction
├── id
├── channel: POS | WEB
└── SaleLine[]

SaleLine                                    (immutable once recorded)
├── id, transaction_id, client_id
├── priceableId                              nullable — null for SHIPPING /
│                                             INSTALLMENT_PAYMENT pseudo-lines
├── type:    SALE | RESERVATION | REFUND | SHIPPING | INSTALLMENT_PAYMENT
├── status:  PENDING | COMPLETED | CANCELLED
├── quantity
├── amount:  Money                           historical fact, never recomputed
├── profit:  Money                           stock-cost snapshot at the time
├── recorded_at                              when this row was written
├── effective_at                             when the event actually happened
│                                             (e.g. the original reservation
│                                             date — see §3.6)
├── originating_sale_line_id                 nullable — set on a REFUND line,
│                                             points at the SaleLine it refunds
├── originating_reservation_line_id          nullable — set when a
│                                             reservation is paid off, points
│                                             at the RESERVATION line it settles
│
│ §3.13 — DESIGNED, NOT YET IMPLEMENTED. Required for a freshly-created SALE
│ line (via the new SaleLine::create(), §3.12/§3.13); NULL on any row written
│ before this shipped (reconstituteFromStorage() keeps accepting NULL, §3.13
│ E-D5) — never backfilled.
├── regularUnitPrice: ?Money                 level 2 (§3.13 E-D1), regular
├── finalUnitPrice:   ?Money                 level 2 (§3.13 E-D1), final —
│                                             replaces dividing amount/quantity
├── promotionDiscountShare: ?Money           this line's share of the order's
│                                             promotion-code discount (§3.13)
├── discretionaryDiscount:  ?Money           register-applied courtesy
│                                             discount, POS only, always zero
│                                             on a WEB line (§3.13 E-D3)
├── netPaidAmount: ?Money                    level 3 (§3.13 E-D1) — actually
│                                             paid; what a return refunds
├── soldAttributes: ?array                   ordered {definitionCode,
│                                             definitionName, valueId, value}
│                                             list, [] for a SIMPLE line
└── unitCost: ?Money                         cost snapshot at sale time; NULL
                                              = genuinely unknown (§3.13 Q2)

InstallmentPlan
├── id, client_id
├── status: ACTIVE | COMPLETED | CANCELLED
├── reserved_lines: SaleLine[]                real references, not text matching
├── payment_lines:  SaleLine[]                (type=INSTALLMENT_PAYMENT)
└── outstandingBalance(): Money               reserved total minus payments total,
                                               computed from real Money values
```

---

## 3. Key design decisions

### 3.1 Money, not float, for every amount

Every `amount`/`profit`/`outstandingBalance()` value is the existing `EasyCo\Pricing\Money` value object (integer minor units), not a PHP float. This directly closes a real bug found in the source system: `partial_payment()` there determined whether an installment plan was fully settled via `round($total_debt - $sum, 2) == 0` — an exact floating-point equality check. A payment off by a single stotinka (a realistic cash-rounding scenario) would silently leave the plan open forever, with its reserved items never flipping to a sold state despite being, for all practical purposes, paid off. Integer minor units make this class of bug structurally impossible, the same reasoning already applied to every price in the Pricing domain.

### 3.2 `SaleLine` is immutable — no in-place status rewriting

The source system's `refunded` status exists purely to mark "this is the old, superseded record" when a reservation return creates a new `ref_res` row — i.e. it retroactively rewrites history to keep the picture consistent. This project already has a hard rule against exactly this pattern (`catalog-domain-design.md` — historical Variation identity is never destroyed or reassigned). The same rule applies here: a `SaleLine`, once recorded, is **never** updated in place. A refund, a settled reservation, a cancelled reservation — each is a **new** `SaleLine`, referencing the original by id (`originating_sale_line_id` / `originating_reservation_line_id`). The full event history is always reconstructable; nothing is ever silently rewritten. The `refunded` status is dropped entirely — it becomes unnecessary once history is append-only.

### 3.3 `InstallmentPlan` is an explicit aggregate, not a string marker

The source system groups a client's reserved items and their partial payments together using a randomly-generated string (`{transaction}_:PAY:_{time}`) written into a free-text comment column, then found again later via a `LIKE`-style match. This review found a real, reproducible bug caused directly by that design: a client's *first* reserved items get the marker; if the client later adds a **new** reserved item while a plan is already active, the code that assigns the marker only runs when *no* marker exists yet at all for that client — so the new item is silently left un-marked. When the plan is later paid off in full, the settlement query filters strictly by marker, and the new item — never marked — is skipped. It stays reserved forever, even though the client has, in every practical sense, paid for it.

`InstallmentPlan` here is a real aggregate with a real id. Adding an item to an active plan is `$plan->attachReservedLine($newLine)` — a direct reference, not a hope that two independently-generated strings happen to match. This bug class is not mitigated; it's structurally impossible.

### 3.4 Refund provenance is explicit, and never mutates Catalog/Pricing — REVISED per §3.13 E-D2, still not implemented

**This section originally proposed `regularPriceAtReturn`/`salePriceAtReturn`/`actualRefundAmount` — never implemented in code (confirmed directly against `SaleLine.php`: no such fields exist), and now superseded by §3.13's fuller design.** The original proposal mirrored the source system's own refund fallback (`refund_price` → `sale_price` → `regular_price`) — a **live re-read of what the item is worth at the moment of the return**. That is exactly the mistake §1's "a sale is a frozen fact, never re-derived" rule (and §3.13 E-D1's three-level model) exists to rule out: the *display* price on the return date has nothing to do with what the customer actually paid, and must never drive a refund amount.

**Corrected design (§3.13 E-D2), not yet implemented:** a `REFUND` line does not duplicate the original SALE line's prices at all — it resolves `productName`/`sku`/`soldAttributes`/`regularUnitPrice`/`finalUnitPrice` through `originatingSaleLineId`, the same link-not-duplicate pattern §3.12 already established for `productName`/`sku`. A `REFUND` line records, on itself:

- **`quantityReturned`** — how many units of the original line this refund covers (§3.13 item 4 — a line can be returned across more than one `REFUND` event; "how many already refunded" is a query over prior `REFUND` lines sharing the same `originatingSaleLineId`, not a stored running counter, matching this document's append-only-history posture, §3.2).
- **`defaultRefundAmount`** — computed from the ORIGINAL line's `netPaidAmount` via §3.13 item 4's deterministic cumulative-share rule. Informational/audit — shows what the system would have refunded before any operator override.
- **`actualRefundAmount`** — what was actually handed back. Defaults to `defaultRefundAmount`; the register operator (holding `Permission::REFUND_CASH` or `Permission::REFUND_BANK`, per `staff-access-domain-design.md`) may consciously set a different value — a goodwill over- or under-payment — and that override is what's recorded as fact, never silently reconciled back to the default.
- **`displayPriceAtReturn`** — nullable, informational only, a live `PriceResolver` read taken at the moment of the return. Never used in the `actualRefundAmount` computation — kept purely so the operator (and a later report) can see "this item is now selling at X" alongside what was actually refunded, the one legitimate use for a live price read this section has.

Per §1, none of this ever writes back into `Variation`'s or `Price`'s own fields — the source system's refund handler did exactly that as a side effect, which this design still deliberately does not carry forward.

### 3.5 `recorded_at` vs. `effective_at`

The source system stores "today's date" in its main sales table but preserves the original reservation date in a separate metadata table — a real, useful distinction, but implicit and dependent on which of two tables you happen to query. Here it's one explicit pair of fields on every `SaleLine`: `recorded_at` (when the row was written) and `effective_at` (when the event actually happened — e.g. the original reservation date for a line that's later settled). Both are always present, on every line, with no cross-table lookup required to reconstruct either.

### 3.6 A refund counts in the daily POS total — confirmed explicitly

`type=REFUND` lines with `channel=POS` are included (as a negative) in the default daily POS report — not excluded. A day's real net revenue includes what came back, not just what went out; this matches the source system's own `refund` status being explicitly "видим в дневния оборот" per its own legend, and was confirmed directly by the domain owner during design.

### 3.7 `Client.name` carries no format rule

An earlier draft of this design proposed requiring lowercase Cyrillic client names, based on an operational habit the domain owner described for reducing cashier data-entry mismatches. That was correctly rejected during review: it's a legitimate operational convention for a specific store, not a domain invariant every future EasyCo merchant should be forced into. `Client.name` is a free-text string; any input discipline (script, case, a dropdown sourced from previously-seen clients) is a UI/operational choice, layered on top via the existing Hook/Extensibility mechanism if a merchant wants to enforce one — not baked into the domain.

### 3.8 Reporting is a query layer, not a domain

The source system's online-order tracking and delivery-reconciliation screens (`web-work.php`, `web-statistic.php`) turned out, on inspection, to be pure read-side queries over the same sales table, filtered by `channel` and `type` and grouped by `transaction`/order number — nothing that needed its own domain concept. The same holds here: "compare POS-register total vs. system total," "show combined POS+WEB revenue," "reconcile courier shipping costs for accounting" are all just differently-filtered queries over `Transaction`/`SaleLine`, not separate aggregates. `SHIPPING` lines are excluded from the default daily POS report (they're a courier cost, not POS revenue) but included in WEB-channel accounting reports, exactly matching the source system's existing split.

### 3.9 `SaleLine.transactionId` backfill — not a §3.2 immutability violation

Not specified in the original design and resolved during implementation: `Transaction` does not construct its `SaleLine`s (unlike `Product`, which is the only way a `Variation` is ever created) — a `SaleLine` is built directly by the caller, which means it may not yet know its owning `Transaction`'s real id at construction time, exactly the same problem `Variation::assignProductId()` already solves for a `Variation` built before its parent `Product` had an id. `SaleLine` accepts the empty string as a `transactionId` placeholder at construction, and exposes one narrow method, `assignTransactionId(string $transactionId): void`, that moves it from that placeholder to a real id exactly once (`LogicException` on a second call, or on a line that already has a real `transactionId`) — `Transaction::assignId()` calls it automatically on every attached line still holding the placeholder, mirroring `Product::assignId()` back-filling `Variation::assignProductId()`.

This is deliberately **not** a violation of §3.2's immutability rule. `transactionId` is a structural/ownership reference — which `Transaction` this line currently belongs to — not a business fact like `amount`, `status`, or `type`. `Variation` already draws exactly this distinction: `attributeAssignments` is a business fact with no backfill or mutation path at all, while `productId` is a structural reference and gets a narrow, one-time `assignProductId()`. `transactionId` on `SaleLine` is the second kind, not the first — `assignTransactionId()` touches no other field and does not open the door to general mutation.

### 3.10 `InstallmentPlan` as implemented: exceptions, overpayment, and settlement

Four exception types guard `InstallmentPlan`'s two mutating operations (`attachReservedLine()`, `recordPayment()`) and `cancel()`, each covering a distinct failure reason:

| Exception | Guards against |
|---|---|
| `InstallmentPlanNotActiveException` | Any of `attachReservedLine()` / `recordPayment()` / `cancel()` called on a plan that is already `COMPLETED` or `CANCELLED`. `cancel()` deliberately raises this on a second call too — see below. |
| `ClientMismatchException` | A `SaleLine` belonging to a different client than the plan being attached (as a reserved line or a payment) — a plan tracks exactly one client's balance. |
| `CurrencyMismatchException` | A `SaleLine` denominated in a different currency than the plan's other lines — required for `outstandingBalance()`'s `Money` subtraction to be computable at all (`Money` itself refuses to subtract across currencies). |
| `OverpaymentException` | `recordPayment()` given an amount larger than the current `outstandingBalance()`. |

**Overpayment is rejected outright, by deliberate scope decision, not an oversight.** Handling an overpayment — refunding the difference, crediting it toward a future purchase, or something else — is a real business decision this design does not make, so `recordPayment()` refuses the operation entirely rather than silently accepting a payment and producing a wrong resulting balance. Whoever eventually designs that policy should do so as a conscious extension of `recordPayment()`, not by discovering this gap in production.

**Settlement, on exact payoff:** when a payment brings `outstandingBalance()` to exactly zero (`Money::isZero()` — see §3.1 for why this is exact where the source system's float check wasn't), the plan transitions to `COMPLETED` and `recordPayment()` returns one new `SaleLine` (not yet persisted — `id` and `transactionId` are placeholders, per §3.9) per reserved line on the plan, `type=SALE`, `status=SaleLineStatus::COMPLETED`, `originatingReservationLineId` set to the reserved line's id. Each settlement line's `effectiveAt` is copied from the **original reserved line's** `effectiveAt` — never "now" — directly implementing §3.5's `recorded_at`/`effective_at` distinction: `recordedAt` is genuinely "now" (when the settlement was written), while `effectiveAt` stays the original reservation date. As with `Product::attemptConvertToSimple()` building a fresh `Variation` for the caller to persist, `recordPayment()` only produces these lines — persisting them is the caller/repository's job, once a persistence layer exists (see §5).

**The direct fix for the source system's marker-string bug (§3.3), confirmed by implementation:** `attachReservedLine()` appends a real `SaleLine` object reference onto the plan's own array. A second reserved line attached to an already-active plan (after a partial payment has already been recorded) works identically to the first — there is no independently-regenerated string that has to happen to match a prior one, so the class of bug the source system had is structurally impossible here, not merely mitigated.

### 3.11 `EasyCo\Pricing\DefaultCurrency` — a fail-loud, non-hardcoded currency fallback

`InstallmentPlan::outstandingBalance()` needs *some* `Currency` to return a zero `Money` for a plan with no lines attached yet (immediately after `open()`, before any `attachReservedLine()`/`recordPayment()` call has established one from a real line — a plan's currency is otherwise entirely emergent from whichever line, reserved or payment, it sees first; see §3.10). The first implementation of this fallback hardcoded `Currency::BGN()`, reasoning from this domain's Bulgarian-POS origin (§3.1's own "stotinka" reference). That hardcode became **factually wrong**, not just provisional, when Bulgaria adopted the euro on 2026-01-01 (with BGN ceasing to be legal tender on 2026-02-01 after a one-month dual-circulation period) — and hardcoding a replacement currency (EUR, or any other single currency) would only move the identical problem to the next currency/country this project eventually needs.

The fix, `EasyCo\Pricing\DefaultCurrency`, belongs to **Pricing, not Operational Sales** — a project-wide default currency is a Pricing-owned concept any future domain might need, the same way `Money`/`Currency` themselves are Pricing-owned. It is a small, framework-agnostic static holder (`set()` / `get()` / `isConfigured()` / `reset()`), configured once by the host application (`PricingServiceProvider::boot()`, reading `config('services.pricing.default_currency')`) and consumed here as `DefaultCurrency::get()`. `get()` throws a `LogicException` rather than silently guessing if nothing was ever configured — the same fail-loud posture as `OverpaymentException` elsewhere in this domain. See `pricing-domain-design.md` for the full writeup; `InstallmentPlan` is its first real consumer.

### 3.12 `SaleLine` gains a name/SKU snapshot — resolved

**The gap:** `SaleLine.amount`/`profit` are already real point-in-time snapshots ("historical fact, never recomputed" — §2's own language), but nothing on `SaleLine` captures *what* was actually sold in human-readable form — only `priceableId`, a plain reference. If the underlying Product/Variation's name is later changed, or archived, a historical report or reprinted invoice has no way to show what the customer actually bought.

**Decision:** `SaleLine` gains two new nullable fields — `productName` and `sku` — required only for `SaleLineType::SALE`, not mirroring `assertPriceableIdMatchesType()`'s full type list. Two types are deliberately excluded from the requirement, for different reasons, both stated here rather than left implicit:

- **REFUND** already carries `originatingSaleLineId`, linking back to the SALE line being refunded — once that line has its own productName/sku snapshot, a REFUND line resolves the same information through that existing link rather than duplicating it. No new field needed on REFUND itself.
- **RESERVATION** has no requirement for now — reservation-recording isn't wired end-to-end in production yet (`inventory-domain-design.md`'s own note), so requiring a snapshot here would only force placeholder values into flows that don't exist practically. Revisit when reservation-recording is actually built.
- **SHIPPING/INSTALLMENT_PAYMENT** keep the original reasoning — both must be null, no real product involved.

A new `assertProductNameAndSkuMatchType()` enforces exactly this: required for SALE, must be null for SHIPPING/INSTALLMENT_PAYMENT, unconstrained (either state acceptable) for RESERVATION/REFUND.

**Captured once, at construction, never updated** — the same immutability rule (§3.2) that already governs every other fact on this class. A product renamed *after* the sale does not retroactively change what a past receipt says was sold; that is the entire point of a snapshot.

**Not this section's job:** deciding how the caller obtains the name/SKU to pass in (loading the real Product/Variation at the point of sale) — that is `CheckoutOrchestrator`'s own concern (and POS's, once built), an application-layer detail, not a domain rule.

**Amended by §3.13 E-D5 — a real defect in this section's own original design, found during Prompt Г (`admin-panel-design.md` §14) and fixed here, not yet in code:** `reconstituteFromStorage()` delegates to the same private constructor as fresh construction, so the "required for SALE" assertion above ran on BOTH paths — a legacy row written before this section shipped, with a genuinely NULL `product_name`, throws the moment it is read back from storage, not just when someone tries to write an invalid one. That is backwards: "required" should govern what a NEW row may look like, never what an OLD row is allowed to have been.

**The fix (applies retroactively to `productName`/`sku`, and governs every new §3.13 field the same way):** split validation into two tiers. Tier A — structural, e.g. "must be null for SHIPPING/INSTALLMENT_PAYMENT" — is a fact about the line's TYPE, true regardless of when it was written, and stays in the constructor, enforced on every path including reconstitution. Tier B — "required for SALE" — is a fact about how this codebase now insists a *fresh* line be built, and moves into a new named factory, `SaleLine::create(...)` (mirroring `Client::create()`/`Promotion::create()`/`Product::createSimple()`'s existing naming precedent), which every fresh-construction call site must use instead of `new SaleLine(...)` directly. `reconstituteFromStorage()` keeps calling the bare constructor — Tier A only — so a legacy NULL reads back exactly as stored, no throw, no invented value.

### 3.13 Full sale-line snapshot for returns — designed, not yet implemented

**Status of this whole section: design only, approved decisions from the domain owner (E-D1–E-D5), not yet built.** See §6 for the implementation stages this becomes once picked up. No code, migration, or test referenced below exists yet.

**The gap this closes:** `amount` (§2) is the pre-promotion unit price × quantity (`CheckoutLinePricer`) — the promotion discount exists only as one order-level total (`orders.discount_minor`), never allocated per line. Building a real return on top of today's `SaleLine` means guessing how much of a given line's money to hand back; `profit` is computed the same pre-promotion way, silently overstating margin on every promoted order. This section designs the fix; it does not build it.

#### E-D1 — Three price levels, never mixed

1. **Display price** (Pricing, live) — changes over time; a sale never writes back into it (§1).
2. **Price at the moment of sale** (snapshot) — the regular and final unit price as the store showed it, frozen the instant the sale happened.
3. **Actually paid** — level 2 minus the promotion-code share minus a discretionary (register) discount. Exists ONLY on the sale record.

**Worked example (stated verbatim — it is what keeps the field names below straight):** a POS item displayed at 51.10 is sold for 50.00 as a courtesy to a regular client. A later return refunds 50.00 — the actually-paid amount — never 51.10. The admin panel and the storefront keep showing 51.10 throughout; the courtesy discount never reaches back into Pricing or Catalog.

#### E-D2 — Returns refund the actually-paid amount, by default

A return refunds the actually-paid amount (level 3) of the returned units by default. The operator may consciously refund a different amount, but that is an explicit override, never the default computation. The display price on the return date is recorded for information only and never drives the refund amount. §3.4 above is rewritten to match.

#### E-D3 — Two separate discount fields per line, never merged

`promotionDiscountShare` and `discretionaryDiscount` are two distinct fields, always — never one merged figure. A web-channel line's `discretionaryDiscount` is always zero (no discretionary-discount UI exists or is designed for the storefront), but the field exists on every SALE line, not only POS ones, so `netPaidAmount`'s formula never needs a channel-specific branch.

#### E-D4 — One snapshot builder for both channels

The logic that turns a priced line into the full field set below is one app-layer service — the same shape `CheckoutLinePricer` already establishes (`App\Services`, not a domain concept; `SaleLine` itself still knows nothing about Pricing, Cart, or POS input, per §1). Web Checkout is its first caller (implementation stage 4, §6); a future POS flow is its second. Two independent implementations of "how to fill in a snapshot" is exactly the class of drift §3.3/§3.8 already warn against — one service, two callers.

**Revised — the allocation math itself is ONE level lower than the snapshot builder, and has a THIRD caller besides the two above.** Splitting a total Money amount across weighted shares is not specific to promotions at all — a future POS bill-level discretionary discount (a cashier knocks 5.00 off the whole ticket; §3.13's own `discretionaryDiscount` field needs a per-line share of that too) needs the exact same operation. Recommend: **`Money::allocate(array $weights): array` on `EasyCo\Pricing\Money` itself**, not a dedicated allocator service and not duplicated per caller — see the Promotion allocation rule below for its exact contract. `Money` is already the shared, framework-agnostic primitive every one of these domains reuses (§3.1); a pure, stateless "split myself into N parts by these weights" operation is the same class of method as the `multiply(int)` it already has, not a new architectural concept. `PromotionDiscountCalculator` becomes `Money::allocate()`'s first caller; the future POS discretionary-discount split and the future snapshot builder (E-D4 above) are its second and third — one implementation, three callers, none of them re-deriving the largest-remainder logic independently.

#### E-D5 — Invariants at creation, trust storage on reconstitution

Covered fully in §3.12's own amendment above (the `SaleLine::create()` / `reconstituteFromStorage()` split). Applies identically to every field below: required on fresh construction, `NULL` accepted on reconstitution, never backfilled on legacy rows.

#### Fields

| Field | Type | Required (fresh SALE line) | Meaning |
|---|---|---|---|
| `regularUnitPrice` | `Money` | yes | Level 2's regular component, per unit. |
| `finalUnitPrice` | `Money` | yes | Level 2's final component, per unit — the price the store actually showed, before any promotion/discretionary discount. For a FRESH line, removes the need for the current workaround in `OrderAdminReader::buildLineView()`, which divides `amount` by `quantity` and defensively checks the division is exact (logging a warning and rendering "unavailable" when it isn't). **`OrderAdminReader` keeps that fallback, unchanged, for any row where `finalUnitPrice` is `NULL`** (a legacy row, per E-D5) — it does not delete the workaround, only stops needing it for new rows; the two code paths (`finalUnitPrice` when present, the divide-and-check fallback when absent) coexist by design, matching E-D5's own "never backfilled" rule. |
| `promotionDiscountShare` | `Money` | yes (zero when not applicable) | This line's share of the order-level promotion-code discount — see Promotion allocation rule below. |
| `discretionaryDiscount` | `Money` | yes (always zero on a WEB line) | E-D3. |
| `netPaidAmount` | `Money` | yes | Level 3 — `finalUnitPrice × quantity − promotionDiscountShare − discretionaryDiscount`. |
| `soldAttributes` | ordered `array<{definitionId: string, definitionCode: string, definitionName: string, valueId: string, value: string}>` | yes (`[]` for a SIMPLE line — never `null`) | The sold Variation's attribute values as they existed at sale time — display/reprint/returns only, not a reporting source (live `catalog_variation_attribute_values` serves that, joined via `priceableId` — see Q3). |
| `unitCost` | `?Money` | recommended (Q2) — nullable even on a fresh line: `null` = genuinely unknown, not zero | Closes `checkout-domain-design.md` §9.3's own deferred gap. |

#### Invariants

- **Same currency** across `amount`, `regularUnitPrice`, `finalUnitPrice`, `promotionDiscountShare`, `discretionaryDiscount`, `netPaidAmount`, and `unitCost` (when set) — enforced by `SaleLine::create()` (single-line scope, `Money`'s own cross-currency operations already refuse to compute otherwise, same posture `InstallmentPlan`'s `CurrencyMismatchException` already establishes, §3.10).
- **`netPaidAmount == finalUnitPrice × quantity − promotionDiscountShare − discretionaryDiscount`, exactly.** `SaleLine::create()` receives `netPaidAmount` as an explicit argument — never silently recomputed internally — and validates it against this formula, throwing on mismatch. The same "cheap corruption detector, not implicit trust" posture `reconstituteFromStorage()`'s own docblock already uses for `Variation`'s signature-vs-assignments check (cited directly in this document's §3.12 amendment above) — a builder bug that miscalculates `netPaidAmount` is caught immediately at construction, never silently persisted.
- **`netPaidAmount ≥ 0`.** `create()` throws if the formula would produce a negative value — a discretionary discount or promotion share exceeding the line's final value is a caller bug the builder/POS UI must cap before calling `create()`, never a state a `SaleLine` represents.
- **Order-level reconciliation, enforced by the snapshot builder (E-D4), not by `SaleLine` itself** (a single line has no visibility into its siblings or the `Order`): for a WEB order, `Σ line.promotionDiscountShare == orders.discount_minor` exactly, and `Σ line.netPaidAmount == orders.total_minor` exactly. If the Promotion allocation rule below is implemented correctly, this assertion should never actually fire in production — it exists as the same class of cheap, always-on corruption detector as the two checks above, not routine defensive programming against an expected failure.
  **Caveat, holds only as long as `checkout-domain-design.md` §10 still holds:** `Σ netPaidAmount == orders.total_minor` is only true because `Order.total` is currently defined as exactly `subtotal − discount`, with **no shipping component** (§10's own explicit statement). The moment shipping is added to `Order.total` — §10 already lists everything else that touches — this invariant needs a `shippingMinor` term added to one side or the other (a `SaleLine` for the shipping charge already exists as its own `SHIPPING`-type line per §2/§4, excluded from this sum today since it isn't a SALE line at all). Not a correction needed now; flagged so whoever adds shipping finds this invariant on the list rather than rediscovering it, the same posture §10 itself already takes for every other shipping consequence.

#### Promotion allocation rule

**Owned by `PromotionDiscountCalculator` (`App\Services`) — the exact same code path that computes the order-level total today, extended, never a second independent split.** `eligibleBase()`/`baseCappedByUsageLimit()` already compute a per-line "eligible amount" internally while accumulating toward one total — today that per-line value is discarded the moment it's added to the running sum. The extension: keep each line's own eligible amount (walked in the SAME array order `baseCappedByUsageLimit()` already establishes), and hand `[eligibleAmount[0], eligibleAmount[1], ...]` as weights to `Money::allocate()` (E-D4 above) together with the already-computed total discount.

**REVISED — largest-remainder method, not "round each share, last line absorbs the remainder."** The original per-line rounding rule in this section was wrong, and demonstrably so, not just inelegant:

> **Counter-example that disproves it:** a 10% promotion over three lines with eligible amounts 5, 5, 1 (minor units; base = 11). `roundedDivide(11 × 1000, 10000)` = **1** minor unit total discount. The old rule: `share[0] = roundedDivide(5 × 1000, 10000)` = 1 (half-up rounds `0.5` up), `share[1]` = 1 the same way, `share[2]` (the last line, "absorbing the remainder") = `total − share[0] − share[1]` = `1 − 1 − 1` = **−1**. A negative promotion share on a real sale line — exactly the class of bug `netPaidAmount ≥ 0` (this section's own Invariants) exists to catch, except here the BUILDER itself would be the thing producing the invalid value, not a caller.

`Money::allocate(array $weights): array` fixes this with the **largest-remainder method** (a well-established, standard technique for this exact problem — not invented here):

1. For each weight `w[i]`, compute the exact (unrounded) share `w[i] × total / Σw` and take its floor: `floor[i] = intdiv(w[i] × total, Σw)`.
2. `leftover = total − Σ floor[i]` (an integer, `0 ≤ leftover < count(weights)` by construction).
3. Distribute `leftover` minor units one each to the `leftover` lines with the **largest fractional remainder** (`w[i] × total mod Σw`, largest first); ties broken by array order — the same "walk in the given order, deterministic" posture `baseCappedByUsageLimit()` already establishes elsewhere in this same class.

**Same counter-example, worked through the new rule:** weights `[5, 5, 1]`, total `1`. `floor[i]` = `intdiv(5×1,11)=0`, `intdiv(5×1,11)=0`, `intdiv(1×1,11)=0` — all zero. `leftover = 1 − 0 = 1`. Fractional remainders: `5×1 mod 11 = 5`, `5×1 mod 11 = 5` (tied with line 0), `1×1 mod 11 = 1`. Line 0 wins the tie (array order) and receives the one leftover unit: `share = [1, 0, 0]`. Sums to `1` ✓; every share is `≤` its own weight ✓; nothing negative.

**Guarantees, stated explicitly (the contract `Money::allocate()` must document and a unit test must prove):**
- `Σ shares == total`, exactly, always.
- `0 ≤ share[i] ≤ weights[i]` for every `i` — a line's share can never exceed its own eligible amount, and never goes negative. (Holds because `total ≤ Σweights` in every caller here — `PromotionDiscountResult`'s own capping already guarantees this on the Promotions side — and the leftover distributed is always `< count(weights)`, so no single floor can be pushed past its own weight; see the method's own docblock for the short proof once implemented.)
- Deterministic: the same `(weights, total)` input always produces the same output — no dependency on hash-map iteration order or anything but array position.

**The four cases restated against the new rule:**
- **PERCENTAGE:** weights = each applicable line's eligible amount; total = the already-computed `roundedDivide(base × basisPoints, 10000)`.
- **FIXED_AMOUNT, uncapped (nominal ≤ base):** weights = eligible amounts; total = `nominal`.
- **FIXED_AMOUNT, capped (nominal > base):** total = `base` itself (`PromotionDiscountResult::capped()`) — `Money::allocate()` with `total == Σweights` degenerates to `share[i] = weights[i]` exactly (every `floor[i]` already equals `weights[i]`, `leftover = 0`), so this case needs no special-casing at all, unlike the old rule.
- **`usageLimitItems` set:** weights are exactly what `baseCappedByUsageLimit()` already computes per line — full `lineTotal` for a line entirely within the limit, `unitPrice × remaining` for the line that crosses it, zero beyond it. Zero-weight lines always floor to `0` and can never win a largest-remainder tie against a genuinely eligible line (their fractional remainder is `0`), so "non-applicable lines get share `0`" falls out of the algorithm, not a special case.

**Per-line keys — POS forces a positional, not associative, breakdown.** `PromotionDiscountResult`'s new accessor returns a plain, sequential `array<int, Money>`, positionally aligned with the `$applicableLines` array already passed into `calculate()` — **never** `array<string, Money>` keyed by `variationId`. On the web, keying by `variationId` would be safe today — `cart_lines` carries a real `UNIQUE(cart_id, variation_id)` constraint (`cart_lines_cart_variation_unique`, `2026_08_31_000002_create_cart_lines_table.php`), so a web cart can never have two lines for the same variation. A future POS ticket has no such constraint and no reason to need one (a cashier may legitimately ring up the same item twice as two separate lines, e.g. rung at different discretionary discounts) — an associative breakdown would silently collapse two real lines' shares into one key. Keying by array position, matching how `$applicableLines`/`$validatorLines` are already built and walked everywhere else in this pipeline, works identically for both channels and can never collide.

#### Partial return of a quantity

**Confirmed sound, with a worked example:** cumulative `floor(netPaidAmount_minor × n / quantity)` for `n = 0..quantity`, differenced. Returning units `r+1..r+k` (i.e., `k` more units on top of `r` already refunded, tracked by summing `quantityReturned` across prior `REFUND` lines sharing this `originatingSaleLineId` — a query, never a stored running counter, per §3.4's revised design above) refunds `cumulative(r + k) − cumulative(r)`.

**Worked example, quantity 3, net 100.00 (10000 minor units):** `cumulative(0)=0, cumulative(1)=3333, cumulative(2)=6666, cumulative(3)=10000`. Returning one unit at a time: `3333`, then `3333`, then `3334` — sums to `10000`. Returning two units then one: `6666`, then `3334` — still `10000`. This holds for ANY sequence of consecutive partial returns, by construction: it's a telescoping sum over integer breakpoints of the same fixed interval `[0, quantity]`, not per-return-event rounding that could drift — the property the prompt's own "never a cent more or less, in any sequence" requirement demands.

#### Profit, computed on net

`profit = netPaidAmount − (unitCost × quantity)`, computed by the snapshot builder at SALE-line creation time (implementation stage 4, §6) and stored in the EXISTING `profit` field — no new field needed. **Existing rows are unchanged** — `profit` is a historical fact (§3.2); nothing rewrites it retroactively. Reports must read `profit` conditioned on `netPaidAmount`'s own nullability (E-D5): `netPaidAmount === null` (a legacy row) means `profit` was computed the OLD way (`amount − unitCost × quantity`, i.e. before any promotion was subtracted); `netPaidAmount !== null` means `profit` is computed the NEW way, on net. No separate schema-version flag is needed — `netPaidAmount`'s own presence already carries this signal, the same "one nullability check answers two questions" shape §3.13's fields already lean on elsewhere in this section.

#### `amount`'s meaning — recommendation (domain-owner decision at review)

**Recommended: keep `amount`'s meaning byte-identical (final unit price × quantity, pre-promotion) and add `netPaidAmount` as a new, separate fact — do not redefine `amount`.** `amount` continues to mean "the gross value of the goods, at the store's displayed final price" — a real, legitimate figure in its own right (what a receipt line would show before a register discount), unchanged for every existing row, no migration needed to preserve its meaning. Reports that want "real revenue, net of everything actually discounted" switch to `netPaidAmount`, falling back to `amount` explicitly (never silently) for a legacy row where `netPaidAmount` is `null`.

**Rejected alternative:** redefining `amount` itself to mean net. This would silently change what every existing row's `amount` value means without changing the stored data — a report written before this change and one written after would disagree about what the identical historical number represents, with nothing in the data itself to tell them apart. That is precisely the kind of undocumented meaning-drift this project's own design-doc discipline (every "corrected during implementation" note throughout this document and `checkout-domain-design.md`) exists to prevent. Presented as a recommendation, not decided here, per this task's own instruction.

#### REFUND lines

Covered fully in §3.4's rewrite above: a REFUND line inherits `productName`/`sku`/`soldAttributes`/`regularUnitPrice`/`finalUnitPrice` via `originatingSaleLineId` (never duplicates them), and records on itself `quantityReturned`, `defaultRefundAmount` (computed, informational), `actualRefundAmount` (the fact of record, operator-overridable), and `displayPriceAtReturn` (informational only, never drives the refund amount — E-D2).

#### RESERVATION lines

Same posture §3.12 already established for `productName`/`sku`: unconstrained for now (either state acceptable) — reservation-recording isn't wired end-to-end in production yet (`inventory-domain-design.md`), so requiring any of §3.13's new fields here would only force placeholder values into a flow that doesn't practically exist. Revisit together, when reservation-recording is actually built.

#### Open questions — recommendations only, not decided here

**Q1 — Price-list source (which price list produced the final price, e.g. "Manual Sale" vs. a campaign)?** Requires an additive change to Pricing's `PriceQuote` (a cross-package change, the most invasive item in this whole section). Value: campaign-level reporting distinct from promotion-code tracking (already fully covered by `promotionDiscountShare`/`PromotionRedemption`), and a possible future input to `excludeSaleItems`-style exchange logic. **Recommend: defer.** No exchange flow exists or is designed anywhere in this project yet (returns here means refund, not exchange), so there is no real consumer for this field today — adding it now would be exactly the kind of speculative field this project's own repeated "no `priority` field until something needs it" (`promotions-domain-design.md` §3.1) and "no shipping component until something charges for it" (`checkout-domain-design.md` §10) precedent argues against. Add `§5`'s deferred list entry below; revisit when a real campaign-reporting or exchange-flow need is actually designed.

**Q2 — Unit cost snapshot (`unitCost`, nullable = unknown).** **Recommend: include now.** Closes `checkout-domain-design.md` §9.3's own already-flagged gap ("cost never set vs. verified zero... would require changing `SaleLine` itself — out of scope") directly — this is that moment. Lets a return correctly reverse profit using the cost that was actually true at sale time, not whatever `ProductCost` says today (which may have changed). Small, low-risk addition: the source already exists and is designed (`CostPriceProvider::costFor()`, `checkout-domain-design.md` §9.1), and `CheckoutLinePricingResult::costRecorded()` already distinguishes "real" from "zero-fallback" — this only needs the actual `?Money` value carried one step further, onto `SaleLine`, instead of being discarded after computing `profit`.

**Q3 — Attribute snapshot storage: JSON column vs. a child table.** **Recommend: a JSON column on the sale line row.** A snapshot is read as a whole, once, alongside its parent line — it is never independently filtered or joined the way `catalog_variation_attribute_values` legitimately is for live catalog browsing. A child table would need its own repository/reconstitution plumbing for a fact that has exactly one real consumer shape ("show what was sold").

**Stronger argument for JSON, confirmed against the real invariant rather than just "it's simpler":** a `Variation`'s attribute assignments are **immutable after creation** — `Product::declareVariationAxes()`/`assertValidCombination()` fix a STANDARD variation's combination at the point it's added, `changeVariationCombination()` is the only mutation path and creates no new historical ambiguity (it validates against a uniqueness check the same way creation does, catalog-domain-design.md §"Atomic variation combination changes"), and a variation is archived, never deleted (§2's own "historical identity is never destroyed," CLAUDE.md rule 4) — so `catalog_variation_attribute_values` for a given `variation_id` is, for all practical reporting purposes, itself already a stable historical fact, not a moving target. **This means a report like "units sold per size" does not need the snapshot at all** — it can `JOIN` live `catalog_variation_attribute_values` through `SaleLine.priceableId` (already a plain id reference, §2) and get an accurate answer, exactly the shape `OrderAdminReader`'s own cross-table reads already establish for other admin queries. `soldAttributes` therefore serves a genuinely narrower job than Q3's original framing suggested: **display, reprint, and returns only** (showing what a specific receipt said, verbatim, even if the variation itself is later archived and its live rows become harder to reach) — never the one thing that might have justified a queryable child table (per-attribute aggregate reporting), because that need is already served, more accurately, by the live join. This makes the JSON recommendation stronger, not merely simpler: there is no real reporting consumer this format under-serves.

Each JSON entry gains `definitionId` (the raw `catalog_attribute_definitions.id`, alongside the already-specified `definitionCode`/`definitionName`/`valueId`/`value`) — a real id, not just its human-readable code/name pair, so a future need to join back to the live definition (e.g. checking whether an attribute's `type` changed) has one to use without re-deriving it from `definitionCode`.

**Q4 — Other gaps found while designing this. Recorded here as domain-owner decisions for the future Returns design — NOT designed or built in this pass; also listed in §5's deferred list.**

- **(a) The performing staff member.** No field records WHO acted anywhere on `SaleLine`/`OperationalSales` today, despite `Permission::REFUND_CASH`/`REFUND_BANK` already gating the action itself. **Decided:** taken from the authenticated user at the point of return and recorded on the REFUND line itself (not the original SALE line — the SALE line's own facts, per §3.2, stay about the sale, never retroactively annotated with a fact from its eventual return).
- **(b) Return reason.** **Decided:** an optional free-text field on the REFUND line. No fixed taxonomy (defective / wrong size / changed mind / ...) decided here — free text is the V1 shape; a structured reason code, if ever wanted, is a later, separate decision.
- **(c) Restocking.** **Decided:** a POS return increases stock unconditionally. Whether the returned unit becomes sellable ONLINE again is a merchant-controlled checkbox at the point of return — a real, common case in physical retail is a returned item going back into POS-only stock (e.g. it has no product photos, or the merchant wants to inspect it before it's orderable online again). An ONLINE return (the storefront's own return flow, once one exists) always increases stock AND makes the variation sellable — no checkbox, since an online customer only ever returns something that was itself sellable online. If the parent Product is inactive at the point of a return that re-enables its only remaining sellable variation, the Product is activated too, so the return doesn't silently produce stock nobody can buy.

  **Open points recorded alongside (c), for whoever picks up Returns:**
  - **Reviving an ARCHIVED variation goes through `Product::restoreArchivedVariation($variation)`, never `Variation::reviveFromArchive()` directly** — confirmed against the installed source: `reviveFromArchive()` is reserved for exactly two sanctioned Product-level callers (`Product::addStandardVariation()`'s implicit reuse case, and `restoreArchivedVariation()`'s explicit one — `Variation.php`'s own docblock, catalog-domain-design.md §3.17); calling it from anywhere else, including a future Returns flow, would bypass `restoreArchivedVariation()`'s own axis-drift validation. `restoreArchivedVariation()` only takes the variation ARCHIVED → DRAFT — `is_visible`/`is_purchasable` deliberately stay `false` afterward (confirmed: `Variation.php`'s own comment on this) — a subsequent, explicit `activate()` (DRAFT → ACTIVE) plus `setVisible(true)`/`setPurchasable(true)` is still needed to actually make it sellable again; `activate()` called directly on a still-ARCHIVED variation throws (`Variation::activate()`'s own guard). **A real failure mode a Returns flow must handle, not assume away:** `restoreArchivedVariation()` throws `VariationNotRestorableException` if the Product's declared axes drifted since the variation was archived — what a return should do when the specific variation it's trying to restock literally cannot be restored is a genuine open question, not answered here.
  - **The activity log:** a Product brought back online by a return is recorded via the existing `App\Services\ActivityLogger` (already the mechanism `ProductResource`'s own edit pages use for other Product-state changes), with the triggering order's id as the recorded reference — reusing the established mechanism, not inventing a parallel one.
  - **Whether a DRAFT product is ever auto-published by a return.** **Recommend: no.** A DRAFT product was never live in the first place — a return reactivating one of its variations should not be the thing that first PUBLISHES the product; that remains a deliberate merchant action (`Product::publish()`), same posture as everywhere else `publish()` is a conscious, explicit call, never a side effect of an unrelated operation.
  - **Whether a "return to stock" checkbox (default ON) is needed for a defective item** — i.e. an explicit way to receive a return WITHOUT restocking it at all (a genuinely defective unit that should never be resold). Not decided here; flagged as a real, likely-needed exception to (c)'s "restocking is the default" rule, for the same future design pass.

---

## 4. Status taxonomy: source system → this model

| Legacy `stats` value | Represented here as |
|---|---|
| `sold` | `type=SALE`, `channel=POS` |
| `web_sold` | `type=SALE`, `channel=WEB` |
| `sold_end` | `type=SALE`, `originating_reservation_line_id` set (settled via an `InstallmentPlan`) |
| `reserve` | `type=RESERVATION`, `status=PENDING`, no discount applied |
| `reserved` | `type=RESERVATION`, `status=PENDING`, discount applied at reservation time |
| `refund` | `type=REFUND`, `channel=POS` |
| `web_refund` | `type=REFUND`, `channel=WEB` |
| `ref_res` | `type=RESERVATION`, `status=CANCELLED` (never became a sale) |
| `paid_res` | `type=SALE`, `originating_reservation_line_id` set (paid off in one go, not via an `InstallmentPlan`) |
| `refunded` | *(removed — see §3.2; the original line is simply left untouched, referenced by the new line instead)* |
| `partial` | `type=INSTALLMENT_PAYMENT`, plan `status=ACTIVE` |
| `part_end` | `type=INSTALLMENT_PAYMENT`, plan `status=COMPLETED` |
| `shipping` | `type=SHIPPING`, `channel=WEB` |

---

## 5. Explicitly deferred (documented, not accidental)

- ~~Persistence layer and migrations for all four aggregates~~ — **done as of v1.2** (see this document's own Status line); this entry was stale (still described as "no Eloquent models, repositories, or migrations exist yet") until corrected in this pass, alongside the identical staleness found and fixed in §6 item 3.
- **Full report/query-layer implementation** (daily register comparison, period summaries, delivery reconciliation) — the data model in §2 is designed to support all of it, but the actual query/view layer is separate follow-up work, not part of this domain's core.
- **`SkuGenerator`/`BarcodeGenerator`** (already deferred from earlier Catalog work) — unrelated to this domain, still queued behind it.
- **Brand/channel-specific discount rules** (e.g. the source system's brand-specific POS discount button) belong to **Pricing**, implemented as Hook filters (`Hook::apply('pricing.discount.percentage', ...)`), not to this domain — Operational Sales only ever records the resulting `amount` as a fact, never decides it.
- **Barcode-based variation lookup for POS** — depends on the still-pending `VariationRepository::findByBarcode()` usage patterns; not designed here.
- **Multi-operator concurrency safety** (two cashiers acting on the same client/plan simultaneously) — the DB-constraint-first pattern established throughout this project (`catalog-domain-design.md` §7) will apply once persistence is designed, but the specific constraints aren't finalized in this pass.
- **Which price list produced a sale line's final price** (§3.13 Q1) — deferred: requires an additive change to Pricing's `PriceQuote`, and no real consumer (campaign reporting, an exchange flow) exists or is designed yet. Revisit if one is.
- **The REFUND/POS flow itself** (§3.13 Q4) — domain-owner decisions recorded (performing staff member from the authenticated user, a free-text return reason, POS-return-always-restocks vs. online-return-restocks-and-relists), but nothing here is built: the restore-from-archive path, the activity-log entry, whether a DRAFT product can be auto-published by a return (recommended: no), and a possible "don't restock, it's defective" checkbox are all still open, for whoever builds returns end-to-end.

---

## 6. Next steps

1. ~~Review of this document by the domain owner (in progress).~~ Done — this is v1.1.
2. ~~Domain-layer implementation (`Client`, `Transaction`, `SaleLine`, `InstallmentPlan` as plain PHP, framework-agnostic, mirroring `Product`/`Variation`'s existing shape) — separate, focused prompts per aggregate, same rhythm as the Catalog build.~~ Done as of v1.1 — see §3.9–§3.11 for what implementation resolved beyond the original design; 61 tests passing.
3. ~~Persistence layer, migrations, and the DB-constraint story for `InstallmentPlan` settlement.~~ **Done as of v1.2** — see this document's own Status line (migrations, Eloquent repositories, verified against a real MySQL database). This item was still marked "next piece of work" here until this pass corrected it — a stale note found while adding §3.13, not left as-is.
4. Reporting/query layer — once the write model is solid.

**§3.13's own implementation stages** (full sale-line snapshot for returns — design approved, nothing below built yet):

5. Schema (migration for §3.13's new `SaleLine` columns) + the `SaleLine` domain changes themselves (`SaleLine::create()`, the reconstitution split, §3.12's amendment) + the retroactive `reconstituteFromStorage()` fix for `productName`/`sku`.
6. Per-line allocation in `PromotionDiscountCalculator` (§3.13's Promotion allocation rule).
7. `CheckoutOrchestrator` writes the full snapshot via the new one-service-two-channels builder (§3.13 E-D4), and `profit` moves to being computed on net (§3.13).
8. Admin Order View (`admin-panel-design.md` §14) surfaces the new fields.
