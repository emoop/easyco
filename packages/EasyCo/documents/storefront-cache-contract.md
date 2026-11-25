# Storefront cache contract (P8b)

The **app side** of the page cache. The cache itself (Varnish with `xkey`) arrives in P8c; everything here works without it: the default purger does nothing.

## 1. The two kinds of response

| Class | Which | Headers |
|---|---|---|
| **Cacheable** | `GET` / `HEAD`, status **200 or 301**, to one of: `/`, `/product/{slug}`, `/product-category/{path}`, `/brand`, `/brand/{slug}`, `/season/{slug}`, `/product-tag/{slug}`, `/collection`, `/collection/{slug}`, `/info/{slug}`, `/robots.txt`, `/favicon.ico` (200 only, with a site icon: LOGO-1), `/sitemap.xml`, `/sitemap/*.xml`; **and the request has no `filter` and no `q` parameter**. `page`, `per_page`, `sort`, `featured` are allowed (bounded by the readers). | `Cache-Control: public, max-age=0, s-maxage=604800` (Symfony prints the directives sorted: `max-age=0, public, s-maxage=604800`) and `xkey: all <the page's keys>`. Never `Set-Cookie`. |
| **Pass** | filtered listings, `/search`, `/cart`, `/checkout`, `/order/received/*`, every preview, `/api/*`, `/admin*`, `/livewire*`, `/_sandbox*`, any non-GET/HEAD, **any status other than 200/301 (a 404 included)**, any unnamed route | Left exactly as the controller set them (`private, no-store` stays). **Never an `xkey`.** `/storefront/card-sizes` keeps its own `public, max-age=60, s-maxage=60` and has no `xkey`. |

**Safety net.** A response that would be cacheable but carries a `Set-Cookie` is downgraded to `Cache-Control: private, no-store`, gets no `xkey`, and a warning is logged (route name only).

The decision lives in one class: `App\Storefront\Cache\StorefrontCacheHeaders`, in the storefront route group (`routes/storefront.php`), which is `web` minus session, cookie and CSRF middleware. `/robots.txt` is in the same sessionless group.

## 2. Surrogate keys

Closed list (anything else is ignored and logged once): `all`, `home`, `sitemap`, `idx-brand`, `idx-collection`, `p{id}` product, `c{id}` category, `b{id}` brand, `s{id}` season, `t{id}` tag, `col{id}` collection, `pg{id}` info page.

| Page | Keys |
|---|---|
| every cacheable response | `all` |
| home | `home` + `p{id}` of every card of every grid / carousel (all go through `CatalogReader::cards()`) |
| category | `c{id}` + the ancestors' `c{id}` + `p{id}` of the rendered cards |
| brand / season / tag / collection | `b{id}` / `s{id}` / `t{id}` / `col{id}` + `p{id}` of the rendered cards |
| `/brand`, `/collection` | `idx-brand` / `idx-collection` |
| product | `p{id}`, `b{brand}`, `s{season}`, `c{id}` of the breadcrumb's categories (with ancestors), `t` / `col` / `s` of the «Мета» section when printed, `p{id}` of every card of «Подобни продукти» and of the slots below the gallery |
| info page | `pg{id}` |
| sitemap files | `sitemap` |

Keys are collected in `SurrogateKeys` (request-scoped) by pure PHP over data the page already loaded: **no extra query**. The reader adds the card keys at the one place cards are built; controllers add the subject keys.

## 3. The purge map

Writers and listeners only **collect** (`CachePurgeCollector::add*`). The collector flushes **once per HTTP request / artisan command** (ONE `terminating` callback per process, registered in `CachePurgeListeners::register()`, flushes the current scoped collector) and **once per queued job** (`JobProcessed` / `JobFailed`), de-duplicates, and sends batches of at most 100 keys through the queued job `PurgeCacheKeys` (3 tries, back-off) and the `CachePurger` contract. A failed purge is logged and never breaks a write.

Product rule = `p{id}` + every subject key of the product (categories with ancestors, brand, season, tags, collections — resolved at flush from the database in grouped statements, **before and after**: for a DELETE the subjects are read before the pivot rows go (`addSubjectsOf`, 3 statements); the old subjects come from the pivot rows that were deleted and from `getOriginal()`) + `home` + `sitemap`.

| Change | Keys | Where it is collected |
|---|---|---|
| Product save / delete / restore / status / visibility / brand / season | product rule (+ old and new `b` / `s`) | `ProductModel` events (`CachePurgeListeners`) |
| Category / tag / collection assignment | product rule + the pivot's subject key | pivot model events |
| Variation save / delete | product rule | `VariationModel` events; `CatalogDeletion` (raw deletes) |
| Variation reorder | product rule | event `catalog.variation.order_changed` from `EloquentVariationRepository::updateSortOrders` (a bulk update fires no model event) |
| Price row (price list item) | product rule | `PriceListItemModel` events |
| Price list started / ended / saved, `prices:refresh-boundaries`, `prices:refresh-from` | product rule for every product whose stored price or availability **changed** | `ProductSortPriceRefresher::refresh` (the one place that knows) |
| Stock crossing zero | product rule | `StockRefreshingRepository` |
| Stock change that does not cross zero | `p{id}` | `StockRefreshingRepository` |
| Product media | `p{id}` | `ProductMediaModel` / `VariationMediaModel` events; `MediaAssetReady` / asset deleted → the products (and brand, season, collection, category) that print it |
| Measurements (sizes) | `p{id}` | hook `catalog.product.measurements.saved`; event `catalog.product.measurement_template_changed` (raw writes) |
| Product section texts | `p{id}` | `SectionTexts::save` (raw writes) |
| HTML block | `all` | `HtmlBlockModel` events |
| Category (save, delete) | `c{id}` (+ ancestors) + `sitemap` + `home` + **`all`** (the name is in the sidebar filters, the menus and the tiles of every page) | `CategoryModel` events |
| Brand (save, delete) | `b{id}` + `idx-brand` + `sitemap` + `home` + `all` | `BrandModel` events |
| Season / tag (save, delete) | `s{id}` / `t{id}` + `sitemap` + `home` + `all` | model events |
| Collection (save, delete) | `col{id}` + `idx-collection` + `sitemap` + `home` + `all` | `CollectionModel` events |
| Measure / measurement template (saved, archived, deleted) | `all` (the names are printed in the size tables) | hooks `catalog.measure.*`, `catalog.measurement_template.*` |
| Info page save / delete | `pg{id}` + `sitemap` + `all` | `PageModel` events |
| Any «Дизайн» publish, header, footer, topbar, appearance tokens | `all` | `StorefrontAppearance::refresh()`; `HomeDrafts::publish` (the home layout is not in the cached payload) |
| Menus (every compile) | `all` | `MenuRecompiler::now()` |
| Filter catalog change | `all` | `FilterCatalog::forget()` |
| Free-shipping threshold | `all` | the WRITE paths only: the `shipping.*` hooks (AppServiceProvider) and LocaleSettings. `FreeShippingThreshold::refresh()` itself never collects: `current()` calls it on a cold cache, i.e. on a page view |
| The cookie banner's settings (CC-1: `cookies.*`; the page «Поверителност → Бисквитки») | `all` | `CookieSettingsPage::persist()` → `AppearanceRecompiler::run()` → `StorefrontAppearance::refresh()` (the same refresh as a «Дизайн» publish: the token stylesheet with the colour, the payload with the texts, one purge); the `cookies.*` keys are also collected by `EloquentSiteSettingsRepository::set/forget`. The policy link follows its page: `CookiePolicyListeners` (rename / unpublish / delete of the picked page → refresh). See `cookie-consent.md`. CC-2: the page the banner links to carries a `data-policy-page="1"` marker on the banner root: it depends on the URL alone (never on a cookie or a session), so the page stays one cached copy per URL for every visitor. **Cache layers must ignore the `easyco_consent` cookie** (it is read by the browser's JS only; the server never reads it; the VCL's `unset req.http.Cookie` already drops it; never vary or hash on it). |
| The site icon, the logos and the shop name (LOGO-1: `site.name`, `site_icon.*`, `logo.*`; the tab «Лого» of «Настройки») | `all` | `LocaleSettings::save()` → `AppearanceRecompiler::run()` → `StorefrontAppearance::refresh()` (the same refresh as a «Дизайн» publish: the payload, the token stylesheet, ONE purge); run ONLY when the name, an icon or a logo changed, never for an unrelated save of the page. `/favicon.ico` (route `seo.favicon`, in `StorefrontCacheHeaders::ROUTES`) is cacheable only as a `200` (an icon is set); with no icon it is a bare `404` with `Cache-Control: private, no-store`, never cached. nginx answers it through the app (`location = /favicon.ico`, before the image-extension rule). See `site-logo.md`. |
| The storefront's dark mode (DM-1: `appearance.mode`, `appearance.dark.button`; «Облик» → «Тъмен режим») | `all` | `AppearanceWriter::save()` → `StorefrontAppearance::refresh()` (and `EloquentSiteSettingsRepository::set/forget`): `mode` rides on the cached appearance payload (the `data-theme` attribute of `<html>`, one value for every visitor, no cookie); a flip of the mode or of the dark button colour collects `all` and makes a new token stylesheet name. See `dark-mode.md` (CC-2: the dev flag is gone; the stored mode is the mode). |
| Any storefront-visible setting (catalog settings, collections switch, locale / country, …) | `all` | `EloquentSiteSettingsRepository::set/forget` (the keys in `EloquentSiteSettingsRepository::INVISIBLE` are excluded: `mail.*`, drafts, the html-block import map, the seeder marker, `invoice.*`, `privacy.*`, `admin.*`, `payment.bank_transfer.*`; no order, job, price refresh, counter or scheduled command writes this table). The search switch is `STOREFRONT_SEARCH` (env), not a setting. |
| Mass edit | the chunk's products + the category / tag / collection / old and new season of the operation, once per chunk | `ProductMassEditor::applyChunk` |

**Laravel-side caches** follow the same events through a version key (`storefront.cache.version.sitemap`, `storefront.cache.version.all`): the collector bumps it at flush when `sitemap` / `all` is among the keys, and the sitemap cache keys include it (TTL 24 h). The 404 page shows no catalog content and costs no catalog query.

## 4. Purgers

`CACHE_PURGER=null|varnish` (`config/cache_purge.php`, default `null`).

- `NullPurger` — does nothing; **no job is dispatched** and no subject is resolved.
- `VarnishPurger` — HTTP `PURGE` to `CACHE_PURGE_URL` (the INTERNAL address, only from config) with header `xkey-softpurge: <keys>`, timeout 3 s.
- `RecordingPurger` — tests.

## 5. The rule for every future page or writer

1. **A new cacheable page** adds its own subject key (`SurrogateKeys::add`) and, where it prints product cards, goes through `CatalogReader::cards()` (so every `p{id}` is added). Add its route name to `StorefrontCacheHeaders::ROUTES`. No `Set-Cookie`, no per-visitor content, no query-string content except the bounded parameters.
2. **A new writer** of anything a cacheable page prints must collect the keys: through a model event in `CachePurgeListeners` when it goes through Eloquent models; **by hand** (`CachePurgeCollector::add*`) when it uses `DB::table`, a bulk `update`, or mutes events. A change to a setting a visitor can see goes through `SiteSettingsRepository` and is collected there.
3. Never call a purger from a writer. Never purge inside a loop: collect.
4. Add a row to the purge map above and a test with `RecordingPurger`.

**A page view never collects.** Nothing a storefront read can reach (a cold cache, a missing compiled menu, a cache store that is down) calls `CachePurgeCollector::add*`; the collect calls live in the write paths only (`ReadsAndWritesTest`).

## NF-1: the content of the 404 page («Дизайн» → «Страница 404»)

The 404 is never cached by Varnish (non-200/301), so what it shows is kept cheap in two layers.

- **The set** (`App\Storefront\NotFound\NotFoundSet`): `title` (≤ 80), `text` (plain, ≤ 300, escaped on output, line breaks kept), `search` (printed only while the shop search is on), `bottom` = `none` | `tiles` | `products` | `block`, `tiles` = 4 | 6 | 8 | 12 (the top-level categories that have visible products and a picture, as the home page's «Плочки»), `products` = the home page product grid's own settings (validated by `HomeValidator`), `block` = an HTML block id, `button`. Default: the default title and text, the search, 8 tiles, the button. Validated on save, publish AND read (a bad stored value is its default; the text is cut on read; an unknown key is dropped). The published copy is `zones.notfound` of the design document the appearance already reads once per request (`NotFoundCompiler`, `StorefrontAppearance::notFound()`): the 404 costs no query for it. Draft: `design.notfound.draft`, shown only through the signed preview (`/design-preview?zone=notfound`, never cached).
- **NF-1.1 (tiles):** `categories` = the owner's chosen category ids (≤ 12, validated and cleaned on read; empty = the top-level categories with «Брой плочки»). The fragment key hashes the chosen list.
- **The fragment** (`NotFoundFragment`): «Съдържание отдолу» is rendered once, with the home page's own engine and partials, and kept in the Laravel cache for 24 h under `storefront.notfound.fragment.v{all}-{sitemap}.{hash}` — the `all` version (any «Дизайн» publish, menu, filter, setting) AND the `sitemap` version (any product or subject membership change, `CacheVersion`, moved by the collector at flush), plus a hash of what the fragment shows. A warm 404 costs 2 queries (the unknown address) / 3 (a missing product): the pins of P8b and P8d.1. The «Показвай, когато…» conditions of an HTML block are about a product: the 404 ignores them, as the home page does.
