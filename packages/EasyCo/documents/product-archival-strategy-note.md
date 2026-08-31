# Product Archival Strategy — Out-of-Stock, No Restock (local note, future work)

Not designed yet. Raised by the domain owner while discussing Media
cleanup, but this is fundamentally a Catalog-domain concept (product
lifecycle), not Media's.

When a product is permanently out of stock with no realistic chance of
restocking, the domain owner wants TWO distinct archival modes to
choose from per product (not a single behavior):

1. **"Sold Out" mode** — invisible in site navigation/search/listing,
   but the product page itself remains reachable via a direct link
   (e.g. an old bookmark, a shared link, an indexed search-engine
   result) and shows a clear "Sold Out" label rather than a 404.
2. **"Redirect" mode** — invisible in site navigation/search/listing,
   AND visiting the old direct link redirects the visitor to the
   nearest matching category page instead of showing the product at
   all.

Both modes are about what happens to *discoverability and the direct
URL* after a product stops being sellable - orthogonal to Catalog's
existing archive-status/soft-delete mechanics (CLAUDE.md rule 4:
historical identity is never destroyed/reassigned). This note only
records the two desired archival UX outcomes; it does not design the
mechanism (a status enum value? a redirect table? how "nearest
category" gets computed?) - all open.
