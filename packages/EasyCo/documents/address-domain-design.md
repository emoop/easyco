# Address Domain Design

**Status:** Draft v1 — approved scope, not yet implemented.
**Builds on:** `checkout-prerequisites-note.md` (the gap this document closes — read that note first, it records why this domain is needed and the constraints already agreed before this design), `catalog-domain-design.md`'s barcode field (§ "Why sku is mandatory but barcode stays optional" and its "no generation logic at all today; every value is caller-supplied" note on SKU-generation extensibility — the precedent this domain's pickup-point fields deliberately mirror), `operational-sales-domain-design.md` (`SaleLine` immutability — the snapshot posture a future Order will need toward Address, noted here but not implemented by this domain).
**Origin:** designed through a structured conversation about two real constraints: guest checkout must keep working (a standing project decision, Baymard-research-backed), and Bulgaria-market delivery reality (courier pickup-point delivery, e.g. Econt/Speedy office pickup, is common and structurally different from a street address) generalized into a carrier-agnostic, extensible shape rather than hardcoded to any one country or courier.

---

## 1. Scope

This document defines the **Address domain**: a delivery destination, capturable during checkout by either a guest or a logged-in customer, optionally saved into a logged-in customer's reusable address book.

**Decision: captured per-checkout by default, optionally saved to an account.** An Address is not required to belong to an Account — `account_id` is nullable. A guest's checkout-time address is a real, valid, standalone Address row with a null `account_id`. A logged-in customer's address, if they choose to save it, gets `account_id` set and becomes part of that account's reusable set (`AddressRepository::findByAccountId()`). This is the simplest structure that satisfies both cases without half-implementing two separate models — see `checkout-prerequisites-note.md`'s own framing of this exact tradeoff.

**Decision: two delivery types, deliberately carrier-agnostic.** `delivery_type`: `STREET_ADDRESS` | `PICKUP_POINT`. A `PICKUP_POINT` is NOT hardcoded to Econt/Speedy or to Bulgaria — see §3.

**Explicitly out of scope for V1** (deferred, not forgotten):
- Any carrier integration — looking up real pickup points for a given settlement/carrier (e.g. "show me Econt offices in Plovdiv") is entirely outside this domain, exactly as barcode generation is outside Catalog. See §3.
- A "default address" flag on a saved account address book entry — cheap to add later once the account address book actually has an HTTP surface; premature now.
- Order-time snapshotting — this domain defines the Address entity itself; a future Order taking an immutable copy of one at checkout time is that future Checkout/Order domain's job, not this one's (mirrors exactly how `OperationalSales\SaleLine` snapshots Catalog/Pricing data — noted here as the expected direction, not built here).
- Any HTTP surface — domain + persistence only, matching how every other domain in this project has been staged (design doc → domain/persistence → HTTP, across separate prompts).

---

## 2. Core entity: Address

```
Address
├── id
├── accountId              nullable — null for a guest/one-off checkout address;
│                          set only when saved into a logged-in customer's
│                          reusable address book
├── deliveryType           STREET_ADDRESS | PICKUP_POINT
├── recipientName          string, required regardless of type — who
│                          physically receives the delivery
├── phone                  string, required regardless of type — every
│                          courier needs a contact number
│
│ STREET_ADDRESS fields — required when deliveryType = STREET_ADDRESS,
│ must be null when deliveryType = PICKUP_POINT:
├── country                string (ISO 3166-1 alpha-2, e.g. "BG")
├── city                   string
├── postalCode             string, nullable even for STREET_ADDRESS —
│                          some regions/rural addresses genuinely
│                          don't have one, same "the physical world
│                          doesn't always cooperate" reasoning
│                          barcode's own nullability documents
├── addressLine1           string
├── addressLine2           string, nullable
│
│ PICKUP_POINT fields — required when deliveryType = PICKUP_POINT, must
│ be null when deliveryType = STREET_ADDRESS:
├── carrierCode            string, free-form — "econt", "speedy",
│                          "dhl", anything — NOT an enum, NOT
│                          validated against a known-carriers
│                          list. See §3.
├── pickupPointReference   string — an opaque id/label meaning
│                          entirely whatever the carrier's own
│                          system says it means. See §3.
└── settlement             string — the town/city the pickup
                           point is in, for display/snapshot
                           purposes only
```

Constructor validates the same kind of exclusivity `Cart` already enforces between `accountId`/`sessionToken` (see `Cart.php`'s own constructor guard as the precedent to mirror): exactly the fields belonging to the given `deliveryType` may be non-null, never fields from the other type, regardless of which type is chosen.

---

## 3. Why carrier/pickup-point fields are free-form, not a carrier integration

**This is a hard boundary, not a temporary shortcut.** `carrierCode` and `pickupPointReference` are plain, caller-supplied strings — this domain never validates that `carrierCode` names a real, known carrier, and never looks up or validates that `pickupPointReference` corresponds to a real, currently-open pickup location. Exactly the same posture `catalog-domain-design.md` already documents for `barcode`: "no generation logic at all today; every value is caller-supplied," deliberately, because different markets and different carriers have genuinely different data (Econt and Speedy in Bulgaria, DHL/UPS/whoever elsewhere), and hardcoding any one of them would make this domain wrong for every market it doesn't happen to hardcode.

**What "show me Econt offices in Plovdiv" actually requires** — a real carrier-integration layer (fetching/caching a live office directory per carrier, keyed by settlement) — is explicitly a future `Shipping`/carrier-integration domain's job, not this one's. This document does not design that layer. It only guarantees Address has a shape that layer's output can be written into once it exists — the same relationship `catalog.variation.sku`'s Hook-based generator has to the plain `sku` column it fills.

---

## 4. Cross-domain contracts

- **Account → Address:** `accountId` references an Account by plain id only — cross-domain by id, same convention as everywhere else in this project. Address does not depend on the Account package.
- **Address never depends on Catalog, Pricing, Cart, or any carrier-specific package.** A future Checkout orchestrates Address alongside Cart/Pricing/Inventory — this domain stays exactly as narrow as Catalog stays toward Pricing (§1 of `pricing-domain-design.md`'s own cross-domain discipline).

---

## 5. What's deliberately not implemented in this iteration

- Any carrier integration (see §3).
- A "default address" flag on saved account addresses.
- Order-time immutable snapshotting (a future Checkout/Order concern).
- Any HTTP surface — domain + persistence only in the first implementation prompt.
- Address validation/normalization services (postal code format checking, address autocomplete, etc.) — plain caller-supplied strings for V1, same posture as every other free-form field in this document.

---

## 6. Testing plan

- `Address` domain unit tests: construction/validation for both `deliveryType` values (correct required fields present, correct forbidden fields absent for each); mixing STREET_ADDRESS fields into a PICKUP_POINT construction (or vice versa) is rejected; `recipientName`/`phone` required regardless of type; `accountId` nullable and optional.
- Repository Feature tests (real MySQL): save and round-trip both delivery types; `findByAccountId()` returns every saved address for that account; a guest (`accountId: null`) address round-trips correctly and is excluded from any `findByAccountId()` query.
