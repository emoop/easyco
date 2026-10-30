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

**CT-2 as built (attribute filter settings, admin + data only).** An attribute (`catalog_attribute_definitions`) has `is_filterable` (default off; only a SELECT or MULTISELECT attribute may be a filter, TEXT / NUMBER / BOOLEAN are refused by `AttributeDefinition::setFilterable()`) and `filter_display` (`list` | `buttons` | `swatches`, enum `FilterDisplay`, default `list`). The attribute form shows the display only while the switch is on; nothing is shown to visitors yet (no filter component, query, cache key or API field).

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

## DS-5 as built (ONE button model for every zone)

**The rule (owner, 2026-10-27, «лекота и леснотийка»): the same buttons, in the same places, in EVERY zone. A new zone gets the toolbar for free and may NOT add a button of its own.** The toolbar is defined once, in `DesignScreen::getHeaderActions()`; a zone only answers questions (`hasUnpublishedChanges()`, `previewNote()`, and `defaultTree()` if it has initial settings).

- **Two versions of every zone:** the WORKING COPY (the draft) and the PUBLISHED version. Every zone has both: the header, the footer and the cart header (`design.*.draft|published`, `header.layout.*`, `footer.layout.*`), the top banner (`banner.draft` beside its published `banner.*` keys and colour overrides) and the two plain sections of GL-2 (`design.page.global.draft`, `design.product.global.draft` beside `design.page.global`, `design.product.global` and the `storefront.gallery.*` keys, which are written only on publish). The storefront, the compiler and `GallerySettings` read ONLY the published storage. A draft equal to the published version is never kept (the plain sections and the banner forget the key), so «no draft» means «nothing to publish».
- **Under the properties panel: «Приложи»** (the panel's only button). It validates the panel, takes it into the working copy and stores the DRAFT; it never publishes. **Toolbar, left to right:** «Към админа» · «Върни публикуваното» (drops the draft) · «Начални настройки» (only a zone with `defaultTree()`: the footer and the two plain sections; it puts the initial values into the working copy, stored as the draft at once, after a confirmation) · **«Публикувай»**, last, red (`danger`). No «Запази», no toolbar «Приложи».
- **«Публикувай»** is disabled with nothing to publish (no draft, or the draft equals the published version) and while the panel holds edits «Приложи» has not taken (`panelDirty`, set by the browser the moment a field is touched; the tooltip says «Първо натиснете „Приложи“»); the server refuses the call too.
- **The state indicator** (a small text, drawn by a render hook right after the toolbar): «Публикувано», «Чернова — не е публикувана» or «Има неприложени промени в панела».
- **Autosave.** Every structural change in a tree zone (add / move / remove a component, a row, a column) and every «Начални настройки» stores the draft at once, when the whole working copy validates (`DesignScreen::autosave()`); a copy that does not validate yet stays on the screen (`dirty`) until «Приложи» takes it. The panel's un-applied edits are never stored. Leaving the screen never loses applied work.
- **Preview.** The tree zones and the cart header preview their draft (signed URLs, as before); the top banner now previews its draft too (`/design-preview?zone=topbar`, the home page with the draft banner and colours for that one request). The two plain sections preview the PUBLISHED page (their values are CSS variables and gallery HTML; a preview request must not make the storefront read a draft): the panel says so under the preview.


## SC-1 as built («Дясна колона»: the right column of the product page as a list of components)

The right column (`section.sf-summary` of `storefront/product.blade.php`) is no longer a fixed order: it is the PUBLISHED layout of the zone `product_summary` («Дясна колона», key `summary` on the screen), an ordered list of components. With no layout stored, the default layout prints and the page is **byte-identical** to the one the shop printed before (four fixtures `tests/Fixtures/storefront/product-summary-default-{1..4}.html`, captured from the unchanged code, are compared exactly by `ProductSummaryBaselineTest`).

- **Components** (`App\Storefront\ProductSummary\ProductSummaryCatalog`, a closed list, no hook): `brand`, `title` (locked), `sku`, `short_description`, `price`, `stock`, `purchase` (locked), `html_block` (an id of an existing HTML block, 0–5). Each is a partial `storefront/partials/summary/{type}.blade.php` holding TODAY's markup. `title` and `purchase`: exactly one each, cannot be removed or duplicated, can be moved. At most 15 components. **Default layout** (also «Начални настройки»): brand, title, short_description, price, stock, purchase (SKU is not in it).
- **Stored shape:** `{components: [{type, settings}]}`. Validated by `ProductSummaryValidator` (FieldKinds for the settings) on «Приложи», when compiled and again when the storefront reads it: an unknown type, a second `title` / `purchase`, a missing one, more than 5 HTML blocks, more than 15 components or a setting out of range is refused (a layout that does not validate prints the DEFAULT layout; the page never breaks).
- **Storage (no migration).** `design.product_summary.global.draft` and `.published` (settings table) and the compiled copy in the shared document `design.compiled`: `zones.product_summary.global = {layout, blocks}` (`blocks` = the sanitised HTML of every HTML block the layout shows, so printing costs no query; the HTML is cleaned AGAIN when printed). A layout equal to the default is NOT stored (the keys and the zone's entry are forgotten, and `design.compiled` too when it is left empty). `ProductSummaryCompiler::refresh()` rebuilds the blocks' HTML from the published layout; the menu rebuild does not call it yet (an edited HTML block shows in the column after the next «Публикувай»).
- **Storefront read.** `StorefrontAppearance::productSummary()` reads the compiled published layout from the one cached payload (no query), never the draft; `ProductController` passes it to the view as `summary`. The product view loops over it; the directives are at the left edge on purpose and each partial starts with a marker character that the loop removes: Laravel trims the leading whitespace of an included view's output, and the default page must stay byte-identical.
- **The editor** is the generic tree zone with ONE row of ONE cell (`HasOneCell`, `ProductSummaryZone`, `ProductSummaryAdapter`); the cell is selected from the start, the schema is a flat list (`schema-summary.blade.php`) with ↑ ↓ ×. The palette offers only the components that can still be added. The same DS-5 toolbar and autosave as every zone; the zone only says it has initial settings (`defaultTree()`).
- **Preview:** `/design-preview?zone=product_summary` (signed, 30 minutes, `noindex`, `no-store`): the product page of the first visible product with the draft layout injected for ONE request (`StorefrontAppearance::previewProductSummary`); an empty shop has no product and the panel says so.
- **Brand.** The brand line stays plain text: the storefront has no page that lists the products of a brand (no route for it), so there is nothing to link to yet. When the brand page exists (the catalog / category design), `partials/summary/brand.blade.php` gets the link (`sf-product-brand-link`).
- **SKU.** `ProductPage::$sku` is the product-level SKU (`catalog_products.base_sku`; not in `toArray()`, the JSON contract is unchanged); an empty one prints nothing. The per-variation SKU arrives with PB-1. The label «Арт. №:» is `design.summary.sku_label` (the storefront lang files are outside SC-1's allowed paths). CSS: `.sf-sku`, `.sf-summary-html img` in `storefront.css`; `.ds-chips-list` in `design-screen.css`.
- **Part A.** The «Облик» page forgets `banner.draft` when it saves (`BannerDrafts::forget('superseded_by_appearance')`), so a stale top-banner draft never overwrites a live banner on «Публикувай».

## CD-1a as built («Каталог»: the columns and the page size of the product listing)

A third plain section of the Design screen (zone key `catalog`, `App\Filament\Design\Zones\CatalogZone` on `PageDesignZone`, section `PageDesign::CATALOG`), stored exactly like `page` and `product` (GL-2 / DS-5): one JSON setting of what is set, a draft beside it, «Приложи» → draft, «Публикувай» → published (draft forgotten), «Начални настройки» → the defaults into the working copy, «Върни публикуваното». **Decisions (owner, 2026-10-30): no filters, no side panel and no layout control in this stage — the «Разположение» and «Страничен панел» controls come with the storefront filters (CT-3).**

- **Controls (closed lists, strings):** `cols` 3 | 4 | 5 (default 4), `cols_phone` 1 | 2 (default 2), `per_page` 12 | 20 | 24 | 36 | 48 | 60 | 72 (default 24; 20 added in CD-1a.2: with 5 columns it is exactly 4 full rows). The defaults are TODAY's values (`lg:grid-cols-4`, `grid-cols-2`, `PerPage::TWENTY_FOUR`). The tablet is automatic: min(desktop, 3) is 3 for every allowed value (today's `md:grid-cols-3`), so it needs no variable.
- **Keys:** `design.catalog.global` (published JSON, only while something differs; the key is forgotten at the defaults) and `design.catalog.global.draft`. The published JSON is read with the rest of the appearance (`AppearanceSettings::READ_KEYS`, payload key `catalog` of the ONE cached `StorefrontAppearance` read, validated again by `PageDesign::catalogValues`): no extra query. (Deviation from the prompt's «compiled into `design.compiled`»: the section follows the `page` / `product` pattern, which the prompt named; the structure is not a layout, so no compiled document is needed.)
- **CSS variables** (closed list, re-checked by `PageDesign::isValidVar` in the token compiler): `--sf-cat-cols` (3 | 4 | 5) and `--sf-cat-cols-phone` (1 | 2), written ONLY when they differ from the defaults; `per_page` is never a variable. `storefront.css` reads them as `.sf-category .sf-grid { grid-template-columns: repeat(var(--sf-cat-cols-phone, 2), …) }`, 3 columns from 768px and `repeat(var(--sf-cat-cols, 4), …)` from 1024px (the same breakpoints as before); the home page's grid (`.sf-home`) is not touched.
- **Per page:** `StorefrontAppearance::catalogPerPage()` is the default of `ListingRequest::query($request, $categoryId, $defaultPerPage)`: without `?per_page=` the published size decides; a `?per_page=` in the URL is validated as ever (`PerPage` gained 20, 36, 60 and 72; anything outside the closed list stays the storefront's 404). `PaginationLinks` leaves out a `per_page` equal to the published default and keeps any other. The canonical link is as before (`?page=N` only).
- **Numbered pages:** the markup of `partials/pagination.blade.php` is kept; `pages` entries are now numbers or `{ellipsis: true}`: up to 7 pages all numbers; more: the first, the last and the current ± 2, a gap of ONE page is filled with its number, a longer one is an `<li class="sf-pagination-ellipsis">…</li>` (plain text). The first page has no `?page=`. (Replaces the sliding window of seven numbers; `SeoMetaTest` expectation updated.)
- **One template:** today there is ONE listing page (the category: `CategoryController`, `storefront.category`, `CatalogReader::listing()`); no route was added for tags, seasons, brands, search or «all products». The view has a clearly named EMPTY slot, `@yield('listing_filters')`, between the sub-categories and the grid: it prints nothing today (byte-identical HTML with the defaults, checked against HEAD) and is where CT-3's filters go.
- **The schematic** (`partials/schema-catalog.blade.php`, admin only, inline styles allowed there and holding only closed-set digits): N boxes in two rows for the desktop and for the phone, and the page size, from the validated working copy.
- **Not changed:** the home page, the product page, the cart, the checkout, the reader's query, `ListingQuery`'s validation, the sessionless pipeline.


## CD-1a.1 as built (the preview of «Каталог» and «Страница и карти» shows the APPLIED draft)

Defect (owner, 2026-10-30): after «Приложи» the preview frame of «Каталог» still showed the published look until «Публикувай». Cause: both zones framed the PUBLISHED page (`previewUrl()` was the plain home / category url and the zone note said «Прегледът показва публикуваното»), because their values are CSS variables and per-page defaults read from published storage. Fix, with the mechanism the other zones use (a signed `/design-preview`):

- **Zones `catalog` and `page`** are two more `zone=` values of `DesignPreviewController` (`storefront.design.preview`, a temporary SIGNED url, 30 minutes; a missing / forged / expired / swapped signature is the storefront's own 404, `noindex`, `Cache-Control: private, no-store`, no cookie). `CatalogZone::previewUrl()` / `PageCardsZone::previewUrl()` return `DesignPreviewController::previewUrl(zone)`; the signature changes with every call, so the frame loads again after «Приложи». «Продуктова страница» is NOT changed (its gallery choices are HTML attributes read from other keys; it keeps the published preview and its note).
- **What the controller does:** reads the working copy (`PageDesignStore::draft()`, else the published values), builds a small preview stylesheet with `PageDesign::previewVars($section, $draft, $published)` — what the draft writes, plus an EXPLICIT default for every variable the PUBLISHED values write and the draft does not (the published token stylesheet is still linked, so without it the published 5 columns would win over a draft back at the defaults) — written as `appearance/design-preview-{hash}.css` (the existing preview-stylesheet store, pruned after an hour) and linked after the token stylesheet through `StorefrontAppearance::previewStylesheet()` / `previewCatalog()`. «Каталог» renders the first shown category through `CategoryController::show()` (the home page when there is none) with the draft's per-page size as the default for this one request (`catalogPerPage()` reads the override); «Страница и карти» renders the home page.
- **The live shop never reads a draft:** the override fields exist on the request-scoped `StorefrontAppearance` and are set only from the signed route; a normal request reads the published `design.catalog.global` from the cached payload as before; the draft CSS is only in the preview file and never in the public token stylesheet or the public cache. No query parameter of a visitor selects a draft (`?preview=`, `?draft=`, `?zone=`, `?ds=`, a forged signature: tested).
- **Known edge:** for «Страница и карти» a draft that goes back to the default `card_radius` (8px) over a published one shows 8px, while the live default follows «Облик»'s corner radius until a value is set here.
- **Text:** the preview note of both zones is «Прегледът показва приложеното; в магазина влиза след „Публикувай“.» (`design.preview.applied_note`). The schematic of «Каталог» keeps showing the applied working copy.
