# Order Lifecycle Design

**Status:** Draft v1 — design only, nothing implemented. This document defines
what happens to an order *after* it is placed: six statuses, the seven legal
transitions between them and the guard behind each one, the one atomic unit of
work that performs a transition plus its side effects, the payment-confirmation
operation the lifecycle cannot work without, the always-on history table a
merchant can actually rely on, and the admin-panel actions that expose all of
it. It also records the staged build order and the review gate between stages.
No code, no migration, and no UI change is part of this pass.

**Builds on:** `checkout-domain-design.md` §3 and §10 — §3 created
`Order.status`, listed its three values, and recorded the domain owner's own
instruction that the transitions and their side effects be built *together with
the admin UI, as one piece, rather than guessed at in isolation*; this document
is that piece's design. §10's deferral list is the other half of the brief.
`payment-domain-design.md` §5.2 (the "at most one CAPTURED payment per
transaction" invariant §4 below has to respect) and §7 (the refund model §7.3
reuses rather than re-invents). `operational-sales-domain-design.md` §3.2
(append-only history — the rule the return path obeys), §3.13 Q4 (the return
decisions already taken with the owner: actor, reason, restocking, and the
archived-variation revival path) and §3.13's remaining stage 5.
`admin-panel-design.md` (the Orders section this document's §8 extends, and the
conventions — D1's read-only posture, D5's single-reader rule, D6's
latest-payment rule — it inherits). `extensibility-design-and-hooks.md` (the
hook list §12 extends; this pass also found a row missing from it).
`staff-access-domain-design.md` (`ORDER_VIEW`/`ORDER_MANAGE`/`REFUND_CASH`/
`REFUND_BANK` — the permissions §8 gates on, used as-is; no new permission is
proposed).

**Relates to:** `product-shipping-fields-note.md` and
`promotions-domain-design.md` §5/§6, which both name a future Shipping domain.
That document's redemption counts are also the one place outside this lifecycle
that a cancellation changes a counter, which is why §7.4 here edits it and why
§13 schedules that edit with the code that makes it true.
This document deliberately does **not** build one: `shipped` here is a *fact the
merchant records*, not a carrier integration (§9). `inventory-domain-design.md`
§6 (the method set §7.1 restocks through, unchanged).

**Origin:** `checkout-domain-design.md` §3 and §10 left two explicit holes — no
code anywhere changes an order's status after `placed`, and no side effect
(restock, refund, history) exists — with an explicit instruction not to guess at
them in isolation. This is the design written to that instruction. Every "today"
claim in §0 below was re-checked against the installed source and against the
live development database in this pass, not recalled from the earlier documents;
where that check contradicted an earlier note, the code is what is written here.

---

## 0. What is true today (verified in this pass)

1. **The status enum has three values, and nothing anywhere changes one.**
   `OrderStatus` is `PLACED | FULFILLED | CANCELLED`
   (`packages/EasyCo/Order/src/Enums/OrderStatus.php:13-15`), and its own
   docblock states the situation plainly: *"No transition methods exist anywhere
   in this package: nothing in this pass changes an Order's status once placed —
   that is deliberately future admin-UI work (design doc §3/§10), not guessed at
   here."* `CheckoutOrchestrator` writes `PLACED` and nothing else. Live check:
   all 6 rows in the development database's `orders` table are `placed`.

2. **`FULFILLED`'s complete reference list — five places, and one of them is a
   test.** Outside the enum itself, the case appears in `lang/en/orders.php:78`
   and `lang/bg/orders.php:78` (the `status_options` label pair), in
   `packages/EasyCo/Order/tests/OrderTest.php:376` and `:402` (the
   street-address reconstitution round-trip constructs it and asserts it comes
   back), in the creating migration's own comment
   (`2026_09_06_000001_create_orders_table.php:78`), and in
   `checkout-domain-design.md` (lines 65, 95, 238, 302). Nothing in `app/` reads
   it, no repository writes it, no admin filter offers it, and no row in any
   known database holds it. Dropping the case is therefore a small and
   fully-enumerated edit — see §10 stage 1 for the migration that ships beside it, and
   for why that migration refuses to run rather than rewriting a single row (owner
   decision D1).

3. **`Order` is immutable by construction, and only one method mutates it.**
   All 23 constructor parameters are `private readonly`
   (`Order.php:53-75`), and the only post-construction mutation in the entire
   class is `assignId()` — one-time, refusing a second call with a
   `LogicException` (`:340-347`). A status change therefore needs a *deliberate*
   new mutator, in the same shape `Product::publish()` and
   `Payment::recordAttemptResult()` already use in this codebase (§5.1) — not a
   setter, and not a reconstitute-and-resave.

4. **The order repository has no compare-and-swap and no locked read.**
   `EloquentOrderRepository::save()` rewrites all 23 order columns unconditionally
   (`:23-45` — every column but `id` and the timestamps) — there is no
   `WHERE status = <from>` guard, so it will happily overwrite a status it never
   read. `findById()` is a plain `OrderModel::find()` (`:56`) with no `FOR UPDATE`
   variant anywhere in the contract, and
   `hasAnyForAccount()` filters on `account_id` alone with no status condition
   (`:63`). Nothing concurrent writes orders today, so this is not a live bug —
   it is the constraint §5.2's locked re-read exists to satisfy.

5. **Nothing can mark a payment `CAPTURED` after checkout.** `Payment`'s only
   status-moving method is `recordAttemptResult()`, and it is a **one-time**
   operation that refuses a second call — exactly the posture `assignId()`
   above has. `CheckoutOrchestrator` records every attempt as `PENDING` with
   `attempted_at` set, offline methods (bank transfer, cash on delivery)
   included; there is no panel action that records "the money arrived". Live
   check: 6 payments, all `pending`, 0 with a NULL `attempted_at`, 0 refunds.
   This is why §4 is not a nicety but the missing half of the lifecycle: without
   it an offline-paid order can never be truthfully recorded as paid.

6. **A `REFUND` sale line can already be written today — what is missing is the
   strict factory and the return's own fields.** `SaleLineType` already carries
   `REFUND` (`SaleLineType.php:13`), `SaleLine::createNonSale()` already accepts
   it and refuses only `SALE` (`SaleLine.php:470-474`), `originatingSaleLineId`
   is already asserted to be settable *only* on a REFUND line (`:147-153`),
   `Transaction::addSaleLine()` and `EloquentTransactionRepository::save()`
   already persist an appended line (new lines only, keyed on `id() === null`,
   `:46-48`, `:52-68`) — and the multi-type round-trip is already covered by
   tests (`tests/Feature/EloquentTransactionRepositoryTest.php:159`,
   `SaleLineTest.php:256-264`). What does **not** exist: a strict REFUND factory
   mirroring `create()`'s formula invariants (explicitly deferred —
   `SaleLine.php:436-443`), the return's actor and reason fields
   (`operational-sales-domain-design.md` §3.13 Q4(a)/(b) are *decided*, not
   built — searching the repository for `returned_by`, `return_reason`,
   `quantity_returned`, `refund_amount` or `display_price_at_return` finds
   nothing), and any app-layer caller of any of it. Live check:
   `operational_sales_sale_lines` holds 8 rows, all `sale`.

7. **The "latest payment" rule is NULL-aware, and it hands a constraint to
   whoever writes `payments` next.**
   `OrderAdminReader::latestPaymentColumnSubquery()` orders by
   `attempted_at DESC, id DESC` (`app/Services/OrderAdminReader.php:157-158`)
   and its comment claims NULL `attempted_at` rows sort last; a direct probe
   against the live MySQL instance confirmed exactly that (NULL sorts lowest, so
   `DESC` places it last). `forOrder()` applies the identical rule (`:229-234`),
   so the list and the view agree, as their comments claim. The consequence for
   §4: **any future writer of a `payments` row for an order must set
   `attempted_at`**, or a *newer* row with a NULL `attempted_at` loses to an
   older answered one and the admin panel keeps showing the previous payment as
   "the" payment. No row in the development database is NULL today, and §4.2's
   confirmation writes nothing but `confirmed_at` on the row the adapter already
   answered — so this document leaves the rule alone and records the constraint
   instead.

8. **`ActivityLogger` cannot serve as an order's history.** `write()` returns
   early unless the `admin.activity_log_enabled` Site Setting is `'1'`
   (`app/Services/ActivityLogger.php:112`), and `php artisan activity-log:prune`
   deletes rows older than `admin.activity_log_retention_months`, default 12
   (`app/Console/Commands/PruneActivityLog.php:54`, `:60`). An order's own
   history has to survive a merchant switching a setting off and has to outlive
   a retention window — so §6 adds its own table rather than overloading the
   admin journal. `ActivityLogger` keeps its existing job, including the one
   `operational-sales-domain-design.md` §3.13 Q4 already assigned it (recording
   the *Product* state change a return causes — a different fact from the
   order's own history).

9. **This pass's new schema objects do not exist yet.** There is no
   `order_events` table (§6.1, stage 3), no `payments.confirmed_at` column
   (§4.4, stage 5), no `payments.voided_at` column (§7.3, stage 5) and no
   `promotion_redemptions.released_at` column (§7.4, stage 6) in the database. Read
   together with items 5 and 6: the money
   fact has no column to live in, and the order's history has no table to live in,
   which is why §4 and §6 are halves of one lifecycle rather than conveniences.

10. **The promotion side has no release path, and the account's order count reads
   no status.** A `PromotionRedemption` is written once, at placement
   (`CheckoutOrchestrator.php:398`), and read back through
   `PromotionRedemptionRepository::countForPromotion()` and
   `countForPromotionAndAccount()` — two plain `COUNT`s over
   `promotion_redemptions` with no release condition and no such column in the
   table (`EloquentPromotionRedemptionRepository.php:35-45`).
   `PromotionUsageContextAssembler::assemble()`
   (`app/Services/PromotionUsageContextAssembler.php:50-62`) is the single place
   both counts *and* `OrderRepository::hasAnyForAccount()` are called, each
   behind its own setting guard. §7.4's two decisions (a redemption is released
   only when the order is cancelled; a cancelled order stops counting as "this
   customer has bought before") therefore touch one new column, two conditions
   and one order query — and nothing else on the storefront's promotion path.

---

## 1. The six statuses

| # | Value (`OrderStatus` case) | Meaning, in one sentence | Terminal? |
|---|---|---|---|
| 1 | `placed` (`PLACED`) | The order exists and no merchant has touched it yet. | no |
| 2 | `confirmed` (`CONFIRMED`) | The merchant has accepted the order and is preparing it. | no |
| 3 | `shipped` (`SHIPPED`) | The goods have left the merchant's hands — handed to a carrier, or on their way to a courier office. | no |
| 4 | `delivered` (`DELIVERED`) | The customer has the goods. | no |
| 5 | `cancelled` (`CANCELLED`) | The order was called off, or the parcel was refused or lost, before it reached the customer. | **yes** |
| 6 | `refunded` (`REFUNDED`) | The goods reached the customer and then came back: every remaining unit is back. | **yes** |

**`FULFILLED` is dropped, and that is the only status this design removes.** It
was a single word for two facts a merchant's fulfilment queue has to tell apart:
"ready, but still in my shop" (where a cancellation is still honest and
`cancelled` still reachable) and "gone, in someone else's hands" (where it is
not). Splitting it is not a re-labelling — it is what makes §2's cancellation
rules stateable at all.

**`cancelled` and `refunded` are the two ways an order ends, and the only
difference between them is *when* it ended.** `cancelled`: called off, refused
or lost before the customer had the goods. `refunded`: the customer had the
goods and every remaining unit came back. Both are terminal, neither is
reachable from the other, and which one a return reaches is decided by where the
order stood when the return was recorded (§2.3's table) — never by whether money
moved, and never by the merchant's preference.

**There is deliberately no `returned` status.** A partial return is a record,
not a state (§2.3, §3 item 5): a status meaning "some of it came back" cannot be
told from "all of it came back" without reading the history anyway, which is the
job the return ledger already does (§6, §7.2). The two terminal values carry the
one distinction a badge can usefully carry — *when the order ended*.

**The three-statuses rule from `checkout-domain-design.md` §238 is unchanged and
this document does not weaken it:** `Order.status` answers *has the merchant
fulfilled this order?*, `Payment.status` answers *has the money arrived?*, and
`SaleLine.status` answers *is this line's own financial fact settled?* None of
the three is derivable from another. An order can be `refunded` while its
`Payment` row still reads `captured` (the money did arrive; the hand-back is
recorded in `payment_refunds`, §7.3), and it can be `cancelled` with money handed
back for exactly the same reason — an order's end state and the money's own
trail are two records of two facts (§3 item 1). §3 draws the consequences.

## 2. Transitions

### 2.1 The transition table

Read this as: the *permission* is what the panel gates an action on (§8.3); the
*guard* is what refuses the transition regardless of who asks — the domain itself
where the aggregate can see the fact, the app service where it needs a read the
aggregate does not own (§5.2); the *side effects* are what the same database
transaction writes. Every row also writes one `order_events` row (§6) and fires
`order.status_changed` after commit (§12); both are omitted from the table so the
rules stay readable. **This table is the only place the transition set is
stated** — every other section refers to it instead of restating it.

| From | To | Permission | Guard | Side effects (same transaction) |
|---|---|---|---|---|
| `placed` | `confirmed` | `ORDER_MANAGE` | — | — |
| `placed` | `cancelled` | `ORDER_MANAGE` (+ R5 once money is settled) | — | every unit of every line back to stock, then R8's money step |
| `confirmed` | `shipped` | `ORDER_MANAGE` | R9 | — |
| `confirmed` | `cancelled` | `ORDER_MANAGE` (+ R5 once money is settled) | — | every unit of every line back to stock, then R8's money step |
| `shipped` | `delivered` | `ORDER_MANAGE` | — | cash on delivery: R10's confirmation, in this transaction |
| `shipped` | `cancelled` | `ORDER_MANAGE` (+ R5 once money is settled) | — | R1's return of every remaining unit — per-line restock flags, R3 — then R8's money step |
| `delivered` | `refunded` | — (system-only, §2.3) | R6 | — (the return that emptied the order wrote its goods and its money in the same transaction) |

`cancelled` and `refunded` are terminal: no row leaves them. "**+ R5 once money
is settled**" means the action also needs the permission R5 derives from the
settled payment's own method — but only when such a payment exists, which is a
fact the table cannot show and §8.3's action reads before offering the button.

**Deliberately absent, and why:**

- `placed` → `shipped` — an order is accepted before it leaves; that is what
  `confirmed` means, and it is where R9's payment check runs. A skippable state
  is a state nobody maintains.
- `confirmed` → `delivered` — every order passes through `shipped`. A
  pickup-point order is not an exception: `pickup_point` names a *courier
  office*, which is why `carrierCode` and `pickupPointReference` are its own
  required fields (`Order.php:73-74`, `:178-192`) — the parcel is still shipped,
  and "the customer collected it" is a `delivered` fact like any other. No
  transition and no rule in this design branches on `deliveryType`.
- `delivered` → `cancelled` — after delivery the goods are with the customer.
  What comes back is a return, and every return that empties the order ends at
  R6's own status for `delivered` (§2.3). `cancelled` means *it ended before the
  customer had it*.
- every backwards move (`delivered` → `shipped`, `shipped` → `confirmed`, …) —
  each describes re-entering a state the order has already left, so the history
  would contain the same transition twice with no way to tell which came first.
  A merchant who moves an order forward by mistake reverses nothing: it moves
  further, or it ends, with the reason recorded in §6's `order_events.reason`.
- `shipped`/`delivered`/`cancelled` → `refunded` as an action of its own — R4:
  money never ends an order by itself. A refund is the money half of the
  cancellation or return that justifies it, written inside that operation's own
  transaction.
- `cancelled` → `refunded` — a cancellation that hands money back writes a
  `PaymentRefund` (R8) and stays `cancelled`: the status records *when the order
  ended*, while the money's own trail lives in `payment_refunds`.

### 2.2 The rules behind the table

**R1 — A cancellation is a return of every remaining unit.** Cancelling and
returning are one operation (R2) with two ways of choosing the quantity: the
merchant clicking "cancel" has decided *all of it*, while a return is a per-line
answer. From `placed`/`confirmed` the goods never left the shop, so stock goes
back unconditionally and nothing is asked about it (R3); from `shipped` the
merchant answers the parcel's own fate line by line (R3). There is no state in
which an order is cancelled and part of it is still unaccounted for.

**R2 — One implementation serves cancellation and every return.** A single return
primitive does the goods (§7.2), the stock (R3), the money (R8) and R6's
compare-and-set; `cancel` calls it with "every remaining unit of every line" and
`recordReturn` with the merchant's own map (§5.2). Two implementations would mean
two places to get R7's cumulative read and R8's money step right, and they would
drift.

**R3 — Whether stock goes back is the goods' own location, never a policy.**
Before `shipped` the goods are still on the shelf, so they go back
unconditionally. At `shipped`/`delivered` the parcel may be lost, refused or
defective, so the restock is a **per-line flag, default true** — a lost parcel is
the same cancellation with the flag off, and its status is still `cancelled`.
Nothing infers the flag from the status, or the status from the flag.

**R4 — Money never ends an order by itself.** A refund is the money half of a
cancellation or a return, never an operation of its own: no button writes only
money, no status is set by money moving, and R5's permission gates the money
rather than replacing `ORDER_MANAGE`. That is why a cancellation that hands money
back is still `cancelled` (§2.1's absent rows), and why the status after a
delivered order's goods all come back is `refunded` because the *goods* came
back — the money is a separate record, written in the same transaction (R8).

**R5 — Which refund permission: derived from the settled payment's own
method.** A `cash_on_delivery` payment is refunded under `REFUND_CASH`; every
other method (bank transfer today, any future card/online adapter) is refunded
under `REFUND_BANK`, because the money leaves a bank account or a processor
account rather than a till. This is the one *new* rule this document adds to an
existing permission pair — the pair itself, and the reason it is split at all,
is `staff-access-domain-design.md`'s. When no settled payment exists there is
nothing to refund through this path and R8(b)/(c) apply instead — the money at
issue was never recorded, so a hand-back happens outside the payment record
(§14 Q3).

**R6 — The terminal status is set by the operation that empties the order, and
§2.3's table states it once.** Nothing else enters `cancelled` or `refunded`, and
which of the two a return reaches is decided by where the order stood when the
return was recorded — never by the merchant, and never by the money.

**R7 — "Already returned" is a query, never a counter.** A return may ask for a
line's own quantity minus the units already returned against it, and that figure
is read inside the locked transaction (§5.2 step 2): `quantityReturned` summed
over the prior `REFUND` lines sharing the line's `originatingSaleLineId` —
`operational-sales-domain-design.md` §3.4's own designed read, used as-is.
`quantityReturned` is what gets summed, never a refund line's own `quantity`
column (⚠️ §7.2 records what that column holds, since §3.4 leaves it open), and
nothing stores a per-line counter, a per-order JSON of what came back, or a "was
restocked" flag: a repeat operation returns early on the status guard (§5.2 step
3).

**R8 — The money half of a cancel or a return, in three cases.** (a) **Settled**
(`Payment::isSettled()`) → one `PaymentRefund` for the returned units' share,
computed cumulatively by that domain's own rule
(`operational-sales-domain-design.md` §3.13: `cumulative(r + k) − cumulative(r)`,
where `cumulative(n) = floor(netPaidAmount_minor × n / quantity)`), and never
more than the settled payment's remaining refundable amount (payment §5.2). A
share that would exceed that remainder is **refused**, because the difference is
money no record could explain. (b) **Pending**, with the return reducing what the
customer still owes → the pending row is voided and a new `pending` payment is
appended for the remainder, or only the void when nothing remains (§7.3).
(c) **Nothing settled and nothing pending** → no money is written at all; the
system never knew about that money (§14 Q3).

**R9 — `confirmed` → `shipped` is refused while a bank transfer has not
arrived.** The guard is a read the aggregate cannot make: for a `bank_transfer`
order, one of its payments must be `isSettled()` (§4.1). `cash_on_delivery` is
deliberately **not** guarded — the money is collected at the door, and refusing
the shipment would block the only way the goods ever leave. The refusal is a
named, translatable reason (§8.3), so the panel shows a sentence an operator can
act on rather than a status it has to interpret.

**R10 — Cash on delivery is confirmed at delivery.** `shipped` → `delivered`
attempts the payment confirmation *inside the same transaction*, through the same
`App\Services\OrderPaymentConfirmer` (§4.3) the manual bank-transfer confirmation
uses: one implementation, two callers, no second confirmation path, and the
payment fact and the goods fact recorded together because they happened
together. When the payment is not confirmable the delivery is still recorded and
the panel says what the payment's own row says (§4.5, §14 Q1).

**R11 — A promotion redemption is released only when the order becomes
`cancelled`.** Never on a return after delivery, and never on a refund: the
release is a side effect of the one transition whose meaning is "this sale did
not happen" (§7.4).

### 2.3 Cancellation, returns, and the two terminal statuses

A cancellation and a return are one operation with different quantities (R1/R2).
Both write goods, stock and money inside one transaction, and both may end the
order. The whole of the status rule is this table, and this is the only place it
is stated:

| The order is at | The return leaves units outstanding | The return empties the order |
|---|---|---|
| `shipped` | status untouched — a record in §6, no badge change | `cancelled` |
| `delivered` | status untouched | `refunded` |

**Compare-and-set, not a recomputation.** The service reads the remaining units
under the row lock (§5.2 step 2), writes the return, and only then, if every
`SALE` line is fully accounted for by `REFUND` lines (R7), moves the status — in
the same transaction, from the status it locked. Two concurrent returns therefore
cannot both "empty" the order: the second one's locked read already sees the
first one's lines, so it either finds less to return or finds nothing and
refuses at the quantity read.

**Three consequences, each argued elsewhere and not restated here:** a partial
return is a record, not a state (§3 item 5); a cancellation that hands money back
does not become `refunded` (R4); and nothing anywhere stores a per-order or
per-line counter of what came back (R7).

**And both terminal statuses are permanent — there is no correction path.**
There is no "un-return": the goods' ledger and the money's trail are append-only
(§6, `operational-sales-domain-design.md` §3.2), so a return recorded in error is
answered by a *new, explained* record — a new return for the units that are
actually back, a new order, or a hand-back recorded outside the system (§14 Q3) —
rather than by walking a status backwards and leaving a timeline in which the
same transition appears twice with nothing to say which came first. This is also
why R3's restock flag exists: "the parcel is lost" and "the unit is defective" are
answered *in the dialog that records the return*, before the status is reached,
instead of afterwards by reversing it.

## 3. What follows from the three-statuses rule

`checkout-domain-design.md` §238's rule (three independent answers, none derivable
from another) is drawn here as concrete prohibitions, because almost every
tempting simplification of a fulfilment lifecycle breaks it:

| Question | Answered by | Never inferred from the others |
|---|---|---|
| Has the merchant fulfilled this order? | `orders.status` | a payment arriving does not fulfil anything |
| Has the money arrived? | `payments.status` + `payment_refunds` | the order's own status never answers it — not while it ships, and not when it comes back |
| Is this line's own financial fact settled? | `sale_lines.type/status` | a `REFUND` line records goods and its own amount; whether the order *ends* is decided by the return's coverage (§2.3), never by the line |

1. **No transition is triggered by a payment fact.** Confirming a payment (§4)
   leaves the order exactly where it is: a `placed` order whose bank transfer has
   arrived is *paid and untouched*, and waiting for the merchant to accept it —
   not auto-`confirmed`. The merchant accepting the order and the money arriving
   are two facts with two actors; inferring one from the other would erase the
   second actor's decision, which is why no payment appears as a `From` or `To`
   value in §2.1's table and why money never ends an order by itself (R4). The one
   operation that writes both facts is R10 — cash on delivery is confirmed at the
   moment the delivery is recorded, because that is the moment the cash changes
   hands — and even there the operation is the merchant's `deliver`; the
   confirmation rides along with it rather than causing it.
2. **No "paid" value is added to `OrderStatus`.** "Paid" is a money fact; adding
   a status for it would be a second, worse version of the `FULFILLED` mistake
   §1 just removed — one word for two questions.
3. **A delivered order is not "paid" because its status says so, and unconfirmed
   money is an exception the panel names rather than a normal state.** Cash on
   delivery is confirmed *at delivery* (R10), so the ordinary COD flow has no gap
   at all. When the payment cannot be confirmed — no row, a `FAILED` attempt, or an
   attempt the adapter never answered — the delivery is still recorded (the goods
   fact is true and the goods are physically gone) and the payment row is left
   exactly as the adapter left it, with the panel showing both side by side and
   **never** collapsing them into a single computed "paid/unpaid" badge (§8.4
   records what it must not do). That state is an exception with its own question
   (§14 Q1), not one of the lifecycle's outcomes.
4. **Stock is not a status, in either direction.** Cancelling restocks and
   returning restocks (§7.1, §7.2) — per R3's own flag, which says so when the
   parcel never came back — but nothing derives an order status from stock and
   nothing derives stock from an order status: a `confirmed` order whose variation
   later runs out stays `confirmed` (the merchant cancels it, or the customer
   waits), and putting stock back on the shelf does not revive a cancelled order.
5. **Partial facts have no status.** A partial return, and the partial refund
   written with it (§7.2, R8), are records, not states. All six statuses are
   all-or-nothing words; the moment one of them means "some of it", the merchant
   cannot tell what is still outstanding without reading the history anyway.
6. **Nothing is recomputed on read.** `orders.status` is the only mutable part of
   the three; the order's history is append-only (§6), payment attempts are
   append-only, sale lines are append-only. Every screen therefore reads the same
   facts the write path wrote, in the order it wrote them — the posture
   `admin-panel-design.md`'s D1 already requires of the Orders section.
7. **The lifecycle answers *fulfilment*, and stops there.** No accounting export,
   no invoice numbering, no tax document, no carrier tracking (§9). Those are
   consumers of these facts, not part of them.

## 4. Payment confirmation

§0 item 5 is the hole this section fills: no code path anywhere can record that
money actually arrived. Online methods do not need one — the adapter's own answer
is `CAPTURED`. The two offline methods do: cash on delivery is settled when the
merchant takes the cash, bank transfer when the merchant sees the credit, and
checkout records both as `PENDING` with `attempted_at` set, after which nothing
can ever move them. Without §4 an offline-paid order can never be told apart from
one whose money never came — the distinction R8's money step, R9's shipping guard
and §8.4's payment block all read. The operation writes a money fact **without**
touching the order (§3 item 1), with one caller that records both facts at once
because they happened at once: R10's delivery, for cash on delivery.

### 4.1 The domain operation: `Payment::confirm()`

One new mutator on `Payment`, beside `recordAttemptResult()` — same class, same
posture, nothing else:

```php
public function confirm(DateTimeImmutable $confirmedAt): void
```

**One-time, like `assignId()` and `recordAttemptResult()`.** A second call throws
`LogicException`. Recording the same money fact twice is a caller bug, not a
no-op: the panel does not offer the action once `confirmed_at` is set (§8.3), so
a second call can only come from a race or a defect, and both deserve to be loud.

**Guard 1 — `attemptedAt() !== null`.** `PENDING` + NULL `attempted_at` is the
state `attempted_at` was added to expose: a crashed or never-answered attempt
(`Payment`'s own class docblock). Confirming it would record money arriving
against an attempt nobody knows the outcome of. Refused with `LogicException`.
(This is §0 item 7's constraint read from the other side: a writer that creates a
payment row at all must set `attempted_at`.)

**Guard 2 — `status() !== PaymentStatus::CAPTURED`.** Already settled by the
adapter; this method is the *offline* half and has nothing to add.

**Guard 3 — `status() !== PaymentStatus::FAILED`.** The adapter answered no.
There is no money to confirm, and "confirming" it would be a refund waiting to
happen. Also refused.

So the only state `confirm()` accepts is `PENDING` **with** an answered attempt.
Those three refusals are the domain's, all three visible on the aggregate itself
— none of them needs a read §5.2's service must perform.

**One new query method, `Payment::isSettled(): bool`**, defined once as
`status === CAPTURED || confirmedAt !== null`. Every rule that means "money is
held" calls this (R1, R5, §7.3, §8.4) instead of re-deriving the two-part
predicate — the same "one rule, one place" posture `ProductStatusChanger` states
for status transitions. Plus `confirmedAt(): ?DateTimeImmutable`.

**Constructor and factories.** `$confirmedAt` is added as a
`?DateTimeImmutable` parameter last, mutable (like `$status`/`$failureReason`),
defaulting to `null`, and both `create()` and `reconstituteFromStorage()` thread
it through. Every existing call site keeps working unchanged because the
parameter is last and defaulted.

### 4.2 What confirmation writes — and why the payment's status does not move

`confirm()` writes `confirmed_at` and nothing else. The row keeps the adapter's
own answer:

| Column | Before | After confirmation |
|---|---|---|
| `status` | `pending` (offline method) | **`pending`** — unchanged |
| `attempted_at` | set | unchanged |
| `confirmed_at` | `NULL` | the instant the merchant recorded the money |

Four reasons, in the order they matter:

1. **The row is the record of what the adapter said.** `PaymentStatus`'s own
   docblock makes `PENDING` a legitimate, *final* state for an offline method,
   distinguished from a never-answered attempt by `attempted_at`. Flipping it to
   `CAPTURED` would replace the adapter's answer with a human's and lose the
   distinction the column was added for.
2. **`recordAttemptResult()` is one-time and refuses a second call.** Reusing it
   here would either break that guard or require weakening it — the same
   one-time-operation posture `assignId()` sets for the whole codebase.
3. **It keeps `payment-domain-design.md` §5.1's DB constraint meaning what it
   documents.** `captured_order_id` (a stored generated column, unique) is
   computed from `status = 'captured'`,
   (`2026_09_04_000001_create_payments_table.php:71-75`); a confirmation that
   flipped `status` would silently widen that index to cover offline rows too.
   §4.4 adds the offline half as a *second, separately named* constraint instead
   of changing the meaning of the existing one behind the reader's back.
4. **It matches how the same class of gap was closed before.** `attempted_at`
   closed "when did the adapter answer" with a timestamp column, not a status.
   `confirmed_at` closes "when did the merchant record the money" the same way.

**What is deliberately not written: an actor on the payment.** There is no
`confirmed_by` column. Who confirmed it is recorded in `order_events` (§6), the
order's own always-on history, which is the record the merchant actually reads —
and no other payment fact carries an actor (an adapter charge has none), so a
column here would be the only one of its kind.

### 4.3 The app-layer operation: `App\Services\OrderPaymentConfirmer`

`ProductStatusChanger`'s counterpart for a money fact, and a separate class from
§5.2's `OrderStatusChanger` because it performs no transition: confirming a
payment must stay callable on a `delivered` order, where there is nothing to
transition at all.

```php
public function confirm(string $paymentId, DateTimeImmutable $confirmedAt): void
```

**Two callers, one implementation — the payment is confirmed in exactly one
place.** The panel's own payment action (§8.1) calls it, and so does
`OrderStatusChanger::deliver()` when the order's payment is cash on delivery
(R10). Nothing else in the codebase writes `confirmed_at`: a second confirmation
path is how "the money arrived" would start meaning two different things
depending on which screen recorded it.

1. **One transaction** (`DB::transaction()`, the plain form every writer here uses
   — `ProductStatusChanger.php:62`, `CheckoutOrchestrator.php:127`; there is no
   retry-on-deadlock loop anywhere in this codebase and this design adds none)
   that **locks the order row first** — the same `SELECT ... FOR UPDATE` primitives §5.2 needs and
   the same lock order (the order, then its payments). One lock order across the
   whole document is what keeps two concurrent panel actions from deadlocking
   each other.
2. Reads the order's payments (`PaymentRepository::findByOrderId()`, which
   already exists and already returns every attempt across retries,
   `Contracts/PaymentRepository.php:21`), and refuses when any of them
   `isSettled()`. The message names the settled attempt. This is a courtesy
   check for a readable error; §4.4's second unique index is the guarantee.
3. `$payment->confirm($confirmedAt)` — §4.1's guards are the domain's, and they
   throw before anything is written.
4. `PaymentRepository::save($payment)`.
5. One `order_events` row (§6): `type = payment_confirmed`, both statuses NULL
   (nothing transitioned), actor + instant recorded.
6. **After commit**: the `order.payment_confirmed` hook (§12). Nothing else — no
   order transition, no stock, no notification.

**Permission.** `ORDER_MANAGE` (§8.3). Like every other app service here
(`ProductStatusChanger` included), this class performs **no** permission check of
its own: the Filament action is what is authorized, and a console caller is
accountable in the same way a console caller of any other service is.

**No actor parameter.** The actor is resolved inside the service from the panel
guard, exactly as `ActivityLogger::write()` does it internally for its own rows
(`ActivityLogger.php:116`, `:131-136`) — so no caller has to remember to pass it,
and a console/job caller records `null` rather than throwing.

**Called from inside `deliver`'s transaction, and safely.** R10's caller already
holds the order's row lock inside its own `DB::transaction()`; Laravel composes
the second one as a savepoint, so the money fact and the goods fact are one atomic
unit under one lock order — the order first (already held), then its payments.
What the caller does with a *refusal* is deliberately the caller's decision and
not this class's: `deliver` asks whether the payment is confirmable before calling
(§4.5's table), because a wrong confirmation must never roll back a delivery that
physically happened. This class therefore keeps §4.1's strict guards and their
loud `LogicException`s untouched.

### 4.4 The read path, and the one new database constraint

**Nothing needs to be added to make it visible, which is worth stating
explicitly.** `OrderAdminReader::forOrder()` already resolves the latest payment
by reconstituting the real domain `Payment` (`OrderAdminReader.php:229-238`), so
once the schema and the mapping carry `confirmed_at`, the View page's payment
block can call `confirmedAt()`/`isSettled()` with **no new query and no reader
change** — the same "one read, one place" property D5 was built for. What must
change is only the plumbing:

| Where | Change |
|---|---|
| `packages/EasyCo/Payment/database/migrations` | New `add_confirmed_at_to_payments_table`: `timestamp('confirmed_at')->nullable()->after('attempted_at')`, `down()` drops it. The original create-table migration is never edited — the convention the `attempted_at` migration states in its own docblock. |
| `PaymentModel` | `confirmed_at` added to `$fillable`, and to `$casts` as `immutable_datetime` (exactly how `attempted_at` is declared today). |
| `EloquentPaymentRepository` | `save()` sets `$model->confirmed_at = $payment->confirmedAt();`; the row→entity mapper passes it into `reconstituteFromStorage()`. |
| `Payment` | §4.1's constructor/factory/accessor additions. |

**The constraint that makes "the money arrived" as DB-enforced as "the money was
captured".** `payment-domain-design.md` §5.1's `captured_order_id` unique index
enforces *at most one settled payment per order* only for the `status =
'captured'` half. §4.2 deliberately does not widen that index's meaning, so the
offline half gets its own, separately named constraint in the same migration:

```php
$table->string('settled_order_id')
    ->storedAs("CASE WHEN status = 'captured' OR confirmed_at IS NOT NULL THEN order_id ELSE NULL END")
    ->unique('pay_settled_order_unique');
```

The same reasoning §5.1 gives applies unchanged: MySQL treats multiple NULLs in a
unique index as non-conflicting, so any number of `pending`/`failed` attempts
contribute NULL and never collide; only rows that really represent money in hand
compete for uniqueness on `order_id`. **Rejected alternative:** redefining the
existing `captured_order_id` expression to include `confirmed_at` would express
the same rule with one index instead of two, but it changes a constraint this
codebase has already proven against a real database, via `->change()` on a
stored generated column — a Laravel operation nothing in this codebase uses, and
one an always-on money invariant should not be the first to try. The redundancy
is the point: two narrow, separately testable constraints beat one clever one.

### 4.5 What payment confirmation is not — and what happens when there is nothing to confirm

- **Not an order transition.** §3 item 1: it never moves the order's status, not
  even to `confirmed`/`delivered`. It rides *inside* R10's delivery when the
  method is cash on delivery; the transition is still the merchant's.
- **Not a partial-payment mechanism.** `Payment.amount_minor` is the whole
  amount; recording "the customer paid half" is not representable today, and this
  document does not add it (§9).
- **Not undoable.** `confirmed_at` is write-once, like `attempted_at` and every
  other fact this project records. A confirmation made against the wrong order is
  corrected the way a wrong capture would be — by the money trail, not by
  mutating history (§14 Q5).
- **Not for online methods.** §4.1's guard 2 refuses it: the adapter's own answer
  is the record there.
- **Not a way to overwrite a failed or unanswered attempt.** Guard 3 refuses a
  `FAILED` payment and guard 1 refuses a `pending` row whose attempt was never
  answered, so a merchant's confirmation can never contradict the adapter's own
  row.

**When a cash-on-delivery order has nothing confirmable, the delivery is still
recorded and nothing is invented.** R10 attempts the confirmation only when the
order's own payment is confirmable (exactly one `PENDING` row with `attempted_at`
set and no `confirmed_at`). The other four states each end the same way — the
goods fact is written, no money fact is, and the panel names the state it found:

| The order's payment | What the delivery does | What the panel shows |
|---|---|---|
| settled already (`CAPTURED`, or `confirmed_at` set) | nothing to confirm, and a second confirmation is refused by §4.1's guards | the payment as settled, unchanged |
| `PENDING`, `attempted_at` set | R10's confirmation, in the same transaction | settled, with the confirmation's own instant |
| `PENDING`, `attempted_at` NULL (crashed or never-answered attempt) | delivery recorded, no confirmation | the payment's own state — §0 item 7's NULL-aware latest-payment rule included |
| `FAILED`, or no payment row at all | delivery recorded, no confirmation, no new attempt | the adapter's failure, or D8's normal "—" |

That this is *possible* follows from §3 item 1 — the status answers the goods, the
payment row answers the money — and it is deliberately not designed as a normal
outcome: a `delivered` order whose money is unrecorded is exactly what §4 exists
to make visible rather than to quietly permit. Whether it should be refusable, and
what a merchant should be able to do about it, is §14 Q1.

## 5. The one unit of work: what a transition is actually made of

A transition is never a column write. §2.1's guards need facts the aggregate does
not own (R1's settled payment, R7's prior REFUND lines) and its side effects live
in three other packages' tables (`inventory_stock_levels`,
`operational_sales_sale_lines`, `payments`/`payment_refunds`). §5 splits the job
along the same seam this codebase already uses for Catalog — `Product`/
`Variation` enforce what they can see, `ProductStatusChanger` performs the
transaction — so that "who refuses what" is answerable by reading one class.

### 5.1 The domain half: `Order`'s transition mutators

`Order` gets five new public methods, one per legal move in §2.1's table, named
the way `Payment::recordAttemptResult()` and `Product::publish()` are:

```php
public function confirm(): void   // placed                   -> confirmed
public function ship(): void      // confirmed                -> shipped
public function deliver(): void   // shipped                  -> delivered
public function cancel(): void    // placed/confirmed/shipped -> cancelled
public function refund(): void    // delivered                -> refunded  (§2.3, system-only)
```

All five delegate to **one** private method, so the rule lives in exactly one
place:

```php
private function transitionTo(OrderStatus $to): void
```

which (1) throws if `$to` equals the current status, (2) throws
`InvalidOrderTransitionException` if the current status may not move there, (3)
assigns. **Every illegal transition is therefore two barriers deep: the method
that performs it does not exist, and the matrix refuses the move if the method is
called from a status that cannot reach it.**

**The matrix itself lives on the enum, not in `Order`** —
`OrderStatus::canTransitionTo(self $to): bool` backed by one private map, plus
`OrderStatus::isTerminal(): bool` (`cancelled`/`refunded`). §2.1's table is its
human-readable mirror; §10's stage 1 carries a test that walks both and fails if
they disagree, so the two can never drift apart silently. Placing it on the enum
also means the definition survives `Order` being reconstituted, serialized, or
read by anyone who never has an `Order` instance in hand.

**`OrderStatus` loses `FULFILLED` in the same edit** (§0 item 2, §10 stage 1) and
gains `CONFIRMED`, `SHIPPED`, `DELIVERED`, `REFUNDED` — four new cases, one
removed, and no `RETURNED`. **There is no mutator for the returns themselves**: a
return that leaves units outstanding changes no status (§2.3), so the return lives
in §5.2's service and only the ones that *empty* the order reach `refund()`.

**One narrow exception to §0 item 3's immutability.** `$status` stops being
`private readonly` (`Order.php:62`); the other 22 constructor properties stay
`readonly`, and `$status` is mutated only inside `transitionTo()`. This is the
same shape `SaleLine::assignTransactionId()` already uses — a structural field
gets a narrow, deliberate mutation path while every business fact stays frozen —
with one difference worth naming: status *is* a business fact, and it is mutable
because §3's three-statuses rule requires it to be the one place the lifecycle's
current answer is kept, next to two append-only ledgers.

**What the domain half deliberately does not guard.** `deliver()` carries no
guard beyond the matrix: every order that can reach `delivered` has been shipped
(§2.1), and R10's payment question belongs to the *service* because it is a read
the aggregate does not own. Nothing here branches on `deliveryType`
(`Order.php:409`), which stays readable as the order's own fact — a delivery is a
delivery whether the parcel went to a street address or to a courier office.

**`InvalidOrderTransitionException`** joins `InsufficientStockException` and
`CannotPublishEmptyVariableProductException` in the package's `Exceptions/`
directory: it carries the from/to pair as values (not just inside a message
string) so a caller can render or branch on them, and exists so no caller has to
interpret a `LogicException`'s text.

**What the domain half deliberately does not do.** No timestamps: the instant a
transition happened is an event fact recorded once, by §6, and a second copy on
the order row would be the same fact in two homes. No payment, stock or refund
knowledge: those are the reads §5.2 performs and the rules R7/R8/R9 state, and none
of them is a constructor property except the status itself. No `assignId()`-style
one-time guard on transitions — an order legitimately moves through its chain, and
the *terminal* statuses plus §2.1's matrix are what stop it, not a counter.

### 5.2 The app half: `App\Services\OrderStatusChanger`

The one transactional unit. Public API — one thin method per §5.1 move, the shape
`ProductStatusChanger` already uses:

```php
public function confirm(string $orderId, DateTimeImmutable $occurredAt, ?string $note = null): void
public function ship(string $orderId, DateTimeImmutable $occurredAt, ?string $note = null): void
public function deliver(string $orderId, DateTimeImmutable $occurredAt, ?string $note = null): void
public function cancel(string $orderId, DateTimeImmutable $occurredAt, ?string $reason = null, array $restock = []): void
public function recordReturn(string $orderId, array $returnedLines, DateTimeImmutable $occurredAt, ?string $reason = null): void
```

`$occurredAt` is explicit rather than `now()` inside the service, exactly as
`CheckoutOrchestrator::place()` takes `$placedAt` — one clock, supplied by the
caller, so a test can assert on it and a back-dated record stays possible.

**The two maps, and why a cancellation takes one.** `$returnedLines` is
`["<saleLineId>" => ['quantity' => int, 'restock' => bool], …]` — a sparse map of
what physically came back, keyed by line id rather than restating the order, which
is what makes a partial return expressible in one dialog (§8.4). `cancel` takes the
same map as `$restock`, and an empty one means *every remaining unit of every line,
restock true* (R1/R3); the key is accepted only from `shipped`, because from
`placed`/`confirmed` the goods never left and the flag would be a question with one
possible answer. Nothing infers either map: no caller may pass "the whole order" in
place of a quantity, and no default silently restocks a unit the merchant said was
lost.

**A reason is free text and optional — for a cancellation and a return alike.**
Neither `cancel` nor `recordReturn` refuses an empty reason. The merchant's note is
stored verbatim and untranslated in `order_events.reason` (§6.1); what the panel
translates is the *machine's* refusal reason — a named enum rendered by one helper
(§8.3) — never the operator's own words. The two are different kinds of text and
stay different on purpose: the operator's prose is data about this order, a refusal
is UI copy about a rule. §14 Q4 confirms that a return's reason stays optional,
which is `operational-sales-domain-design.md` §3.13 Q4(b)'s own decision rather
than a new one.

**No method takes an amount, and none takes a publication flag.** `Money` is
`EasyCo\Pricing\Money`, the same type `Payment::amount()` and
`PaymentRefund::amount()` already take (`Payment.php:196`, `PaymentRefund.php:139`),
but every amount this service writes is *derived* — from the returned units' own
cumulative shares (R8) and the locked payment's own remainder — so a refund can
never be typed in and §7.3's sum cannot disagree with the goods ledger. Neither
call takes a `$relist`/publication flag: a return restocks and never touches a
Product's publication state, for the reasons §7.2 gives (§14 Q7).

**Every method does the same six things.** The private worker owns steps 2–6; a
public method adds only its own parameter validation and its own guards.

1. **Validate parameters** before any lock, so a malformed call never opens a
   transaction: non-empty `$orderId`, a non-empty `$returnedLines` map for a
   return, positive integer quantities, and a `$restock` map accepted only from
   `shipped` — `InvalidArgumentException`, the class
   `ProductStatusChanger::requireProduct()` already throws for "does not exist"
   (`:149`). The reason is deliberately *not* validated here: it is optional for
   both methods that take one (§5.2 above).
2. **Open one `DB::transaction()` and re-read the order under a row lock.**
   `OrderRepository` gains `findByIdForUpdate(string $id): ?Order`, implemented as
   `OrderModel::query()->lockForUpdate()->find($id)` and reconstituted through the
   same mapping `findById()` uses. This one addition is what §0 item 4 identified
   as missing: without it every guard below reads a status that can change before
   the write lands. A missing order is refused right here, by the same
   `InvalidArgumentException`.
3. **Idempotence: `$order->status() === $target` returns immediately** — no
   domain call, no write, no `order_events` row, no hook. This is
   `ProductStatusChanger::applyStatus()`'s documented behaviour ("a DRAFT ->
   DRAFT or ARCHIVED -> ARCHIVED 'transition' is a silent no-op here (no log
   entry, no write)", `:31-42`) applied to the double-click case. The *domain*
   still refuses a same-status transition (§5.1): strictness in the aggregate,
   forgiveness in the UI-facing service — the only layer that can tell a retry
   from a bug.
4. **Service-owned guards**, all reading facts the aggregate cannot see, and all
   after the lock — a guard whose read is not covered by the same lock as the
   write it protects is decoration. R9 (`ship`: the transfer must have arrived),
   R7's remaining-units read and R8's remaining-refundable read (`cancel` from
   `shipped`, and `recordReturn`), R5's derived permission whenever the money step
   will run (§8.3), and R11's promotion lookup on `cancel`.
5. **The domain call, named explicitly.** Each public method calls its own §5.1
   method (`deliver()` for target `delivered`, `refund()` for target `refunded`,
   …). The worker takes the method to call rather than deriving it from the target
   status, because the target alone does not identify the operation: `cancelled`
   is legitimately reachable from three statuses, and only the caller knows which
   one it is acting from. No string-to-method magic, one line per public method.
   Then `OrderRepository::save($order)`, on the status it just changed.
6. **Side effects, still inside the transaction**, in this order: §7.2's `REFUND`
   lines on the return's own `Transaction`, §7.1's stock, §7.3's money step,
   §7.4's promotion release when the order is on its way to `cancelled`, then
   R6's compare-and-set (§2.3) and **one `order_events` row** (§6).

**`cancel` and `recordReturn` share one implementation of "the goods come back"
(R2).** A private worker turns a line map into steps 4–6's return half — the
`REFUND` lines, the stock, the money step, R6's compare-and-set — and the two
public methods differ only in how that map is built: `cancel` fills it from every
`SALE` line's remaining units (restock flags from its own argument), `recordReturn`
takes the operator's. This is what keeps a cancellation from `shipped` and a
partial return from drifting apart, and it is why "how much is left to return"
(R7) and "how much may still be refunded" (R8) are computed in exactly one place.

**After the transaction commits**, hooks fire (§12): `order.status_changed` for
every transition, plus each specific one — `order.cancelled` (all three
cancellations), `order.returned` (every recorded return, partial included, and
also a cancellation from `shipped`, because the goods really did come back),
`order.refunded` (only when money actually went back) and
`order.payment_confirmed` (§4.3's caller). Deliberately outside the transaction, so
a listener can never roll back a recorded fact, and no listener can hold the
order's row lock while doing external work.

**Errors are not wrapped.** `InvalidOrderTransitionException`,
`InsufficientStockException`, and each guard's own exception surface as they are —
the posture `EloquentPaymentRepository`'s class docblock states for the
`captured_order_id` violation ("a database-engine-level safety net … catching and
wrapping it is a decision for whoever writes the orchestration"), applied here to
the panel: the service refuses truthfully and §8.3's actions turn the refusal into
the merchant's message.

**Two things this section does not fix, named rather than implied.** (1)
`EloquentOrderRepository::save()` still rewrites all 23 order columns
unconditionally (§0 item 4) — the lock makes that safe *for callers that use it*,
and §11 records the standing risk for anyone who writes an order without it. (2) An
`$expectedStatus` parameter in the shape `VariationAxisRestructure::apply()` uses
(`:117`, "the fingerprint of the plan the caller SHOWED the merchant") was
considered and **not** adopted: it refuses a stale page loudly, but it guards only
the write, while R7/R8/R9 guard *reads* that need the lock regardless — and a
shipment has no reviewable plan for a fingerprint to describe. §8.3's actions are
the stale-page layer instead.

---

## 6. `order_events`: the order's always-on history

§0 item 8 is the whole reason this table exists: `ActivityLogger` writes nothing
unless an admin Site Setting is `'1'` (`:112`) and its rows are deleted by
`activity-log:prune` after `admin.activity_log_retention_months` (default 12,
`PruneActivityLog.php:54`, `:60`). An order's history cannot be conditional or
expiring — it is the record a merchant needs a year later to answer "did this ever
ship, and did we give the money back?". So the order gets its own journal, and
`ActivityLogger` keeps its existing job unchanged (§0 item 8).

**Shape: a table, an Eloquent model, and one always-on writer — no domain entity,
no repository.** The precedent is `activity_log` itself: plain infrastructure with
a plain `App\Models\ActivityLogModel`, written through the model by
`App\Services\ActivityLogger`, deliberately *without* a package/domain layer,
because it is "a factual record, not a protected business invariant"
(`ActivityLogModel.php:7-13`). `order_events` is the same kind of thing with the
same shape: `App\Services\OrderEventRecorder` writes it, and §8's View page reads
it through `OrderAdminReader` (D5's single-reader rule), so nothing else in the
codebase touches the table.

### 6.1 The table

```php
Schema::create('order_events', function (Blueprint $table) {
    $table->id();

    $table->foreignId('order_id')
        ->constrained('orders', indexName: 'oe_order_id_foreign')
        ->restrictOnDelete();

    $table->string('type');
    $table->string('from_status')->nullable();
    $table->string('to_status')->nullable();
    $table->text('reason')->nullable();

    $table->foreignId('transaction_id')->nullable()
        ->constrained('operational_sales_transactions', indexName: 'oe_transaction_id_foreign')
        ->restrictOnDelete();

    $table->foreignId('staff_id')->nullable()
        ->constrained('staff', indexName: 'oe_staff_id_foreign')
        ->nullOnDelete();
    $table->string('staff_name')->nullable();

    $table->timestamp('occurred_at');
});
```

Column by column, with the precedent each one follows:

| Column | Why |
|---|---|
| `order_id` | Real FK, `restrictOnDelete()` — the posture `orders` itself takes toward `transaction_id`/`client_id` (`create_orders_table.php:57-63`), and the honest alternative to a cascade that would delete history. No order-deletion path exists anywhere; the constraint *proves* that rather than assuming it. |
| `type` | The operation, named once and human-legible: `status_changed`, `payment_confirmed`, `returned`, `refunded`, `payment_voided`, `note_added`. A plain string, like every other enum column here (`orders.status`, `sale_lines.type`). `note_added` is the one value that is not a change to the order at all — an internal note an operator left on it (owner decision D5): both statuses NULL, like every other event that moved nothing, and `reason` carries the note itself, non-blank, because a note nobody can read is not a note. |
| `from_status` / `to_status` | §5.1's enum values, as status names. Both NULL exactly when nothing transitioned — a partial return, §4.3's `payment_confirmed` and §7.3's `payment_voided` are real events with no status change (§3 item 1), and NULL/NULL is how they say so. |
| `reason` | The operator's own words, verbatim and untranslated: a cancellation's reason, a return's reason, a free-text note on a transition — or nothing, since §5.2 makes all of them optional. One column, because all of them answer the same question — *why did this happen* — and `Payment.failureReason`/`PaymentRefund.failureReason` already establish "one nullable text column, set only when it means something". |
| `transaction_id` | The return's own `Transaction`, nullable, where the goods half of a return lives (§7.2). The event says *which* transaction to read for the per-line quantities instead of copying them into the event (§6.4) — and it is a real FK with `restrictOnDelete()`, the posture `orders.transaction_id` already takes (`create_orders_table.php:61-63`), so a return's record cannot be deleted out from under the order's own history. NULL for every event that is not a return. |
| `staff_id`, `staff_name` | The actor, copied from `activity_log` verbatim, including the `staff_name` **snapshot** (`create_activity_log_table.php:18-23`): a log entry stays meaningful after the staff member is renamed or deactivated, and `nullOnDelete` means a soft-deleted staff row never takes the order's history down with it. Both NULL for a console/job caller. |
| `occurred_at` | §5.2's `$occurredAt` — the instant the fact happened, caller-supplied, like `placed_at`. |

**No `updated_at`, and no `$table->timestamps()`.** `ActivityLogModel` sets
`public $timestamps = false;` for exactly this reason, and an append-only row has
no update to stamp. `occurred_at` is the only timestamp this table needs: unlike
`SaleLine`, it carries no second "when the sale was rung up" fact, so §3.5's
`recorded_at`/`effective_at` pair collapses to one here — the two would always be
equal except for a back-dated correction, which is precisely what `occurred_at`
is for.

**No index beyond the FK's own.** Every query is `WHERE order_id = ? ORDER BY
occurred_at, id`; the FK's generated index already narrows to one order's handful
of rows, and no query in this design crosses orders. A composite
`(order_id, occurred_at)` index would be decoration — and this project adds an
index only when a real query needs it
(`2026_09_25_000001_add_priceable_id_index_to_operational_sales_sale_lines_table.php`
was added on exactly that basis).

### 6.2 The writer: `App\Services\OrderEventRecorder`

One public method, called by §5.2 and §4.3 **inside** their own transactions:

```php
public function record(
    string $orderId,
    OrderEventType $type,
    ?OrderStatus $fromStatus,
    ?OrderStatus $toStatus,
    ?string $reason,
    ?string $transactionId,
    DateTimeImmutable $occurredAt,
): void
```

- **`$type` is `OrderEventType`, not a `string` as first written here.** The enum
  (`App\Enums\OrderEventType`) is the six values above in one place: the writer
  cannot be handed a type no label exists for, a caller cannot mistype one, and
  the label-parity test can walk every case. The stored column stays the plain
  string §6.1 describes — the writer stores `$type->value`.

- **`$transactionId`, never the quantities.** A return's event points at the
  `Transaction` its `REFUND` lines were written into (§7.2) rather than storing a
  map like `{"<saleLineId>": 2}` itself: the lines *are* the record, the event is a
  signpost to them, and a copied map would be a second home for the same numbers —
  the shape §11 item 11's "no derived column" rule keeps out of the schema. The
  `order.returned` hook still carries the map in its payload (§12), because a
  listener has no ledger query of its own.

- **Always on.** No Site Setting gate, no early return — the entire point of §0
  item 8. `admin.activity_log_enabled` keeps governing `activity_log` only.
- **No prune command, no retention setting.** There is no age- or count-based
  deletion path; §11 records that this is a deliberate permanent commitment, not
  an oversight.
- **The actor is resolved internally** from the panel guard
  (`Filament::auth()`), exactly as `ActivityLogger::currentStaff()` does
  (`:131-136`) — so no caller passes a staff id, and a console caller records
  NULL rather than failing.
- **Never updated, never deleted.** The only statement this class ever issues is
  an insert.
- **Called inside the caller's transaction, not after it**, so a rolled-back
  transition leaves no event behind: the event is part of the same fact. §5.2's
  hooks — deliberately after commit — are the only post-transaction step.

### 6.3 The read path

`OrderAdminReader::forOrder()` gains an `events` list on `OrderAdminOrderView`,
read as one query ordered by `occurred_at, id` — the id breaking ties between two
events recorded in the same second, the same tie-breaking
`latestPaymentColumnSubquery()` already applies with `attempted_at DESC, id DESC`.
A second, deliberate consequence of that ordering: the reader needs no join to
`staff` — `staff_name` is already on the row (§6.1).

- **Only the View page reads it; the list page does not.** The list's query shape
  stays exactly as documented in `admin-panel-design.md` §Orders: no per-row
  events query, no N+1, no "latest event" correlated subquery. D5's single-reader
  rule exists so that page's cost stays knowable, and a timeline belongs on the
  page a merchant opens deliberately.
- **Events are shown, never recomputed.** The reader renders what the writer
  wrote, in write order (§3 item 6). It derives no status from the events and no
  event from a status — the timeline can therefore disagree with `orders.status`
  only by being *longer* (it records history the status has since moved past),
  never by being wrong about it.

### 6.4 What deliberately does not go in `order_events`

- **Facts that already have a home.** Stock movements live in
  `inventory_stock_levels` (`inventory-domain-design.md` §5-§7), refund amounts in
  `payment_refunds`, per-line quantities in `operational_sales_sale_lines` — the
  event points at the return's own `Transaction` (§6.1's `transaction_id`) and
  never duplicates its numbers. What the timeline needs is *that* something
  happened, *when*, *by whom* and *why*; how much came back is one query away.
- **A general audit log.** `activity_log` keeps that job. Two journals with two
  lifetimes, one job each; the stock a return moves is a third record, and it
  lives in inventory (§7.1).
- **Anything a merchant has not done.** No `viewed`/`printed`/`exported` events:
  an event is a change to the order or to the money recorded against it, not a
  page impression. (If a report ever wants "who opened this order", that is
  `activity_log`'s job, not this table's.)

---

## 7. Side effects: what each operation actually writes

Every operation in §5.2 runs its side effects *inside* its own transaction, after
the guards and after the status write, and each side effect has exactly one home.
This section is that map; §5.2 owns the ordering.

### 7.1 Stock: the units that physically came back go back on the shelf

- **Which lines, and how many.** For a return: the lines the operator listed, for
  the quantities counted back. For a cancellation from `shipped`: every `SALE`
  line's remaining units — its own `quantity()` minus what has already been
  returned against it (R7). For a cancellation before shipping: every `SALE`
  line's `quantity()`, unconditionally, because the goods never left (R3).
- **Which of those actually go back: R3's flag, per line.** `restock = true` calls
  `StockLevelRepository::increase()`; `restock = false` writes no stock at all — a
  lost or refused parcel is not stock, and a defective unit is the merchant's own
  decision about their own shelf. The flag is never inferred from the status, the
  reason or the quantity.
- **How.** `increase($variationId, $quantity)` per line — the atomic
  single-statement `UPDATE … SET quantity = quantity + ?` that
  `inventory-domain-design.md` §6/§7 defines, called once per line rather than
  loaded-mutated-saved, for the same reason `decrease()` is documented that way:
  the read-modify-write round trip is the race.
- **`increase()` gets its first caller here.** Its docblock still says "No caller
  exists yet" and that is still true for `increase()`; the sibling claim on
  `decrease()` is stale — `CheckoutOrchestrator.php:291` already calls it. Worth
  correcting in the same pass as this feature (§10 stage 6), because the pair's
  docblocks should not disagree about which half is wired.
- **Nothing is returned for a line whose variation has no stock row.** `increase()`
  is specified to create the row when it is missing, so a cancellation after an
  inventory reset cannot lose the units silently.
- **No quantity is ever inferred.** A line's own `quantity()`, minus what R7 sums
  back, is the number; the order's "unit count" is never recomputed by summing
  across lines with different variations.
- **`ship`, `deliver`, `confirm` and the money step touch no stock.** Units have
  already left the shelf by placement time (checkout decrements at placement — this
  design does not move that boundary), so shipping them changes no quantity, and
  R4's refund writes money, never goods.
- **Idempotence falls out of the checks that already had to be made, not a stock
  flag.** A return's quantities are validated against R7's remaining-units read, a
  repeat `cancel` on a `cancelled` order returns early at §5.2 step 3, and stock
  moves only inside the same transaction as the record that justifies it — so the
  units cannot be added twice. That is why there is no "was restocked" column
  anywhere.

### 7.2 Returns: one `Transaction`, its `REFUND` lines, stock, then the status

A return writes four things, in this order, and each has exactly one home. Nothing
here is conditional on the return being "full": a return of one unit of one line
goes through exactly this code.

1. **One new `Transaction` (`channel = WEB`) holding one `REFUND` `SaleLine` per
   returned line.** A return is its own event — it happens days or weeks after the
   sale, and it is neither a POS ticket nor a correction of the placement
   transaction — so it gets its own transaction rather than appending lines to the
   order's placement transaction. `orders.transaction_id` keeps pointing at what
   checkout wrote, untouched and still readable as "what was sold"; the return's
   transaction holds "what came back". Nothing else is needed to create it:
   `Transaction` is `id` + `channel` (`Transaction.php:28-32`) and its table holds
   exactly those two columns plus timestamps
   (`2026_08_25_000002_create_operational_sales_transactions_table.php:11-18`).
   `operational-sales-domain-design.md` §3.4/§3.13 own the `REFUND` line's field
   set; this document supplies the values:
   - `originatingSaleLineId` — the `SALE` line the units came from, which is what
     makes R7's cumulative read expressible: "already returned" is a query over
     `REFUND` lines sharing that id, not a counter on the `SALE` line.
   - `type = REFUND`, `status = SaleLineStatus::COMPLETED` — the line records a
     refund that has happened, and `SaleLineStatus` has exactly this case.
   - `returnedBy` — the authenticated staff id (§3.13 Q4(a)).
   - `returnReason` — the optional free text (§3.13 Q4(b), §5.2).
   - `displayPriceAtReturn` — a live `PriceResolver` read, nullable and
     informational only, exactly as `SaleLine`'s own docblock frames that field.
   - `recordedAt`/`effectiveAt` — §5.2's `$occurredAt` (§3.5's pair; the same
     instant for both, because this is not a back-dated POS correction).
   - `quantityReturned` — the counted-back units: what R7 sums, and what R8's
     cumulative share is computed from.
   - `defaultRefundAmount`/`actualRefundAmount` — §3.13's two facts, and **this
     lifecycle sets both to the same value**, the cumulative share R8 derives.
     §3.13 lets a register operator override `actualRefundAmount` for a goodwill
     over- or under-payment; that override is deliberately not exposed on this
     path, because §5.2 takes no amount and a `REFUND` line whose two amounts
     disagreed would let the goods ledger and the `PaymentRefund` row say
     different things about one return (§14 Q3 is where a hand-back outside the
     record belongs).
   - ⚠️ **One value stays with the operational-sales pass**: the existing `quantity`
     column is asserted `> 0` by the constructor, and §3.4 does not say what a
     `REFUND` line's `quantity` holds when it differs from the returned count. §14
     Q6 records the recommendation (`quantity` = the *original* line's quantity, so
     one row reads "2 of the 5 that line sold") rather than guessing here. The ⚠️
     is narrow on purpose: R7's sum runs over `quantityReturned`, so every guard
     behaves identically whichever way Q6 is answered.
2. **`StockLevelRepository::increase()` for every line whose flag says so** —
   §7.1's mechanics, by the returned count. A line the operator returned with
   `restock = false` writes no stock at all (R3).
3. **No Product state changes at all.** This is the one place this document
   overrules an apparent invitation: the return does **not** relist, republish or
   restore anything. A unit physically returning is a stock fact; whether its
   variation may be sold *online* again is a catalog decision with its own
   consequences (media, axes, price) that a returns screen must not make silently.
   So: restock, and if the product/variation is currently archived or draft, the
   confirmation says so and links to the product — the merchant publishes it when
   they mean to. §14 Q7 asks the owner to confirm this, because it overrules
   `operational-sales-domain-design.md` §3.13 Q4(c)'s *online* half: that
   document decided an online return "always increases stock AND makes the
   variation sellable", activates an inactive parent Product so the stock is not
   unsellable, and (for an archived variation) restores it through
   `Product::restoreArchivedVariation()`. This path does the stock half only —
   Q7 lists each of the three state changes it declines, rather than leaving the
   difference to be discovered by whoever reads both documents.
4. **§7.3's money step, then the status only if the return emptied the order.**
   §2.3's table is the whole rule and is not restated here. There is no "partial
   return mode" in the service: one implementation always writes the lines, the
   stock and the money, and §2.3's read is the only thing that decides whether a
   terminal status is entered — §3 item 5's "a record, not a status" made
   mechanical.

### 7.3 The money step: a `PaymentRefund`, a void, or nothing

The money half of a cancellation or a return (R8), and the only place either one
writes money. It runs inside §5.2's transaction — after the goods (§7.2), before
R6's compare-and-set — through one class, `App\Services\OrderRefunder`, so that
"how much may still go back" is computed in exactly one place, under the payment's
own row lock (payment §5.2's invariant).

**The three cases, and nothing else.** R8 states them; this is what each one
actually writes:

| The payment when the operation runs | What is written | Why |
|---|---|---|
| settled (`isSettled()`) | one `PaymentRefund` for the returned units' share, plus one `order_events` row of type `refunded` | the money really left, and the goods ledger and the money ledger have to agree about how much (R8(a)) |
| `pending`, and the return reduces what the customer still owes | the current row's `voided_at`, a **new** `pending` payment for the remainder — or no new row when nothing remains — plus one `payment_voided` event | append-only: the customer was never told a smaller amount, so what is owed changes by adding a row, never by editing the old one (R8(b)) |
| nothing settled, nothing pending | nothing at all | there is no *recorded* money to move (§14 Q3) |

- **Which payment: derived, never asked for (§4.5, R5, R8).** For a refund,
  `PaymentRepository::findSettledForOrder($orderId)` — newly added for this
  feature, because the existing `findLatestForOrder()` returns the most recently
  *attempted* payment, and a failed retry would otherwise be the thing refunded.
  "**Current payment**" is defined once, here, and is what §8.4's panel shows: *the
  newest payment row for the order by `attempted_at DESC, id DESC` whose
  `voided_at` is NULL* — the row the order's obligations currently live on. A
  refund targets the settled row; a void and the reissued row target the current
  one.
- **How "voided" is represented: a nullable `voided_at` on `payments`, not a
  fourth `PaymentStatus`.** Two reasons, and the second is the decisive one.
  First, a void has the exact shape `confirmed_at` has — a fact a *merchant*
  records on a row an *adapter* already answered — and §4.1-§4.4 solved that shape
  with a nullable timestamp, not a status. Second, `PaymentStatus`'s own docblock
  argues against exactly this addition in its own words: "an attempt either
  captures or it doesn't … PENDING remains a fully legitimate, final status for an
  offline method, distinguished from a crashed/never-completed attempt by
  attemptedAt, not by adding a status here." A void is not the attempt's outcome —
  the attempt was answered as `PENDING` and always will have been — it is the
  order's *requirement* shrinking afterwards. So: `Payment` gains
  `void(DateTimeImmutable $voidedAt): void` (one-time, refusing anything but a
  `pending` row with an answered attempt, mirroring §4.1's guards),
  `isVoided()`, and `voidedAt()`; `payment-domain-design.md` §2's field list and
  §5.1's constraint list gain the column and one sentence. Nothing about the
  money-side invariants moves: `captured_order_id` and `settled_order_id` are
  computed from `status`/`confirmed_at`, so a voided row contributes NULL to both
  (§4.4), and the two unique indexes keep meaning exactly what they document.
- **The reissued row is a real row, and it sets `attempted_at`.** The remainder
  payment is a fresh `payments` insert — same order, same method, `PENDING`, amount
  = the current payment's amount minus the returned units' shares — written in the
  same transaction that voids the old one. It sets `attempted_at` to the instant it
  was created, which is what checkout already does for a first attempt (§0 item 5)
  and what §0 item 7 requires of every writer: without it, the newest row would
  lose to the older answered one in the panel's `attempted_at DESC, id DESC` rule
  and the merchant would keep looking at the row that was just voided. The current
  payment is therefore well defined at every instant, and the panel's latest-payment
  rule needs one addition rather than a rewrite: both of `OrderAdminReader`'s
  payment reads (`latestPaymentColumnSubquery()` `:152-160`, `forOrder()` `:229-234`)
  add `whereNull('payments.voided_at')`. With every row voided and nothing owed, the
  section renders D8's normal "—", which is the honest answer.
- **The record.** `PaymentRefund` created through its own constructor, which
  already enforces the whole shape (`PaymentRefund.php:31-65`): a positive
  `amount` (zero and negative are refused — `assertPositiveAmount()`), a
  status-matched `failureReason`, and an optional `reason` that §5.2 passes through
  from the operator's own words, verbatim and untranslated.
  - `paymentId` — the settled payment's id.
  - `amount` — the returned units' share: R8(a)'s cumulative difference,
    `cumulative(r + k) − cumulative(r)`, summed over the lines this return covers.
    A full return's lines happen to sum to the whole payment, and the *sum* is what
    says so — there is no "refund everything" shortcut and no amount parameter
    (§5.2). The remainder is a *read*, not a stored field: "already refunded" is
    `PaymentRefundRepository::sumCompletedForPayment($paymentId)`, and a share that
    would exceed it is refused (R8(a)). No `remaining_amount` column, no counter on
    `Payment`, no `refundable` boolean.
  - `refundedBy` — the authenticated staff id, resolved the same way §6.2 resolves
    the event's actor.
  - `status` — whatever the method adapter's `refund()` returns. For V1's two
    offline methods that is `COMPLETED` (`CashOnDeliveryPaymentMethodAdapter::refund()`,
    `BankTransferPaymentMethodAdapter::refund()` — both unconditional), which is
    what makes the offline flow finish inside one request.
- **The permission is derived, never chosen in the UI** (§2.2 R5): the payment's
  `method` picks the permission — `REFUND_CASH` for the cash-like method,
  `REFUND_BANK` for the transfer method — and the panel **shows** which one a given
  order needs instead of offering a permission picker. §14 Q8 asks the owner to
  confirm the two-item mapping; a future online method has no permission of its own
  today, which the same question records. Because the money step happens *inside* a
  cancellation or a return, the action that can refund carries the derived
  permission **as well as** `ORDER_MANAGE`, decided per order from the payment's own
  method (§8.3).
- **A refund the adapter refuses: V1 cannot reach it, and the rule is written down
  anyway.** Both offline adapters' `refund()` are unconditional and answer
  `COMPLETED` inside the same request (`CashOnDeliveryPaymentMethodAdapter::refund()`,
  `BankTransferPaymentMethodAdapter::refund()`), so the failure branch belongs to
  the first adapter that talks to a network. The rule for whoever builds it: the
  goods record and R6's status do **not** depend on the money's answer — the return
  physically happened — so a refused refund is written as its own row (with its
  `status` and `failureReason`) and completing it is the payment domain's
  multi-attempt business (§11 item 14, §9). What this design will not do is roll the
  goods back to keep two ledgers looking consistent: that edits a fact to flatter a
  failure.
- **Waiting for money is not a status, and money is not the order's end.** There is
  no "refund pending" value in `OrderStatus`, deliberately (§1, §3 item 2), and R4
  keeps money out of §2.1's table entirely: an order can be `cancelled` or
  `refunded` while a `PaymentRefund` row is not `COMPLETED`, and that row is read
  where it lives, in `payment_refunds`, with §8.4's payment block naming it.
- **Two rows, one fact, on purpose.** A completed refund writes the `PaymentRefund`
  and §7.3's `order_events` row (`type = refunded`), and the actor appears in both —
  `payment_refunds.refunded_by` and the event's `staff_id`/`staff_name` — deliberately:
  each record stays self-contained for its own reader (the payment side never joins
  the order journal, §6.3's reader never joins `staff`), the same "snapshot the
  context onto the record" reasoning `SaleLine`'s `productName`/`sku` and
  `activity_log.staff_name` already use. The amount is **not** duplicated the other
  way round: the event carries none, because `payment_refunds.amount` is its one
  home.
- **A partial refund is not a smaller version of the status.** Every refund this
  design writes covers *some* units, and none of them moves a status: the terminal
  statuses come from R6's goods test (§2.3), never from a money sum.
  `sumCompletedForPayment($paymentId)` is what R8(a) uses to cap a share — the two
  sums answer two different questions, which is why R7 and R8 are stated separately.
- **Online refunds will need one thing this design does not build.** An external
  gateway call inside `DB::transaction()` is a hazard: when a real online refund
  adapter exists, the call must move outside the transaction — record intent,
  call, record the result — which is the shape `PaymentStatus`'s own multi-attempt
  design already anticipates. §11 carries this as a named constraint for the
  future, not as V1 work.

### 7.4 The two reads outside the order that a cancellation changes

A promotion redemption and the account's own order count are the only facts outside
this lifecycle that a cancellation moves, and they are also the only place where
"the order did not happen" has to be said to something other than the order.

- **A redemption is released only when the order becomes `cancelled` (R11).**
  `promotion_redemptions` gains a nullable `released_at`, `PromotionRedemption`
  gains a one-time `release(DateTimeImmutable $releasedAt)` plus `releasedAt()`, and
  §5.2's cancel path calls it inside its own transaction — the same shape §4.1 uses
  for `confirmed_at`: a nullable timestamp recording a fact that changes what later
  counts mean, never an edit of the placement fact. No separate `order_events` row:
  the cancellation's own event is the record that it happened. A return after
  delivery does **not** release — the customer bought, the merchant fulfilled, the
  money moved and came back — and re-opening a spent slot for a completed sale would
  hand back a limit the merchant deliberately set. Both count queries therefore
  exclude released rows (`EloquentPromotionRedemptionRepository::countForPromotion()`
  and `countForPromotionAndAccount()`, `:35-45`), which is the whole of the change on
  the storefront's side: a released redemption stops counting against
  `usage_limit_total`/`usage_limit_per_customer`, and remains in the table, in
  `applied_promotion_code` and in the panel's history.
- **A cancelled order stops counting as "this customer has bought before"; a
  refunded one still counts.** `EloquentOrderRepository::hasAnyForAccount()` filters
  on `account_id` alone today (`:61-64`) and gains one condition — the status is not
  `cancelled`. `OrderRepository`'s own docblock says the opposite in words ("An
  order in any status counts, including CANCELLED: they did place one",
  `Contracts/OrderRepository.php:19-27`), so the docblock is corrected in the same
  commit: the sentence and the query must not disagree.
- **Why those two directions differ, since they look inconsistent.** They are
  different questions. `new_customers_only` asks *is this customer new to us*, and
  an order the merchant called off — or the customer refused — never became a
  purchase: counting it grants a first-purchase discount to someone with nothing to
  show for it, and grants it on the next order, which is the one place the discount
  matters. A `refunded` order is the opposite case: the customer really did buy, the
  goods really were delivered, and only then came back — treating a return as "never
  bought" would let anyone farm a new-customer discount by buying and returning. The
  redemption release runs the other way for the same underlying reason: what it
  undoes is *this code was spent on an order that did not happen*, and it is only
  ever re-opened for a cancellation, never for a completed sale that came back.
- **Nothing else on the promotion path changes.** `PromotionValidator`,
  `PromotionDiscountCalculator` and `PromotionUsageContext` are read as-is, and
  `PromotionUsageContextAssembler` learns nothing new: it already calls both count
  methods and `hasAnyForAccount()` behind their own setting guards
  (`app/Services/PromotionUsageContextAssembler.php:50-62`), so both changes land
  behind the guards that already exist and no caller gains a parameter.

---

## 8. Admin: the actions that perform these transitions

Everything so far is domain and service. §8 is the merchant's only way in, and it
begins by amending a decision rather than extending one.

**`admin-panel-design.md` §14's D1 — "strictly read-only" — stops being literally
true here, and that is stated rather than smuggled in.** The Orders screens today
are strictly read-only in every sense the design meant: `OrderResource::form()`
returns an empty schema (`OrderResource.php:111-114`), the list has five columns
and exactly one filter (`:166-203`), and both pages return `[]` from
`getHeaderActions()` (`ListOrders.php:30-33`, `ViewOrder.php:20-23`, each with its
own "D1: strictly read-only" docblock). A button that writes a status is not
read-only, so this document says so, and says what does **not** change:

- **No create, edit or delete page, and no bulk actions.** `getPages()` still
  holds exactly `index` and `view` (`:206-212`); no form is ever submitted,
  because the writes below go through services, never through Eloquent.
- **D2 (snapshot, never live), D3, D4 (no profit anywhere) and D5 (one reader)
  stand unchanged**, as do the five columns, the single payment-method filter and
  the one-read-per-record shape of the View page (`:214-240`, `:413-415`).
- **The list page stays a reading surface.** Only the View page gains actions
  (§8.1) — the asymmetry with the products list's own row status buttons
  (`admin-panel-design.md` §13.9) is deliberate and is argued below.

### 8.1 Where the actions live: one surface, and it already exists

**The View page's header, and only there.** `ViewOrder::getHeaderActions()`
becomes one call into the Resource — `return OrderResource::orderActions();` —
because a page-level method is not testable without Livewire while a plain public
static method on the Resource is, which is the shape `ProductResource` already
uses for every one of its actions (`bulkArchiveAction()`, `statusViewButtons()`,
`ProductResource.php:2377`, `:2476`). The Resource stays the one place that knows
which actions exist; the page stays a two-line adapter.

**Why not the list page, per row.** Both writing operations need something a row
cannot supply: `cancel` and `recordReturn` each take a per-line map — quantities and
restock flags (§8.4) — and an optional reason in the operator's own words (§5.2). A
row of five columns has no room for either, and an action that opens a modal to
ask for the missing facts is a View page hiding behind a table. §13.9's products
list shows the dividing line exactly: `publish`/`archive` are unconditional flag
flips with nothing to ask and nothing to lose, so a row button is right for them;
an order's moves are none of those things. The list's `getHeaderActions()` stays
`[]`.

**Payment confirmation (§4) lives on the same page, in the payment section.** It
is not a transition (§4.3) and does not belong in a status-action list, but it is
the operator's other write on this screen: "the transfer arrived" is recorded
from the order in front of them. It gets its own action beside the payment
entries, gated by `ORDER_MANAGE` like the rest.

**"Add internal note" is the page's third write, and it is not a transition
either.** One action, gated by `ORDER_MANAGE`, readable by anyone who can open the
order at all (`ORDER_VIEW`): it records a single `note_added` event (§6.1) and
moves no status — nothing on the order changes except its own history, which is
what makes it the one action here that cannot refuse. Its UI belongs to stage 7,
like every other action in this section.

### 8.2 Which buttons exist: visibility is derived from §2.1, never re-listed

**Every action's `->visible()` is two questions, and the matrix answers the first
one.** `OrderStatus::canTransitionTo()` (§5.1) is what decides whether a move is
*offered*; the second question is the permission (§8.3). Writing a second,
hand-kept "which buttons appear at which status" list in the Resource is exactly
the drift §5.1's enum placement exists to prevent — a list there would not be
covered by §10 stage 1's matrix test, so the enum and the panel could disagree
with nothing failing. So the Resource reads the enum:

```php
->visible(fn (OrderAdminOrderView $view): bool => $view->status->canTransitionTo(OrderStatus::SHIPPED)
    && OrderResource::staffHasPermission(Permission::ORDER_MANAGE))
```

**`deliver`'s visibility is the matrix and nothing else.** No condition on
`deliveryType` appears beside it: every order that reaches `delivered` was shipped
(§2.1), pickup-point orders included, so the panel offers exactly what the domain
allows and the two cannot disagree.

**Two things are deliberately still offered when they might refuse:** a return on an
order whose lines are already partly back, and a cancellation from `shipped` whose
shares might exceed what the payment can still refund. Their guards need reads the
page would have to duplicate (R7's per-line sums, R8's `sumCompletedForPayment()`),
and any such read is stale the moment the button is clicked. The panel offers, the
service refuses truthfully, §8.3 renders the refusal. Visibility is about *what the
domain forbids by definition*; guards are about *facts that can change between
render and click*.

**And the money step adds a third question, answered per order: the permission.** A
`cancel` from `shipped`, or a `recordReturn` on an order with a settled payment,
*will* write a `PaymentRefund`, so it needs R5's derived permission —
`REFUND_CASH` for a `cash_on_delivery` payment, `REFUND_BANK` otherwise — on top of
`ORDER_MANAGE`. The payment section has already read both facts it depends on
(`isSettled()` and the payment's `method`, §4.1), so no new read is needed. An order
with nothing settled needs only `ORDER_MANAGE`: there is no money to hand back, so
the operation is the goods half alone (§2.2 R8(c), §14 Q3).

**A stale page is expected and is not a design failure.** Two operators on the
same order, one clicks `ship` while the other's tab still shows `confirm`: the
second click reaches a service whose status guard and row lock (§5.2 steps 2–4)
refuse it. §5.2's deliberately-not-adopted `$expectedStatus` parameter has its
reason recorded there; §8.3 is the layer that turns the refusal into a sentence
the operator can act on.

### 8.3 The shape of every action — five things, no exceptions

**1. Permission, through the trait's own gate.** Each action is gated by
`OrderResource::staffHasPermission(Permission::ORDER_MANAGE)`, and an action that
*will* refund money needs R5's derived permission **in addition** (`REFUND_CASH`
for a `cash_on_delivery` payment, `REFUND_BANK` otherwise) — a conjunction, not a
replacement: the operator has to be able to manage the order *and* to hand money
back, which is exactly why the pair exists in `RoleResource.php:255`.
`staffHasPermission()` is a **new public static wrapper** on
`OrderResource`, the same deliberate escape hatch `ProductResource` already
carries (`ProductResource.php:186-189`, added there for its writer pages): the
trait's `staffCanForAction()` is `private static`, so a method on the Resource
itself can call it, but a page or any other class cannot. **No new permission is
added**: `RoleResource.php:255` already groups `ORDER_VIEW`, `ORDER_MANAGE`,
`REFUND_CASH` and `REFUND_BANK` under 'Orders', so the Role editor needs no
change.

**2. Confirmation, the reason typed into it, and the map.** Every action carries
`->requiresConfirmation()` — the panel's convention for a status write
(`ProductResource.php:1826`, `:1856`, `:1998`, `:2484`, `:2519`). Both writing
operations collect the facts the service needs in that confirmation form: `cancel`
collects its optional reason and, from `shipped`, the per-line restock flags;
`recordReturn` collects the per-line quantities with their restock flags (§8.4) and
its optional reason. **A reason is never `->required()`** (§5.2: free text,
optional, on both), and `->required()`/`->disabled()` on any field here is UX, not
enforcement — the service validates what it needs, the same posture
`ProductResource`'s price fields state (`:175-184`). The action passes the
operator's words through unchanged; it never composes a reason on their behalf.

**3. The action calls the service and opens no transaction of its own.** It
validates nothing the service validates, writes nothing itself, and builds the
explicit clock `$occurredAt` (§5.2: one clock, supplied by the caller). The unit
of work — lock, guards, status write, side effects, event (§5.2 steps 2–6) —
belongs to `OrderStatusChanger`/`OrderPaymentConfirmer`, exactly as
`ProductStatusChanger::archive()` owns each product's own transaction rather than
`ProductResource` opening one (`ProductResource.php:2630-2631`). One action, one
service call, one order, one transaction.

**4. A refusal becomes the operator's message; a bug stays a bug.** Caught and
reported: `InvalidOrderTransitionException` (its from/to are values precisely so
this message can name both statuses — §5.1), each guard's own exception as it is
(`InsufficientStockException`, the `LogicException` `Payment::confirm()` throws
for a second confirmation or an unanswered attempt — §4.1's guards), and
`InvalidArgumentException` for a record that vanished between render and click.
Rendering is the panel's own convention —
`Notification::make()->title(…)->body(…)->danger()->send()`, the shape
`ProductResource.php:2127` already uses for a domain refusal — and success is a
`->success()` notification naming the status the order just reached.
**`\Throwable` is deliberately not caught**, the one place this design departs
from its bulk cousins: there, an unexpected failure on one row must not take the
other rows' writes with it, so it is reported per record
(`ProductResource.php:2663-2668`). A single-record action has no such duty, and
flattening an unexpected exception into a friendly toast is how a defect stops
being reported.

**A refusal that is a *rule* carries a value; only a bug carries a string.** R9's
refusal is the first of these and is never rendered from an exception message:
`OrderRefusalReason` — an enum, `PaymentNotSettled` / `NothingLeftToReturn` /
`RefundExceedsRemainder` to begin with — is carried by the guard's exception and
rendered by one helper into `lang/en/orders.php` and `lang/bg/orders.php` text, so
the sentence an operator reads is translatable and the rule that produced it stays
a value. The operator's own free-text reason travels the opposite way and is never
translated (§6.1): one is UI copy about a rule, the other is data about this order
(§5.2).

**5. After a success, the page is re-read rather than trusted.** This is not
fussy: `OrderAdminReader` memoizes its `OrderAdminOrderView`s per instance
(`OrderAdminReader.php:88`) and is bound `scoped()` (`AppServiceProvider.php:52`),
so the *same request* that performed the write would render the header, the
status badge and the §6.3 timeline from the pre-write read — every one of them one
step behind the click. So each action ends by redirecting to the order's own View
URL (`OrderResource::getUrl('view', ['record' => $orderId])`): a new request, a
fresh scoped reader, nothing to invalidate, and a correct timeline for free. A
public `forget(string $orderId)` on the reader was considered and not adopted —
it adds a mutator to a read-only class to avoid one navigation the operator
expects anyway after a status change.

### 8.4 Two things §8 must also build: a form, and a badge that was missing

**The cancel/return dialog is the only form worth designing, and it is one form.**
§5.2's two writing methods take the same shape, so the View page builds it once:
one integer input per `SALE` line (keyed by line id, `minValue(0)`, up to that
line's own quantity minus the units already returned against it — the R7 read,
rendered from the `REFUND` lines the DTO already carries) and one toggle per line
labelled *return to stock*, default **on** (R3). Leaving a line blank or at 0 means
"not returned", which is why the map is sparse rather than a restatement of the
order. `cancel` opens the same form with the quantities fixed at the remaining units
and only the toggles editable when the order is `shipped`; from `placed`/`confirmed`
the toggles are not offered at all, because the goods never left. The dialog's
wording follows the operation and the status — "cancel this order" against "return N
units" — because the merchant is answering two different questions that happen to
produce the same records.

- **The result is not always a status move, and the notification must not pretend
  otherwise.** A partial return writes the `REFUND` lines, the stock and the money
  and leaves the status exactly where it was (§2.3); only a return that empties the
  order reaches a terminal status. So the success notification names what was
  *asked for* ("returned 2 units on …") and the order's own badge — re-read by §8.3
  item 5's redirect — states what happened to the status. Deliberately **not** solved
  by making `recordReturn()` return a completeness flag: the status it may or may not
  have moved already says it, and a second answer would be a second source of truth
  for a fact the return's own event and lines record anyway (§6, §7.2).
- **A legacy line is still returnable.** `OrderAdminReader` reads sale lines
  through a raw query precisely because `SaleLine`'s constructor rejects a
  pre-2026-09-17 `SALE` line with a NULL `product_name`/`sku` (that class's own
  docblock), so a line can render "—" for its name and still has an id and a
  quantity — the whole of what this form needs. It is filled from the id, never
  from the name.
- **`reason` stays optional** (§5.2) and is recorded in the event when given —
  verbatim, untranslated, exactly as typed. Whether a per-line *reason* or per-line
  *condition* is wanted — the fields a real return desk keeps — is **Q6** (§14), and
  whether a return should be allowed to ship a Product back to publication is
  **Q7**: this document says no (§7.2), and Q7 asks the owner to confirm overruling
  the *online* half of §3.13 Q4(c) — which also covers that bullet's "don't restock,
  it's defective" checkbox, deliberately handled by R3's toggle today and by nothing
  cleverer.

**`confirmed_at`, the fact §4 writes, currently has nowhere to be seen — a real hole
this section closes, and the one §7.3's `voided_at` arrives with.** The payment
section renders method, `payment_status`, provider reference, failure reason,
`attempted_at` and the attempt count (`OrderResource.php:365-406`); **not one of
those changes when §4.1's `confirm()` runs**, nor when §7.3 voids a row and appends
its successor, so an operator who records an arrived bank transfer watches the badge
stay on "Pending" and nothing move at all — which reads like a failed click. The fix
needs no new write and no schema change, and it does not weaken §4.2:

- **`OrderAdminOrderView`'s latest-payment part gains `confirmedAt` and `voidedAt`**
  (read from the columns §4 and §7.3 add, through the same payment read `forOrder()`
  already performs — no extra query), and that read is §7.3's *current* payment: the
  newest row whose `voided_at` is NULL, so a voided row is never shown as "the"
  payment and a reissued one is. `payment-domain-design.md`'s D6 stays what it was —
  "the most recent payment row (by `attempted_at`, then id)" — with one clause.
- **The section gains one entry**: the instant the money was recorded, plus whether
  the payment is *settled* — `isSettled()` (§4.1). Two labels, one row:
  `payment_status` keeps saying exactly what the adapter said (§4.2's whole
  argument), and "settled" says whether money is held. Because it is literally the
  predicate R8, R9 and §4.1 call, the badge cannot disagree with the guard that
  decides whether the order may be shipped or how much may be refunded.
- **The confirmation action is replaced by that statement once set** — §4.1's
  one-time rule means the button's absence is the correct state, not a missing
  feature. The same statement is what an operator sees after R10's delivery
  confirmed a cash-on-delivery payment: no second call to action, because the fact
  is already recorded.
- **§4.5's exception has a face here.** When a cash-on-delivery delivery could not
  confirm anything (no row, a `FAILED` attempt, an unanswered one), the payment row
  goes on saying exactly what the adapter said and the section says the money is not
  recorded — no computed "unpaid" badge (§3 item 3), and no silence either. That is
  the panel half of §14 Q1.

**The timeline (§6.3) renders as one more Section at the end of the View page** —
newest first, since a merchant opening an order wants the last thing that happened,
and each row is read-only: instant, type, from → to where both are set, the
operator's own reason, a link to the return's own lines where §6.1's `transaction_id`
is set, and the actor. It derives no status and no amount from anything (§6.3's rule
restated where it would be tempting), so it can only ever be longer than the status,
never wrong about it. New translation keys ship with the enum change:
`orders.status_options.*` for §1's four new statuses in `lang/en/orders.php:76-79`
**and** `lang/bg/orders.php`, with `fulfilled` becoming a dead key, plus one new
group for the event type labels — `optionLabel('status', …)`
(`OrderResource.php:184`) would otherwise print a raw `snake_case` value rather than
fail, which is exactly the kind of wrong that survives review (§10 stage 2).

**And the badge itself: six values, and the two terminal ones must not look alike.**
`cancelled` and `refunded` are the two words for "it ended", and §1 splits them by
*when* it ended. A badge that renders them the same way throws that split away at the
only place a merchant reads it in a glance — a list row, a filter chip, a View
header. So the two are deliberately distinct from each other **and** distinct from
`delivered`: `refunded` is the one value that means *the customer had the goods and
they came back*, and an operator scanning for "what came back, and what do we still
owe" has to see it without opening the order. `cancelled` is not a warning colour (a
refusal is routine), `refunded` is not a success colour (money left), and neither
borrows `delivered`'s. Which of the six the list's status *filter* offers is §14 Q2,
where the default is all six.

---

## 9. Scope: what this design covers, and what it deliberately leaves alone

**Covered, in one list** — because "designed" and "built later" must not blur: the
six statuses (§1) and the seven transitions that govern them (§2, §2.3), the rules
R1–R11, payment confirmation for the two offline methods and its one caller at
delivery (§4), the single unit of work that performs a transition and the return
that shares it (§5), the order's own always-on history (§6), the side effects and the
two reads outside the order that a cancellation moves (§7), the admin surface that
exposes them (§8), and — §10 — the order the work ships in.

**Not covered, each named here rather than left implied:**

- **A Shipping domain.** `shipped` and `delivered` are facts *the merchant
  records*: no carrier rates, no labels, no tracking numbers, no provider
  callbacks, no delivery windows. `product-shipping-fields-note.md` and
  `promotions-domain-design.md` §6 already name that future domain; this document
  is careful to leave it a future.
- **Any customer-facing surface.** No storefront order-status view, no guest
  lookup by order number (`checkout-domain-design.md` §10 still owns that gap), no
  email or SMS at any transition. §12's hooks are the extension point a
  notification attaches to later — this document ships the extension point and no
  listener, the posture `account.registered` and `catalog.variation.barcode`
  already take.
- **The POS return screen** (`operational-sales-domain-design.md` §3.13 Q4 is
  still deferred there). §7.2 designs the return a merchant records from an
  order's own page; it reuses Q4(a)/(b)'s decided *values* (actor from the
  authenticated user, optional free-text reason) without claiming to have built
  the POS flow. Both paths write the same `REFUND` `SaleLine`; what differs is the
  screen and the channel, not the record.
- **A partial-payment or deposit mechanism** (§4.5): `Payment.amount_minor` is the
  whole amount, and nothing here splits it.
- **An un-refund.** `PaymentRefund` is append-only, so money given back to the
  merchant needs a new, separate money fact that no table owns — and nothing here
  creates one: there is no reversal of a return (§2.3), no operator override of a
  refund's amount (§5.2, §7.2), and a hand-back that has to be *undone* is a
  payment-domain decision (§14 Q3).
- **A standalone goodwill refund, without a cancellation or a return.** Money leaves
  only as R8's money step, inside the operation that justifies it (R4): there is no
  "refund" button and no amount field anywhere (§5.2). A merchant who wants to give
  money back without goods coming back has to do it as a cancellation with the
  restock flag off, which is exactly the case that record is meant to describe.
- **A partial cancellation of an unshipped order.** Before `shipped` a cancellation
  returns every remaining unit (R1) — there is nothing partial about it, because the
  goods never left and the merchant's reason for stopping is the same for all of
  them. Partiality is a `shipped`/`delivered` idea, i.e. a return.
- **Any online payment adapter, and any capture after checkout** (§4.2): the two
  offline methods are the whole refund surface this design touches, and
  `Payment.status` never moves as a result of anything here.
- **Order editing.** No lines, quantities, prices or addresses are edited after
  placement. `OPERATIONAL` corrections are *recorded* — a return, a cancellation, a
  hand-back — never made by rewriting a `SALE` line
  (`operational-sales-domain-design.md` §3.2's append-only rule).
  `ORDER_MANAGE`'s own description in `staff-access-domain-design.md` §3 mentions
  "editing order information"; that remains unbuilt and unclaimed here.
- **Automatic or scheduled transitions.** Nothing auto-cancels a stale order,
  auto-confirms one, or closes a delivered order after N days. §5.2's explicit
  `$occurredAt` exists partly so that any such rule stays a decision of whatever
  calls the service, never a hidden `now()` inside it.
- **Accounting, invoices, tax documents, exports and reporting** (§3 item 7):
  consumers of these facts, not part of them.
- **Bulk and list-page actions** (§8.1): one order at a time, from its own View
  page, and the list page stays a read.
- **Returns policy enforcement** — no return window, no restocking fee, no RMA
  number, no per-line condition grade, no per-line reason (§8.4; Q6 records whether
  the last two are wanted).
- **Exchanges.** An exchange is a return plus a new order; nothing here couples
  the two, and no "replacement order" link is introduced.
- **An optimistic-concurrency token on the order** — §5.2 records why
  `$expectedStatus` was considered and not adopted; the row lock is the mechanism
  instead, and §8.3's actions are the stale-page layer.
- **Any new permission or role** (§8.3): the four existing ones are used as-is,
  and `RoleResource`'s grouping already fits.
- **Pruning or retention for `order_events`** (§6.2): the table has no deletion
  path and §11 records that as permanent.
- **An actor on a payment.** There is no `confirmed_by` column (§4.2); the actor
  lives in the order's history, where the merchant reads it.
- **A storefront view of an account's own orders.** §7.4 narrows what
  `hasAnyForAccount()` counts, but this design adds no surface that *shows* an
  account its history: a "my orders" page with statuses on it is `checkout-domain-design.md`
  §10's gap, and it is the consumer that would make the narrowed answer visible.

---

## 10. Staged build order, and the review gate

**Every stage is one commit, reviewed on its own.** Nothing here is a long-running
branch: each stage leaves the project green by itself, and the next one starts
only after the previous diff has been read. That matters more than usual here,
because several stages are *retroactive* — they make a claim that already exists in
the codebase false (§0 item 1's enum docblock, §0 item 2's `FULFILLED` references,
§0 item 4's notes and `hasAnyForAccount()`'s own docblock, §0 item 7's `attempted_at`
rule, §0 item 10's two count queries) — and a retroactive edit is exactly the kind
that must be reviewed next to the thing that forced it.

**Stage 1 — `OrderStatus` gains four values, loses one, and gains the matrix.**
The enum edit itself: `CONFIRMED`, `SHIPPED`, `DELIVERED`, `REFUNDED` added (no
`RETURNED`, §1), `FULFILLED` removed, `canTransitionTo()`/`isTerminal()` added
(§5.1), the class docblock's "No transition methods exist anywhere in this package:
nothing in this pass changes an Order's status once placed" paragraph rewritten (it
is about to become false), and the two fixtures that construct `FULFILLED`
(`packages/EasyCo/Order/tests/OrderTest.php:376`, `:402`) moved to a case that
still exists. Tests: the matrix walked against §2.1 in **both** directions — every
listed pair `true`, every other pair of the 6×6 `false`, which is what pins "no
skipping, no backwards move" — plus the terminal pair, plus the value list itself.

**The removal ships with a migration, and the migration is a gate.** §0 item 2 is a
reference list, not a guarantee that no other installation holds the value, so the
enum edit is paired with a migration whose whole job is to prove that no row is lost.
**Owner decision D1: it maps nothing.** If any row still holds `fulfilled`, the
migration fails loudly — the exception names the count and the `orders.id` values it
found — and every row keeps the value it has; a deployment that holds them resolves
them by hand and re-runs it. A `fulfilled` → `shipped` rewrite would be the one
irreversible statement in the whole build order, and it would be a guess: `fulfilled`
meant "prepared/handed over", and no column in the row says whether the parcel ever
left the merchant's hands. Those are real orders and a decision about each of them,
not a mapping. In the database this design was checked against, the gate passes: all
six `orders` rows are `placed` (§0 item 1), so the count the exception would name is
zero and the migration is a no-op with a proof attached. The creating migration's own
comment (`2026_09_06_000001_create_orders_table.php:78`) is **not** edited: it is
history, the convention §4.4's own migration states in its docblock.

**Stage 2 — labels.** `lang/en/orders.php` and `lang/bg/orders.php` get four new
`status_options.*` keys and lose `fulfilled`, plus one new group for §6.1's event
types — without it `OrderResource::optionLabel('status', …)`
(`OrderResource.php:184`) prints a raw `snake_case` value instead of failing,
which is the kind of wrong that survives review (§8.4). No query changes
anywhere: the list's filter and the badges read the enum. Which of the six
values the *filter* offers is Q2 (§14); the default is all six.

**Stage 3 — `order_events`.** §6.1's migration (both FKs, `order_id` and the
nullable `transaction_id` each carrying `restrictOnDelete()`),
`App\Models\OrderEventModel`, `App\Services\OrderEventRecorder`, and the `events`
read §6.3 adds to `OrderAdminReader` — inert until stage 7 shows it. Tests:
insert-only (the class issues no other statement), each FK's restrict behaviour
proven by a real delete attempt against the live engine, actor resolution from the
panel guard, and `null` for a console caller.

Stages 2 and 3 shipped as ONE piece of work (owner decision): the status labels are
meaningless without the enum that produces them and the event-type labels are
meaningless without `OrderEventType`, so the two stages were built and reviewed
together rather than in two passes over the same files.

**Stage 4 — `Order`'s transitions, and the locked read.** §5.1's five mutators on
the aggregate, and `OrderRepository::findByIdForUpdate()` (§5.2 step 2). No other
repository change, no service yet. Tests: each method's own guard (including
`refund()` being unreachable from anything but `delivered`); the same-status
refusal; the matrix re-checked *through* the mutators, because the stage 1 test
alone would still pass if a mutator bypassed the enum; and the lock's statement
shape.

**Stage 5 — payment confirmation, and the void.** `confirmed_at` plus the
`settled_order_id` stored generated column and its unique index (§4.4), the
`PaymentModel`/`EloquentPaymentRepository`/`Payment` plumbing, `Payment::confirm()`
with `isSettled()` (§4.1) and `Payment::void()` with `isVoided()`/`voidedAt()` plus
the `voided_at` column (§7.3), `findSettledForOrder()` with
`sumCompletedForPayment()` (§7.3), `OrderPaymentConfirmer` (§4.3), and the
`voided_at` condition on `OrderAdminReader`'s two payment reads (§7.3). Tests: the
three confirmation guards and the void's own; a second confirmation refused;
`isSettled()`'s truth table over `status × confirmed_at`; a voided row never being
the "current" payment, including the case where every row is voided; and the
constraint proven for real — two settled rows for one order must fail at the engine,
the same direct `SHOW CREATE TABLE`-plus-insert standard
`payment-domain-design.md` §8 already applies to `captured_order_id`.

**Stage 6 — the services and their side effects.** `OrderStatusChanger` (§5.2),
`OrderRefunder` (§7.3) and §7's writes: `StockLevelRepository::increase()` for every
line whose restock flag is on (§7.1, §7.2), the return's own `Transaction` and its
`REFUND` `SaleLine`s (§7.2), the `PaymentRefund` or the void-plus-reissue (§7.3),
the `promotion_redemptions.released_at` release (§7.4), and
`EloquentOrderRepository::hasAnyForAccount()`'s status condition (§7.4).
**Three comment blocks are corrected in this stage and no other**: the two
`StockLevelRepository` docblocks (§7.1 — this is the stage that makes `increase()`'s
"No caller exists yet" false) and `hasAnyForAccount()`'s own docblock (§7.4 — this
is the stage that makes "An order in any status counts, including CANCELLED" false).
Each corrected text names its real caller or condition rather than a plan stage, so
it cannot drift again. Tests: one per rule R1–R11; the rollback proof (a refused
guard leaves no status change, no stock movement, no `REFUND` line, no money row and
no event); "cancel twice restocks once" (§5.2 step 3); the promotion release
happening on a cancellation and **not** on a return; `hasAnyForAccount()`'s truth
table over the six statuses for an account with one order; and a listener that throws
**after** the commit, proving the recorded fact survives an extension's failure while
the operator still sees the error (§8.3 item 4).

**Stage 7 — the admin surface.** §8 in full: `OrderResource::orderActions()`,
`staffHasPermission()`, `ViewOrder::getHeaderActions()`, the cancel/return dialog
(§8.4), the settled/confirmed/voided payment row with §7.3's *current* payment read,
the timeline section, the six badges (§8.4), and redirect-on-success. Tests: which
actions render at which status — asserting the *derivation* from the matrix, never a
hand-written list; the permission gate per action, including R5's derived refund
permission as a conjunction with `ORDER_MANAGE` rather than a replacement; one refusal
rendered as a translatable sentence (§8.3 item 4); and the fresh-read redirect.

**Stage 8 — the cross-reference edits §13 schedules here, and the Hook Reference
rows** (§13). Docs-only, one commit, deliberately last, because every sentence it
adds is a claim about what exists by then: the five new hook rows and the missing
`order.placed` row (§12), `staff-access-domain-design.md` §3's one sentence, and the
pre-existing staleness in `promotions-domain-design.md`. The edits §13 assigns to
stages 1, 5, 6 and 7 do not wait for this one — they ship with the code that makes
each sentence true, per §13's landing rule — and §13 also records the two edits this
*design* pass has already applied, which needed no code behind them.

**The review gate, stated once.** A stage is done when its own tests pass *and*
its diff has been read by someone other than its author — on the understanding
that an implementation which contradicts this document is reported and argued,
never quietly absorbed by editing the document afterwards to match. Anything the
implementation genuinely forces a decision on belongs in §14, with the reason.

## 11. Permanent commitments and standing constraints

Not every decision here is a preference a later pass may tune. The items below are
the shape of the thing: each states what it is and what breaks if it is quietly
changed. None of them is a convention — each follows from a fact stated elsewhere
in this document or in the documents it builds on, which is why changing one is a
reopening of that argument rather than an edit.

1. **`order_events` has no deletion path.** No prune command, no retention
   setting, no soft delete (§6.1, §6.2) — the same posture
   `operational-sales-domain-design.md` §3.2 takes toward sale lines. Its lifetime
   is the order's, because it is the only *always-on* record: `ActivityLogger` is
   gated by a Site Setting and pruned after `admin.activity_log_retention_months`
   (§0 item 8), and "did this ever ship, and did we give the money back?" is asked
   a year later. The same figure is what makes the `restrictOnDelete()` FK *prove*
   that no order-deletion path exists rather than assuming it: an order with any
   history cannot be deleted by any future code that tries.
2. **`orders.status` is mutated only through §5.1's five mutators.** It is the one
   mutable part of the order (§3 item 6), and the mutators are where the matrix is
   enforced. `EloquentOrderRepository::save()` rewrites all 23 order columns
   unconditionally (§0 item 4): safe under §5.2's row lock, and unsafe for any
   future writer that decides *whether* to write without taking that lock. The lock
   is not optional for a read-then-write path — that is the standing risk recorded
   here, not a hypothetical.
3. **The three-statuses rule stands** (§1; `checkout-domain-design.md` §238). No
   "paid", "partially paid" or "payment pending" value is ever added to
   `OrderStatus`; money facts live in `payments`/`payment_refunds`, and §4's
   `confirmed_at` (with §7.3's `voided_at` beside it) is precisely how the offline
   case was closed without inventing another answer.
4. **`settled_order_id` is a stored generated column and stays one.** It is never
   `->change()`d and never dropped and recreated "to tidy it up", and
   `captured_order_id`'s own expression keeps the meaning `payment-domain-design.md`
   §5.1 documents. Two narrow, separately testable constraints are the point of
   §4.4; one widened one is the alternative that was rejected.
5. **Every future writer of a `payments` row must set `attempted_at`** (§0 item 7).
   The panel's latest-payment rule sorts by `attempted_at DESC, id DESC` in two
   places, so a newer row with a NULL attempt sorts *below* an older answered one —
   and the admin panel goes on showing the previous payment as "the" payment.
   §4.2's confirmation deliberately touches nothing but `confirmed_at` on the row
   the adapter already answered, and §7.3's reissue deliberately *does* set
   `attempted_at`, precisely because it is a new row: this rule is what keeps the
   void-and-reissue pair from hiding the row that is now current.
6. **`confirmed_at` is write-once, and there is no un-confirm** (§4.5). A
   confirmation recorded against the wrong order is corrected through the money
   trail, not by clearing the column; §14 Q5 asks the owner whether a recorded
   reversal fact is wanted instead, because that would be a new decision rather than
   a relaxation of this one.
7. **No reason is ever required — on any operation** (§5.2). A cancellation's and a
   return's reason are both optional free text, stored verbatim and untranslated in
   `order_events.reason` (§6.1); what the panel translates is the machine's own
   refusal reason (§8.3 item 4). Requiring prose on either operation would be a
   different decision than this one, and §14 Q4 is where the owner would take it.
8. **`order_events.type`'s five values and the status strings are permanent names.**
   A new type may be added; an existing one may not be renamed or reused, because
   every row already written carries it (§6.1's plain string column, the same as
   every other enum column in this project). The same applies to the values in
   `orders.status`: they are what `OrderStatus` reads back.
9. **The event is written inside the transaction; the hooks fire after it**
   (§5.2, §6.2, §8.3 item 4). Moving a hook inside would let a listener roll back a
   fact the merchant has already been told about; moving the event outside would let
   a rolled-back transition leave a history entry behind. Neither direction is a
   style question.
10. **A return writes `REFUND` lines on its own `Transaction`, and a `SALE` line is
   never annotated with its own return** (`operational-sales-domain-design.md` §3.2,
   §3.13 Q4(a); §7.2 here). "Already returned" is a query over refund lines pointing
   at the sale line, which is exactly what makes R7 statable without a counter on the
   sale line — and the placement transaction is never rewritten, only read.
11. **No derived column, anywhere.** No `was_restocked`, `remaining_amount`,
   `refundable`, `returned_count` or `units_returned` on an order or a payment
   (§7.1–§7.3), and no `returned_lines` JSON on `order_events` (§6.1: the event
   points at the transaction that holds the lines). Each was considered and each
   would be a second source of truth for something a read already answers correctly.
12. **Stock leaves the shelf at placement and nowhere else** (§7.1). `confirm`,
   `ship`, `deliver` and §7.3's money step move no quantity; only a cancellation's or
   a return's restock flag puts units back, and changing where stock leaves is a
   change to checkout, not to this lifecycle.
13. **One lock order: the order row first, then its payments** (§4.3, §5.2 step 2).
    Two services that lock the same rows in opposite orders can deadlock, and the
    order-first rule is the whole reason the pair is safe under concurrent panel
    actions.
14. **When a real online refund adapter exists, its gateway call must move outside
   the transaction** (§7.3) — record intent, call, record the result. V1's two
   offline adapters answer synchronously and are safe inside it; the first adapter
   that talks to a network does not inherit that safety, and the multi-attempt shape
   `PaymentStatus` already anticipates is where it belongs.
15. **The Orders list stays a read** (§6.3, §8.1): no row actions, no per-row events
   query, no "latest event" correlated subquery. D5's single-reader rule exists so
   that page's cost stays knowable as the order tables grow.
16. **No new permission, and no permission check inside a service**
   (§4.3, §8.3). The Filament action is what is authorized; the services stay
   callable from a console or a job, recording a `null` actor rather than throwing.
   A future requirement for a service-level check is a new decision, not a fix.
17. **`isSettled()` is the only place "money is held" is decided** (§4.1). R5, R8, R9,
   §7.3 and §8.4 all call it; re-deriving `status === CAPTURED || confirmedAt !== null`
   anywhere else is how the badge and the guard that refuses a shipment begin to
   disagree with each other.
18. **`OrderStatus::canTransitionTo()` is the only transition matrix** (§5.1). The
   panel reads it for visibility and never re-lists the buttons (§8.2), and §10
   stage 1's test walks it against §2.1's table so the prose and the code cannot
   drift apart.
19. **"Current payment" is one rule, and `voided_at` is the only way a row leaves
   it** (§7.3): the newest `payments` row for the order by `attempted_at DESC, id
   DESC` whose `voided_at` is NULL, read in both of `OrderAdminReader`'s payment
   queries. A fourth `PaymentStatus` was considered and refused on the argument
   `PaymentStatus`'s own docblock already makes; a voided row stays visible in the
   payment trail, and a reissued row always sets `attempted_at` (item 5).
20. **A promotion redemption is released only by a cancellation, and both redemption
   counts exclude released rows** (§7.4, R11) — never by a return, never by a refund.
   `hasAnyForAccount()` counts every status except `cancelled`: a cancelled order was
   never a purchase, a refunded one was. Both directions are argued in §7.4 and
   neither is a preference to be tuned later: together they decide which orders a
   first-purchase discount and a usage limit are computed over.

---

## 12. Hooks: five extension points, and the row that was missing

§5.2's closing paragraph already states *when* these fire (after the transaction
commits, never inside it). This section is the complete list: the names, the exact
signatures, and the one gap this pass found in
`extensibility-design-and-hooks.md` §3.

| Hook | Type | Fired from | Signature | Fires when |
|---|---|---|---|---|
| `order.status_changed` | Action | `App\Services\OrderStatusChanger` — every public transition method | `(Order $order, OrderStatus $from, OrderStatus $to): void` | After commit, once per transition |
| `order.cancelled` | Action | `OrderStatusChanger::cancel()` | `(Order $order, ?string $reason): void` | With any of the three cancellations |
| `order.returned` | Action | `OrderStatusChanger::cancel()` (from `shipped`) and `OrderStatusChanger::recordReturn()` | `(Order $order, array $returnedLines): void` | On **every** return, partial included — and on a cancellation of a shipped order, because that is a return of everything (§2.3) |
| `order.refunded` | Action | `App\Services\OrderRefunder`, called by §5.2's cancel/return | `(Order $order, PaymentRefund $refund): void` | When a completed refund is written; a void fires nothing |
| `order.payment_confirmed` | Action | `App\Services\OrderPaymentConfirmer::confirm()` | `(Payment $payment): void` | With §4.3's `payment_confirmed` event (no transition) |

**The five names follow the one `order.*` hook that already exists.** §3's naming
convention is `{domain}.{entity}.{event}`, but the live precedent is
`CheckoutOrchestrator.php:197`'s `Hook::fire('order.placed', $order)` — two
segments, because here the domain *is* the entity and a middle segment would read
as noise. Consistency with the shipped name beats consistency with the diagram.

**The specific hook fires with the *event*, not with the status move.** This is the
rule to read carefully, because three of these fire where no status changes, and one
fires twice for a single operation:

- `order.cancelled` only ever accompanies a transition — a cancellation is nothing
  else (§2.1). Cancelling a `placed`/`confirmed` order fires it **once**, with no
  `order.returned`, because nothing had left the shop (R3).
- `order.returned` fires for **every** return: a partial one that moves no status, a
  full one that ends the order, and the return that *is* a cancellation from
  `shipped` — in that last case both this hook and `order.cancelled` fire, because
  both facts are true (the order was called off, and the goods came back). A
  listener that only wants "goods came back" registers this hook and needs no
  knowledge of the status; one that wants "the order ended" registers
  `order.status_changed` and reads the target.
- `order.refunded` fires when a **completed** refund is written, partial included
  (R8), and it is fired by the money step rather than by a transition: a
  cancellation that hands money back fires it, and so does a return, while §7.3's
  *void* fires **nothing** — no money moved, so no money hook, and the
  `payment_voided` event is where that fact is read (§6). A refund the adapter
  refuses fires nothing either: the row a failure writes is the attempt's own
  record, and `payment_refunds.failure_reason` is where it is read (§7.3). A
  listener therefore cannot mistake a rejected request, or a cancelled obligation,
  for money that moved.
- `order.payment_confirmed` accompanies §4.3's `payment_confirmed` event and never a
  status change (§3 item 1) — money arriving is precisely the fact that needs a hook
  *because* it moves no status. When R10's delivery confirms it, the same hook fires
  and `order.status_changed` fires alongside it, each carrying its own fact.

**Payloads are the aggregate plus scalars — never a DTO, never a model.** `$order`
is the real `EasyCo\Order\Order`, the same thing `order.placed` already passes, so
a listener reads the same API the domain does. `OrderAdminOrderView` (the panel's
DTO) and `OrderModel` are never passed: either would tie an extension to the admin
surface's shape, which is the coupling §2 of the extensibility document exists to
prevent. `$returnedLines` is the same map the caller handed §5.2
(`["<saleLineId>" => ['quantity' => 2, 'restock' => true]]`), passed straight
through, so a listener needs no ledger query and the hook cannot disagree with what
was written; the history answers the same question by pointing at the return's own
`Transaction` (§6.1's `transaction_id`) instead of copying the map.

**No filter hooks in this design, deliberately.** Nothing here needs to *transform*
a value: a status, an amount and a reason are facts, not candidates. A filter over
the target status would make §2.1's matrix decorative — the whole argument of §5.1
is that the refusal is the domain's, not a listener's. If a merchant-specific need
to influence one of these appears, it is a new hook and a new decision, taken
openly.

**Zero listeners ship with this change.** All five are extension points only, the
same posture `account.registered` and `catalog.variation.barcode` already take —
registered, documented, and quiet until someone attaches something. A
confirmation email, a CRM sync or an accounting export is a later listener on
these hooks, not part of this design (§9).

**The gap this pass found, stated plainly.** `order.placed` has been fired since
checkout was built, and §3's Hook Reference — the table whose own rule is that a
new `Hook::action()`/`Hook::filter()` call site or listener added anywhere in the
project gets "a row here in the same commit" — has no row for it. Nothing is
broken: the hook works. What is broken is the table's claim to be the single
source of truth for "what can I hook into".
Its row is added together with the five above in §10 stage 8, so the table is
complete rather than merely current (§13).

**And nothing fires from inside a domain package.** `Order`'s mutators,
`Payment::confirm()` and `Payment::void()` themselves dispatch nothing: only
`app/Services/*` calls
`Hook::fire()`, exactly as extensibility §2 requires, since `Hook` resolves its
registry through the container. A hook fired from inside `Order` would drag
Laravel into a package that is deliberately framework-agnostic.

---

## 13. The documents this changes, and when each edit lands

This pass is design-only: none of §0–§12 is built. What follows is the complete
blast radius, stated so the owner approves the plan *and* the edits at once rather
than discovering a changed sentence later. Every edit is a sentence, a bullet or a
table row; no section is rewritten, and none of them touches a decision those
documents already made with the owner.

**This pass's diff is one added file, plus two purely documentary edits already
applied.** `checkout-domain-design.md` §8.5 and §10 gain the two sentences written
out below, because neither claims anything about code that does not exist yet: one
names the document that answers the deferral, the other records that answer on the
deferral entry itself. Both
were true the moment this document existed, so they ship with it rather than waiting
for a stage. **Every other document listed below is untouched in this pass** and keeps
reading exactly as it reads today until the stage that makes each sentence true —
which is what the table records. Each planned edit is written out in full so the owner
is approving the actual sentence rather than a description of it.

**The landing rule: an edit ships in the stage that makes it true, and nothing waits
for stage 8 that could be true earlier.** A document that describes a three-value
status list while the enum has six — or that promises a redemption is never released
after the code releases it — is worse than either state, so:

| Stage | Cross-reference edits that land there |
|---|---|
| 1 | `checkout-domain-design.md` §3 — the status list and the `FULFILLED` bullet |
| 5 | `payment-domain-design.md` — the confirmation bullet, the `confirmed_at`/`voided_at` fields, §5.1's constraint note, §8's test plan |
| 6 | `inventory-domain-design.md` §11's `increase()`/`decrease()` bullet, `operational-sales-domain-design.md` §5's REFUND bullet and its §3.13 Q4 notes, `promotions-domain-design.md` §5/§6's release sentence, plus three comment blocks in code: the two `StockLevelRepository` docblocks and `OrderRepository::hasAnyForAccount()`'s |
| 7 | `admin-panel-design.md` §14's D1 and its "Deferred, explicitly" list, §11's creation/editing bullet, and `OrderResource.php`'s own class docblock |
| 8 | `extensibility-design-and-hooks.md` §3's five new rows and the missing `order.placed` row, `staff-access-domain-design.md` §3's one sentence, and the pre-existing staleness in `promotions-domain-design.md` |
| **now** | `checkout-domain-design.md` §8.5's pointer and §10's "answered" note — **already applied in this pass**, because both sentences are true today |

### `checkout-domain-design.md`

- **§3 (lines 65 and 95)** — `status OrderStatus: PLACED | FULFILLED | CANCELLED`
  becomes the six values of §1, and the `FULFILLED` bullet ("the merchant has
  prepared/handed over the order. No trigger exists yet …") is replaced by a
  pointer to this document. Nothing else in the `orders` schema block changes: no
  column is added, removed or retyped. This is the stage 1 edit, because the enum
  changes there.
- **§8.5 (line 238) — applied in this pass.** Kept verbatim, because it is still
  exactly right (§11 item 3 keeps it that way), plus one sentence: the offline
  "the money has arrived" case is answered by `order-lifecycle-design.md` §4 — by
  `payments.confirmed_at`, *without* a fourth status. Nothing in that paragraph is
  relaxed; it now names the document that kept its promise.
- **§10's deferred bullet (line 302) — applied in this pass.** "Order status
  transitions and their side effects … Domain-owner instruction: build this together
  with the future admin UI, as one piece, not guessed at in isolation now" — marked
  **answered by this document**. Recorded as followed rather than relaxed: §5's
  transitions and §8's actions were designed in one pass, and
  `staff-access-domain-design.md` §10 (line 289) had already recorded the same
  pairing from the other side.
- **§12.2 (lines 369-371)** — unchanged. It is the only place `order.placed` is
  written down today, and §12 here designs the four rows that sit beside it.

### `payment-domain-design.md`

- **§7's third bullet (line 173)** — "A confirmation mechanism/endpoint for moving
  a `PENDING` payment (bank transfer received, cash collected on delivery) to
  `CAPTURED` — the domain-layer status transition is designed for, its HTTP
  exposure is not built here" is replaced by what was actually decided: the
  confirmation exists, as §4.3's admin action, and it deliberately does **not** move
  the status — `confirmed_at` says "the money is in the till" without re-opening
  `captured`'s double-capture invariant (§4.2). This is the most consequential edit
  in this list: a reader who takes line 173 literally would expect exactly the
  transition this design refused.
- **§2's `Payment` field list (the diagram block, lines 33-71)** — `confirmedAt`
  and `voidedAt` added after `attemptedAt`, described as §4.1 and §7.3 define them,
  with the note that `status`, `attemptedAt`, `confirmedAt` and `voidedAt` are four
  different facts and not four ways of saying one — and that neither of the two new
  ones is a `PaymentStatus`, on `PaymentStatus`'s own argument (§7.3).
  `PaymentRefund`'s own field list (the block ending at line 124) gains nothing:
  §7.3 here uses that contract exactly as it stands.
- **§5.1 (`captured_order_id`, line 153)** — one sentence pointing at §4.4's second
  stored generated column, so the two constraints are read together instead of one
  being mistaken for a looser duplicate of the other — plus one sentence recording
  that a voided row keeps contributing NULL to both, so `voided_at` cannot interact
  with either unique index (§7.3).
- **§8's test plan (lines 182-184)** — the `settled_order_id` pair added beside the
  `captured_order_id` pair, for the same stated reason: prove the *engine* refuses a
  second settled row, not that an application check happens to — plus the void's own
  pair, since `void()` is the only other writer in this design that touches a payment
  fact.
- **§3's `reason` field (line 98) and §7's bullet on it (line 176)** — unchanged
  (V1 is free text, by that document's own decision), plus one sentence naming §7.3
  here as its first real writer. `PaymentRefund`'s own contract is likewise used
  as-is: nothing in that document is reopened, and that is the point of §7.3 here.

### `operational-sales-domain-design.md`

- **§5's REFUND/POS bullet (line 342)** — substance unchanged, two sentences added:
  the *shape* of a return is fixed elsewhere now, while the REFUND `SaleLine` stays
  this domain's own record; and a return recorded from an order's page writes its own
  `Transaction` (`channel = WEB`, §7.2 here) instead of appending lines to the
  placement transaction, so the ledger's own read of "what was sold" stays untouched.
  §7.2 supplies the values (`originatingSaleLineId`, `type = REFUND`,
  `status = COMPLETED`, `returnedBy`, §3.13 Q4(b)'s optional `returnReason`,
  `quantityReturned`, `defaultRefundAmount`/`actualRefundAmount`) and §7.3 owns the
  money (`PaymentRefund`). Nothing in §3.2's append-only rule, §3.4's provenance rule
  or §3.13's field set is reopened: "always a new line, never an edit to the old one"
  is exactly what makes R7's cumulative read — and therefore a partial return —
  expressible as a query over prior REFUND lines rather than a counter.
- **§3.13 Q4(a)/(b)'s decided values (lines 301-302)** — used as-is and quoted as
  authoritative rather than re-decided: the actor comes from the authenticated user,
  the return reason is optional free text. What this design adds is a caller with a
  UI (an order's own View page, §8) and one question for the owner about the
  *granularity* of that reason (§14 Q6), not about its existence.
- **§3.13 Q4(c) (line 303) — the one place this design overrules that document,
  and §7.2 says so out loud rather than sliding past it.** Q4(c) decided that a
  POS return restocks unconditionally while an *online* return "always increases
  stock AND makes the variation sellable", reactivating an inactive parent
  Product and restoring an archived variation. §7.2 does the stock half and none
  of the state half, so **Q7 (§14) asks the owner to confirm the overrule** —
  including the axis-drift failure mode Q4(c)'s own open points flag
  (`VariationNotRestorableException`, line 306), which this path avoids entirely
  by never attempting a restore. Two further open items in the same bullet are
  answered the same way and recorded in Q7 instead of being left implied: the
  POS-side "sellable online again" checkbox (a POS-flow control with no visible
  counterpart on a storefront order), and line 309's "don't restock, it's
  defective" checkbox, which §7.2/§8.4 **do** implement in a narrower form — R3's
  per-line restock flag, default on, whose off position answers both "the parcel
  never arrived" and "this unit is not going back on the shelf". What is still
  declined is the *record* of why: the flag says what happened to the stock, and the
  operator's free-text reason (if they give one) says why — a per-line condition
  grade is §14 Q6.

### `inventory-domain-design.md`

- **§11's "Which future task actually calls `increase()`/`decrease()`" bullet
  (line 103)** — its premise is half-stale, and the half that is stale is named in
  §7.1 here: `decrease()` already has a caller (`CheckoutOrchestrator.php:291`, at
  placement, unchanged by this design), while `increase()` gets its first one in
  this design's own cancel and return paths (§7.1, §7.2). Stage 6 lands the
  corrected bullet together with the two docblocks in
  `Contracts/StockLevelRepository.php` (`:32`, `:41`) that still read "No caller
  exists yet" — comment-only corrections in code, zero behaviour, and therefore
  outside this pass entirely: this pass adds one document and applies two
  documentary sentences in `checkout-domain-design.md`, and touches nothing else.
- **§6/§7's atomicity contract** — unchanged, and explicitly relied on rather than
  re-argued: §7.1 calls `increase($variationId, $quantity)` per line and §7.2
  restocks per returned line, never load-mutate-save, so "the read-modify-write
  round trip is the race" stays the one rule that matters. Nothing in §3's scope
  (one quantity, no reservations), §4's `restrictOnDelete()` or §5's implicit-zero
  read moves either.
- **§11's RESERVATION bullet (line 99)** — unchanged, and one sentence in it becomes
  slightly more load-bearing: a cancellation from `placed`/`confirmed` restocks every
  remaining unit unconditionally (§7.1, R3), so a cancelled order can never leave
  units held by a mechanism that does not exist yet.

### `promotions-domain-design.md`

- **§5 (line 102), and §6's two bullets (lines 111 and 113)** — pre-existing
  staleness, corrected here because leaving it costs a reader a real conclusion:
  "The `Checkout` domain doesn't exist yet", "nothing decrements them yet" and "The
  `Checkout` domain itself … not implemented" are false today. Checkout exists,
  is implemented, and writes a `PromotionRedemption` at placement
  (`CheckoutOrchestrator.php:398`, `:595-603`), while `PromotionUsageContextAssembler`
  counts prior redemptions to enforce `usage_limit_total`/`usage_limit_per_customer`
  before a code is applied. The correction keeps §5's *decision* exactly as it is
  (a redemption is written only at placement, never at cart-apply time) and drops
  only the "not yet" half of it.
- **The one fact this design adds to that document's picture, and it is a decision,
  not a question: a redemption is released when — and only when —
  the order becomes `cancelled`.** Not on a return after delivery, not on a refund.
  §7.4 here states the whole rule and the two queries it changes
  (`countForPromotion()`, `countForPromotionAndAccount()`), and that is the sentence
  this document's §5/§6 gain in stage 6. The direction is deliberate: a cancellation
  means *this sale did not happen*, so the slot a rejected order consumed is handed
  back, while a delivered-and-returned order did happen and keeps its slot — which is
  what stops a use-then-return loop through `usage_limit_per_customer` (§7.4's third
  bullet argues both directions). Nothing else in that document moves: §8's per-line
  allocation is read as-is, and `Money::allocate()` stays Pricing's.

### `admin-panel-design.md`

- **§14's D1 (lines 1005-1013)** — "Strictly read-only" gains one sentence, because
  it stops being literally true the moment §8's actions ship: the posture holds for
  pages, forms, bulk actions and row actions, and §8 adds none of those — but a View
  page with its own header actions is exactly what §8 is. The edit amends D1 and
  restates what stays true, rather than deleting D1 or leaving it to be discovered
  as false.
- **§14's "Deferred, explicitly" list (lines 1150-1159)** — two bullets change shape.
  "Order status transitions / any editing capability" splits in two: transitions are
  built, as §8's header actions on the View page, while editing capability stays
  deferred exactly as written. "Refunds UI … with no admin surface yet" becomes false
  in the same stage — the money step *is* that surface, inside the cancel/return
  dialog — so the bullet is replaced by what it now is (money written by a
  cancellation or a return on the order's own View page, never an operation of its
  own — R4).
  The by-status half of the list-filters bullet is the same subject as §14 Q2 here,
  which is why that bullet is left to Stage 7 rather than Stage 8.
- **§11's bullet (lines 369-370)** — "Order creation/editing from admin — orders
  originate from checkout only" is narrowed, not relaxed: creation and line/price/
  address editing still originate nowhere else, and §9 here lists exactly that as not
  covered. One sentence, so §11's reader does not read §8's new buttons as the start
  of an editing UI.
- **§14's D5 (lines 1031-1044)** — unchanged, and worth saying why: `OrderAdminReader`
  stays the one cross-table read for the section, and §8's actions are writers, not a
  second reader. Nothing new is added to the List page's query shape either — §5.2's
  row lock is per record, one order at a time, and the actions redirect on success
  instead of re-reading in place.

### `app\Filament\Resources\OrderResource.php`

- **The class docblock (lines 35-53)** — one sentence added, for the same reason D1
  needs one: it reads "STRICTLY READ-ONLY (D1): list and view only, no create/edit/
  delete pages, no bulk actions, no status transitions", and it must not be left
  saying that after §8 ships. Everything else in it stays true and is untouched —
  `form()` still returns `[]`, `getPages()` still holds only `index`/`view`,
  `createPermission()`/`editPermission()`/`deletePermission()` stay unset, and every
  cross-table read still lives in `OrderAdminReader`. Stage 7, comment-only: the
  stage that makes the sentence false is the stage that corrects it.

### `extensibility-design-and-hooks.md`

- **§3's Hook Reference table (lines 48-54)** — five new rows, landing in Stage 8:
  `order.status_changed`, `order.cancelled`, `order.returned`, `order.refunded` and
  `order.payment_confirmed` (§12), each with its firing site, signature and purpose.
  The table's own rule — a row lands in the same commit as its call site — is why this
  is not optional, and why all five are listed there rather than left to be found in
  the services that fire them.
- **The row that is missing today, and is that same rule's existing violation:
  `order.placed`.** It is fired at `CheckoutOrchestrator.php:197`, and the Hook
  document's own §1 (line 18) already uses it as the example that illustrates the
  whole mechanism — yet the reference table has no row for it. §12 adds that row; this
  design does not move the call, change its payload, or fire it a second time.
- **§3's naming convention (line 44)** — unchanged in substance, and read as
  permitting the two-segment shape when the entity *is* the domain's aggregate:
  `order.placed` is the precedent, and §12's five names match its `order.` prefix
  rather than inventing a third segment. The convention's other half is followed too:
  a hook name is a stable string, never an enum class name, which is exactly why
  `order.status_changed` carries the from/to statuses in its payload instead of
  encoding them in its name.
- **§2's boundary** — followed, not bent: §12's hooks are fired from `app/Services/*`
  (`OrderStatusChanger`, `OrderPaymentConfirmer`, `OrderRefunder`,
  `OrderEventRecorder`), never from inside `EasyCo\Order`.
  The domain dispatches nothing and gains no `Illuminate` import; the only list of
  transitions stays `OrderStatus::canTransitionTo()` (§5.1).

### `staff-access-domain-design.md`

- **§3's Orders block (lines 74-78)** — one sentence: what those four existing
  permissions now gate is designed in §8 and §9 here, and the block gains nothing —
  no new permission, no new role, no new grouping. `RoleResource.php:255`'s `'Orders'`
  group already holds exactly `ORDER_VIEW`/`ORDER_MANAGE`/`REFUND_CASH`/`REFUND_BANK`,
  so the role editor's Orders section is complete before this design and needs no row
  added; §9 here says the same from the other side by listing "any new permission or
  role" as not covered.
- **§10's "Granular order permissions" bullet (line 294)** — unchanged, and now
  exercised: status and fulfilment sit behind `ORDER_MANAGE`, money-out behind
  `REFUND_CASH`/`REFUND_BANK`, and which of the two applies is derived from the settled
  payment's own method (§2.2 R5) rather than chosen in the UI where it could be chosen
  wrongly. That is the evidence the bullet's "splitting further is easy later" argument
  was written for. Splitting further is still not proposed, and no permission is added.
  One thing noticed while reading it, flagged rather than quietly fixed: the bullet says
  the document "ships three" and its own parenthetical then lists four values
  (`ORDER_VIEW`, `ORDER_MANAGE`, the two refund permissions). That is a counting slip
  inside that document's sentence, outside this design's blast radius — left for its own
  owner to fix in whatever pass touches that file next.

### Documents checked and deliberately not amended

Every other document in this folder was checked for a sentence this design makes
false, by searching the whole folder for the vocabulary it changes (`OrderStatus`,
`FULFILLED`, `orders.status`, "order status") rather than by memory. Nothing outside
the files above matches. Two near misses, named so nobody "fixes" them later:

- `channel-native-commerce-vision.md:779`'s `OrderFulfilled` is a vision document's
  name for an event that exists nowhere in code and is not one of §1's six statuses.
  Left exactly as it is.
- `checkout-prerequisites-note.md`, `vertical-slice-notes.md` and
  `sandbox-manual-test-checklist.md` describe the checkout order's own first status
  without naming the enum, so §1's rename does not reach them.

The remaining documents contain no order-status claim to contradict:
`cart-domain-design.md` (the cart empties at placement and knows nothing of a status),
`catalog-domain-design.md`, `pricing-domain-design.md`,
`pricing-persistence-domain-design.md`, `account-domain-design.md`,
`address-domain-design.md`, `media-domain-design.md`, `site-settings-design.md`,
`storefront-frontend-design.md` and `production-requirements.md` — none owns an order
lifecycle, and this design adds no storefront, media or setting surface.

**The rule to hold onto when reading this section:** only the sentences carrying a
stage number are edits. Everything else above is a check that came back negative, and
is written down because a cross-reference section that lists only its hits reads as
though it stopped looking.

---

## 14. Open questions for the owner

§0–§13 decide. This section is the other half of the document: eight questions whose
answer is the owner's rather than this design's. Each one states what this document
does by default, what the fork actually is, and a recommendation with its reason — so
an unanswered question still has a deliberate default behind it instead of a gap.

**None of them blocks the pass.** The "answer lands in" line beside each names the last
stage that can absorb an answer without rework; every stage's own tests assert the
defaults below, so silence is a decision rather than a stall. Two things this document
settles for itself are not asked here: what a row that still holds `fulfilled` does to
the build order (stage 1's migration refuses to run until a human resolves it — §10,
owner decision D1) and whether a redemption is ever released (it is — on a cancellation
only, §7.4).

**Q1 — A cash-on-delivery order whose payment cannot be confirmed: what should the
delivery do?**

- **Default:** §4.5's table — the delivery is recorded, no money fact is written, and
  the panel names the payment's own state (a `FAILED` attempt, an unanswered one, or no
  row at all). Nothing is invented to fill the gap, and nothing blocks the goods fact,
  because the goods are physically gone whatever the money did.
- **The fork:** three shapes are defensible. (a) Refuse the delivery until a
  confirmable payment exists — loud, and wrong for the case this question exists for,
  where the cash was refused at the door and the parcel came back. (b) Record the
  delivery and leave the money unrecorded, as the default does. (c) Give the operator a
  way to record the money anyway — a new payment row, or a manual confirmation that
  overrides the adapter's `FAILED` — which is a new write in the payment domain, and
  the one option that could contradict an adapter's recorded answer.
- **Recommendation:** the default, and a deliberate refusal to invent (c) here. The
  empty case is a *legacy-data* case in practice: checkout always writes one `PENDING`
  payment with `attempted_at` set (§0 item 5), so "no row" means something wrote an
  order without its payment, and the honest answer to money the system cannot confirm
  is to say so rather than to record money nobody saw. If the owner wants it recordable
  regardless, that is a payment-domain addition — the mirror of `PaymentRefund` for
  money coming *in* — and it belongs with Q3's answer.
- **Answer lands in:** stage 7 for the panel's statement; a future payment-domain pass
  for anything beyond it.

**Q2 — Which of the six statuses should the Orders list's status filter offer?**

- **Default:** all six (§10 stage 2), and no filter is built until stage 7.
- **The fork:** a filter listing only the four active statuses (`placed`, `confirmed`,
  `shipped`, `delivered`) keeps the dropdown short and matches the day-to-day
  question, "what still needs me?" — but it makes the two terminal statuses unreachable
  *by filter* while remaining visible on every badge. A merchant looking for a cancelled
  or refunded order would have to search by hand.
- **Recommendation:** all six, because the filter's job is to reach anything the panel
  can display, and the terminal ones are exactly the population a merchant audits
  (`cancelled` and `refunded` are the rows someone looks for after a bad week). A
  six-item dropdown on a screen with five columns costs nothing, and §8.4's badge split
  (`refunded` visually distinct from `cancelled` and from `delivered`) is what makes the
  filter usable once it has them. If it ever gets noisy, the honest fix is a second
  dimension — date range — not hiding values.
- **Answer lands in:** stage 7, which builds the filter; stage 2's labels already cover
  all six either way.

**Q3 — When money has to move and no payment row records it, is recording nothing
acceptable — in both directions?**

- **Default:** yes (§2.2 R5, R8(c); §7.3). Every money step reads *recorded* money: a
  settled `Payment` is what a refund reverses, and a `pending` one is what a void can
  touch. Money the system never saw — cash handed over outside the record, or a refund
  a merchant made by transfer when no payment row existed — leaves no row here. The
  shortfall is stated rather than hidden: the money moves, the system records nothing.
- **The fork:** the alternative is to make unlinked money recordable — a `PaymentRefund`
  with no payment, a free-standing cash-movement table, an "adjustment" row, or, for
  money *in*, a manual payment the system never attempted. Each is a new fact in the
  payment domain, decided by `payment-domain-design.md`, not here.
- **Recommendation:** record nothing in V1, in either direction. By construction this is
  the case where the system never knew about the money: had a row existed, §7.3's refund
  (or its void) would be the path, with a record. So the fork is not "record the same
  thing differently" but "invent a home for money nobody recorded" — and a refund row
  with no payment would break the one thing every money query in the panel relies on,
  that a refund is always the reversal of a specific payment. §11's "no derived column"
  argument is the same argument: an orphan money row is a second source of truth for
  nothing. Q1's option (c) is this gap pointing the other way, and the two should be
  answered together — one "money with no payment row" position covers both.
- **Answer lands in:** no stage of this pass; a later payment-domain pass, if the owner
  wants it recorded rather than accepted.

**Q4 — Confirm that neither a cancellation nor a return requires a reason.**

- **Default:** both are optional free text (§5.2, §11 item 7). The reason is stored
  verbatim in `order_events.reason` and never translated; what the panel translates is
  the *machine's* refusal reason (§8.3 item 4). Neither operation has a field that must
  be filled, so the two a merchant performs most often keep their shortest path.
- **The fork:** requiring prose would make every terminal status explainable a year
  later by construction, at the cost of a field that reads "customer changed their mind"
  fifty times a week and stops being read at all — the argument
  `operational-sales-domain-design.md` §3.13 Q4(b) already made for the return alone.
  The narrower middle — required on a cancellation, optional on a return — splits
  the two ways of choosing a quantity inside one operation (§2.2 R2) into two
  different contracts, and would make the shared return worker ask which caller it
  is serving.
- **Recommendation:** keep both optional, and keep the *machine's* refusals mandatory
  (they are enums, so they cannot be empty). If the owner wants prose required, the
  change is one guard in each of §5.2's two methods plus the form's own `->required()`,
  both in stage 7's surface — a form change, not a data change.
- **Answer lands in:** stage 6 (the service guards) and stage 7 (the form), before
  either is written.

**Q5 — Is a recorded reversal fact wanted for `confirmed_at`, or does write-once stand
alone?**

- **Default:** write-once, no un-confirm, no reversal fact (§4.5, §11 item 6). A
  confirmation recorded against the wrong order is corrected through the money trail:
  the money's true state is re-recorded by the operation that reflects it — a
  cancellation, or a return with its own money step — and the timeline shows the
  confirmation event followed by the operation that undid its effect.
- **The fork:** "the confirmation was wrong" is arguably a different fact from "the money
  came back" — one says the operator misread a bank statement, the other says the money
  left again. The clean shapes are a sixth `order_events.type`
  (`payment_confirmation_reversed`, append-only, no change to `payments`) or a nullable
  `unconfirmed_at` column, which would contradict §4.5's write-once rule and is refused
  either way.
- **Recommendation:** no sixth event type in V1. If one is ever added, the event is the
  right shape rather than the column, because §11 item 8's rule allows adding a type and
  forbids rewriting the meanings of the ones that exist — that rule is what makes a later
  addition cheap. The reason to wait: unlike a refund, a wrong confirmation has no
  automatic undo, so the event would record a human-only act that the merchant's own bank
  statement already records, in a place no query reads. One sentence from the owner
  settles it; if the answer is "yes", the addition is a type in stage 3's list plus the
  panel action that writes it in stage 7.
- **Answer lands in:** stage 3 if wanted at all — adding a type later is legal under §11
  item 8, but it must not be *retrofitted* by redefining one of the existing names.

**Q6 — What does a `REFUND` line's own `quantity` column hold, and is any per-line detail
of a return wanted beyond it?** (Two halves in one question — §7.2's ⚠️ and §8.4's "no
per-line condition grade, no per-line reason" both point here.)

- **Default:** `quantityReturned` carries the counted-back units (already designed in
  `operational-sales-domain-design.md` §3.4 / §3.13 item 4, and what R7 sums), while the
  sibling `quantity` column — which §3.4 gives no meaning for on a `REFUND` line, though
  the constructor asserts it `> 0` — is recommended to hold the *original* SALE line's
  quantity, so one refund row states both numbers: *2 of the 5 that line sold*.
- **The fork on (a):** the honest alternative is `quantity` = the returned count, matching
  "what this line is about" and how a POS refund line reads. Its cost is that `quantity`
  and `quantityReturned` then hold the same number on every `REFUND` line forever — a
  duplicate that can disagree with itself, the shape §11 item 11's "no derived column"
  rule exists to keep out — and it moves R7's denominator off the row the guard already
  reads and into a join back to the sale line. Under the recommendation the pair means
  something; under the alternative the second number means nothing.
- **The fork on (b):** per-line *reasons* are already representable with **no schema
  change**, because §7.2 writes one `REFUND` line per returned `SALE` line and each
  carries its own `returnReason` — §3.13 Q4(b)'s decision is per-line whether or not the
  form is. What is missing is the *form*: §8.4 collects one optional reason for the whole
  return, because the alternative is a text input per row on a table whose job is
  quantities. Per-line *condition* grades (sellable/defective) have no field anywhere, and
  one is only worth having if it gates restocking — which R3's per-line restock flag now
  answers in one bit, without a grade (§14 Q7).
- **Recommendation:** (a) as documented — the original line's quantity; (b) one reason per
  return in the form for V1 and no condition grade, while recording that per-line detail
  is a *form* change rather than a data change. Both halves deserve the same trigger: a
  returns desk that needs per-line reasons is a returns desk with a real defect rate, and
  that is the moment to add them together, not before it.
- **Answer lands in:** the operational-sales pass that implements `quantityReturned` (this
  document writes lines only through that domain's own API and states its expectation
  here); the form half lands in stage 7.

**Q7 — Confirm that a return restocks and never republishes: overruling the *online* half
of `operational-sales-domain-design.md` §3.13 Q4(c).**

- **Default:** §7.2 step 3 does exactly one thing to the world — stock up, and only for the
  lines R3's flag leaves on. It does **not** touch visibility or purchasability, does
  **not** activate an inactive parent Product, and does **not** restore an archived
  variation (so it can never meet the axis-drift `VariationNotRestorableException` Q4(c)'s
  own open points flag). When the variation is draft or archived the confirmation says so
  and links to the product. The "don't restock, it's defective" case is answered by the same
  flag: the operator turns *return to stock* off for that line.
- **The fork:** Q4(c) decided the opposite for an online return — "always increases stock AND
  makes the variation sellable", reactivating an inactive Product so the restock is not
  unsellable. That was written before a storefront order existed to attach it to, and nothing
  in this design contradicts it *except* §7.2 — which is why the overrule is stated where it
  happens (§7.2, §13) instead of being left for a reader to notice. What is still not built
  is a per-line *condition grade*: the flag records what happened to the stock, a grade
  would record why, and §14 Q6 is where that belongs.
- **Recommendation:** keep §7.2, and route the merchant to the catalogue rather than doing
  the catalogue's work for them. Republishing a variation from a returns screen silently
  makes product-level decisions — media, axes, price, slug/SEO — at the one moment the
  operator is least focused on them; the one-click link is the honest half-fix, because the
  merchant then publishes deliberately, having seen the product. A unit that is not going
  back on the shelf is already expressible without a new concept: R3's flag off, plus the
  operator's own words in the reason field if they want to say why.
- **Answer lands in:** §7.2 as written, i.e. stage 6. Republishing from the return flow would
  be a new catalogue-side operation with its own failure modes and its own stage — not an
  edit to stages 1–6, all of which stand either way.

**Q8 — Confirm the two-value refund-permission derivation, and accept that a future online
method has no permission of its own.**

- **Default:** `cash_on_delivery` → `REFUND_CASH`, everything else (bank transfer today,
  any future card/online adapter) → `REFUND_BANK`, derived from the settled payment's own
  method and *shown* in the panel rather than chosen (§2.2 R5, §8.3). No third permission —
  and because R4 makes money a side effect rather than an action of its own, the derived
  permission is required *in addition to* `ORDER_MANAGE` on the cancellation or return that
  will refund (§8.2, §8.3).
- **The fork:** two questions wearing one coat. The first is whether the mapping is right:
  cash at the door is the till, a transfer is the bank. The second is whether a
  processor-initiated reversal — money that never sat in the merchant's bank account at all
  — is genuinely the `REFUND_BANK` role's business, or deserves a permission held by
  whoever owns the online channel.
- **Recommendation:** confirm the mapping as designed; defer the second half until an adapter
  exists to force it. The enum is the only place a permission is *named*
  (`staff-access-domain-design.md` §10's own reasoning), so a third one is small and additive
  — the same posture that document already takes toward POS permissions with no endpoint
  behind them. Inventing one today would encode a distinction no role has asked for, which is
  precisely what §10 declines to do.
- **Answer lands in:** stage 7 for the panel's own derivation; a future online-adapter pass
  for anything beyond it.

---

**If only three of these ever get answered,** answer Q1, Q3 and Q7. Q1 because it is the
case with no tidy implementation either way, and what the panel does on a cash-on-delivery
delivery that cannot confirm money has to be settled before stage 7 builds it; Q3 because
it is the same gap seen from the other side — money the system never recorded, moving in or
out — and the two answers have to agree with each other; Q7 because it is the single place
this document overrules another one, so leaving it open leaves two documents disagreeing
about what a return does to the catalogue. Q2, Q4, Q5, Q6 and Q8 all have defaults that are
safe to ship behind, for the reason each one states, and each is absorbable by the stage
whose surface it touches. None of the eight changes the lifecycle's shape: the order moves
through the same states either way.
