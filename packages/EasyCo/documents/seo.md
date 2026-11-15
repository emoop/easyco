# SEO-1: sitemap, robots.txt, share cards, structured data

What the storefront gives search engines and link previews. No admin fields: those are SEO-2 (below).

## robots.txt

- One source: `App\Storefront\Seo\RobotsTxt`. The route in `routes/web.php` answers `/robots.txt` with it, **always through the app**.
- **`public/robots.txt` is gone on purpose.** A static file wins on Apache (Laragon) and would bypass the app, so the `EASYCO_NOINDEX` switch, the Sitemap line and any later rule could not reach it. Without the file Apache, nginx and Varnish all reach the route.
- Rules (same order as the old file): `/admin/`, `/api/`, `/design-preview`, `/cart`, `/checkout`, `/*filter[`, `/*filter%5B`, **`/search`** (new: the results page is `noindex, follow` already; the line saves crawl budget and server work against bots). Last line: `Sitemap: {APP_URL}/sitemap.xml`.
- With `easyco.noindex` (a test server) the answer is exactly `User-agent: *` / `Disallow: /`, no Sitemap line.

## Sitemap

`App\Storefront\Seo\Sitemap`, controller `SitemapController`, routes in `routes/storefront.php` (sessionless).

| URL | content |
|---|---|
| `/sitemap.xml` | the index. `lastmod` for pages and categories (newest `updated_at`); none for product chunks (a date per chunk would cost a query per chunk) |
| `/sitemap/pages.xml` | the home page + every published content page that is not `noindex` |
| `/sitemap/categories.xml` | every category the storefront shows (the category page's own rule); no filtered URL, no `?page=` |
| `/sitemap/products-{n}.xml` | visible products by id in chunks of **5,000** (`STOREFRONT_SITEMAP_CHUNK`); `{n}` past the last chunk is a 404. Each `<url>` has `<image:image>` of the main picture at its largest stored size |

- `<loc>` is the canonical URL, the same string `SeoMeta` builds (percent-encoded path, so a Cyrillic slug is `%D1%80…`), XML-escaped. `<lastmod>` is W3C datetime from `updated_at`. No `changefreq` / `priority` (ignored by search engines).
- A product, category or page its own page would hide is never listed (product page rule: a sold-out product stays listed, its page still exists).
- **Cost:** a constant number of statements per file whatever the number of rows (a product chunk = the page of products with its first picture as a sub-select, then the assets: 2 statements). Pinned in `SitemapTest` (5 vs 50 products).
- **Cache: every file is kept in the cache store for ONE HOUR** (`Sitemap::TTL_SECONDS`), one key per file. There is no purge yet: a new product shows up within the hour. Purging by hook belongs to P8.
- With `easyco.noindex` every sitemap URL is a 404.

## Open Graph and Twitter cards

`SeoMeta::og()` builds the tags from data the read models already hold; `App\Storefront\Seo\SeoHead` prints them (each value escaped with `e()`; the layout prints the result through one `@php echo` line) **only when the page has a canonical and is not noindex**: never on a filtered listing, a 404, a preview, the cart, the checkout, a search or a `noindex` content page.

`og:type` (`product` / `website`), `og:title`, `og:description` (when not empty), `og:url`, `og:site_name` (the shop name), `og:locale` (`bg` → `bg_BG`, `en` → `en_US`, a short closed map), for a product also `og:image` (+ width, height, alt: the first gallery image, the stored size closest to 1200 px wide) and `product:price:amount` / `product:price:currency` (the current "from" price). `twitter:card` is `summary_large_image` with an image, else `summary`; plus `twitter:title`, `twitter:description`, `twitter:image`. No `twitter:site` (SEO-2). Categories, the home page and content pages have no image in SEO-1.

## JSON-LD

`App\Storefront\Seo\StructuredData` returns one graph (`@context` + `@graph`) or nothing; `encode()` is the ONLY raw output of the layout (`JSON_HEX_TAG | AMP | APOS | QUOT`, so `</script>` in a name comes out as `</script>`). Same print condition as the share cards.

- **Product:** `Product` (name, description, up to 5 images, sku, brand, url) with `offers`: an `Offer` when the price is one value, an `AggregateOffer` (`lowPrice`, `highPrice`, `offerCount` = purchasable variations) otherwise; `priceCurrency`, `availability` (`InStock` / `OutOfStock`), `itemCondition`, `url`. Prices are decimal strings from the minor units (`Money::decimalValue()`). Plus a `BreadcrumbList` (Home, the trail, the product; absolute URLs, the last item has none).
- **Category:** `BreadcrumbList`.
- **Home:** `WebSite` (with a `SearchAction` to `/search?q={search_term_string}` only while `SearchFeature::enabled()`) and `Organization` (name, url).
- **Content page:** nothing (a page has no breadcrumbs).

0 extra queries on product, category and home pages: everything comes from the read models.

## Index pages `/brand` and `/collection` (SEO-1.1)

`App\Http\Controllers\Storefront\SubjectIndexController`, the rows and pictures in `App\Storefront\Index\SubjectIndex`, the page `storefront.subject-index`.

- **Which rows.** A brand or a collection is listed when it has at least one product its own listing would show (`StorefrontVisibility::listed()`, the category listing's rule). **This is stricter than «answers 200»**: the subject page `/brand/{slug}` answers 200 for an empty brand too (`CatalogReader::subject()` only looks the slug up). Empty subjects are left out of the index and of the sitemap, not hidden from their own page.
- **Collections** are rows only while `catalog.collections_enabled` is `1`, through the same gate the detail page uses (`CatalogSubjectKind::COLLECTION->gated()`, inside the statement). Switch off, or no listed collection = 404.
- **Brands** and **collections** with no listed row = 404.
- **Order.** By name in Bulgarian collation (`Collator('bg_BG')`, `ext-intl` is required by composer; without it a case-insensitive byte order, Cyrillic after Latin). Brands are grouped by their first letter with a letter bar of plain anchors (`#a`, …; a name that does not start with a letter is the group `#` / «Други», last). Collections have no letter bar.
- **Pictures.** A brand: its logo (name-only tile when it has none). A collection: its own picture; else the first usable image of one of its visible products; else the storefront's neutral placeholder. The alt of a tile picture is empty (the caption names the link).
- **Canonical** is the bare path on `APP_URL` (`/brand`, `/collection`), never with a query string. OG tags, and a `BreadcrumbList` JSON-LD (Начало → Марки / Колекции, the last step without a link). Under `easyco.noindex` the page has no canonical, no share or structured data, and the noindex robots tag.
- **Cost.** One statement for the rows, at most two more for the pictures (the media assets; for collections without a usable own picture, one statement for the first usable product image). The count does not depend on the number of rows (pinned in `SubjectIndexPagesTest`).
- **Route conflicts.** `/brand` and `/collection` are literal paths with no `{slug}`; `/brand/{slug}` and `/collection/{slug}` are unchanged. A route test guard (`CatalogListingTest`) knows the two index routes and the `sitemap/` files.
- **Menu.** A menu item of type link takes a relative path (`/brand`, `/collection`): `AppearanceSettings::isSafeBannerUrl()` accepts a path that starts with `/`. Not exercised through the menu UI in this task.

## Subject sitemap files (SEO-1.1)

| URL | content | present when |
|---|---|---|
| `/sitemap/brands.xml` | the brands of `/brand` | at least one listed brand |
| `/sitemap/seasons.xml` | the seasons with a visible product (`/season/{slug}`) | at least one |
| `/sitemap/collections.xml` | the collections of `/collection` | the switch is on and at least one listed collection |
| `/sitemap/tags.xml` | the tags with a visible product (`/product-tag/{slug}`, a public page) | at least one |

- Each `<url>` has the subject's own path (percent-encoded slug), `<lastmod>` from its `updated_at`. No `changefreq` / `priority`.
- `/sitemap.xml` lists a file only when it has rows; `/sitemap/pages.xml` lists `/brand` and `/collection` under the same conditions as their pages.
- Each file is one file. At `storefront.sitemap.chunk` (5,000) rows it would need chunking like the products; these kinds stay far below that, and the code does not chunk them yet.
- Each file is cached for one hour (`storefront.sitemap.brands`, …), no purge (P8). With `easyco.noindex` every one of them is a 404.

## SEO-2 (not here)

Admin meta title / description per product and category; shop-wide SEO settings (shop name, default share image, logo, social profiles, `twitter:site`); latin slugs and 301s; `hreflang`; reviews in JSON-LD; `ItemList` on categories; sitemap purge on change (P8).
