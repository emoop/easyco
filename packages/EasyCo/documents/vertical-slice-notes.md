# Catalog + Pricing Vertical Slice — Notes

**Status:** Done — a narrow, real, end-to-end slice; not a template to copy for every future endpoint.
**Builds on:** `catalog-domain-design.md` (Product/Variation aggregate, migrations) and `pricing-domain-design.md` §4.1 (the `PriceResolver` contract shape, implemented here for the first time).

---

## 1. What this proves

The full path, all real, no mocks below the HTTP boundary:

```
HTTP POST /api/products
    → ProductController::store()
    → Product::createSimple()                         (domain, in-memory)
    → EloquentProductRepository::save()                (DB::transaction())
    → MySQL: catalog_products, catalog_variations       (real insert, real constraints)
    → PriceResolver::resolve()                          (cross-package contract call)
    → HTTP response
```

Before this slice, `easyco/catalog` (76 tests) and `easyco/pricing` (87 tests) were each verified correct *in memory only* — no Eloquent model had ever been instantiated, no migration had been exercised by application code, and no other domain had ever actually called `PriceResolver` (it didn't exist as code at all; only as a shape described in `pricing-domain-design.md` §4.1). Two specific risks don't show up in unit tests and only show up once real infrastructure is involved: whether the domain aggregates' invariants survive a round trip through Eloquent and a real database (in particular, whether `Product`/`Variation` even *can* be rebuilt from storage without re-running business validation that doesn't apply to already-persisted data), and whether the DB-level uniqueness guarantee described in `catalog-domain-design.md` §3.1 actually fires as designed against real MySQL error codes rather than only in a hand-written SQLite test. Proving both now — on the smallest possible slice — is cheaper than discovering either is broken after Inventory, Orders, and Cart are all already built against the same repository shape.

---

## 2. What was added

### Catalog (`packages/EasyCo/Catalog/src/`)

Four Eloquent models under `Persistence/Eloquent/`, mapped 1:1 to existing migration columns (no columns added or renamed):

```
ProductModel             → catalog_products
VariationModel            → catalog_variations
AttributeDefinitionModel  → catalog_attribute_definitions
AttributeValueModel       → catalog_attribute_values
```

Media, category and tag tables have no models yet — not needed by this slice.

**`EloquentProductRepository`** (implements `Contracts\ProductRepository`, bound in `CatalogServiceProvider`):
- `save()` wraps the Product, all its Variations, and their `catalog_variation_attribute_values` child rows in a single `DB::transaction()`.
- Duplicate-combination detection reads the driver-reported **SQLSTATE (`23000`)** and **MySQL error code (`1062`, `ER_DUP_ENTRY`)** off `QueryException::$errorInfo` as the primary check, with the index name (`catalog_variations_product_signature_unique`) matched against `errorInfo[2]` only as a secondary narrowing. It deliberately does not match on `$e->getMessage()` — that string is formatted by the exception itself and is fragile against driver, locale, and version differences; the SQLSTATE/error-code pair is the actual contract PDO/MySQL guarantee.

**`Product::reconstituteFromStorage()` / `Variation::reconstituteFromStorage()`** — added directly to the domain classes as explicit, docblocked, persistence-layer-only factories, replacing an earlier `ReflectionProperty`-based hydration. Both trust that the data they're given already passed validation once, at write time, and do not re-run it. `Variation::reconstituteFromStorage()` is the one exception worth calling out precisely: it *does* still recompute the signature from the given assignments and run the existing consistency check — that's a cheap corruption detector, not business validation, and stays for every Variation regardless of how it's built. What neither factory does is re-validate a Variation's combination against its Product's declared `VariationAxis` set, because axis declarations (`catalog_product_attributes` / `catalog_product_axis_values`) are not themselves loaded from storage anywhere in this slice. That's a scope boundary, not a discovered gap — `catalog-domain-design.md` §6 already lists axis persistence/reload as deferred work, and this slice never needed it to prove the create → save → resolve path.

### Pricing (`packages/EasyCo/Pricing/src/`)

**`Contracts/PriceResolver.php`, `PriceContext.php`, `PriceQuote.php`** — until this slice these existed only as a spec in `pricing-domain-design.md` §4.1; they are now implemented verbatim from that section (same method signature, same constructor parameters, same `isDiscounted()` logic).

**`Persistence/InMemoryPriceResolver.php`** — an explicitly-labelled temporary stand-in (see §3). Takes `array<priceableId, Price>` in its constructor and looks up by id only.

**`PricingServiceProvider`** now binds `PriceResolver::class` as a singleton to `InMemoryPriceResolver`, seeded with exactly one hardcoded entry: `"1" => 23.99 EUR`.

### Application layer

- `app/Http/Controllers/Api/ProductController.php` — `store()` validates `name` and `base_sku` (both `required|string|max:255`, the latter added once `Product::createSimple()` made `baseSku` mandatory — see `catalog-domain-design.md` §3.8), creates and saves a SIMPLE product with both, resolves a price for its Universal variation's `priceableId()`, and returns `price: null, price_unavailable: true` (still HTTP 200) instead of failing the request when `PriceResolver` throws `OutOfBoundsException`.
- `routes/api.php` — did not exist in this Laravel 11+ install; created and wired into `bootstrap/app.php`'s `withRouting()` for this slice.
- `tests/Feature/CreateProductVerticalSliceTest.php` — three tests: the two original ones (both now posting `base_sku` alongside `name`) plus a new one asserting that omitting `base_sku` returns a 422 validation error. All rebind `PriceResolver` to test doubles so none depend on `InMemoryPriceResolver`'s hardcoded seed or on guessing an auto-increment id.

---

## 3. Explicitly temporary — not production-ready

| What | Why it's not final |
|---|---|
| `InMemoryPriceResolver` + the single hardcoded seed in `PricingServiceProvider` | No `PriceList`/`PriceListItem`/`PriceRule` persistence exists at all yet (`pricing-domain-design.md` §2–3). Quantity tiers, customer group, channel, currency, and scheduled validity from `PriceContext` are all silently ignored. |
| ~~`Contracts\VariationRepository`~~ | **Resolved since this doc was first written**: `EloquentVariationRepository` now implements it (`findById`/`findBySku`/`findByBarcode`/`findByProductId`) and is bound in `CatalogServiceProvider` alongside `ProductRepository`. |
| `POST /api/products` | No authentication or authorization of any kind — anyone who can reach the route can create products. |
| `catalog_products.slug` | Handled entirely inside `EloquentProductRepository` (derived from the name at insert time, never touched again) but has **no representation on the domain `Product` class at all** — `Product` exposes no `slug()`/`setSlug()`. This is a real mismatch between the domain model and the persisted schema, not an oversight to paper over; it needs a conscious decision (see §5) before it's treated as settled. |
| SKU/barcode auto-generation | No generation strategy exists for either. `sku` is now mandatory everywhere (`catalog-domain-design.md` §3.8) and `VariationCombinationGenerator::generate()` takes a required `$skuForCombination` callable, but that's only an injection point — today the caller (the controller's `base_sku`/`name` input, or whatever strategy the generator's caller supplies) must produce every sku explicitly. `barcode` has no generation logic at all; it's caller-supplied or absent. |

---

## 4. What the test coverage proves — and doesn't

- **163 domain unit tests** (76 Catalog + 87 Pricing, both packages' existing suites, untouched by this slice) prove business-rule correctness entirely in memory: signature determinism, axis/combination validation, SIMPLE↔VARIABLE transition guards, Money/Price tax math. None of it touches Eloquent, a database, or HTTP.
- **The 2 new feature tests** (`CreateProductVerticalSliceTest`) prove the HTTP route resolves to the controller, the controller correctly drives `Product::createSimple()` through the repository into MySQL and back out via the JSON response, and that a `PriceResolver` failure returns `200` with `price_unavailable: true` rather than failing the request.
- **What neither proves:** concurrent *HTTP* request behavior. The only race-condition coverage that exists anywhere in this slice is the manual Tinker verification run earlier in this work — two independent in-memory `Product` aggregates for the same product id, the second `save()` blocked by the real MySQL unique constraint and correctly translated via the SQLSTATE `23000`/error code `1062` check. That was a one-off manual check at the repository level, not an automated test, and it never exercised two actual concurrent HTTP requests against the route.
- Also not covered: `EloquentVariationRepository`'s query methods (`findById`/`findBySku`/`findByBarcode`/`findByProductId`) — verified manually via Tinker in this session, not by an automated test — real `PriceList`-backed resolution, axis-declaration validation on a reconstituted Product (out of scope by design — see §2), and anything about load or performance under real traffic.

---

## 5. Recommended next steps, in priority order

1. **Real Pricing persistence** (`PriceList`/`PriceListItem` at minimum) to replace `InMemoryPriceResolver` — the single hardcoded seed is the largest gap between this slice and anything resembling real pricing.
2. **Resolve the `Product`/slug mismatch** noted in §3 — decide, deliberately, whether slug becomes a real domain concept on `Product` or stays a pure persistence-layer derivation, rather than leaving today's ad hoc `EloquentProductRepository` behavior as the de facto answer.
3. **Only then, the next domain** — Inventory, Orders, or Cart, whichever the architect prioritizes. Nothing here should be extended further until 1–2 land; that's the entire point of §1.

*(`VariationRepository` implementation was step 2 here originally — now done, see §2/§3.)*
