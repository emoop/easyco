# Operational Sales Domain Design

**Status:** v1.0 — approved model, not yet implemented.
**Builds on:** `catalog-domain-design.md` (Product/Variation identity, `priceableId()`), `pricing-domain-design.md` (Money value object, reused here directly to eliminate a real float bug found in the source system).
**Origin:** derived from a line-by-line review of an existing, years-in-production WooCommerce POS plugin (internal codename `raf_pos`) — used as a **functional reference only, not architecture to copy**, same posture this project already takes toward WooCommerce/Bagisto/Aimeos elsewhere. Two real bugs found in that review directly shaped two of this domain's core decisions (§3.3, §3.5).

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
└── originating_reservation_line_id          nullable — set when a
                                              reservation is paid off, points
                                              at the RESERVATION line it settles

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

### 3.4 Refund provenance is explicit, and never mutates Catalog/Pricing

A `REFUND` `SaleLine` carries three separate Money fields: `regularPriceAtReturn`, `salePriceAtReturn` (nullable — not every product was ever on sale), and `actualRefundAmount` (what was actually handed back — may differ from both, at the operator's discretion, e.g. a goodwill rounding-down). This mirrors the source system's actual fallback behavior (`refund_price` → `sale_price` → `regular_price`, confirmed directly in its refund handler) but makes every value an explicit, recorded fact rather than three optional request fields with implicit fallback logic buried in application code. Per §1, none of this ever writes back into the Variation's own price fields — the source system's refund handler did exactly that as a side effect, which this design deliberately does not carry forward.

### 3.5 `recorded_at` vs. `effective_at`

The source system stores "today's date" in its main sales table but preserves the original reservation date in a separate metadata table — a real, useful distinction, but implicit and dependent on which of two tables you happen to query. Here it's one explicit pair of fields on every `SaleLine`: `recorded_at` (when the row was written) and `effective_at` (when the event actually happened — e.g. the original reservation date for a line that's later settled). Both are always present, on every line, with no cross-table lookup required to reconstruct either.

### 3.6 A refund counts in the daily POS total — confirmed explicitly

`type=REFUND` lines with `channel=POS` are included (as a negative) in the default daily POS report — not excluded. A day's real net revenue includes what came back, not just what went out; this matches the source system's own `refund` status being explicitly "видим в дневния оборот" per its own legend, and was confirmed directly by the domain owner during design.

### 3.7 `Client.name` carries no format rule

An earlier draft of this design proposed requiring lowercase Cyrillic client names, based on an operational habit the domain owner described for reducing cashier data-entry mismatches. That was correctly rejected during review: it's a legitimate operational convention for a specific store, not a domain invariant every future EasyCo merchant should be forced into. `Client.name` is a free-text string; any input discipline (script, case, a dropdown sourced from previously-seen clients) is a UI/operational choice, layered on top via the existing Hook/Extensibility mechanism if a merchant wants to enforce one — not baked into the domain.

### 3.8 Reporting is a query layer, not a domain

The source system's online-order tracking and delivery-reconciliation screens (`web-work.php`, `web-statistic.php`) turned out, on inspection, to be pure read-side queries over the same sales table, filtered by `channel` and `type` and grouped by `transaction`/order number — nothing that needed its own domain concept. The same holds here: "compare POS-register total vs. system total," "show combined POS+WEB revenue," "reconcile courier shipping costs for accounting" are all just differently-filtered queries over `Transaction`/`SaleLine`, not separate aggregates. `SHIPPING` lines are excluded from the default daily POS report (they're a courier cost, not POS revenue) but included in WEB-channel accounting reports, exactly matching the source system's existing split.

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

- **Full report/query-layer implementation** (daily register comparison, period summaries, delivery reconciliation) — the data model in §2 is designed to support all of it, but the actual query/view layer is separate follow-up work, not part of this domain's core.
- **`SkuGenerator`/`BarcodeGenerator`** (already deferred from earlier Catalog work) — unrelated to this domain, still queued behind it.
- **Brand/channel-specific discount rules** (e.g. the source system's brand-specific POS discount button) belong to **Pricing**, implemented as Hook filters (`Hook::apply('pricing.discount.percentage', ...)`), not to this domain — Operational Sales only ever records the resulting `amount` as a fact, never decides it.
- **Barcode-based variation lookup for POS** — depends on the still-pending `VariationRepository::findByBarcode()` usage patterns; not designed here.
- **Multi-operator concurrency safety** (two cashiers acting on the same client/plan simultaneously) — the DB-constraint-first pattern established throughout this project (`catalog-domain-design.md` §7) will apply once persistence is designed, but the specific constraints aren't finalized in this pass.

---

## 6. Next steps

1. Review of this document by the domain owner (in progress).
2. Domain-layer implementation (`Client`, `Transaction`, `SaleLine`, `InstallmentPlan` as plain PHP, framework-agnostic, mirroring `Product`/`Variation`'s existing shape) — separate, focused prompts per aggregate, same rhythm as the Catalog build.
3. Persistence layer, migrations, and the DB-constraint story for `InstallmentPlan` settlement — after the domain layer is tested in isolation.
4. Reporting/query layer — last, once the write model is solid.
