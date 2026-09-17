# Admin Panel Configurability & Small Ideas (local note, future work)

Not designed yet. Raised by the domain owner across several
conversations while hands-on testing the admin panel.

## 1. Simple/Variable badge + filter in ProductResource's table

Once the VARIABLE product wizard exists and both product types
genuinely coexist in the same admin list (ProductResource currently
excludes VARIABLE entirely — see admin-panel-design.md's own note on
that), add a visible type badge/icon column (Simple vs Variable) plus
a filter by type, so staff can tell them apart at a glance.

## 2. Per-merchant feature toggles

Several admin-panel capabilities built so far are useful to some
merchants and irrelevant to others: the activity log/journal, Season,
ProductGroup, Brand, Tag. The domain owner wants Site Settings toggles
letting a merchant turn each on/off after install, based on what their
specific catalog actually needs — not everything mandatory for every
store size/type. Mirrors the already-established
catalog.product_group_required precedent (admin-panel-design.md
§13.4) — same mechanism, more settings.

## 3. SKU generator: prefix/suffix configuration

The current SKU generator (CatalogSkuGeneratorServiceProvider) is a
plain sequential number. The domain owner wants merchant-configurable
prefix/suffix around it (e.g. a store-specific letter code before the
number).

## 4. Product type restriction setting

A Site Setting letting a merchant restrict which product type(s) their
store uses at all — Simple only, Variable only, or both — with
in-admin explanatory text describing what each type offers, so a
merchant who only ever sells single-SKU items isn't shown
VARIABLE-specific UI/complexity at all.

## 5. Storefront "push to front" without falsifying created_at

The domain owner's real scenario: a product entered a while ago gets
new stock in today: it should be able to jump back to the front of a
"newest first" storefront ordering, the same way a brand-new product
naturally would. The tempting WordPress-style fix — just overwrite
created_at — is explicitly rejected: created_at must stay a true
historical fact, especially now that a real activity log
(products.php's own audit trail work) exists specifically to keep
history honest.

Proposed direction (not designed): a separate storefront_sort_date
field on Product, distinct from created_at — set equal to created_at
at creation, updated to "now" on a genuine restock/push event, with
storefront "newest" ordering reading this field instead of created_at.

Deliberately deferred until the Inventory/stock-management admin UI
(Phase 2 of the original Product admin plan) exists, since restocking
is the natural, real trigger for this — there's no UI to hang an
automatic trigger on yet. A cheaper interim option floated but not
committed to: a manual "push to front" button a merchant could use
before real inventory tracking lands.
