# Order Editing Design

**Status:** Draft v1 — design only, nothing implemented. This document defines
how a merchant edits an order **before it ships**: what may change, how the
money and stock consequences are computed and written, how the change stays
visible next to what was originally placed, and the staged build order. No
code, no migration, and no UI change is part of this pass.

**Builds on:** `order-lifecycle-design.md` — read in full before this
document; it is not restated here except where this document narrows or
extends a specific rule, always by section number. Everything in that
document's §1–§8, R1–R11 and §11's 20 permanent commitments is **built, real
code** as of this pass (stages 1 through 7c-3), not a plan — this document
treats it the same way `order-lifecycle-design.md` itself treated
`checkout-domain-design.md`: as ground truth to extend, never to second-guess.
`operational-sales-domain-design.md` §3.2 (append-only — the rule this whole
document has to fit inside), §3.4/§3.13 (the REFUND field set this document
reuses rather than duplicates). `payment-domain-design.md` §5 (the constraints
the money rule stays inside). `checkout-domain-design.md` §3/§8
(`CheckoutOrchestrator`'s own write shape — editing is designed as "a small,
later re-checkout of the affected lines," not a new invention).
`inventory-domain-design.md` (the same `StockLevelRepository` methods every
prior stage already uses). `staff-access-domain-design.md` (`ORDER_MANAGE`'s
own description already names "editing order information" as its job — this
document is what finally claims it). `order-context-design.md` (D12, invoice
details) — that document's own code is confirmed **not built** (§0 item 8
below); this document only states a forward dependency on it.

**Origin:** `order-lifecycle-design.md` §9 says plainly: *"Order editing. No
lines, quantities, prices or addresses are edited after placement... that
remains unbuilt and unclaimed here."* This is the document written to that
gap, for the one window where it is safe: before the goods leave and before
money is held.

---

## 0. What is true today (verified in this pass)

1. **`Order` is exactly as immutable as the lifecycle design left it.** 22
   `private readonly` constructor properties plus `$status` — the only
   mutable one, moved by five real transition methods (`confirm()`/`ship()`/
   `deliver()`/`cancel()`/`refund()`, `Order.php:411-455`). `subtotal`,
   `discount`, `total`, `appliedPromotionCode` and the full 11-field delivery
   snapshot (`addressId` through `settlement`) are all `readonly`, with no
   setter anywhere in the file.
2. **`SaleLine` already carries a real REFUND shape**, built in stage 6b-i:
   `quantityReturned`, `defaultRefundAmount`, `actualRefundAmount`,
   `displayPriceAtReturn`, `returnedBy`, `returnedByName`, `returnReason`, all
   written only by the strict `createRefund()` factory
   (`SaleLine.php:638-649`), never by `create()` (`:429-449`). A line already
   carries `promotionDiscountShare` and a separate `discretionaryDiscount` —
   two fields, never merged — plus `regularUnitPrice`/`finalUnitPrice`/
   `netPaidAmount`. `createRefund()`'s own built row keeps `quantity` at the
   **original** line's quantity, never `$quantityReturned` — §14 Q6's own
   resolution, already live code, not a future decision this document has to
   make again.
3. **The only carrier concept is still `carrierCode`/`pickupPointReference`**,
   required for `PICKUP_POINT` and forbidden for `STREET_ADDRESS`
   (`assertFieldsMatchDeliveryType()`, `Order.php:158-202`). No tracking
   number field exists anywhere — a repository-wide search finds nothing real
   (every hit is either an unrelated Filament table-selection variable or
   prose stating tracking is out of scope). §3 below is what changes this.
4. **`Order.total` had no shipping component when this document was written**
   — `total = subtotal - discount`. **Superseded by shipping stage 2**
   (`shipping-domain-design.md` §7): `Order` now carries `shippingMinor` and
   `total = subtotal - discount + shipping`. An edit does not re-price
   shipping — it carries the order's stored shipping through unchanged
   (`reviseTotals` computes the total from it) — and the pending-payment
   comparison and reissue use the resulting total, shipping included.
5. **A return only ever runs from `shipped`/`delivered`** —
   `OrderStatusChanger::recordReturn()`'s own `legalStartingStatuses`
   (`OrderStatusChanger.php:226`). E1's own "editing only at
   `placed`/`confirmed`" is therefore **guaranteed non-overlapping with a
   return by the real status matrix**, not merely by this document's own
   intent: the two operations can never both be legal on the same order at
   the same time.
6. **`PromotionRedemption::release()`/`isReleased()` are real**, `release()`
   throws `LogicException` on a second call
   (`PromotionRedemption.php:134-143`), and the only production call site
   today is `OrderStatusChanger`'s own cancel path
   (`OrderStatusChanger.php:461`), gated on reaching `CANCELLED`. §7 below
   states precisely how editing adds a second, narrower call site and which
   sentences of `order-lifecycle-design.md` that makes stale (report only;
   that document stays unedited by this pass).
7. **Every collaborator this document reuses is real, and their locking
   convention is not perfectly uniform** — worth stating precisely rather
   than glossing over: `OrderStatusChanger` locks the order via
   `OrderRepository::findByIdForUpdate()` as its *very first* statement, in
   both of its two worker shapes. `OrderPaymentConfirmer::
   confirmWithinOpenTransaction()` reads the **Payment** row first (the order
   id to lock is only known from it) and locks the order *second* — a
   deliberate, documented exception to "order first," not a violation of
   §11 item 13 (payments are still locked strictly after the order,
   `Payment` here is read, not locked, before the order). `OrderRefunder`,
   `ReturnGoodsRecorder` and `CumulativeRefundShareCalculator` never call
   `findByIdForUpdate()` themselves at all — all three assume they are
   already inside a caller's open transaction, under a lock the caller
   already holds, and fire no hook of their own; only `OrderStatusChanger`
   opens the outer transaction and fires hooks after it commits. Editing
   follows `OrderStatusChanger`'s own shape exactly (§5 below): lock the
   order first, call the same money/stock collaborators from inside that one
   lock, exactly as `cancel()`/`recordReturn()` already do.
8. **`order-context-design.md` exists and is fully unbuilt.** It designs
   `order_attributions`, `order_invoice_details` and three new nullable
   columns on `orders` (`terms_accepted_at`, `terms_version`,
   `confirmation_requested`) — a repository-wide search for every one of
   those names, in migrations and in application code alike, finds nothing.
   §8's E7 entry is written as a forward dependency only, on the strength of
   that document's own design, not on any table this pass can read or write
   today.

---

## 1. What may be edited, and when (E1/E2)

**Editing is legal only while `Order.status` is `placed` or `confirmed`** —
never at `shipped`, `delivered`, `cancelled` or `refunded` (E1, owner
decision, unchanged). The guard is a fact the aggregate itself can see, so it
lives on `Order`, the same posture `transitionTo()` already takes for the
matrix itself (§2 below).

**What may change** (E2, owner decision, unchanged):

- add a product/variation with a quantity;
- remove a line entirely;
- change a line's own quantity (up or down);
- give a line a manual discount — the owner's own example, unit price 48.00,
  discount 3.00, final 45.00 — **never a free price override**: the regular
  unit price is never typed in, only a discount subtracted from it, the same
  "no price override anywhere outside a price list" posture
  `catalog-domain-design.md` already holds for the storefront side;
- add, remove or replace the promotion code;
- change the delivery method and destination (recipient, phone, street
  address or pickup point) and set/change the courier (§3);
- **customer email is never editable** — the one field E2 names explicitly as
  off-limits, and this document adds nothing that would let an edit read as
  "this order was placed by someone else."

**Not editable, ever, by this document:** the customer's own account/guest
identity, the placed-at instant, the channel, and (non-goals) shipping price,
top-ups, refunds-inside-an-edit, and anything after `shipped` — a return
handles that, and the two operations cannot legally overlap (§0 item 5).

---

## 2. The domain half: two new `Order` mutators, and one small new table

**`order-lifecycle-design.md` §5.1's own pattern, extended by exactly two
methods** — never a generic setter, one narrow method per kind of change,
each delegating to a shared guard the five status mutators do not need to
duplicate:

```php
public function reviseTotals(Money $subtotal, Money $discount, Money $total, ?string $appliedPromotionCode): void
public function reviseDelivery(
    OrderDeliveryType $deliveryType,
    string $recipientName,
    string $phone,
    ?string $country,
    ?string $city,
    ?string $postalCode,
    ?string $addressLine1,
    ?string $addressLine2,
    ?string $carrierCode,
    ?string $pickupPointReference,
    ?string $settlement,
): void
```

Both are **one-shot per edit, never partial** — `reviseTotals()` always
receives all three money fields together (they are interdependent; there is
no such thing as editing just `discount`), and `reviseDelivery()` always
replaces the *whole* delivery snapshot atomically, running the exact same
`assertFieldsMatchDeliveryType()` validation `create()` already runs (one
implementation, called from three places now instead of two — `create()`,
`reconstituteFromStorage()`'s own trust boundary is unaffected, and
`reviseDelivery()`). Both throw a new `OrderNotEditableException` (the same
shape as `InvalidOrderTransitionException` — carries the current status as a
value, not only inside a message) when `$status` is not `placed`/`confirmed`,
via one shared private guard:

```php
private function assertEditable(): void
{
    if (! in_array($this->status, [OrderStatus::PLACED, OrderStatus::CONFIRMED], true)) {
        throw OrderNotEditableException::because($this->status);
    }
}
```

**`$status` itself is untouched by either mutator** — an edit is not a
transition (§3 item 1's three-statuses rule again: an edit changes *what was
ordered*, never *has the merchant fulfilled it*), so neither method appears
in `OrderStatus::canTransitionTo()`'s matrix and neither is covered by the
stage-1 drift test — they need no entry there, the same way `Payment::
confirm()` needs none.

### 2.1 What is preserved — a new, tiny, write-once table, not a mutation of `Order` itself

**The problem this section exists to solve:** `EloquentOrderRepository::
save()` rewrites all of `Order`'s columns unconditionally (§11 item 2) — so
once `reviseTotals()`/`reviseDelivery()` run and the row is saved, the
*original* subtotal/discount/total/promo code/delivery snapshot are gone from
`orders` itself. But "what a future confirmation email told the customer"
has to stay visible (a real requirement, not a nicety — a merchant fielding
"you charged me 45, the email said 48" needs the email's own numbers on
screen). `order_events` is deliberately not the place for this: it has no
generic snapshot column, and adding one would be exactly the "no derived
column, anywhere" §11 item 11 forbids — a snapshot is not a derived value,
but a JSON blob duplicating fields that already have real, typed homes.

**Recommendation: `order_placement_snapshots`, one row per order, written
once, at placement, never updated.** A `Migration` shape as `orders` and
`payments` already have — the FK to `orders` is real and `restrictOnDelete()`
(the same posture every other order-history table already takes):

```php
Schema::create('order_placement_snapshots', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_id')->unique()
        ->constrained('orders', indexName: 'ops_order_id_foreign')
        ->restrictOnDelete();
    $table->unsignedBigInteger('subtotal_minor');
    $table->string('subtotal_currency', 3);
    $table->unsignedBigInteger('discount_minor');
    $table->string('discount_currency', 3);
    $table->unsignedBigInteger('total_minor');
    $table->string('total_currency', 3);
    $table->string('applied_promotion_code')->nullable();
    $table->string('delivery_type');
    $table->string('recipient_name');
    $table->string('phone');
    $table->string('country')->nullable();
    $table->string('city')->nullable();
    $table->string('postal_code')->nullable();
    $table->string('address_line_1')->nullable();
    $table->string('address_line_2')->nullable();
    $table->string('carrier_code')->nullable();
    $table->string('pickup_point_reference')->nullable();
    $table->string('settlement')->nullable();
    $table->timestamp('created_at');
});
```

Written **once**, by `CheckoutOrchestrator` itself, in the same placement
transaction that already writes the `Order` row — a small, real, additive
change to that class, out of this pass's own code scope but named here
because the schema depends on it existing. `Order`'s own 22 fields keep their
existing meaning **unchanged**: after this pass they answer *the order's
current state*, exactly as they do today, while `order_placement_snapshots`
answers *what was originally placed* — two homes for two questions, the same
"three-statuses" discipline applied one level down. Never updated, never
deleted while the order exists (no delete path exists for `orders` either);
admin UI reads it once, alongside the current row, the same "read what was
written, never recomputed" posture §3 item 6 already states for
`order_events`.

### 2.2 `orders.edit_revision` — the one new column on `orders` itself

One `unsignedInteger`, `default(0)`, incremented by exactly 1 inside the same
locked transaction as a successful edit (§5's own compare-and-set). Answers
two needs at once: D5's optimistic concurrency (the edit form carries the
revision it was built from; a stale submission is refused before anything
else runs) and a human-readable "this order has been edited N times" fact for
the admin view. `0` means "never edited" — reconstructible without a query,
since `order_placement_snapshots` and `orders` agree exactly when
`edit_revision = 0`.

---

## 3. Courier and delivery model (D3)

**The exclusivity rule narrows, and this document says so plainly rather than
smuggling it in.** Today `carrierCode` is required for `PICKUP_POINT` and
**forbidden** for `STREET_ADDRESS` (`assertFieldsMatchDeliveryType()`). E2
asks for "set/change the courier" on any order, including a street-address
one — a real, new fact `Order` cannot represent today. `pickupPointReference`
and `settlement` stay exactly as forbidden for `STREET_ADDRESS` as they are
now (a location identifier is still meaningless for a home delivery); only
`carrierCode`'s own rule changes:

| Delivery type | `carrierCode` | `pickupPointReference`/`settlement` |
|---|---|---|
| `PICKUP_POINT` | required (unchanged) | required (unchanged) |
| `STREET_ADDRESS` | **optional** (was: forbidden) | forbidden (unchanged) |

**Compatibility consequence, stated honestly:** every existing
`STREET_ADDRESS` row already has `carrierCode = NULL` (the old rule forced
it), which satisfies "optional" without a backfill — this is a pure
*relaxation*, not a breaking change, and no migration touches existing data.
The one real code change is inside `assertFieldsMatchDeliveryType()` itself:
the `STREET_ADDRESS` branch's blanket "`carrierCode` must be null" check is
dropped, the `PICKUP_POINT` branch is untouched.

**Tracking number (E6): one new nullable column, `orders.tracking_number`.**
Placed beside `carrierCode` for the same reason `carrierCode` itself sits
directly on `Order` rather than in a side table — one more fact about *how
this parcel is travelling*, no new aggregate needed. Entered or corrected
**only while `shipped`** (goods have left, the fact now exists to record; once
`delivered` it is frozen — a delivered parcel's tracking history is closed
the same way `confirmed_at` closes once set, §11 item 6's posture applied to
a shipping fact instead of a money one). One new `App\Enums\OrderEventType`
case, `TRACKING_RECORDED` — none of the six existing cases fit (not a
transition, not a money fact, not a return, not a note) — both statuses
`NULL` (nothing transitioned), `reason` carries the tracking number itself
verbatim. A correction while still `shipped` writes a **second**
`TRACKING_RECORDED` row rather than rewriting the first (append-only, same as
every other event) — the admin view shows the latest one as *the* tracking
number, exactly the "current payment" pattern §11 item 19 already
establishes for `voided_at`/reissue.

---

## 4. The ledger: how an edit writes lines without ever rewriting one (D1)

**Recommendation: (a), revisions — every edit writes fresh, append-only
lines; nothing already written is ever mistaken for a customer return.**

### 4.1 One new `SaleLineType` case: `EDIT_REVERSAL`

Adding a sixth case to `SaleLineType` (`SALE`, `RESERVATION`, `REFUND`,
`SHIPPING`, `INSTALLMENT_PAYMENT` today) rather than reusing `REFUND` with a
flag — the requirement is explicit ("never mistaken for a customer return in
reports"), and a `type` column a report already filters on is a cleaner,
zero-ambiguity signal than a second boolean a report would have to remember
to check. **The new type reuses the *entire* existing REFUND-shaped column
set — no new `SaleLine` columns, no new migration for the table itself**:
`quantityReturned` holds the quantity removed by this edit,
`defaultRefundAmount`/`actualRefundAmount` hold the line's own net-amount
reduction (for ledger/report consistency — see §4.3 for why this is never a
`PaymentRefund`), `returnedBy`/`returnedByName` hold the **staff member** who
made the edit (a deliberate, narrow reuse of a customer-facing name for a
staff fact — justified purely by column economy, and safe *because* `type`
already tells a reader which meaning applies). `SaleLine::createRefund()`
itself needs no change beyond accepting the new type as a legal
`$type`/context — it already asserts `originatingLine` is type `SALE`, which
holds unchanged for an edit reversal.

### 4.2 Every edit writes fresh lines — never a partial rewrite of what exists

**One rule, applied uniformly to every kind of E2 change:** the affected
original line is **fully** reversed (an `EDIT_REVERSAL` line for its own
*entire remaining* quantity, not just the delta), and — if anything of that
line survives the edit — a **brand new** `SALE` line is written for the new
state (new quantity, new discount, or both). Nothing is ever "half-edited in
place":

| Edit | What is written |
|---|---|
| Add a line | one new `SALE` line, no originating line |
| Remove a line | one `EDIT_REVERSAL` line, full remaining quantity |
| Change a line's quantity (5 → 3) | one `EDIT_REVERSAL` (full 5) + one new `SALE` line (qty 3) |
| Give a line a manual discount | one `EDIT_REVERSAL` (full quantity) + one new `SALE` line (same quantity, new `discretionaryDiscount`/`netPaidAmount`) |

**Why full reversal-and-replace, not a partial delta:** a later return's own
cumulative-share math (`CumulativeRefundShareCalculator::shareFor()`) divides
by the line's own `originalQuantity` and multiplies by the line's own
`netPaidAmount` — both fixed at the line's *own* construction and never
correctable afterward (§0 item 1's immutability, unchanged, deliberately not
weakened for this feature). If an edit only recorded a *delta* against the
original 5-unit line, a later return of one of the remaining 3 units would
still divide by 5 and multiply by the *original* `netPaidAmount` — the one
computed for 5 units' worth of the pre-edit total — silently under- or
over-crediting the return the moment the order's own total changed. Writing
a **fresh** `SALE` line for the post-edit state gives that line its own
correct `originalQuantity` (3) and its own correct `netPaidAmount` (the new
line's own share of the recomputed total, §4.4) — a later return against
*this* line uses exactly the numbers it should, with zero special-casing in
`CumulativeRefundShareCalculator` itself.

**One additive, backward-compatible change to `SaleLine::create()`:** a new,
optional, last-and-defaulted parameter, `?string $originatingSaleLineId =
null` — the exact shape `unitCost`/`originatingReservationLineId` already
use, every existing call site keeps compiling unchanged. Set only when a
fresh `SALE` line replaces a specific prior one (the quantity-change and
discount cases above), purely for **lineage/audit** — "this line replaced
that one" — and deliberately **not** load-bearing for any refund-share
calculation, which always reads the *current* line's own fields directly.

### 4.3 The money on an `EDIT_REVERSAL` line is never a `PaymentRefund`

E3's own rule makes this simple rather than subtle: **editing is legal only
while the payment is not settled** (§6). No money has been captured for
*any* line at edit time, so an `EDIT_REVERSAL` line's `defaultRefundAmount`/
`actualRefundAmount` record *money never charged for these units*, purely for
ledger/report correctness (so "current lines" totals, and any later return's
own cumulative math, net out correctly) — never money handed back, because
none was ever taken. §6 states the real money consequence: void the pending
payment and reissue one for the new, recomputed total.

### 4.4 "Current lines of the order" — one read, used everywhere

**Definition, stated once:** for every `SALE` line ever written for this
order — the placement transaction's own lines, plus every `SALE` line written
by a later edit — compute `remaining = quantity - sum(EDIT_REVERSAL against
it) - sum(REFUND against it)`. A line is *current* when `remaining > 0`.
These two subtracted sums are **temporally disjoint for any single line's
whole lifetime** — editing is legal only at `placed`/`confirmed`, a return
only at `shipped`/`delivered` (§0 item 5) — so at most one of them is ever
non-zero for a given line, and the formula never needs to guess which
applies.

**Enumerating "every `SALE` line ever written for this order" needs one new
join path, not a new FK.** `Transaction` carries no `order_id` today —
`Order` points at *one* placement transaction, and every later transaction
(a return's, and now an edit's) is reachable only through the `order_events`
row that references it (`transaction_id`). This already works for returns;
an `EDITED` event (§7) extends the exact same path: "every transaction for
this order" = the placement transaction, plus every transaction named by an
`order_events` row (`RETURNED` or `EDITED`) for that order — no new foreign
key, one more `UNION`/`whereIn` in the same read `OrderAdminReader` already
performs.

### 4.5 `CumulativeRefundShareCalculator` needs no change; `SaleLineRepository` gains one new read

**The pure formula itself is untouched** — `shareFor(netPaidAmount,
originalQuantity, alreadyReturned, thisReturn)` still just computes
`floor(netPaidAmount × n / originalQuantity)`; it never knew about edits and
does not need to. What **does** need a change, stated explicitly per this
document's own instruction: `ReturnGoodsRecorder`'s own R7 read. Today
`SaleLineRepository::sumQuantityReturnedForOriginatingLine()` sums only
`REFUND`-type lines. Once an edit can write `EDIT_REVERSAL` lines against a
*replacement* `SALE` line (§4.2's quantity-change case — the replacement line
itself can later be edited again, before it ships), R7's own "how much
remains" read must also subtract any `EDIT_REVERSAL` already written against
*that* line, or a second edit could ask for more than actually remains. One
new, narrowly-named contract method —
`sumQuantityEditedAwayForOriginatingLine(string $originatingSaleLineId): int`
— mirroring the existing one exactly, summed alongside it wherever "remaining"
is computed (§4.4's own formula is exactly this sum, generalised).

### 4.6 Profit

Unchanged in shape: profit stays computed on **net**, from each `SALE` line's
own `profit`/`netPaidAmount`, the same rule every prior stage already
applies. A fresh replacement line's own `profit` is computed fresh, from its
own new quantity and price — never inherited or prorated from the line it
replaced.

---

## 5. The unit of work (D5)

**One edit = one transaction, one revision — the same shape §5.2 already
established, narrowed to what editing actually needs:**

1. **Validate the submitted form before any lock** (§5.2 step 1's own
   posture): a non-empty line set, non-negative quantities, a manual discount
   that does not exceed the line's own regular price. `InvalidArgumentException`
   for a malformed call, exactly as every prior stage's own step 1 does.
2. **Open one `DB::transaction()`, lock the order first**
   (`OrderRepository::findByIdForUpdate()`, §0 item 7's own convention).
   Refuse an unknown order the same way every prior service already does.
3. **Compare-and-set on `edit_revision`**: the form carries the revision it
   was built from; if the locked row's own `edit_revision` no longer matches,
   refuse — a stale edit, exactly the "two operators, one tab" case §8.2's
   own closing paragraph already describes for status actions, now applied to
   a form instead of a button.
4. **`assertEditable()`** (§2): refuse unless `placed`/`confirmed`.
5. **E3's money gate**: read the order's payments (`PaymentRepository::
   findByOrderId()`, the exact read `OrderRefunder`/`OrderStatusChanger`
   already perform); if any is `isSettled()`, refuse — the edit action is not
   even offered at this state (§8), and the service refuses truthfully if
   reached anyway (a stale page, the same "the panel offers, the service
   refuses" posture §8.2 already states).
6. **Compute the new lines** (§4.2), the new totals and the redistributed
   promotion discount (§7) — with the **same** pure calculation used by the
   admin UI's own preview (§8): one function, two callers, no duplicated
   math, the same "one implementation, no drift" rule `checkout-domain-design.md`
   already applies to `PromotionDiscountCalculator`.
7. **Stock** (§6): decrease for every added/increased unit (aborts the whole
   edit on `InsufficientStockException`); increase unconditionally for every
   removed/decreased unit.
8. **Write the lines** (§4), call `Order::reviseTotals()`/`reviseDelivery()`
   as needed, save the order with its incremented `edit_revision`.
9. **The money step** (§6): void-and-reissue via the *same* `OrderRefunder`
   machinery already built for R8(b), called from inside this same lock.
10. **One `order_events` row**, type `EDITED`, both statuses `NULL`,
    `transaction_id` = the edit's own new transaction, `reason` = the
    operator's own optional note.

**Rollback:** any exception at any step rolls back everything written in
steps 6–10 together — no partially-applied edit, the same guarantee every
prior stage's own rollback test already proves for `cancel()`/`recordReturn()`.
**Idempotency:** a retried submission of the *same* form after a successful
edit is refused by step 3 (the revision it carries is now stale) — there is
no separate idempotency key, because the revision check already is one.

---

## 6. Money and stock (E3/E4, D8's own detail)

**E3, restated exactly, because it is the one rule everything else in this
document exists to stay inside:** totals are recomputed from the resulting
lines. If the payment is **pending**: void it and append a new pending
payment for the new total — reusing `OrderRefunder`'s own R8(b) void-and-
reissue implementation directly (the same method, called with the edit's own
recomputed total in place of a return's refund share — no second
implementation). If the payment **is settled**: the order is not editable at
all, full stop — no refund-difference logic, no top-up, no second payment,
ever. **An edit that would empty the order is refused, and cancel is offered
instead** — editing never reaches zero lines by design.

**Stock (E4):** added/increased units go through `StockLevelRepository::
decrease()` — the exact call `CheckoutOrchestrator` already makes at
placement — and an `InsufficientStockException` aborts the whole edit
(nothing partially applied, §5 step 7). Removed/decreased units go back
through `StockLevelRepository::increase()` — the exact call
`ReturnGoodsRecorder` already makes, unconditionally, because the goods never
left the shelf before `shipped`. A discount-only edit touches no stock at
all.

---

## 7. Promotions (D4)

**Re-runs the same two real classes checkout already uses — no duplicate
implementation.** Adding, removing or replacing a code re-validates via
`App\Services\PromotionValidator::validate()` and recomputes the discount via
`App\Services\PromotionDiscountCalculator::calculate()`, over the **current**
(post-edit) lines, using the **same** `PromotionUsageContextAssembler::
assemble()` read checkout already performs for the account's own usage
history. The per-line `promotionDiscountShare` is never redistributed by
rewriting an existing line's field (readonly, §0 item 1) — it is simply
computed fresh, onto whichever `SALE` lines the edit itself already writes
(§4.2), exactly the same "compute once, write once" shape checkout already
has.

**A manually-discounted line's own promotion eligibility is an open
question, not a call this document makes** — see §10 Q1: whether a line
carrying a manual `discretionaryDiscount` counts as "already discounted" for
a promotion whose own scope excludes discounted items depends on a real
business rule this document has not been given.

**An edit that would make the existing code invalid is refused unless the
code is explicitly removed in the same edit** (the brief's own recommended
rule, adopted) — never silently dropped, never silently kept invalid.

**R11's own sentence needs a narrow amendment — report only, this pass edits
nothing in `order-lifecycle-design.md` itself.** Today: *"A promotion
redemption is released only when the order becomes `cancelled`"* (R11, §2.2,
restated in §11 item 20 as a *permanent* commitment). This document adds a
**second**, narrower release trigger: **a promotion code explicitly removed
during an edit**, while the order is still `placed`/`confirmed` — the exact
same "this code was spent on an order that did not happen [for this
purchase]" reasoning §7.4 already gives for a cancellation, applied to "spent
on a code the merchant is no longer applying," not to the order itself
ending. **Exactly one sentence of `order-lifecycle-design.md` is what this
touches**: R11 itself (§2.2) and its restatement in §11 item 20, both of
which currently say "only... cancellation" and would need "...or an edit that
removes the code" appended. **A code added or replaced during an edit writes
a brand new `PromotionRedemption`** — the same write checkout itself already
performs, called from inside the edit's own lock instead of checkout's.

---

## 8. Admin UX (D7)

**A dedicated `editAction()` on `OrderResource`**, following stage 7's own
established shape exactly — the shared per-line form pattern
`cancelAction()`/`recordReturnAction()` already established, `runOrderAction()`'s
existing error-mapping (extended with one new catch branch for
`OrderNotEditableException`, translated the same way `InvalidOrderTransitionException`
already is), and the same redirect-and-re-read after success (§8.3 item 5 —
never a soft refresh, for the exact reason that section already gives).

**Visible only while `canTransitionTo()`-adjacent** — not a matrix entry
itself (editing is not a transition, §2), but the same two-part visibility
shape every stage-7 action already uses: `staffHasPermission(ORDER_MANAGE)`
**and** `status in [placed, confirmed]` **and** the order's current payment is
not settled (read via the same `latestPayment` the Payment section already
reads — no new query, the same reuse `moneyPermissionClause()` already
established for cancel/return).

**The manual-discount field needs a second permission, `ORDER_DISCOUNT`
(E5)** — checked inside the edit form itself, not the action's own
`->visible()` (the *edit* action stays offered to any `ORDER_MANAGE` staff
member; only the *discount field* is disabled/hidden without `ORDER_DISCOUNT`,
the same "UX aid, not the real enforcement" posture §8.3 item 2 already
states for every other field on this page — the service re-validates
server-side regardless of what the form showed).

**Preview before save, same code path as apply (D5 step 6):** the form
computes and displays the new line list, new totals, and — when the payment
is pending — the new payment amount, **before** the confirmation modal is
submitted, using the exact function `apply` itself calls. No separate
"preview" implementation to drift from "apply."

**Query-count expectations:** reported, not guessed, when built — the same
convention every prior stage's own T5/T6 already follows (5 vs 25 lines, one
real number each, bounded rather than exact).

---

## 9. Events and hooks (D6)

**Two new `App\Enums\OrderEventType` cases**, added the same way `NOTE_ADDED`
was (one real writer needs it, not ahead of one): `EDITED` (both statuses
`NULL`, `transaction_id` = the edit's own transaction, `reason` = the
operator's own optional note) and `TRACKING_RECORDED` (§3). Both get real
translation labels in `lang/en/orders.php`/`lang/bg/orders.php`'s existing
`event_type_options` group, the same `OrderEventTypeLabelsTest`-style
parity discipline already applied to every prior case.

**One new hook, `order.edited`**, fired the same way every existing hook is —
after commit, never inside the transaction (§11 item 9, unchanged):

```
order.edited | Action | App\Services\OrderEditor (new) | (Order $order, int $revision): void
```

Added to `extensibility-design-and-hooks.md`'s own Hook Reference table in
the **same commit** as the real call site, per that table's own standing
rule — not applied by this pass (§0's own "no other file changes"; listed in
§11's landing table below).

**The customer-notification contract, described, not built:** a listener
registered on `order.edited` is where "tell the customer what changed" lives
— the hook's own payload is deliberately the aggregate plus a scalar (the
revision number), never a diff object or an email template, the same
"payload is the aggregate plus scalars, never a DTO" rule §12 already states
for every other hook. Building the actual email is a later, separate stage
(non-goal here).

---

## 10. Open questions for the owner

Only real business decisions — nothing this document could answer from
existing code or existing owner decisions:

**Q1.** Does a line carrying a manual `discretionaryDiscount` count as
"already discounted" for a promotion whose own scope excludes discounted
items? (§7)

**Q2.** Once `order-context-design.md`'s invoice details exist and an invoice
has been issued for an order, E7 says money-changing edits close — does a
**non**-money edit (delivery/courier/tracking change) still stay open after
an invoice is issued, or does issuing an invoice close editing entirely? This
document assumes the former (only *money*-changing edits close) but the owner
has not been asked this specific split.

**Q3.** Should a `TRACKING_RECORDED` correction (§3) be limited to a maximum
number of corrections, or logged without limit the same way every other
append-only event is? This document assumes the latter (no limit — the same
posture `order_events` already takes everywhere else) but has not had it
confirmed as a deliberate choice rather than a default.

**Q4.** `ORDER_DISCOUNT` (E5) is an architect default, not an owner-confirmed
permission name or default role assignment — the owner should confirm both
the permission's exact label (shown in the Role editor) and that Administrator
**and** Manager (not Administrator only) are the right starting holders,
before the data migration (§11 landing table) ships.

---

## 11. Staged build plan, review gate per stage, and the landing table

**One stage per section of real risk, each with its own review gate — the
same discipline every prior lifecycle stage already used**, not written out
move-by-move here (that belongs to each stage's own brief when it is
written):

1. **Schema**: `order_placement_snapshots`, `orders.edit_revision`,
   `orders.tracking_number`, `SaleLineType::EDIT_REVERSAL`, `SaleLine::
   create()`'s new optional `$originatingSaleLineId` parameter,
   `OrderEventType::EDITED`/`TRACKING_RECORDED`, `CheckoutOrchestrator`'s new
   snapshot write. No behaviour change beyond the new, unused columns/cases —
   every existing test stays green unchanged, the same proof every prior
   schema-only stage already had to give.
2. **Domain**: `Order::reviseTotals()`/`reviseDelivery()`/
   `assertEditable()`, `OrderNotEditableException`,
   `assertFieldsMatchDeliveryType()`'s narrowed `STREET_ADDRESS` rule (§3),
   `SaleLineRepository::sumQuantityEditedAwayForOriginatingLine()`. Pure
   domain/contract work, no service, no UI.
3. **The service**: `App\Services\OrderEditor` (or whatever name reads best
   at build time) — §5's unit of work end to end: lock, revision
   compare-and-set, money gate, line computation, stock, `OrderRefunder`
   reuse, promotion re-validation, events, hook. The one stage carrying the
   real transactional risk; the one that needs the fullest test list
   (happy path per E2 category, every refusal, rollback, query-count).
4. **Admin UX**: `editAction()`, the shared form, the preview, `ORDER_DISCOUNT`
   gating, the data migration delivering it to Administrator/Manager (the
   `PRODUCT_DELETE` precedent — a plain migration reading/writing
   `staff_roles.permissions` directly for the two shipped system roles only,
   idempotent, never touching a merchant's own custom role).
5. **Notification contract**: the real `order.edited` listener wiring, if a
   concrete customer-facing channel is approved by then — otherwise this
   stage stays exactly what §9 already scoped it to: a documented, unused
   extension point.

### Landing table — cross-reference edits to other documents (listed, none applied by this pass)

| Document | Edit |
|---|---|
| `order-lifecycle-design.md` §2.2 (R11) | Append "...or an edit that removes the applied promotion code (see `order-editing-design.md` §7)" to R11's own sentence. |
| `order-lifecycle-design.md` §11 item 20 | Same addition, in the permanent-commitments restatement. |
| `order-lifecycle-design.md` §9 | Remove "Order editing... remains unbuilt and unclaimed here" once stage 3 above ships; replace with a one-line pointer to this document. |
| `extensibility-design-and-hooks.md` §3 (Hook Reference) | Add the `order.edited` row, in the same commit as stage 3's own call site — not before. |
| `staff-access-domain-design.md` §3 | Add `ORDER_DISCOUNT` to the permission list and to the `RoleResource.php` 'Orders' group, in the same commit as stage 4's own gate. |
| `lang/en/orders.php`, `lang/bg/orders.php` | New `event_type_options.edited`/`.tracking_recorded` keys, new field/action labels for the edit dialog — same-commit-as-call-site, the same discipline every prior admin-UI stage already followed. |

---

## Non-goals (unchanged from the brief, restated once for completeness)

POS orders; customer-initiated edits; shipping price/recalculation
(an edit carries the order's shipping through unchanged and never re-quotes
it — §0 item 4, `shipping-domain-design.md` §7); top-up or second payments; refunds inside an edit (E3's own
"never, ever"); free price overrides (E2's own "no free price override");
edits after shipping (a return is the only path once goods leave); exchanges;
any code, migration or test — this pass is the document alone.
