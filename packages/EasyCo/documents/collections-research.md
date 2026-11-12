# «Колекции» (Collections) — research (P6e-0)

Research only: no code was changed. Facts are cited as `path:line` (lines of the tree at commit `8cd47b4`, «P6d»); **Proposal** marks everything that is my suggestion, not a fact. Sections A–H follow the task brief.

**Summary in five lines**

1. The season is mentioned in ~320 lines of `app/`, ~200 of `packages/`, 65 of `lang/`, 730 of `tests/` (55 files); ~35 distinct places need a decision for a collection (table B).
2. **The tag already is a «many-to-many subject» with a page, a filter, counts, scopes, conditions, home sources, mass edit and a product picker.** For almost every season place there is a tag twin that solves the many-to-many question; a collection is closer to the tag code than to the season code in 10 of the 12 areas (table C).
3. The storefront read side is already table-driven (`match` arms and `['brand' => …, 'season' => …, 'tag' => …]` arrays in ~14 places). A small **registry of subject kinds** can replace those arrays; the **write side must not be unified** (a column vs a pivot). That is realistic and cheap; a full «shared product-group mechanism» that also moves the season is not worth the risk (D).
4. **The season has no storefront OFF switch today**: `catalog.season_field_enabled` is read only by the admin (`ProductResource.php:342`, `SeasonResource.php:77`, `CatalogSettings.php:106`); the public `/season/{slug}` page and the season filter work with the switch off. A collection needs a real OFF (404, no filter): that is NEW code on public paths, readable without a new query (E).
5. Proposed split: 4 tasks, the first leaves a working, switched-off feature (G); 5 questions for the owner (H).

---

## A. Every place the season is mentioned (facts)

Counts (`git grep -i -c season`, excluding vendor): `app/` 317 lines in 62 files · `packages/` 196 in 45 · `resources/` 4 in 3 · `routes/` 2 in 1 · `lang/` 65 in 18 · `database/` 1 in 1 · `config/` 0 · `tests/` 730 in 55 files (Browser 14, Feature 39, Unit 2; counted, not listed).

| Area | Places (file:line) |
|---|---|
| **Domain** | `Product` aggregate `seasonId()` / `assignSeason()` (`packages/EasyCo/Catalog/src/Product.php:459`), `Season` entity, `ProductTemplate` (`season_id`, `ProductTemplate.php`, `EloquentProductTemplateRepository.php:23,55`) |
| **Persistence** | `catalog_seasons` (`id,name,slug,picture_media_asset_id`, timestamps), `catalog_products.season_id` nullable FK `nullOnDelete` (`2026_09_14_000003_add_season_id_to_catalog_products_table.php:18`), `SeasonModel`, `ProductModel::season()` BelongsTo (`ProductModel.php:54`) + fillable (`:31`), `SeasonRepository` / `EloquentSeasonRepository` (`countProductsUsing` = `where('season_id')`, `:49-51`), product map/save `EloquentProductRepository.php:49,637` |
| **Product admin** | switch `seasonFieldEnabled()` (`ProductResource.php:342`); form section + `Select season_id` (`:563-568`); table `SelectFilter season_id` (`:1560-1563`); view `TextEntry season.name` (`:1827`); create `CreateProduct.php:105`; edit `EditProduct.php:271-274`, `EditVariableProduct.php:2634-2637`; duplicate `DuplicateProduct.php:84`; P6d mass edit `ProductMassEditor.php:56,116,172,245-251`, UI `ProductResource.php:2808`; detach `DetachProductFromCatalogLookup.php:42,65`, `CatalogLookupKind.php:15` |
| **Catalog settings** | `CatalogSettings.php:106,121-123,181` (`catalog.season_field_enabled`, default ON) |
| **Season admin resource** | `SeasonResource.php` (251 lines; product-count column `:161`, delete guard `:217`, visibility tied to the switch `:77`), `SeasonResource/Pages/{List,Create,Edit,View}Season.php`; picture usage `Media/Usage/SeasonPictures.php`, registered `MediaUsageRegistry.php:34` |
| **Storefront routes / controllers** | `routes/storefront.php:88` `GET /season/{slug}` → `SubjectListingController::season` (`:26-28`); `StorefrontUrls::season()` (`:44-46`) |
| **Reader / read models** | `CatalogReader::subject()` table match (`:238,255`); `cloud()` (`:294`); `applyScope()` (`:554-555`); built-in facet ids (`:533`); `has_season_filter` scalar (`:356,469`); home source `'season' => [ListingScope::ALL, ['season' => [$entity]]]` (`:418`); `ListingScope::SEASON` (`ListingScope.php:11`); `ListingQuery::SCOPE_KEYS` (`:22`); `ListingRequest.php:28` |
| **Filters / facets** | `FilterConstraint::BUILTIN` (`:24`), `builtin()` `'season' => whereIn('season_id')` (`:79`); `FilterCatalog` (order brand, season, attributes, tag; `:22,47,91,172-176,188` — one UNION ALL query, cached, forgotten on model change); `FilterSelection`, `SidebarFilters::BUILTIN` (`:18`), `SidebarStore.php:31,98`, `StorefrontAppearance.php:668-678`, `PageDesign.php:65,123` (`filterSeason`), `SidebarZone.php:213`, `CatalogZone.php:16`, `DesignPreviewController.php:205` |
| **Counts / cloud** | `CatalogReader::cloud()` season branch (`:294`); facet ids (`:533`); category counts are not season-related |
| **«Съдържание» (P3)** | `ContentTemplate::KINDS` (`:28`: `categories|brands|seasons|tags`), `ContentStore.php:25`, `ContentSet.php:6`, `ContentZone.php:27,42`, `ListingPageRenderer.php:24-25,59,83` (one renderer for every subject) |
| **Menus** | **none**: menu item types are `link, category, page, product` only (`MenuValidator.php:45-51`); there is no brand / season / tag item type |
| **HTML blocks / `ProductConditions`** | `in_season` predicate (`ProductConditions.php:51`), key `seasons` (`:40,111,153,259,297`, `MAX_SEASONS = 20` `:32`), `ProductContext::seasonId()` lazy read (`:83-90`), editor field `HtmlBlockResource.php:314-315`, lang |
| **Home grids / carousels / tiles** | `HomeCatalog` sources (`:35,38,43,45`), `HomeValidator.php:416,466`, `HomeTiles.php:28,311,327-330` (season picture + url), `HomeTilesListeners.php:33`, `HomeTilesRecompiler.php:11,25`, `HomeZone.php:780,802` |
| **Product page** | `SectionMeta` «Мета информация» (season + tags links, one UNION query, `:33-50`), `SectionSet.php:10,237-245`, `SectionCompiler.php:18`, `SectionResolver.php:26,45`, `SectionsZone.php:167,190,218`; `ProductController.php:61` |
| **Price-list scopes** | `PriceListScopeType::SEASON` (`Pricing/src/Enums/PriceListScopeType.php`), `CatalogScopeResolver.php:115,156-159` (`matchingScopeReferenceIds['season']`), `CatalogScopeLookup.php:22,38,52`, `PriceListAdminReader.php:60`, `SortPriceRefreshDispatcher.php:76`, `PriceListResolutionEngine.php` (2 lines, kinds grouped) |
| **Promotions** | **none**: `PromotionWriter::SCOPE_TYPES` = brand, category, tag, product, attribute value (`PromotionWriter.php:30-42`); a promotion cannot be scoped to a season (`CatalogScopeLookup.php:11`) |
| **Sitemap / SEO / breadcrumbs** | no sitemap or feed exists; breadcrumbs of a subject page are built in `CatalogReader::subject()` (`:259-267`) |
| **Audit / hooks** | audit `season_id` entries in `EditProduct.php:273`, `EditVariableProduct.php:2636`, `ProductMassEditor`; **no hook mentions the season** |
| **Help / docs** | `resources/help/*/price_lists.md`, `lang/*/{html_blocks,related_products,settings,price_lists,design,seasons,storefront}.php`; 12 documents mention it (`catalog-domain-design.md` 9, `pricing-persistence-domain-design.md` 8, `design-pages-plan.md` 8, `admin-panel-design.md` 7 …) |
| **Seeders / fixtures** | none for seasons; goldens that contain a season: none (`tests/Fixtures` has no `season`); `tests/Unit/Storefront/PageDesign` (2 files) pin `filterSeason` |
| **Other** | `Media` usage/orphan cleanup (`SeasonPictures`), `PruneProductsToOriginal` (pivots only for tags/categories, season is a column) |

## B. Classification: (1) shared list, (2) own small code, (3) season-specific

«Shared» = after a registry of subject kinds exists, the place takes the collection from the registry with no per-kind code. «Many» = what changes because a product has MANY collections.

| # | Place | Class | What it takes for a collection |
|---|---|---|---|
| 1 | `Product.seasonId()` column read/write | **(3) untouched** | A collection is NOT a column. Pivot `catalog_product_collections(product_id, collection_id)` + `ProductCollectionRepository` (clone of `ProductTagRepository`, ~110 lines). The `Product` aggregate stays frozen: pivots are written like tags (separate repository, as `EditProduct::syncTags` does) |
| 2 | `catalog_collections` table / model / repository / entity | (2) | clone of `Season` (+ `picture_media_asset_id`), ~5 small files |
| 3 | Product form picker | (2) small | a `Select::make('collections')->multiple()` section like tags (`ProductResource.php:554-561`), `visible` by the switch; sync method like `syncTags` (`EditProduct.php:443-475`) in the two edit pages + create pages |
| 4 | Product list filter | (2) small | `SelectFilter` like `tags` (`:1604`, uses a pivot `whereHas`) |
| 5 | P6d mass edit | (2) small | `ADD_COLLECTION` / `REMOVE_COLLECTION` = the `pivots()` method of `ProductMassEditor` (already generic over a repository + field name) |
| 6 | Catalog settings switch | (2) | one more toggle, key `catalog.collections_enabled`, **default OFF** (`!== '0'` is the season/tag default-ON pattern; a collection needs `=== '1'`) |
| 7 | Collection admin resource | (2) | clone of `SeasonResource` (251 lines) with the product count via the pivot |
| 8 | Storefront route `/collection/{slug}` | **(1)** after registry | `SubjectListingController` gains one method (a 3-line match arm); route line like `routes/storefront.php:88` |
| 9 | `CatalogReader::subject()` / `cloud()` / `applyScope()` / facet ids | **(1)** after registry | joins instead of a column: `whereIn('catalog_products.id', pivot select)` exactly as the TAG branch (`:295,553`), facet = `join pivot … pluck('collection_id')` as the tag branch (`:534`) |
| 10 | Listing filter, facets, `FilterConstraint::builtin` | **(1)** after registry | tag branch is `whereExists(pivot)` (`FilterConstraint.php:81`) — reuse; filter order, `SidebarFilters::BUILTIN`, `filterCollection` kind in `PageDesign` (3 more places that list kinds) |
| 11 | «Съдържание» default template + per-page override | **(1)** | add kind `collections` to `ContentTemplate::KINDS` / `ContentStore` / `ContentZone::SOURCE_OF`; storage is a per-kind JSON, no migration |
| 12 | Counts on the page | (1) | listing total comes from the same query; no count read model for subjects (`productCount` is 0 for brand/season/tag, `CatalogReader.php:265`) |
| 13 | Breadcrumbs / title / cloud | (1) | `crumb_collection` lang key (`storefront.crumb_*`, `CatalogReader.php:266`) |
| 14 | `StorefrontUrls::collection()` | (2) 3 lines | |
| 15 | HTML-block conditions (`ProductConditions`) | **(1) tag twin** | `has_collection` like `has_tag` (`:50`; `ProductContext::tagIds()` `:78-80`); key + MAX + editor `Select` + lang |
| 16 | Home grid / carousel sources + tiles | (2) | `HomeCatalog` const arrays (`:35-45`), `HomeValidator`, `HomeTiles` (collection picture + url), `HomeTilesListeners` (add the model class), `HomeZone` options; the product-grid source follows the TAG arm (`CatalogReader.php:417`) |
| 17 | `SectionMeta` «Мета информация» | (2) small, optional | a third UNION member |
| 18 | Price-list scope kind | (2) | `PriceListScopeType::COLLECTION`; `CatalogScopeResolver` (pivot read like tags `:124-127`, **one more grouped read per resolve** — see F), `CatalogScopeLookup`, `PriceListAdminReader`, `SortPriceRefreshDispatcher::productsMatching` (`:76-78`), **`SortPriceTriggers`: register `ProductCollectionModel::saved/deleted`** like `ProductTagModel` (`:70`) |
| 19 | Promo-code scope | (2) later | `PromotionScopeType::COLLECTION` + writer/lookup (tags are the model) |
| 20 | Menus | **not a twin** | no season/tag/brand item type exists (`MenuValidator.php:45`); a collection page is reachable today through a `link` item. A real «collection» item type = new menu feature (type, target, compile, editor): proposal in H.5 |
| 21 | Media usage (`SeasonPictures`) | (2) | same provider for `catalog_collections.picture_media_asset_id` |
| 22 | Audit | (1) | `logFieldChanged('product', id, 'collections', old, new)` like tags (`EditProduct.php:459,473`) |
| 23 | Hooks / help / sitemap | — | none exist for the season; none needed. Help page: optional |
| 24 | Duplicate product | (2) small | copy the pivot (tags are copied in `DuplicateProduct.php:108`; season is `:84`) |
| 25 | `ProductTemplate` | (3) | templates keep `season_id`; **no** collection in templates in the first waves |
| 26 | `DetachProductFromCatalogLookup` / `CatalogLookupKind::SEASON` | (3)/(2) | detach for a many-to-many = remove one pivot row; add only if the product page offers it |
| 27 | Season admin switch behaviour | (3) | do NOT change the season (the public season page still works with the switch off — see E) |

**Where the code assumes «one per product» (`season_id`)** — these are the places that become joins/pivots for a collection and must NOT be copied:
`CatalogReader.php:294,533,555`, `FilterConstraint.php:79`, `CatalogScopeResolver.php:115,157-159` (a single-element id array), `SortPriceRefreshDispatcher.php:76`, `ProductContext.php:86`, `SectionMeta.php:45`, `EloquentSeasonRepository::countProductsUsing`, `EloquentProductRepository.php:49,637`. All have a working tag twin in the same file (table C).

## C. How tags already work (many-to-many) and the closer twin

Tag storage: `catalog_tags(id,name,slug)` (no picture), pivot `catalog_product_tags(id,product_id,tag_id)` with a unique pair (`EloquentProductTagRepository.php:41-60`), `ProductTagModel`, `ProductTagRepository::{save,remove,findByProductId(s)}` (grouped read `findByProductIds`, used by `CatalogScopeResolver.php:124` and `ProductMassEditor`).

| Area | Season code | Tag twin (file:line) | Collection is closer to |
|---|---|---|---|
| Storage | column `season_id` | pivot (`ProductModel.php:80` `belongsToMany`) | **tag** |
| Subject page | `/season/{slug}` | `/product-tag/{slug}` (`routes/storefront.php:89`) | same controller, shared |
| Listing scope | `where season_id` (`CatalogReader.php:555`) | `whereIn id (pivot select)` (`:553`) | **tag** |
| Filter (`FilterConstraint`) | `whereIn(season_id)` (`:79`) | `whereExists(pivot)` (`:81`) | **tag** |
| Facet ids | `whereNotNull(season_id)->distinct` (`:533`) | `join pivot → tag_id` (`:534`) | **tag** |
| Cloud | `where season_id` (`:294`) | `whereIn pivot` (`:295`) | **tag** |
| Conditions | `in_season`, lazy read of the column (`ProductContext.php:83`) | `has_tag`, lazy pivot read (`:78-80`) | **tag** |
| Home sources | `ListingScope::ALL + filter season` (`:418`) | `ListingScope::TAG` (`:417`) | tag |
| Price-list scope | `matchingScopeReferenceIds['season']` from the products read | pivot read grouped by product (`CatalogScopeResolver.php:124-127`) | **tag** |
| Sort-price refresh triggers | no trigger (season is on `ProductModel`, whose `saved` is already a trigger) | `ProductTagModel::saved/deleted` (`SortPriceTriggers.php:70`) | **tag** |
| Product form / mass edit | `Select` + aggregate method, audited column | `Select multiple` + `syncTags`, `pivots()` mass edit | **tag** |
| Promotions | no season scope | `PromotionScopeType::TAG` | **tag** |
| Picture / home tile picture | `catalog_seasons.picture_media_asset_id` | tags have none | **season** (the picture column + `SeasonPictures` + `HomeTiles` branch) |
| Admin resource | `SeasonResource` (picture upload, delete guard `nullOnDelete`) | `TagResource` (209 lines, delete guard `cascadeOnDelete` `:174`) | **season** (picture) with the tag's delete rule |
| Catalog switch | `season_field_enabled` default ON | `tags_field_enabled` default ON | neither (a collection is default OFF) |

Conclusion: **tag code solves many-to-many for 10 of 12 areas**; reuse beats new code. Only the picture and the admin resource follow the season.

## D. The shared mechanism

### Facts
- Hard-coded per-kind arrays exist in these places (the candidates for a registry): `ContentTemplate::KINDS` (`:28`), `ContentZone::SOURCE_OF` (`:42`), `ListingQuery::SCOPE_KEYS` (`:22`), `CatalogReader::subject()` table match (`:236-241`) and URL match (`:253-257`), `CatalogReader` has_*_filter loop (`:356`), `FilterCatalog` union (`:164-183`), `FilterConstraint::BUILTIN` (`:24`), `SidebarFilters::BUILTIN` (`:18`), `HomeCatalog::TILE_SOURCES / SOURCES_WITH_PAGE / PRODUCT_SOURCES*` (`:35-45`), `HomeTiles` wanted-ids (`:28,311`), `HomeTilesListeners` (`:33`), `ProductConditions` key arrays (`:111,153,259,297`), `CatalogScopeLookup` (`:22,38,52`), `PriceListAdminReader.php:60`, `SubjectListingController` (3 identical methods).
- The renderer is already shared (`ListingPageRenderer.php:24-25`: «ONE listing page for a category, a brand, a season and a tag»).

### Proposal
A **read-side registry of «catalog subject kinds»** — an enum `CatalogSubjectKind` (`brand`, `season`, `tag`, `collection`) with, per case: the table, the slug/name/picture columns, the pivot (null for a column kind), the product column (`season_id`/`brand_id`, null for a pivot kind), the `ListingScope`, the URL prefix, the content kind (`seasons`…), the filter key, the lang key of the crumb, and `enabled(): bool`. The `match` arms and arrays listed above read it. Two small helpers carry the cardinality difference: `constrain($query, $ids)` (column `whereIn` or pivot `whereExists`) and `idsOfProducts(...)`.

- **What moves into it:** the table/url/scope/content-kind arrays (reads only). **What does NOT move:** the write side (aggregate `assignSeason`, `ProductCollectionRepository`, audit, mass edit), admin resources, the price-list scope enum (domain, stays in the Pricing package), the P6a triggers.
- **Is a full shared «product group» mechanism that also re-implements the season realistic?** No. It would touch the frozen `Product.php`, change `season_id` storage or add an abstraction over a column and a pivot, and risk pinned counts/goldens for no user benefit. **Recommended: the registry above, with the collection implemented as a tag-shaped pivot kind; the season and the tag code are not rewritten, only their arrays are replaced by registry reads (a mechanical change with the existing season/tag tests as the safety net).**
- **Alternative considered and rejected:** reuse `catalog_tags` with a `kind` column (a collection = a tag of kind «collection»). It would reuse every tag path for free, but it mixes two admin concepts in one table, forces a filter on every tag list/picker/scope lookup/mass edit/home source/URL (`/product-tag/…` would serve collections), breaks the clean OFF (tags are default ON) and puts all existing tag pins at risk. Cheaper to write, dirtier to keep.
- **Risk to existing behaviour:** the registry refactor changes no query text for season/tag (same tables, same predicates) → pinned counts unchanged; URLs unchanged; goldens unchanged (the subject goldens do not exist; `listing_page.json` has no subject). The risk is mechanical (a typo in an array), covered by `SubjectListingPageTest`, `CategoryFiltersTest`, `HomeProductGridTest`, `ContentZone*` tests (the 55 test files that mention the season).

## E. The OFF switch

Key `catalog.collections_enabled` (`'1'` = on, **missing = off**) — opposite default of `season_field_enabled` / `tags_field_enabled` (`!== '0'` = on, `ProductResource.php:342-354`).

Fact to know: today **only the admin** reads the season/tag switches (`ProductResource.php:342-354`, `SeasonResource.php:77`, `CatalogSettings.php`, `ProductMassEditor.php:116`). The storefront does not (the `/season/{slug}` page and the filter work with the switch off). A collection needs a true OFF, so each public place needs a read:

| Place | How to read the switch (P6c pattern) | New query? |
|---|---|---|
| Admin: nav item (`CollectionResource::canViewAny`), product form section, list filter, mass-edit actions + service assert, Catalog settings toggle | `SiteSettingsRepository::get()` — memoised per request, same as `seasonFieldEnabled()`; the products list already reads 4 settings in one render (`SiteSettingsMemoizationTest`: 4 queries for 4 keys) → **one more key = one more query on the admin list**; avoid by `getMany([...5 keys])` in the one place that already batches, or accept +1 on the admin list (the pinned admin list count is 40; it would become 41 — **STOP/decide in task 1**; prefer `getMany`) | admin only |
| Public page `/collection/{slug}` → 404 when OFF | inside the subject query: `catalog_collections` lookup `WHERE EXISTS (select 1 from site_settings where key = 'catalog.collections_enabled' and value = '1')` (exactly the P6c `availableOnly()` shape, `StorefrontVisibility::soldOutShownSetting`) | **no** |
| Listing filter + facet + sidebar order | `FilterCatalog` is cached (2 cold queries, 0 warm); the collection kind is added to `knownKeys()`/the union only when ON → the setting must be known when the cache is filled and the cache **forgotten on the settings save** (`FilterCatalog::forget` is already called on model changes, `:185-188`: add the call to `CatalogSettings::save`) | no (cold path already reads the DB) |
| `has_collection_filter` scalar in `listingProducts` | scalar subselect `exists(collections) and exists(site_settings … '1')` like the `has_*_filter` scalars (`CatalogReader.php:356`) | **no** |
| Home sources / tiles / validator / editor options, «Съдържание» kind, conditions editor, price-list scope picker | editor/validator options: `SiteSettingsRepository::get()` (admin, not a pinned storefront path). Storefront compile: a stored source of a switched-off kind **renders nothing** (the grid/tile read goes through the same subject lookup that carries the in-query EXISTS) | no |
| Menus | a `link` item to `/collection/…` → 404 when OFF; nothing to hide | no |
| Existing data | stays stored; the pivot rows keep being written by nobody (the field is hidden), the P6a triggers still refresh prices if a collection-scoped campaign exists (harmless) | — |

Do **not** put the switch in the appearance payload: P6c found that this makes reader-only pinned query counts depend on a warm cache (`StorefrontAppearance` cold/warm).

## F. Query counts and goldens

Pinned (all measured in this session's full runs): subject pages brand / season / tag **27 each** (`SubjectListingPageTest.php:339`, ceiling 27), category page 28 (28), listing 19 (19), category listing 21 (21), product page 22 (22), home 25 (25), cart 6/7, admin products list 40/32/30/30 (all/active/draft/archived).

| Risk | Design |
|---|---|
| Collection subject page costs more than the tag page | the collection page is the tag page with another table: same query shape ⇒ **27** (the scope is one `whereIn (pivot select)`, no extra query). Verify with `SubjectListingPageTest` extended to a 4th subject |
| Filter facets | one more `UNION ALL` member only when ON, inside the existing cached cold query: counts unchanged; `has_collection_filter` is a scalar column, not a query |
| Price resolution (`CatalogScopeResolver`) | a collection scope needs **one more grouped pivot read per resolve** (as tags do: `ProductTagRepository::findByProductIds`). That is +1 query on every priced page (listing, product page, cart, checkout) **when the scope kind exists** — a pinned-count risk. **Mitigation (task 3):** read the collection pivot only when at least one active price list has a COLLECTION scope (the resolver already loads the lists; one `in_array` check), or fold the collection ids into the existing tag read with a `UNION`. Pins: product page 22, listing 19, checkout 9 |
| Product page conditions | `has_collection` reads lazily only when a conditioned block asks (`ProductContext`, like tags) — no cost for existing pages |
| `SectionMeta` | a third UNION member in the same query — no extra query |
| Goldens | none contains a season, tag or collection field (`tests/Fixtures/storefront/*.json`); `product_card.json` / `product_page_*.json` / `listing_page.json` unchanged **as long as `ProductCard` / `ProductPage` read models get no new field**. Do not add a «collections» array to `ProductPage` (use `SectionMeta` instead) |
| Admin products list | +1 settings read if not batched (see E) |
| `StorefrontQueryCountTest`, `HomeProductGridTest` (home 23/25) | unaffected unless the home sources are rewritten per kind; the registry refactor keeps query text |

## G. Split proposal (coder tasks, in order)

| # | Task | Contents | Model | Full suite | Size |
|---|---|---|---|---|---|
| **P6e-1** | **Data + admin, switched OFF** | migration (`catalog_collections`, `catalog_product_collections`, unique pair, short index names), `Collection` entity / model / repository, `ProductCollection` pivot repository, `CollectionResource` (clone of `SeasonResource`: name, slug, picture, product count, delete guard), Catalog settings toggle `catalog.collections_enabled` (default OFF), product form picker + sync in the 4 create/edit pages, list filter, view entry, P6d mass add/remove, audit, duplicate-product copy, media-usage provider; **every UI hidden when OFF; no public route**. Tests: write path, audit, mass edit, OFF hides each item, `getMany` for the admin list so its pinned count stays 40 | Sonnet | **yes** (touches `ProductResource`, settings, goldens of the admin list) | M (~25 files) |
| **P6e-2** | **Storefront page + filter** (registry) | `CatalogSubjectKind` registry replacing the arrays of D (mechanical, season/tag pins as the net); `/collection/{slug}` route + controller method + `StorefrontUrls::collection()`; reader `subject()/cloud()/applyScope()/facets`; sidebar filter (`filterCollection`, order, kind, `PageDesign`, «Сайдбар» zone) with the cache invalidation of E; «Съдържание» kind `collections` (own default template + override); OFF = 404 via the in-query EXISTS; breadcrumbs + lang; browser script | Sonnet | **yes** | L (~30 files) |
| **P6e-3** | **Integrations** | HTML-block conditions `has_collection` + editor field; home product grid / carousel sources, tiles (+ listener, recompile), `SectionMeta` third member; price-list scope `COLLECTION` (`PriceListScopeType`, `CatalogScopeResolver` with the +1-query mitigation of F, `CatalogScopeLookup`, `PriceListAdminReader`, dispatcher, **`SortPriceTriggers` on the pivot**, `PriceListResolutionEngine` untouched) | Sonnet | **yes** (pinned prices pages) | M (~20 files) |
| **P6e-4** *(optional, later)* | promo-code scope `PromotionScopeType::COLLECTION`; a menu item type «collection»; help page | Sonnet (promo) / Haiku (help text) | targeted folders only | S |

Order rationale: P6e-1 ships a complete feature that is invisible (no public route, no filter, no menu), so a half-visible state never exists; P6e-2 makes the pages visible only when the owner switches it on; P6e-3 is the only task that can move a pinned query count (price resolution) and therefore stands alone with its own full run.

## H. Open questions for the owner (with a recommendation)

1. **URL prefix of the public page.** (a) `/collection/{slug}` — matches `/season/…`, `/brand/…` (recommended); (b) `/kolekcia/{slug}` — a Bulgarian slug; (c) `/collections/{slug}` — plural, like a listing index.
2. **«Съдържание» template.** (a) a **separate** kind `collections` with its own default template and per-page overrides (recommended: one more key in the existing JSON, no migration, no coupling to seasons); (b) share the seasons' default — fewer settings, but changing one page type changes the other.
3. **What a collection has.** (a) name, slug, **one picture** — exactly the season (recommended; banner/SEO text then come from the «Съдържание» template's top HTML block and bottom slot, as on the season page); (b) + sort order (menus/home tiles by hand-picked order); (c) + a description / dates (campaign window). Note: the season has none of these; (c) is new fields on a new table and a new editor.
4. **First wave scopes.** (a) price-list scope in P6e-3, promo-code scope later in P6e-4 (recommended: promotions cannot be scoped to the season either, the price-list scope is the one with the query-count mitigation); (b) none in the first wave; (c) both in P6e-3.
5. **Menu items.** (a) none — a collection is reachable by a normal link item (recommended: no season/brand/tag menu item type exists today, so a «collection» type would be a new menu feature, not parity); (b) add a menu item type «collection» in P6e-4 (type, target picker, compile, editor, `ALLOWED` lists `MenuValidator.php:45`).

---

## As built (P6e-1) — data + admin, switched OFF

- **Data:** migration `2026_11_12_000001_create_catalog_collections_tables.php` — `catalog_collections` (name, slug unique `ccol_slug_unique`, `picture_media_asset_id` RESTRICT like the season's) and the pivot `catalog_product_collections` (unique pair `cpcol_product_collection_unique`, both FKs cascade). Domain `Collection` (cloned from `Season`) and `ProductCollection` (cloned from `ProductTag`), `CollectionRepository` (in-use count through the pivot) and `ProductCollectionRepository` (grouped `findByProductIds`), `ProductModel::collections()`. `Product.php` is untouched.
- **Switch:** `catalog.collections_enabled`, ON only for the literal `'1'` (a missing key = OFF). `ProductResource::collectionsEnabled()`; the five Catalog switches of the products screen are now read by ONE `getMany` (`catalogSwitches()`), so the products list renders with the same or fewer queries (pinned 40 / 32 / 30 / 30 unchanged; ON = OFF = 8 in the render test).
- **Admin:** `CollectionResource` (sort 71, between «Сезони» and «Артикулни групи»; hidden in the navigation and 403 on a direct URL while OFF; the in-use delete block is the tag's: a collection that is in use cannot be deleted, the pivot FK cascade is the database's last guard), product form section «Колекции» (multiple select), view entry, list filter `collections` with LAZY options (so ON adds no query), duplicate copies the pivot, P6d mass add / remove (`field_off` in the service), `CollectionPictures` media-usage provider, `SortPriceTriggers` registers the pivot model.
- **Hidden field:** `SyncsProductCollections` skips the sync while the switch is OFF or the key is absent, so a product saved while OFF keeps its collections (tested for the simple and the variable edit page).
- **Not in this task:** any storefront route, reader, filter, «Съдържание» kind, conditions, home source or price-list scope (P6e-2…4).


## As built (P6e-2) — registry + public page + filter + «Съдържание»

- **Registry (read side only):** `App\Storefront\ReadModels\CatalogSubjectKind` (`brand`, `season`, `tag`, `collection`): table, URL prefix, scope, «Съдържание» kind, filter key, crumb / filter-name lang keys, the «Каталог» field of the filter's look, the product COLUMN or the PIVOT, and how the kind narrows a product query (`constrainScope`: the fixed scope of a subject page and the cloud; `constrainFilter`: the visitor's choice; `facetIds`; `hasAnySubselect`; `gated()`: the switch inside a statement). The old literal arrays and `match` arms read it: `CatalogReader` (subject lookup, cloud, scope, facet ids, the `has_*_filter` scalars), `FilterConstraint::BUILTIN` / `builtin()`, `SidebarFilters::BUILTIN`, `ContentTemplate::KINDS`, `ListingQuery::SCOPE_KEYS`, `StorefrontUrls`, the four routes (one loop), `FilterCatalog`'s order, `ContentZone::names()`, `SidebarStore` / `SidebarZone` / `CatalogZone` / `DesignPreviewController` (the «Каталог» fields). The brand / season / tag queries have the SAME SQL text as before (`CollectionStorefrontTest` builds the legacy statement next to the registry's and compares `toSql()` and the bindings). Not moved: the write side, the home sources (`HomeCatalog`, `HomeValidator`, `HomeTiles`, `HomeZone`), conditions, `SectionMeta`, price-list scope (P6e-3).
- **Page:** `GET /collection/{slug}` → `SubjectListingController::collection`; `StorefrontUrls::collection()`; `ListingScope::COLLECTION`; crumb `storefront.crumb_collection`. The lookup carries `and exists (select 1 from site_settings where key = 'catalog.collections_enabled' and value = '1')` inside the same statement: **OFF = the storefront's 404 at the cost of a missing slug, no query of its own**. The season page shows no picture today, so the collection page shows none either (the picture serves the admin and, later, the home tiles).
- **Filter:** `filter[collection]=1,2` (OR inside, AND with the others), offered only while ON. `FilterCatalog` (cache key `…v3`) reads the collections in the same UNION, each member gated by the switch inside the statement, plus one marker row `collections_on` (a switched-on shop with no collection yet still knows the key). The cache is forgotten when the Catalog settings are saved and when a collection is saved / deleted. The listing's `has_collection_filter` scalar carries the switch inside its subselect.
- **«Сайдбар»:** the key `collection` is a built-in like the tag; **hidden by default** (`FilterCatalog::HIDDEN_BY_DEFAULT`, like «Препоръчани»), so a shop that switches the collections on sees no new filter until the merchant shows it. The look is the «Каталог» field `filterCollection` (default `off`, a soft field). While OFF the editor does not know the key; a stored row / kind is left as stored (and a publish while OFF carries the stored row over), so ON again restores it.
- **«Съдържание»:** kind `collections` with its own default template and per-page overrides (`ContentTemplate::KINDS`, JSON, no migration); the editor offers it only while ON (`ContentZone::offeredKinds()`); the stored templates stay in the set while OFF. The `current` source of a collection page's bottom carousel reads the collection (`CatalogReader::homeProducts` has a `collection` arm; the home page's own sources remain a closed list).
- **Pins:** subject pages 27 (brand / season / tag) and the collection page ≤ 27; the category listing costs the same with the switch ON and OFF.
