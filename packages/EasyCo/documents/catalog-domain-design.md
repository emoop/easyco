# Catalog Domain Design

**Status:** v1.5 — directional variation-axis re-declaration and explicit archived-variation restore (v1.4 approved)
**Builds on:** `easyco/pricing` (Currency, Money, Price — Catalog references it by id only, never duplicates its fields)
**Supersedes:** the earlier "Simple Product vs Variable Product as separate models" framing from the initial Catalog prompt

**Changes in this pass (v1 → v1.1):** the core Product/Variation model is unchanged and approved — see §2. This pass closes the one real gap identified in review: the domain layer did not validate that a Variation's attributes were actually declared axes of its Product, and `Variation` did not store its own attribute assignments at all (only the derived `attribute_signature`). Added: `VariationAxis` (a Product-declared axis + its enabled values), `Product::declareVariationAxes()`/`assertValidCombination()`, `Variation::attributeAssignments()` as the authoritative representation, `Product::changeVariationCombination()` as the single atomic operation for mutating an existing combination, and a hard runtime guarantee that a Variation's signature can never drift from its assignments. See §3.5 and §3.6.

**Changes in this pass (v1.1 → v1.2):** driven by building the first real Catalog↔Pricing vertical slice end-to-end (see `vertical-slice-notes.md`), which surfaced that neither `Product` nor `Variation` had a caller-facing identifier a human can type. Added: mandatory, unique `Product::baseSku()` and mandatory `Variation::sku()` (§3.8); archived-variation revival via `Variation::reviveFromArchive()` so re-adding a previously-archived combination reuses its identity instead of creating a new row (§3.9); `VariationCombinationGenerator::generate()`'s required `$skuForCombination` injection point (§3.2); `Contracts\ProductRepository::findByBaseSku()`; and `EloquentProductRepository`/`EloquentVariationRepository`, the first concrete implementations of both repository contracts, now wired into `easyco-main` (§6, §7).

**Changes in this pass (v1.2 → v1.3):** a targeted corrective pass, not a redesign — closes the one gap v1.2 explicitly documented as deferred rather than accidental: `Product::reconstituteFromStorage()` previously skipped axis-declaration rehydration entirely, so a reloaded VARIABLE product silently accepted any combination instead of validating against its real declared axes. Added: axis rehydration in `EloquentProductRepository` (§3.10) and the new `UnsafeAxisRedeclarationException` invariant (§3.10). Explicitly *not* touched: the Product/Variation model itself, `VariationSignature`, the Pricing boundary, SKU/barcode/slug handling, DB uniqueness constraints, the SIMPLE↔VARIABLE transition methods, or descriptive (non-axis) product attributes (at the time of this v1.3 pass, still no domain representation on `Product` — the design for resolving it lives in §3.11, not yet implemented as of this writing).

**Changes in this pass (v1.3 → v1.4):** closes the "real SKU-generation strategy" item §6 explicitly listed as deferred. `App\Providers\CatalogSkuGeneratorServiceProvider` (app/ layer, mirroring `CatalogSlugGeneratorServiceProvider`'s exact shape) now backs both `catalog.product.base_sku` and the new `catalog.variation.sku` Hook filter (`{baseSku}-{n}`, deliberately not attribute-value-based — see §3.2). The persistent, concurrency-safe base_sku sequence itself — `catalog_sku_sequence`, configurable start via `PRODUCT_SKU_SEQUENCE_START` — lives **inside the Catalog package** (`Contracts\SkuSequenceRepository` / `Persistence\Eloquent\EloquentSkuSequenceRepository`, migration in `packages/EasyCo/Catalog/database/migrations/`), the same boundary already established for `EasyCo\Pricing\DefaultCurrency`: the state-holder lives in the owning domain package, only the Laravel-specific wiring (registering the listener, reading the config value at migration time) lives in app/. (An earlier version of this table shipped directly in the root app's migrations and was corrected to this location as an immediate follow-up — see the migration's own docblock.) `CatalogSkuGeneratorServiceProvider::variationSkuStrategy(Product $product): callable` is a convenience factory returning the `catalog.variation.sku`-wired closure ready to pass as `generate()`'s `$skuForCombination` — the one canonical place to get it from, so the hook name/signature isn't re-derived at every future call site. `VariationCombinationGenerator::generate()`'s `$skuForCombination` parameter is now optional (§3.2) rather than required, but the class still cannot call `Hook::apply()` itself — the architectural boundary from `extensibility-design-and-hooks.md` §2 held throughout this pass, not relaxed for convenience. `EloquentProductRepository` gained a third implementation of the SQLSTATE-23000 unique-constraint-collision-retry pattern (§7), for `catalog_variations_sku_unique`. `DemoHooksServiceProvider`, the proof-of-concept this replaces, is deleted.

**Changes in this pass (v1.4 → v1.5):** replaces §3.10's coarse "refuse ANY axis re-declaration once a STANDARD variation has ever existed (by type, not status)" guard with a **directional compatibility check** (§3.17), because the old rule made two organic merchant operations structurally impossible: enabling a new value on an existing axis (e.g. adding "XL" to "Size"), and any explicit merchant-facing restoration of an archived variation (only `addStandardVariation()`'s implicit revive-by-signature existed, itself unreachable without re-declaring axes first). `Product::declareVariationAxes()` now calls the new private `assertAxisChangeIsSafe()`, which compares the proposed axis set against the current one and against every LIVE (non-ARCHIVED) STANDARD variation, evaluated as five ordered rules (§3.17): an identical set is always a no-op; a pure value addition to an existing axis is always safe; removing an axis, adding a new axis, or removing a value a live variation depends on is refused only while it would actually orphan that live variation's combination — never because of an ARCHIVED one. Added: `Product::restoreArchivedVariation(Variation $variation): void`, the new explicit counterpart to `addStandardVariation()`'s implicit revival branch (§3.9) — both are now documented as the only two callers of `Variation::reviveFromArchive()`. Added exception `VariationNotRestorableException`, thrown when a restore's own combination no longer validates against the current axes (the fail-loud check for axes that drifted while the variation sat archived, since archived variations never block an axis change themselves). `UnsafeAxisRedeclarationException` was rewritten from one factory (`becauseStandardVariationsExist()`, removed) to three, one per refusal rule. `Product::hasAnyStandardVariation()` (type-only, its one caller gone) is deleted; `assertAxisChangeIsSafe()` builds its own local live-variation collection instead. Explicitly *not* touched: the Product/Variation model itself, the "no partial combinations" invariant in `assertValidCombination()`, `VariationSignature`, `addStandardVariation()`'s existing revival branch, the SIMPLE↔VARIABLE transition methods, `UnsafeProductTypeTransitionException`, the DB schema (no migration — `EloquentProductRepository::persistVariationAxes()`'s existing full delete+reinsert already handles an extended or reduced axis set with no changes needed).

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

**sku generation (v1.2 → v1.4):** `generate()` takes an **optional** `?callable $skuForCombination` (`array $combination): string`, default `null`), called once per combination to produce its sku, now that `addStandardVariation()` requires one (§3.8). As of v1.4 the real SKU-generation feature exists: the `catalog.variation.sku` Hook filter (`App\Providers\CatalogSkuGeneratorServiceProvider`, see `extensibility-design-and-hooks.md`'s Hook Reference), which generates `{baseSku}-{n}` — a simple per-product sequential integer starting at 1 (e.g. `154215-1`, `154215-2`, ...). **Deliberately not attribute-value-based** (e.g. never `154215-s-black`) — decided explicitly with the domain owner: attribute values may be Cyrillic, may be long (`"11-12 years (152cm)"`), and the axis count varies per product, all of which make value-derived SKUs long and awkward to type at a POS terminal. `base_sku` is the human-typed lookup key; individual variations are normally scanned by barcode instead, so a variation's sku only needs to be short and unique, not descriptive.

`VariationCombinationGenerator` itself still cannot call this hook — it is a Catalog domain class, and this project's hard architectural rule (`extensibility-design-and-hooks.md` §2) is that domain packages never call `Hook::apply()`/depend on `EasyCo\Extensibility` directly. So `$skuForCombination` stays an explicit collaborator: when omitted and there is at least one combination to generate, `generate()` throws a `LogicException` rather than silently reaching for the hook itself — it is app/ layer code's job to build a closure that calls `Hook::apply('catalog.variation.sku', '', $product->baseSku(), $product)` and pass it in, the same "wrap from outside" pattern `ProductController::store()` already uses for `catalog.product.base_sku`/`catalog.product.slug`. The best-effort `count($product->variations()) + 1` candidate this listener picks can still collide (a gap from an archived variation, or a manually-assigned sku matching the pattern); `EloquentProductRepository::save()`'s own SQLSTATE/error-code-driven retry against `catalog_variations_sku_unique` (§7) is the authoritative, race-condition-safe guarantee, mirroring the slug retry exactly.

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

### 3.10 Variation axis rehydration and the redeclaration guard

**Decision:** `EloquentProductRepository::findByIdWithVariations()` — and every other finder, since they all share one `toDomainProduct()` helper — now reloads a VARIABLE Product's declared variation axes from storage (`catalog_product_attributes` where `is_variation_axis = true`, joined with `catalog_product_axis_values`) and rebuilds the real `AttributeDefinition`/`AttributeValue`/`VariationAxis` domain objects before the reconstituted Product is returned. This closes the one real persistence-layer gap this document previously flagged as deferred, not accidental (§6, prior to this pass): a reloaded VARIABLE product can now immediately call `addStandardVariation()`/`changeVariationCombination()` and have it validate correctly against its real declared axes, exactly as if the Product had never left memory — it no longer silently accepts any combination just because no axes were ever reloaded.

**How the rehydrated axes reach the aggregate:** `Product::reconstituteFromStorage()` gained a `VariationAxis[] $variationAxes = []` parameter and passes it straight through the real `declareVariationAxes()` method — not a bypass, so the normal within-set validation (SELECT-only axes, no duplicate definition — §3.5) still runs even on trusted, already-persisted data. The one thing that makes this safe is ordering: `declareVariationAxes()` is called **before** `$variations` are attached, so at that point the aggregate genuinely has zero variations regardless of how many are about to be attached next — which matters because of the new guard immediately below, which would otherwise block exactly this call.

**New invariant (v1.4): `Product::declareVariationAxes()` refused to change the axis set at all once the Product had any STANDARD variation** — checked by **type**, not current status, so an archived STANDARD variation still blocked it. **Superseded as of v1.5 — see §3.17.** That blanket rule made two organic merchant operations structurally impossible (enabling a new value on an existing axis; explicitly restoring an archived variation), so it was replaced by a directional compatibility check: re-declaring an identical set, or adding a new value to an existing axis, is always safe regardless of existing variations; removing/adding an axis or removing a value is refused only while it would actually orphan a **live (non-archived)** STANDARD variation's combination. §3.17 has the full rule table and the new `Product::restoreArchivedVariation()` operation this change also introduces. `UnsafeAxisRedeclarationException` is still the exception thrown (now via three distinct factories instead of one) — still a distinct invariant from `UnsafeProductTypeTransitionException`, not a reuse of it.

**Explicitly not resolved by this pass:** descriptive (non-axis) product attributes — `catalog_product_attributes` rows with `is_variation_axis = false` — still have **no domain representation on `Product` at all**. This pass only ever touched `is_variation_axis = true` rows; `Product` exposes no accessor for a descriptive attribute value and none is read or written anywhere in the domain or persistence layers. Adding that is new scope, not a fix, and remained a separate, real deferred item until resolved — see §3.11.

### 3.11 Descriptive (non-axis) attribute representation — designed, not yet implemented

**Status:** design only until the implementation prompt below lands — flagged as a real documentation error, not a status update, after a later task (§3.13) found Product.php never actually received these methods despite this section's own prior "resolved" language.

**Decision:** `Product` gains two new methods closing the gap §6 previously
flagged ("Descriptive (non-axis) product attributes have no domain
representation on `Product` at all... Adding this is new scope, not a fix"):

- `Product::setDescriptiveAttribute(AttributeDefinition $definition, string|AttributeValue $value): void`
- `Product::descriptiveAttributes(): array` — returns `array<string, string|AttributeValue>`
  keyed by `AttributeDefinition->code()`.

**Validation, at the point `setDescriptiveAttribute()` is called:**
- Throws `InvalidArgumentException` if `$definition` is currently declared
  as one of this Product's variation axes (`$this->hasVariationAxis($definition)`,
  per §3.5) — the same `AttributeDefinition` cannot be both an axis and a
  descriptive value on the same Product simultaneously; the underlying row
  is one or the other, never both (§4.3's schema comment already says
  this: "per-product: descriptive OR axis").
- If `$definition->type() === AttributeType::SELECT`: `$value` must be an
  `AttributeValue` instance whose `attributeDefinitionId()` matches
  `$definition->id()` — throws `InvalidArgumentException` otherwise. This
  is the only type where `$value` is not a plain string.
- If `$definition->type()` is `TEXT`, `NUMBER`, or `BOOLEAN`: `$value`
  must be a `string` — throws `InvalidArgumentException` if an
  `AttributeValue` is passed for one of these types. (`NUMBER`/`BOOLEAN`
  are stored and validated as their string representation at this layer;
  numeric-format or `'1'`/`'0'` normalization is a persistence-layer
  concern, not asserted again here.)
- If `$definition->type() === AttributeType::MULTISELECT`: throws
  `InvalidArgumentException` unconditionally — still explicitly deferred
  (§6), not silently accepted as if it worked.

**Persistence:** `EloquentProductRepository` reads/writes
`catalog_product_attributes` rows with `is_variation_axis = false` exactly
as it already does for `is_variation_axis = true` rows (§3.10's
precedent) — same table, same `toDomainProduct()` reconstruction path,
filtered on the opposite flag value.

**What stays deferred, unchanged from §6:** true multiselect descriptive
attributes (more than one value per definition per product) —
`UNIQUE(product_id, attribute_definition_id)` is untouched by this
decision.

### 3.12 Mutators for Brand, Category, Tag, AttributeDefinition, AttributeValue — resolved for the admin panel

Mirrors §3.11's own reasoning — these five entities shipped fully
immutable after creation (no `reconstituteFromStorage()` distinction,
plain public constructor, no mutators at all) because nothing consumed
an edit capability. `admin-panel-design.md`'s Filament resources for
them are that consumer now.

**`Brand`:** `rename(string $newName): void`, `changeSlug(string
$newSlug): void`. Also gains a nullable `logoMediaAssetId` field and
`setLogo(string $mediaAssetId): void` / `removeLogo(): void` — a
brand's logo, used in navigation menus, brand-filter carousels, and
sometimes alongside its products in the catalog, per the domain
owner's own stated use cases. A plain string reference, not a
`MediaAsset` instance — cross-domain references are always by id,
never a direct package dependency (CLAUDE.md rule 9); Brand never
imports `EasyCo\Media\MediaAsset`, mirroring how `ProductMedia`/
`VariationMedia` handle the same relationship. Deliberately a single
nullable reference, not a `ProductMedia`-style pivot with a gallery/
count-guard system — a brand has one logo, not a gallery.

**`Category`:** `rename(string $newName): void`, `changeSlug(string
$newSlug): void`, `changeParent(?string $newParentId): void` —
inherits the exact same "no cycle detection in v1" limitation the
constructor's own docblock already states.

**`Tag`:** `rename(string $newName): void`, `changeSlug(string
$newSlug): void`.

**`AttributeDefinition`:** `rename(string $newName): void` only —
deliberately NOT `code` or `type`. Changing `type` after a definition
has been used as a variation axis or has real `AttributeValue` rows
against it is a genuine invariant risk (e.g. SELECT→TEXT after it has
already generated real variation combinations) that would need
cross-referencing validation this pass does not attempt to design.

**`AttributeValue`:** `rename(string $newValue): void` (renaming the
value's own display text) and `changeSortOrder(int $newSortOrder):
void`. Renaming is confirmed safe by `VariationSignature`'s own
existing docblock: hashing is by the value's id, never its label, so
renaming never changes any variation's identity.

**Real, flagged risk, not solved here:** changing a `Brand`/`Category`/
`Tag`'s `slug` after `storefront-frontend-design.md`'s URL scheme is
live breaks whatever was already indexed/linked at the old URL. Not a
reason to withhold the mutator, but the admin UI consuming this should
treat a slug change as a deliberate, not casual, action.

### 3.13 Season entity, descriptive-attribute removal, and product-count/bulk-unlink safety (resolved for the admin panel)

Three related additions, all surfaced by real admin-panel usage.

**New entity: `Season`.** Structurally identical to `Brand` minus the
logo field — plain public constructor, `name`/`slug`, no
`reconstituteFromStorage()` distinction, `rename()`/`changeSlug()`
mutators mirroring §3.12's Brand treatment exactly. `Product` gains a
nullable `seasonId` column and `assignSeason(?string $seasonId): void`,
mirroring `assignBrand(?string $brandId)` exactly. Used for storefront
filtering/sorting ("Summer 2026") — single-select per product, same as
Brand, not a multi-value concept like Tag.

**New mutator: `Product::removeDescriptiveAttribute(AttributeDefinition
$definition): void`.** `setDescriptiveAttribute()` (§3.11) had no
inverse. Removes the `catalog_product_attributes` row for that
definition only when it is NOT currently this product's variation
axis — throws `CannotRemoveVariationAxisAttributeException` if it is,
rather than silently doing nothing or reaching into axis/Variation
territory it has no business touching. The only safe path to actually
removing an axis is resolving/removing the Variations that depend on
it first — separate, future work this method does not attempt.
Idempotent: removing a definition that was never set at all is a
no-op.

**Repository count methods, one per entity, for the product-count/
drill-down/delete-safety feature:**
- `BrandRepository::countProductsUsing(string $brandId): int`
- `SeasonRepository::countProductsUsing(string $seasonId): int`
- `CategoryRepository::countProductsUsing(string $categoryId): int`
- `TagRepository::countProductsUsing(string $tagId): int`
- `AttributeDefinitionRepository::countProductsUsing(string
  $definitionId): array{descriptive: int, axis: int}` — returns BOTH
  counts, not a single total, since the admin panel's bulk-unlink
  feature treats them completely differently (descriptive:
  bulk-unlinkable; axis: not, ever, by that mechanism).
- `AttributeValueRepository::countProductsUsing(string $valueId):
  array{descriptive: int, axis: int}` — same shape, since a SELECT
  value can be used descriptively on one product and as a real axis
  choice on another.

**Explicitly NOT built here, by deliberate design:** any mechanism —
bulk or single — for removing an `AttributeDefinition`/
`AttributeValue`'s **axis** usage from a product. A variation axis in
real use has real, possibly-already-sold `Variation` rows depending on
it; the only safe order is resolving those variations first, then the
axis usage becomes removable via already-existing, correctly-gated
machinery (§3.4/§3.10's guard on `declareVariationAxes()`), which
needs no new capability once variations are actually gone.

### 3.14 Product mutators: rename, status transitions, description, base_sku — resolved

Mirrors §3.11's own reasoning exactly — `Product` shipped without these
because nothing consumed an edit capability for them yet. The admin
panel's Product Edit page is that consumer now.

**`rename(string $newName): void`** — reuses the constructor's own
name validation (extracted into `assertValidName()` as part of this
pass — `Product`'s constructor had no such reusable assertion at all
before this, unlike every sibling lookup entity).

**Status transitions — three named methods, not a generic
`setStatus()`:** `publish()`, `markAsDraft()`, `archive()`. Matches
this codebase's own established convention (`Staff::deactivate()`/
`reactivate()`, not a raw enum setter). All three are idempotent.
`publish()` alone is guarded: throws
`CannotPublishEmptyVariableProductException` for a VARIABLE product
with zero non-archived `STANDARD` variations — structurally
unreachable for a SIMPLE product, whose Universal variation always
exists from `createSimple()` onward.

**`description()`/`changeDescription(?string $newDescription): void`**
— a new, dedicated nullable field. Deliberately NOT routed through
`descriptiveAttributes()` (§3.11): that mechanism is for flexible,
merchant-specific flags added without new domain code; a product
description is universal, near-mandatory core content every product
has, not a merchant-specific extension point.

**`changeBaseSku(string $newBaseSku): void`** — reuses the
constructor's own `assertValidBaseSku()`. The real
`catalog_products_base_sku_unique` constraint protects an UPDATE the
same way it already protected an INSERT (confirmed empirically, not
assumed, via a real collision test). Warning a merchant that changing
an already-in-use `base_sku` may orphan printed labels/barcodes is a
UI-layer concern for the admin panel to handle — this method itself is
a plain, unguarded mutator.

### 3.15 ProductGroup entity — designed, not yet implemented

**Status:** design only until the implementation prompt lands.

**New entity: `ProductGroup`.** A merchant-defined internal merchandise/
reporting group ("Обувки" = 4, "Комплекти" = 6) — explicitly distinct
from a future, legally-mandated VAT tax group (Наредба Н-18), which
remains out of scope until v2. Not tied to Category (a dress and a
dress+blouse set can share a category but belong to different groups),
mirrors `AttributeDefinition`'s shape (`code` + `name`, plain public
constructor, `rename()` only — `code` stays immutable after creation,
same "stable machine identifier" reasoning §3.12 already established
for `AttributeDefinition::code`).

`Product` gains a nullable `productGroupId` column and
`assignProductGroup(?string $productGroupId): void`, byte-for-byte
mirroring `assignSeason()`.

### 3.16 ProductTemplate entity — designed, not yet implemented

**Status:** design only until the implementation prompt lands.

A named, reusable default-value bag for Product creation — NOT a live
link: applying a template pre-fills a Create form's brand/season/
product group/categories/tags once, all fields remain fully editable
afterward, and editing the template later never retroactively touches
any product already created from it.

Plain public constructor: `name`, `brandId` (nullable), `seasonId`
(nullable), `productGroupId` (nullable), `categoryIds` (array of
string ids), `tagIds` (array of string ids). `categoryIds`/`tagIds`
stored as a JSON column directly on `catalog_product_templates`, not
two new pivot tables — a template's category/tag list needs no
relational integrity guarantee beyond "these ids existed when the
template was saved" (§3.3's own "smallest model" precedent). `rename()`
and `changeDefaults(...)` (replacing all five default fields at once —
no reason to expose five separate single-field mutators for a bag of
suggestions with no invariants between them).

### 3.17 Directional axis re-declaration, value extension, and archived-variation restore

**Numbering note:** this section is numbered 3.17, not 3.15, even
though the implementation task that produced it asked for "3.15" —
that number (and 3.16) were already in use by §3.15 ProductGroup and
§3.16 ProductTemplate above (both real, design-only sections, unrelated
to this change) by the time this pass started. Appended as the next
free number instead of renumbering two unrelated, already-referenced
sections.

**Decision:** replaces §3.10's v1.4 blanket guard ("refuse ANY axis
re-declaration once the Product has any STANDARD variation, by type
not status") with a **directional compatibility check**,
`Product::assertAxisChangeIsSafe(array $newAxesByDefinitionId): void`
(private, called from `declareVariationAxes()` after the existing
within-set validation — SELECT-only axes, no duplicate definition —
has already built the candidate `$byDefinitionId` map). All scoping is
by **LIVE STANDARD variations only** — type `STANDARD` **and** status
`!== VariationStatus::ARCHIVED`. The rules are evaluated in this order;
the first one that matches decides the whole operation:

| Rule | Condition | Outcome |
|---|---|---|
| R1 | New set identical to the current one (same declared `attribute_definition_id` keys, and per definition the same allowed-value id set — order never matters, compared as `array_values(array_unique(...))` then `sort(..., SORT_STRING)`) | **ALLOW**, unconditionally — a no-op, even with live variations. Keeps `reconstituteFromStorage()`-style reloads and a plain admin re-save harmless. |
| R2 | A currently-declared `attribute_definition_id` is absent from the new set (axis **removed**) | **REFUSE** while any live STANDARD variation exists (`UnsafeAxisRedeclarationException::becauseLiveVariationsWouldLoseAnAxis()`) — `assertValidCombination()`'s "every declared axis must be supplied" rule means any live variation necessarily already has a value for every currently-declared axis, so no per-variation filtering is needed here: existence of a live variation is itself sufficient. |
| R3 | The new set introduces an `attribute_definition_id` not currently declared (axis **added**) | **REFUSE** while any live STANDARD variation exists (`becauseNewAxisWouldInvalidateLiveVariations()`) — the mirror-image reason: no existing live variation can have a value for a genuinely new axis, and v1 has no migration path that invents one. |
| R4 | A definition present in **both** sets lost one or more of its allowed values (**value removed**) | **REFUSE** only if at least one live variation's own `attributeAssignments()` actually uses one of the specifically removed values (`becauseLiveVariationsUseRemovedValues()`) — unlike R2/R3 this one genuinely filters per-variation, since the axis itself is kept and most of its other values may still be fine. |
| R5 | Otherwise (identical axes with a pure value **addition**, or removing an axis/value no live variation depends on — including a Product with zero live variations at all) | **ALLOW.** |

**Implementation note on "the first one that matches decides":** R2/R3/R4
are not mutually exclusive across one submission (e.g. one axis removed
*and* a value removed from a different, still-present axis, in the same
call) — the check does not collect every simultaneous violation before
deciding; it refuses at the first one found (R2 checked across every
currently-declared definition absent from the new set, then R3 across
every newly-introduced definition, then R4 for definitions present in
both), and the caller fixes that one and resubmits.

**Why ARCHIVED variations never block R2/R3/R4:** an archived variation
is a historical record that is never re-validated against a changing
axis declaration, and its `catalog_variation_attribute_values` rows are
never touched by an axis change (unaffected by
`persistVariationAxes()`, which only ever touches
`catalog_product_attributes`/`catalog_product_axis_values`). The
trade-off this creates is deliberate: a merchant who retires a value by
removing it from the axis simply cannot restore the variations that
used it — fail-loud, not silent, and the check happens at the other end
of the trade-off instead (see immediately below), not by blocking the
axis change up front.

**New operation: `Product::restoreArchivedVariation(Variation $variation): void`** (public, placed next to
`addStandardVariation()`/`findArchivedVariationBySignature()`) — the
explicit, merchant-facing "bring this archived variation back"
counterpart to `addStandardVariation()`'s own **implicit**
revival-by-signature branch (§3.9, triggered merely by re-submitting
the same axis values). These two are now the **only** sanctioned
callers of `Variation::reviveFromArchive()` anywhere in the codebase.
Contract, in order:

1. `$variation` must already belong to this Product (`\LogicException` otherwise).
2. Only a `STANDARD` variation can be restored (`\LogicException` for `UNIVERSAL`).
3. `$variation` must currently be `ARCHIVED` (`\LogicException` for `DRAFT`/`ACTIVE`).
4. Its **current** `attributeAssignments()` are re-validated against the Product's **current** declared axes via the existing, unchanged `assertValidCombination()` — this is the fail-loud check for axes that drifted while the variation sat archived (exactly the risk R2–R4 deliberately don't block up front). A resulting `InvalidVariationAxisException` is translated into `VariationNotRestorableException::becauseItsCombinationIsNoLongerValid()`.
5. `Variation::reviveFromArchive()` — ARCHIVED → DRAFT. `id`/`sku`/`barcode`/`attributeAssignments()`/`attributeSignature()` stay completely untouched; `is_visible`/`is_purchasable` **stay false** (`archive()` forced both false; revival does not silently restore them — the merchant re-enables them explicitly, e.g. via `activate()`).

No uniqueness/signature check inside `restoreArchivedVariation()`
itself: flipping an *existing* row's status can never create a
duplicate signature, so the DB `UNIQUE(product_id, attribute_signature)`
index (§3.1) remains the sole authoritative guarantee, unaffected.

This operation now also has an HTTP surface at
`POST /api/variations/{variationId}/restore` (app layer,
`staff.can:product_manage`, any domain refusal returned as a 422 carrying
the exception's own message).

**Deliberately not built:** partial/optional axis combinations
(WooCommerce-style "any value" on an axis, where a variation need not
supply every declared axis). `assertValidCombination()`'s "every
declared axis must be supplied" rule (§3.5) is unchanged by this pass —
redefining it to allow partial combinations would also require
redefining the uniqueness strategy (§3.1, `VariationSignature`'s
determinism assumes a complete, fixed set of assignments per
variation) and is out of scope here.

### 3.18 Product timeline: `timeline_at`, promote/unpromote ("Избутай напред")

**Decision:** `catalog_products.timeline_at` is a product's EFFECTIVE
position in the merchant-facing product timeline — the single column
both the admin listing and a future storefront ordering will sort by.
Always equal to `created_at` unless a merchant explicitly promotes the
product; NOT NULL, indexed `(timeline_at, id)`. Ordering is always a
plain `ORDER BY timeline_at DESC, id DESC` — no `COALESCE`, no second
"is this promoted" source of truth to keep in sync.

**Why not `updated_at`:** `updated_at` changes on every ordinary edit
(a description tweak, a price change, a photo swap) — using it as a
timeline signal would silently "bump" a product to the front every
time a merchant fixes a typo, the opposite of the deliberate,
merchant-chosen "move to front" this feature actually is (§D7-style
rule: no automatic promotion, ever — see below).

**Why not a nullable `promoted_at` + `COALESCE(promoted_at, created_at)`
at read time:** two real, concrete costs, not a style preference.
(1) `COALESCE(...)` is a non-indexable expression — MySQL cannot use a
plain B-tree index to satisfy `ORDER BY COALESCE(promoted_at,
created_at) DESC`, forcing a filesort on every listing render as the
catalog grows, exactly the kind of query the `(timeline_at, id)` index
here is built to avoid. (2) it is two columns that can, by construction,
independently drift — a place `promoted_at` and `created_at` could end
up mutually inconsistent with each other is a bug surface `timeline_at`
alone (a single column, single source of truth) cannot have.

**`Product` API (pure PHP, no framework import — CLAUDE.md rule 1):**
- `createdAt(): DateTimeImmutable` — added as prerequisite
  infrastructure; the entity did not previously expose this at all
  (confirmed by reading the class before this pass, not assumed).
- `timelineAt(): DateTimeImmutable`.
- `isPromoted(): bool` — `timelineAt() > createdAt()`, strict. EDGE
  CASE, deliberate: a promotion landing within the SAME SECOND as
  creation is indistinguishable from "not promoted" by this comparison
  — harmless, since the timeline POSITION is identical either way;
  nothing about ordering or display behaves differently for that
  one-second window.
- `promote(DateTimeImmutable $at): void` — fails loud
  (`InvalidArgumentException`) if `$at < createdAt()`: promoting to a
  point before the product was created would place it timeline-BEHIND
  genuinely older products it should be shown ahead of, an obviously
  wrong result no caller could have intended.
- `unpromote(): void` — resets `timelineAt` to `createdAt`,
  unconditionally (idempotent; the caller, `App\Services\
  ProductTimelinePromoter`, is what turns "already not promoted" into a
  true no-op with no write and no log entry — see admin-panel-design.md).
- The entity knows only **when** a promotion happened, never **why** —
  no reason/comment field exists or is accepted. The merchandising
  motive (a campaign, a restock, a merchant's own judgment call) is an
  app-layer/display concern, never this entity's.

**`createdAt`/`timelineAt` are optional constructor parameters,
defaulting to "now"** (a plain `new DateTimeImmutable()`, never
Laravel's `now()` — this codebase's own established
`EloquentPriceResolver::resolve()` default-fallback idiom, `$at =
$context->at ?? new DateTimeImmutable()`) — deliberately NOT
`OperationalSales\SaleLine`'s fully-required-parameter style, which is
right for a financial/audit record but unnecessarily heavy for "when
was this product made." This is what makes "a new product starts with
`timelineAt = createdAt`" true, with zero call-site changes, for every
one of this project's real Product-creation paths
(`CreateProduct`/`CreateVariableProduct`/`ProductController`/
`VariableProductController`/`DuplicateProduct` — confirmed by grep, none
of the five pass either argument). `reconstituteFromStorage()` takes
both as REQUIRED parameters instead — persisted storage always has a
real value for both, and reconstitution must never silently invent
"now" in their place.

**Persistence:** for a brand-new product, `EloquentProductRepository`
sets `created_at` explicitly from the domain's own in-memory value
before `save()` (making the attribute "dirty"), rather than leaving it
to Eloquent's own auto-timestamp — confirmed against the installed
Eloquent source (`HasTimestamps::updateTimestamps()` skips its own
`freshTimestamp()` for an already-dirty `created_at`), which is what
guarantees `timeline_at` is byte-identical to `created_at` for a new
row, not merely "close enough" between two independently-computed
`now()` calls milliseconds apart. `timeline_at` itself is written
unconditionally on every `save()`, new or existing.

**No automatic promotion, ever:** no observer, no hook, nothing fires
`promote()` as a side effect of any other edit (an image upload, a
price change, a status transition). The only caller is the explicit
merchant action — see admin-panel-design.md's own entry for the row
actions.

### 3.19 Deletion and axis restructuring

**Status: stages 1–4 implemented.** Stage 1 was this document; stage 2
(§3.19.12 item 2) shipped as
`Admin: permanently delete a variation from EditVariableProduct`; stage 3
(item 3) shipped as
`CatalogDeletion: delete an ARCHIVED product with all of its variations` and
`Admin: permanently delete an ARCHIVED product from ViewProduct and the list`;
stage 4 (item 4) shipped as
`Admin: change the variation axes from the Axes tab`. §3.19.12 records what
each stage delivered, and §3.19.3, §3.19.8 B and §3.19.8 C each carry the
places the implementation deliberately deviates from this section's own
first wording — three of them now, rather than two.

It designs the four operations the domain
owner asked for and that §3.17 left out of scope: deleting a STANDARD
variation, deleting an ARCHIVED product, and an admin "change axes" flow
that restructures a VARIABLE product's axes by deleting or archiving
whatever blocks the change. It amends CLAUDE.md rule 4 (§3.19.1) and
narrows §7's own closing statement that `softDeletes()` is "the actual
'removal' mechanism" — that stays true for every record with history,
and stops being true for a record referenced only by configuration.

**Why this needs designing at all, when this codebase says "never hard
delete":** `catalog_variations.sku`, `.barcode`,
`UNIQUE(product_id, attribute_signature)`, `catalog_products.base_sku`
and `.slug` are real `UNIQUE` indexes. Soft-deleting or archiving a row
frees **none** of them — the row still occupies each index entry
forever. So today a mistyped `base_sku` on an abandoned draft product is
unusable for all time, and a variation whose combination is re-added is
forced down §3.9's revival path with its original id and SKU rather than
becoming a new one. G-D8 deliberately frees them: an identifier belongs
to a physical/commercial fact, and once that fact is withdrawn the
identifier is reusable.

#### 3.19.1 CLAUDE.md rule 4, amended — history vs. configuration

The prohibition is re-scoped from *what a record is* to *what references
it*: a record with history is never deleted; a record referenced only by
configuration may be, through the sanctioned operations below and nothing
else. Rule 4's exact new text:

```
4. **Historical identity is never destroyed or reassigned.** No hard
   delete of any record that has HISTORY - history meaning a financial
   record references it by id, primarily an
   `operational_sales_sale_lines` row (`priceable_id`, any type: sale,
   refund, reservation, settlement). Such a record is never deleted and
   never orphaned: soft-delete / archive-status / append-only-new-row
   patterns only. A record referenced ONLY by configuration (a
   `pricing_price_list_items` row, a `pricing_product_costs` row, a
   `cart_lines` row, a product-scoped `promotion_scopes` /
   `pricing_price_list_scopes` row, a media or taxonomy pivot) is not
   protected by this rule and may be hard-deleted - but only through the
   sanctioned deletion operations, and only after the app-layer
   deletability check in catalog-domain-design.md §3.19. See Catalog's
   Variation lifecycle and operational-sales-domain-design.md §3.2
   (SaleLine immutability - a correction is always a NEW row referencing
   the old one, never an in-place rewrite).
```

**The test is "does a financial record reference it", not "is it
important".** A `pricing_product_costs` row can be worth real money and
is still configuration: it describes what the merchant *intends* to pay,
and a deleted variation's cost row is regenerated the moment the
variation is. A sale line is the opposite: it records money that already
changed hands, so it wins over any configuration need.

**Why the rule must be amended rather than bypassed once:** the old text
("no hard deletes of anything another domain might reference by id") is
satisfiable only by never deleting anything a *future* domain might want
to reference — which is every row. That is what made §3.19's three
operations structurally impossible; G-D1 replaces it with a check against
the references that actually exist (the map in §3.19.2).

#### 3.19.2 The reference map — every table that can reference a product or variation

Verified by reading every migration in the repository, not inferred — the
same exercise `PruneProductsToOriginal` performed for its own narrower
purpose. "History" / "configuration" is the G-D1 classification.

| Referencing table.column | Kind of reference | Classification |
|---|---|---|
| `operational_sales_sale_lines.priceable_id` | **history** — a SALE / REFUND / RESERVATION / INSTALLMENT_PAYMENT line; no FK, by design (§1) | **HISTORY — never deleted, and never deleted *around*** |
| `operational_sales_sale_lines.product_name` / `.sku` / `.sold_attributes` / `.unit_cost` | the §3.12/§3.13 snapshot, not an id | not a reference — and the reason history stays *readable* after a delete |
| `catalog_variations.product_id` | the variation's own parent, `restrictOnDelete()` | configuration, structural — deleted last, by the operation (FK) |
| `catalog_product_attributes.product_id` | axis declarations + descriptive attributes, `cascadeOnDelete()` | configuration — DB-cascaded |
| `catalog_product_axis_values.product_id` | enabled values per declared axis, `cascadeOnDelete()` | configuration — DB-cascaded |
| `catalog_product_categories.product_id`, `catalog_product_tags.product_id` | taxonomy pivots, `cascadeOnDelete()` | configuration — DB-cascaded |
| `catalog_product_media.product_id`, `catalog_variation_media.variation_id` | media pivots, `cascadeOnDelete()` | configuration — DB-cascaded (the *assets* are not: §3.19.7) |
| `catalog_variation_attribute_values.variation_id` | the variation's own combination rows, `cascadeOnDelete()` | configuration — DB-cascaded |
| `stock_levels.variation_id` | the variation's stock, `restrictOnDelete()`, UNIQUE | configuration — **explicit delete** + the concurrency lock (§3.19.5) |
| `cart_lines.variation_id` | a basket line, `restrictOnDelete()` | configuration — **explicit delete**, converted carts included (§3.19.4) |
| `pricing_price_list_items.target_id` (`target_type = variation \| product`) | which list prices this variation/product; no FK, by design | configuration — **explicit delete** |
| `pricing_product_costs.priceable_id` | the variation's cost; no FK, by design | configuration — **explicit delete** |
| `pricing_price_list_scopes.scope_reference_id` (`scope_type = product`) | which list applies *because of this product* | configuration — **explicit delete** (product deletion only) |
| `promotions_promotion_scopes.scope_reference_id` (`scope_type = product`) | which promotion applies because of this product | configuration — **explicit delete** (product deletion only) |
| `activity_log.entity_type` / `.entity_id` (`'product'` today) | the log entry *about* the record; plain strings, **no FK** | neither — deliberately retained (§3.19.10) |
| `catalog_media` rows and physical files | **not** a child of a product (only the pivots are) | neither — orphaned by design, §3.19.7 |

**Checked and cleared explicitly, so this is a map and not a guess:**
`catalog_products.brand_id` / `.size_guide_id` / `.season_id` /
`.product_group_id` point *from* the product outward;
`catalog_product_templates` references brand/season/product-group plus
JSON category/tag id arrays — it is a Create-form pre-fill, not a live
link, and no migration ever adds a `template_id` to `catalog_products`;
`catalog_brands.logo_media_asset_id` points at `catalog_media`, never at
a product; `orders` references client/transaction/account/address only,
with all line detail in `operational_sales_sale_lines`;
`promotion_redemptions` references promotion/order/account;
`payments` / `payment_refunds` reference orders and payments. Nothing in
this list is "unclear".

**One real gap this map exposes in existing tooling — reported, not fixed
here:** `PruneProductsToOriginal` deletes `stock_levels`,
`pricing_price_list_items` and the catalog rows, but **not**
`pricing_product_costs`, `pricing_price_list_scopes` or
`promotion_scopes`. After a prune run today those three tables keep rows
pointing at products/variations that no longer exist. Stage 2
(§3.19.12) fixes the command as part of extracting the shared gate —
otherwise the admin deletion path would inherit the same omission.

**A second difference in that command, reported for the same reason:** its
gate has *two* parts — it aborts on a doomed variation's sale lines **and**
on its `cart_lines` rows. Only the first is history. The cart-line half is
a scope-specific safety net for that command's much broader "delete
everything except the 6 oldest" job, where aborting is clearly safer than
silently emptying merchants' baskets; the admin path instead treats cart
lines as configuration and deletes them (§3.19.4), because its scope is
one variation the merchant explicitly chose and confirmed. So "one rule,
not two" holds for the **history** rule — after stage 2 the sale-line half
is `variationIdsWithHistory()` in both places — while the command keeps
its cart-line abort as an explicitly separate, scope-specific guard.
Flattening both into one predicate would give the same function two
different meanings at its two call sites.

#### 3.19.3 Where each piece lives

**Domain — `Product` (the aggregate).** One new operation,
`Product::removeStandardVariation(Variation $variation): void`:

1. `$variation` must already belong to this product (`LogicException`
   otherwise — the same rule §3.7's `changeVariationCombination()`
   enforces).
2. It must be `STANDARD` (`LogicException` for `UNIVERSAL` — G-D2: a
   UNIVERSAL variation is only ever deleted *together with* its
   product).
3. It drops the variation from `$this->variations`, leaving the aggregate
   self-consistent so a caller can immediately `declareVariationAxes()`
   and generate the replacements inside the same instance (that ordering
   is what the change-axes flow needs).

**Deliberately NOT in the domain:** the history and stock checks. The
aggregate cannot see them — Catalog must never query
`operational_sales_sale_lines` or `stock_levels` (CLAUDE.md rule 1;
`operational-sales-domain-design.md` §1). #3 above also does not delete
anything: `EloquentProductRepository::save()` upserts the variations it is
given and **never** removes rows absent from the aggregate (verified — it
has no delete path at all), so "removed from the aggregate" and "row
gone" are two different, separately-authorized steps. That is deliberate:
the physical delete is the part that needs the history check, so it must
not be reachable by a plain `save()`.

**Repository — `ProductRepository` (Catalog-owned rows only).** Two new
methods, both `withTrashed()` + force-delete, and both touching
`catalog_*` tables exclusively:

- `deleteVariation(Variation $variation): void` — force-deletes the
  `catalog_variations` row, letting the DB cascade
  `catalog_variation_attribute_values` and `catalog_variation_media`.
- `delete(Product $product): void` — force-deletes **every one of the
  product's own `catalog_variations` rows first** (`withTrashed()`, so an
  ARCHIVED row — a real row — and a *soft-deleted* row go alike: a
  soft-deleted variation has no domain object for a caller to hand to
  `deleteVariation()`), and then the `catalog_products` row, cascading
  `catalog_product_attributes`, `_axis_values`, `_categories`, `_tags`,
  `_media`. **Both statements are `withTrashed()` force-deletes and both
  touch `catalog_*` tables only** — no cross-domain table is reachable from
  here.

  **As implemented, the repository clears its own way** rather than
  requiring its caller to have deleted the variations already (this bullet's
  first wording): that is what keeps
  `catalog_variations.product_id`'s `restrictOnDelete()` from ever blocking
  the product row, and it is the only way a soft-deleted variation can be
  swept up at all. The order inside
  `CatalogDeletion::deleteProduct()` is unchanged by that: it runs the
  per-variation **cross-domain configuration deletes** (§3.19.4 step 1 —
  `stock_levels`, `cart_lines`, variation-target
  `pricing_price_list_items`, `pricing_product_costs`) for every variation
  *before* handing the aggregate to this method, and the product-scope rows
  (product-target price items, `pricing_price_list_scopes`,
  `promotion_scopes`) before it too, all in the same transaction.

Neither may touch `stock_levels`, `cart_lines`,
`pricing_price_list_items`, `pricing_product_costs`, `promotion_scopes`
or `pricing_price_list_scopes` — a Catalog class referencing those would
break package isolation. They are deleted by the app layer below, in the
same transaction.

**App layer — ONE deletability/deletion service,
`App\Services\CatalogDeletion`.** The single place any history check
across domains lives, for the same reason `OrderAdminReader` is the single
place the Orders admin's cross-table reads live (and
`CheckoutOrchestrator` the single place checkout's cross-domain writes
do):

- `variationIdsWithHistory(array $variationIds): array<string, int>` —
  sale-line counts per variation id (`priceable_id`, any type, any
  status, **including soft-deleted lines**). This *is* the one rule
  CLAUDE.md rule 4 now names; `PruneProductsToOriginal`'s own inline
  `operational_sales_sale_lines` gate is replaced by a call to it
  (stage 2), so the command and the admin path cannot drift apart.
- `impactForVariation(string $variationId): VariationDeletionImpact` and
  `impactForProduct(string $productId): ProductDeletionImpact` — read-only
  reports, no writes, safe to render in the confirmation modal
  (§3.19.8). Each carries: deletable or not, the refusal reason, the
  history count, the stock quantity, and the configuration row counts
  that would be removed (cart lines, price items, costs, media pivots).
  The product impact additionally splits its variations into
  **delete** (no history *and* stock 0) and **archive** (history or
  stock > 0) — the exact lists G-D4's modal shows.
- `deleteVariation(string $variationId): void` /
  `deleteProduct(string $productId): void` — re-run every check inside
  their own transaction after taking the locks (§3.19.5), record the
  activity-log snapshot (§3.19.10), then execute §3.19.4.

The impact objects and the delete methods are one service on purpose: a
second caller computing "is this deletable?" separately is exactly the
drift `variationIdsWithHistory()` exists to prevent. Authorization
(`permission::PRODUCT_DELETE`, §3.19.9) is *not* checked here — the
service enforces the invariant, the UI/HTTP layer enforces who may ask.

#### 3.19.4 The deletion order

Everything below happens inside ONE transaction (§3.19.5). "Explicit"
means the app-layer service issues the delete itself, because the row is
either cross-domain (Catalog must not know it exists) or a deliberate
guard against the DB's own `restrictOnDelete()`. "Cascade" means the
database does it when the catalog row is force-deleted, and it is
re-verified after execution rather than assumed — the same
prove-it-didn't-just-look-right posture `PruneProductsToOriginal`'s own
final-state report already takes.

**Deleting a STANDARD variation `V` of product `P`:**

| # | Table | How |
|---|---|---|
| 1 | `stock_levels` `WHERE variation_id = V` | explicit |
| 2 | `cart_lines` `WHERE variation_id = V` | explicit |
| 3 | `pricing_price_list_items` `WHERE target_type = 'variation' AND target_id = V` | explicit |
| 4 | `pricing_product_costs` `WHERE priceable_id = V` | explicit |
| 5 | `catalog_variations` row `V` | explicit, `withTrashed()` + `forceDelete()` |
| 6 | `catalog_variation_attribute_values`, `catalog_variation_media` | cascade from #5 |

`catalog_product_axis_values` is **not** touched: deleting one variation
does not remove the axis value that produced it — the merchant may still
want a *different* combination using it.

**Deleting an ARCHIVED product `P`** (only reachable when every one of
its variations passes the same two checks):

| # | Table | How |
|---|---|---|
| 1 | for **every** variation of `P` — UNIVERSAL, live STANDARD, ARCHIVED and soft-deleted alike, in ascending variation id — the whole variation sequence above (its steps 1-4, then its force-delete) | explicit |
| 2 | `pricing_price_list_items` `WHERE target_type = 'product' AND target_id = P` | explicit |
| 3 | `pricing_price_list_scopes` `WHERE scope_type = 'product' AND scope_reference_id = P` | explicit |
| 4 | `promotion_scopes` `WHERE scope_type = 'product' AND scope_reference_id = P` | explicit |
| 5 | `catalog_variations` rows of `P` | explicit, `withTrashed()` + `forceDelete()` — **before** the product row, because `catalog_variations.product_id` is `restrictOnDelete()` |
| 6 | `catalog_products` row `P` | explicit, `withTrashed()` + `forceDelete()` |
| 7 | `catalog_product_attributes`, `_axis_values`, `_categories`, `_tags`, `_media` | cascade from #6 |

**Every table is deleted in exactly one place.** Step 1 *is* the variation
sequence above, applied once per variation — and that sequence is already
where `stock_levels`, `cart_lines`, variation-target
`pricing_price_list_items` and `pricing_product_costs` are deleted. The
product path therefore adds only the rows that are *product*-scoped, and
never a second, parallel delete of the same table: two definitions of "how a
variation's configuration goes away" would be two things to keep in step.

**`forceDelete()`, and `withTrashed()`, are not optional.** `ProductModel`
and `VariationModel` both use `SoftDeletes`, so a plain `delete()` only
sets `deleted_at`: the row survives, keeps occupying `sku` / `barcode` /
`base_sku` / `slug` / `(product_id, attribute_signature)` (the whole point
of G-D8 fails), and still blocks `catalog_variations.product_id`'s
restrict FK. A *already* soft-deleted variation is therefore included by
`withTrashed()` and force-deleted with the rest — which is also what makes
"delete an archived product" (an archived variation is a real row, not a
deleted one) coherent.

**`cart_lines` are removed for open and converted carts alike.**
`cart_lines.variation_id` is `restrictOnDelete()`, so a converted cart
(checkout only sets `carts.order_id`; it never clears or copies the lines
— verified) would otherwise block the delete forever. These rows are
configuration under G-D1: a cart is a transient basket, and everything
financial about what was bought already lives in the Order and its
SaleLines, which carry their own product name / SKU / unit prices /
discount shares / sold attributes snapshot (§3.12, §3.13). Nothing
historical is lost by removing the basket line — but the impact summary
(§3.19.8) states the count and how many of those carts were already
converted, so it is never a silent side effect.

**Not deleted, deliberately:** `activity_log` rows (§3.19.10) and
`catalog_media` rows/files (§3.19.7).

#### 3.19.5 Concurrency — the check must not be stale

The failure this closes: between "this variation has no history and zero
stock" and the DELETE, a checkout sells it. The sale line is written and
the variation row is gone — history referencing a variation that no
longer exists, which is the exact prohibition rule 4 states, achieved by
a race rather than by intent.

**Verified facts this design builds on:** `CheckoutOrchestrator::
placeWithinTransaction()` runs inside one transaction and decrements
stock (step 7, `StockLevelRepository::decrease()`) **before** it writes
the Transaction and the SALE SaleLines (step 8), so for one variation the
stock decrement and its history row commit or roll back together.
`decrease()` is a conditional UPDATE — `UPDATE stock_levels SET quantity
= quantity - n WHERE variation_id = ? AND quantity >= n` — which takes an
exclusive row lock on that `stock_levels` row and holds it until the
checkout commits. `stock_levels.variation_id` is UNIQUE, so that lock is
exactly one row, never a gap-lock cascade.

**Locking rules.**

1. **The stock row is locked first, and that same statement performs the
   stock check:** `SELECT quantity FROM stock_levels WHERE variation_id =
   ? FOR UPDATE`. When no row exists, MySQL's unique-index equality read
   takes a gap lock on the missing key, so a concurrent
   `increase()`/`save()` cannot create the row underneath the decision;
   "no row" is then read as quantity 0 — `StockLevelRepository::
   findByVariationId()`'s own documented "no row and zero are the same
   fact" rule, not a new interpretation.
2. **The history check must be a locking read:** `SELECT id FROM
   operational_sales_sale_lines WHERE priceable_id = ? FOR UPDATE`. A
   plain `SELECT COUNT(*)` is *not* sufficient: under MySQL's default
   REPEATABLE READ the consistent snapshot is fixed by the transaction's
   first non-locking read, so a sale line committed after that snapshot
   but before our lock was granted would be invisible and we would delete
   anyway. A locking read always reads the latest committed version, and
   it additionally blocks a concurrent INSERT of a new sale line for this
   variation (insert-intention locks conflict with the gap/next-key lock
   the scan holds).
3. **`priceable_id` therefore needs an index.** It has none today — the
   `os_sale_lines_*` indexes cover `transaction_id`, `(client_id, type,
   status)`, `installment_plan_id` and the FKs — so rule 2's `FOR UPDATE`
   would scan and lock the whole sale-lines table. Stage 2 adds a plain
   `INDEX operational_sales_sale_lines.priceable_id`, which also makes
   the count O(log n) instead of a full scan. This is the only schema
   change this design requires.
4. **Multi-variation operations take their locks in ascending
   `catalog_variations.id` order**, and the transaction is wrapped with a
   bounded deadlock retry (Laravel's `DB::transaction($closure, attempts:
   3)`). A concurrent checkout locks one stock row at a time in cart-line
   order and cannot be made to observe our ordering, so deterministic
   ordering plus retry is the honest answer — "deadlock impossible" is
   not claimable here and is not claimed.

**How this serializes with checkout, both ways round.**

- *Deletion first:* it holds the stock row's exclusive lock. The
  checkout's step-7 UPDATE blocks; when the deletion commits the row is
  gone, so the UPDATE matches zero rows →
  `InsufficientStockException` → the entire checkout transaction rolls
  back: no order, no sale line, no payment.
- *Checkout first:* it holds that lock and commits its sale line before
  releasing it. The deletion's rule-1 read blocks until then, and rules 1
  and 2 then read the committed state — stock is now 0 (not itself a
  refusal) but the history count is ≥ 1, so the deletion is refused with
  the sale-line count and archiving is offered instead (G-D2). Nothing is
  deleted and nothing is orphaned.

**What is deliberately not locked, and what protects it instead:**
`cart_lines` insertions are not serialized against the deletion —
`CartLineAdder` reads the variation without a lock, so a line can land
either side of our cart-line delete. The protection is the schema, not a
lock: `cart_lines.variation_id` is `restrictOnDelete()`, so whichever
order the two transactions commit in, one of them fails loudly — our
`catalog_variations` delete hits the FK restriction and the whole
deletion rolls back (the merchant retries and the recomputed impact
already accounts for the new line), or the cart insert hits it and the
cart add fails. Never a silent orphan; this is CLAUDE.md rule 2 doing
exactly the job it exists for. `catalog_variations` itself is not locked
up front: its row lock comes from the `forceDelete`, which is the last
step, by which point the verdict is already computed.

#### 3.19.6 After a delete: no revival, a genuinely new variation

§3.9's implicit revival-by-signature is triggered by
`addStandardVariation()` finding an **archived** variation with the same
`(product_id, attribute_signature)` — `findArchivedVariationBySignature()`
reads storage. After a hard delete that row does not exist, so re-adding
the same combination creates a **new** variation: new `id`, and its
`sku`/`barcode` are whatever the caller supplies or the
`catalog.variation.sku` hook generates. Nothing has to be changed to make
this true, and nothing is added that could make it a revival.

`restoreArchivedVariation()` (§3.17) is untouched and strictly narrower:
it operates on an in-memory `Variation` instance that must currently
exist and be `ARCHIVED`, so a deleted variation is not reachable from the
Restore action at all — it has no instance to offer.

The merchant-facing consequence is that "archive then restore" and
"delete" are two genuinely different offers, and the UI must say which
one it is doing (§3.19.8): archiving keeps the id, SKU, barcode **and**
the combination's claim on the uniqueness index; deleting frees all four,
so the combination becomes creatable as a new variation. That is the
whole reason G-D8 exists.

§3.17's R2/R3/R4 guards are unaffected, and this is the mechanism that
makes the change-axes flow possible at all: they collect **live**
(non-archived) STANDARD variations only, so deleting a live variation —
or archiving one — removes it from that collection and the axis may then
be re-declared.

#### 3.19.7 The media gap — deliberately not solved here

Deleting a product or variation removes the `catalog_product_media` /
`catalog_variation_media` **pivot** rows (DB cascade). It does **not**
delete the `catalog_media` rows or the physical files: `catalog_media`
has no FK to a product, because an asset may be legitimately shared by
several products and by a brand logo. This is the already-tracked gap in
`media-cleanup-and-storage-optimization-note.md` §1, and the same gap
`PruneProductsToOriginal`'s own docblock flags for its own deletion path.

It is not solved here, and specifically not by deleting every
`catalog_media` row a removed pivot pointed at — that would delete assets
still in use elsewhere. What the deletion does provide is the media count
in both the impact summary and the activity-log snapshot, so orphaned
assets are at least discoverable by the future cleanup UI. Solving it is
that note's work, not this section's.

#### 3.19.8 The admin UX (described, not built)

**A — Delete a variation.** A per-row action on `EditVariableProduct`'s
Variations tab, next to (never instead of) the archive action already
there. That existing per-row action is an *archive* — it is labelled
`products.variation_archive.button_label` precisely because it removes the
row from the submitted set and the save path then archives it — so the
delete must be an additional, differently-labelled action. Visible with
`PRODUCT_DELETE`; when the impact says not deletable, the action is
disabled with the reason shown and the archive action is the offered
alternative. The modal shows: the SKU and the attribute combination
(`attributeAssignments()`); "no sales history", or the count if it changed
between render and submit; the stock quantity; the configuration that
will also be removed (N cart lines, of which M in already-converted
carts; N price-list items; N cost rows; N media attachments — **the files
are not deleted**); the explicit "this cannot be undone" checkbox; and a
field requiring the variation's SKU to be typed (G-D6).

**B — Delete a product.** On `ViewProduct`'s header (and mirrored as a
list row action), offered only when the product is `ARCHIVED`. On a
non-archived product the same slot offers one line explaining the two-step
rule (G-D3) — a disabled button with no reason is exactly what this
codebase avoids — together with a real first step to take.

**As implemented, that first step is not a separate archive action, and
that is a deliberate deviation from this paragraph's first wording.** The
admin panel has **no Archive action on `ViewProduct`** at all: archiving is
the Edit page's `Status` field (`EditProduct::handleRecordUpdate()`, which
also runs `ArchiveProductMediaCleaner` and logs the change). So the
non-archived half is a single action, `archive_first`, visible with
`PRODUCT_DELETE` on a non-archived product: it states the rule in one line
and redirects to the product's own Edit page (`edit` for SIMPLE,
`edit-variable` for VARIABLE) — the real first step, reached without
introducing a second archive code path that would have to be kept in step
with the first. The modal shows every
variation with its own verdict, the product-scope rows that would go
(price-list scopes, promotion scopes), the aggregate cart-line /
price-item / cost / media counts, the `base_sku` and `slug` that will
become reusable, the checkbox, and a field requiring the **base SKU**.
Because G-D3 refuses when any variation must be archived, this list is in
practice all-delete or the operation is refused — the modal says which.

**C — Change axes.** On the existing Axes tab. Before this change, a
refused submission simply surfaced `UnsafeAxisRedeclarationException` as an
error notification; the
change is that a submission which *would* be refused first shows an impact
step: current axes vs submitted axes, then two lists — **will be
deleted** (live STANDARD variations, no history, stock 0) and **will be
archived** (history or stock > 0) — with counts, and, when either list is
non-empty, the visible warning that listed variations and their
identifiers are permanently removed. On confirm, in one transaction:
archive the second list, delete the first *through* `CatalogDeletion` (so
the checks re-run inside the same locking window), then re-declare the
axes through the unchanged `declareVariationAxes()`, then create the new
combinations with the existing generation path
(`catalog.variation.sku` hook + `VariationCombinationGenerator`). A
variation in the "will be archived" list whose value is being removed
stays archived **and unrestorable** — §3.17's documented trade-off — and
the modal says so up front rather than letting the merchant discover it
when the Restore action later refuses.

**Amended by the implementation — D1 and D3.** Two deliberate departures
from the wording above, recorded rather than silently made:

- **D1 — a dedicated action, not an interception of the tab's Save.** The
  Axes tab gains a "Change axes" action whose modal is the three steps in
  order: the new axes (the tab's own inputs), the impact, the confirmation.
  The tab's Save is unchanged in shape — still refusing an unsafe
  re-declaration, with its refusal now naming the action — and a SAFE change
  (R1's identical set, or R5's value addition) still goes through Save with
  no modal at all. Intercepting Save instead would have meant either
  hijacking a submission that carries every other field on the page (and
  then deciding what happens to those edits when the merchant cancels), or
  silently saving a subset of it; a refusal-triggered interception is also
  not re-editable, which an impact step has to be. The action needs
  `PRODUCT_MANAGE` like the rest of the page, and its modal states that
  unsaved edits elsewhere on the page are discarded, because the page
  reloads from the database when it finishes.
- **D3 — without `PRODUCT_DELETE`, the first list is ARCHIVED instead.** The
  change is reachable for a Manager who may not delete (§3.19.9 grants that
  capability to Administrator only): nothing is deleted, every blocker is
  archived, their SKUs stay occupied, and the modal says exactly that. The
  restructure service takes the capability as an argument and never reads
  permissions itself (§3.19.9's own boundary), while the caller computes it
  from the authenticated staff member — never from submitted data.

**The confirmed plan is the executed plan — the fingerprint rule.** The
impact report carries a `fingerprint` of the plan it describes: a hash of the
proposed axes (per definition, its allowed value ids — order never matters)
plus the SORTED variation ids of the "will be deleted" and "will be archived"
lists. The dialog carries that fingerprint along with the impact it is
showing, and `apply()` is given it as the plan the merchant confirmed. It
re-derives the plan inside its own transaction exactly as described above and
REFUSES, changing nothing, when the two fingerprints differ — the new
`PLAN_CHANGED` reason, whose sentence is "the product changed since you opened
this dialog — reopen it to see the current impact". That closes the one
window this section's first wording left open: a variation added, archived or
deleted between the impact and the apply used to be executed against a
confirmation the merchant never saw. Nothing about the fingerprint is a
secret, and nothing about it is trusted for safety: a fabricated value can
only cause a refusal, because the lists that are deleted and archived are the
ones `apply()` derives itself — and §3.17's guard is the second net under the
same fact, refusing the re-declaration if a live variation the plan never saw
would be orphaned (the same `PLAN_CHANGED` answer, discovered one step later).

ONE further implementation fact worth recording: the impact deliberately does
NOT count the combinations the change will generate. That number would be a
promise about a cartesian product, whereas the real created/restored counts
are reported after the fact by the same generation path the modal's own text
describes.

**The doc's own ORDER of the two lists is the other way round in practice:**
the "will be deleted" list is deleted FIRST, and the archive happens
afterwards, on the aggregate re-loaded after those deletions — because that
same aggregate is the one the archiving and the re-declaration share (one
write pass instead of two, and one load fewer). Deleting second would give
the identical result, since `CatalogDeletion::deleteVariation()` checks and
writes inside its own transaction either way; nothing about the two lists'
own semantics depends on the order.

**D — Refusal messages are the domain's own.** Each refusal is shown
verbatim, the same posture `VariationController` already takes for
`VariationNotRestorableException`, never a generic failure: history —
"Variation {sku} has {n} sale line(s) and cannot be deleted. Archive it
instead."; stock — "Variation {sku} still has {n} in stock. Set stock to 0,
or archive it instead."; not archived — "Only an archived product can be
deleted. Archive {name} first."; blocked variation — "Variation {sku} has
{n} sale line(s), so {name} cannot be deleted."

**E — i18n:** new keys under `products.deletion.*` in `lang/en` and
`lang/bg`, beside the existing `products.variation_archive.*` keys.

#### 3.19.9 Permission

A new dedicated permission, **`Permission::PRODUCT_DELETE`
(`'product_delete'`)** — a capability the code actually enforces, in the
existing vocabulary's shape. Granted by `StaffSystemRolesSeeder` to
**Administrator only** (G-D5): a hard delete destroys configuration and
frees identifiers, so it does not ship enabled for Manager — but it is
**grantable to Manager through the Role editor**, which is what "grantable
to Manager" means.

**Already documented, not deferred:**
`staff-access-domain-design.md` §3 (the permission list), §3.1 (why it is
split from `PRODUCT_MANAGE`) and §4.1 (the three roles' granted/withheld
lists and the side-by-side matrix) are updated in this same change, not in
stage 5. §12.1 there also records the one real consequence for
implementation: system roles' permissions are currently not editable
(`Role::updatePermissions()` throws `CannotModifySystemRoleException`, and
`RoleResource::canEdit()` is false for a system role), so making
"grantable to Manager" reachable needs either permission-level editing of
a system role or a copy-to-custom-role path — with the constraint that any
such change must keep Administrator holding every permission, since it is
looked up by exact name at bootstrap. Nothing new is needed to *view* the
impact reports: they show counts and identifiers, never a cost amount, so
`PRODUCT_VIEW`/`PRODUCT_MANAGE` coverage is unchanged and there is no new
`COST_VIEW` interaction.

#### 3.19.10 Nothing silent — the activity-log snapshot, and the two gaps it closes

**G-D7's record.** Before any row is deleted, one `activity_log` entry is
written describing the whole operation: entity type and id, product name,
`base_sku`, `slug`, every variation's id, SKU, barcode, attribute
combination, status, price-list items, cost rows, stock quantity, the
cart-line / price-item / cost / media counts, and the acting staff member
and timestamp. Written through a new `ActivityLogger::logDeleted()` into
the existing `activity_log` table, whose `entity_type`/`entity_id` are
plain strings with **no foreign key** — verified — so the entry survives
the deletion it describes. That survival is the whole reason the design
depends on the log, and it is a genuine property of the schema rather than
something this design has to add.

**Decision (domain owner) — a deletion is never gated by the log setting.**
`ActivityLogger::write()` returns early unless `admin.activity_log_enabled`
is `'1'`, and that setting ships **off** (LocaleSettings' own Tab 2) — so
under the existing gate a deletion snapshot on a default installation would
silently record nothing at all, making G-D7 a promise the code does not
keep. `ActivityLogger::logDeleted()` therefore writes **regardless** of that
setting, and it is the only method that does. Routine field-change telemetry
staying opt-in is fine; the record that a product or variation *was
destroyed* is not telemetry — it is the only remaining trace of the row, and
the operation cannot be undone. The setting's own purpose ("don't log every
edit unless asked") is untouched.

**Decision (domain owner) — deletion snapshots are never pruned.**
`activity-log:prune` deletes rows older than a configured retention period
(months), which would take a deletion snapshot with it: the record of *what
was destroyed* would expire while the sale lines it was reconciled against
never do. The command therefore excludes rows with `action = 'deleted'`
from age-based pruning — a handful of rows per store's lifetime, against
losing the only record of a destroyed identifier. The command's own
docblock carries this exception explicitly, not implicitly.

**A third, smaller caveat, reported rather than solved:** `entity_id`
holds the product/variation **id**, and ids are not guaranteed unique
across time once rows are hard-deleted — InnoDB recomputes a table's
auto-increment counter as `max(id) + 1`, so removing the highest-id row
(MariaDB, or MySQL after a restore/`ALTER`) can let a *different*, later
record take the same id. That is precisely why the snapshot above carries
the SKU(s), `base_sku` and slug as well: the identifiers, not the
surrogate id, are what the log entry must be readable by. Worth stating
plainly because it is the second reason not to treat `entity_id` as a
stable key.

#### 3.19.11 Identifiers are freed on purpose (G-D8) — the verified mechanics

| Identifier | Generator today | What a hard delete frees |
|---|---|---|
| `catalog_products.base_sku` | `catalog.product.base_sku` hook → `SkuSequenceRepository::next()`, a persisted monotonic counter (`catalog_sku_sequence`); a merchant-typed value is returned unchanged | the `UNIQUE` entry. The generator will never *re-issue* the value (its counter only moves up); a merchant may type it again freely |
| `catalog_products.slug` | `catalog.product.slug` hook (slugified) + DB-constraint retry against `catalog_products_slug_unique` | the `UNIQUE` entry, immediately reusable by the generator's own retry |
| `catalog_variations.sku` | `catalog.variation.sku` hook → `{baseSku}-{n}`, `n = count($product->variations()) + 1`, explicitly *best-effort*, with the authoritative retry against `catalog_variations_sku_unique` | the `UNIQUE` entry **and** the position in that count — so the next generated SKU can be exactly the deleted one (a product whose 3rd variation was deleted gets `-3` back for its next combination) |
| `catalog_variations.barcode` | none — caller-supplied only | the `UNIQUE` entry |
| `UNIQUE(product_id, attribute_signature)` | `VariationSignature`, deterministic | the combination's claim, so re-adding it creates a new variation (§3.19.6) rather than reviving the old one |

That last line is the mechanism, not a side effect: the reclaimed count
position is *why* deletion and archiving must stay visibly different
offers in the UI. Archiving a variation keeps its SKU occupied forever, so
"archive" is the safe action for a variation whose barcode is on a printed
label still sitting on a shelf; deleting it hands the number back and the
next generation may reuse it for a different physical item. Hence the
typed-SKU confirmation (G-D6) and the identifiers in the log snapshot
(§3.19.10) are not ceremony — with a freed barcode they are the only
things distinguishing two different physical items in the record.

**Verified, not assumed (the stage-4 task's own §0 question):** the
count-based candidate does **not** search for a free number, and `count()`
does include ARCHIVED variations, because they are real rows that keep their
SKUs. A candidate can therefore collide with an ARCHIVED variation's SKU
whenever the two happen to coincide — an archived row carrying a
merchant-typed SKU (`-3`) while a deleted row's reclaimed position is `-3`
too. That is by design, and it is handled exactly where the table above says
it is: the repository's DB-constraint retry saves the same candidate with a
numeric suffix and writes the saved value back onto the aggregate, so the
new variation gets a different, genuinely free SKU while the archived row
keeps its own. Proven by
`tests/Feature/VariationAxisRestructureTest.php`'s
`test_a_generated_sku_that_collides_with_an_archived_variations_sku_is_saved_under_a_different_sku`,
which constructs precisely that coincidence. No fix was needed. The bound to
be aware of is the retry's own: four attempts, then a loud
`RuntimeException` — which inside the restructure's transaction rolls the
whole change back and leaves the product as it was.

#### 3.19.12 Implementation stages, each with a review gate

Each stage ships its own admin surface — there is no separate final "UI
stage" that would leave the operation unreachable until the last one lands;
each stage below names the slice of §3.19.8 it delivers.

1. **This document.** `catalog-domain-design.md` §3.19, the CLAUDE.md rule
   4 amendment, the one line in `operational-sales-domain-design.md`. No
   code. *Gate: approval of this design.*
2. **Deletability + variation deletion, with its admin action.**
   `App\Services\CatalogDeletion` (`variationIdsWithHistory()`,
   `impactForVariation()` + `VariationDeletionImpact`, `deleteVariation()`),
   `Product::removeStandardVariation()`, `ProductRepository::
   deleteVariation()`, `ActivityLogger::logDeleted()` with its two
   decisions (§3.19.10) and the `activity-log:prune` exclusion, the
   `operational_sales_sale_lines.priceable_id` index migration,
   `Permission::PRODUCT_DELETE` + `StaffSystemRolesSeeder` + the data
   migration that extends an already-installed Administrator role,
   `PruneProductsToOriginal` switched onto `variationIdsWithHistory()`
   **including** its three currently-missed tables (§3.19.2), and the
   `EditVariableProduct` per-row delete action with its impact /
   refusal / confirmation modal (§3.19.8 A) plus `products.deletion.*` in
   both locales. Tests: refusal on history (including a soft-deleted sale
   line) and on non-zero stock; a successful delete leaving zero rows in
   every table of §3.19.4 and the unique indexes genuinely free — re-adding
   the combination then creating a NEW variation with a new id; the prune
   command's gate; the emitted SQL using `FOR UPDATE` on both reads, plus a
   two-connection test proving the in-transaction re-check refuses when a
   sale line is committed after the impact was computed; the permission
   migration; and the action's own authorization, refusal and typed-SKU
   paths. *Gate.* **Implemented** — commit `Admin: permanently delete a
   variation from EditVariableProduct`, with its domain, activity-log,
   prune-command, permission and service halves in the five commits before
   it. Tests live in
   `tests/Feature/CatalogDeletionTest.php` and
   `tests/Feature/EditVariableProductDeleteVariationTest.php`.
3. **Product deletion, with its admin action.** `ProductRepository::
   delete()`, `CatalogDeletion::deleteProduct()`, the ARCHIVED gate, the
   variation loop, the four product-scope/price-item deletes, and the
   `ViewProduct` header / list-row delete action with its own modal
   (§3.19.8 B). Tests: refusal for a non-archived product and for any
   variation with history or stock; success freeing `base_sku` and `slug`.
   *Gate.* **Implemented** — commits
   `CatalogDeletion: delete an ARCHIVED product with all of its variations`
   and `Admin: permanently delete an ARCHIVED product from ViewProduct and
   the list`; tests in `tests/Feature/CatalogProductDeletionTest.php` and
   `tests/Feature/ViewProductDeleteProductTest.php`.

   **What the delivered slice adds beyond this item's own list:** step 1 of
   a product deletion is stage 2's per-variation routine, called once per
   variation rather than re-implemented; every variation row is included —
   UNIVERSAL, live STANDARD, ARCHIVED and soft-deleted alike, in ascending
   `catalog_variations.id` order — with the product row, each stock row and
   the history read locked in that order before any write, and one
   `activity_log` snapshot (§3.19.10) carrying all of them. The two places
   the implementation deliberately departs from this section's first wording
   are recorded in §3.19.3 (`ProductRepository::delete()` performs the
   `withTrashed()` variation sweep itself) and §3.19.8 B (there is no
   Archive action on `ViewProduct`; the non-archived slot is
   `archive_first`). `PruneProductsToOriginal` is deliberately unchanged by
   this stage: it prunes *non-archived* products and keeps its own
   scope-specific cart-line abort, so `deleteProduct()` cannot serve it
   (§3.19.2).
4. **Change-axes flow, with its modal.** `App\Services\
   VariationAxisRestructure`: compute the two lists, archive, delete via
   `CatalogDeletion`, re-declare, generate; the Axes-tab impact step
   (§3.19.8 C). Tests: removing an axis that a live variation blocks;
   removing an `R4` value; the "archived and unrestorable" outcome stated
   in the modal. *Gate.* **Implemented** — commit
   `Admin: change the variation axes from the Axes tab`, on top of
   `Catalog: refusal types for the axes-restructure flow`. Tests live in
   `tests/Feature/VariationAxisRestructureTest.php` (the service: plans,
   permission mode, freed identifiers, unrestorable reporting, atomicity, the
   plan fingerprint — including a variation added between the impact and the
   apply — the concurrency window, the SKU-collision question §3.19.11 records)
   and
   `tests/Feature/EditVariableProductChangeAxesTest.php` (the action: what
   is rendered, what is refused, what a crafted call cannot do).

   **What the delivered slice adds beyond this item's own list.** The plan
   comes from ONE predicate — "does this variation's own combination still
   fit the new axes?" — which is exactly what §3.17's R2/R3/R4 refuse on, so
   the flow asks the domain's own `declareVariationAxes()` whether the set
   may be declared at all and never re-implements R1–R5. The deletable /
   archivable split reuses stage 3's product impact for its per-variation
   facts (history count, stock, and `CatalogDeletion`'s own refusal) instead
   of a second copy of that rule. `apply()` re-loads the product after the
   deletions before archiving and re-declaring — reusing the pre-deletion
   aggregate would leave the deleted rows in its variations array as LIVE
   objects and make the guard refuse the very change the flow exists to
   perform; a test asserts the outcome only reachable through the re-load.
   The impact's third list is §3.17's own trade-off named in advance:
   ARCHIVED variations that are restorable today and would not be after this
   change. Two deliberate simplifications are recorded in §3.19.8 C (D1's
   dedicated action, D3's archive-instead-of-delete), and the dialog and the
   operation are bound together by the plan's own FINGERPRINT: `apply()`
   refuses (`PLAN_CHANGED`) when the plan it derives inside its transaction is
   not the one the merchant was shown, so a variation added, archived or
   deleted in between changes nothing. `PruneProductsToOriginal`
   is untouched by this stage as well.

**Left out of every stage above, deliberately, and tracked elsewhere:**
the mechanism that makes `PRODUCT_DELETE` *grantable to Manager* — system
roles' permissions are not editable today (§3.19.9; `staff-access-domain-
design.md` §12.1 records the two ways to change that), and that is a
Staff-domain decision, not a Catalog one. There is no fifth "UI" stage.

**Deliberately not designed here, so it is not silently assumed:**
a bulk/CSV deletion path; any "undo" (there is none — that is the point);
a barcode generator (freed barcodes are reusable but still never
auto-generated); the media-orphan cleanup of §3.19.7, which belongs to its
own note; and merchant-defined axis order, below.

**Merchant-defined axis order — its own task, not this one.**
`catalog_product_attributes.sort_order` exists as a column and is always
written as the literal `0`
(`EloquentProductRepository::persistVariationAxes()`, both call sites), and
`loadVariationAxes()` does not `ORDER BY` it — so the order
`Product::variationAxes()` returns is simply whatever order the rows come
back in, and **a merchant cannot order a product's axes at all**. The
change-axes flow above inherits that limit: it can add, remove and re-value
axes, but the axis *sequence* it writes is not a merchant decision. The
effect reaches past the admin — `sold_attributes` in the §3.13 sale-line
snapshot is sorted by `attribute_definition_id` as a deterministic
substitute for a real order (`SaleLineSnapshotBuilder`, and §3.13's own D5
note), so the order a customer sees on the storefront and on a receipt is
definition-id order, not the shop's chosen order. Fixing it needs a real
`sort_order` write path, a UI, an ordering rule for rows that already exist,
and a deliberate decision about whether the display order should also drive
`sold_attributes` from then on — plus an answer for the snapshots already
written. It spans the admin, the storefront and receipts, so it is designed
as its own task; nothing here should half-implement it.

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
- ~~Eloquent model classes and concrete repository implementations~~ — **done as of v1.2**: `EloquentProductRepository` and `EloquentVariationRepository` (`src/Persistence/Eloquent/`) implement both `Contracts\ProductRepository` and `Contracts\VariationRepository` and are wired into `easyco-main` via `CatalogServiceProvider` — see `vertical-slice-notes.md`. Still not modeled: Eloquent models for `catalog_media`/`catalog_categories`/`catalog_tags` and their pivots.
- ~~Reloading a Product's `VariationAxis` declarations from storage~~ — **done as of v1.3**: see §3.10. `Product::reconstituteFromStorage()`/`EloquentProductRepository` no longer skip axis-declaration rehydration.
- Descriptive (non-axis) product attributes have no domain representation on `Product` at all — the design for this exists (§3.11) but was never actually implemented; corrected from this section's own prior false "done" claim. Still deferred, unchanged: true multiselect descriptive attributes (below).
- ~~A real SKU-generation strategy (deterministic templates, sequence-based, collision-retry, etc.)~~ — **done as of v1.4**: see §3.2. `Product::baseSku()` auto-generates from a persistent sequence (`catalog.product.base_sku` Hook filter); `Variation::sku()` auto-generates as `{baseSku}-{n}` (`catalog.variation.sku` Hook filter), both with DB-constraint-driven collision retry in `EloquentProductRepository`.
- A barcode-collision-avoidance strategy. `barcode` has no generation logic at all today; every value is caller-supplied, and `catalog_variations_barcode_unique` is the only thing preventing a collision, enforced at insert time, after the fact.

---

## 7. Database design

**Driver target:** SQLite (current `.env`) and MySQL/MariaDB (planned) — every constraint used (`UNIQUE`, `FOREIGN KEY`) is portable across all three; no MySQL-only or SQLite-only DDL features were used (e.g. no generated/stored columns, no `CHECK` constraints that reference another table).

**Tables** (16 migrations, `packages/EasyCo/Catalog/database/migrations/` — the 14th and 15th add `catalog_products.base_sku` and tighten `catalog_variations.sku` to `NOT NULL`, both via the same safe backfill-then-constrain pattern: add nullable, backfill any pre-existing rows with a synthesized placeholder, then tighten; the 16th adds `catalog_variations.sort_order`, the merchant's own variation display order — see §7's own note below):

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
| `product_id → variations in the merchant's own display order` | composite `(product_id, sort_order)` on `catalog_variations` — the exact pair both read paths order by (`sort_order ASC, id ASC`) |
| `product_id + attribute combination → variation` | `UNIQUE(product_id, attribute_signature)` — same index that enforces uniqueness *is* the lookup path |
| `product_id → complete catalog representation` | every child table (`catalog_variations`, `catalog_product_attributes`, `catalog_product_media`, ...) is indexed on `product_id` via its FK, bounding eager-load to one query per table, no N+1 across variations |

**Batched reads, added for the Pricing price-range work (pricing-domain-design.md §4.4):** `VariationRepository::findByIds(array $variationIds)`, `ProductRepository::findBrandIdsByProductIds(array $productIds)`, `ProductCategoryRepository::findByProductIds(array $productIds)`, `ProductTagRepository::findByProductIds(array $productIds)` — each a thin `whereIn` sibling of an existing single-id method (`findById()`/`findByProductId()`), returning the identical entity shape, so `App\Services\CatalogScopeResolver::forVariations()` (the batched sibling of `forVariation()`) can assemble the scope-matching data for many variations across many products at a bounded query count — never one query per variation or per product, which is what a product-listing page with a price range per row would otherwise cost. `findBrandIdsByProductIds()` is deliberately narrow rather than a batched `findByIds()`: building a full `Product` aggregate is roughly 4 queries per product (the model itself, plus `loadVariationAxes()`/`loadDescriptiveAttributes()`, plus every one of its Variations' own attribute-assignment load) — scope matching only ever needs `brand_id`, so a single plain-column `whereIn` answers it instead of 4N queries for something none of that aggregate data is used for.

`catalog_variations.sort_order` is deliberately **not** a `Variation` domain field — `Variation` still carries only what Catalog itself owns (identity, SKU/barcode, attributes, media reference, flags, physical properties). Order is a merchandising concern, the same category the media pivots' own `sort_order` already occupies, and it is the one thing a merchant sets directly by dragging rows in `EditVariableProduct`'s Variations tab (admin-panel-design.md §13.6). It is written by two places, both in the persistence layer: `EloquentProductRepository::saveVariation()` (a NEW variation is APPENDED — `max(sort_order) + 1`, never 0) and `VariationRepository::updateSortOrders()` (the drag-and-drop reorder, array index = `sort_order`, unchanged rows left untouched). It is read back ordered by `EloquentProductRepository::findByIdWithVariations()` and `EloquentVariationRepository::findByProductId()` — `sort_order ASC, id ASC`, both of them. The column carries no DB-level uniqueness and defaults to 0 on purpose: every never-reordered row sits at 0 together, and the `id` tiebreak then reproduces exactly the order such rows were displayed in before the column existed (which is also what the migration's own backfill writes).

`barcode` is a plain `UNIQUE` column (nullable): both MySQL/MariaDB and SQLite treat multiple `NULL`s in a unique index as distinct by default, which already gives "unique when present" semantics without needing a filtered/partial index (a feature MySQL/MariaDB don't support anyway) — confirmed in `DatabaseUniquenessConstraintTest::test_multiple_null_skus_are_allowed_null_is_not_treated_as_a_duplicate`. `sku` (on `catalog_variations`) and `base_sku` (on `catalog_products`) are `UNIQUE NOT NULL` instead — both are mandatory identifiers now (§3.8), so there is no "unique when present" case to accommodate. `sku` was tightened to `NOT NULL` in a later migration than the column's original creation, precisely so a dev/staging DB with pre-existing rows wasn't rejected outright by the constraint — see the migration-count note above.

**Required pattern for handling a unique-constraint violation at the repository layer:** check `QueryException::$errorInfo` for **SQLSTATE `23000`** as the always-portable primary signal, but the **driver-specific error code differs by database** and must be checked against **both**: MySQL/MariaDB report `1062` (`ER_DUP_ENTRY`) specifically for a duplicate-key violation; SQLite reports `19` (`SQLITE_CONSTRAINT`) for *any* constraint violation (`UNIQUE`, `NOT NULL`, `CHECK`, foreign key alike) — confirmed directly against a real SQLite connection, not assumed. Because SQLite's code isn't UNIQUE-specific, `errorInfo[2]` (the driver's own raw error text, never `$e->getMessage()`) must still be checked afterward to confirm *which* constraint fired — and that check must itself handle both drivers' message formats, since those differ too: MySQL names the violated index (e.g. `"...for key 'catalog_products_slug_unique'"`), while SQLite instead lists the table/column pair (e.g. `"UNIQUE constraint failed: catalog_products.slug"`).

This is now implemented **three times** in `EloquentProductRepository` — `isVariationSignatureUniqueViolation()` (§3.1, the original implementation), `isProductSlugUniqueViolation()` (the slug-collision retry that backs `catalog.product.slug`), and `isVariationSkuUniqueViolation()` (the sku-collision retry that backs `catalog.variation.sku`, v1.4 — see §3.2 and `extensibility-design-and-hooks.md`'s Hook Reference) — all three delegating their primary SQLSTATE/code check to one shared `isPossibleUniqueConstraintViolation()` helper, with each caller supplying its own pair of secondary message substrings (index name + table.column). **Any future unique-constraint collision handling in this codebase must follow this same shared-primary-check, dual-format-secondary-check pattern** — do not re-derive it from scratch or assume a single error code covers every supported driver. The SQLite gap in the first (signature) implementation was only caught because an automated test happened to actually exercise the collision path (the app's test suite runs against SQLite per `phpunit.xml`, while production runs MySQL) — the sku implementation's own feature tests (`tests/Feature/EloquentProductRepositoryVariationSkuCollisionTest.php`) exercise the same collision path for the same reason, not as an afterthought.

Foreign keys from child tables to `catalog_products`/`catalog_variations` use `restrictOnDelete()` where a hard delete would silently orphan pricing/order references (products, variations themselves), and `cascadeOnDelete()` for pure ownership data with no external references (attribute assignments, media pivots, taxonomy pivots) — both `catalog_products` and `catalog_variations` additionally carry `softDeletes()` as the actual "removal" mechanism, so the restrictive FK is a last-resort safety net, not the primary safeguard.

**What was NOT verified in this sandbox:** actual execution against MySQL/MariaDB (no network access to a real instance here), and Eloquent model behavior (no `illuminate/database`/`orchestra/testbench` available — packagist wasn't reachable). What *was* verified: the exact constraint shape (`UNIQUE(product_id, attribute_signature)` etc.) against a real SQLite connection with real concurrent-insert semantics, matching the project's current `DB_CONNECTION=sqlite`. Running `php artisan migrate` against the real app and a quick manual duplicate-insert check on MySQL/MariaDB before considering this schema final is the recommended next step.

---

## 8. Research comparison (functional reference only, not architecture)

- **WooCommerce** — good checklist for variation-level capabilities (SKU, GTIN, price, sale schedule, stock, weight, dimensions, shipping class, image); its variation-as-near-Product ownership model was deliberately not copied.
- **Bagisto** — useful for the configurable-product idea and attribute-type system; its attribute-family concept was deliberately not copied (see §3.3, "smallest model" decision).
- **Medusa / Aimeos** — used only as comparative reference during design; no direct architectural borrowing.

---

## 9. Test coverage (`packages/EasyCo/Catalog/tests/`)

86 tests, 128 assertions, all passing (plus `easyco/pricing`'s existing, untouched 87 tests — 173 total across the two packages) *as of the v1.1 pass this count was first written for — not kept in sync every pass since; see the real, current count below instead.*

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
- `ProductAxisRedeclarationGuardTest` (v1.5, rewritten from v1.3's coarse-guard version) — every rule in §3.17's table: identical-set no-op with a live variation, identical-set no-op with only an archived variation, refusal on a genuinely different set while a live variation exists, value-addition to an existing axis leaving a live variation byte-for-byte unchanged, axis removal refused-then-allowed-once-archived (with the refusal message asserted to list the live variation's own id), new-axis-addition refused-then-allowed-once-archived, and value-removal refused/allowed/allowed depending on whether a *live* variation depends on the removed value.
- `ProductVariationRestoreTest` (v1.5, new) — `Product::restoreArchivedVariation()`: the happy path (status DRAFT, id/sku/barcode/assignments/signature unchanged, visibility/purchasability still false, a following `activate()` works), `\LogicException` for another product's variation / a UNIVERSAL variation / a DRAFT or ACTIVE variation, `VariationNotRestorableException` for both axis-drift directions (an axis removed, or a new axis added, while only archived variations existed), and that a pure value extension of an existing axis never makes an archived variation unrestorable.

**Real, current count as of this v1.5 pass:** `php vendor/bin/phpunit -c packages/EasyCo/Catalog/phpunit.xml` → **285 tests, 393 assertions**, all passing (277 before this pass's 8 new `ProductVariationRestoreTest` tests; `ProductAxisRedeclarationGuardTest` grew from 4 to 13 tests as part of that same 277, 2 of which replace — not add to — the old coarse-guard tests, per §3.17's own changelog).

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
