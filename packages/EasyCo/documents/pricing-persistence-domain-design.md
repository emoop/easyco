# Pricing Persistence Domain Design

**Status:** v1.0 — approved model, not yet implemented.
**Builds on:** `pricing-domain-design.md` (Money, Price, Currency, DefaultCurrency, the existing `PriceResolver`/`PriceContext`/`PriceQuote` contract — this document finally implements a real resolver behind that contract, replacing `InMemoryPriceResolver`), `catalog-domain-design.md` (cross-domain-by-id reference to Product/Variation/Brand/Category/Tag/AttributeValue, never a package dependency), `operational-sales-domain-design.md` §7 (this is the fix for the largest gap that domain's persistence work exposed — every `SaleLine.amount` and `InstallmentPlan` balance so far has been computed against a single hardcoded price seed).
**Origin:** designed through a structured, example-by-example conversation covering wholesale pricing, POS brand-specific discounts, seasonal collection pricing, and simple WooCommerce-style regular/sale price entry — each real scenario resolved against the same underlying mechanism, one at a time, rather than four separate features.

---

## 1. Scope & the Catalog boundary (unchanged)

This document does not change the existing Catalog↔Pricing boundary. Catalog exposes `priceableId` (a Variation's own id); Pricing resolves everything price-related against that id and never writes anything back into Catalog. Cross-references to `catalog_brands`, `catalog_categories`, `catalog_tags`, `catalog_attribute_values` (§4.2) are plain ids at the database FK level — never a PHP package dependency, per CLAUDE.md rule 9, exactly as `OperationalSales`'s dependency on Pricing's `Money` was drawn (a value object reuse, not a domain-aggregate coupling) versus its explicit *non*-dependency on Catalog.

---

## 2. The core insight: one mechanism, not four features

The conversation that produced this design covered what looked like four separate pricing needs — wholesale price lists, POS brand-specific percentage discounts, seasonal/collection pricing, and simple regular/sale price entry (the only kind of pricing the domain owner had prior hands-on experience with, via WooCommerce). All four turned out to be the same underlying mechanism — a `PriceList` with a scope and a priority — configured differently:

| Real scenario | How it's expressed |
|---|---|
| Wholesale pricing, per-variation, quantity tiers | `FIXED_ITEMS` mode, `customer_group` scope |
| "All Guess products get 20% off at POS, unless already on sale" | `PERCENTAGE_OFF_REGULAR` mode, `BRAND` scope, priority resolves the "unless already on sale" part automatically |
| "Autumn/Winter 2025 collection is 30% off, time-boxed" | `PERCENTAGE_OFF_REGULAR` mode, `ATTRIBUTE_VALUE` scope, `valid_from`/`valid_until` |
| "This one product should always be a fixed price, all variations the same" | `FIXED_ITEMS` mode, `PRODUCT`-level item, no scope needed |
| Simple regular/sale price fields (WooCommerce-familiar UX) | Two reserved, always-existing system `PriceList`s (§4.5) — the merchant never needs to know price lists exist at all if they only ever use these two fields |

No merchant is required to understand "price lists" to use the simple case. The power-user case (brand/category/collection percentage rules, the exact kind of thing the domain owner currently pays a separate WooCommerce plugin for) is the *same* mechanism, not a bolt-on.

---

## 3. Core entities

```
PriceList
├── id
├── name
├── mode                    FIXED_ITEMS | PERCENTAGE_OFF_REGULAR
├── percentage              Decimal, only meaningful when mode = PERCENTAGE_OFF_REGULAR
├── priority                integer — higher wins on conflict (§4.6)
├── valid_from / valid_until  nullable — null means "no time limit"
├── status                  ACTIVE | INACTIVE — manual disable without deleting history
├── is_system               bool — true only for the two reserved lists (§4.5);
│                           merchant cannot delete or rename a system list
└── PriceListScope[]        zero or more — see §4.2. Zero scopes = applies universally.

PriceListScope
├── id, price_list_id
├── scope_type              BRAND | CATEGORY | TAG | ATTRIBUTE_VALUE | CUSTOMER_GROUP | CHANNEL | PRODUCT
└── scope_reference_id      plain string id into Catalog/OperationalSales — cross-domain
                            by id only, per §1

PriceListItem                only meaningful when mode = FIXED_ITEMS — a
                              PERCENTAGE_OFF_REGULAR list has none of these, its
                              price is computed dynamically (§4.6)
├── id, price_list_id
├── target_type             PRODUCT | VARIATION
├── target_id               product_id or variation's priceableId, per target_type
├── min_quantity            integer, default 1 — the quantity-tier threshold (§4.4)
└── price: Price            the actual fixed price for this target/quantity
```

---

## 4. Key design decisions

### 4.1 `PriceListScope` is polymorphic, not four hardcoded columns

Rejected outright: `PriceList.brand_id`, `.category_id`, `.season`, `.tag_id` as four separate nullable columns. Every future scope dimension (material, collection, whatever comes next) would demand a new column — the exact "hardcoded attribute columns" mistake `catalog-domain-design.md` §3.3 already rejected for product attributes, applied here to price-list conditions instead. `PriceListScope` is a child table instead: one row per condition, `scope_type` + `scope_reference_id`. "Season" needs no dedicated column or table at all — it's already a Catalog `AttributeValue` (a descriptive, non-axis attribute per `catalog-domain-design.md` §3.3), so `ATTRIBUTE_VALUE` scope covers it directly.

**Multiple scope rows on one `PriceList` = AND logic**, confirmed explicitly: a list scoped to both `BRAND:Guess` and `ATTRIBUTE_VALUE:Summer2026` only matches a product that is *both*.

### 4.2 `PRODUCT` scope: manually attaching a specific product to a list

The domain owner's own scenario: an admin, editing a product, picks a `PriceList` from a dropdown of already-defined percentage-based lists (e.g. "New Arrival -5%", "Autumn/Winter 2025 -30%") rather than typing a number. This is not a new mechanism — it's `PriceListScope(scope_type: PRODUCT, scope_reference_id: this product's id)`, created (or removed) by that dropdown interaction. Because scoping is by product id and each `PriceList` carries its own `valid_from`/`valid_until`, the domain owner's own stated safety property falls out for free: attaching a new product to an already-expired seasonal list produces zero effective price change — the scope match exists, but `valid_until` fails the resolution check (§4.6), so nothing breaks silently; the mistake is visible (the product simply doesn't get the discount) rather than actively harmful (it never risks becoming *cheaper* than intended by accident).

### 4.3 Two "levels" of `FIXED_ITEMS` entry, resolved together — "Option 2" from the bulk-edit conversation

Rejected: auto-materializing one `PriceListItem` row per existing variation when a merchant sets "one price for all variations." That silently fails to cover a variation added *later* (e.g. a new size added after the sale was configured) — structurally the same class of bug already found and fixed in the source POS system's installment-marker mechanism (`operational-sales-domain-design.md` §3.3): a convenience shortcut that quietly stops covering new data.

Instead: a `PriceListItem` may target a `PRODUCT` directly (covers every current *and future* variation of that product automatically) or a specific `VARIATION` (an explicit override). Within one `PriceList`, resolving a specific variation's price checks for a `VARIATION`-level item matching that exact `priceableId` first; if none exists, falls back to the `PRODUCT`-level item for that variation's product. This gives the domain owner's "one price for all, unless I need an exception" workflow exactly, without ever needing to touch variations that don't need a different price.

### 4.4 Quantity tiers: `FIXED_ITEMS` only, never `PERCENTAGE_OFF_REGULAR`

Confirmed explicitly. `min_quantity` on a `PriceListItem` is a simple **threshold model** (not marginal/bracket pricing) — the resolver picks the highest `min_quantity` that is still ≤ the requested quantity (e.g. `min_quantity: 1 → €22`, `min_quantity: 10 → €19`; ordering 7 units resolves to €22, ordering 12 resolves to €19). A `PERCENTAGE_OFF_REGULAR` list applies its flat percentage regardless of quantity — correct for the POS brand-discount button use case, which was never quantity-dependent in the source system either.

### 4.5 Two reserved, always-existing system `PriceList`s carry the simple UX

`is_system = true`, seeded once per store, never deletable/renamable by a merchant: **"Regular Prices"** (the canonical base price — see §4.6 for why `regular` in `PriceQuote` always resolves against this specific list, not merely "whatever has the lowest priority") and **"Manual Sale"** (a merchant-facing "sale price" field, active only while populated). A product's edit screen showing two plain fields — "Regular Price" and "Sale Price" — writes `PriceListItem`s into these two lists; a merchant who never touches the power-user `PriceList` feature never needs to know it exists. The "single price for all variations" checkbox (§4.3) determines whether that write is `PRODUCT`-level or `VARIATION`-level — the same mechanism, not a parallel one.

**Deferred, explicitly:** the exact UX of switching a product between "one price for all variations" and "per-variation pricing" after variation-level items already exist (does switching to "one price" hide, delete, or leave untouched the existing per-variation rows?) is an Admin UI decision, not a domain-model one — the domain supports both `PRODUCT`- and `VARIATION`-level items coexisting (§4.3) regardless of how any future UI toggles between showing them.

### 4.6 Conflict resolution: `PriceResolver::resolve()`, step by step

Given a `PriceContext` (priceableId, quantity, customerGroup, channel, currency, at-time):

1. Filter every `ACTIVE` `PriceList` whose scopes all match (AND, per §4.1) and whose `valid_from`/`valid_until` cover the context's time — zero scopes matches everything.
2. Among matches, the highest `priority` wins outright — no blending, no stacking two lists' effects together.
3. If the winning list's `mode = FIXED_ITEMS`: resolve the price per §4.3's item-level fallback, then §4.4's quantity-tier lookup within that item.
4. If `mode = PERCENTAGE_OFF_REGULAR`: compute `regular price × (1 − percentage)`, where "regular price" is *always* resolved fresh against the reserved "Regular Prices" system list (§4.5) specifically — never against whatever list happens to have the next-lower priority. This is what makes stacked/layered discounts (a seasonal collection discount on top of nothing else) behave predictably instead of compounding unpredictably against an arbitrary lower list.
5. `PriceQuote.regular` is *always* the "Regular Prices" system list's resolved value, independent of what wins for `final` — the "was €29.99, now €17.99" comparison a storefront shows is always anchored to the same stable reference.

### 4.7 Duplicate-priority uniqueness: DB-enforced for exact scope duplicates, honestly *not* for partial overlap

Every prior uniqueness invariant in this project (`attribute_signature`, `sku`, `slug`, `active_client_id`) had a single deterministic value a DB `UNIQUE` index could sit on. This one is harder, and the honest position is stated here rather than overclaimed: whether two `PriceList`s' scopes "overlap" is data-dependent (a `BRAND:Guess` list and a `CATEGORY:Shirts` list, both priority 10, only actually conflict for a product that happens to be *both* a Guess shirt) — not something a simple column-level constraint can express.

What **is** DB-enforceable, reusing the exact technique from `catalog-domain-design.md` §3.1 (`VariationSignature`): a deterministic `scope_signature` — a hash of the sorted `(scope_type, scope_reference_id)` pairs on a `PriceList` — with a real `UNIQUE(priority, scope_signature)` index. This catches the case of two lists with the **exact identical** scope condition set at the same priority (e.g. two separate `BRAND:Guess` lists both at priority 10) — a real, race-condition-safe guarantee, not an application-layer guess, and it directly implements the domain owner's original request ("fail loud at write time so the operator fixes their priorities").

**Genuine partial-overlap detection** (the `BRAND:Guess` + `CATEGORY:Shirts` case above) is explicitly **not** solved by a database constraint in v1 — confirmed against real-world precedent (§4.8: even Odoo, the most mature pricelist system surveyed, does not solve this automatically by default either). Deferred to §7, with the health-check report (§4.8) as the mitigation, not a hard guarantee.

### 4.8 Cross-list price sanity (wholesale ≤ regular): write-time check + on-demand health-check report, not full bidirectional blocking

Real research finding, not assumed: even Odoo's pricelist system — deliberately built for exactly this class of problem — does **not** automatically prevent a restricted list's price from silently exceeding the regular price after the fact; it relies on an optional, per-rule "minimum margin" safeguard the merchant opts into, plus a manual "test price" tool for verification before trusting a configuration. "Full bidirectional blocking" (validating every write to *any* list against every other list's current effective price) was considered and rejected: it would mean a routine seasonal sale on 200 products could get blocked by one forgotten wholesale row on product #147, a real UX cost for a boutique-scale catalog making frequent, targeted pricing changes.

**Adopted instead (Approach B, refined against the Odoo precedent):**
- **Write-time check for restricted lists** (e.g. `CUSTOMER_GROUP:wholesale`): when a `PriceListItem` is saved into such a list, resolve the current regular price for that target and reject the write if the new price would exceed it — the easy, catchable-at-the-source case.
- **On-demand "price list health check" report** (not a background job auto-generating warnings): an admin-triggerable scan across the whole catalog surfacing any current cross-list inconsistency (a restricted-list price now higher than the current regular price, caused by an unrelated later change to the regular list) — mirrors Odoo's manual "test price" tool, but catalog-wide rather than product-by-product. This is a reporting/query concern (§9 of `catalog-domain-design.md`'s established "reporting is a query layer, not a domain" precedent), not a blocking write-time constraint.

---

## 5. Database design

New package, `packages/EasyCo/Pricing` already exists (it owns `Money`/`Currency`/`Price`/`DefaultCurrency`) — this domain's tables live in its `database/migrations/`, following every other domain's self-containment convention.

**Tables:** `pricing_price_lists` (with `scope_signature` char(64), per §4.7), `pricing_price_list_scopes`, `pricing_price_list_items`. Every foreign key and unique index explicitly named up front (the 64-char-limit lesson from `catalog-domain-design.md` §7 applies here identically — `pricing_price_list_items` combined with several multi-word FK columns is exactly the shape that has bitten this project twice already).

**Hot paths:** `resolve()`'s scope-matching query needs `(scope_type, scope_reference_id)` indexed on `pricing_price_list_scopes`; `PriceListItem` lookup needs `(price_list_id, target_type, target_id, min_quantity)` indexed for the fallback-then-tier resolution in §4.3/§4.4.

**Unique constraint handling:** any `UNIQUE(priority, scope_signature)` violation must use the established SQLSTATE 23000 + dual-driver-code pattern (`catalog-domain-design.md` §7) — the third domain to need this exact pattern, after `attribute_signature`/`sku`/`slug` and `active_client_id`.

---

## 6. Replacing `InMemoryPriceResolver`

The existing `Contracts\PriceResolver` interface (`pricing-domain-design.md` §4.1) does not change — a new `EloquentPriceResolver` implements it, running §4.6's algorithm against real tables instead of a hardcoded array. `PricingServiceProvider`'s binding swaps from `InMemoryPriceResolver` to the new class; every existing caller (`ProductController`, the vertical-slice feature tests) is unaffected at the contract level, though the feature tests that specifically asserted `InMemoryPriceResolver`'s single-seed behavior will need updating to seed real `PriceList`/`PriceListItem` rows instead — flagged here as expected, not a regression to silently work around.

---

## 7. Explicitly deferred (documented, not accidental)

- **Genuine partial-scope-overlap detection** at write time (§4.7) — the health-check report (§4.8) is the v1 mitigation, not a hard guarantee, matching honest industry precedent rather than overclaiming a solved problem.
- **The exact Admin UI behavior** for switching a product between "one price for all variations" and per-variation pricing (§4.5) — a UI decision, not a domain one.
- **Stacking/compounding multiple discounts** (e.g. a brand discount *and* a seasonal discount both applying additively) — v1 is strictly single-winner-by-priority, never additive stacking; revisit only if a real scenario demands it.
- **`CUSTOMER_GROUP`/`CHANNEL` as first-class Catalog-style entities** — for v1 these are treated as plain scope-reference strings the application layer resolves (e.g. from `PriceContext.customerGroup`), not full domain aggregates of their own, mirroring how `Channel` already exists as a simple enum in `operational-sales-domain-design.md`, not a rich domain concept.
- **Barcode/SKU-style Hook-based generation** — not applicable here; noted only to confirm this domain deliberately does *not* follow that pattern, since price *values* are merchant/business decisions, not generated identifiers.

---

## 8. Next steps

1. Domain-layer implementation (`PriceList`, `PriceListScope`, `PriceListItem`, framework-agnostic, mirroring `Product`/`Variation`'s existing shape) — separate, focused prompts per aggregate, same rhythm as Catalog and Operational Sales.
2. `EloquentPriceResolver` + migrations, replacing `InMemoryPriceResolver`.
3. The two reserved system `PriceList`s' seeding mechanism (per-store, once).
4. The health-check report (§4.8) — after the write model is solid, same "reporting last" sequencing already established for Operational Sales.
