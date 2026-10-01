# Shipping Domain Design — zones, classes, methods, and the doors a carrier plugs into

**Status:** Draft v1 — approved scope, not yet implemented.
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
├── countryCodes          the countries this zone covers
└── settlementPatterns    nullable — optional narrowing within those countries
                          (a settlement name or postcode list), so "София-град"
                          can sit above "България"
```

**Matching rule, taken from WooCommerce because it is proven and a merchant can hold it in his head:** zones are checked in `sortOrder`, the first one matching the delivery address wins, and **an order matches exactly one zone**. Narrow zones are placed above broad ones.

**An address matching no zone cannot be shipped**, and checkout refuses with a clear message rather than silently offering nothing or falling back to a free delivery. There is no implicit "rest of the world" zone: a merchant who wants one creates a zone with no narrowing and puts it last, which is the same thing but visible in his own configuration rather than hidden in the code.

**Which address is matched:** the order's delivery address. For a `PICKUP_POINT` address the `settlement` field is matched, since a pickup point has no street address of its own — another reason `Address` was right to carry it.

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
