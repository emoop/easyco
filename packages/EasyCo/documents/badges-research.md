# Badge builder (P7) — research (P7-0, 2026-11-12)

Status: RESEARCH ONLY. No code was written or run. Facts carry `file:line` (verified in the code on 2026-11-12); proposals are marked **PROPOSAL**; every open decision is in §H with a recommendation.
Sources: `design-pages-plan.md` part 5 + row P7, `storefront-design.md` §2.4 / §8.3 / §3.3 (the OLD S10 badge design: older than the owner decisions below, partly superseded), `collections-research.md` (format), the real code.

## 0. Owner decisions already taken (recorded, not re-opened)

1. Two positions on the card: TOP over the picture («Промо», «Нови») and BOTTOM under the price (informative: «Безплатна доставка», «Последни бройки», «Само онлайн»…). No empty bottom row is reserved unless at least one bottom badge is enabled.
2. «Промо» is a built-in, mandatory badge with a show / hide switch (for a product on sale).
3. «Нови» is a built-in badge (definition OPEN, §H1).
4. «Безплатна доставка» depends ONLY on the product (the card is identical for every visitor and will be cached): shipping class gives free shipping OR the current (sale) price ≥ the shop's free-shipping threshold (80.00 € → yes, 79.99 € → no). Reuse the threshold the cart already uses; never store a second one.
5. Free-text badges: text, colour, shape, display conditions through the EXISTING `ProductConditions`.
6. The builder lives in «Дизайн». Security rules: closed lists, no `style=`, no inline JS, colours as validated tokens / compiled CSS, identical HTML for every visitor.

## A. The card today

**Pipeline** (reader → read model → Blade):
- `CatalogReader::cards()` (`app/Storefront/Reader/CatalogReader.php:604`) is the ONE place that builds `ProductCard` (`:641`). It takes the already-loaded `ProductModel` rows, reads shown variations (`:618`), stock per variation in ONE read (`stockByVariation`, `:777`) and live price quotes in a batch (`quotesByVariation`, `:799`), and prints `badges: []` (`:650`). The price is `productPrice()` (`:669`) = `AvailablePriceRule::select` (`app/Storefront/Reader/AvailablePriceRule.php:25`) → `priceBlock()` (`:825`): `fromMinor` (lowest final), `toMinor`, `regularFromMinor` (set ONLY when the cheapest quote is discounted).
- Read model: `ProductCard` (`app/Storefront/ReadModels/ProductCard.php:15-26`; `toArray()` always emits `'badges'` `:44`); `Badge` (`ReadModels/Badge.php`: `type,label,placement,style,priority`; its docblock: «no resolver exists in S1»). `ProductPage` also has `badges: []` (`CatalogReader.php:183`, `ProductPage.php:73`).
- Blade: ONE partial `resources/views/storefront/partials/card.blade.php` (24 lines): `.sf-card` > `a.sf-card-link` > `.sf-card-image` (already `position: relative`, `resources/css/storefront.css:274-278`, so a top overlay needs no change of the frame) + brand + name; then `.sf-card-footer` (price `partials/price.blade.php` + «Изчерпан» `<p class="sf-stock sf-stock-out">`, `card.blade.php:15-20`) printed ONLY `if price !== null || ! inStock`. The card never prints `$card->badges` today.

**Every place a card is printed** (all go through `partials/card.blade.php`):
| Place | How |
|---|---|
| listing / category / collection, brand, season, tag pages | `category.blade.php:51` ← `CatalogReader::listing()` (`:306`) → `cards()` (`:318`) |
| home product grid | `home.blade.php:30`, `partials/home/product_grid.blade.php:5` ← `homeProducts()` (`:373`) → `cards()` (`:429`) |
| home carousel and «Подобни продукти» (a carousel kind, `design-pages-plan.md` §4) | `partials/home/carousel.blade.php:10` (renders the card view to a string) ← `homeProducts()`; the product page's «Под галерията» slot goes through `Below/BelowView.php:24` with the same reader closure |
| search | NOT BUILT: `routes/storefront.php` has no search route |
| Sandbox | `app/Sandbox/SandboxProductCard.php` is a separate sandbox model, out of scope |

So ONE injection point (`cards()`) and ONE partial cover every card; the JSON contract `ListingPage::toArray()` (`ListingPage.php:31`) carries the same `badges` key.

**Golden:** `tests/Fixtures/storefront/product_card.json` (`"badges": []`, `price.regular_from_minor: 10000`). It must stay byte-identical (§F).

**Pinned counts** (ceilings, `≤`): `tests/Feature/Storefront/StorefrontQueryCountTest.php:35-41` listing 19, category listing 21, product 22 (design budgets 14 / 16); `StorefrontPageQueryCountTest.php:22-27` HOME 26, CATEGORY 28, PRODUCT 27 (they include the footer's content-page query). NOTE for the owner: the task text quotes «home 25 / subject 27»; the constants in the repo today are the ones above (HOME is 26) — the coder must read the real numbers in Step 0, I did not run anything.

## B. Data a badge needs, per card, and its cost

| Badge / condition | Data | Already on the card? | Extra queries |
|---|---|---|---|
| «Промо» (on sale) | `PriceBlock::regularFromMinor !== null` (`PriceBlock.php`; `ProductContext::onSale()` `ProductContext.php:56-59` uses exactly this) | YES, from the live quote | 0 |
| «Безплатна доставка», price part | card `price->fromMinor` (the final price incl. price lists) vs the threshold | price YES | threshold: see below |
| «Безплатна доставка», class part | see below | no | see below |
| «Нови» | `catalog_products.timeline_at` (`2026_09_24_000001_add_timeline_at…php`; = `created_at` unless «move to front», `ProductTimelinePromoter`) or `created_at`. There is NO published-at / first-activated column | not selected: the listing select is only `id, slug, name, brand_id` + 2 media subselects (`CatalogReader.php:337-339`) | 0: add the column(s) to that SAME select |
| «Последни бройки» | `stock_levels.quantity` of the shown variations | the `$stock` map is already loaded inside `cards()` (`:629`) | 0 |
| condition `on_sale` | as «Промо» | yes | 0 |
| condition `brands` | `catalog_products.brand_id` (already selected, `:337`) | yes | 0 |
| condition `seasons` | `catalog_products.season_id` | not selected | 0: add to the same select |
| condition `categories` (+ ancestors), `tags`, `collections`, `attributes` | pivots `catalog_product_categories` / `_tags` / `_collections`, `catalog_variation_attribute_values` (`ProductContext.php:62-124`) | no | **N+1 if done as today (below)** |

**Free-shipping facts (verified):**
- There is NO shop-wide threshold setting. A threshold is `shipping_methods.free_above_minor` of a METHOD (`ShippingMethodModel.php:25`), applied ONLY if the method's free set covers the destination (`ShippingRateCalculator.php:26-33`). The cart's number is picked at runtime by `CartFreeShippingHint::forCart()` (`app/Services/CartFreeShippingHint.php`): the store country's BROAD zone (`ZoneMatcher`, no settlement / postcode) → its active methods → `FreeShippingHintReader::read()` (`app/Services/FreeShippingHintReader.php`: the LOWEST threshold among methods carrying one). Costs several statements (zones, methods for the zone, the store-country setting).
- There is NO «free» shipping class. `ShippingClass` (`packages/EasyCo/Shipping/src/ShippingClass.php`) is only a code + name; a class matters ONLY as a key of the per-class rate of a `PER_CLASS` method (`ShippingRateCalculator.php:15-20`: «a rate of 0 is valid»). The class lives on the VARIATION (`Variation.shippingClass`; FROZEN file, read-only for us — a product has NO class of its own). So «the product's shipping class gives free shipping» is not a stored fact; it must be DEFINED (§H6a). Method kind `FREE` (`ShippingMethodKind::FREE`) is free for every cart: if the zone has an active FREE method, EVERY product «has free shipping».
- `CartFreeShippingHint` states «the shipping classes of the lines do NOT affect the hint» (its docblock): the cart itself never used classes for the threshold.

**KEY RISK — `ProductContext` is per product and lazy.** `ProductContext` is built once per product PAGE (`ProductController.php:51-52`, `ProductContext::fromPage`) and each of its pivot reads is its own statement (`categoryIds()` 1 + 1 parents map, `tagIds()` 1, `collectionIds()` 1, `seasonId()` 1, `attributeValues()` 1). On a page of 24 cards a conditioned badge set would cost up to 5×24 = 120 statements (pins are 19-28).

**PROPOSAL — page-batch evaluation (recommended).** Evaluate all badge conditions of a page in a FIXED number of statements, inside `cards()` (the one injection point):
1. The compiled badge set (§D) lists the condition KINDS some ENABLED badge uses (`categories, tags, seasons, brands, attributes, collections, on_sale`). Only those kinds are read.
2. For the page's product ids (≤ 24 per page, ≤ 24 per carousel): ONE grouped statement per used kind: `select product_id, category_id from catalog_product_categories where product_id in (…)` (+ the one parents map the context already shares, `ProductContext::$parents`, so ancestors expand in PHP), same for tags, collections (the switch rides inside via `CatalogSubjectKind::COLLECTION->gated(...)`, `ProductContext.php:93-96`), attributes via `catalog_variation_attribute_values where variation_id in (shown variations — already known in `cards()`)`. `brand_id`, `season_id`, `on_sale` cost 0 (§above). Worst case = 4 statements + the parents map = 5 per card-printing page region, independent of the number of cards; a page with no enabled condition kind pays 0.
3. Build a lightweight `ProductContext` per product from these maps (a second constructor / a `ProductContext::fromBatch(...)` with the lazy fields pre-filled). The PREDICATES and `ProductConditions::matches()` (`ProductConditions.php:48`, `:94`) stay the ONE logic; no second copy.

Alternatives compared:
| Option | Extra statements per page | Staleness | Cost / risk |
|---|---|---|---|
| (1) Lazy per-card `ProductContext` (today's code) | up to 5 × cards | none | N+1: REJECTED |
| (2) **Page-batch (above)** | ≤ 5, only for used kinds, 0 when none enabled | none (live data) | small: 1 new class + a constructor; pins get an explicit new pinned test «with badges» |
| (3) Correlated `group_concat` sub-selects inside the existing products read (the P6e-3b technique, `CatalogScopeResolver`) | 0 | none | 1024-byte cut of `group_concat`; touches 4 different query builders (listing, home, subjects, similar) that all end in `cards()`; ancestors still need PHP; more fragile than (2). Keep as a fallback only if a pin forbids (2) |
| (4) Precomputed derived column / compiled set refreshed by triggers (like `is_available`, `ProductSortPriceRefresher`, `SortPriceTriggers`) | 0 | until the refresher runs; needs triggers on 6 sources (categories, tags, collections, season, brand, attribute values) + a full recompute job every time a badge RULE changes | highest effort and failure surface; REJECTED for v1 |

**Free-shipping threshold with 0 per-card cost — PROPOSAL.** Compute ONE number («shop free-shipping threshold, minor units + currency», or null) NOT per request: a small `FreeShippingThreshold` service reuses the very selection of `CartFreeShippingHint` / `FreeShippingHintReader` (lowest threshold of the store zone's active local methods; `null` if none) and the result is kept in the application cache under a stable key with NO expiry, REPLACED when a shipping method, a zone or the store country is saved (same pattern as `StorefrontAppearance::CACHE_KEY`, `StorefrontAppearance.php:37`). Warm pages pay 0; a cold one pays the zone+methods reads once. Staleness: none if the save hooks refresh it (the coder must find every writer of methods / zones). Rule on a card: `price->fromMinor >= threshold` (the cheapest AVAILABLE variation's final price; `>=` like `ShippingRateCalculator`), OR the class rule of §H6a. Quantity is 1: the badge says «this product has a free shipping option», not that every method is free (`design-pages-plan.md` part 5).

## C. Caching and time dependence

- **Fact:** there is NO page cache today (the only `s-maxage` is `CardSizesController.php:30`); the S4 cache in `storefront-design.md` §3 is planned. Today every request renders live, so every badge is correct at render time. The rules below make them stay correct under S4:
- Time-dependent badges:
  1. «Нови»: flips `N` days after the product's date. PROPOSAL: compare at DAY granularity in the store time zone (`StoreTimezone`), so a TTL of minutes/hours is never visible; the worst case is «late by the page-cache TTL». No refresh job needed.
  2. «Промо» and `on_sale` conditions and the price part of «Безплатна доставка»: depend on a dated price list. Facts: `prices:refresh-boundaries` and `prices:refresh-from` run every minute (`routes/console.php:39-40`) and refresh the STORED `price_from_minor`; the card price itself is a live quote. Under S4 the same boundary pass (it already knows which products a list touches) is the natural place to emit the «purge these URLs» event planned in `storefront-design.md` §3.3 — one more subscriber of the existing refresher, NOT a second scheduler. Until S4 exists nothing is needed.
  3. A badge's own validity dates are NOT proposed (decision 5 reuses `ProductConditions`, which has no dates); a «Black Friday» campaign is switched on / off by the merchant by the single `enabled` switch, which republishes the set (below) and purges. Dates on a badge are an OPEN QUESTION only if the owner wants them (§H6b).
- Stock-based («Последни бройки»): changes with every order → under S4 the existing «stock change → purge product URL» rule (`storefront-design.md` §3.3, line 337) applies; category lists tolerate the TTL (O5 of that document). If the owner prefers zero staleness, do not cache listing pages that show this badge (a per-page decision, not a data one).
- Config change (badge text, colour, enable): the publish writes the compiled set and recompiles the stylesheet; S4 purges everything (a global event, like a theme change).

## D. Storage and editor

**Where it is stored — PROPOSAL.** The «Дизайн» zones follow ONE pattern: a published value + a DRAFT beside it, «Приложи / Публикувай», validated on save and again on read, a compiled copy read through `StorefrontAppearance` (`StorefrontAppearance.php:94 data()`, one `getMany` of `AppearanceSettings::READ_KEYS`, `AppearanceSettings.php:166`, kept in the app cache). Do the same: ONE setting key `storefront.badges.compiled` (JSON) added to `READ_KEYS` → **0 extra statements** on any page, warm or cold (it rides the same `getMany`). Zone registry: `DesignScreen::ZONES` (`app/Filament/Pages/Design/DesignScreen.php:52`) has 13 zones; add a NEW zone **`badges`** («Баджове») rather than extend «Каталог» (`CatalogZone`, a fixed-field form: columns / per page / card name lines) because a badge list needs a repeater of rows and the built-ins' switches; the card-name-lines precedent (`catalogCardNameLines()`) shows card settings sit in «Каталог», but a list editor does not fit that form. Draft / publish storage: the pattern of `PageDesignStore` (published key + draft key) or the repeater-capable `ZoneStore` adapters (`app/Storefront/Design/ZoneStore/*Adapter`), the coder picks by Step 0 (the Sidebar zone is the closest list-editor model).

**One badge (fields; all validated, closed):**
| Field | Rule |
|---|---|
| `kind` | `builtin:sale` / `builtin:new` / `builtin:free_shipping` / `builtin:low_stock` (if built-in, §H5) / `custom` |
| `text` | plain text ≤ 24 chars (`App\Rules\PlainText`, already used by `StorefrontAppearance::isPlain`), no control characters; built-ins have a default translated text that the merchant may override |
| `position` | `top` / `bottom` (closed) |
| `colour` | a CLOSED palette of ≥ 8 design tokens (`neutral, red, orange, green, blue, purple, dark, light`) mapped to CSS classes `sf-badge--{token}`; PLUS (optional, owner's call §H7) a validated `#rrggbb` that is written into the compiled stylesheet as a class `sf-badge-c{n}` by `AppearanceCompiler` (same mechanism as the colour tokens: `AppearanceCompiler.php` header: «a colour is `#RRGGBB`», nothing else reaches the output). NEVER `style=` (a static test forbids it, `AssertsSafeHtml.php`) |
| `shape` | `square` / `rounded` / `pill` (closed list → class) |
| `priority` | integer 1-99 (lower first) |
| `enabled` | bool (the «Промо» switch is this field; «Промо» cannot be deleted, only switched) |
| `conditions` | the `ProductConditions` shape (`ProductConditions.php:43` KEYS), validated by `ProductConditions::validate` / `storable` / `formState` / `fromForm` — the SAME form component the HTML-block admin uses |
| builtin params | «Нови»: `days` 1-90; «Последни бройки»: `max_qty` 1-20; «Безплатна доставка»: none (threshold is the cart's) |

**Limits — PROPOSAL:** ≤ 12 badges in total (the compiled JSON stays tiny and the batch reads stay bounded); at most 2 badges SHOWN in TOP, 2 in BOTTOM per card (§H4); text ≤ 24; conditions inherit the `ProductConditions` limits (20 ids per list, 5 attribute rules).

**Editor — PROPOSAL.** A Filament repeater in the zone's panel (one field per row, narrow, `admin-ui-standard.md`): built-ins first (fixed order, a switch + their few parameters, not removable), then «Допълнителни баджове» (add / remove / reorder, ≤ 12). A live preview of a SAMPLE card through the existing SIGNED preview (`DesignPreviewController::previewUrl`, used by `CatalogZone::previewUrl()`): the working copy is the draft; the public storefront reads ONLY the published copy. Compile on publish: sort, drop disabled, re-validate, write the compiled JSON + the badge CSS; if the set has no enabled badge, write NOTHING (so `badges` stays `[]` and every page is byte-identical to today).

**Rendering — PROPOSAL.** `cards()` calls a `BadgeResolver` (pure; input: the compiled set, the card's facts, the per-product context) → `list<Badge>` (the read model already exists; `style` = the colour token class, add `shape`). The partial prints, only when the card has badges of a position: TOP `<ul class="sf-cbadges sf-cbadges--top">` INSIDE `.sf-card-image`; BOTTOM `<ul class="sf-cbadges sf-cbadges--bottom">` after the price, inside `.sf-card-footer`; each `<li class="sf-cbadge sf-cbadge--{colour} sf-badge--{shape}">{{ $text }}</li>` — plain text, `{{ }}` escaped, no attribute from data except the closed class names. The footer is printed today only `if price !== null || !inStock`; with a bottom badge it must print for it too (§F).

## E. Product page

- Natural places already exist: ABOVE the gallery (an overlay at the top corner of the gallery frame, mirroring the card's top row) and NEXT TO the price (the summary zone's price component, `ProductSummaryZone`, `app/Storefront/ProductSummary`). `ProductPage` already carries `badges` (`ProductPage.php:73`) and `ProductContext::fromPage` already holds the full price block, so the built-ins and `on_sale` cost 0; conditions cost ≤ 5 reads for ONE product (no N+1 on a page of one product; the lazy context is fine here: `ProductController.php:51-52`). The product page pin (22 / 27) holds with no badge enabled; with conditions it gets its own pin.
- Effort: small, reuse of the resolver and the partial. The only design question is WHICH bottom badges make sense on the product page («Последни бройки» yes, per-variation stock vs the sum; «Безплатна доставка» yes). Recommend: yes, in a second, smaller step (P7-3), §H3.

## F. Pinned counts and goldens

- **With NO badge enabled (the default and every existing shop):** the compiled setting is absent → `cards()` skips the resolver entirely → `badges: []` → the partial prints NOTHING new. Therefore `product_card.json` stays byte-identical, every pinned count stays (the key rides the existing `getMany`: 0 statements), and the HTML of a card is today's HTML. Test: `CardBadgesOffTest` asserting identical HTML and identical `DB::listen` counts with and without the code path.
- The three DB-bound columns added to the listing select (`timeline_at`/`created_at`, `season_id`) change the SQL text but not the number of statements.
- **With badges enabled:** NEW pinned tests (never raise an existing ceiling): «builtins only» = 0 extra statements; «conditions of kinds K1..Kn» = + the number of used kinds, independent of the number of cards (a test with 5 and with 24 products must count equally — the mutation: switch the evaluator to per-card context → the test is red).
- New goldens: `product_card_with_badges.json` as a SECOND fixture (never overwrite the old one).
- The cart and checkout pins are NOT touched (badges are catalog-only; the cart's free-shipping hint is unchanged).
- Classifier warning from P6e-3b: the pinned «settings read» tests now classify by main `FROM` table; the new compiled key rides `getMany`, which they already count the same way.

## G. Split proposal (coder tasks in order)

| Task | Content | Model | Full run |
|---|---|---|---|
| **P7-1** | Storage + the «Баджове» zone + editor (draft / publish / signed preview) + compiled set + CSS classes + rendering on the card + the built-ins with ZERO-query data: «Промо» (switch, text), «Нови» (days), «Последни бройки» (stock already loaded), `badges: []` byte-identical when off; a step-0 for the free-shipping rule is NOT done here | Sonnet 5.5 | one `--full` at the end |
| **P7-2** | «Безплатна доставка» (threshold service + cache + hooks on method / zone / country saves + the class rule) AND the conditional free-text badges with the page-batch evaluator (§B option 2) + new pins and the mutation «per-card context» | Sonnet 5.5 (the evaluator is the risky part; if it feels too complex, flag it, do not pre-emptively upgrade) | one `--full` |
| **P7-3** | Product page: top over the gallery + next to the price; product pin with badges | Sonnet 5.5 | one `--full` |
Reason for this order: P7-1 delivers visible value with no query risk and fixes the data model; the two risks (the batch evaluator and the shipping rule, which needs a decision) are isolated in P7-2.

## H. Open questions for the owner (recommendation first)

1. **«Нови» — what is «new»?** Options: (a) days since `timeline_at` (`storefront-design.md` §8.3 chose it; a product «moved to front» becomes «new» again — maybe wanted, maybe a surprise); (b) days since `created_at` (a product created as a draft and published 3 weeks later may never be «new»); (c) days since FIRST ACTIVATION: needs a new column + the activation path, i.e. a change of `Product.php` (FROZEN) or an app-layer listener — a question, not a proposal; (d) a manual flag per product (a collection or tag named «Нови», used through a free-text badge condition: works TODAY with no code). **Recommend (b) `created_at` + `days` setting (default 14), and (d) for control:** the merchant who wants hand-picked «new» products makes a collection and a free-text badge. If the owner promotes products only to reorder, (a) misleads.
2. **«Промо» text:** (a) fixed word «Промо» (recommend for v1: simple, identical for every visitor, and a range card whose variations have different percents has no honest single percent); (b) «−X%» of the CHEAPEST discounted variation (the block's own `regular_from` vs `from`: the card's displayed price, so it agrees with the strike-through; a range → label «до −X%» is NOT recommended: two variations, two percents); (c) a choice «дума / процент». Recommend (a) now, (c) later; when percent is chosen it must be integer, 1-99, rounded half-up, from the SAME block the card prints.
3. **Product page too?** Recommend YES, in P7-3 (cheap, §E). The owner may stop at P7-1/2.
4. **How many per position, and in what order?** Recommend ≤ 2 TOP + ≤ 2 BOTTOM per card; order by `priority` then the builtin before custom, then the id; the extras beyond the limit are dropped (not wrapped). On a phone the TOP row keeps ≤ 2 and BOTTOM ≤ 2 (one line, ellipsis, like the card name).
5. **«Последни бройки»:** built-in (recommend: stock is already loaded; a condition kind «stock ≤ N» would add a new predicate to `ProductConditions`) with `max_qty` (default 3). Needs a definition for variable products: (a) the SUM of the shown variations' stock ≤ N (recommend), or (b) the cheapest available variation's stock ≤ N. Never when out of stock (the «Изчерпан» label owns that state, `card.blade.php:18`; `catalog_products.is_available` (P6c) is the list-level availability and is not needed here).
6. **Anything the code reveals:**
   a. **«Shipping class gives free shipping» has no data behind it** (§B): the class is on the VARIATION (frozen), it is only a rate key of PER_CLASS methods, there is no «free» class. Options: (i) drop the class part: free = threshold only (recommend: honest and simple); (ii) a PER_CLASS method in the store zone with rate 0 for the class of the product's variations (needs a read per page of variation classes + rates: costs a statement, and a variable product may mix classes); (iii) a new «free shipping» flag on the shipping class (a domain change in the Shipping package, outside P7). Also: if the zone has an active method of kind FREE, EVERY product is «free» — recommend: then the badge is shown on none (it would be noise) unless the owner wants it everywhere.
   b. **Badge dates** (a «Black Friday» that switches itself on / off): `ProductConditions` has none. Recommend NOT now: one `enabled` switch; dates are a later extension through a scheduled boundary like the price-list one.
   c. **Free-shipping threshold when several methods carry different thresholds or the zone is narrowed by settlement:** the cart hint takes the store country's broad zone and the LOWEST threshold; the badge inherits exactly this. Say so in the admin hint.
   d. **Colour freedom (§D):** closed palette only (recommend; contrast guaranteed with the text colour) vs palette + validated hex compiled into the stylesheet (needs an AA-contrast check on save).
   e. **The old S10 design (`storefront-design.md` §8.3) is superseded** by the owner decisions above (it had `storefront_badges` table, 3 custom styles, max 2 badges, `scope_type`): P7-1 should mark that section «superseded by badges-research.md» in the same commit. Its tokens `new, sale, custom-1..3, promo, shipping` are NOT reused.
   f. **Frozen files:** nothing in this plan touches `Product.php`, `Variation.php` or `VariationSignature.php`; only option 1(c) above would.
   g. **Search results** are not built; when search arrives it will go through `cards()` and inherit badges.
   h. **Hooks:** the badge set is a merchant setting, not an extension point; no new Hook is proposed (the project rule: domain packages never call `Hook::`, only the app layer). If the owner wants a theme to add badges, a filter `storefront.card.badges` would be added in the same commit as its Hook Reference row — NOT proposed now.

## Owner decisions (2026-11-12) — recorded at the start of P7-1

1. **Every built-in badge is OFF by default.** A shop that never touches the zone prints today's HTML byte for byte (`product_card.json` unchanged, every pin unchanged).
2. **«Промо»** (top by default): shown when the card's price block is discounted (`regularFromMinor !== null`). The merchant chooses the display: a WORD (default «Промо», editable, ≤ 24 chars plain text) or a PERCENT «−20%» = `round((regular − final) / regular × 100)` half-up, an integer, from the SAME block the card prints (the cheapest available variation); not shown if the result is < 1. No own dates: it follows the live price, so a price list's date range applies automatically.
3. **«Нови»** (top by default): only when enabled; days since `catalog_products.created_at` (decision to H1: `created_at`, not `timeline_at`), `days` 1–90 (default 14), compared at DAY granularity in the store time zone. Text default «Нови», editable.
4. **«Последни бройки»** (bottom by default): only when enabled; the SUM of the stock of the card's shown variations is between 1 and N, N from the closed list 1–6 (default 3). Never when out of stock (the «Изчерпан» label owns that state). Text default «Последни бройки», editable.
5. At most **2 badges per position** per card, ordered by `priority` (1–99, lower first), then built-in before custom; extras are dropped, never wrapped.
6. Colours: a closed palette of 8 tokens (neutral, red, orange, green, blue, purple, dark, light); shape square / rounded / pill. No hex, no `style=`.
7. NOT in P7-1: «Безплатна доставка», custom free-text badges with conditions (P7-2), the product page (P7-3). The stored shape carries a `kind` per row so P7-2 adds kinds without migrating stored data.
8. Decided with them: **free shipping = the threshold only** (the lowest threshold of the store zone's active methods; no shipping-class rule; not shown anywhere if the zone has an active FREE method); badges also on the product page (P7-3); no badge dates.

## As built (P7-1)

- **Storage:** published COMPILED copy `design.badges.global` (only the ENABLED rows; the key does not exist while no badge is on) in `AppearanceSettings::READ_KEYS` → read with the appearance's ONE `getMany` (no statement of its own) and kept in the appearance cache entry (`StorefrontAppearance::badgeSet()`); its draft `design.badges.global.draft`. `BadgeStore` (draft / publish / discard, the pattern of `SidebarStore`); `BadgeSet` (validation, defaults, compile; strict read: a copy that does not validate renders nothing).
- **Row shape:** `{kind, enabled, text, position top|bottom, colour, shape, priority}` + sale `mode` word|percent, new `days`, low_stock `max`. `kind` is the extension point of P7-2.
- **Zone «Значки»** (`BadgesZone`, key `badges`, the LAST entry of `DesignScreen::ZONES`; in the menu right after «Страници и карти»): a form zone, three sections, one field per row, an info icon on the non-obvious fields (bg + en), «Приложи» / «Публикувай» / «Начални настройки» as every zone, a signed preview of the first category (`DesignPreviewController::badges`).
- **Resolver:** `BadgeResolver` (pure) called from `CatalogReader::cards()` only when a set exists. Facts it uses, all already loaded: the price block, the stock map (`cards()`), `catalog_products.created_at` (added to the SAME listing select; the home / similar / subject reads all go through that select). The store day comes from `StoreTimezone` (the middleware has read `site.timezone` by then on every storefront request: the memoised read is free).
- **Rendering:** `partials/card.blade.php` + `partials/badges.blade.php`: TOP `<ul class="sf-cbadges sf-cbadges--top">` inside `.sf-card-image` (the no-picture placeholder loses its `aria-hidden` only while it holds a badge); BOTTOM `<ul class="sf-cbadges sf-cbadges--bottom">` right after the price inside `.sf-card-footer` (the footer prints for a bottom badge too). The statements use same-line `@if` so an empty position adds NOT ONE byte (`tests/Fixtures/storefront/card_html_before_badges.json` is the pre-change HTML of five card shapes, a NEW fixture). CSS: `.sf-cbadge*` in `resources/css/storefront.css`.
- **Read model:** `Badge` gained `shape` (default `square`); `ProductCard::badgesAt($placement)`.

## As built (P7-1.1) — corrections after the first look (2026-11-12)

- **«Always a pill» — the cause:** the menu's label `.sf-badge` (`resources/css/storefront.css`, near the end of the file, MENU-2) is UNLAYERED and has `border-radius: 9999px`; the card's rules lived in `@layer components`, and an unlayered rule beats every layered one whatever the specificity (it also set the same custom properties `--sf-badge-bg / -fg`). So every card badge was a pill. **Fix:** the card classes are renamed `sf-cbadges`, `sf-cbadges--top|bottom`, `sf-cbadge`, `sf-cbadge--{pair|shape|oNN}` (Blade, CSS, tests, docs, browser script); the menu's class is NOT touched. `BadgeContrastTest` fails if a card view or the card CSS block uses `.sf-badge`.
- **Colours = six ready contrast pairs** (`BadgeSet::PAIRS`, the same values are `--sf-cbadge-rgb / -fg` of `.sf-cbadge--{key}`; a test compares them): `red` #be0f2c + white, `orange` #f59e0b + black, `blue` #1e40af + white, `green` #15612f + white, `black` #111 + white, `grey` #e5e5e5 + #111. Contrast over white at 100 / 90 / 80 % of the background: red 6.37 / 5.61 / 4.79, orange 9.78 / 10.54 / 11.31, blue 8.72 / 6.83 / 5.31, green 7.55 / 5.91 / 4.64, black 18.88 / 14.55 / 10.21, grey ≈ 15.7 / 16 / 16.3 (all ≥ 4.5; the first suggestions #1d4ed8 and #15803d fell below 4.5 at 80 % and were darkened). Defaults: «Промо» red, «Нови» black, «Последни бройки» orange. Old tokens are read: `dark`→`black`, `light` and `neutral`→`grey`, `purple`→`blue`.
- **Opacity** of the BACKGROUND only: 100 / 90 / 80 (`opacity` in the row, default 100; classes `--o100|--o90|--o80`, `rgb(var(--sf-cbadge-rgb) / var(--sf-cbadge-a))`); the text stays opaque. Over a photo the contrast is not computable: the field's hint says «при по-светла снимка изберете 100%».
- **Size:** 11px letters, padding 0.25rem 0.625rem (a phone ≤ 30rem: 0.5rem and a tighter letter-spacing, so «Последни бройки» stays whole on one line in a ~150px card), upper case + letter-spacing, the top row inset 0.625rem.
- **Tests:** `BadgeContrastTest` (AA at every opacity, CSS = PHP pairs, three different radii, no class collision); `CardBadgesTest` / `DesignBadgesZoneTest` extended (pairs, legacy tokens, opacity). The `StorefrontQueryCountTest` warm-up is in the P7-1 commit.
