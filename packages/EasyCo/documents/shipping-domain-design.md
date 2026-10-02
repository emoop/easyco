# Shipping Domain Design — zones, classes, methods, and the doors a carrier plugs into

**Status:** Draft v1 — approved scope. **Stage 1 (entities and persistence) is implemented** (commit `0fdbfad`); stage 2 (`Order` carries shipping, §7) is in progress; stages 3–5 are not started. The order of work is in `shipping-and-checkout-queue-note.md`.
**Closes / supersedes:** `product-shipping-fields-note.md`'s open questions 1–4 (that note stays as the record of what was already true before this). Resolves `checkout-domain-design.md` §3/§10's standing statement that `Order.total` carries no shipping component.
**Builds on:** `address-domain-design.md` (the `PICKUP_POINT` shape this design depends on and does not change), `checkout-domain-design.md` §8.3 (the two-phase placement this inserts into), `payment-domain-design.md` (whose adapter/resolver shape the provider contracts here deliberately mirror), `promotions-domain-design.md` (unchanged by this document — see §8).
**Origin:** the domain owner's requirement, stated directly: this is not being built only for his own shop, so it has to be a working shipping system — zones, classes, a fixed amount, a free-shipping threshold, free or priced delivery driven by a product's class, all varying by zone — **with the foundation first and the doors for anyone to plug an extension into**. Econt / Speedy / BoxNow are explicitly NOT part of it; they come after, through those doors.

Two platforms and the Bulgarian market were checked before any of this was written:

- **WooCommerce** models shipping as ordered **zones** → **methods** → **classes**: zones are matched against the customer's address top to bottom, the first match wins, and each customer matches exactly one zone; the built-in methods are only three (flat rate, free shipping, local pickup); classes are assigned per product and let a method charge differently per class. The skeleton is sound and is adopted below. The domain owner's own criticism is about the *reach* of those three methods, not the structure: no office selection, no weight or volume calculation, no label from the carrier's API — which is exactly right.
- **Shopify** keeps the same skeleton but ships three *kinds* of rate rather than one: flat, weight-based, and value-based (price thresholds — i.e. the free-shipping threshold as a first-class concept). More importantly it has what Woo lacks: a **carrier service** callback, where an external provider returns live rates for a request, with response caching and fallback rates when the provider times out. That callback is the model for §6's provider contract.
- **The Bulgarian market confirms both.** An entire industry of paid apps (Dostavi.bg, Izprati, CourierHub, ShipBG, Fulfillvam) exists specifically to add office/locker selection from a map, real carrier pricing, and label generation to Shopify and WooCommerce — the three things neither does natively. That is evidence the work below is the differentiator rather than a reimplementation of something already solved.

---

## 1. Scope, and the three doors

Built here: `ShippingClass`, `ShippingZone`, `ShippingMethod`, rate resolution at checkout, and `Order` finally carrying a shipping amount.

**Not built here, but named and contracted, so a carrier can be added later without reopening this design:**

1. `ShippingRateProvider` — a live rate from a carrier.
2. `PickupPointProvider` — the list of a carrier's offices and lockers.
3. `ShipmentLabelProvider` — the waybill (товарителница).

Each is a contract with zero implementations in V1. They exist so that the Econt/Speedy/BoxNow work is an *addition*, not a redesign. The project already has the exact precedent for this shape: `PaymentMethodAdapter` is bound under named container keys, resolved by `PaymentMethodAdapterResolver`, which refuses an unknown method rather than falling back to a default — because silently charging someone through a method they did not choose is worse than a loud failure. The same rule applies to carriers, for the same reason.

---

## 2. What already exists, verified rather than assumed

- **`Catalog\Variation` already carries the physical facts**: `shippingClass` (today a plain nullable string), `weightGrams`, `lengthMm`, `widthMm`, `heightMm` — integers in grams and millimetres on purpose, so no float drift appears between Catalog and Shipping. Columns, both write paths and both read paths are done. Only the admin UI is missing.
- **`Address` already models a pickup point properly**: `STREET_ADDRESS` or `PICKUP_POINT`, the latter carrying `carrierCode`, `pickupPointReference` and `settlement`, deliberately carrier-agnostic. **This design does not change `Address` at all.** It is the single most valuable thing already in place, because office delivery is where the two big platforms fail and where Bulgarian commerce actually lives.
- **`Order` has no shipping anywhere**: `subtotal_minor`, `discount_minor`, `total_minor`, and `Order::create()` computes `total = subtotal - discount` itself, refusing a separately supplied total. §7 changes exactly this and nothing else about the entity.
- **`Catalog\Product` has nothing**: no physical properties, no columns. So the SIMPLE product's shipping fields are not a UI-only task (§3.2).

---

## 3. `ShippingClass` — a real entity, not a free string

```
ShippingClass                                  (package EasyCo\Shipping)
├── id
├── name                  merchant-facing: "Обемисти", "Чупливи", "Малки пратки"
├── code                  unique, stable, machine-facing; what a Variation refers to
└── description           nullable, for the merchant's own memory
```

Today `Variation.shippingClass` is a nullable free-text column with no list behind it, so no `Select` can read from one and two variations can disagree by a typo. It becomes a reference to this entity's `code`.

**Deliberately `code`, not `id`:** a class is referenced from `Catalog`, and this project's rule is that cross-domain references are plain values, never foreign objects. A stable string code keeps `Catalog` free of any dependency on `Shipping`, exactly as `Address.carrierCode` is a plain string today. Migrating the existing column is therefore a data concern, not a structural one — existing values become codes.

**No class = the method's default rate.** Absence is a normal, supported state, not a configuration error: most products in a boutique need no class at all.

### 3.1 Combining classes in one cart — THE MOST EXPENSIVE CLASS WINS

Domain-owner decision, and the one rule most likely to be "improved" later by someone who assumes summing is more correct:

> **When a cart holds items of several shipping classes, the order is charged the single most expensive of those classes' rates — not their sum.**

The reasoning is the merchant's own: one parcel usually goes out, and charging the customer the sum of three class rates for one parcel overcharges them. If the real dispatch turns out to need more, the merchant edits the placed order and adjusts it there — the order editing flow already exists for exactly this kind of after-the-fact correction.

Summing, per-parcel splitting, and per-item charging are all explicitly rejected for V1. Whoever revisits this should read this paragraph first.

### 3.2 The product side needs real work, not a form field

`Variation` is ready; `Product` is empty. A SIMPLE product has exactly one variation, so it *can* carry its values there — and that is the recommended route, since it keeps one storage shape for both product types and avoids a second source of truth that would need a precedence rule. The SIMPLE form writes to its single variation's fields.

If the decision later goes the other way (product-level values with per-variation overrides), that is a migration plus a precedence rule and must be designed deliberately, not drifted into.

---

## 4. `ShippingZone` — ordered, first match wins

```
ShippingZone                                   (package EasyCo\Shipping)
├── id
├── name                  "България", "София-град", "Европейски съюз"
├── sortOrder             the match order; lower is checked first
├── countryCodes          the countries this zone covers (uppercase ISO alpha-2)
├── settlementNames       nullable — optional narrowing: a list of settlement
│                         names, stored as entered ("София", "гр. София")
└── postcodes             nullable — optional narrowing: a list of postcodes,
                          normalized at construction (trim, ALL whitespace
                          removed, uppercase; ^[A-Z0-9-]{2,12}$)
```

Narrowing is what lets "София-град" sit above "България". The two lists replaced a single free-form `settlementPatterns` list that mixed names and postcodes; with nothing in the data to tell them apart, a postcode could be mistaken for a name. A zone with neither list is not narrowed at all.

**Matching rule, taken from WooCommerce because it is proven and a merchant can hold it in his head:** zones are checked in `sortOrder`, the first one matching the delivery address wins, and **an order matches exactly one zone**. Narrow zones are placed above broad ones.

**A zone matches the address when the country matches AND EITHER of these holds:** the zone has **no settlement names and no postcodes** (not narrowed), **or** the address's settlement matches one of the zone's **names**, **or** the address's postcode matches one of the zone's **postcodes**. A street address's **`city` is its settlement**; a pickup point's **`settlement`** is its settlement. **The delivery country is a validated ISO 3166-1 alpha-2 code on EVERY address, a `PICKUP_POINT` included** (owner decision D1, final; built in stage 3.0b), so the country test applies to a pickup point exactly as to a street address. The store country (`site.country`) is only the **default the checkout form preselects**; it is never substituted for a missing country. A pickup point has **no postcode**, so a zone narrowed by postcodes alone cannot match it. The matcher is built (stage 3a); its exact rules follow.

**An address matching no zone cannot be shipped**, and checkout refuses with a clear message rather than silently offering nothing or falling back to a free delivery. There is no implicit "rest of the world" zone: a merchant who wants one creates a zone with no narrowing and puts it last, which is the same thing but visible in his own configuration rather than hidden in the code.

**Which address is matched:** the order's delivery address. For a `PICKUP_POINT` address the `settlement` field is matched, since a pickup point has no street address of its own — another reason `Address` was right to carry it.

**Settlement matching (addendum to §4, built in stage 3a).** Settlement **names** are *stored as entered* and normalized only when matching, on BOTH sides of the comparison (each zone name and the destination's settlement), by a `SettlementNameNormalizer` (`EasyCo\Shipping\Contracts`, `normalize(string): string`). **Postcodes** are normalized once, when the zone is built, and the destination's postcode is put through the same function (`PostcodeNormalizer`): trim, remove ALL whitespace (ASCII, NBSP and every other Unicode separator), uppercase — "sw1a 1aa" and " SW1A1AA " both become "SW1A1AA".

*The neutral rules* (`NeutralSettlementNameNormalizer`, used for every locale without rules of its own), in this order:
1. Unicode NFC, so a decomposed "й" (и + U+0306) equals the composed "й";
2. case **folding** (`MB_CASE_FOLD`), not just lower-casing;
3. every run of whitespace — including NBSP and other Unicode separators — becomes ONE space, and the ends are trimmed;
4. the dash variants U+2010 ‐, U+2011 ‑, U+2013 – and U+2014 — become "-".

Nothing else: **no prefix stripping and no transliteration** — "Sofia" and "София" are different names, and a merchant lists each spelling he wants. Malformed UTF-8 normalizes to the empty string, which matches nothing (the destination is customer-supplied, so this is a refusal, never an exception).

*The Bulgarian rules* (`BulgarianSettlementNameNormalizer`): the neutral steps, plus stripping **one** leading settlement-type prefix: "гр." and "с." (with or without a space after the dot), and "гр ", "град ", "с ", "село " (these four need the following space, so "Градец", "Сопот" and "Селце" are untouched), case-insensitively. So "гр. София", "ГР. СОФИЯ", "град София" and "София" are the same name. **Deliberately NOT stripped:** "кв." (квартал), "ж.к." (жилищен комплекс) and every other prefix — they name a district inside a town, not a settlement, and stripping them would equate "кв. Лозенец" with the town "Лозенец".

**The comparison is equality after normalization — never a substring, prefix or fuzzy match.** A blank settlement or postcode matches nothing. A pickup point has no postcode: any postcode given with a pickup point is ignored.

**The matcher** (`EasyCo\Shipping\Matching\ZoneMatcher`, pure: no framework, no Hook, no Address/Order/Cart) takes the zones, a `ZoneDestination` value (`countryCode`, `settlement`, `postcode`, `isPickupPoint`) and the normalizer. It orders the zones by `sortOrder` ascending then `id` ascending (numerically), applies the rule above, and returns a `ZoneMatchResult`: the first matching zone, or the explicit refusal `no_zone_for_destination` — never null and never an empty list.

**Choosing the normalizer (app layer).** `App\Services\SettlementNormalizerResolver` picks it by the **store locale** (`StoreLocale::current()`, not `App::getLocale()`, which the `api` group never sets). It looks the locale up in the container under the name **`shipping.settlement_normalizer.<locale>`** — the whole locale first (`pt_br`), then its language (`pt`); `bg`, `bg_BG` and `bg-BG` all reach the Bulgarian one. A locale with no binding, and an unknown or empty locale, gets **`shipping.settlement_normalizer.neutral`** (owner decision D3: never a refusal). **There is no list of locales in core:** the package registers only `neutral` and `bg`, and **an extension package adds a locale by binding its own implementation of the contract under that name** (`$this->app->bind('shipping.settlement_normalizer.ro', RomanianSettlementNameNormalizer::class)`) — nothing in core is edited.

---

## 5. `ShippingMethod` — what the customer actually picks

```
ShippingMethod                                 (package EasyCo\Shipping)
├── id
├── zoneId                the zone this method is offered in
├── name                  what the customer sees: "Еконт до офис", "Доставка до адрес"
├── kind                  FLAT | FREE | PER_CLASS | CARRIER
├── sortOrder             the order they are listed in; the first is preselected
├── isActive
├── amountMinor           FLAT: the price. PER_CLASS: the price when an item has
│                         no class, or its class has no rate here
├── classRates            PER_CLASS: an amount per ShippingClass code
├── freeAboveMinor        nullable — a free-shipping threshold (§5.1)
├── carrierCode           CARRIER only: which provider to ask (§6)
└── requiresPickupPoint   whether choosing this method means choosing an office
```

**The four kinds, and why only four:**

- **FLAT** — one price for the zone. The overwhelmingly common case.
- **FREE** — zero. Kept as its own kind rather than "FLAT with 0" so a merchant reading his configuration sees intent, and so the checkout can label it plainly.
- **PER_CLASS** — the rate depends on what is in the cart (§3.1's most-expensive rule). This is what makes "free if it is in this class, 5 € if it is in that one" real.
- **CARRIER** — this method's price is not configured here at all; it is asked for, live, through `ShippingRateProvider` (§6). No implementation in V1, so no merchant can select it yet — the kind exists so the door is part of the model rather than bolted on later.

**Weight-based rates are deliberately NOT a kind in V1** — domain-owner decision, "later". The data is already there (`weightGrams` on every variation), so adding a `WEIGHT` kind later is one enum case, one rate table and one branch in the calculator. It is deferred, not designed out: §10 records it.

### 5.1 The free-shipping threshold is measured AFTER the discount

Domain-owner decision, recorded explicitly because the opposite reading is equally defensible and someone will raise it:

> **`freeAboveMinor` is compared against the order's `subtotal - discount`, i.e. what the customer actually pays for goods — not against the pre-discount subtotal.**

So a 100 лв. cart with a 20% code and a 90 лв. threshold does **not** get free shipping: the customer is paying 80 лв. The merchant's exposure follows the money he actually receives, which is the honest basis for a giveaway.

The customer must be able to see why, so the storefront shows how much more is needed, computed on the same basis. A threshold that silently moves when a code is applied is worse than no threshold.

### 5.2 The rate calculator: the exact rules (built in stage 3b)

`EasyCo\Shipping\Rating\ShippingRateCalculator` is pure: its input is a `RateRequest` — `currency`, `goodsAfterDiscountMinor` (the shared `CartPricing` figure, §5.1) and a list of `RateLine {shippingClass: ?string, quantity: int}`; an empty list of lines is refused — and its output is, per method, a `MethodRate`: either a price in minor units or "needs a carrier quote". It is given the matched zone's methods and returns the **active** ones only, ordered by `sortOrder` ascending, then `id` ascending **numerically** (2 before 10).

All of it is **per order, never per unit**:

- **FREE:** 0, whatever the cart.
- **FLAT:** `amountMinor`.
- **PER_CLASS:** every line takes the rate of its class on this method. A line with **no class** (null or blank), or whose class **has no rate on this method**, or whose class code **Shipping does not know** (`Variation.shippingClass` is still free text, and codes are compared exactly, never case-folded), takes the method's `amountMinor` — the **fallback**. The order is charged the **single highest** of those per-line rates (§3.1), **never their sum**, and **quantities do not multiply it**: classes rated 3, 7 and 5 charge 7, not 15. A rate of 0 is valid; all lines at 0 charge 0.
- **Free-shipping threshold (FLAT and PER_CLASS):** when `freeAboveMinor` is set and `goodsAfterDiscountMinor >= freeAboveMinor`, the charge is 0. **It is `>=`: an order exactly at the threshold is free** (7999 against 8000 is charged; 8000 and 8001 are free).
- **CARRIER:** "needs a carrier quote" — never priced locally, not even 0. How `freeAboveMinor` combines with a live quote is still open (queue note) and is deliberately **not** applied.

The value objects are named `RateRequest`, `RateLine` and `MethodRate` so they do not collide with the §6 provider types (`ShippingQuote`, `ShippingContext`, …), which arrive in stage 3c.

---

## 6. The doors: three contracts, zero implementations

All three live in `EasyCo\Shipping\Contracts`, are resolved by code through named container bindings, and **never fall back to a default provider** — the exact posture `PaymentMethodAdapterResolver` already takes, and for the same reason.

```php
interface ShippingRateProvider
{
    /** @return ShippingQuote[] — empty means this carrier will not carry it */
    public function quote(ShippingContext $context): array;
}

interface PickupPointProvider
{
    /** @return PickupPoint[] — offices and lockers for a settlement */
    public function pickupPointsIn(string $countryCode, string $settlement): array;
}

interface ShipmentLabelProvider
{
    public function createLabel(ShipmentRequest $request): ShipmentLabel;
}
```

`ShippingContext` carries what any carrier needs and nothing a carrier should not see: the delivery address shape (country, settlement, whether it is a pickup point), the total weight in grams, the summed dimensions, the goods value for insurance and cash-on-delivery, and the currency. It carries **no customer identity and no cost prices.**

**A deliberate difference from `PaymentMethodAdapter`, worth stating because it is the one structural lesson from Shopify:** a payment is charged once, at the end, for a known amount. A shipping rate is needed **before the order exists**, several times, while the customer is still deciding. So `quote()` must be cheap, cacheable and failure-tolerant: a provider that times out must not take the checkout down with it. Shopify's own answer is caching identical requests and falling back to backup rates on a timeout; this design requires the same of whoever implements the first provider, and `checkout-orchestration-performance-note.md` §2's "never hold a DB transaction open across an external call" applies to `quote()` with full force.

**`PickupPointProvider` has nothing to change in `Address`** — it only *lists* what `Address` already knows how to store. That is why it is a small contract rather than a domain.

**`ShipmentLabelProvider` is the furthest away** and is named here only so the eventual Econt/Speedy work has a declared home. Nothing in V1 calls it.

---

## 7. `Order` finally carries shipping

```
Order
├── subtotalMinor
├── discountMinor
├── shippingMinor          NEW — what was charged for delivery, snapshotted
├── totalMinor             NOW subtotal - discount + shipping
├── shippingMethodName     NEW — snapshot of the method's name as shown
└── shippingMethodCode     NEW — nullable, which method was chosen
```

**This is the breaking part of the whole document and it has to happen before real orders exist.** `Order::create()` today computes `total = subtotal - discount` and deliberately refuses a separately supplied total, precisely so the two can never disagree. That refusal stays; the formula gains a term. Everything downstream that asserts the old formula — the checkout orchestrator, the order editor's re-planning, the payment reissue that compares a pending payment's amount against the order total, the admin panel's totals — must be found and updated together, not discovered one at a time.

**The method name is snapshotted, not referenced**, the same rule the address snapshot already follows: a merchant renaming "Еконт до офис" next year must not rewrite what last year's orders say they were charged for.

**An edit that changes the goods does not re-price shipping in V1.** The merchant adjusts it deliberately if he wants to — consistent with §3.1's "he edits the placed order", and with the existing rule that an edit never re-prices goods either. Automatic re-quoting on edit is deferred (§10).

### 7.1 Decisions taken for stage 2 (owner, final)

- **Shipping is an order-level amount only.** There is NO `SaleLine` for shipping: `SaleLineType::SHIPPING` exists in the enum and stays unused. The ledger therefore stays goods-only, and the placement reconciliation (`CheckoutOrchestrator::assertSaleLinesReconcileWithOrder`) keeps comparing the sum of the lines' `netPaidAmount` to `subtotal - discount` — **not** to the order total, which now also holds shipping.
- **`Order::reviseTotals` no longer accepts a total.** Its signature is `reviseTotals($subtotal, $discount, $appliedPromotionCode)`; it computes `total = subtotal - discount + (the order's stored shipping)` itself. This extends `Order::create()`'s existing rule — a total is never supplied by a caller — to the editing path, and it is how an edit carries shipping through unchanged. `OrderEditor` compares and reissues the pending payment against the order's resulting total (shipping included), while its goods reconciliation stays goods-only.
- **Name and code are nullable.** `shippingMethodName` and `shippingMethodCode` are null on an order with no shipping method (every order placed before this stage). Invariants: `shippingMinor` is never negative and is in the order's currency; a name, when given, is trimmed, non-empty and at most 255 characters; a code, when given, is trimmed, at most 64 characters and requires a name; `shippingMinor > 0` requires a name. The `Order` package does not import the Shipping package — name and code are plain strings.
- **Storage.** `orders.shipping_minor` (`bigInteger NOT NULL DEFAULT 0`), `shipping_method_name` (`string(255) NULL`), `shipping_method_code` (`string(64) NULL`). On MySQL/MariaDB the database also enforces `total_minor = subtotal_minor - discount_minor + shipping_minor` through a CHECK constraint (`orders_total_formula_check`), per CLAUDE.md rule 2.
- **Placement snapshots carry shipping in a LATER stage**, not stage 2. `order_placement_snapshots` is a write-once copy of the order at placement; adding the shipping fields to it belongs with the checkout integration (stage 4), which is the first writer of a non-zero value.

### 7.2 Refunds: the merchant decides every amount; EasyCo keeps the records, the caps and the facts

**Owner decision (final), replacing the earlier "refund policy behind one seam":** EasyCo hard-codes **no refund rule and no platform default**. Not "goods only", not "shipping on a cancel", not a return window, not a payment-method rule. Every refund amount is the merchant's decision, entered per refund. What EasyCo owns is the part that must never depend on a person's judgement: **correct records, hard caps, safe execution, and showing the facts the merchant's own policy depends on.** (The earlier version of this section, a table of the shop's own situations behind a "policy seam", is superseded. The shop's policy is the merchant's to apply by typing the amounts; it is not code.)

The points below that differ from the code as it stands are marked **(changes today's behaviour)**.

#### 7.2.1 The refund form, and what the ledger records

The cancel and return dialog collects one refund as these amounts:

| Field | Prefill | Editable |
|---|---|---|
| **Goods**, per line | the returned lines' net paid share, as today (`cumulative(r + k) − cumulative(r)` of `netPaidAmount`) | yes, per line — except on a pending payment (§7.2.4) |
| **Shipping refund** | 0 | yes |
| **Deduction** (optional) | 0 | yes — and a deduction **requires a free-text reason** |

`total = goods + shipping refund − deduction`. When the shipping refund is 0 the dialog shows a **neutral notice** stating the order's shipping amount (and how much of it has already been refunded); it never adds the shipping on its own and never suggests it. **(changes today's behaviour: the goods amount is no longer the only amount, and is no longer a value the caller cannot override.)**

**The ledger records what the merchant entered.** Each REFUND `SaleLine` stores the **merchant-ENTERED goods amount of that line** as its `amount` AND its `actualRefundAmount` (both, so a report summing either is true to the money; see §7.2.13), and the **computed share** as its `defaultRefundAmount` — the two facts `operational-sales-domain-design.md` §3.13 already defines for exactly this. **(changes today's behaviour: the lifecycle sets both to the computed share and exposes no override.)** The point is that product-level reports (what was refunded on this product, and for how much) stay true to the money that actually went back, not to a formula nobody applied.

**The deduction never touches a SaleLine.** It is a refund-level figure and lives **on the refund record**, with its reason. It is **retained revenue** — money the merchant keeps from the customer's payment — and is not a goods figure: it is not subtracted from any line, it appears in no product report, and the REFUND lines of the same return add up to the goods component only.

#### 7.2.2 Hard caps, cumulative across all refunds of the order

These are checked on every refund, against everything refunded before it. A refund counts toward a cap while it is **OWED, PAID_OUT, REQUESTED or COMPLETED**; a FAILED or CANCELLED refund (§7.2.11) moved no money and counts for nothing.

0. **Per line:** `goods refunded on this line so far + this line's goods refund ≤ that line's net paid amount`. A line can never be refunded more than the customer paid for it, however many partial returns it is spread over.
1. **Shipping:** `shipping refunded so far + this shipping refund ≤ the order's shipping`.
2. **Deduction:** `deduction ≤ goods + shipping refund (+ adjustment, §7.2.11)` of this refund — the refund total is never negative.
3. **Paid:** `total refunded so far + this total ≤ the amount actually paid` — the payment's received amount where one is recorded (§7.2.7), otherwise its expected amount. **(changes today's behaviour: the cap sums only COMPLETED refunds, so an in-flight refund is not counted; it must count every refund listed above.)**

A refund that breaks any cap is refused with a sentence naming the cap and the remaining room; nothing is written.

**What the refund record stores.** Every refund stores its **breakdown** as plain integers in minor units: goods (and, per line, the goods amount of each line — the rows the per-line cap sums, so that cancelling a refund frees exactly its own room), shipping, adjustment (§7.2.11), deduction with its reason, and the total, with `total = goods + shipping + adjustment − deduction`. On MySQL/MariaDB the database enforces it (CLAUDE.md rule 2): **CHECK constraints that every breakdown component is ≥ 0, that the total is ≥ 0, and that the total equals that sum.** The caps are aggregate rules across rows and cannot be a CHECK; they are enforced under the lock in §7.2.3.

#### 7.2.3 Concurrency, double submit, idempotency

- **One serialization point.** Every operation that moves money for an order already starts by locking the order row (`findByIdForUpdate`); the cap checks, the refund write, the void and the reissue all happen inside that one locked transaction, and nothing else may be added that checks a cap outside it. The payment-row lock that older text mentions is not what serializes today (the order lock is); the model keeps the order lock as the single point and states it.
- **Idempotency of the refund operation.** The dialog generates an **operation key** when it is opened and submits it with the form. The key is stored with a UNIQUE constraint — on the order's history row for the operation, so that an operation that writes **no** refund (a full cancel of a pending payment, §7.2.4) is covered too — and a second submission with the same key returns the first result and writes nothing. **(changes today's behaviour: there is no key. A cancel is protected only by the status guard, and a `recordReturn` submitted twice returns the units twice as long as enough remain.)**
- **Every state-changing action is idempotent.** "Mark as received", "mark paid out", "cancel an owed refund" and "mark remitted" (§7.2.7) are each a **no-op when repeated**: a second identical request changes nothing and writes **no second history entry**, whether it comes from a double click or a retry. Each is a conditional update inside the order-locked transaction (it applies only from the state it is defined for), not a read-then-write outside it.
- **No external call inside the transaction.** An offline refund calls nothing external. An online provider refund (§7.2.5) is never executed inside the database transaction: the transaction records it as REQUESTED and commits; the provider call happens after, and its result updates the record. **(today's `OrderRefunder` calls the adapter's `refund()` inside the open transaction; harmless for the two offline adapters, which call nothing, and not acceptable for an online one.)**

#### 7.2.4 Pending versus settled payments (this closes H1 and H2)

(H1 and H2 are the two defects found while preparing stage 2: H1, a cancelled order left with a new pending payment for its shipping amount; H2, a cancelled order whose paid shipping stays held without anyone deciding it.)

- **Pending payment: nothing is paid back, because nothing was paid.** A **full cancel or full return voids the pending payment completely and never issues a new one.** A **partial return** voids it and issues a new pending payment for the remainder: `pending amount − goods reduction − shipping reduction`. **The goods reduction is NOT editable on a pending payment: it is exactly the returned lines' share** (the dialog shows the per-line amounts read-only), because what the customer still owes for goods that were kept is not a negotiation on an unpaid order. **Only the shipping reduction is editable** (it may reduce what the customer still owes for delivery). A **deduction stays refused** on a pending payment (there is no money to deduct from). **(changes today's behaviour: today the remainder, shipping included, is reissued even on a full cancel, leaving a pending payment for the shipping amount on a CANCELLED order — H1.)**
- **Settled payment: a refund record is created** as §7.2.1–§7.2.2 describe, and the shipping that was paid is no longer silently left held, because the merchant now sees it and decides (H2).

#### 7.2.5 Refund states

One record, `PaymentRefund`, carries every kind, extended with the breakdown (§7.2.2), the operation key (§7.2.3), the payout channel (§7.2.8) and a state:

| Method | States |
|---|---|
| Offline (cash on delivery, bank transfer) | **OWED** — created by the cancel or return, dated; then **PAID_OUT** — the merchant confirms after paying in his bank app (or from the register): **date, reference, optional note**, and who. An OWED refund may instead be **CANCELLED** before payout (§7.2.11). |
| Online (a future provider) | **REQUESTED**, then **COMPLETED** or **FAILED**. Never executed inside a database transaction (§7.2.3). A FAILED refund frees its cap room. |

**(changes today's behaviour: an offline refund is recorded COMPLETED the moment it is created, which claims the money has been paid out when the system only knows it is owed.)** **Decided (owner, 2026-10-02): existing offline refund rows become PAID_OUT** with `paid_out_at` = their `created_at` and the note **"legacy: recorded before the owed/paid model"** (done by the migration that introduces the states, R1). Existing PENDING rows (online, none exist) become REQUESTED.

The order shows, from its refund records, four figures: **paid in**, **refunded and paid out**, **refunded but still owed**, and **still refundable** (paid in minus all counting refunds). The payout is recorded as a free-text reference; **no customer bank account number is stored in V1** (§7.2.10).

#### 7.2.6 Facts, not rules

The dialog and the order show the facts a shop's own policy depends on, and enforce none of them: the **delivery date** (the order's `delivered` history entry), the **date the customer announced the return** (entered by staff), the **date the goods came back** (entered by staff; the REFUND lines' `effectiveAt`, which today equals `recordedAt`), and the **days elapsed** between them. **Decided (owner, 2026-10-02): the announced-return date lives on the return operation's history row** (the `returned` event the operation writes), not on the refund record, because a return that produces no refund still has one. EasyCo enforces **no deadline**. A **filter hook** may later *suggest* prefill values from a shop's own policy; **EasyCo ships none**, and a suggestion is only ever a prefill the merchant can overwrite (the hook is app-layer, never called from a domain package — CLAUDE.md rule 10 — and gets its row in the Hook Reference when it is built). Money that is waiting on the merchant is listed under "Needs attention" (§7.2.7), which also enforces nothing.

#### 7.2.7 Payment verification, remittance, and "needs attention"

- **Bank transfer, "mark as received":** today it writes only `confirmed_at`. It stores, instead, the **received amount, the date and the bank reference** (and the staff member). If the received amount **differs from the expected amount**, the payment is **not settled silently**: the facts are recorded and the order is flagged **"needs attention"** until a person resolves it (accept the received amount, with a reason, which settles it for exactly that amount; or leave it unsettled). Refund caps (§7.2.2) then use the received amount.
- **Cash on delivery** has two facts that today are one. **Collected by the courier** — the cash was taken at the door — and **remitted to the merchant by the courier** — the courier's payout reaches the merchant's bank, usually days later. **Today "settled" for cash on delivery means neither exactly:** `deliver()` confirms any single eligible pending payment in the same transaction (`confirmDeliveryPaymentIfEligible`), so "settled" means "the order was marked delivered", i.e. collected, assumed. **Target:** keep that as **collected** (it is what unlocks refunds and reports), and add **remitted** as its own recorded fact.
- **Remittance is recorded per order as four facts:** the **remitted amount**, the **courier fee** (couriers usually remit in batches and may deduct a COD fee), the **date**, and the **bank reference**. A **batch action** marks **many orders remitted in one go with one shared reference**, each order keeping its own amount and fee. **The mismatch test is `collected − fee ≠ remitted`** — not `collected ≠ remitted`, which would flag every order that carries a fee. A mismatch is a "needs attention" item, not a block.
- **"Needs attention" lists facts and enforces nothing.** It shows, each with its **age in days** and **no threshold**: **OWED refunds** (days since created, oldest first), **payments with a received-amount mismatch**, **COD orders collected but not yet remitted** (days since collected), and **remittance mismatches**. Nothing is overdue by rule; the merchant reads the age and decides.

#### 7.2.8 Permissions and audit

- **The permission follows the PAYOUT CHANNEL. Decided (owner, 2026-10-02).** The merchant chooses the channel **when the refund is recorded** — **cash from the register** or **bank** — and that choice decides the permission for **both recording the refund and marking it paid out**: cash → `REFUND_CASH`, bank → `REFUND_BANK` (Administrator-only, per `staff-access-domain-design.md` §3.1). This replaces today's rule, which derives the permission from the *payment* method (`REFUND_CASH` for cash on delivery, `REFUND_BANK` for everything else) and lets a Manager record the refund of a cash-on-delivery order that will in fact be paid by bank transfer. A refund on a pending payment (§7.2.4) moves no money and needs only `ORDER_MANAGE`. **(changes today's behaviour.)**
- **Everything else** needs `ORDER_MANAGE`: cancelling an owed refund needs the same permission as recording it (the channel's), so that whoever may create it may withdraw it and nobody can withdraw one they could not have created.
- **Audit.** Every refund and every state change goes into the order's history with its breakdown and the staff member: the existing `refunded` event gains a reference to the refund record (today it carries only the return's transaction id and no amount), and new history entries are added for "refund paid out", "refund cancelled" (with its reason), "payment received (amount, reference)", "remittance recorded" and "needs attention".

#### 7.2.9 Return shipping is unchanged

Who bears return shipping is an **informational store setting** shown to the customer before ordering. No return-shipping money moves through the system.

#### 7.2.10 Privacy

The customer's bank account number is **not stored** in V1. The payout reference is free text the merchant types (for example his own bank reference).

#### 7.2.11 Corrections

Built in R2 (cancelling an owed refund) and R3 (the money-only refund).

- **An OWED refund may be CANCELLED before payout.** The refund moves to **CANCELLED**, which **frees its cap room** (it no longer counts in §7.2.2, per line included), and writes **one history entry carrying the staff member and a mandatory reason**. A **PAID_OUT refund is never cancelled** — money that left cannot be un-recorded; a mistake after payout is corrected by a new refund or recorded outside the system. Cancelling a refund cancels the *money* only: the goods stay returned, stock stays restocked, and the REFUND `SaleLine`s are never edited or deleted (CLAUDE.md rule 4). Reports exclude a cancelled refund's goods amounts by a **compensating (storno) ledger line** appended in the same locked transaction as the cancellation (decided, §7.2.13, option (b)); a `SaleLine` is never rewritten.
- **PAID_OUT confirms exactly the owed total.** Marking a refund paid out records that **the owed total** was paid; **a different amount is not a payout but a correction**: cancel the owed refund and record a new one for the amount actually to be paid.
- **A money-only refund ("refund without return").** A refund with **goods 0**: a **shipping refund** and/or an **adjustment amount**, with a **mandatory free-text reason**. It runs under **the same caps** (§7.2.2, shipping and total; there is no goods line, so no per-line cap applies), **the same states** (§7.2.5), **the same idempotency** (§7.2.3) and the same permission by channel (§7.2.8). It covers **goodwill and corrections** and **touches no stock and no `SaleLine`**; the adjustment is a refund-level component stored on the refund record like the deduction, never a goods figure. (An adjustment is available only on a money-only refund; a return's refund uses the goods lines.)

#### 7.2.12 Staged implementation plan

Each stage ends in a review gate. **R1 must land before shipping stage 4** (checkout), so no non-zero-shipping order can exist without the pending-payment rules and the caps; R2–R4 follow shipping stage 3d; R5 comes with the first online payment method.

**R1 — The refund record, the caps, the lock, the idempotency, the pending rules, the state set.** `PaymentRefund` gains the breakdown (goods, per-line goods rows, shipping, adjustment, deduction and reason, total), the operation key (UNIQUE), the payout channel and the state set (OWED, PAID_OUT, CANCELLED, REQUESTED, COMPLETED, FAILED); the CHECKs of §7.2.2; the migration that maps existing rows (§7.2.5); cap checks under the order lock counting every counting refund, including the per-line cap; the REFUND lines record the entered amount; the pending-payment rules, including the read-only goods reduction. No dialog change beyond passing the key and the prefilled amounts.
Tests: a **cumulative cap across two partial returns** (shipping and total); a **per-line cap** (two partial returns of one line can never exceed its net paid amount); the **ledger records the entered amount** (the REFUND line's `actualRefundAmount` is the entered amount and `defaultRefundAmount` the computed share, and the deduction appears on no line); a **double submit creates one refund** (and one return); **concurrent refunds cannot exceed the paid amount** (two transactions racing the same order); a **full cancel on a pending payment never reissues**; a **deduction on a pending payment is refused**; the **goods reduction on a pending payment is not editable** (a submitted goods amount other than the returned lines' share is refused) while the shipping reduction is; a partial return on a pending payment reissues exactly `pending − goods − shipping`; a deduction larger than goods + shipping is refused; a FAILED refund frees its cap room; the CHECKs reject a negative component and an inconsistent total; the mapping migration turns an existing offline refund into PAID_OUT with the legacy note.

**R2 — The owed/paid-out flow and corrections to an owed refund.** **(R2 must also split the `order.refunded` hook.** Today it fires for a refund that is only OWED — `OrderStatusChanger` fires it with the OWED `PaymentRefund` whenever a DELIVERED order's full return reaches REFUNDED — so an extension that tells a customer "your money was returned" would say so before it was. R2 replaces it with two hooks, **"refund recorded"** (a refund exists and is owed) and **"refund paid out"** (the money left), and gives both rows in the Hook Reference in the same commit. R1b changed nothing there.**)** OWED → PAID_OUT with date, reference, note and actor, for exactly the owed total; CANCELLED with a mandatory reason; permission by payout channel; the order's four figures; the history entries; OWED refunds in "needs attention" by age.
Tests: **cancelling an OWED refund frees the cap** (a following refund up to the freed amount succeeds, per line too) **and a PAID_OUT one cannot be cancelled**; marking paid out is refused for any amount but the owed total; **marking paid out twice, and cancelling twice, are no-ops that write no second history entry**; a Manager cannot record or pay out a bank-channel refund and can a cash-channel one; the four figures add up after two refunds, one paid; every state change writes one history entry with the staff member; the OWED list is ordered by age and enforces nothing.

**R3 — The dialog, and the money-only refund.** Shipping refund, deduction plus reason, the neutral notice, the facts (§7.2.6), the operation key and the payout channel in the form; the money-only refund (§7.2.11).
Tests: the notice shows the order's shipping and refunded-so-far and never prefills it; a deduction without a reason is refused; the form's key makes a double click one refund; the facts render and nothing is enforced after any number of days; a **money-only refund respects the shipping and total caps, requires its reason, writes no REFUND `SaleLine` and changes no stock**, and repeated with the same key creates one refund.

**R4 — Payment verification.** Received amount, date and reference for a bank transfer; the mismatch flag and its resolution; cash on delivery collected versus remitted, per order (amount, fee, date, reference) and as a batch with one shared reference; the "needs attention" lists of §7.2.7.
Tests: **a mismatch on mark-as-received flags the order and does not settle it**; accepting the received amount settles for exactly that amount and the cap follows it; **a batch remittance where every order carries a fee produces no false mismatch** (`collected − fee = remitted`) and one with a real difference flags only that order; a remittance recorded later does not change "collected"; **marking received and marking remitted twice are no-ops with no second history entry**; the collected-not-remitted list orders by days since collected.

**R5 — Online provider refunds (later, with the first online method).** REQUESTED/COMPLETED/FAILED outside the transaction, reconciliation of REQUESTED refunds. Designed here, built with the provider.

**Decided by the owner (2026-10-02)** — recorded above, no longer open: the existing offline refund rows become PAID_OUT with the legacy note (§7.2.5); the permission follows the payout channel chosen when the refund is recorded (§7.2.8); the announced-return date lives on the return operation's history row (§7.2.6).

#### 7.2.13 Decisions taken for R1a (owner, final)

1. **The ledger is the source of truth for money refunded per product line (option (b)).** A cancelled OWED refund will append a **storno `SaleLine`** (built in R2, not R1a); a corrected refund appends a new money line, never rewrites one. Reasons, from the code: `OperationalSales` may not read `payment_refunds` (it depends on Pricing only, CLAUDE.md rule 9), so a ledger report cannot join refund rows; POS register refunds are immediate cash with no refund record, so the ledger is the only record both channels share; and CLAUDE.md rule 4 already says a correction is a new row. The refund record's per-line rows (`payment_refund_lines`) are **operational state**: they drive the caps and the cancellation. R2 adds a test that, per line, non-cancelled refund rows equal REFUND minus storno lines.
2. **A REFUND `SaleLine`'s `amount` AND `actualRefundAmount` hold the merchant-ENTERED amount; `defaultRefundAmount` keeps the computed share.** A REFUND line may carry **0** (the goods came back, no money is paid for that line). `SaleLine::createRefund()` takes the entered amount as an optional argument (non-negative, in the origin's currency). The computed share keeps its own stricter positive-only guard, unchanged.
3. **A refund whose total would be 0 creates no `PaymentRefund`** (the domain's `amount > 0` stays). The goods still move: the REFUND lines are written, stock is restocked, a `returned` event is recorded, and no refund event is.
4. **Idempotency (built in R1b):** a repeat with the same operation key and the **same payload** returns the first result; the same key with a **different payload** is refused with a clear, translated error. A payload hash is stored next to the key.
5. **The money permission follows the payout channel and is enforced in the service layer as well as in the UI** (built in R1b). Until then it is the existing UI-only check, now reading the stored channel.
6. **Concurrency testing (R1b):** the deterministic two-connection lock test is required; a subprocess race test is optional and only if it is fully deterministic.

**What R1a built.** `payment_refunds` carries the order link, the payout channel, the breakdown (`goods_minor`, `shipping_minor`, `adjustment_minor`, `deduction_minor` + its reason; `amount_minor` stays the total) and the paid-out facts; `payment_refund_lines` holds the per-line goods rows (real FKs to the refund and to the original SALE line, UNIQUE per pair). The states are OWED, PAID_OUT, CANCELLED, REQUESTED, COMPLETED, FAILED. CHECKs (MySQL/MariaDB only): every part ≥ 0, total ≥ 0 and = goods + shipping + adjustment − deduction, a deduction requires its reason, a line amount ≥ 0. Existing offline refunds were mapped to PAID_OUT with the note "legacy: recorded before the owed/paid model" (a gate refuses a refund with no payment, or a COMPLETED one on a non-offline method, rather than guess). Both offline adapters now return OWED; `PaymentMethodAdapter::isOffline()` exists and the refund path refuses any adapter that is not offline, by name — an online refund never runs inside the order-locked transaction (R5). The payment cap counts every refund whose money is spoken for (OWED, PAID_OUT, REQUESTED, COMPLETED). The order history shows an OWED refund as **"refund owed"** (`refund_owed`), not "refunded". `OrderStatusChanger::cancel()` / `recordReturn()` take an optional `RefundRequest` whose defaults are exactly today's dialog (computed shares, shipping 0, deduction 0, channel derived: cash on delivery → cash, otherwise bank); non-default values are reachable only from tests until R1b adds the caps and R3 the dialog.

#### 7.2.14 What R1b built, and the decisions behind it (owner, final)

- **The caps are live** (`RefundCapGuard`, called from `OrderRefunder`, inside the order-locked transaction and before any refund write): per original sale line, shipping, total against the settled payment, and the deduction against the goods + shipping of the same refund. CANCELLED and FAILED refunds free their room, per line included. A broken cap throws `RefundCapExceededException`, a translated sentence naming the cap and the room left (`orders.refund_caps.*`, en + bg); the whole transaction — goods lines and restock included — rolls back, and the admin action shows it as a refusal notice, never a 500.
- **Legacy refunds get their per-line rows before the per-line cap goes live** (migration `2026_10_06_000002_backfill_payment_refund_lines`). A refund written before R1a has no `payment_refund_lines`, so the cap would have believed its line had never been refunded. The rows are rebuilt from the REFUND sale lines of the return that produced the refund, through the link that really exists: the `returned` and `refunded` events of the return carry the return's `transaction_id`, and a refund carries only a payment id (which gives its order). So, per order, its goods-bearing refunds without lines are paired in id order with its `refunded` events; the pairing is accepted only if it is unambiguous (equal counts) and each pair's REFUND lines add up to the refund's goods. **It is a gate:** otherwise it writes nothing and aborts naming the refund ids. A refund with goods 0 needs no lines.
- **A fully discounted line is returnable.** `defaultRefundAmount` (the computed share) must be positive, except that exactly 0 is accepted when the line has no net paid left to give back (`net paid − cumulative(already returned)` is 0: a gift, a 100% discount). The goods move and restock; no money does, and if every line of the return is free no refund exists (decision 3). A negative share is always refused, and a zero share while net paid remains is still refused (it would silently drop money).
- **Idempotency** (`order_events.operation_key`, `operation_payload_hash`, `payment_refund_id`; UNIQUE (`order_id`, `operation_key`), CHECK key and hash together). The key is on the **first** event the operation writes. Under the order lock, **before** the status guard (a finished cancel has moved the order to a status that guard would refuse), a repeat of the same key with the same hash returns the first result (`OrderReturnResult::wasReplay()`, with the first refund) and writes nothing and fires no hook; the same key with a **different** hash throws `OperationKeyReusedException`, translated (en, bg). **The hash (sha256) covers everything the merchant decided:** the action (cancel or return), the lines with quantities and restock choice, a cancel's restock overrides, the entered goods amounts, the shipping refund, the deduction and its reason, the channel, and the operation's free-text reason; every map is sorted first, so the order a form submitted things in is irrelevant. The unique index is only the backstop (a duplicate-key error re-runs the call once and replays). The cancel/return dialog carries one hidden key generated when it opens.
- **The money permission is enforced in the service.** Recording a refund on a **settled** payment requires the permission of its payout channel — `REFUND_CASH` for cash, `REFUND_BANK` for bank — checked in `OrderRefunder` before the caps, for the acting panel staff member (`PanelStaffActor` + `AuthenticatedStaffResolver`, the pair the UI check already used). **No acting staff member is a refusal** (fail closed): an unattended path must not record a payout the panel would show to nobody. A refusal is `RefundPermissionDeniedException`, translated, shown as a notice. The panel's visibility clause and the service both read `RefundPermissionPolicy::permissionFor()` — one derivation, so they cannot disagree. A refund on a pending payment moves no money and needs only `ORDER_MANAGE`, which is still checked by the panel only.

---

## 8. Promotions do not touch shipping. At all.

Domain-owner decision, in his own words: a promotion code is a discount **on products only**.

So: no promotion type discounts shipping, no "free shipping" promotion exists, and `discountMinor` never includes a shipping component. `promotions-domain-design.md` needs no change — this section exists so that nobody later adds a `FREE_SHIPPING` discount type believing it was merely an oversight.

The interaction that *does* exist is §5.1's: a promotion changes the basis the free-shipping threshold is measured against. That is a threshold rule, not a discount on shipping, and the distinction matters — the customer's shipping line either is free or costs its full price; it is never partially discounted.

---

## 9. Where this sits in checkout

Rate resolution happens while the customer is choosing, before anything is written:

1. The delivery address is known (typed or chosen) → match a `ShippingZone` (§4).
2. List that zone's active methods → compute each one's amount (§5), FLAT and FREE and PER_CLASS locally, CARRIER through a provider (§6).
3. The customer picks one. A method with `requiresPickupPoint` also demands an office, which `Address` already stores.
4. At placement, `CheckoutOrchestrator` snapshots the chosen method's name, code and amount onto the `Order` (§7), inside Phase 1's transaction, with the amount **recomputed server-side** and never taken from the client — the same rule the line prices already follow, for the same reason: the customer must pay what he was shown, and the server must be the one that decides what that is.

If the recomputed amount differs from what the customer was shown, placement **refuses** with a clear message rather than charging the new figure — exactly the posture already taken for a promotion code that went invalid between the cart and the checkout. The customer must never press "Pay" seeing one total and be charged another.

---

## 10. Explicitly out of scope for V1 (deferred, not forgotten)

- **Every carrier integration** — Econt, Speedy, BoxNow. The whole point of §6 is that these are additions.
- **Weight-based rates** — domain-owner decision, "later". `weightGrams` already exists; a `WEIGHT` kind is one enum case and one rate table when it is wanted.
- **Volumetric / dimensional pricing** — same, and it needs the dimensions the UI does not yet collect.
- **The product/variation shipping-fields UI** — `product-shipping-fields-note.md` already maps where they go in both forms; that note stays the guide.
- **Multi-parcel splitting** — one order, one shipping charge (§3.1).
- **Re-quoting shipping when an order is edited** (§7).
- **Shipping tax** — no tax domain exists; `Price` carries a tax rate but nothing applies it to shipping.
- **Local pickup from the shop itself** — expressible today as a `FREE` method, so no separate kind is built for it.
- **Per-location / multi-warehouse origin** — Shopify's delivery profiles exist for this; a single-shop V1 has no use for it, and nothing here precludes it.

---

## 11. Testing plan

- `ShippingClass` / `ShippingZone` / `ShippingMethod` domain unit tests: construction and validation; a `PER_CLASS` method with no rate for a class falls back to `amountMinor`; a `FLAT` method rejects a negative amount; a method with `freeAboveMinor` set and `kind: FREE` is rejected as contradictory.
- **Zone matching, as a real matrix**: an address matching two zones takes the one with the lower `sortOrder`; an address matching none is refused, with the refusal asserted rather than an empty list; a `PICKUP_POINT` address matches on `settlement`.
- **§3.1's most-expensive rule, which is the single most important test in this document**: a cart with three classes charging 3, 7 and 5 is charged **7**, asserted explicitly as *not* 15. Name the test after the rule so a future "fix" to summing fails loudly.
- **§5.1's threshold basis**: a cart whose pre-discount subtotal clears the threshold but whose post-discount total does not is charged for shipping — asserted as the specific number, not merely "shipping is non-zero". And the reverse case. This is the rule most likely to be implemented against the wrong figure.
- **§7's formula**: `Order::create()` computes `subtotal - discount + shipping` and still refuses a supplied total; an order with zero shipping produces exactly the old number, so existing expectations hold.
- **§9's recompute-and-refuse**: a method whose amount changes between quoting and placement refuses the order and writes nothing — the same shape as the existing promotion-no-longer-valid test, which it should mirror.
- **§8**: a promotion never reduces `shippingMinor`, asserted directly.
- Real MySQL Feature tests for each repository; a `SHOW CREATE TABLE` confirmation of the new `orders.shipping_minor` column and of the zone/method foreign keys.
- `ShippingRateProvider` is **not** tested against a real carrier — there is none. A fake provider in the test suite proves the resolver refuses an unknown carrier code and never falls back to a default, mirroring `PaymentMethodAdapterResolver`'s own tests.
