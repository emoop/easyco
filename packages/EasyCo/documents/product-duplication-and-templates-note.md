# Product Duplication (VARIABLE) & Fast-Create Templates (local note, next work)

Status: not implemented. Confirmed decisions and open questions recorded
here so they survive the chat session (ai-collaboration-protocol.md's
own deferred-work rule). Nothing in this note is code.

## Confirmed by the domain owner

**Variations are NEVER copied when a product is duplicated.** Not for
VARIABLE products, not partially, not "just the fields". A duplicate's
variations are always *created fresh* (generated from the copied axis
declarations), never carried over from the source. This closes the one
question admin-panel-design.md §13.2 left open in its own wording
("the actual Variations are NOT [copied]" — now an explicit, permanent
rule rather than a pass-scoped one).

Why this is the right rule, not merely the convenient one: a Variation's
identity is physical and commercial — `UNIQUE(variation.barcode)` is a
real printed label, `UNIQUE(sku)` is a real stock identifier, and price /
cost / stock rows are keyed by the OLD variation's priceableId. Copying
any of that would either collide in the database or silently attach a
label/number to a second physical item. Generating instead gives fresh
SKUs through the existing `catalog.variation.sku` hook and its
DB-constraint-driven retry, and leaves prices/stock to be entered on the
edit screen — exactly like the creation wizard already does.

## What §13.2 already decides (unchanged, for reference)

- Copied: name `"{original} ({duplicate_suffix})"`, brand, season,
  product group, categories, tags, description, descriptive attributes.
- Re-derived, never copied: slug (`catalog.product.slug` hook) and
  base_sku (cleared, `catalog.product.base_sku` hook) — both are UNIQUE.
- Not copied: photos, status (always DRAFT), price/cost/stock (a
  duplicate is a catalog skeleton today, for SIMPLE as well).
- VARIABLE: axis DECLARATIONS are copied (which definitions/values were
  selected); with the pre-stated fallback that if that one piece proves
  materially harder than the rest, the duplicate is created with zero
  axes declared and the merchant starts axis declaration fresh.

## Already built, so the remaining work is small

- `App\Services\DuplicateProduct` — the parent-field/categories/tags
  copying is type-agnostic already; its only SIMPLE-specific parts are
  `Product::createSimple()` and the `LogicException` guard.
- `ProductResource::duplicateAction()` — shared by the list row action
  and `ViewProduct`'s header action; its `->visible()` is where VARIABLE
  rows are currently hidden.
- `Product::createVariable()` / `declareVariationAxes()` (with §3.17's
  directional rules) / `addStandardVariation()`, and
  `VariationCombinationGenerator` — already reused by the creation
  wizard, the merchant API and EditVariableProduct's "Generate missing
  variations".
- `EditVariableProduct` — Axes tab, "Generate missing variations",
  "Add variation", plus the Variations tab (bulk price/stock fields) which
  the creation flow now lands on via `?tab=variations`
  (`ProductResource::VARIATIONS_TAB_ID`).

## Open questions to settle when this is picked up

1. **Axes: copied or not** — §13.2 says yes (fallback: no). Copying means
   rebuilding `VariationAxis[]` from the source's `variationAxes()`,
   which needs loaded `AttributeDefinition` + `AttributeValue` objects
   (the service injects the definition repository already; the value
   repository would be added).
2. **Are variations generated immediately, or left to the merchant** —
   §13.2 says the merchant "re-runs generation"; the safer reading is a
   duplicate with zero variations plus a landing on the tab where
   generation and pricing live.
3. **Which tab the duplicate lands on** — `?tab=variations` when axes
   were copied, `?tab=axes` on the zero-axes fallback path.
4. **Notification wording/UX** — reuse the pattern already built for the
   creation wizard (what is still missing + a link to the View page).
5. **The service's guard** — the `LogicException` and the action's
   `->visible()` must be lifted together; showing the action while the
   service still refuses would surface a 500 instead of a refusal.

## Fast-create templates — coupled to the above, not yet designed

The domain owner's own follow-up: once duplication is settled, design the
"quick product creation" templates too. Design already exists in
`admin-panel-design.md` §13.3 and `catalog-domain-design.md` §3.16: a
`ProductTemplate` is a named bag of FIVE default fields (brand, season,
product group, category ids, tag ids) applied as a one-time pre-fill to a
Create form's fields — not a live link, no axes/variations, and editing a
template never touches products already created from it. Infrastructure
exists (`catalog_product_templates` table, `ProductTemplateRepository`,
bound in `CatalogServiceProvider`), but there is no Filament resource and
nothing in `app/` uses it yet.

Not to be confused with duplication: templates pre-fill a Create form
before anything exists; duplication copies an existing product's fields.
They may end up sharing the same "which fields are tedious to re-enter"
list, which is the reason to design them together.
