# EasyCo Storefront — Read Layer, Merchandising, Funnel (Design)

**Status:** DESIGN ONLY (2026-10-09). Nothing in this document is built. It extends and, in the places listed in §0.2, supersedes
`storefront-frontend-design.md` (the earlier draft: Blade + Alpine, URL structure, JSON-LD, hydration of personal fragments).
It is written for the owner (decisions in §14) and for the coders (stages in §13).

**Conventions.** **V** = verified by reading the code or documents named. **I** = my inference or proposal. Anything I could not verify
is under *Unknowns* in §14.2. Everything in code and documents is English; the shop's language is chosen by `site.locale`.

---

## 0. Where we start

### 0.1 Verified starting point (V)

- `routes/web.php` has one route: `/` returns `resources/views/welcome.blade.php`. There is no real storefront. The only customer-facing
  pages are the sandbox under `/_sandbox` (routes registered by `App\Providers\SandboxServiceProvider::boot()` only when
  `config('sandbox.enabled') && ! app()->isProduction()`; middleware `web` + `NoIndexHeaders`; routes `/`, `/products/{productId}`, `/cart`,
  `/checkout`, `/order-placed`). Product URLs in the sandbox use **ids**, not names.
- **There is no public catalog read API.** `routes/api.php` exposes GET `/api/categories`, `/api/brands`, `/api/tags`, product media and stock only
  inside the `auth:staff` group; public routes are account, addresses, cart, checkout and `POST /api/shipping/quote`.
- The rule "a product is shown to a customer" exists **only** in `App\Sandbox\SandboxCatalogReader` and is documented there as provisional:
  product `status = active` AND `catalog_visibility = visible`; a VARIABLE product shows its STANDARD variations with `status = active` and
  `is_visible = 1`; a SIMPLE product shows its single UNIVERSAL variation on `status = active` alone (the domain forces `is_visible = false` for it).
  Catalog itself has no "may a customer see this" concept (`catalog_products` has `status` draft|active|archived, `catalog_visibility` visible|hidden,
  `is_featured`, `timeline_at`, `brand_id`, unique `slug`, soft deletes, index `(status, catalog_visibility)`).
- The sandbox list holds a fixed query count (its docblock: the page query + the paginator count + one eager brand read + a correlated first-image subquery,
  prices through ONE `ProductPriceRangeProvider::forProducts()` call; proven by `SandboxProductListQueryCountTest` with 5 and 24 products). The sandbox product
  page does per-image and per-variation work (owner's audit: N+1).
- Images: the Media pipeline writes WebP renditions next to the original as `{basePath}-{tier}.webp` with tiers from `config('services.media.image_variants')`:
  **`thumbnail` 400 px, `medium` 900 px, `large` 1600 px** (scale: fit inside a square bound, aspect kept, never upscaled; quality 80/82/85) and `admin_grid`
  (42x42 cover, admin only). Variant rows carry their real width and height (`MediaVariant`: tier, width, height, quality, path). There is **no** tier called "small".
  Processing runs on a queue worker (`ProcessMediaAssetJob`).
- Pricing: `ProductPriceRangeProvider` (per-request memoised, scoped) returns a `PriceRange` of `PriceQuote`s; a quote answers `isDiscounted()`; the range exposes
  `lowestFinalQuote()`, `lowestRegularPrice()` and `lowestDiscountedFinalQuote()`. Price lists have modes `fixed_items` and `percentage_off_regular`, scopes
  (brand, category, tag, attribute value, customer group, channel, product), validity dates and a priority.
- Promotions (`promo_promotions`): unique `code`, discount type/percentage/amount, `valid_from`, `valid_until`, `status`, usage limits, minimum spend, scopes. **There is no
  "advertise this code publicly" flag.**
- Settings: `site_settings` (key, text value; `SiteSettingsRepository::get/set/forget`, no built-in defaults — the caller supplies them). Used today for `site.locale`,
  `site.timezone`, `site.country`.
- `Hook` (Extensibility) is the extension mechanism: filters and actions registered in `documents/extensibility-design-and-hooks.md`'s Hook Reference. `order.placed` is fired
  by the checkout and **has no listener**.
- `public/robots.txt` is `User-agent: *` / `Disallow:` (everything allowed). `public/` has no sitemap.
- The `web` middleware group carries the session and cookie middleware; `ApplyStoreLocale` and `ApplyStoreTimezone` are appended to it (`bootstrap/app.php`).
  `.env.example`: `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`.
- No consent mechanism, no marketing-consent field and no mail sending exist anywhere (no `Mail::`, no Mailable). `accounts` has `email`, `password`, remember token,
  timestamps, soft deletes — nothing about consent.
- Carts (`carts`): `account_id` unique nullable, `session_token` unique nullable, `expires_at`, `applied_promotion_code`, `order_id`, claimed identity columns, timestamps.
  A guest cart stores **no email address**.

### 0.2 Relationship to existing documents (contradictions, and which side wins)

| Existing statement | New decision | Resolution |
|---|---|---|
| `storefront-frontend-design.md` §6: the storefront "consumes its own product's API" and, for a missing read, adds a read endpoint to the relevant domain's controller | Owner decision 1: a **read layer** used directly by Blade; the JSON API is a thin wrapper over the same methods | **New wins** for READS. For state-changing calls (add to cart, promo, checkout) the existing §6 rule stays: Alpine `fetch()` against the existing API. |
| Same doc §8: every cacheable route must answer with **no `Set-Cookie`** and a public `Cache-Control` | Funnel events need a visitor identifier, only with consent | **Both hold**: the consent flag and visitor id live in the browser (JS-set first-party storage), never in a server `Set-Cookie` on a cacheable page; events are POSTed to an uncached endpoint (§10). Cacheable routes need a middleware group **without** the session (the default `web` group has it) — see §3.4. |
| Same doc §9 defers search, hero sliders and CMS content | Owner decisions 2 and 3 bring them in | **New wins**; §7 and §8 specify them. |
| Task brief and audit: image tier "small" | Real tiers are `thumbnail`/`medium`/`large` | Documents use the real names. |
| `production-requirements.md`: Varnish is the reference full-page cache, "no CDN, no Cloudflare" | Owner mentions a Cloudways-style Varnish | Compatible: host-agnostic Varnish behind Nginx/Apache. §3.5 covers proxy behaviour. |

---

## 1. Goals, principles, non-goals, speed budget

**Goal.** A light, fast, modular storefront for a Bulgarian boutique whose funnel (browse → offers → cart → checkout → order → payment/couriers) is the owner's first priority.

**Principles.**
1. **One read layer, many faces** (decision 1): a PHP service reads and shapes catalog facts into immutable read models; Blade renders them; the JSON API serializes the very same objects.
2. **One rule for "visible"** (§2.2), in one class, pinned by tests.
3. **Complexity only when needed:** every merchandising feature is optional and OFF by default; an unused feature costs zero queries and zero bytes (§8.1).
4. **The domain stays pure** (CLAUDE.md rules 1, 9, 10): read models live in `app/`, domain packages never learn about HTML, schema.org, caches or hooks; hooks are fired from `app/` only.
5. **Input security in every decision:** search and filter parameters are whitelisted, length-capped and rate-limited; output is escaped; merchant-editable text is plain text or restricted markup (§8, mail document §5).
6. **Facts, not guesses:** every merchandising badge is derived from stored facts (price lists, dates, stock) or typed by the merchant; nothing is invented.

**Non-goals (this document).** A page builder or CMS; reviews and ratings; wishlists; customer accounts UI (deferred by `storefront-frontend-design.md` §9); multi-currency display; AI-agent feeds
(ACP/UCP/MCP, `channel-native-commerce-vision.md`); an external search engine implementation (only the swap point); campaign mail (see `mail-design.md`).

**Speed budget (targets, to be measured on the running app — see §14.2).**
- LCP < 2.5 s on a mid-range mobile over 4G for home, category and product pages; the LCP image is never lazy-loaded (§4.4).
- **< 20 database queries per catalog page** (home, category, product, search), **no N+1**; the query count is a pinned test number per page type (§2.5).
- Cacheable anonymous HTML served by the proxy without PHP when warm; a cold render stays inside the query budget.
- Total JS on a catalog page: Alpine plus a few small modules (no framework bundle); CSS from the existing Vite/Tailwind build.

---

## 2. The read layer

### 2.1 Where it lives (I)

`App\Storefront\` — a folder in the root application, not a domain package (same reasoning as `storefront-frontend-design.md` §1 and `checkout-domain-design.md` §1: it owns no aggregate).
It reads through Eloquent models and the existing batched services; a read is not a write, so the "UI must go through the domain layer" rule (which is about writes) is not engaged — the same
posture `SandboxCatalogReader` documents. Behavioural facts that live in domain objects (`Variation::isEffectivelyPurchasable()`) are read through the domain objects, loaded in ONE batch.

```
App\Storefront\
  Visibility\StorefrontVisibility        the ONE rule (§2.2)
  ReadModels\ (immutable, final readonly)  ProductCard, ProductPage, CategoryNode, CategoryPage, ListingPage, Facets, Badge, ImageSet, HomeBlock*, Breadcrumb
  Reader\CatalogReader                   the public methods (§2.3)
  Search\ProductSearch (interface)       + DatabaseProductSearch (§7)
  Merchandising\BadgeResolver, HomeBlocks, StorefrontSettings
  Seo\StructuredData, SeoMeta, SitemapBuilder
  Http\Controllers\Web\*, Http\Controllers\Api\Storefront\*   thin
```

### 2.2 The ONE "active and visible" rule

`StorefrontVisibility` is the single source of truth. It exposes **query scopes** (for lists) and **predicates** (for a loaded row), both generated from the same constants, so the two cannot disagree:
- Product: `status = active AND catalog_visibility = visible AND deleted_at IS NULL`.
- Variation shown: VARIABLE product → `type = standard AND status = active AND is_visible = 1`; SIMPLE product → its UNIVERSAL variation on `status = active`.
- A category is shown when it has at least one visible product in itself or its descendants (computed once, cached, §3), or when the merchant marks it shown (I: a later flag; not in V1).
- A brand/tag page is shown when it has at least one visible product.
- "In stock" is a **fact about a shown variation** (`stock_levels.quantity > 0`), never part of "visible": an out-of-stock product stays visible and says so (decision O4 in §13).

Tests that pin it (`StorefrontVisibilityTest`): every combination of status × visibility × deleted for a product; the two variation branches; the predicate and the scope return the same set for a mixed fixture; a hidden product is a **404** on its URL, in the listing, in search, in the sitemap, in the JSON API and in related-product blocks. The sandbox's reader keeps working until the storefront replaces it, then `App\Sandbox` is deleted as its own docblock plans.

### 2.3 Public methods (all return read models; arrays via `toArray()`)

| Method | Returns | Notes |
|---|---|---|
| `product(string $slug)` | `ProductPage` or null | gallery, variations/axes, price block, stock, brand, breadcrumb, badges, SEO facts |
| `category(string $path)` | `CategoryPage` or null | resolves by the LAST segment (slugs are globally unique), carries the canonical path |
| `listing(ListingQuery $q)` | `ListingPage` | category/brand/tag/search scope + filters + sort + page; items are `ProductCard`s; includes `Facets` |
| `search(string $term, ListingQuery $q)` | `ListingPage` | through `ProductSearch` (§7) |
| `homeBlocks()` | list of `HomeBlock` | the merchant's chosen block (§8.2) |
| `categoryTree()` | `CategoryNode[]` | for menus; cached |
| `related(string $productId, int $limit)` | `ProductCard[]` | same category, visible, in stock first |

`ListingQuery` is an immutable value object created ONLY from a validated request (§6, §7): category/brand/tag id, price min/max (minor units), attribute value ids, `in_stock`, `sort` (enum), `page` (1..cap), `per_page` (enum 12|24|48), `term` (≤ 80 chars after normalisation).

### 2.4 Read models

Immutable (`final readonly`), no framework types, every field scalar/array so `toArray()` is exact and the API and Blade see the same facts. Core shapes:

```
ProductCard   { id, slug, url, name, brand: ?{name, slug}, image: ?ImageSet, price: {from_minor, to_minor, currency, regular_from_minor?},
                badges: Badge[], sizes: ?string[], in_stock: bool }
Badge         { type: new|sale|custom|promo|free_shipping, label: string, placement: image_top|image_bottom|under_price, style: token, priority: int }
ImageSet      { alt, width, height, srcset: [{url, width}], sizes_hint: string, lqip?: string }   // §4
```

`url` is built by the read layer from the slug (§5) so Blade, the sitemap and the API never build URLs themselves.

### 2.5 Batched resolution and the query budget

Every page type has an explicit budget and a test that fails when it is exceeded. The mechanism: **one query per concern per page, never per row**:
1. the page of products (with brand eager-loaded);
2. the count (paginator) — replaced by `simplePaginate` + a cached total where counts are expensive (I);
3. first image per product: the sandbox's correlated subquery, extended to return the media id so step 4 can load variants once;
4. image variants for the page's media ids: ONE query;
5. prices: ONE `ProductPriceRangeProvider::forProducts()` call;
6. stock for the page's variations: ONE grouped read (`variation_id → sum(quantity)`);
7. sizes (axis values) for the page's products: ONE grouped read, only when the "sizes" feature is on;
8. badges inputs: price-range facts come from step 5; "new" from `timeline_at` already loaded; custom badges: ONE read of active rules, only when the feature is on; promo badge: ONE read of public promotions, only when on;
9. settings: ONE read of the storefront settings group, memoised for the request.

| Page | Budget (queries) | Composition |
|---|---|---|
| Category / listing | ≤ 14 | steps 1–9 + category lookup + facets (2) |
| Product | ≤ 16 | product + brand, ALL gallery media and variants (1+1), all shown variations with their axis values (2), prices for all variations (the existing batched resolvers), stock (1), related cards (reuse steps 1–6), settings |
| Home | ≤ 12 | settings, the chosen block's data, featured/new cards (steps 1–6) |
| Search | ≤ 14 | as listing, minus facets plus the search read |

How a test asserts it: `DB::listen`/`DB::getQueryLog()` around the real request with fixtures of 5 and 24 products (as the sandbox test does); assert (a) total ≤ budget and (b) **equal counts** for 5 and 24 products. A second assertion pins the query for each feature OFF vs ON (an OFF feature adds zero queries — §8.1).

### 2.6 As built in S1 (facts found in the code, and where the build differs from the text above)

**Built:** `App\Storefront\Visibility\StorefrontVisibility`, the read models (`final readonly`, exact `toArray()`), `Reader\CatalogReader` (`product`, `category`, `listing`, `categoryTree`), `Reader\CategoryIndex`, `Reader\ImageSetBuilder`, `Url\StorefrontUrls`, `ReadModels\ListingQuery`. No route, view, controller, cache, hook, migration or JSON API; `App\Sandbox` is untouched. Golden files are in `tests/Fixtures/storefront/`.

**Facts located in the code (these replace the "to be located" unknowns):**
- **Ids** are auto-increment integers (`catalog_*` tables), carried as strings; `ListingQuery` accepts `[1-9][0-9]{0,18}`.
- **Categories:** `catalog_categories` (`id`, `parent_id`, `name`, `slug` unique); no soft delete, no active flag, no description. The tree is `parent_id`. A product belongs to many (`catalog_product_categories`).
- **Stock:** `stock_levels` (`variation_id` unique, `quantity`), one row per variation, no row = 0. Read in one `whereIn`.
- **Images:** renditions are NOT rows. They are a JSON column `variants` on `catalog_media` (`{tier,width,height,quality,path}`), written when processing is `ready`. "Variants for the page's media ids in ONE query" is therefore one `catalog_media` read. Only `thumbnail`/`medium`/`large` reach a customer (`admin_grid` never).
- **Prices:** per variation, `PriceRangeResolver::resolveQuotes()` fed by `CatalogScopeResolver::forVariations()`; `ProductPriceRangeProvider::forProducts()` wraps both. `PriceRange` exposes the lowest final quote, the lowest regular price and the lowest discounted quote, but **no maximum**.
- **`description` is rich text** (the admin edits it with a RichEditor), so it may hold HTML. `ProductPage` carries it as stored; S2 must sanitise it before printing it as markup. Every other text field is plain and raw.

**Where the build differs from, or fills in, the design:**
- **Query budgets are NOT met for the listing and the product page.** Measured after S1b: listing 19 (design 14), category listing 21, product page 22 / 21 (design 16), category tree 2 and category page 2 (design 3). All are identical for 5 and 24 products, with 2 and 6 variations, with 1 and 8 images: there is no N+1. The gap is price resolution alone, through `CatalogScopeResolver` (6 queries) and the pricing resolver (7): 13 queries on a listing and on a product page. The tests pin the measured ceilings; the design numbers stay as constants and a skipped test. Options for the owner: raise the budgets to 19 / 22; or feed `PriceRangeResolver` directly from the rows the reader already holds (reaches about 14 / 16 but re-implements the scope assembly of `CatalogScopeResolver`); or change the pricing services.
- **Prices (S1b):** a card and a product page share one code path: the exact block (lowest and highest final price, regular price of the cheapest quote when discounted) of the quotes of the product's SHOWN variations, resolved in ONE batched call per page. `ProductPriceRangeProvider` is no longer used by the reader (it priced every non-archived variation, so a draft or hidden variation could lower a card's "from" price). `to_minor` is therefore exact on cards too. This removed the provider's own variation read (listing 20 -> 19).
- **Price filter and price sort are validated by `ListingQuery` but refused by the reader** (`ListingOptionNotAvailable`): they need S7's `price_from_minor`. Nothing is silently ignored.
- **A category listing includes the products of its descendants**; `ListingScope::ALL` (no scope id) lists the whole catalog. `ListingQuery` takes at most one scope.
- **Category counts** are distinct visible products of the subtree, from one read of the visible (category, product) pairs (the scaling cost of exact counts; S4 caches it). A category outside the "shown" rule is `null` from `category()`.
- **URLs** are root-relative paths with percent-encoded segments (`/product/{slug}`, `/product-category/{path}`, plus `/product-tag/{slug}` and `/brand/{slug}`); a segment is `[\p{L}\p{M}\p{N}-]`, 1 to 200 characters, a path 1 to 8 segments; anything else is refused before any query.
- **Lookup case:** the product lookup follows the database collation (case- and accent-insensitive on MySQL, case-sensitive on SQLite) and the page carries the stored slug and canonical url so a caller can redirect; the category lookup lower-cases both sides.
- **A SIMPLE product's page** lists its universal variation in `variations` (with no attributes): its id is what add-to-cart needs. `purchasable` mirrors `Variation::isEffectivelyPurchasable()` and is pinned against the domain method.
- **Variation order** is `sort_order, id` (the column exists since the 2026-09-23 migration; the sandbox still orders by id). `options` follow the attribute definition id and the value `sort_order`.
- **ImageSet** carries `src` (the `medium` tier, else the largest ready variant) besides the design's fields; `lqip` is not built.
- `sizes` (S10), badges (S10) and facets (S7) are empty arrays.

---

## 3. Caching

### 3.1 What is cached, where, for how long (I)

| Layer | What | Key | TTL / validity |
|---|---|---|---|
| Reverse proxy (Varnish) | anonymous HTML of home, category, product, brand/tag, listing pages, sitemap, robots | URL (query string normalised: allowed parameters only, sorted) | `s-maxage` 300 s for lists/home, 600 s for products; `stale-while-revalidate` 60 s; purge on events (§3.3) |
| Application cache (Redis; `database` in dev) | `categoryTree()`, category product counts, facet option lists, storefront settings group, home block data | `storefront:{what}:v{n}[:{id}]` | no TTL beyond a safety 24 h; invalidated by events (versioned key bump) |
| Request memo | prices, settings | scoped instance | one request (already how `ProductPriceRangeProvider` works) |
| Browser | images (immutable, versioned), CSS/JS (Vite hashes) | file URL | 1 year, `immutable` |

Caching stays **outside the domain**: it lives in `App\Storefront` (and the existing rule of `performance-and-channel-strategy.md` §1 — repositories may cache — is not extended into the read layer's models). The read layer caches *its own read models by key*, not Eloquent entities.

### 3.2 Never cached

The cart, `/api/cart`, checkout, account and every response that depends on a session, a cookie or a person; `POST` endpoints; the funnel-events endpoint; any page rendered with `Set-Cookie`. A test enforces the list per route (§3.4).

### 3.3 Invalidation — the events

Named, so the coder wires exactly these (all fired from `app/` after commit):
- **Catalog change:** a product/variation/category/brand/tag is created, updated, archived, restored or its taxonomy changes → bump `categoryTree` and `product:{id}` keys; purge the product URL, its category/brand/tag URLs and home.
- **Price change:** a price-list item or list (status, validity, priority, scope) changes → purge affected product URLs (by scope: product ids, or category/brand/tag pages) — coarse is acceptable: purge all catalog pages and rely on the TTL for the rest (I: start coarse).
- **Stock change:** an order (stock decrease), a stock update or a restock → purge that product URL; category lists tolerate the TTL, except the "in stock" filter and sold-out badge which accept up to `s-maxage` staleness (O5).
- **Settings change:** any `storefront.*` setting → bump the settings key, purge home and all catalog pages.
- **Scheduled boundary:** a promotion/price-list/custom badge `valid_from`/`valid_until` passing is a *time event*: a scheduled command (every minute) finds rules whose boundary passed since the last run and purges the affected URLs. Without it a "Black Friday" badge would appear up to `s-maxage` late.
Many of these have no hook today (V: only `order.*`, `shipping.*`, `catalog.*` filter hooks, `account.registered` exist). The stage that builds caching adds a small `storefront.cache.invalidate` action fired by the existing writers and Eloquent observers, and registers it in the Hook Reference table in the same commit (CLAUDE.md rule 10).

### 3.4 HTTP caching of anonymous pages

- Cacheable routes live in their own route group with a **middleware set that has no session, no cookie encryption/queue and no CSRF** (the default `web` group carries all three; reusing it would add `Set-Cookie` and make Varnish pass). They still need `ApplyStoreLocale`; that middleware reads `site.locale` from settings, not from a session (V: `bootstrap/app.php` registers it on `web` — it must be registered on the new group too).
- Response headers: `Cache-Control: public, max-age=60, s-maxage=300, stale-while-revalidate=60`; `ETag` from a hash of the rendered body (or of the read model's `updated_at` max + settings version — cheaper, I recommend this); `Last-Modified` = the newest `updated_at` among the page's facts. Conditional requests answer 304 without rendering when the ETag can be computed from the version keys.
- Personal fragments (cart count, account name) are hydrated client-side from `/api/cart` and `/api/account/me` — already decided in `storefront-frontend-design.md` §4 and kept.
- **Tests (extending that document's §8):** every cacheable route returns no `Set-Cookie`, a `public`/`s-maxage` header and 304 for a matching `If-None-Match`; every non-cacheable route returns `private, no-store` and may set cookies.

### 3.5 Behind a reverse proxy

Varnish keys by URL. The query-string normalisation (drop unknown parameters, sort the rest, cap length) must happen **in the proxy** (VCL snippet shipped in the docs) *and* in the app (unknown parameters ignored and not echoed), otherwise `?utm_x=random` creates unlimited cache keys — the exact bot weakness `bot-traffic-and-rate-limiting-note.md` names. Purge: `BAN`/`PURGE` requests from the app to a configured proxy URL, authenticated by a secret header; if no proxy is configured the purge step is a no-op and TTLs rule. Trust the proxy for `X-Forwarded-*` only from configured proxy IPs (Laravel `trustProxies`). Cloudways-style managed Varnish: the same headers apply; their purge API replaces our `PURGE` call behind the same interface (`CachePurger`).

---

## 4. Images

- **Variants (V):** `thumbnail` 400, `medium` 900, `large` 1600 (WebP), original kept. These are the srcset widths. A product card uses `thumbnail`+`medium`; the product gallery uses `medium`+`large`; the LCP hero uses `large`. Whether a 1200 px tier or a 200 px "tiny" tier is needed is a measurement question (§13.2); adding a tier is a config change (`image_variants`) plus a backfill command, not code.
- **srcset/sizes:** `ImageSet.srcset` is built from the variant rows (real widths, so a small source that never upscaled advertises its true width). `sizes` is a per-placement string from a small map: card in a 4-column grid `(min-width:1024px) 25vw, (min-width:640px) 50vw, 100vw`, etc.
- **width/height:** always emitted from the variant row (the real dimensions) so the browser reserves space — no layout shift. Where the store's aspect ratio setting exists (`media.admin_grid_aspect_ratio`-style site setting for the storefront ratio, `media-domain-design.md` §3.3) the card container uses `aspect-ratio` CSS from that setting.
- **Lazy loading:** `loading="lazy"` and `decoding="async"` on every image **except** the LCP image of the page (first card row's first image on listings only if above the fold — the layout marks the first N=2 images eager; the product page's main image; the home hero). The LCP image gets `fetchpriority="high"` and a `<link rel="preload" as="image" imagesrcset imagesizes>` in `<head>`.
- **Formats:** WebP only (V: that is what the pipeline writes). AVIF is optional later: a new tier family generated lazily by a second job; browsers pick through `<picture><source type="image/avif">`. Not in V1 (O6).
- **Generate at upload vs lazily:** upload-time for the three tiers (already so, `ProcessMediaAssetJob`); lazy on-demand generation is rejected (a public endpoint that writes files is an attack surface and a cache-miss cost). Missing/pending variants: the read layer falls back to the largest READY variant, and to no image (a neutral placeholder) if none — the sandbox already filters `processing_status = ready`.
- **Serving:** files come from the public disk (`/storage/...`) or object storage (V: `MediaStorageAdapter`, `production-requirements.md`); the app sets nothing here, the web server/proxy serves them with `Cache-Control: public, max-age=31536000, immutable`; variant paths are never overwritten (a replaced image gets a new asset id), which makes `immutable` safe. The admin documents this as a web-server setting.
- **Alt text (V):** `catalog_media.alt_text` exists (nullable string, mapped by `EloquentMediaAssetRepository`). `ImageSet.alt` = the asset's `alt_text`, else the product name for the main image and `"{name} — {n}"` for the others.

---

## 5. URLs, slugs, redirects

- **Routes (kept from `storefront-frontend-design.md` §7):** `/product/{slug}`, `/product-category/{path}` (nested, resolved by the last segment, canonical = full ancestor path), `/product-tag/{slug}`; added: `/brand/{slug}` (I), `/search?q=`, `/` (home). Slugs may be native-script (V: the slug generator keeps Cyrillic).
- **Slug uniqueness (V):** `catalog_products.slug`, categories, brands are DB-unique. Tags/seasons per their tables.
- **Slug history (I):** a slug change today silently breaks every old link. Add table `catalog_slug_history` — `id`, `entity_type` (`product|category|brand|tag`), `entity_id`, `slug` (varchar 191), `created_at`; UNIQUE `(entity_type, slug)`; index `(entity_type, entity_id)`; a row is written **by the writer that changes a slug**, with the OLD slug. Lookup order for `/product/{slug}`: current slug → else history → **301** to the current URL → else 404. A slug that is reused by a different entity later wins over the history row (the history row is deleted when its slug is taken by a live row, in the same transaction).
- **Canonical:** every page emits `<link rel="canonical">` with the absolute, normalised URL (no tracking parameters; the page-1 canonical drops `?page=1`; filtered/sorted listings canonicalise to the unfiltered category except `?page=n`).
- **Migrating raf.bg (WooCommerce) (I):** the structure already mirrors WooCommerce (`/product/{slug}`, `/product-category/...`, `/product-tag/...`), so most URLs survive unchanged if the slugs are imported. For the rest: table `storefront_redirects` — `id`, `from_path` (varchar 500, unique, stored normalised: lowercase host-less path, no trailing slash, no query), `to_path` (varchar 500), `status` (301|302|410), `source` (`import|manual`), `hits` (unsigned bigint, updated lazily — not on the hot path), `created_at`. Lookup happens only on a 404 (so it costs nothing on a hit), cached negatively for a short time. A CLI importer takes a CSV of old→new (the owner exports WooCommerce URLs). Loop guard: from ≠ to and no chain deeper than 3 (checked when a row is saved). Admin: a plain list with import — Stage S9.
- **Pagination URLs:** `?page=n` (rel prev/next not used by Google; the canonical rule above suffices).
- **Security:** slugs/paths are matched against a strict pattern (`[\p{L}\p{N}-]` segments, length-capped) before any lookup; nothing user-typed is echoed unescaped.

---

## 6. The public JSON API — a thin wrapper

- **Endpoints (I), read-only, public, unauthenticated, GET:** `/api/v1/storefront/products/{slug}`, `/categories` (tree), `/categories/{path}`, `/listing` (query: category, brand, tag, q, filters, sort, page), `/search`, `/home`. Each controller is ~10 lines: validate the query into a `ListingQuery`, call the `CatalogReader` method, return `$model->toArray()` inside `{data, meta}`.
- **Resource shape = read model.** No separate resource classes: the DTO's `toArray()` IS the JSON. Therefore the Blade side and the JSON side cannot diverge on facts; they can differ only in rendering.
- **Versioning:** URL prefix `/api/v1/`. Adding fields is non-breaking; removing or renaming one is a `v2`. The cart/checkout API stays at `/api/...` unversioned for now (existing clients; V: tests and sandbox use them) — a v1 alias is a later, optional step.
- **Rate limiting (V gap: no general throttling exists today — only login and `shipping/quote`, per `bot-traffic-and-rate-limiting-note.md`):** named limiters in `App\Http\ApiRateLimits` (the project's one place): `storefront-read` 120/min per IP, `storefront-search` 30/min per IP, with a per-IP+URL burst guard on `listing` filter combinations. Responses 429 with the project's translated body.
- **Bot protection:** whitelist of parameters (unknown ignored), max `per_page` 48, max `page` 500, term length 80, max 5 attribute filters, price range clamped; no wildcard/regex search syntax; expensive combinations (deep page + sort by price + filters) cap at the page limit. Search results are not cacheable at the proxy by default (high cardinality) but the *application* caches popular `term+filters` result ids for 60 s (I).
- **Contract test (`StorefrontParityTest`):** for a fixture catalog, request each page through Blade and the JSON API; extract the facts from the HTML (JSON-LD `Product`/`Offer`, card data attributes the templates emit: `data-product-id`, `data-price-minor`) and assert they equal the JSON's `data` for names, slugs, prices, stock, badges, image URLs. The templates render from the same read model, and this test is the guard against anyone adding a second source.

---

## 7. Search and filters

### 7.1 The interface (I)

```
interface ProductSearch {
    public function search(SearchQuery $query): SearchResult;   // ordered list of product ids + total + optional facet counts
    public function supportsFacets(): bool;
}
```
`SearchQuery` = normalised term, `ListingQuery` filters, sort, page. `SearchResult` returns **ids in rank order**; the read layer then loads cards for those ids with the batched steps of §2.5 (so an external index never needs to know prices or stock formats). Visibility is applied by the read layer after the search as a *safety filter* even for external drivers (an index can be stale): ids are re-checked in the page query with `StorefrontVisibility`.

### 7.2 `DatabaseProductSearch` (default driver)

- Searches `name`, `sku` (variation SKU and base SKU), brand name and category names. Normalisation (`SearchNormalizer`): lowercase (mb), trim and collapse whitespace, strip diacritics for Latin, **fold Cyrillic/Latin transliteration only for the same query term** (a customer typing "rokliq" or "рокля" finds "Рокля"): store a `search_text` column on `catalog_products` (I: new nullable column + `FULLTEXT`/prefix index) built by an observer from name + brand + SKUs + category names in both scripts. Term matching: every word must match as a prefix (`word%`) — `AND` semantics; ranking: exact name > name starts-with > word match in name > brand/SKU > category. Result ordering inside equal rank: `timeline_at desc, id desc`.
- Indexes: `catalog_products(search_text)` FULLTEXT (MySQL/MariaDB; SQLite falls back to `LIKE` in tests); `catalog_variations.sku` exists and is NOT NULL (V: migration `2026_08_23_000015`); whether it carries an index must be checked by S7 (prefix search on SKU needs one); brand name prefix via the eager brand read.
- Limits: term ≥ 2 characters (else the empty-state), ≤ 80, at most 6 words, results capped at 1000 ids before pagination.

### 7.3 The add-on swap point

`ProductSearch` is bound in a service provider through a manager: `config('storefront.search.driver')` (default `database`); an add-on package registers a driver with `StorefrontSearch::extend('meilisearch', fn () => new MeilisearchProductSearch(...))`. **Keeping an external index in sync:** the add-on listens to the same `storefront.cache.invalidate`-family events (catalog product saved/archived/deleted; price and stock changes only if it indexes price) — never by polling the database. A full re-index is an artisan command the add-on ships. The read layer calls only `ProductSearch`; swapping drivers touches no storefront file.

### 7.4 Filters and sort

- **Filters:** category (path), price range (min/max in minor units of the store currency, applied to the lowest final price — needs a derived `price_from_minor` per product, see below), attributes (one or more value ids per attribute, AND between attributes, OR within), brand, in stock.
- **Price filter without N prices (I):** filtering by *resolved* price needs the price logic. V1 approach: filter on the product's **regular price** column cache `catalog_products.price_from_minor` maintained by an event (price-list item/stock change), documented as "regular price, discounts not applied"; discounted filtering is deferred (O7). Rejected: resolving every product's price in PHP per filter request (unbounded work).
- **Sort:** `newest` (default, `timeline_at desc`), `price_asc`, `price_desc` (by `price_from_minor`), `name`, `featured` (`is_featured desc, timeline_at desc`). `relevance` only with a term.
- **Facet counts:** two grouped queries (attribute values, brands) limited to the current result set, cached 60 s by filter hash; the facet UI shows counts only when `supportsFacets()` or the DB driver computes them (budget in §2.5).

---

## 8. Merchandising features (all optional, all OFF by default)

### 8.1 The cost-of-unused rule

A feature has one master switch in the settings group `storefront.*` (read ONCE per request into a memoised `StorefrontSettings` object). The batched read of a feature's data (§2.5 steps 7–8) is **skipped entirely** when its switch is off, and the Blade partial for it is not included. A test per feature pins "OFF ⇒ same query count as the baseline and no markup of that feature". Defaults: all OFF; a fresh install renders plain product cards.

### 8.2 Home blocks (decision 3)

The merchant picks ONE home block type (setting `storefront.home.block` = `none|hero_slider|banner_grid`) plus up to N featured-products rows (a separate switch, using `is_featured`; the placeholder in `product-brand-slider-widget-note.md`).
- **Hero slider:** table `storefront_hero_slides` — `id`, `sort_order`, `media_asset_id` (FK `catalog_media`-style asset; image only), `mobile_media_asset_id` null, `heading` (varchar 120), `subheading` (varchar 200) null, `button_label` (varchar 40) null, `link` (varchar 500, validated: relative path or http(s) URL, no `javascript:`), `starts_at` null, `ends_at` null, `is_active`. Constraints: heading/subheading plain text; max 6 active slides; `ends_at > starts_at`. The first slide image is the LCP image (§4). Without JS the slider shows the first slide (progressive enhancement); autoplay off by default, controls labelled for screen readers, respects `prefers-reduced-motion`.
- **Banner block:** table `storefront_banners` — `id`, `block_id`, `sort_order`, `media_asset_id`, `heading`, `link`, `alt`, `is_active`; and `storefront_banner_blocks` — `id`, `layout` (`1`, `2`, `3`, `4`, `2-1`, `1-2`, `1-1-1`… a closed enum rendered by CSS grid classes), `sort_order`, `is_active`. A layout defines how many banner slots it has; saving a block with the wrong number of banners is refused.
- Admin (what the merchant sees): a "Storefront → Home" page: a Select for the block type, then a repeater for slides or a layout picker with image slots; a preview link. Uses the existing Media upload and the site-settings pattern (`SiteSettingsRepository`).
- Data lives in these tables + `site_settings` keys `storefront.home.block`, `storefront.home.featured_enabled`, `storefront.home.featured_count`.

### 8.3 Product card extras (decision 4) — one `BadgeResolver`

The card gets an **ordered** `badges[]` (sorted by `priority` then type), each `{type, label, placement, style, priority}`; the template renders by `placement` (`image_top` = upper part of the image, `image_bottom`, `under_price`). `style` is a token (`new`, `sale`, `custom-1..3`, `promo`, `shipping`) mapped to Tailwind classes in ONE partial, so themes restyle in one place; there are no colour values in data. Each badge has visible text (never colour-only) and `aria-label` where the text is abbreviated.

| Feature | Setting (default OFF) | Data | Automatic / manual | Read-model field |
|---|---|---|---|---|
| **New** | `storefront.badge.new.enabled`, `.days` (default 14) | `catalog_products.timeline_at` (V: exists, indexed with id) | AUTOMATIC: shown when `now − timeline_at ≤ days`. Open question: `timeline_at` may be moved by the merchant ("product timeline"); using it means "new" follows the timeline, which is the intended meaning (O8) | badge `new`, placement `image_top` |
| **Sale** | `storefront.badge.sale.enabled`, `.mode` = `percent|text`, `.text` | price facts: `PriceQuote::isDiscounted()`, regular vs final from `ProductPriceRangeProvider` (V) | AUTOMATIC: a product is on sale when `lowestDiscountedFinalQuote()` exists; `percent` = `round((regular − final)/regular×100)` of the **best** discounted variation, shown as "-N%" only if N ≥ 1 and N ≤ 99; for a range with different percents the label is "up to -N%" | badge `sale`, placement `image_top` |
| **Custom** ("Black Friday") | `storefront.badge.custom.enabled` | table `storefront_badges` — `id`, `label` (varchar 24, plain text), `style` (custom-1..3), `placement`, `priority`, `starts_at`, `ends_at`, `is_active`, `scope_type` (`all|category|brand|tag|product`), `scope_id` null | MANUAL, with a date range (the scheduled boundary job of §3.3 purges at start/end) | badge `custom` |
| **Brand above name** | `storefront.card.show_brand` | `brand_id` → name (V: brand eager-loaded in the sandbox list) | AUTOMATIC | `brand: {name, slug}` (always in the model; the template shows it when on) |
| **Sizes under price** | `storefront.card.show_sizes`, `.axis` (the size attribute definition) | the product's axis values for the configured size attribute (`catalog_product_axis_values`, V: exists) restricted to values with a purchasable, in-stock variation | AUTOMATIC from variations; string like "S M L" in the attribute's own order; sold-out sizes dropped (O9) | `sizes: string[]` |
| **Promo / free shipping** | `storefront.badge.promo.enabled`, `storefront.badge.freeship.enabled` | promo: a **new flag** `promo_promotions.is_public` (V: no such flag today) + validity; the code is shown on a product when the promotion is active, public, and its scope covers the product (scopes exist, V). Free shipping: a **store-level** fact: the cheapest method of the store's default zone with `free_above_minor` — "free shipping from 100 €" (stage 3e machinery, V) — shown as text, not per product | promo AUTOMATIC from public active promotions; free shipping AUTOMATIC from shipping settings | badge `promo` (label = the code), `shipping` |

Notes: (1) A promo code is a secret by default; a public flag is therefore an explicit merchant act (O10). (2) The "sale" badge must agree with the price shown (same quote object — one source). (3) Percent badges use integers only; no floats leak into labels. (4) At most `storefront.badge.max` (default 2) badges per card, by priority.

### 8.4 What the merchant sees in the admin

One Filament page "Storefront" with tabs: *Home* (block type, slides/banners), *Product cards* (switches and the few parameters above, each with a one-line explanation and a live preview of a sample card), *Badges* (custom badge list), *Search* (driver, read-only in V1), *Consent & analytics* (§10). All saves go through writer services that fire the invalidation event; the page is gated by the existing `settings_manage` permission (V: the enum has it).

---

## 9. SEO

- **Titles/meta:** `<title>` = `{product|category name} — {site name}` (site name from a setting); meta description from `short_description` stripped and cut at 155 characters (never raw HTML), else a template. One `<h1>` per page. Listing pages with filters/sort use `noindex,follow` and canonical to the unfiltered list.
- **Open Graph / Twitter:** `og:title`, `og:description`, `og:image` (the `large` variant, absolute URL), `og:type` (`product` for products), `og:url` = canonical, `og:locale` from `site.locale`.
- **JSON-LD:** as decided in `storefront-frontend-design.md` §5, generated from the SAME read model by a Blade component: `Product` (name, description, image[], sku, `brand` as an object, category), `Offer` (price from the same quote, `priceCurrency`, `availability` from stock, `url`), `BreadcrumbList` on product and category pages. `hasMerchantReturnPolicy`/`shippingDetails` stay conditional on the merchant having entered the facts (O11). No `AggregateRating` until reviews exist. The parity test (§6) decodes the JSON-LD with `json_decode`.
- **Sitemap:** `/sitemap.xml` index → chunked `/sitemap-products-{n}.xml` (≤ 5,000 URLs each), `/sitemap-categories.xml`, `/sitemap-pages.xml`; `lastmod` = the entity's `updated_at` (products: max of product/price/stock change is NOT used — only the product row, to keep it a cheap indexed read); built by the same `StorefrontVisibility` scope; generated by a scheduled command into the public disk (or cached) — never computed per request; `Cache-Control` 1 h.
- **robots.txt:** replace the open file with a dynamic or generated one: `Disallow: /admin`, `/_sandbox`, `/api/`, `/cart`, `/checkout`, `/account`, `/search` (parameters), `/*?*sort=`, `/*?*filter`; `Sitemap: {absolute}/sitemap.xml`. In non-production environments: `Disallow: /` for everything (V gap: the current file allows all everywhere).
- **hreflang:** the store runs ONE language at a time (`site.locale`, bg or en — V). If both languages are ever published at once they need separate URLs (`/en/...`); that is a decision for later (O12). V1: `<html lang>` from `site.locale`, no hreflang.
- **Product URLs by name:** §5 (slugs) — satisfied once the storefront routes exist.

---

## 10. Measurement — two tiers (decision 5, amended by the owner 2026-10-09)

**Decided by the owner 2026-10-09:** measurement has two tiers. **Tier 1 (no consent):** aggregate counters with no visitor identifier and nothing stored on the device. **Tier 2 (consent):** per-visitor funnel events. **Hashing does not remove the need for consent for tier 2** — a hashed, salted or random per-visitor identifier is still an identifier that links events of one person, so it is treated as personal data and as information stored on the device. Tier 2 is therefore OFF by default (`storefront.analytics.tier2_enabled`, §8.4 tab *Consent & analytics*), and with it off no consent banner is shown at all; tier 1 is ON by default and needs no banner.

### 10.1 Legal reading — to be confirmed by the owner (not legal advice)

The EU ePrivacy rules (cookie/"similar technologies" consent) apply to **storing information on, or reading it from, a visitor's device**; GDPR applies to **personal data**. My working reading: **tier 1** does neither — it stores nothing on the device, reads nothing from it, and stores no data that identifies or can single out a person (a daily count per metric and product) — so it needs no consent. **Tier 2** stores an identifier on the device and links events to it, so it needs consent in most EU member states unless the national authority has exempted strictly-aggregated first-party audience measurement under strict conditions (some do — Bulgaria's reading must be confirmed). **The legal reading must still be confirmed by the owner with a legal adviser:** (a) that tier 1 is indeed outside consent in Bulgaria, including the IP-based throttling described in §10.2 (kept only in a short-lived cache, never in the database); (b) whether tier 2 is exempt anywhere or always needs consent; (c) the wording of the consent text; (d) the retention periods of §12.1. The design below is built so that the **strict reading** (consent required for tier 2) costs nothing extra: without consent there is no identifier and no per-visitor record. Strictly necessary (no consent needed in either tier): the session for the cart and checkout (V: the cart token), CSRF, the consent choice itself.

### 10.2 Tier 1 — aggregate counters (no consent)

**What is stored — one table, `traffic_daily`:**

| Column | Type | Meaning |
|---|---|---|
| `day` | date | The store-time day (`site.timezone`) |
| `metric` | varchar(24), closed list | `page_view`, `product_view`, `category_view`, `search`, `add_to_cart`, `checkout_start` |
| `subject_id` | bigint unsigned NOT NULL DEFAULT 0 | The product id for `product_view` / `add_to_cart`, the category id for `category_view`, otherwise 0 (a real 0, not NULL, so the unique index below works on MySQL) |
| `hits` | bigint unsigned | The count |
| PRIMARY/UNIQUE | `(day, metric, subject_id)` | one row per day, metric and subject |

That is **everything**: no timestamp finer than the day, no IP address, no user agent, no referrer, no session or cart id, no cookie value, no search term (the `search` metric counts searches, not words; terms are never stored), no email. Orders are **not** counted here: `order_placed` per day is read from the `orders` table, the authoritative figure. `checkout_start` and `add_to_cart` may be counted on the server where the request already reaches the cart/checkout API (the seam is decided in S12, Unknown: whether the cart API fires a hook or needs a one-line call); `page_view`, `product_view`, `category_view` and `search` need the beacon below because cached pages never reach PHP.

**Why no identifier and no device storage:** a counter needs neither. Without an identifier nothing can be linked to a person and nothing is read from or written to the visitor's device (no cookie, no `localStorage`, no fingerprint), which is exactly what keeps this tier outside the consent rule in the working reading above. The price is stated plainly: **it measures views, not people** — "unique visitors" and returning-visitor figures do not exist in tier 1; the ratios available are hits per metric (add-to-cart per product view, orders per product view).

**How a cached page still counts — the beacon.** The page HTML is identical for every visitor (Varnish serves it, §3.4), and it contains a tiny inline script (~15 lines, no library) with the metric and subject already rendered into the markup (`data-metric="product_view" data-subject="123"`). When the page has been visible for ~1.5 seconds (`document.visibilityState`, a timer — most simple crawlers never wait), the script sends **one** request: `POST /t/c` (an uncached, session-less route in the cacheable middleware group of §3.4) with `fetch(..., {keepalive: true, credentials: 'omit'})` and a body of a few bytes (`m=product_view&s=123&t=<page token>`). `credentials: 'omit'` means the browser sends no cookies and the response sets none: the route sits in the group WITHOUT the session middleware, so there is no `Set-Cookie` (the same test as the cacheable pages, §3.4). The response is `204 No Content` with `Cache-Control: no-store`. The page token `t` is a signed value (HMAC with the app key) over `metric:subject:day` rendered into the cached page, so the endpoint accepts only a metric/subject pair the server itself emitted for today; a replayed token can only add to that same counter and is covered by the throttle. An unknown or hidden subject id is ignored (one cached existence check).

**Counting without hot-row contention (I):** the endpoint does `INCR` on a cache key per `(day, metric, subject)` and a scheduled command (every minute) flushes the keys into `traffic_daily` with one upsert per key (`hits = hits + n`); with the file/array cache driver (no Redis) it upserts directly. A lost minute of counts on a cache flush is acceptable for aggregate numbers.

**Bot filtering without storing an IP address (I), cheapest first:** (1) **Requires JavaScript and a visible page for ~1.5 s** — most crawlers and scanners never send the beacon; (2) the request must carry the browser-set `Sec-Fetch-Mode: cors` / `Sec-Fetch-Site: same-origin` headers (absent on most scripted clients); (3) a small **User-Agent denylist regex in code** (known crawlers and HTTP libraries), evaluated in memory and **never stored**; (4) the signed page token (above) — cannot invent metrics or ids; (5) a **short-lived throttle**: Laravel's `RateLimiter` key = `hash_hmac('sha256', ip, app key + today's date)` held **in the cache with a 60-second TTL only** (e.g. 30 beacons per minute per key) — the IP is used transiently as the throttle key, it is never written to the database or a log, and the salt changes daily so the key is useless tomorrow; (6) **anomaly flag on the report**: a day whose hits for a metric exceed 5× the median of the previous seven days is shown with a "possible bot traffic" note (facts, not enforcement — nothing is dropped or corrected silently). Residual limit, stated plainly: a determined script that runs a real browser can still inflate the counters; the numbers are for trends and ratios, not for billing.

**Retention:** `traffic_daily` holds no personal data and is kept indefinitely by default (`privacy.retention.aggregates_months`, 0 = keep, §12.1).

### 10.3 Tier 2 — the consent mechanism (I)

- The banner appears **only while `storefront.analytics.tier2_enabled` is on** (default off, §10); with it off the storefront shows no banner and sets nothing. A small banner (Alpine, ~1 KB of logic) on first visit with two equal buttons: *Accept analytics* / *Only necessary*; a persistent "Cookie settings" link in the footer.
- The choice is stored **client-side** in `localStorage` key `easyco.consent` = `{v:1, analytics: bool, at: iso}` — not as a server cookie, so cacheable pages stay cookie-free (§0.2). A server-side record of consent is not required by the strict reading for analytics (no personal data is stored server-side without it); if the owner wants proof, a `consent_log` row is written *by the events endpoint with the visitor id* only when the visitor accepted.
- When `analytics = true` the JS creates a random visitor id (UUID v4) and a session id (per tab session) in `localStorage`; when `false` or absent **nothing is generated and nothing is sent**; withdrawing consent deletes both ids and sends a single `DELETE /api/v1/funnel/visitor/{visitorId}` (erasure) request.
- The endpoint is **uncached, throttled and idempotent** (below). It works even if the page came from the proxy cache.

### 10.4 Tier 2 — the events table and the six events

`funnel_events` — `id` (bigint), `occurred_at` (timestamp(3)), `visitor_id` (char(36)), `session_id` (char(36)), `event` (closed list: `product_viewed|added_to_cart|checkout_started|order_placed|checkout_failed|cart_abandoned`), `product_id` null, `variation_id` null, `cart_id` null, `order_id` null, `value_minor` null, `currency` char(3) null, `source` (varchar 40, whitelisted: direct|search|category|home|email|other) null, `event_key` (char(36), UNIQUE — client-generated UUID, makes the endpoint idempotent), `created_at`. Indexes: `(event, occurred_at)`, `(visitor_id, occurred_at)`, `(product_id, occurred_at)`, UNIQUE `event_key`. **No email, no IP, no user agent, no free text.**

The six events (the brief says "the six events" without naming them; these are my choice, O19): `product_viewed`, `added_to_cart`, `checkout_started`, `order_placed`, `checkout_failed` (a controlled refusal or payment failure — reason code only), and `cart_abandoned` (computed server-side, §11; not sent by the browser). `order_placed` is **recorded by the server from the checkout success** only for visitors whose consent flag arrived with the checkout request (a header or field `X-Consent-Analytics: 1` + the visitor id) — the order itself is business data and is recorded regardless in `orders`; the *funnel link* between a visitor and an order exists only with consent.

Validation at the endpoint: event name from the closed list; ids must be ids of real, visible products (checked in one query for a batch); body ≤ 2 KB; at most 20 events per request; `value_minor` ≤ 10^9; unknown fields dropped; throttle 60 events/min per visitor id and 120/min per IP; reject if the visitor id is not a UUID. Bots that do not run JS never produce events.

### 10.5 Tier 2 — retention and aggregation

Raw rows are kept 13 months by default (O13; the period is the setting `privacy.retention.funnel_events_months`, §12.1) then deleted by a scheduled command; before deletion they are rolled up into `funnel_daily` — `day`, `event`, `product_id` null, `count`, `value_minor_sum`, unique `(day, event, product_id)` — which holds no visitor ids and can be kept indefinitely. The report reads `funnel_daily` for history and raw rows for the last 30 days.

### 10.6 The owner's report (read-only Filament page, `report_view` permission, V: exists)

Four numbers over a chosen period, each with the previous period beside it: **add-to-cart per viewed product** (`added_to_cart / product_viewed`), **checkout per cart** (`checkout_started / carts_with_a_line` — carts counted from `added_to_cart` distinct sessions), **order per checkout** (`order_placed / checkout_started`), **average order** (from the `orders` table, not from events — the authoritative figure). Plus a ranked list of the most viewed products with their add-to-cart rates, and the abandoned-cart recovery rate (§11). Limits stated on the page: the tier 2 numbers cover **only visitors who accepted analytics**; the page shows the acceptance rate so nobody mistakes them for total traffic. **The tier 1 counters (§10.2) are shown beside them as the unconsented, unbiased totals** (views and add-to-carts per day for everybody), so the page can say "of N product views, M visitors accepted analytics".

---

## 11. Abandoned carts

- **Definition (decision 6):** a cart with at least one line, not claimed by an order (`order_id IS NULL`, V), whose `updated_at` is older than the **abandon threshold** (default 60 minutes; setting `storefront.cart.abandon_minutes`), and for which no order exists from the same identity since. States (computed, stored on the cart-recovery table, §below): `active → abandoned → reminded_1 → reminded_2 → (reminded_3) → recovered | expired | opted_out`.
- **A scheduled command** (every 10 minutes) selects candidate carts with a **single indexed query** (V: the carts migration indexes `expires_at` only; `updated_at` is not indexed in that migration, so the stage checks the live schema and adds a plain index on `updated_at` if it is missing), inserts missing rows into `cart_recoveries` and fires the existing planned hook `cart.abandoned` (named in `cart-abandoned-recovery-note.md`; it must be registered in the Hook Reference in that stage). Mail sending is a LISTENER (as the note says), not part of Cart.
- **`cart_recoveries`:** `id`, `cart_id` (unique FK), `state`, `abandoned_at`, `last_sent_at`, `sent_count` (tinyint), `recovered_order_id` null, `recipient_email_hash` null, `created_at/updated_at`. No email is stored here; the recipient is resolved at send time (below).
- **Who can be mailed — the real constraint (V):** a guest cart has no email; an account cart has the account's email. Therefore: **account carts of customers who consented to cart-reminder mail**, and **guests who voluntarily tick the reminder checkbox at checkout (decided by the owner 2026-10-09, below)**. Without the checkbox a guest cart can never be mailed.
- **The guest-email checkbox (decided by the owner 2026-10-09):** next to the email field at checkout there is an **unticked** checkbox: "Send me a reminder if I do not finish my order" (bg/en; the wording is versioned in `mail_consent_texts` with `purpose = cart_reminder`, the table of `mail-design.md` §7 gaining a `purpose` column and UNIQUE `(purpose, version, locale)`). It is never pre-ticked and never required for the order. **Its own consent record:** a table **`consent_records`** — `id`, `purpose` (`cart_reminder`), `subject_type` (`cart|account`), `subject_id` (varchar 64), `granted` (bool), `text_version` (varchar 20), `ip_hash` (char(64) null, HMAC of the IP with the app key — proof without the raw IP, as in `mail-design.md` §7), `created_at`; **append-only**: ticking inserts `granted = 1`, unticking or the unsubscribe link inserts `granted = 0`, and the mail job reads the **latest** row per subject. When the box is ticked, the page sends the typed email to an uncached endpoint `POST /api/cart/reminder` (validated address, throttled, neutral answer, the cart id must be the visitor's own cart by the existing identity check, V: carts are identified by account id or session token) which writes `carts.reminder_email` and `carts.reminder_consent_at` (two new nullable columns, stage S15) and a `consent_records` row, so the email is captured **before** the order exists. Unticking clears `reminder_email` at once. When an order is placed from the cart the columns are cleared by the claimed-cart rules (§12.1), and a reminder is never sent for a claimed cart. Logged-in customers use the account email with the same checkbox (consent stored against the account). The checkbox state is not remembered across carts: each cart asks again.
- **Thresholds (decision 6):** first email 2 h after abandonment (configurable 1–4 h), second at 24 h, optional third at 72 h (default OFF — O15), default **1 email** total. Never mailed when: an order exists for the identity after the cart's last update; the cart is empty or all lines are unavailable; the recipient has no consent or unsubscribed; the last send was < the spacing ago; the cart is older than 14 days; quiet hours 21:00–08:00 store time (send at 08:00).
- **Claimed-cart retention (V + I):** a claimed cart (order placed) is **kept** past `expires_at` by `cart:prune` (stage 4h) because it answers checkout replays; the recovery table ignores claimed carts. A separate retention rule for old claimed carts is still an open owner decision from stage 4h — proposal: delete claimed carts 90 days after the order's last status change.
- **Discount in the reminder:** `cart-abandoned-recovery-note.md` wants a single-use, time-limited code generated by Pricing/Promotions, not by Cart. Default: **no discount** in the first email (measure first); a later option "offer code X% in email 2" generates one promotion with `usage_limit_total = 1`, `valid_until = +3 days`, scoped to nothing (whole cart), created through the promotions writer and stored on `cart_recoveries`. Decision O16.
- **Measurement:** `recovered` is set when an order is placed from the same identity within 7 days after a send; the report shows reminders sent, recovered carts and recovered value.
- **Interaction with mail:** see `mail-design.md` §6 (template `cart.abandoned`, consent rules, unsubscribe, idempotency by `mail_log`).

---

## 12. Privacy tools and retention (decision 4, decided by the owner 2026-10-09)

Two things the owner asked for: **retention periods as settings** and a **per-customer personal-data export and "forget" (anonymise — orders are never deleted)**. Orders are accounting records and CLAUDE.md rule 4 forbids destroying historical identity; "forget" therefore clears the *person* from the records and keeps the *business facts*.

### 12.1 Retention table (settings, with proposed defaults)

All periods live in `site_settings` under `privacy.retention.*`, are edited on one Filament page "Privacy" (permission `settings_manage`; Unknown: whether a dedicated `privacy_manage` permission is wanted), and are enforced by one scheduled command `privacy:prune` (daily, off-peak; deletes in chunks of 500; reports counts; journalled through `ActivityLogger` with counts only). A period of `0` means "keep until another rule removes it". **The defaults are my proposals; none is legal advice — the owner confirms them with an adviser (O17, O21).**

| Data | Where it lives | Setting key | Proposed default | At the end of the period |
|---|---|---|---|---|
| Per-visitor funnel events (tier 2) | `funnel_events` | `privacy.retention.funnel_events_months` | 13 months | rolled up into `funnel_daily`, then raw rows deleted |
| Aggregates (tier 1 counters, daily funnel) | `traffic_daily`, `funnel_daily` | `privacy.retention.aggregates_months` | 0 (keep) | none — they hold no personal data; a positive value deletes older days |
| Mail log | `mail_log` (`mail-design.md` §6) | `privacy.retention.mail_log_days` | 180 days | rows deleted (addresses and facts only; bodies were never stored) |
| Newsletter subscribers | `mail_subscribers` | `privacy.retention.subscribers_unsubscribed_months` | 24 months after unsubscribe/bounce | row deleted; **active subscribers are kept while active** |
| Cart recoveries | `cart_recoveries` (§11) | `privacy.retention.cart_recoveries_days` | 90 days after the recovery reached a final state | rows deleted |
| Guest reminder email on a cart | `carts.reminder_email` | `privacy.retention.cart_reminder_email_days` | 30 days after the last reminder, or at once when the cart is claimed or the box unticked | the email and consent timestamp cleared (the `consent_records` rows stay as proof, 24 months, `privacy.retention.consent_records_months`) |
| Inactive customer accounts | `accounts` | `privacy.retention.inactive_account_months` and `privacy.retention.inactive_account_action` | 36 months without login or order; action `report` | `report` = listed on the Privacy page only; `anonymise` runs the same routine as "forget" (§12.3) — switching the action on is a deliberate owner act, and a warning mail 30 days before is proposed (Unknown: wording and legal need) |
| Claimed carts (cart of a placed order) | `carts` with `order_id` | `privacy.retention.claimed_cart_days` | 90 days after the order's last status change (the open question from stage 4h) | cart and lines deleted — they are configuration, not history (CLAUDE.md rule 4) and the order keeps its own sale lines |
| Admin activity journal | `activity_log` | `admin.activity_log_retention_months` (V: exists, 6/12/18) | 12 months | already implemented, unchanged |
| **Orders, their sale lines, payments, receipts, refunds** | `orders` and children | none | **never deleted** | accounting records; only anonymised on request (§12.3). The accounting retention duty (years) is for the adviser |

### 12.2 Export — the customer's data in JSON

`php artisan privacy:export {--account=ID | --email=ADDRESS}` writes one JSON file (UTF-8, pretty-printed, LF) to a private storage path and prints its location; an admin action on the customer later calls the same service. **Format (I):** `{"schema": "easyco.privacy-export.v1", "generated_at": ISO-8601 UTC, "subject": {"account_id", "email"}, "account": {registered_at, email, name?}, "addresses": [...saved addresses...], "orders": [{"id", "placed_at", "status", "currency", "subtotal", "discount", "shipping", "total", "promotion_code", "delivery": {type, recipient_name, phone, country, city, postal_code, address lines or pickup point}, "shipping_method": {...}, "payments": [{method, status, amount}], "lines": [{product_name, sku, attributes, quantity, final_unit_price, line_total}]}], "carts": [...open carts...], "newsletter": {status, consent_at, text_version}, "consents": [{purpose, granted, text_version, at}], "mail_log": [{template_key, status, queued_at}], "funnel_events": [...events linked to the customer's order or cart ids...]}`. **Never included:** password hashes, API tokens, unit costs, profit or margin, internal staff notes about the customer (Unknown for the owner and adviser: whether staff notes are personal data to disclose — the default is to list *that* notes exist), other customers' data. The file is generated for a person the staff member identified; a guest is found by email through their orders. The writer is read-only, queries by indexed keys, and streams per order so a large history does not exhaust memory.

### 12.3 Forget — anonymise, never delete orders

`php artisan privacy:forget {--account=ID | --email=ADDRESS}` (and the same service behind a confirmed admin action; a dry-run `--report` lists what would change). **Rule: orders are never deleted; the person is removed from them.**

| Cleared or replaced | Kept |
|---|---|
| `orders.email` → `deleted-{orderId}@anonymized.invalid` (the `.invalid` TLD is reserved, RFC 2606; the column is NOT NULL); `recipient_name` → a fixed "Anonymised customer" text; `phone` → empty/neutral; `address_line_1/2`, `postal_code`, `city` → cleared; pickup-point name, address, reference and settlement → cleared; the same fields in `order_placement_snapshots` | order id, placed-at, status and history, currency and every amount (subtotal, discount, shipping, total), promotion code, shipping method name/courier/delivery type/service code, **country** (needed for tax treatment), tracking number (a shipment identifier — flagged for the adviser), all sale lines (product name, SKU, attributes, quantities, prices), payment rows and receipts' amounts and dates, refund records |
| The account: email → `deleted-{accountId}@anonymized.invalid`; password hash → a random unusable hash; name cleared; API tokens and sessions revoked; saved addresses cleared or deleted (Unknown: whether `orders.address_id` is a foreign key that forces *anonymise* instead of *delete* — to verify in S16); open carts deleted | the account row itself and its id (orders reference it) |
| Newsletter row deleted; `mail_log` rows for the address deleted; `consent_records` for the account/carts kept as proof for their period; `funnel_events` of visitor ids that appear together with the customer's order or cart ids deleted (events hold no email, so the link is the order/cart id) | the aggregates (no personal data) |
| Free text that may contain a name (order notes, event notes, receipt/payment references) | **Unknown — S16 starts with an inventory** of every column that can hold personal data and a test that fails when a new such column appears unclassified (a registry `PersonalDataRegistry` lists table, column, class: `identifying|business|free_text`, and the forget routine handles each class) |

**Safety rules:** (1) idempotent — a second run changes nothing; (2) **refused while an order of the subject is still open** (status `placed`, `confirmed` or `shipped`, a payment not settled or a refund owed — the facts the Needs-attention page already derives) with the list of blocking orders, so a package in transit is never orphaned (decision O22); (3) one database transaction per subject, then the deletions that cannot be transactional (files) run after commit; (4) the action is journalled with the subject's id (never the email) and counts; (5) a forgotten subject cannot log in and receives no mail (the address no longer exists); (6) nothing in the routine touches the frozen Catalog classes or the Order domain's invariants — it writes through a dedicated `PersonalDataEraser` in `app/`, with a documented narrow write path on `orders` (these are PII columns, not business facts, in the same sense as CLAUDE.md rule 7's structural-reference exception — to be documented there when built).

### 12.4 Stage

Built in **S16** (§13): the Privacy page and settings, `privacy:prune`, `privacy:export`, `privacy:forget`, the registry and its completeness test. S12–S15 each register their own data in the registry and honour its retention setting from the day they ship, so nothing has to be retrofitted.

## 13. Staged plan

Each stage ends in a review gate and a full-suite run in a private test database (the house rule); no stage commits without the owner; every stage lists **Must not touch**. "Main" = the main coder (risky, cross-cutting, schema); "Cheap" = a cheaper coder (views, JS, lang, text) against a fixed read-model API.

| # | Stage | Goal | Files / areas | Tests | Must not touch | Who |
|---|---|---|---|---|---|---|
| **S1** | Read layer foundation | `StorefrontVisibility`, read models, `CatalogReader::product/listing/categoryTree`, batched image/price/stock, query budgets | `app/Storefront/**` | visibility matrix; 5-vs-24 query-count pins; read-model `toArray` golden files | domain packages, sandbox, checkout | Main |
| **S2** | First visible shop: category + product pages | cacheless Blade pages `/product/{slug}`, `/product-category/{path}`, layout, product card partial, breadcrumbs, 404, canonical | `routes/web.php`, `resources/views/storefront/**`, controllers | rendered-content assertions; hidden product = 404; path resolution rule from `storefront-frontend-design.md` §8 | cart/checkout code | Main (controllers) + Cheap (views) |
| **S3** | Home + cart integration | `/` (replaces welcome), cart page, Alpine cart/account hydration, add-to-cart through the existing API | views, Alpine modules | cart page not cacheable; hydration fetches `/api/cart` | the cart API | Cheap after S2 |
| **S4** | HTTP caching | cacheless route group, headers, ETag/304, purge interface, invalidation events, scheduled boundary job | route group, `CachePurger`, observers, Hook Reference | cacheability tests (§3.4); invalidation per event; no Set-Cookie | session config of other groups | Main |
| **S5** | Images | `ImageSet`, srcset/sizes, width/height, LCP rule, preload, placeholder; `immutable` doc | read layer + partials | srcset/dimensions from variant rows; LCP not lazy; fallback to largest ready | Media pipeline | Main (read model) + Cheap (partials) |
| **S6** | SEO | meta, canonical, OG, JSON-LD, sitemap command, robots | `Seo/**`, views | JSON-LD decoded and asserted; sitemap excludes hidden; robots per environment | catalog domain | Main (builders) + Cheap (tags) |
| **S7** | Search + filters + sort | `ProductSearch`, `DatabaseProductSearch`, `search_text`, `price_from_minor`, facets, rate limits | migration(s), `Search/**`, limiters | normalisation (Cyrillic/Latin), ranking, swap test with a fake driver, throttle | checkout | Main |
| **S8** | Public JSON API | thin controllers + `StorefrontParityTest` | `Api/Storefront/**` | parity test; rate limit; parameter whitelist | existing API routes | Main |
| **S9** | URLs: slug history + redirects | `catalog_slug_history`, writer hooks, `storefront_redirects`, importer command | migration, writers (minimal), command | 301 after rename; loop guard; import | frozen Catalog classes (`Product.php`, `Variation.php`, `VariationSignature.php`) — slug writes go through the existing writer/service seam, to be located first | Main |
| **S10** | Merchandising: card extras | `BadgeResolver`, settings, `promo_promotions.is_public`, admin tab "Product cards" | migration (1 column), resolver, settings page | OFF = zero queries; sale % maths; new window; sizes; ordering/max | pricing/promotions rules | Main (resolver) + Cheap (admin lang/views) |
| **S11** | Merchandising: home blocks | slider + banners tables, admin page, rendering | migrations, Filament page, partials | one active block; max slides; link validation; reduced-motion markup | Media domain | Main (schema, writers) + Cheap (views) |
| **S12** | Tier 1 counters | `traffic_daily`, the beacon script and `POST /t/c`, signed page tokens, flush command, bot filters, anomaly flag, report numbers | migration, controller, JS, command | no cookie and no `Set-Cookie`; unknown metric/subject ignored; token replay bounded; denylist; throttle key is cache-only (no DB write of IP); flush adds up; page works with JS off | cached routes' cookie-freeness | Main |
| **S13** | Tier 2: consent + funnel events | banner (only when `tier2_enabled`), `localStorage` logic, endpoint, table, validation, throttling, erasure | migration, controller, JS | no consent ⇒ no request; banner off by default; idempotency; validation limits; erasure; no cookie on cached pages | cached routes' cookie-freeness | Main |
| **S14** | Measurement report | aggregation command, report page showing tier 1 and tier 2 side by side, acceptance rate | command, Filament page | rates maths; acceptance rate shown; anomaly note | orders | Main |
| **S15** | Abandoned carts | `cart_recoveries`, command, `cart.abandoned`, **guest reminder checkbox + `consent_records` + `carts.reminder_*` columns**, thresholds | migrations, command, hook, checkout form field (consent) | candidate query count; never-when-ordered; quiet hours; spacing; unticked by default; untick clears the email; consent read as latest row | Cart domain internals (listener only) | Main (needs `mail-design.md` M1 first) |
| **S16** | Privacy tools and retention | Privacy page and `privacy.retention.*` settings, `privacy:prune`, `privacy:export`, `privacy:forget`, `PersonalDataRegistry` and its completeness test | settings page, commands, `PersonalDataEraser`, one narrow write path on `orders` PII columns | retention cutoffs exact; export shape and exclusions; forget keeps every listed business fact and clears every listed PII field; idempotent; refused while an order is open; journalled without the email; registry fails on an unclassified new column | Order domain invariants, frozen Catalog classes | Main |

**Order:** S1 → S2 (first visible result: category + product) → S3 (home + cart) → S4 → S5 → S6 → S7 → S8 → S9 → S10 → S11 → S12 → S13 → S14 → S15 → S16. S5/S6/S7 can interleave. S15 waits for the mail stages M1–M3; S16 may be built earlier (it only needs the data it protects to exist).

---

## 14. Decisions needed from the owner (with my recommendation)

- **O1 — Where the read layer lives:** `App\Storefront` (recommended) vs a new domain package. A package would have to import Catalog, Pricing and Media, which the cross-domain rule (CLAUDE.md rule 9) forbids for domain packages; `app/` is allowed to compose them.
- **O2 — URL scheme:** keep raf.bg's `/product/{slug}`, `/product-category/{path}`, `/product-tag/{slug}` (already confirmed in `storefront-frontend-design.md` §10), add `/brand/{slug}`. Recommend yes.
- **O3 — Cacheable group without a session:** the cacheable storefront runs in its own middleware group (no session/cookies). Recommend yes; the cost is that nothing personal can be server-rendered on those pages (already accepted: client hydration).
- **O4 — Sold-out products stay visible** (with a "sold out" state) rather than disappearing. Recommend visible; hide-when-sold-out can be a later setting. (`product-archival-strategy-note.md` covers archived/sold-out pages separately.)
- **O5 — Staleness of stock on cached pages:** up to the product page's `s-maxage` (≤ 10 min) but purge on every stock change for the product URL. The cart and checkout always check real stock. Recommend this.
- **O6 — AVIF:** not in V1.
- **O7 — Price filter on regular price (cached column) in V1;** discounted price filtering later. Recommend.
- **O8 — "New" = within N days of `timeline_at`** (default 14), automatic. Recommend; confirm that moving `timeline_at` deliberately is the way to refresh "newness".
- **O9 — Sizes:** show only sizes with an in-stock, purchasable variation, in the attribute's own order. Recommend; the alternative (show all, strike sold-out) costs a slightly bigger read.
- **O10 — Promo badge needs an explicit `is_public` flag on promotions** (codes are secret today). Recommend yes; default false.
- **O11 — `hasMerchantReturnPolicy` / `shippingDetails` in JSON-LD** only once the merchant enters a returns policy and shipping facts (settings). Recommend; both are weighted heavily by shopping surfaces, so enter them early.
- **O12 — One language at a time** (`site.locale`), no hreflang in V1.
- **O13 — Funnel raw-event retention 13 months,** then daily aggregates; now the setting `privacy.retention.funnel_events_months` (§12.1). Confirm with the legal adviser (§10.1).
- **O14 — Abandoned-cart recipients (decided by the owner 2026-10-09):** account carts with consent + guests through an **unticked checkbox** next to the email field at checkout ("Send me a reminder if I do not finish my order") with its own append-only consent record (§11).
- **O15 — Third reminder at 3 days:** default OFF; default total = 1 email (decision 6).
- **O16 — Discount code in the reminder:** none in V1; measure first, then offer a single-use 3-day code in the second email.
- **O17 — Consent text and the strict-reading default** (§10.1): confirm with the legal adviser; I recommend building for the strict reading. The two-tier structure itself is decided (O20).
- **O19 — The six funnel events:** `product_viewed`, `added_to_cart`, `checkout_started`, `order_placed`, `checkout_failed`, `cart_abandoned`. Recommend; tell me if you meant a different six.
- **O20 — Two-tier measurement (decided by the owner 2026-10-09):** tier 1 = aggregate counters, no identifier, nothing on the device, no consent; tier 2 = per-visitor events with consent; hashing does not remove the need for consent for tier 2 (§10). Still to confirm legally: that tier 1 needs no consent (§10.1).
- **O21 — Privacy tools and retention (decided by the owner 2026-10-09):** retention periods are settings; per-customer JSON export; "forget" anonymises and never deletes orders (§12). Open inside the decision: the default periods of §12.1 (mine), the inactive-account action (`report` until switched), and whether staff notes belong in the export.
- **O22 — Forget is refused while the customer has an open order** (placed/confirmed/shipped, unsettled payment, refund owed). Recommend yes; the alternative is to anonymise anyway and lose the delivery details of a parcel in transit.
- **O18 — Order of the first stages:** S1 → S2 → S3 gives home + category + product + cart earliest; recommend this over starting with SEO or search.

### 14.2 Unknowns (need the running app, or a decision I did not read)

- Real LCP and the real query counts of the sandbox pages on the owner's hosting; whether Cloudways' Varnish passes `Cache-Control`/`Vary` as designed and what its purge API is.
- Whether merchants actually fill `alt_text` (the column exists; fill rate unknown) — decides whether the name fallback is the common case.- Whether a `1200 px` or `200 px` image tier is wanted (measure the LCP image size on mobile first).
- The location of the single seam where product slugs are written (several callers use the `catalog.product.slug` filter hook) — S9 must locate it before touching anything, and must not modify the frozen Catalog classes.
- How the admin currently stores the store's product image aspect ratio setting (the design doc names it; I did not verify the final key).
- The legal reading of §10.1 and of the abandoned-cart mail (soft opt-in rules differ by country).
- Whether the existing Livewire/Filament stack is acceptable for the "Storefront" admin tabs without extra permission keys (the enum has `settings_manage`, `report_view`; a dedicated `storefront_manage` may be wanted).
