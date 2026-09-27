# Product shipping fields (local note, next shipping work)

Status: not implemented. A heads-up from the domain owner, recorded here so it
survives the chat session (ai-collaboration-protocol.md's deferred-work rule).
Nothing in this note is code.

## Confirmed by the domain owner

When the shipping **methods** are built, **both** product forms gain additional
fields: the SIMPLE product form (`ProductResource`) and the VARIABLE product's
variations form (`EditVariableProduct`). Recorded now because both forms were
compacted into paired `Grid` rows in the same session — whoever picks the
shipping work up should know where the new fields were already making room for
themselves, rather than re-flowing those layouts from scratch.

## What is already there (verified in the code, not assumed)

The **Variation** side is ready down to the database; only the admin UI is
missing:

- `Catalog\Variation` already carries `shippingClass`, `weightGrams`,
  `lengthMm`, `widthMm`, `heightMm` — ctor props (`Variation.php:62-66`),
  `shippingClass()` / `setShippingClass()` (`:392-400`), `weightGrams()`
  (`:402`) and `setDimensions(?int $weightGrams, ?int $lengthMm, ?int $widthMm,
  ?int $heightMm)` (`:413`). Integers in grams/millimetres on purpose: "no float
  drift between two systems (Catalog, Shipping)" (`Variation.php:407-412`).
- Columns exist (`2026_08_23_000006_create_catalog_variations_table.php:55-63`
  — a nullable `shipping_class` string plus four `unsignedInteger`s), are
  fillable (`VariationModel.php:32-36`) and round-trip through both write
  paths and both read paths (`EloquentProductRepository.php:400,817`,
  `EloquentVariationRepository.php:189`).

The **Product** side has nothing at all: no properties on `Catalog\Product`, no
columns in `catalog_products` (checked the create migration and every follow-up
since). So the SIMPLE form's shipping fields are not a UI-only task — they need
the domain property, a migration and the repository mapping first, mirroring the
Variation shape.

**No shipping domain exists yet.** There is no `ShippingClass`, `ShippingMethod`
or `ShippingZone` entity anywhere in `app/` or `packages/` — `shipping_class` is
a plain nullable string today, so there is also no merchant-owned list of classes
for a `Select` to read from. `admin-panel-design.md` has no shipping /
physical-properties section for the product forms either, so that design gap is
real, not merely unread.

Already documented downstream, cross-referenced here rather than repeated:
`checkout-domain-design.md:304-305` records that `Order.total` is exactly
`subtotal - discount` and excludes any shipping charge, plus the list of what
adding one later implies (`shippingMinor` on `Order`, redefining `total`,
`Order::create()`'s refusal to accept a supplied total, and whether a promotion
may discount shipping).

## Impact on the layouts compacted this session

- `ProductResource::generalTabComponents()` is now six stacked rows — full. New
  product-level physical fields belong in their own tab or section, not appended
  there.
- `ProductResource::priceStockTabComponents()` is two `Grid::make(2)` rows
  (`regular_price`|`sale_price`, `cost`|`stock_quantity`). A third pair drops in
  verbatim, e.g. `shipping_class`|`weight`; but dimensions are three values, so
  `Grid::make(3)` for `length`|`width`|`height` is the likelier shape than
  another pair.
- `EditVariableProduct`'s per-variation `Repeater`s (`existing_variations`,
  `new_variations`) already carry the bulk-vs-per-row pattern, including the
  `clear_regular_price_overrides` / `clear_sale_price_overrides` toggles — a
  per-variation shipping override should reuse exactly that shape rather than
  inventing a second convention. There the constraint is row width, not tab
  height.

## Open questions to settle when this is picked up

1. **Which fields, and on which side** — weight / dimensions / shipping class on
   the product, on the variation, or (following the Variation entity's own
   precedent) on the variation with product-level values as the fallback a
   merchant overrides.
2. **Product-level columns** — the migration and `Product` properties the SIMPLE
   side needs, with the same grams/millimetre integers and nullable defaults.
3. **`shipping_class` as free text or as a list** — if the shipping work
   introduces a merchant-owned class table, the variation's plain string column
   and the `Select` in both forms change together, in one pass.
4. **Where the fields live in the UI** — a new "Shipping" tab on both forms, or a
   section inside Price & Stock.
5. **Units and labels** — the merchant typing grams/millimetres directly, or a
   unit-aware input (with `hintIcon(...tooltip:...)` carrying the stored unit,
   matching the convention the compacted forms now use).
