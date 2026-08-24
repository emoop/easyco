# Pricing Domain Design

**Status:** Draft v2 — for Chief Architect review
**Builds on:** `easyco/pricing` (Currency, Money, Price — already implemented and tested)
**Supersedes:** RFC-01 (Products & Pricing research), Draft v1 of this document

**Changes since v1:** `PriceResolver` now returns a `PriceQuote` (regular + final price) instead of a bare `Price`, so storefronts can render "was X, now Y" without a second call. Added a separate `CostPriceProvider` contract for internal cost/margin use (POS, analytics) — deliberately isolated from the customer-facing resolver so cost data can never leak through a storefront API. Clarified that Pricing stores no history of what it resolved — snapshotting a resolved price is the *consuming* domain's responsibility (see §7).

---

## 1. Scope

This document defines the **Pricing domain**: how EasyCo decides "what does this cost, for this customer, right now" — and hands other domains a price they can trust, without those domains knowing how the decision was made.

**Explicitly out of scope for this domain** (per the domain list in the foundational architecture doc):
- **Tax rate resolution** (which rate applies, by jurisdiction) — belongs to the future `Tax` domain. Pricing only *consumes* a resolved rate through a contract; it never decides jurisdiction logic itself.
- **Promotions/coupons/campaigns** — belongs to the future `Promotions` domain. Pricing resolves the *catalog* price; Promotions applies cart-level adjustments on top, as a separate step Cart/Checkout orchestrate.
- **Currency conversion** — inherited from `Money`'s existing boundary; a future service, not part of Pricing.
- **Product identity/attributes** — owned by `Catalog`. Pricing references a priceable item by ID only; it does not know what a product *is*.

---

## 2. Entities

### 2.1 Price List

A named, prioritized set of prices for a given currency, optionally scoped to a customer group and/or sales channel.

```
PriceList
├── id
├── code              (e.g. "retail-eur", "wholesale-eur")
├── currency          (ISO code)
├── customer_group_id (nullable — null = applies to all groups)
├── channel_id         (nullable — null = applies to all channels)
├── priority           (lower number = higher priority when multiple lists match)
└── is_default          (fallback list when nothing more specific matches)
```

### 2.2 Price List Item

One price entry: a priceable item, in one price list, optionally for a specific quantity tier and/or a scheduled date range.

```
PriceListItem
├── id
├── price_list_id
├── priceable_type / priceable_id   (polymorphic — points at a Catalog product/variant)
├── min_quantity          (default 1 — enables quantity-tier pricing)
├── amount_minor           (integer minor units — becomes a Money via the list's currency)
├── valid_from / valid_to  (nullable — scheduled prices)
```

### 2.3 Price Rule

A conditional, catalog-wide adjustment (e.g. "15% off Category X for the Wholesale group, this weekend").

```
PriceRule
├── id
├── name
├── priority
├── conditions   (JSON — e.g. {category_id, customer_group_id, channel_id})
├── action_type  (percentage | fixed_amount)
├── action_value
├── valid_from / valid_to
└── is_active
```

**Performance-critical decision:** rules are never evaluated live against `conditions` JSON on the request path. A background job precomputes matching outcomes into:

```
PriceRuleResult   (precomputed index — write-heavy job, read-heavy lookup)
├── priceable_id
├── customer_group_id
├── channel_id
├── currency
├── computed_amount_minor
├── rule_id
└── generated_at
```

Rebuilt incrementally on rule create/update/expire and on relevant product/category changes — event-driven, not a blind full rebuild (see §6 Caching).

### 2.4 Product Cost — separate, internal-only data

What the product costs EasyCo's merchant, for margin calculation. Deliberately **not** part of `PriceList`/`PriceListItem` — it carries no currency-list scoping, no customer group, no tax, and must never be reachable through any customer-facing endpoint.

```
ProductCost
├── priceable_id
├── currency
└── amount_minor
```

---

## 3. Price resolution algorithm

`PriceResolver::resolve(PriceContext $context): PriceQuote` runs these steps, in order:

1. **Select the Price List** — best match by currency + customer group + channel (most specific wins; fall back to the `is_default` list for that currency). No match at all → domain-level exception, not a silent zero price.
2. **Select the Price List Item** — highest `min_quantity` tier `<= requested quantity`, currently within its `valid_from`/`valid_to` window if scheduled. This is the **regular price**.
3. **Check for a better Price Rule result** — look up `PriceRuleResult` for this priceable/customer-group/channel/currency.
4. **Policy decision (flagging for Chief Architect confirmation):** when both a scheduled special price and a matching Price Rule apply, EasyCo takes the **lower of the two** for the customer ("best price wins") as the **final price**, rather than stacking. One-line change to flip to additive stacking later if not the intended policy.
5. **Wrap both regular and final amounts as `Price`** — via `TaxRateResolver` (a contract Pricing depends on, implemented by the future `Tax` domain) plus the store's tax-inclusive/exclusive convention.
6. **Return a `PriceQuote`** carrying both, so the caller can render "regular vs. sale" without a second lookup.

```php
interface TaxRateResolver
{
    public function resolveBasisPoints(TaxContext $context): int;
}
```

Pricing ships a `NullTaxRateResolver` (always `0`) as the default binding until the `Tax` domain exists, so Pricing is usable and testable standalone today.

---

## 4. Service contracts (what other domains are allowed to depend on)

### 4.1 Customer-facing: price resolution

```php
namespace EasyCo\Pricing\Contracts;

use EasyCo\Pricing\Price;

interface PriceResolver
{
    public function resolve(PriceContext $context): PriceQuote;
}

final class PriceContext
{
    public function __construct(
        public readonly string $priceableId,
        public readonly int $quantity,
        public readonly string $currency,
        public readonly ?string $customerGroupId = null,
        public readonly ?string $channelId = null,
        public readonly ?\DateTimeImmutable $at = null, // for scheduled-price evaluation; defaults to now
    ) {}
}

/**
 * Regular price = what the Price List says before any rule/special-price
 * adjustment. Final price = the resolved price the customer actually pays.
 * When they're equal, the product simply isn't discounted right now.
 */
final class PriceQuote
{
    public function __construct(
        public readonly Price $regular,
        public readonly Price $final,
    ) {}

    public function isDiscounted(): bool
    {
        return ! $this->final->gross()->equals($this->regular->gross());
    }
}
```

### 4.2 Internal-only: cost / margin

```php
namespace EasyCo\Pricing\Contracts;

use EasyCo\Pricing\Money;

/**
 * Deliberately separate from PriceResolver. Bind this ONLY in service
 * providers for internal-facing packages (POS, Admin, future Analytics).
 * Never expose this through a storefront/public API route — cost data
 * leaking to a customer's browser is a business-risk bug, not just a
 * technical one.
 */
interface CostPriceProvider
{
    public function costFor(string $priceableId, string $currency): Money;
}
```

This — plus `PriceResolver`/`PriceContext`/`PriceQuote`, `CostPriceProvider`, and the already-public `Money`/`Currency`/`Price` — is the **entire** public surface other domains may depend on. `PriceList`, `PriceListItem`, `PriceRule`, `PriceRuleResult`, `ProductCost`, and their repositories are internal; no other domain package may `use` them directly.

---

## 5. API contract (sketch)

**Public/storefront-safe** — regular + sale price only, never cost:

```
GET /api/pricing/resolve
    ?priceable_id=...&quantity=1&currency=EUR&customer_group_id=...&channel_id=...

200 OK
{
  "regular": { "net": "24.99", "gross": "29.99" },
  "final":   { "net": "16.66", "gross": "19.99" },
  "is_discounted": true,
  "tax": { "amount": "3.33", "rate_percent": 20.0 },
  "currency": "EUR"
}
```

**Internal-only** (POS/Admin, separate route group with its own auth guard — never mounted under the public API prefix):

```
GET /internal/pricing/cost?priceable_id=...&currency=EUR

200 OK
{ "cost": "9.50", "currency": "EUR" }
```

Keeping these as two distinct route groups (not one endpoint with a permission flag) means a misconfigured permission check can't accidentally expose cost data — the storefront route literally has no code path that can return it.

---

## 6. Caching strategy

- **`PriceRuleResult` is itself the cache** for rule outcomes.
- **Resolved `PriceQuote`s** are cached keyed by `(priceableId, customerGroupId, channelId, currency, quantity-tier-bucket)`, short TTL as a safety net, but primarily **invalidated by event**:
  - `PriceListItemUpdated`, `PriceRuleRecomputed`, `ProductCostUpdated`, `ProductRemoved` (dispatched by Catalog, listened to here) each invalidate only the affected keys.

---

## 7. Cross-domain contracts

- **Catalog → Pricing:** Catalog owns product identity; Pricing only ever receives a `priceable_id` string — no real foreign key into Catalog's tables.
- **Cart/Checkout/Orders → Pricing:** call `PriceResolver::resolve()` **once**, at the moment of adding to cart / placing an order, and **snapshot** the returned `PriceQuote`'s `final` `Money` values onto their own `CartLine`/`OrderLine` rows. **Pricing stores no record of this call itself** — it is a stateless resolver. The snapshot lives entirely in the consuming domain's own tables (e.g. `order_lines.unit_price`), so Orders never has to ask Pricing "what was the price back then" — it already has the answer, permanently, independent of later catalog changes.
- **POS → Pricing:** calls both `PriceResolver` (sale price) and `CostPriceProvider` (cost) to compute margin at the point of sale — POS is an internal package, so both bindings are available to it; the storefront package only ever gets `PriceResolver` bound.
- **Tax → Pricing:** Pricing depends on `TaxRateResolver`; Tax domain provides the binding once it exists.
- **Promotions → Pricing:** not a dependency in either direction. Promotions sits *above* Cart, adjusting cart totals after Pricing has already resolved each line's catalog price.

---

## 8. Testing plan

- **Already covered:** `Money`/`Currency`/`Price` unit tests (87 tests, existing).
- **New for this design:**
  - `PriceResolver` resolution-precedence tests (no match → exception; quantity-tier selection; scheduled-price windows; rule-vs-special "lower wins" policy).
  - `PriceQuote::isDiscounted()` — true/false cases.
  - `CostPriceProvider` tests, including confirming no route/contract path connects it to any storefront-facing service.
  - `PriceRuleResult` precompute-job tests.
  - Cache-invalidation tests: only the correct keys invalidate per event.

---

## 9. What is deliberately not implemented in this iteration

- The `Tax` domain itself — only its contract shape is defined here.
- The `Promotions` domain.
- Multi-warehouse/multi-supplier cost pricing (one `ProductCost` row per priceable/currency for now, not per-location).
- Currency conversion between price lists.

---

## 10. Open decision for Chief Architect confirmation

§3 step 4 — when a scheduled special price and a Price Rule both apply, EasyCo takes the lower ("best price for customer"). Confirm this is the intended policy before implementation.
