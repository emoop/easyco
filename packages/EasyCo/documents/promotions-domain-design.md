# Promotions Domain Design

**Status:** Draft v1 — approved scope, not yet implemented.
**Builds on:** `pricing-domain-design.md` §1/§7 (the Pricing/Promotions boundary — Pricing resolves catalog price, Promotions adjusts cart totals on top, as a separate step Cart/Checkout orchestrate), `pricing-persistence-domain-design.md` §3 (`PriceListScope` — the scope shape this domain deliberately mirrors, without sharing code — see §3 below for why), `cart-domain-design.md` §4/§6/§7 (the live-resolution-not-snapshot philosophy, applied here to promo codes), `catalog-domain-design.md` (cross-domain-by-id reference to Product/Variation/Brand/Category/Tag/AttributeValue), `account-domain-design.md` (cross-domain-by-id reference to Account).
**Origin:** designed through a structured conversation researching WooCommerce's native coupon fields (General/Usage Restriction/Usage Limits tabs) and Bagisto's Cart Rules (conditions, actions, coupon codes) as real-world precedent, then adapted to this project's existing cross-domain-by-id conventions rather than copied verbatim.

---

## 1. Scope

This document defines the **Promotions domain**: customer-entered discount codes ("promo codes"), applied explicitly by the customer at cart level, as a correction layered on top of whatever price Pricing has already resolved for each line.

**This is not the same mechanism as Price Lists** (`packages/EasyCo/Pricing`), and the two must not be conflated. The cleanest way to state the distinction: **Price Lists are a group mechanism, Promotions is a personal one.**

| | Price Lists (Pricing domain, existing) | Promotions (this domain) |
|---|---|---|
| Trigger | Automatic — applied on every `PriceResolver::resolve()` call, no customer action | Explicit — a specific customer types a specific code |
| Audience | A broad segment — sometimes literally every customer, every channel | One customer (or a bounded set), by construction — even a "public" code is consumed one customer, one use, at a time |
| Usage tracking | None — a Price List isn't "used up" by anyone | Central to the domain — total/per-customer redemption limits are the whole point |
| Applies to | A single priceable item's catalog price | The cart as a whole |
| Owns | `packages/EasyCo/Pricing` | `packages/EasyCo/Promotions` (new) |

**Explicitly out of scope for V1** (deferred, not forgotten — see §6):
- Free shipping toggle — no `Shipping` domain exists yet; the field would be inert. Add when Shipping is designed.
- Auto-generated codes (bulk generation with prefix/suffix, à la Bagisto) — needed later for abandoned-cart recovery (`cart-abandoned-recovery-note.md`), not required for V1's manually merchant-created codes.
- BOGO / buy-X-get-Y mechanics — a genuinely different, quantity-based mechanism, not an amount-based discount.
- Checkout integration — the `Checkout` domain doesn't exist yet. This document covers Promotion definition, validation, and Cart-level application only (see §5 for why redemption counting specifically waits for Checkout).
- Any stacking-priority resolution beyond `individual_use_only`'s binary block — no `priority` field in V1; nothing would consume it yet (see §3.1).

---

## 2. Core entity: Promotion

```
Promotion
├── id
├── code                       string, unique, case-insensitive comparison
├── discount_type              PERCENTAGE | FIXED_AMOUNT
├── discount_value             Decimal (0–100) when PERCENTAGE, Money when FIXED_AMOUNT — reuses
│                              Pricing\Money, the same value object OperationalSales already reuses
├── individual_use_only        bool — cannot be combined with any other active Promotion on the same cart
├── exclude_sale_items         bool — does not apply to a CartLine whose live PriceQuote::isDiscounted()
│                              (already exists in Pricing) is true
├── minimum_spend              Money, nullable
├── maximum_spend              Money, nullable
├── new_customers_only         bool — restricts to accounts with no prior completed order. Applies
│                              to logged-in Accounts only — a guest cart always fails this check,
│                              since there is no reliable identity to check "no prior order" against
├── usage_limit_total          int, nullable — null = unlimited
├── usage_limit_per_customer   int, nullable — null = unlimited
├── usage_limit_items          int, nullable — max cart items the discount applies to
├── valid_from                 nullable datetime — null = active immediately
├── valid_until                nullable datetime — null = never expires
├── status                     ACTIVE | INACTIVE — manual disable without deleting history,
│                              same pattern as PriceList.status
└── PromotionScope[]           zero or more — see §3. Zero = applies to every product/variation.
```

No `priority` field in V1 — see §3.1 for why.

---

## 3. PromotionScope — the extensible eligibility mechanism

```
PromotionScope
├── id, promotion_id
├── scope_type           BRAND | CATEGORY | TAG | ATTRIBUTE_VALUE | PRODUCT | ACCOUNT
├── scope_reference_id   plain string id — cross-domain by id only, same convention as PriceListScope
└── mode                 INCLUDE | EXCLUDE
```

- **Brand-specific and season/collection-specific codes** — the concrete cases that motivated this design — need no special-casing: brand uses `scope_type = BRAND`; season/collection uses `scope_type = ATTRIBUTE_VALUE`, exactly the same shape `pricing-persistence-domain-design.md` already uses for its own "Autumn/Winter 2025 collection is 30% off" example.
- **`ACCOUNT`** is new here — the WooCommerce "Allowed emails" equivalent, expressed as a real `Account` id now that the Account domain exists, rather than a raw email string.
- **Resolution rule:** a Promotion applies to a given CartLine if (zero `INCLUDE` scopes exist, or at least one `INCLUDE` scope matches that line) **and** (no `EXCLUDE` scope matches it).
- **Extensible by design:** a new `scope_type` (e.g. `CHANNEL`, once multi-channel matters) can be added later without changing the `Promotion` entity or any existing resolution code path.

### 3.1 Why `PromotionScope` is its own class, not a shared package with `PriceListScope`

`PriceListScope` (`packages/EasyCo/Pricing/src/PriceListScope.php`) has a structurally similar shape — but a direct read of the real, shipped code (`PriceListScope.php` and its resolution logic inside `EloquentPriceResolver.php`) shows the actual matching behavior is small and genuinely different:

- Pricing's scope matching is ~10 lines of inline AND-only logic inside `EloquentPriceResolver` — no `EXCLUDE` mode exists there at all; "don't discount an already-discounted item" is solved via `priority`, not an exclusion scope.
- Promotions needs `INCLUDE`/`EXCLUDE` mode and an `ACCOUNT` scope type that Pricing has no reason to ever support.
- There is no separate, reusable "scope matcher service" in Pricing to extract in the first place — the matching is inline against `PriceContext`'s fields.

Given that, sharing runtime code would mean refactoring already-tested, already-shipped Pricing code (`PriceListScope`, `PriceListScopeType`, `EloquentPriceResolver`, migrations, tests) for minimal real duplication savings, and would introduce exactly the dependency `pricing-domain-design.md` §7 explicitly rules out in either direction between Pricing and Promotions. `PromotionScope` therefore mirrors `PriceListScope`'s proven shape and cross-domain-by-id convention **as precedent**, implemented independently within `packages/EasyCo/Promotions`.

---

## 4. Cross-domain contracts

- **Catalog → Promotions:** `PromotionScope` references Product/Variation/Brand/Category/Tag/AttributeValue by plain id only — never a package dependency, same convention as Pricing.
- **Pricing → Promotions:** Promotions calls the existing `PriceResolver::resolve()` contract to read each CartLine's live `PriceQuote::isDiscounted()`, purely for the `exclude_sale_items` check — not a new dependency direction, since Cart already depends on `PriceResolver` for the same live-pricing reason.
- **Account → Promotions:** `PromotionScope`'s `ACCOUNT` scope_type, and the `new_customers_only` check, reference an Account id by plain id only.
- **Cart → Promotions:** Cart stores only the applied code as a string (a new nullable column on the cart aggregate). On every `GET /api/cart` (and on add/update), Cart calls Promotions to **live-revalidate**: is the code still `ACTIVE`, within `valid_from`/`valid_until`, has `usage_limit_total`/`usage_limit_per_customer` not been exhausted, does `minimum_spend`/`maximum_spend` still hold against the live-priced subtotal, does `new_customers_only` still hold? If any check fails, the code is silently dropped from the cart response with a flag, not an error — the same graceful-degradation shape already established for `PriceNotConfiguredException`. The code is never snapshotted or locked in, mirroring exactly why Cart never snapshots a resolved price either.
- **Promotions never queries Cart directly** — Cart is the caller, Promotions is a stateless-per-call validator, the same relationship shape as Cart → Pricing.

---

## 5. Redemption tracking waits for Checkout

A `PromotionRedemption` record should only ever be written at the point something becomes a permanent, historical fact — order placement — not at cart-apply time, mirroring exactly how Pricing resolves live but `OperationalSales\SaleLine` snapshots once, permanently, at sale time. The `Checkout` domain doesn't exist yet, so **`usage_limit_total` and `usage_limit_per_customer` have nothing real to count against until it does** — Promotion definition and live cart-side validation can be built and tested now, but actual redemption counting activates only once Checkout writes the first `PromotionRedemption` row. This is flagged explicitly rather than building a counting mechanism against Cart events, which are not permanent — a cart can be abandoned, edited, or emptied, none of which should decrement a usage counter.

---

## 6. What's deliberately not implemented in this iteration

- Free shipping toggle (needs a `Shipping` domain that doesn't exist yet).
- Auto-generated codes / bulk generation with prefix/suffix (needed for abandoned-cart recovery, not V1's manually created codes).
- BOGO / quantity-based mechanics.
- Actual redemption counting (needs Checkout to exist — see §5). `usage_limit_total`/`usage_limit_per_customer` are stored and validated for shape, but nothing decrements them yet.
- A `priority` field or any stacking-priority resolution beyond `individual_use_only`'s binary block.
- The `Checkout` domain itself, and therefore any code path that turns an applied Promotion into a real, permanent discount on a placed order.

---

## 7. Testing plan

- `Promotion` domain unit tests: construction/validation for both `discount_type` variants, `valid_from`/`valid_until` window logic (including both-null = always valid), `minimum_spend`/`maximum_spend` boundary checks.
- `PromotionScope` resolution tests: zero-scope universal case, single `INCLUDE` match, single `EXCLUDE` override, `INCLUDE` + non-matching `EXCLUDE`, multiple scope types combined.
- Cross-domain integration tests: `exclude_sale_items` correctly reads a real `PriceQuote::isDiscounted()` result from Pricing; `ACCOUNT` scope correctly resolves against a real Account id; `new_customers_only` correctly rejects a guest cart.
- Cart integration tests: applying a valid code, applying an invalid/expired/exhausted code (rejected, cart unaffected), a previously-valid code becoming invalid between requests (silently dropped with a flag on next `GET /api/cart`, not a 500).
