# Shipping, phone validation and checkout resilience: the agreed queue

**Status:** working note, 2026-10-01. Records the order of work agreed with the domain owner and the open items that must not be lost. Every other document in this list is the source of truth for its own subject.

## The queue, in order

1. **Shipping stage 1:** entities and persistence (`ShippingClass`, `ShippingZone`, `ShippingMethod`). DONE, approved for commit.
2. **Shipping stage 2:** `Order` carries shipping (`shippingMinor`, method name and code snapshot, `total = subtotal - discount + shipping`). Part A is reconnaissance only, then the change in committable steps.
3. **Shipping stage 3:** zone matching, rate calculator (most-expensive-class rule, threshold after discount), quote endpoint, `SettlementNameNormalizer`.
4. **Phone validation:** design document first (coder reads the real code), then implementation (libphonenumber, E.164 storage, filter hook for merchants).
5. **Checkout resilience audit:** read-only, against the list below, BEFORE stage 4.
6. **Shipping stage 4:** checkout integration, recompute-and-refuse in Phase 1.
7. **Shipping stage 5:** admin screens (classes, zones, methods) plus the shipping-class field on product/variation forms and the migration of `Variation.shippingClass` from free text to codes. Admin must warn visibly when no shipping zone exists.
8. **Later:** price-list admin (list screen, attach field on the product, bulk attach; part of it may be solved with the existing scopes, to be checked against real code).

## Owner decisions recorded here

- Zones are created through the admin; with no zone, delivery is impossible, by design.
- Settlement matching follows the STORE's locale setting. No cross-script transliteration in V1; a merchant lists each spelling. Postcodes compare exactly after trimming. Needs a store-level locale/country setting (verify whether one exists).
- Phone number is mandatory on every online order; an invalid number fails the order. libphonenumber is accepted as a dependency. A hook lets a merchant attach stricter verification (e.g. SMS ownership check).
- A customer with an account always has access to the purchase history, read from the placement snapshots. Guest orders attach to an account only after a verified email.
- A failure anywhere in checkout never deletes the cart; the merchant must see it, and the customer must get a clear message.
- **Refund of shipping (PARTLY SUPERSEDED, to be settled 2026-10-02; see the last sentence of this item):** the shipping charge is refunded ONLY when an order is cancelled before it ships (nothing left the shop; for a prepaid order the refund is the full amount paid; for cash on delivery nothing was collected). Once an order has shipped, the shipping charge is never refunded: a refused or uncollected parcel (shipped -> cancelled; the merchant bears the loss), a full return after delivery, and a partial return all refund the goods only. No explicit "refund the shipping" action exists in V1. Rule of thumb: shipping is refunded iff the parcel never left. **Added by the owner on 2026-10-01 (supersedes the "after shipping, never" part for this case):** an order paid through a virtual POS that is returned within 14 days is refunded in full, shipping included. To settle on 2026-10-02: whether it applies only to virtual-POS payments or also to cash on delivery and bank transfer; whether it covers a full return only (partial returns keep the shipping); what the 14 days count from (delivery date) and whether the window is a configurable store/jurisdiction setting; and how a refused parcel is treated. The rule must live behind ONE policy seam.
- **Return shipping (decided 2026-10-01):** the shop does not bear return shipping by default; the customer pays it directly to the courier, outside the system, and the system never moves return-shipping money in V1. Who bears it is a store policy, expected as a store setting tied to the terms of use; its V1 effect is informational only (shown to the customer, available to hooks). Reimbursement arithmetic is deferred. Real shop: its terms already state that the customer pays return shipping; the shop currently runs without a virtual POS (cash on delivery and bank transfer only); for a parcel that is refused or never collected, the return shipping is the shop's cost. DECIDED by the owner: on a full return within 14 days of a cash-on-delivery order the original shipping charge is NOT refunded (goods only, e.g. 80 on an 80 + 5 order), because the shop's fixed shipping fee is usually below the real courier cost. This is the shop's own policy, not the default of a legal profile; the policy seam must make it configurable.

## Checkout resilience audit: what the read-only audit must verify

- Phase 1 is one transaction with no external call inside; amounts are recomputed server-side; a changed amount refuses the order and writes nothing.
- Cart-to-order link gives idempotency: a double submit returns the same order.
- Virtual POS (when it exists): the browser return is NOT proof of payment; only the signed server-to-server callback is, plus periodic reconciliation with the provider for pending payments. Verify signature, amount, currency and order match; handle repeated callbacks idempotently.
- Pending-payment orders older than a threshold are found and resolved by a scheduled job. Verify a scheduler actually runs (`cart:prune` is still unscheduled).
- Merchant-visible "needs attention" list: payments pending too long, amount mismatches, failed attempts.
- Security: no card data touches our server; signature checks on every callback; session cookie flags and CSRF; rate limiting on checkout endpoints; owner-only access to orders (guests via signed token, not guessable id); no personal data in logs.

## Open items and deferred notes from stage 1 review

- No delete for classes, zones, methods: the foreign keys refuse deletes with dependants. The admin stage needs an application-layer deletability check and an archive-versus-delete decision.
- `freeAboveMinor` on a CARRIER method: stage 3 must define how a threshold interacts with a live carrier quote.
- Zone `settlementPatterns` are stored only; the matching grammar belongs to stage 3 (see the locale decision above).
- `ship_classes_code_unique` is case-insensitive and relies on the domain's lowercase-only format; an import path that bypasses the domain would be caught by the database, not by the format check.
- On SQLite a class foreign-key failure would surface as a raw `QueryException` (the message carries no constraint name). No test of this package runs on SQLite.
- Deferred, shipping money: recording the merchant's own cost of a refused or uncollected parcel (outbound plus return courier charges) for reporting; an optional, off-by-default merchant action "also refund the shipping" for merchant-fault returns (wrong or defective item), which the owner excluded from V1; reimbursement of return shipping when the store policy makes the shop bear it.
- `shipping-domain-design.md`: status line ("not yet implemented"), the settlement-matching addendum to §4, and the product-form note need a docs pass.
