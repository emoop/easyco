# Checkout prerequisites — two gaps to close first

**Status:** Note only. Nothing here is built or designed yet. Recorded so it isn't rediscovered late, when it would be expensive.

**Origin:** an external architecture-review document proposing the Commerce Core completion order (Catalog → Pricing → Inventory → Cart → Promotions → Checkout → Payment → Order). Its sequencing matched this project's own plan almost exactly, but it surfaced two things genuinely absent from our roadmap, plus one assumption we deliberately reject. Note that the same document described Catalog/Pricing/Inventory/Cart as upcoming work — all four are complete and pushed — so it was written without reading the real repo state. Its value is in the two gaps below, not as a status report.

---

## 1. No Address model exists

Nothing anywhere in this project can hold a delivery destination. Checkout cannot be meaningfully "complete" without one — an order with no address isn't an order.

Open questions to settle before Checkout is designed:
- Does an Address belong to `Account` (a saved address book, reusable across orders) or is it captured per-order (typed fresh at checkout, snapshotted onto the order)? Very likely both eventually, but V1 should pick one deliberately rather than drift into a half-implementation of each.
- Guest checkout must remain supported (a standing project decision — Baymard research on forced-account-creation abandonment). So an address captured at checkout cannot require an `Account` to exist. That constrains the answer above.
- Bulgaria-specific reality worth checking before modelling generically: courier-office delivery (Econt/Speedy office pickup) is extremely common here and is not the same shape as a street address. Modelling only street addresses would be a Western-e-commerce default that doesn't fit the actual market this store operates in.
- Immutability: once an order is placed, its address must be a snapshot, never a live reference to a mutable saved address — the same reasoning `OperationalSales\SaleLine` already applies to price and product data.

## 2. No Payment domain boundary

Our roadmap previously ran Checkout → Order directly, with no explicit Payment layer between them. Payment provider integrations must sit behind a contract so Checkout never depends on a concrete provider:

```
Checkout
↓
Payment contract (this project's own interface)
↓
Payment provider adapter (Stripe, a Bulgarian PSP, cash-on-delivery, bank transfer, ...)
```

Why this must be decided BEFORE writing Checkout, not after: Checkout has to know the Payment contract's shape from its first line of code. Retrofitting a boundary into an already-written orchestration layer is exactly the kind of rework this project avoids by designing first.

Points to settle:
- Cash on delivery and bank transfer are not "payment providers" in the API sense but ARE real payment methods this store needs — the contract must accommodate a method that involves no online authorization step at all, not just card-processing flows.
- Where does payment state live? A `Payment` record separate from the Order, or a status on the Order itself? Affects how a failed-then-retried payment is represented.
- Idempotency: a customer double-clicking "pay" must never produce two charges or two orders. Which layer owns that guarantee?

## 3. Inventory reservations — deliberately NOT adopted

The same external document assumes a reservation strategy is needed. This project decided otherwise, on purpose: a soft availability check at add-to-cart time, and a hard, atomic decrement at Checkout finalization — no holds, no reservation records, no expiry-sweeping of held stock (see `inventory-domain-design.md` and `cart-domain-design.md`).

That decision stands and should be reconfirmed, not silently reversed, when Checkout is written. What Checkout DOES need is that the finalization-time decrement is genuinely atomic and race-safe — `EasyCo\Inventory`'s `decrease()` is already a single conditional UPDATE for exactly this reason. The correct behavior when stock ran out between add-to-cart and checkout is a clean, explicit failure at checkout, not a reservation that prevents the situation from arising.
