# Product/Brand Slider Widget (local note, future Storefront/Admin UI work)

Different in kind from the Hero Slider/Grid (which is manually-uploaded
static content, admin-curated images/links). This is a DYNAMIC widget:
no upload, it reads existing Catalog/Pricing data and renders cards
automatically - product name, current price (via priceableId ->
PriceResolver), and default links to the product page. A brand variant
shows brand logos/names linking to brand pages instead.

Existing foundation already in place, no new domain needed:
- catalog_products.is_featured (already a column, from the earliest
  Catalog migrations) is the natural default selection source for a
  product slider - "show featured products" - no new field required.
- catalog_brands (already exists) is the natural source for a brand
  slider.

Belongs to the future Storefront/Admin UI phase
(performance-and-channel-strategy.md), not the Media/Hero-Slider domain
being designed now - deliberately kept separate so neither document
covers two unrelated concepts. Not designed yet - this is a placeholder.
