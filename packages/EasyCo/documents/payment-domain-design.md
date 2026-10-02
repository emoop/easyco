# Payment Domain Design

**Status:** Draft v1 — approved scope, not yet implemented.
**Builds on:** `checkout-prerequisites-note.md` (the gap this document closes), `operational-sales-domain-design.md` §3.2/§3.4 (the append-only, explicit-provenance correction pattern `PaymentRefund` deliberately mirrors — "a refund is a new record referencing the original, never an in-place rewrite," and "every value is an explicit, recorded fact rather than implicit fallback logic"), `pricing-persistence-domain-design.md` §4.7 (the precedent for stating honestly when an invariant is *not* purely DB-enforced, rather than overclaiming).
**Origin:** designed through a structured conversation establishing three decisions before any code: a single contract must cover both online and offline payment methods uniformly; Payment must be a separate entity from Order to support retry without corrupting the order; double-capture prevention must be DB-enforced, never an app-level check-then-act race. A fourth decision — whether `PaymentRefund` belongs in V1 — was deliberately revisited on a second, unhurried session after being raised once already: refunds are an everyday retail reality (in-store and online alike), not an edge case, and the domain owner chose to include it now rather than defer it. Verified against real-world precedent before finalizing, not assumed: WooCommerce's `WC_Order_Refund` (confirms `reason`, and contributes `refunded_by` — who authorized a return, useful for a POS-capable store) and Stripe's `Refund` object (confirms a refund is scoped to the original charge/payment, not the order — the same relationship this document already chose for `PaymentRefund` → `Payment` — plus its `status`/`failure_reason` shape).

---

## 1. Scope

This document defines the **Payment domain**: recording payment attempts against an order (once `Order` exists — see §6) through a uniform contract that treats online and offline methods identically, and recording refunds against a specific successful payment.

**Decision: one contract for every payment method, online or offline.** Cash-on-delivery and bank transfer are not "payment providers" in the API-integration sense, but they ARE real, first-class payment methods this store needs — not an exception bolted onto a card-processing-shaped contract. See §4.

**Decision: `Payment` is a separate entity from `Order`.** A failed attempt must be retryable without corrupting the order it belongs to — a second attempt is a new `Payment` row, not an in-place rewrite of a failed one. This mirrors the same "history is append-only, never silently rewritten" principle `operational-sales-domain-design.md` §3.2 already established for `SaleLine`.

**Amendment, found during Checkout implementation, not part of the original design: `Payment.status`/`providerReference`/`failureReason` are mutable exactly once, via `recordAttemptResult()`.** This does NOT contradict the append-only rule just stated — the distinction is real and worth being explicit about, not left to inference. A RETRY is still a brand-new `Payment` row; that rule is untouched. What this adds is recording the OUTCOME OF THE SAME ATTEMPT that is already underway: Checkout now writes the `Payment` row as `PENDING` inside its own transaction, before the charge is even attempted (see `checkout-domain-design.md` §8.3 step 10), so that a crash between commit and the actual charge leaves a real, findable row instead of none at all. `recordAttemptResult()` is how the already-in-flight attempt's real result gets written, once, when it becomes known — one attempt, one row, its outcome written once, never a second write.

**Decision: double-capture prevention is DB-enforced, not app-level check-then-act.** At most one `Payment` per order may ever reach `CAPTURED` status — enforced at the database engine level (§5.1), immune to two concurrent requests racing past an application-level check. This is the direct answer to the domain owner's explicit requirement: a customer must never be charged twice for the same order.

**Decision: `PaymentRefund` is in V1 scope**, deliberately, after reconsideration — see Origin above.

**Explicitly out of scope for V1** (deferred, not forgotten — see §7):
- Any real online payment provider adapter (Stripe or otherwise) — only the contract shape and two offline adapters (cash-on-delivery, bank transfer) are built now. The contract is designed to accommodate a real provider later without changing its shape.
- Confirming a `PENDING` payment (e.g., staff marking a bank transfer as received, or cash collected on delivery) over HTTP — a domain-layer status transition is designed for, but the HTTP surface for it is a later prompt.
- The `Order` domain itself — `Payment.orderId` is a forward reference to a domain that does not exist yet (see §6).
- Checkout-level idempotency (a customer double-clicking "pay" before an Order even exists) — that is Checkout's responsibility via an idempotency key at order-creation time, not this domain's; this domain's guarantee starts only once a `Payment` row is being created against a real, already-created order.

---

## 2. Core entity: Payment

```
Payment
├── id
├── orderId             plain string — cross-domain by id toward a domain
│                       that doesn't exist yet (Order). See §6.
├── method              string, free-form — "cash_on_delivery",
│                       "bank_transfer", "card_stripe", anything —
│                       NOT a fixed enum, deliberately extensible for
│                       future providers, same posture Address's
│                       carrierCode already takes toward carriers
├── amount              Money
├── status              PENDING | CAPTURED | FAILED
├── providerReference   nullable string — an opaque id from whatever
│                       adapter processed it (e.g. a real future
│                       Stripe charge id); null for offline methods,
│                       which have none
├── failureReason       nullable string — populated only when
│                       status = FAILED
├── attemptedAt         nullable DateTimeImmutable — ADDED during
                        Checkout implementation, not part of the
                        original design (see the amendment note in §1).
                        Records WHEN THE ADAPTER ANSWERED — deliberately
                        the narrower, honest claim: "resolved" would
                        imply the payment is finished, but for cash-on-
                        delivery/bank-transfer nothing is finished the
                        moment the adapter answers, the money still
                        hasn't arrived. Null from creation until
                        recordAttemptResult() runs. This is NOT merely
                        crash-detection scaffolding — it answers a real
                        support question no other field can: "when did
                        we learn this payment failed?" Separates two
                        states status alone cannot: status PENDING +
                        attemptedAt NULL means the charge attempt never
                        completed (crash, timeout, a Phase 2 that never
                        ran) and needs a retry; status PENDING +
                        attemptedAt SET means a normal offline order
                        genuinely awaiting the customer's money and
                        needs a merchant confirmation (§7), not a retry.
├── confirmedAt         nullable DateTimeImmutable — ADDED with the
│                       offline confirmation (order-lifecycle-design.md
│                       §4.1). Records WHEN A MERCHANT SAW THE MONEY
│                       ARRIVE — the half no adapter can answer for a bank
│                       transfer or a cash-on-delivery order — written
│                       once by Payment::confirm() and never cleared.
│                       NOT a fourth PaymentStatus, deliberately (§7.3,
│                       and PaymentStatus's own docblock): the row is the
│                       record of what the adapter answered, and a
│                       confirmation that moved `status` would silently
│                       widen captured_order_id's own double-capture
│                       invariant instead of leaving it meaning what §5.1
│                       documents.
└── voidedAt            nullable DateTimeImmutable — ADDED with the void
                        (order-lifecycle-design.md §7.3). Records WHEN THE
                        ORDER'S REQUIREMENT SHRANK: the customer was never
                        told a smaller amount, so what is owed changes by
                        APPENDING A NEW PAYMENT ROW, and this column is
                        what says the old row is no longer the order's
                        current payment. Written once by Payment::void(),
                        never cleared. Only ever set on a pending,
                        unconfirmed row, so a voided row keeps contributing
                        NULL to both captured_order_id and
                        settled_order_id and cannot interact with either
                        unique index (§5.1).
```

No `priority`/reservation concept here — mirrors how `inventory-domain-design.md` deliberately has none either; a `Payment` attempt either captures or it doesn't, no holding state beyond `PENDING`. **This remains true even with `attemptedAt` added** — `attemptedAt` records *when* an attempt's outcome became known, not a new outcome an attempt can reach; the attempt itself still only ever captures or doesn't, exactly as this sentence originally said.

**`status`, `attemptedAt`, `confirmedAt` and `voidedAt` are four different facts, not four ways of saying one** — what the adapter answered, when it answered, when a merchant recorded the money as received, and when the order's requirement was called off. Neither of the two new ones is a `PaymentStatus`, on `PaymentStatus`'s own argument echoed in §7.3 below: an attempt either captures or it doesn't, and a confirmation or a void is a *human* fact recorded on a row the adapter already answered, so folding either into `status` would replace that answer instead of sitting beside it (`order-lifecycle-design.md` §4.2).

---

## 3. Core entity: PaymentRefund

> **Extended (2026-10-02), `shipping-domain-design.md` §7.2.** A refund also stores its breakdown (goods, shipping, deduction and its reason, total), an operation key, and — for offline methods — the states OWED then PAID_OUT instead of COMPLETED at creation. The status described below ("COMPLETED immediately" for an offline method) is the state of the code today and is superseded by that model.

```
PaymentRefund
├── id
├── paymentId           plain string — the specific Payment being
│                       corrected. Scoped to the PAYMENT, not the
│                       Order — confirmed against Stripe's own
│                       Refund object, which references a charge/
│                       payment_intent, never an order directly. This
│                       domain does not validate that the referenced
│                       Payment is actually CAPTURED at construction
│                       time — same cross-domain-by-id posture
│                       PromotionScope already takes toward ids it
│                       references; enforcing that invariant is the
│                       caller's/an application service's job (see
│                       §5.2 for why it can't be a pure DB constraint
│                       here anyway)
├── amount              Money — the actual amount returned; may be
│                       partial, confirmed as a normal real-world
│                       case by both WooCommerce and Stripe
├── reason              nullable string — free text (e.g. "defective",
│                       "wrong item", "goodwill"). Free text rather
│                       than Stripe's fixed fraud-oriented enum
│                       (duplicate/fraudulent/requested_by_customer)
│                       deliberately — this is a merchant/staff tool
│                       first, not a fraud-detection pipeline; a
│                       future iteration could add a stricter enum
│                       if that need materializes. Its first real
│                       writer is designed in `order-lifecycle-design.md`
│                       §7.3 — the `PaymentRefund` a cancellation or a
│                       return writes (that document's §10 stage 6);
│                       nothing else in the codebase writes one.
├── refundedBy          nullable string — the staff/Account id who
│                       authorized this refund, null if issued
│                       automatically/by-API. Added specifically
│                       because of WooCommerce's own
│                       refunded_by field, confirmed via direct
│                       research rather than assumed — a real,
│                       useful audit-trail fact for a
│                       POS-capable store, not present in the
│                       original draft
├── status              PENDING | COMPLETED | FAILED — a refund
│                       against an online method may itself round-
│                       trip through a provider and can fail; a
│                       refund against an offline method (cash
│                       physically handed back, a bank transfer
│                       sent back) has no external system to fail
│                       against and is COMPLETED immediately
└── failureReason       nullable string — populated only when
                        status = FAILED
```

---

## 4. The PaymentMethodAdapter contract — the actual boundary

```php
interface PaymentMethodAdapter
{
    public function charge(Money $amount, PaymentContext $context): PaymentAttemptResult;

    public function refund(Payment $original, Money $amount, PaymentContext $context): PaymentRefundAttemptResult;
}
```

Checkout (once it exists) calls only this — never a concrete provider directly, never branches on "is this online or offline." `PaymentAttemptResult`/`PaymentRefundAttemptResult` carry exactly what a `Payment`/`PaymentRefund` row needs to be constructed from (status, providerReference/failureReason or the refund equivalents) — the HTTP/orchestration layer persists a row from whichever result the adapter returns, the same "adapter computes, caller persists" separation `PromotionValidator`/`PromotionDiscountCalculator` already keep from their own callers.

V1 ships exactly two adapters, both deterministic, neither calling any external system:
- **`CashOnDeliveryPaymentMethodAdapter`** — `charge()` always returns `PENDING`, no `providerReference` (payment happens physically at delivery, confirmed later — see §7). `refund()` always returns `COMPLETED` immediately (a physical cash handback has no external system to round-trip through).
- **`BankTransferPaymentMethodAdapter`** — `charge()` always returns `PENDING` (waiting for the transfer to arrive, confirmed manually later — see §7). `refund()` always returns `COMPLETED` immediately, same reasoning.

A real online provider adapter (Stripe or otherwise) is not built in V1 — see §7 — but the contract's shape does not need to change to accommodate one later: `charge()` would return `CAPTURED`/`FAILED` immediately or `PENDING` pending async confirmation (e.g. a webhook), with a real `providerReference`; `refund()` would return `PENDING`/`FAILED`/`COMPLETED` depending on the provider's own round-trip, exactly matching Stripe's own confirmed `pending`/`succeeded`/`failed` refund statuses.

---

## 5. Two invariants, two different strengths of guarantee — stated honestly

### 5.1 "At most one CAPTURED Payment per order" — genuinely DB-enforced

MySQL has no native syntax for "unique only when a condition holds" (unlike Postgres's partial indexes). The standard, portable MySQL mechanism: a `STORED` generated column, `captured_order_id`, computed as `CASE WHEN status = 'captured' THEN order_id ELSE NULL END`, with a plain `UNIQUE` index on that generated column. MySQL treats multiple `NULL`s in a unique index as non-conflicting, so only rows that are actually `CAPTURED` ever compete for uniqueness on `order_id` — a `PENDING` or `FAILED` row (however many exist for the same order, across retries) contributes `NULL` and never collides with anything. This makes double-capture for the same order **physically impossible at the database engine level**, immune to two concurrent requests racing past an application-level check-then-act — the direct, literal answer to the domain owner's "never charge a customer twice" requirement.

**The same mechanism was extended, not replaced, when the offline confirmation arrived.** `payments` now carries a SECOND stored generated column beside this one — `settled_order_id`, computed as `CASE WHEN status = 'captured' OR confirmed_at IS NOT NULL THEN order_id ELSE NULL END`, with its own unique index (`pay_settled_order_unique`) — so "at most one settled Payment per order" covers a merchant's confirmation of an offline payment too, while this index keeps meaning exactly what it says above rather than having its expression rewritten under existing rows. Read the two together: two narrow, separately testable constraints, neither a looser duplicate of the other (`order-lifecycle-design.md` §4.4). And `voided_at` participates in neither: a void is only possible on a pending, not-yet-confirmed row, so a voided row contributes `NULL` to both indexes and cannot interact with either (that document's §7.3).

### 5.2 "Sum of PaymentRefund amounts for a Payment never exceeds that Payment's captured amount" — NOT purely DB-enforceable, stated honestly rather than overclaimed

Unlike §5.1's uniqueness, this is a SUM-aggregate constraint across multiple rows — MySQL's `CHECK` constraints (available since 8.0.16) cannot reference other rows or run aggregates, so there is no equivalent single-column trick here. This invariant requires a transactional guarantee instead: whoever creates a `PaymentRefund` must do so inside a database transaction that first locks the parent `Payment` row (`SELECT ... FOR UPDATE`), computes the sum of existing `PaymentRefund`s against it, and only proceeds if the new refund would not exceed the captured amount — all within that same locked transaction, never as a separate read-then-write step outside one. This is a weaker guarantee than §5.1's (it depends on every future caller using the transaction correctly, rather than being impossible to violate by construction) — exactly the same honest posture `pricing-persistence-domain-design.md` §4.7 already takes toward its own not-fully-DB-enforceable uniqueness case, rather than pretending otherwise.

---

## 6. Cross-domain contracts

- **`Order` → `Payment`:** `orderId` is a forward reference to a domain that does not exist yet. This is deliberate, not an oversight — the same relationship `Address.accountId` already had toward `Account` before this design existed, except here the referenced domain itself is still unbuilt. No FK is possible until `Order` exists; `orderId` is a plain string, cross-domain by id, same convention as everywhere else in this project.
- **`Payment` → `PaymentRefund`:** by plain id, within this same package — the only genuinely internal relationship in this document.
- **Payment never depends on Cart, Pricing, Promotions, or Catalog.** A future Checkout orchestrates Payment alongside Cart/Address/Order — this domain stays exactly as narrow toward its neighbors as every other domain in this project does toward theirs.

---

## 7. What's deliberately not implemented in this iteration

- Any real online payment provider adapter (Stripe or otherwise) — contract shape only, two offline adapters built (§4).
- Any HTTP surface at all — domain + persistence only, matching how every other domain in this project has been staged.
- A confirmation mechanism for moving a `PENDING` payment (bank transfer received, cash collected on delivery) to `CAPTURED`: **the confirmation exists now, and it deliberately does not move the status.** `Payment::confirm()` writes `confirmed_at` — the instant a merchant recorded the money as received — and `Payment::isSettled()` (`status === CAPTURED || confirmedAt !== null`) is the single predicate that means "the money is held" everywhere a rule or a screen needs to ask. The operation around it is `App\Services\OrderPaymentConfirmer::confirm()`: one transaction that locks the order row, one `payment_confirmed` row in the order's own history, and one `order.payment_confirmed` hook after the commit. The status is left exactly as the adapter answered it, so §5.1's `captured` double-capture invariant keeps meaning what it documents instead of silently widening to cover offline rows — the rejected alternative, argued in `order-lifecycle-design.md` §4.2/§4.4. The panel action that will call this service, and the order transitions that ride with it, are that document's own scope (§8.1, §5.2); this domain gains no HTTP surface from any of it.
- Checkout-level idempotency for a double-clicked "pay" button before an Order exists — explicitly Checkout's responsibility, not this domain's (see §1).
- The `Order` domain itself, and therefore any real, end-to-end payment flow — this document defines Payment/PaymentRefund's own shape only.
- A stricter `reason` enum on `PaymentRefund` (Stripe's fraud-oriented duplicate/fraudulent/requested_by_customer) — free text for V1, noted in §3 as a possible future addition if the need materializes, not assumed now.

---

## 8. Testing plan

- `Payment` domain unit tests: construction/validation for all three statuses; `failureReason` only meaningful (or only settable) when `FAILED`; `providerReference` nullable for offline methods.
- `PaymentRefund` domain unit tests: construction/validation for all three statuses; `failureReason` only meaningful when `FAILED`; `refundedBy` nullable; a zero or negative `amount` rejected.
- Repository Feature tests (real MySQL): save/round-trip both entities; a real, direct `SHOW CREATE TABLE` confirmation that the `captured_order_id` generated-column unique index exists exactly as described in §5.1 (not assumed from the migration file alone); a real test that attempting to save a second `CAPTURED` `Payment` for the same `orderId` throws a real database-level uniqueness violation, not an application-level rejection — proving §5.1's guarantee is genuinely enforced by the engine, not simulated in PHP.
- The `settled_order_id` pair, added beside that one for the same stated reason (§5.1): a real `SHOW CREATE TABLE` confirmation that the second generated column's expression and its `pay_settled_order_unique` index exist exactly as described — asserted against the engine *and* asserted not to have widened the `captured_order_id` expression — plus real insertion tests proving the engine refuses a second settled row for one order (a `confirmed` one; a `captured`/`confirmed` pair, in both arrival orders) while any number of `pending`, `failed` and voided rows coexist. `findSettledForOrder()`, `Payment::isSettled()` and the generated column are cross-checked against each other row by row, so the three expressions of one rule cannot drift apart silently. Plus the void's own pair: both new timestamps round-tripping through the repository (and reading back `NULL` when unset), and `Payment::void()` refusing an unanswered, captured, failed, already-confirmed or already-voided row.
- `PaymentMethodAdapter` tests for both V1 adapters: `CashOnDeliveryPaymentMethodAdapter::charge()` always returns `PENDING` with no `providerReference`; its `refund()` always returns `COMPLETED`; same pair of assertions for `BankTransferPaymentMethodAdapter`.
