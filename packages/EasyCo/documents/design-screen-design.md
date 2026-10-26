# The «Дизайн» screen: one schema-driven core for every zone of the storefront

Status: proposal for the owner's approval (2026-10-23). Base: `main` at a33c798 (HDR-3).
Builds on `storefront-customization-design.md` (D1–D5, §12–§18 "as built") and `menu-editor-v2-design.md`.
Agreed look and feel: the canvas "EasyCo — екран Дизайн" (4 boards: Хедър, Каталог, Футър, Телефон).

## 0. Decisions this document takes

| # | Decision |
|---|---|
| DS-D1 | **No parallel system.** The header (D2) and footer (FT-1) builders are re-expressed on one core and moved into the new screen. Their stored keys, validators' rules, compiled payloads and storefront markup stay byte-for-byte the same. The merchant's published shop does not change. |
| DS-D2 | **One component descriptor** (PHP array) drives the admin form, the validator, the compiled CSS classes/variables and the help text. `HeaderCatalog` and `FooterCatalog` become two *component sets* of the same registry. |
| DS-D3 | **One closed set of field kinds** (§2) and **one set of common property groups** (§3), identical in every zone. |
| DS-D4 | **The layout tree is plain data with stable node ids**, edited by Livewire actions (add / move / remove / select), **not** by nested Filament repeaters/builders. Only the *selected* node's settings are a Filament form. This removes the Livewire nesting-depth problem and the `getState()` key renumbering (`ReconcilesFormKeys` is no longer needed in the new screen). |
| DS-D5 | **Scope key** on every layout: `global` or a page type. v1 uses `global` for Топбанер / Хедър / Футър / Сайдбар, and page types for Съдържание. Catalog and category share one page type `listing` (owner, 2026-10-23). |
| DS-D6 | Storefront stays server-rendered, cookie-free, cacheable; **no new JS dependency, no `style=`**; every compiled payload rides the ONE appearance read (`AppearanceSettings::READ_KEYS`); query pins do not move. |
| DS-D7 | No drag-and-drop: a palette chip adds the component to the selected cell; ← → ↑ ↓ and × move/remove (owner, 2026-10-22). |

## 1. Vocabulary and the generic layout tree

```
Zone      topbar | header | footer | content | sidebar | checkout_header     (fixed list, v1)
Scope     "global" | "home" | "product" | "listing"                          (grammar §4)
Layout    { v: 1, variant?: string, props: {…}, rows: [Row] }               (header has two layouts: desktop, mobile)
Row       { id, props: {…}, cells: [Cell] }
Cell      { id, props: {span?, align?}, items: [Node] }
Node      { id, type, settings: {…}, common: {…} }
```

- `id` is a short random string (`[a-z0-9]{8}`) given when the node is created and never reused; it is what the screen selects, what an error points at, and what a compiled CSS rule is keyed by (`.sf-n-{id}`) when a node needs a variable.
- Each zone declares its **shape** (a `ZoneSpec`): row limits, cell limits, fixed cell names, which component set is allowed, per-type limits (`logo` exactly once, `menu_toggle` ≤ 1, …). Examples:
  - header desktop: rows fixed `top | main | bottom`, each exactly 3 cells named `left | center | right`, ≤ 6 nodes per cell;
  - header mobile: rows `top | main`, plus the drawer as one extra cell (fixed order rules of MENU-4 stay in `HeaderView`);
  - footer: upper 1–2 rows × 1–5 cells (span 1|2), ≤ 6 nodes/cell, ≤ 40 nodes; lower = one row, one cell, one `text_line` node;
  - content/home: 0–20 rows ("sections"), 1–4 cells each;
  - content/product: a fixed core (§7) plus named slot cells;
  - checkout_header: one row, fixed `logo` + optional `text` (§6).
- **Adapters, not a data migration.** For header and footer the core reads/writes through a `ZoneStore` that converts between the generic tree and the stored D2/FT-1 JSON (`header.layout.draft|published`, `footer.layout.draft|published`). The adapter adds `id`s on read (deterministic from the path for stored data that has none, e.g. `h-d-main-left-0`), drops them on write where the old shape has no place for them, and is covered by a round-trip test: *stored → tree → stored* is identical for the seeded defaults and for a large fixture.

## 2. Field kinds (shared, closed)

One registry `App\Storefront\Design\FieldKinds`; each kind = `default()`, `validate($value, $spec): ?error`, `form($name, $spec): Filament component`, `toPlain($value)` (for the compiled payload). Existing kinds of both catalogs map onto it one to one:

| Kind | Spec keys | Notes |
|---|---|---|
| `int` | min, max | |
| `enum` | options, `display: select|cards|buttons` | `cards` = visual select cards (radio + CSS thumbnail), used for layouts |
| `bool` | | |
| `text` / `textarea` | max | through `PlainText` |
| `url` | | root-relative or `https://` (existing `isSafeBannerUrl` rule) |
| `email`, `phone` | | phone through `ValidPhone` |
| `html` | max | `DescriptionSanitizer` on save AND on render |
| `color` | | hex only, `#rgb|#rrggbb`; empty = inherit the Облик token |
| `menu` | | id of an existing menu or empty = the zone's location menu |
| `html_block` | | id; deleting a used block is refused (existing `HtmlBlockUsage`, extended to all zones) |
| `media` | image only | `MediaAsset` id; READY check at compile; later the media library adds a picker, the stored value does not change |
| `category`, `page`, `product`, `tag` | | id; a missing target is skipped at compile, like menus |
| **`attribute`** | `filterable_only: true` | the **attribute picker** (owner, 2026-10-23): stores the attribute's **code/slug**, shows its current label; renaming the label never breaks the setting |
| `social`, `payments` | max | the existing closed lists |
| `list` | of: one kind, max | a small flat list (e.g. filter values order) — never nested deeper than one level |
| **`items`** | fields (simple kinds + `media`), min, max | a list of small records with their own fields: slides, cards, gallery pictures (§7.1). One level only: an `items` field never holds another `items` or `list`. Edited in the properties panel as a compact repeater with ↑ ↓ ×; state path `props.settings.slides.2.heading` (depth 5) |

Extensions keep adding component types through `storefront.header.elements` / `storefront.footer.blocks` and the new `storefront.design.components` (§5); an extension field may use only `int, enum, bool, text, textarea, url, color` (as today).

## 3. Common property groups ("supports")

A component descriptor lists the groups it supports; the properties panel shows them in one fixed section **«Общи»** above the component's own fields, with the same labels and order everywhere.

| Group | Values (closed) | Compiles to |
|---|---|---|
| `align` | start / center / end | class `sf-al-{s|c|e}` |
| `spacing` | top and bottom: 0 / S / M / L | classes `sf-mt-{0|s|m|l}`, `sf-mb-…` (one scale, defined once in `storefront.css`: S 8px, M 16px, L 32px; the theme may redefine the variables) |
| `visibility` | desktop on/off, phone on/off | `sf-hide-d`, `sf-hide-m` (CSS at the existing breakpoint, no UA sniffing) |
| `colors` (rows/zones only) | background, text (`color` kind) | CSS variables `--sf-n-{id}-bg/-fg` in the token stylesheet, used by `.sf-n-{id}` |
| `text_size` (rows/zones only) | S / M / L | class `sf-ts-{s|m|l}` |

- The header **row colours and text size** (open item from §4 of the handover) are simply `colors` + `text_size` on header rows: built once, here.
- Footer `bg/text/border` keep their FT-1 variables (`--sf-ftu-*`, `--sf-ftl-*`); the adapter maps them to/from the `colors` group, so nothing in the footer CSS changes.
- Rule: a group value equal to its default is not stored and emits nothing (D1 rule "only differences are compiled").

## 4. Scope key and storage

- Grammar: `global` | `home` | `product` | `listing`. Reserved for later, **not built**: `listing:category:{id}`, `product:category:{id}` (per-category overrides; resolution = most specific scope that has a published layout, else the page type, else `global`).
- New zones store `design.{zone}.{scope}.draft` / `.published` (JSON) and compile into **one** key `design.compiled` = `{hash, zones: {zone: {scope: payload}}}` (≤ 256 KB, refused with a message otherwise), added to `AppearanceSettings::READ_KEYS`. Header and footer keep their own keys through the adapter (§1); the top banner keeps the D1 `banner.*` keys.
- **Validation in three places stays the rule:** on save, on publish/compile and on every read of the cached payload; invalid on read → the zone's safe default (header seeded default, old footer, empty content zone = today's page).
- Draft / Приложи (= save draft + reload preview) / Публикувай / Върни публикуваното work **per zone and scope**; publishing one zone never publishes another.

## 5. The component descriptor

```php
'banner_grid' => [
    'label'    => 'Банери',                 // Bulgarian admin label (lang key in code)
    'icon'     => 'grid',                   // palette chip icon, closed list
    'zones'    => ['content:home'],         // where the palette offers it
    'supports' => ['align', 'spacing', 'visibility'],
    'limits'   => ['max_per_layout' => 4],
    'requires_route' => null,               // e.g. 'storefront.search': stored always, printed only when the route exists
    'fields'   => [
        'layout' => ['kind' => 'enum', 'options' => ['1', '2', '3', '1-2', '2-1'], 'display' => 'cards', 'default' => '2'],
        'items'  => ['kind' => 'list', 'of' => 'html_block', 'max' => 3],
    ],
    'view'     => 'storefront.design.components.banner_grid',
],
```

- `App\Storefront\Design\ComponentRegistry::for(zone, scope)` = built-ins ∪ hook additions; a hook that removes/replaces a built-in is ignored and logged once (existing rule). Header/Footer catalogs become thin wrappers that return their set from the registry, so existing callers and tests keep working.
- A view receives `$settings`, `$common` (already resolved to classes), `$data` (compiled lookups: menus, HTML blocks, media renditions) and `$uid`. Views never query.

## 6. The admin screen «Дизайн»

- One Filament page `App\Filament\Pages\Design\DesignScreen` (group «Дизайн», `content_manage`), full width, sidebar collapsed on this page, button «Към админа». Layout as on the canvas: zone list left (Топбанер, Хедър, Футър · Съдържание: Начална, Продукт, Каталог/Категория · Сайдбар · Количка и поръчка), palette on top, schema + preview in the middle, properties right, **Десктоп / Телефон** toggle (switches the header between its two layouts, sets the preview frame to 390 px, and filters the visibility group).
- **Schema** = the tree drawn as boxes (rows → cells → node chips), each cell with `+`, each node with ← → ↑ ↓ ×; all actions are Livewire methods on the plain array (`addNode(cellId, type)`, `move(nodeId, dir)`, `remove(nodeId)`, `select(nodeId)`), each re-checked against the `ZoneSpec` limits on the server.
- **Properties** = a Filament form built from the selected node's descriptor (`«Общи»` + own fields), state path `selected.settings.*` (depth ≤ 4). Errors from the validator come as `nodeId + field`; the screen selects that node and Filament shows the message under the field (UX-0: `novalidate`, Bulgarian messages).
- **Preview**: an `<iframe>` of a signed 30-minute URL `GET /design-preview?zone=&scope=&device=` (`noindex`, `no-store`), rendering the page type of the scope with the draft of that zone and the published rest. `/header-preview` and `/footer-preview` become aliases of it.
- **Количка и поръчка**: a fixed simplified header (logo, optional phone/text, a «Към магазина» link) with 3 settings; no palette. Cart and checkout keep their layout otherwise (C-D6).
- The old pages «Хедър» and «Футър» stayed during stage DS-2 for comparison and were removed in DS-3 (their urls redirect to the new screen with the zone selected).

### 6.1 Compact density (DS-2a.1, the standard for every zone)

The screen is dense on purpose: the owner works on a board, not on a form. Everything lives in `resources/css/design-screen.css`, under the screen root `.ds` (and `.fi-page:has(.ds)` for the page header's buttons), and is defined as CSS variables that EVERY later zone (footer, top banner, checkout header, home, product) must use instead of its own numbers:

| Variable | Value | Meaning |
|---|---|---|
| `--ds-zones-w` / `--ds-props-w` | 9.5rem / 18.5rem | the zone list and the properties column (the board takes the rest; below 1100px the properties go under the schema) |
| `--ds-fs-base` / `--ds-fs-small` / `--ds-fs-tiny` | 13px / 12px / 11px | text, labels and buttons, chips and hints |
| `--ds-control-h` | 32px | inputs, selects, buttons |
| `--ds-chip-h` | 28px | chips, arrows, plus and cross (44px on `pointer: coarse`) |
| `--ds-gap` | 0.5rem | space between fields |

Rules: the zone list is dark graphite (`#2b2f33`, `#1f2326` in dark mode) with text of at least 4.5:1 (the muted captions and the «следва» entries use `#a9b0b7`, the active zone a primary left bar and white text); Filament's own controls are shrunk only inside `.ds-props` and in ONE commented block «Compact Filament overrides (re-check after a Filament upgrade)». No other admin page is touched. `tests/Browser/design-screen.mjs` measures the columns, the graphite and the contrast.

## 7. Content zone (later stages, frame only)

- **Начална**: sections from D5 as components — HTML block (instead of a slider), banner grid, category tiles, product row (by category / tag / season, newest or featured, fixed N: no randomness), text, the editable intro (S3-V). An untouched home renders exactly as today.
- **Продукт**: the core stays fixed in order (gallery, title, price, variation choice, add to cart, description); the merchant chooses the **gallery layout** with visual cards: thumbnails left · thumbnails below · 2-column grid · single column stacked · large first + tiles. Phone: always a CSS scroll-snap strip with dots (the dots and click-zoom reuse the small existing `storefront.js`, no library). Named slot cells around the core (`below_title`, `below_price`, `after_add_to_cart`, `after_description`) take HTML block / text / button components. The owner may still send 2–3 reference shops for the gallery before DS-5.
### 7.1 Content components (owner, 2026-10-23)

Available in the content zones (Начална first; the same components later in the product slots and at the top of a category). Every one supports the common groups `align`, `spacing`, `visibility`; every picture is a `media` field (an image `MediaAsset`, rendered from its WebP variants with `srcset`, `sizes`, `width`/`height` to avoid layout shift, `alt` required); every link is the safe-URL rule (root-relative or `https://`).

| Component | Own fields | Notes |
|---|---|---|
| **Снимка** | picture, picture for phone (optional), alt, link (optional, = «снимка с линк»), new tab, caption (optional), ratio (оригинал / 1:1 / 4:5 / 3:4 / 16:9 / 21:9), width (цял екран / в контейнера) | «Снимка с линк» is this component with a link, not a separate one (one less thing to learn). |
| **Снимка с текст** | picture, alt, side (снимка вляво / вдясно), split (50/50, 40/60, 60/40), heading, text (plain lines, ≤ 600), button (label + link), vertical align, on phone (снимката първа / текстът първи) | Pure CSS grid; stacks on phone. |
| **Галерия** | `items` 1–24 × {picture, alt, link optional}; layout cards: решетка 2 / 3 / 4 колони · мозайка (първата голяма) · колони с различна височина (masonry, CSS columns) · лента с плъзгане; gap S/M/L; click: нищо / увеличение (lightbox) / връзката | Lightbox = a `<dialog>` + the small existing storefront script; without JS the picture link opens the full-size file. |
| **Херо слайдър** | `items` 1–6 slides × {picture, picture for phone, alt, heading, text, button label, link, link on: бутона / целия слайд, text position (ляво / център / дясно × горе / среда / долу), text colour светъл / тъмен, overlay 0 / S / M / L}; height (S / M / L / цял екран), arrows on/off, dots on/off, autoplay (off by default; when on: 4–10 s, pauses on hover / focus / tab hidden, a visible pause button) | One slide = no script at all. Several: CSS scroll-snap track (works and can be swiped without JS), the script adds arrows, dots, autoplay. `prefers-reduced-motion` → no autoplay, no animated transition. **Speed:** the FIRST slide's picture is eager with `fetchpriority="high"` (it is the LCP), all others `loading="lazy"`; no slide is chosen at random (cache). |
| **Карусел** | `items` 2–20 cards × {picture, alt, heading, text (≤ 160), link}; cards per view: desktop 2–6, phone 1 / 1.2 / 2 (1.2 = the next card peeks); arrows on/off; no autoplay | Same track and script as the hero. A **product carousel** (cards built from the catalog: featured / a category / a tag / a season, fixed N, price from the price resolver) is a separate later component (`product-brand-slider-widget-note.md`); it reuses this track. |
| Text, HTML block, Бутон, Разстояние | as in the header/footer catalogs | already exist as kinds |

Rules for all of them: the slider/carousel script is ONE small module of `storefront.js` shared by both (no library); keyboard: arrows are real `<button>`s with `aria-label`, the track is a labelled region (`aria-roledescription="carousel"`, each slide «2 от 5»); no layout shift (reserved height from the picture ratio); a component with an invalid or missing picture prints nothing rather than a broken frame.

- **Каталог/Категория** (`listing`): layout cards sidebar left / right / none (filters on top); on phone the filters open in a drawer like the menu.
- **Сайдбар**: filter components (brand, category, price range, season, collection, gender, size, colour) and ONE universal **«Филтър по атрибут»** (attribute picker + label, kind checklist/buttons/colour dots/range, value order alpha/count/manual, counts on/off, collapsed). Needs the S7 backend (cached facet counts, plain GET links, `noindex`/`canonical` on combinations). **Checked in the code (a33c798): attributes have no "filterable" flag yet** → a small catalog task F0 (flag + closed value lists confirmed) comes before the sidebar stage.

## 8. Security and speed (unchanged rules, restated)

Escaped output everywhere; HTML only through the sanitizer on save and render; colours only validated hex → CSS variables in the token stylesheet; sizes and spacing only from closed scales → classes; no inline script, no `style=`; links by the existing safe-URL rule; ids and every enum validated on read. Every compiled payload is in the one cached appearance read: zero new queries on home/category/product/cart/checkout (pins unchanged; a test compares a warm page with and without each zone published). Compiled size ≤ 256 KB per key.

## 9. Stages (each useful alone, each one commit)

| Stage | Content | Visible result for the owner |
|---|---|---|
| **DS-1 core** | `FieldKinds`, common groups (CSS classes + scale), `ComponentRegistry`, `ZoneSpec`, `ZoneStore` adapters for header + footer; Header/Footer catalogs and validators delegate to it. Golden tests: compiled header/footer and rendered HTML identical before/after; round-trip test of the adapters. | Nothing changes in the shop or admin (pure extraction; the safety net for everything after). Small-medium. |
| **DS-2 screen** | The «Дизайн» page with zones Топбанер, Хедър, Футър, Количка и поръчка (simplified header); schema, palette, properties, preview, Десктоп/Телефон; common groups incl. **header row colours and text size**. | The new screen works for everything that exists today. Medium-large (may be split DS-2a shell + header, DS-2b footer + topbar + checkout header). |
| **DS-3 cleanup** | Remove old «Хедър»/«Футър» pages (redirects), drop `ReconcilesFormKeys` where unused, drop `pages.in_footer`/`footer_order`, hook table entries. | One place for design. Small. |
| **ML media library** | «Медия»: grid of uploaded pictures, search, where-used, delete unused, and a picker in every `media` field («Избери от медията» / «Качи нова»). | Recommended BEFORE DS-4: every content component is built on pictures. Medium. |
| **DS-4a home, static** | Content zone `home`; the `items` field kind; components Снимка, Снимка с текст, Галерия (without lightbox), Текст, HTML блок, Бутон, Разстояние; the S3-V intro as a component. | Home built from blocks. Medium. |
| **DS-4b sliders** | Херо слайдър + Карусел (shared track + script), gallery lightbox. | Medium. |
| **DS-5 product** | Gallery layouts + product slots. | Product page variants. Medium. |
| **F0 → S7 → DS-6** | Filterable flag on attributes; search/filter backend; then the sidebar zone and `listing` layout cards. | Filters in the catalog. Large, after Phase 1 essentials. |

The media library picker plugs into the `media` field kind whenever it is built; nothing stored changes.

## 10. Owner's answers (2026-10-23)

1. DS-2 is delivered in two parts: DS-2a (shell + header), DS-2b (footer + topbar + checkout header).
2. The old «Хедър»/«Футър» pages stay until DS-3 for side-by-side comparison.
3. The simplified checkout header is: logo + «Към магазина» link + optional phone.

Status: approved by the owner on 2026-10-23; DS-1 prompt issued.

## DS-2b as built (Футър, Топбанер, Количка и поръчка)

The screen has four working zones; each is a `DesignZone` (`app/Filament/Design/Zones`): `HeaderZone` (DS-2a, moved without a change), `FooterZone`, `TopbarZone`, `CheckoutHeaderZone`. The page (`DesignScreen`) keeps the Livewire state (Locked `tree`, `selected`, `device` = the current layout key, `previewUrl`), the permission check of every action and the generic selecting / adding / moving / removing; a zone is stateless and supplies its tree, palette, rules, properties, validator and storage. Two kinds: `tree` (header, footer) and `form` (top banner, checkout header: no tree, ONE properties form; the page keeps its values in `tree['values']`).

- **Футър.** The FT-1 footer as the generic tree (`FooterAdapter`, `locate()` added). Rows and columns are added, removed and moved through `TreeEditor` (`addRow`, `removeRow`, `addCell`, `removeCell`, `moveCell`; limits read from `ZoneSpec::for('footer.upper')`); a row or column that still holds blocks is removed only after the merchant confirmed. Draft / publish / discard are `FooterDrafts`, the code the old page calls. The preview frame is `FooterPreviewController::previewUrl()`, scrolled to `.sf-footer` by a script of the ADMIN page (same origin): the footer's HTML has no id and must not change.
- **Топбанер.** No draft: the banner has always been saved live. «Запази» calls `AppearanceWriter::save()`, the code the «Облик» page saves with (colours, fonts and the banner, one recompile, one cache replacement). Preview: the live home page.
- **Количка и поръчка.** A NEW storefront feature: the simplified header (logo, back link, optional international phone) on the cart, the checkout and the order-received page. Storage = the first use of the generic keys of section 4: `design.checkout_header.global.draft|published` and `design.compiled` (`{hash, zones: {checkout_header: {global: values}}}`, at most 256 KB, in `AppearanceSettings::READ_KEYS`, validated on save, on publish and on every read). Colours and logo heights are CSS variables (`--sf-chdr-*`) written only when set. Preview: `GET /design-preview?zone=checkout_header` (signed, 30 minutes, noindex, no-store).
- The compact density of 6.1 applies to everything new.


## LG-1 as built (the site logo as a picture)

- **Where.** A `media` field `image` in the settings of the header's `logo` element (desktop and phone are two elements, so the picture is chosen on each; the same picture is simply picked again) and `logo_image` in the checkout header's values (`HeaderCatalog::LOGO_IMAGE`, shared). Per element, so draft / preview / publish / «Върни публикуваното» come for free. The logo's own size setting is the existing «Височина» (`height`; the checkout header's `logo_height_d` / `logo_height_m`): no second size was added.
- **Empty = today.** A picture field equal to its default is NOT stored and emits nothing: `HeaderValidator` and `CheckoutHeaderValidator` leave a null media field out, `HeaderCatalog::defaultsFor()` skips it, `HeaderZone::applyProps()` removes it. The golden snapshots of the header and the checkout header did not change (no GOLDEN_WRITE); the logo markup without a picture is byte for byte the old one (the `@if … @else … @endif` sits on the `<img>` lines on purpose: Blade swallows the newline after a directive).
- **Where it is checked.** The validators are pure (the storefront runs them on every read: no query), so «the asset exists, is READY, is an `image`, and its stored `mime` is PNG / WebP» lives in `App\Storefront\Design\LogoImage::problem()`: called by the zones' `validate()` when the Design screen saves (the message is shown on the field at «Приложи», not a 500) and by the compilers, which call `LogoImage::rendition()` (the same checks) and give NO entry for a picture that fails: the logo then falls back to the static file. The picker (`mimeTypes()`), `MediaAssetId` and the uploader enforce the same list; the type is always `catalog_media.mime`, never the file name; a row with no mime yet is refused with the `media:backfill-meta` hint.
- **The rendition.** At compile the smallest WebP size variant (thumbnail 400 / medium 900 / large 1600 px wide; never the 42px admin crop) whose width is at least 2× the width the logo is SHOWN at (its height setting × the picture's proportions; the widest use wins when the desktop and the phone logo differ), else the largest. Example: a 4:1 logo shown 40 px high is 160 px wide, needs 320 → the 400 px size; shown 120 px high it needs 960 → the 1600 px one. `{url, width, height}` (the url through `MediaStorageAdapter`) goes into the compiled payload: `logo_images` (id => rendition) beside `menu_keys` in `header.compiled` (a compiled-only key `HeaderValidator` accepts and checks strictly), and `zones.checkout_header.logo` beside the values in `design.compiled` (not inside them, which are validated again on every read; `published()` hands it to the view only when it is for the picture the values name and is well formed). `HeaderView` puts it into the logo element's settings (`logo_image`), the views print `<img src width height alt="{site name}" decoding="async">`: no `style=`, no `loading="lazy"`, no query. The preview of a draft looks the rendition up at request time (`HeaderCompiler::withMenuKeys()` for the header, `CheckoutHeaderCompiler::withLogo()` in `DesignPreviewController` for the checkout header).
- **Transparency** survives the variants (the pipeline encodes WebP with alpha; tested with a real transparent PNG, the corner pixel of every variant stays fully transparent).
- **Usage and delete.** `App\Media\Usage\DesignZones` reads the header / checkout-header DRAFT and PUBLISHED JSON (the zones' own keys, which are what HeaderAdapter / CheckoutHeaderDrafts store) and now also recognises `settings.image` of a `logo` node and `logo_image`; labels «Дизайн — Хедър, лого» / «Дизайн — Хедър на поръчката, лого», a draft marked «(чернова)», one line per place (the desktop and the phone logo of one header count once). `MediaDeleter` therefore refuses to delete a picture used as a logo, a draft-only use included; replacing the logo leaves the old picture in «Медия» as unused.

## DS-3 as built (one place for design)

- **Removed:** the admin pages «Хедър» (`HeaderSettings`) and «Футър» (`Design\Footer`), their navigation entries, `ReconcilesFormKeys` (only those two used it) and `HeaderDrafts::keepLook()` (it existed for the old Header page). The zone classes, compilers, validators and the stored keys are untouched; the golden snapshots are byte-identical.
- **Redirects.** `/admin/header-settings` and `/admin/footer` answer 302 (`App\Http\Controllers\Admin\DesignRedirectController`, registered with the panel's `authenticatedRoutes()`: a guest gets the login, a staff member without `content_manage` the 403 the old pages gave) to `/admin/design?zone=header|footer`. The screen reads `?zone=` once in `mount()` and takes it only when it is a key of `DesignScreen::ZONES`; anything else is the first zone.
- **A menu the design uses is never deleted.** `App\Content\Menus\DesignMenuUsage` reads the six stored layouts (header, footer, checkout header; published and draft) in ONE query and finds every field of kind `menu` through the zone's component catalog (so an extension's block counts too). `MenuWriter::delete()` refuses with «Менюто „…“ се използва в Дизайн — Хедър / Футър / Хедър на поръчката (чернова)». A menu that holds a location keeps its own rule.
- **Hook table** (`extensibility-coverage-design.md`): `storefront.header.elements`, `storefront.footer.blocks` (added: it was missing) and `media.usages` are the hooks the screen uses; no hook belonged only to the removed pages.
- **DS-3.1 as built.** `pages.in_footer` / `footer_order` and `pages_status_footer_index` are dropped (migration `2026_10_27_000001`, `down()` re-creates the original definitions). `PageReader` no longer selects or orders by them (published pages by title); `MenuSeeder` creates the `footer` menu empty. A page that had `in_footer = 1` stays published and reachable; the merchant places it in the footer with a footer menu block.
