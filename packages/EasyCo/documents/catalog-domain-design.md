# Catalog Domain Design

**Status:** v1.2 — vertical-slice hardening pass (v1.1 approved; this pass adds mandatory identifiers and the Eloquent persistence layer)
**Builds on:** `easyco/pricing` (Currency, Money, Price — Catalog references it by id only, never duplicates its fields)
**Supersedes:** the earlier "Simple Product vs Variable Product as separate models" framing from the initial Catalog prompt

**Changes in this pass (v1 → v1.1):** the core Product/Variation model is unchanged and approved — see §2. This pass closes the one real gap identified in review: the domain layer did not validate that a Variation's attributes were actually declared axes of its Product, and `Variation` did not store its own attribute assignments at all (only the derived `attribute_signature`). Added: `VariationAxis` (a Product-declared axis + its enabled values), `Product::declareVariationAxes()`/`assertValidCombination()`, `Variation::attributeAssignments()` as the authoritative representation, `Product::changeVariationCombination()` as the single atomic operation for mutating an existing combination, and a hard runtime guarantee that a Variation's signature can never drift from its assignments. See §3.5 and §3.6.

**Changes in this pass (v1.1 → v1.2):** driven by building the first real Catalog↔Pricing vertical slice end-to-end (see `vertical-slice-notes.md`), which surfaced that neither `Product` nor `Variation` had a caller-facing identifier a human can type. Added: mandatory, unique `Product::baseSku()` and mandatory `Variation::sku()` (§3.8); archived-variation revival via `Variation::reviveFromArchive()` so re-adding a previously-archived combination reuses its identity instead of creating a new row (§3.9); `VariationCombinationGenerator::generate()`'s required `$skuForCombination` injection point (§3.2); `Contracts\ProductRepository::findByBaseSku()`; and `EloquentProductRepository`/`EloquentVariationRepository`, the first concrete implementations of both repository contracts, now wired into `easyco-main` (§6, §7).

---

## 1. Scope

This document defines the **Catalog domain**: what a Product *is*, what a Variation *is*, and the rules that keep the two consistent under change — SIMPLE ↔ VARIABLE conversion, attribute vs. variation-axis, and the database-level guarantees behind all of it.

**Explicitly out of scope for this domain:**
- **Regular/sale price, cost, discounts, tax** — belongs to `Pricing`. A Variation exposes `priceableId()` (its own id); Pricing resolves everything price-related against that id. See `pricing-domain-design.md` §4 (`PriceResolver`/`CostPriceProvider`).
- **Stock levels, backorders, reservations** — belongs to a future `Inventory` domain. Catalog only exposes the Variation id Inventory keys off of.
- **Channel-specific availability/feed rules** (Web, Google Shopping, Meta, AI agents, ...) — belongs to a future `Channel & Distribution` domain, layered on top of `CatalogVisibility`, not a replacement for it.
- **Full size-measurement engine** — a clean extension point (`catalog_size_guides`) exists; the actual per-product/per-variation measurement tables are deferred (§6).
- **Physical media storage** — Catalog owns the *reference* (`catalog_media`), never the storage provider/CDN.

---

## 2. The authoritative model

SIMPLE and VARIABLE are **not** two domain models. Both are the `Product` aggregate with a `Variations` collection; only the invariant enforced on that collection differs:

```
Product
├── id, type (SIMPLE | VARIABLE), name, status, catalog_visibility, ...
└── Variations[]
       SIMPLE   → exactly one Variation, type = UNIVERSAL, never customer-selectable
       VARIABLE → one or more Variations, type = STANDARD, customer-selectable
```

A `Variation` is not a `Product`. It belongs to exactly one Product, is created/edited only through the Product aggregate boundary (`Product::addStandardVariation()`, never `new Variation(...)` from outside the package), and is the identifier every other domain (Pricing, Inventory, Cart, POS, Orders) keys off of.

---

## 3. The four resolved design questions

### 3.1 Variation combination uniqueness

**Decision:** a deterministic SHA-256 signature (`VariationSignature`) computed in the application layer from the sorted `(attribute_definition_id:attribute_value_id)` pairs, persisted as a plain column `catalog_variations.attribute_signature`, enforced by a real database `UNIQUE(product_id, attribute_signature)` index.

**Why not a DB-generated/stored column:** the source data (which axis maps to which value) lives in a *child* table, `catalog_variation_attribute_values` — MySQL/MariaDB and SQLite generated columns can only read other columns in the *same row*, so a generated column was never an option here regardless of DB engine choice.

**Why this is race-condition-safe and the app-layer check in `Product::addStandardVariation()` is not, alone:** an in-memory/application "does this combination already exist? then insert" has a TOCTOU window under concurrent requests. The actual guarantee is the DB unique index: two concurrent inserts of the same `(product_id, attribute_signature)` are serialized by the database itself, and the second one raises a constraint violation that the repository layer must catch and translate to `DuplicateVariationCombinationException::fromDatabaseConstraintViolation()`. This is proven directly in `tests/DatabaseUniquenessConstraintTest.php` (`test_concurrent_insert_race_is_caught_by_the_constraint_not_a_check_then_insert`), which performs two raw `INSERT`s with no `SELECT` in between and confirms the second fails.

**A pleasant side effect:** the UNIVERSAL variation gets a *fixed constant* signature (`VariationSignature::forUniversalVariation()`) rather than a hash of an empty set. That means "a SIMPLE product has exactly one Universal variation" falls out of the exact same `UNIQUE(product_id, attribute_signature)` index — no second constraint needed. Proven in the same test file (`test_a_simple_product_can_only_ever_have_one_universal_variation_via_the_same_constraint`).

### 3.2 Variation generation

**Decision:** the merchant declares which attributes are axes for a product (via `catalog_product_attributes.is_variation_axis`) and which values are enabled per axis (`catalog_product_axis_values`). `VariationCombinationGenerator` computes the cartesian product and calls `Product::addStandardVariation()` for each combination — so a generated variation and a manually-created one are indistinguishable afterwards; they go through the exact same invariants.

**sku injection point (v1.2):** `generate()` also takes a required `callable $skuForCombination` (`array $combination): string`), called once per combination to produce its sku, now that `addStandardVariation()` requires one (§3.8). This is deliberately *not* the real SKU-generation feature — it is only the seam that keeps the generator working now that sku is mandatory. A real deterministic/templated SKU generator is still deferred (§6); today the caller supplies the strategy.

**State model:** the minimum needed, not a bespoke workflow engine. `VariationStatus::DRAFT` represents "combination exists, not yet merchant-confirmed" — a DRAFT variation is never effectively purchasable regardless of its `is_purchasable` flag (`Variation::isEffectivelyPurchasable()` folds this in so callers never have to check status and flags separately). Re-running generation after the merchant adds one more axis value only creates the *new* combinations — existing ones are silently skipped, not re-created or errored on (`VariationCombinationGeneratorTest::test_running_generation_twice_skips_already_existing_combinations`).

Explicitly **not** built in v1: bulk variation-management UI/workflow, partial-combination templates, or an "undo generation" operation.

### 3.3 Attribute definition scope

**Decision:** the smallest model that avoids both hardcoded columns *and* a Bagisto-style attribute-family system. `catalog_attribute_definitions` is a single, global, reusable set of definitions ("Color", "Material", "Voltage", ...). Whether a given definition is **descriptive** or a **variation axis** is decided **per product**, on a single pivot table (`catalog_product_attributes.is_variation_axis`) — not on the definition itself, and not on a category-mandated attribute set.

This directly satisfies `ATTRIBUTE != VARIATION AXIS`: the same "Color" definition can be a variation axis on a T-shirt and a purely descriptive, single-value attribute on an accessory that only ships in one color, with no schema difference between the two cases — just a different value of one boolean column.

Only `AttributeType::SELECT` is usable as an axis (`AttributeDefinition::assertUsableAsVariationAxis()`, throws `InvalidVariationAxisException` otherwise) — an axis needs a closed, enumerable value set to generate combinations from; free-text/number/boolean attributes don't have one. `MULTISELECT` is explicitly descriptive-only in v1 (see §6, deferred).

**Deferred, explicitly:** category-level suggested/required attribute sets, attribute groups/families à la Bagisto. Documented here so it's a conscious choice, not an oversight.

### 3.4 SIMPLE ↔ VARIABLE transitions

**SIMPLE → VARIABLE** (`Product::changeToVariable()`): the Universal variation is **archived**, never deleted — same row, same id, `VariationStatus::ARCHIVED`, forced `is_visible = false` / `is_purchasable = false`. Idempotent: calling it again on an already-VARIABLE product is a no-op.

**VARIABLE → SIMPLE**, guarded path (`Product::attemptConvertToSimple()`): allowed **only** if the product has never had a STANDARD variation created — checked by type, not by current status, so archiving a STANDARD variation first does **not** unlock the transition (`ProductTypeTransitionTest::test_attempt_convert_to_simple_is_refused_even_if_the_standard_variation_was_later_archived`). Catalog cannot see into Orders/POS/Inventory to know whether a variation id is already referenced there, so the conservative default is refusal — throwing `UnsafeProductTypeTransitionException`.

**VARIABLE → SIMPLE**, explicit escape hatch (`Product::forceConvertToSimple(bool $iHaveVerifiedNoExternalReferencesExist)`): no default argument, a deliberately long/awkward parameter name so it cannot be called by accident or muscle memory, requires `true` to proceed. Archives all existing STANDARD variations (never deletes) and creates a fresh Universal variation. The verification that no external system references the archived variations is explicitly **outside Catalog's boundary** — the caller (an application service that *can* see other domains, or a human operator) is responsible for it.

**The rule that never bends, in either direction:** a Variation id, once it exists, is never deleted and never reassigned to a different combination's identity — see §3.6 for what "changing a combination" means precisely (the id stays the same; only its assignments/signature change, atomically).

### 3.5 Variation attribute validation

**The invariant:** every attribute a Variation uses must be a **declared axis** of its Product, and the value used must be one the merchant actually **enabled** for that axis on that product. `Material = Cotton, Color = Black` is invalid on a product that only declared `Color` and `Size` as axes — Material was never declared, so it cannot appear in a combination at all, regardless of whether "Material" exists as a valid attribute somewhere else in the catalog.

**Where this lives:** `Product::declareVariationAxes(VariationAxis[] $axes)` records the axis set (a full replace, not incremental — keeps the v1 mental model simple: re-declare the whole set when it changes). `VariationAxis` itself is constructed from an `AttributeDefinition` plus the specific `AttributeValue`s enabled for it, and validates two things immediately at construction:
- the definition is `AttributeType::SELECT` (only a closed, enumerable value set can be an axis — enforced via `AttributeDefinition::assertUsableAsVariationAxis()`, the same rule from §3.3),
- every supplied value actually belongs to that definition (`InvalidVariationAxisException::valueBelongsToWrongDefinition()`), so a "Material" value can never be smuggled in under a "Color" axis by id collision.

`Product::assertValidCombination()` then checks any candidate combination against the declared axes before it can become a Variation:
1. every supplied `attribute_definition_id` is a declared axis → `InvalidVariationAxisException::axisNotDeclaredForProduct()` otherwise,
2. every declared axis is supplied exactly once (no partial combinations) → `InvalidVariationAxisException::missingValueForAxis()` otherwise,
3. every supplied value is one the merchant enabled for its axis → `InvalidVariationAxisException::valueNotAllowedForAxis()` otherwise.

**Duplicate axis assignment** (e.g. trying to set `Color` to two different values in one combination) is not a runtime check — it is **structurally impossible**: a combination is represented as a PHP map keyed by `attribute_definition_id`, so a second value for the same key silently replaces the first before any validation code even runs. `ProductStandardVariationTest::test_duplicate_axis_assignment_is_structurally_impossible` documents this explicitly rather than pretending there is a runtime branch to test.

**The generator gets the same guarantee, plus one more:** `VariationCombinationGenerator` re-validates the *entire* requested axis/value set against the Product's declared axes **before generating a single Variation** (`assertEveryAxisAndValueIsValidForProduct()`), not just per-combination as it goes. This matters specifically because of how a cartesian product works: if validation only happened inside each `addStandardVariation()` call, an invalid value appearing late in one axis's list (e.g. `Color: [Black, White, INVALID]`) would only be discovered after Black and White had already been created — a partial, half-finished result. The upfront check makes `generate()` all-or-nothing; `VariationCombinationGeneratorTest::test_an_invalid_value_deep_in_the_list_does_not_leave_earlier_valid_combinations_behind` proves this directly. The generator also rejects an axis supplied with zero values (`InvalidVariationAxisException::emptyAxis()`) and deduplicates repeated values within one axis's list deterministically (`array_unique`) before computing the cartesian product, so `{Black, Black, White}` behaves identically to `{Black, White}`.

### 3.6 Authoritative source of variation attributes

```
Variation
    │
    ├── attributeAssignments()   map: attribute_definition_id => attribute_value_id
    │       ↓
    │   SOURCE OF TRUTH  (backed by catalog_variation_attribute_values)
    │
    └── attributeSignature()     sha256 of the sorted assignments
            ↓
        UNIQUENESS / INDEXING PROJECTION  (backed by catalog_variations.attribute_signature)
```

The signature is derived from the assignments — never the reverse. Nothing in the domain layer reconstructs a Variation's attribute state *from* its signature (a sha256 hash is one-way by construction; there would be nothing to reconstruct from). Concretely, `Variation` now stores `attributeAssignments` as a real constructor parameter and exposes it via `attributeAssignments()`, not just the signature it previously carried alone.

**The consistency guarantee is enforced, not just documented:** both `Variation`'s constructor and `replaceCombination()` (§3.7 below) independently recompute `VariationSignature::forCombination($assignments)` and compare it against the signature they were handed, throwing `LogicException` on any mismatch. This makes it structurally unreachable for a `Variation` object to exist with a signature that doesn't correspond to its own assignments — a future bug in a caller that computes the two from different inputs is caught immediately at construction time, not discovered later as silent data corruption.

### 3.7 Atomic variation combination changes

A Variation's defining combination can legitimately need to change post-creation (e.g. correcting `Color: Black` to `Color: Red` on an already-created variation, id unchanged). `Product::changeVariationCombination(Variation $variation, array $newAssignments)` is the **only** sanctioned way to do this:

1. confirms `$variation` actually belongs to this Product (`LogicException` otherwise — a caller cannot mutate another aggregate's child through the wrong parent),
2. confirms it is a STANDARD variation (a UNIVERSAL variation has no mutable combination — `LogicException` otherwise),
3. validates the new combination against the declared axes (§3.5 — reuses `assertValidCombination()`, so a change is held to exactly the same rules as an initial creation),
4. computes the new signature and checks it doesn't collide with **another** variation of the same product (reuses the same in-memory check as `addStandardVariation()`, correctly excluding the variation being changed itself — see `ProductCombinationMutationTest::test_a_variation_can_be_changed_to_its_own_existing_combination_without_a_false_conflict`),
5. only then calls `Variation::replaceCombination()`, which itself re-derives and checks the signature against the new assignments (§3.6) before swapping both fields together.

**No partial updates on failure:** every validation happens *before* `Variation::replaceCombination()` is ever called, so a rejected change (invalid axis, or a collision with an existing variation) leaves the variation's assignments and signature completely untouched — proven by `ProductCombinationMutationTest::test_rejected_change_leaves_the_variation_completely_untouched`.

**Persistence note (see §11 in the deferred-work sense):** at the repository/infrastructure layer, the equivalent operation must wrap the `catalog_variation_attribute_values` row updates and the `catalog_variations.attribute_signature` column update in a single database transaction, exactly like initial creation (§3.1) — the domain layer's atomicity guarantee is only half the story until persistence wraps it the same way.

### 3.8 Product baseSku and mandatory Variation sku

**Decision:** `Product::createSimple()`/`createVariable()` now both require a mandatory, unique `baseSku` (throws `InvalidArgumentException` on an empty value), and every `Variation` — UNIVERSAL and STANDARD alike — now requires a mandatory, non-empty `sku` for the same reason at the Variation level (`Variation`'s constructor and `setSku()` both throw `InvalidArgumentException` on empty).

**Why baseSku exists, and why it's mandatory — the real-world driver is POS lookup:** a staff member at the till types a short, memorable code (e.g. `"167342"`) to pull a product up, then visually picks the specific variation — color, size — from what's on screen. Requiring them to type a full derived sku (e.g. `"167342-12Y-RED"`) character-for-character at speed is slow and error-prone, exactly the failure mode a till workflow can't tolerate. `baseSku` is that short, human-typeable lookup key; `Contracts\ProductRepository::findByBaseSku()` is its corresponding lookup method. The Universal variation's `sku` is set to exactly `baseSku`, with no suffix — a SIMPLE product has exactly one sellable thing, so a distinguishing suffix would be meaningless (see §4.2).

**Why sku is mandatory but barcode stays optional — they are different kinds of identifier:** `sku` is EasyCo's own tracking identifier. The system needs one on every Variation because internal workflows (POS, inventory, reporting) key off it — a Variation without one is an incomplete record, not a valid resting state. `barcode` is a convenience value, typically manufacturer-provided (GTIN/EAN/UPC): it may not exist yet (private-label stock awaiting a barcode assignment), may be identical across multiple otherwise-distinct variations (bundled or reissued stock sharing one printed barcode), or may never apply at all. Making it mandatory and unique would make illegitimate real-world states unrepresentable in exactly the case where the physical world doesn't cooperate — so `barcode` remains nullable, unchanged.

### 3.9 Archived-variation revival

**Decision:** before creating a brand-new STANDARD variation, `Product::addStandardVariation()` first checks whether an ARCHIVED variation of this Product already occupies the requested `attribute_signature`. If one does, no new Variation is created — `Variation::reviveFromArchive()` transitions that exact object ARCHIVED → DRAFT and it is returned as-is. If none does, behavior is unchanged from §3.1/§3.5: validate the combination, check for a live duplicate, create a new STANDARD variation with the given sku.

**What's preserved vs. what's deliberately ignored:** the revived variation keeps its original `id`, `sku`, and `barcode` completely untouched. The `$sku` argument passed into that call to `addStandardVariation()` is discarded in this branch — reusing the archived variation's own identity is the entire point of reviving it; assigning it the freshly-supplied sku would defeat that, and risks colliding with whatever sku the caller actually meant for a genuinely new variation.

**Why this doesn't weaken the DB uniqueness guarantee:** a revived variation still occupies exactly the one `(product_id, attribute_signature)` row it always did (§3.1) — revival only changes which lifecycle `status` that one existing row is in, never how many rows exist for that signature. `reviveFromArchive()` is a deliberately distinct operation from `activate()`, not a loosening of it: `activate()` still only allows DRAFT → ACTIVE and still refuses a directly-archived variation — a merchant explicitly retiring a variation is not undone by casually reactivating it. Revival is reserved for the one specific case of the system reusing an existing identity for a regenerated combination.

## 4. Entities

### 4.1 Product (aggregate root)

```
Product
├── id
├── type                    SIMPLE | VARIABLE
├── name, slug
├── base_sku                mandatory, globally unique — short human-typeable
│                           POS lookup key, see §3.8
├── short_description, description
├── brand_id                → catalog_brands (nullable)
├── size_guide_id            → catalog_size_guides (nullable)
├── status                  DRAFT | ACTIVE | ARCHIVED        (lifecycle)
├── catalog_visibility      VISIBLE | HIDDEN                 (storefront display)
├── is_featured
└── Variations[]
```

`status` and `catalog_visibility` are deliberately two different columns — see §5 (Visibility vs sellability).

### 4.2 Variation

```
Variation
├── id
├── product_id
├── type                    UNIVERSAL | STANDARD
├── status                  DRAFT | ACTIVE | ARCHIVED         (lifecycle, distinct from the flags below)
├── attributeAssignments()  map: attribute_definition_id => attribute_value_id   ← SOURCE OF TRUTH (§3.6)
├── attribute_signature     sha256 of the sorted assignments  ← derived projection, never the reverse
├── sku                     mandatory, globally unique — EasyCo's own tracking id (§3.8)
├── barcode                 optional, globally unique when present — manufacturer-
│                           provided convenience value, may be absent/shared (§3.8)
├── is_visible, is_purchasable
├── short_description
├── shipping_class, weight_grams, length_mm, width_mm, height_mm
└── (no price/cost fields — see Pricing ownership, §1)
```

`priceableId()` on Variation is the same value as `id()`; the separate accessor name exists purely to document *why* Pricing/Inventory/Cart reach for it.

### 4.3 Attributes

```
AttributeDefinition          (global, reusable)
├── id, code, name, type     TEXT | NUMBER | BOOLEAN | SELECT | MULTISELECT

AttributeValue                (only for SELECT/MULTISELECT)
├── id, attribute_definition_id, value, sort_order

VariationAxis                 (domain-layer object, not a table of its own — see §3.5)
├── one AttributeDefinition (must be SELECT)
├── the specific AttributeValues enabled for this axis on one Product

Product.variationAxes()       in-memory set of VariationAxis, keyed by attribute_definition_id
                               (persisted as the two tables below)

catalog_product_attributes    (per-product: descriptive OR axis — see §3.3)
├── product_id, attribute_definition_id, is_variation_axis
├── text_value | attribute_value_id     (descriptive value, when is_variation_axis = false)

catalog_product_axis_values    (allowed values per declared axis — feeds VariationAxis + the generator)
├── product_id, attribute_definition_id, attribute_value_id

catalog_variation_attribute_values   (a variation's actual chosen value per axis — the AUTHORITATIVE
                                       source Variation::attributeAssignments() represents; hashed
                                       into attribute_signature, never reconstructed from it)
├── variation_id, attribute_definition_id, attribute_value_id
```

---

## 5. Visibility vs. sellability vs. channel availability

Three independent signals, never conflated:

| Signal | Owner | Answers |
|---|---|---|
| `Product.catalog_visibility` | Catalog | Does this show up in storefront listing/search? |
| `Variation.is_purchasable` + `status` (via `isEffectivelyPurchasable()`) | Catalog | Can this specific configuration be sold at all? |
| Channel availability | *future* Channel & Distribution domain | Is this offered on Web / Google / Meta / AI agent X specifically? |

A product can be `catalog_visibility = HIDDEN` and still have an `isEffectivelyPurchasable() = true` Universal or Standard variation — exactly the POS scenario from the original brief: not shown on the storefront, still scannable/sellable at the till or through an authorized direct-order flow.

---

## 6. Deferred to a later version (documented, not accidental)

- Full size-measurement engine (per-product/per-variation body measurements). `catalog_size_guides` exists as the reference point only (`scope`: universal/brand/category/product); no measurement rows table yet.
- Category-level attribute requirements / attribute families.
- True multiselect **descriptive** attributes (multiple values on one non-axis attribute for one product) — v1's `catalog_product_attributes` pivot holds one value per row via `UNIQUE(product_id, attribute_definition_id)`.
- Bulk variation-management UI/workflow (spreadsheet-style bulk edit, partial-combination templates).
- Category hierarchy beyond a single nullable `parent_id` (no materialized path / nested set yet — add only if query patterns actually need it).
- ~~Eloquent model classes and concrete repository implementations~~ — **done as of v1.2**: `EloquentProductRepository` and `EloquentVariationRepository` (`src/Persistence/Eloquent/`) implement both `Contracts\ProductRepository` and `Contracts\VariationRepository` and are wired into `easyco-main` via `CatalogServiceProvider` — see `vertical-slice-notes.md`. Still not modeled: Eloquent models for `catalog_media`/`catalog_categories`/`catalog_tags` and their pivots, and reloading a Product's `VariationAxis` declarations from storage (this is exactly why `Product::reconstituteFromStorage()`/`Variation::reconstituteFromStorage()` skip axis-declaration re-validation — see their docblocks).
- A real SKU-generation strategy (deterministic templates, sequence-based, collision-retry, etc.). `VariationCombinationGenerator`'s `$skuForCombination` callable (§3.2) is only the injection point added in v1.2 to keep the generator working now that sku is mandatory — it is not this feature.
- A barcode-collision-avoidance strategy. `barcode` has no generation logic at all today; every value is caller-supplied, and `catalog_variations_barcode_unique` is the only thing preventing a collision, enforced at insert time, after the fact.

---

## 7. Database design

**Driver target:** SQLite (current `.env`) and MySQL/MariaDB (planned) — every constraint used (`UNIQUE`, `FOREIGN KEY`) is portable across all three; no MySQL-only or SQLite-only DDL features were used (e.g. no generated/stored columns, no `CHECK` constraints that reference another table).

**Tables** (15 migrations, `packages/EasyCo/Catalog/database/migrations/` — the 14th and 15th add `catalog_products.base_sku` and tighten `catalog_variations.sku` to `NOT NULL`, both via the same safe backfill-then-constrain pattern: add nullable, backfill any pre-existing rows with a synthesized placeholder, then tighten):

```
catalog_brands
catalog_size_guides
catalog_categories                (self-referencing parent_id)
catalog_tags
catalog_products
catalog_variations
catalog_attribute_definitions
catalog_attribute_values
catalog_product_attributes        (descriptive attrs AND axis declarations)
catalog_product_axis_values       (allowed values per declared axis)
catalog_variation_attribute_values (each variation's chosen value per axis)
catalog_media / catalog_product_media / catalog_variation_media
catalog_product_categories / catalog_product_tags
```

**Hot paths and how each is indexed:**

| Lookup | Index |
|---|---|
| `barcode → variation` | `UNIQUE(barcode)` on `catalog_variations` |
| `SKU → variation` | `UNIQUE(sku)` on `catalog_variations` |
| `base_sku → product` (the POS till-lookup path, §3.8) | `UNIQUE(base_sku)` on `catalog_products` — `ProductRepository::findByBaseSku()` |
| `product_id → variations` | FK index + composite `(product_id, status)` |
| `product_id + attribute combination → variation` | `UNIQUE(product_id, attribute_signature)` — same index that enforces uniqueness *is* the lookup path |
| `product_id → complete catalog representation` | every child table (`catalog_variations`, `catalog_product_attributes`, `catalog_product_media`, ...) is indexed on `product_id` via its FK, bounding eager-load to one query per table, no N+1 across variations |

`barcode` is a plain `UNIQUE` column (nullable): both MySQL/MariaDB and SQLite treat multiple `NULL`s in a unique index as distinct by default, which already gives "unique when present" semantics without needing a filtered/partial index (a feature MySQL/MariaDB don't support anyway) — confirmed in `DatabaseUniquenessConstraintTest::test_multiple_null_skus_are_allowed_null_is_not_treated_as_a_duplicate`. `sku` (on `catalog_variations`) and `base_sku` (on `catalog_products`) are `UNIQUE NOT NULL` instead — both are mandatory identifiers now (§3.8), so there is no "unique when present" case to accommodate. `sku` was tightened to `NOT NULL` in a later migration than the column's original creation, precisely so a dev/staging DB with pre-existing rows wasn't rejected outright by the constraint — see the 15-migration note above.

Foreign keys from child tables to `catalog_products`/`catalog_variations` use `restrictOnDelete()` where a hard delete would silently orphan pricing/order references (products, variations themselves), and `cascadeOnDelete()` for pure ownership data with no external references (attribute assignments, media pivots, taxonomy pivots) — both `catalog_products` and `catalog_variations` additionally carry `softDeletes()` as the actual "removal" mechanism, so the restrictive FK is a last-resort safety net, not the primary safeguard.

**What was NOT verified in this sandbox:** actual execution against MySQL/MariaDB (no network access to a real instance here), and Eloquent model behavior (no `illuminate/database`/`orchestra/testbench` available — packagist wasn't reachable). What *was* verified: the exact constraint shape (`UNIQUE(product_id, attribute_signature)` etc.) against a real SQLite connection with real concurrent-insert semantics, matching the project's current `DB_CONNECTION=sqlite`. Running `php artisan migrate` against the real app and a quick manual duplicate-insert check on MySQL/MariaDB before considering this schema final is the recommended next step.

---

## 8. Research comparison (functional reference only, not architecture)

- **WooCommerce** — good checklist for variation-level capabilities (SKU, GTIN, price, sale schedule, stock, weight, dimensions, shipping class, image); its variation-as-near-Product ownership model was deliberately not copied.
- **Bagisto** — useful for the configurable-product idea and attribute-type system; its attribute-family concept was deliberately not copied (see §3.3, "smallest model" decision).
- **Medusa / Aimeos** — used only as comparative reference during design; no direct architectural borrowing.

---

## 9. Test coverage (`packages/EasyCo/Catalog/tests/`)

86 tests, 128 assertions, all passing (plus `easyco/pricing`'s existing, untouched 87 tests — 173 total across the two packages):

- `VariationSignatureTest` — determinism, order-independence, the fixed Universal constant, empty-input rejection.
- `ProductSimpleCreationTest` — the Universal-variation invariant, non-selectability, id back-fill on persistence.
- `ProductStandardVariationTest` — variation creation, in-memory duplicate detection, DRAFT/ACTIVE/ARCHIVED lifecycle, the structural-impossibility of duplicate axis assignment.
- `ProductTypeTransitionTest` — both transition directions, the guarded refusal (including the "archived first" bypass attempt), the explicit `forceConvertToSimple` escape hatch.
- `AttributeDefinitionTest` — the SELECT-only axis rule.
- `VariationAxisTest` — axis construction validation: SELECT-only, value-belongs-to-wrong-definition rejection, empty-axis rejection, unpersisted-definition rejection.
- `ProductVariationAxisValidationTest` — the core of this hardening pass: valid assignment accepted, undeclared attribute rejected, disallowed value rejected, missing-axis (incomplete combination) rejected, no-axes-declared-yet rejected, axis redeclaration replaces the previous set, duplicate axis declaration in one call rejected, SIMPLE products cannot declare axes.
- `ProductCombinationMutationTest` — `changeVariationCombination()`: valid change, uniqueness conflict against another variation, no-false-conflict when "changing" to the same combination, no-partial-update on a rejected change, cross-product misuse rejected, UNIVERSAL variation's combination cannot be changed.
- `VariationCombinationGeneratorTest` — cartesian product correctness, skip-existing-on-regeneration, undeclared-axis rejection, disallowed-value rejection, empty-axis-in-request rejection, deterministic deduplication of repeated values, and — the two tests specific to this hardening pass — that an invalid axis/value anywhere in the request rejects the *entire* generation with zero partial variations created.
- `DatabaseUniquenessConstraintTest` — the actual DB-level guarantee, including a genuine concurrent-insert race (no check-then-insert), scoped-per-product uniqueness, and the Universal-variation-count side effect, against a real SQLite connection.
- `ProductBaseSkuAndVariationRevivalTest` (v1.2) — mandatory `baseSku`/`sku` validation on both creation paths, the Universal variation's sku matching `baseSku` exactly (including after `attemptConvertToSimple()`), and the full archived-revival behavior: identity/sku preserved, the newly-supplied sku ignored, no duplicate row created, revival scoped to ARCHIVED only, and `activate()` unaffected.

---

## 10. Final review checklist (this hardening pass)

Verified by inspection and by the test suite above that there is no path through which:

| Concern | How it's prevented |
|---|---|
| A Variation uses an undeclared axis | `Product::assertValidCombination()` on every `addStandardVariation()`/`changeVariationCombination()` call, plus the generator's own upfront check |
| A Variation uses an invalid/disallowed attribute value | `VariationAxis::isAllowedValueId()`, checked in the same two places |
| A Product has duplicate Universal Variations | No public API creates a second one (`createSimple()`/transition methods each create exactly one); DB `UNIQUE(product_id, attribute_signature)` also enforces it via the fixed Universal signature constant (§3.1) |
| Duplicate combinations bypass application validation | `assertSignatureNotAlreadyUsed()` in both `addStandardVariation()` and `changeVariationCombination()` |
| Duplicate combinations bypass DB uniqueness | Unchanged `UNIQUE(product_id, attribute_signature)` index — proven against a real SQLite connection in `DatabaseUniquenessConstraintTest` |
| Signature and assignments drift apart | `Variation`'s constructor and `replaceCombination()` both recompute the signature from the assignments and assert equality — see §3.6 |
| Historical Variation identity is destroyed | No delete operations anywhere in the domain layer; `archive()` only flips status/flags; `changeVariationCombination()` changes assignments/signature but never the id |
| Catalog becomes responsible for Pricing | No price/cost fields exist anywhere in `Product`/`Variation`; unchanged from v1 |

**Intentionally deferred**, unchanged from §6. The repository-layer transaction wrapping persistence (child-table update + signature-column update, including for `changeVariationCombination()`) — flagged here as outstanding through v1.1 — is now implemented as of v1.2: `EloquentProductRepository::save()` wraps the whole Product aggregate (all Variations, all `catalog_variation_attribute_values` writes) in a single `DB::transaction()`. The domain-layer atomicity guarantee (§3.7) and the persistence-layer transaction now both hold.

**No remaining architectural concern from this pass requires a further decision** — the four items raised in review are now enforced in code and covered by tests, without changing the approved Product/Variation model, the hashing algorithm, or the database uniqueness strategy.
