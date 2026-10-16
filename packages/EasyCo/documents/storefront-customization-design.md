# Storefront customization: appearance, top banner, header builder, menus, design slots, themes

**Status:** design, accepted by the owner 2026-10-15 (§11). Build stages D1, D2, D3 are specified for building; D4 and D5 are a frame
that gets its own short spec before it is built. **Date:** 2026-10-15.
**Builds on:** `storefront-design.md` (cookie-free storefront, read layer, S4 caching, S11 home blocks — superseded here by D5),
`pages-design.md` (`DescriptionSanitizer` for merchant HTML, footer flag — superseded by D3 menus), `site-settings-design.md`
(`SiteSettingsRepository`, `getMany`), `extensibility-design-and-hooks.md` (`Hook::filter`), `media-domain-design.md` (assets),
`staff-access-domain-design.md` (permissions).
**References studied:** WoodMart's header builder (rows × columns of elements, separate desktop and mobile layouts, a catalog of
elements: logo, menus, search, account, cart, wishlist, text/HTML, HTML block, button, social, divider/space) and the owner's shop
raf.bg (promo top bar, mega menu left, account/wishlist/cart/search right, category banners, legal footer column).

---

## 0. Decisions

| # | Decision |
|---|---|
| C-D1 | **No PHP from the admin, ever.** Merchant-entered content is plain text, validated values, or HTML cleaned by `DescriptionSanitizer`. Anything that needs code (a PHP function, a widget with logic) is an **extension**: files deployed to the server, registering on the same element catalogs and slots through `Hook::filter`. A stolen staff password must never become code execution on the server. (owner: yes) |
| C-D2 | **Themes are files, not uploads.** A theme is a folder with a manifest and Blade views; Blade is PHP, so a theme is installed like an extension (deployed, reviewed), never uploaded through the admin. A theme only DISPLAYS: it receives the read layer's read models and the settings described here; it never queries or writes. |
| C-D3 | **The current storefront becomes the default theme `easyco` now** (a manifest next to the existing views, no file moves). Switching themes from the admin and a published theme standard for outside authors are **version 2**; the mechanism that resolves "active theme → default theme" is built in D1 so the door stays open. |
| C-D4 | **Header builder without a drawing canvas or drag-and-drop for now:** an admin page with, per layout (desktop, mobile) and per row, three ordered lists (left, centre, right) of elements from a catalog. The owner tries it and decides later whether a visual canvas is needed. (owner: try without) |
| C-D5 | **No random products anywhere in the header or menus** (it breaks the page cache). The mega menu's picture is a **static image with an optional link**. (owner decision) |
| C-D6 | **Cart and checkout get no slots and no builder** — the customer stays focused. They do follow the appearance tokens (colours, fonts, radius). |
| C-D7 | Order: **D1 (appearance + top banner + theme mechanism) → D2 (header builder) → D3 (menus + mega menu)** → D4 (slots) → D5 (home sections + product page slots). (owner: yes) |
| C-D8 | Everything a builder produces is **compiled on save** into one stored value and served from the cache: the page pays no extra work per request for being configurable. |

---

## 1. Goals and non-goals

**Goals:** a merchant changes the look (colours, fonts, corners), the top banner, the header and the menus without a developer, safely,
with a preview; the shop stays as fast as today; a developer can replace any part through a theme or an extension without forking.

**Non-goals (named so nobody assumes them):** a page builder with drag-and-drop on a canvas; per-page different headers (V2, WoodMart
has it); theme upload/marketplace (V2); editing CSS in the admin (V2 at the earliest, and then only as a sanitized CSS subset);
custom fonts uploaded by the merchant (V2); any code typed into the admin (never, C-D1).

---

## 2. Themes (D1 builds the mechanism; V2 builds switching)

### 2.1 The manifest
`resources/themes/{key}/theme.json`, validated on read (unknown keys refused, sizes bounded):

```json
{
  "key": "easyco",
  "name": "EasyCo",
  "version": "1.0.0",
  "extends": null,
  "views": null,
  "assets": { "css": "resources/css/storefront.css", "js": "resources/js/storefront.js" },
  "tokens": { "...": "the token list and defaults of §3.1" },
  "header": { "rows": ["top", "main", "bottom"], "elements": ["logo", "menu", "menu_toggle", "search", "account", "wishlist", "cart", "text", "html", "button", "social", "spacer"] },
  "slots": []
}
```

- `views: null` = the theme uses `resources/views` as it is (the default theme). Another theme names its folder
  (`resources/themes/{key}/views`) and **overrides by file name**: `storefront/product.blade.php` in the theme replaces the default one,
  anything it does not have falls back to the default theme. `extends` names the parent (V1: only `easyco`).
- `slots` (D4) lists the slot names the theme's views print, so the admin can list them per page.

### 2.2 Resolution
- `App\Storefront\Theme\ThemeRegistry`: reads and validates manifests, memoised; `active()` = setting `storefront.theme` (absent =
  `easyco`; V1 offers no UI to change it and refuses an unknown key by falling back to `easyco` and logging once).
- Middleware `ApplyStorefrontTheme` in the storefront route group ONLY (never admin, mail or API): when the active theme has its own
  `views` folder, it is **prepended** to the view finder's paths for that request. The default theme prepends nothing → zero cost today.
- Mail templates and the admin are not themed.

### 2.3 What a theme may rely on (the "standard", written for V2, kept true from now)
The view names of the storefront and, per view, the variables listed in the controllers' docblocks (`storefront-design.md` §2.7
"VIEW receives EXACTLY"); the read models' `toArray()` shapes; the appearance tokens as CSS variables (§3.2); the header/menu
compiled structures (§4.4, §5.3); the slot helper (§6). A change to any of these is a **breaking change** and is listed in the docs.

---

## 3. Appearance tokens and the top banner (D1)

### 3.1 Tokens (admin page «Облик», permission `content_manage`)

| Token | Setting key | Default | Validation |
|---|---|---|---|
| Page background | `appearance.color.background` | `#fafafa` | `#RRGGBB` only |
| Text | `appearance.color.text` | `#171717` | idem |
| Muted text | `appearance.color.muted` | `#525252` | idem |
| Primary (buttons, links) | `appearance.color.primary` | `#171717` | idem |
| Text on primary | `appearance.color.on_primary` | `#ffffff` | idem |
| Accent (sale price, badges) | `appearance.color.accent` | `#b91c1c` | idem |
| Borders | `appearance.color.border` | `#e5e5e5` | idem |
| Header background / text | `appearance.color.header_bg` / `header_text` | `#ffffff` / `#171717` | idem |
| Footer background / text | `appearance.color.footer_bg` / `footer_text` | `#ffffff` / `#404040` | idem |
| Font (body) | `appearance.font.body` | `system` | closed list: `system`, `inter`, `manrope`, `lora` |
| Font (headings) | `appearance.font.heading` | `same` | closed list + `same` |
| Corner radius | `appearance.radius` | `md` | `none` (0), `sm` (4px), `md` (8px), `lg` (12px), `pill` (buttons only) |
| Button style | `appearance.button` | `solid` | `solid`, `outline` |

- **Only hex colours** pass validation: nothing typed can become CSS (no `url()`, no `;`, no `}`) — this is the CSS-injection guard.
- The admin shows a **contrast warning** (WCAG AA 4.5:1) for text/background, on_primary/primary, header_text/header_bg,
  footer_text/footer_bg and banner text/background. A warning, not a refusal: the merchant decides.
- **Fonts are bundled with the shop** (woff2 files in the build, `font-display: swap`), never loaded from Google or another host: no
  third-party request, no visitor IP sent abroad (privacy), no extra DNS lookup. Only the chosen families are linked on the page.
- Defaults reproduce today's look exactly, so D1 changes nothing visible until the merchant changes something.

### 3.2 How tokens reach the page
- On save, `AppearanceCompiler` writes **one stylesheet** `storage/app/public/appearance/tokens-{hash}.css` (`:root { --sf-bg: …;
  --sf-text: …; --sf-radius: …; … }` plus the `@font-face` rules of the chosen fonts) and stores the hash in `appearance.compiled`
  (JSON: `{hash, fonts: [...]}`). Old files are removed after a successful write (keep the previous one, for pages cached a moment earlier).
- The layout links `/storage/appearance/tokens-{hash}.css` (needs the `public/storage` link, already a deployment step for media) (long-lived, `immutable`: the name changes when the content does). No inline
  `<style>`: keeps the page ready for the CSP header (roadmap).
- `storefront.css` is rewritten to use the variables (`var(--sf-primary)` …) with today's values as fallbacks; Tailwind utility classes
  that hard-code colours in the storefront components are replaced by the variables. Cart and checkout included (C-D6).
- Missing file or empty `appearance.compiled` → no link, the fallbacks inside `storefront.css` apply (the shop never breaks on a bad save).

### 3.3 The top banner (same admin page, own section)
| Field | Key | Rule |
|---|---|---|
| Show | `banner.enabled` | bool, default off |
| Text | `banner.text` | `PlainText`, 1–160 chars |
| Link | `banner.url` | optional; root-relative path or `https://` (the pages link rule) |
| Link text | `banner.link_label` | optional `PlainText` ≤ 40; when empty and a link exists the whole bar is the link |
| Text colour / background | `banner.color.text` / `banner.color.bg` | hex, defaults `#ffffff` / `#171717` |
| From / until | `banner.starts_at` / `banner.ends_at` | optional dates; `ends_at > starts_at` |

- Rendered above the header (outside the builder rows), escaped, a single line that truncates with an ellipsis on a phone (full text
  in the link's accessible name). No close button in V1 (asked later if wanted; it would use `localStorage`, never a cookie).
- **Dates and the page cache (S4):** the banner's visibility at a boundary is the S4 "scheduled boundary job" case: until S4 the check
  runs per request (it is a comparison, no query); with S4 the boundary job purges at `starts_at` and `ends_at`.

### 3.4 One read per request
`StorefrontAppearance` (the one reader) loads `appearance.compiled`, the banner keys and — from D2 — `header.compiled` with ONE
`getMany`, memoised, and caches the result in the application cache under a version key bumped on every save (warm pages: zero
queries for appearance). Pinned page query numbers move by at most **+1** (cold cache), once, documented in the stage report.

---

## 4. Header builder (D2)

### 4.1 Model
```
layout = {
  desktop: { sticky: bool, rows: { top: Row, main: Row, bottom: Row } },
  mobile:  { sticky: bool, rows: { top: Row, main: Row }, drawer: [Element] }
}
Row     = { enabled: bool, left: [Element], center: [Element], right: [Element] }
Element = { type: string, settings: object }
```
- Stored as JSON: `header.layout.draft` (what the admin edits) and `header.layout.published` (what the shop shows). On publish the
  server validates, then compiles (§4.4).
- Limits: ≤ 6 elements per slot; `logo` at most once per layout; the mobile `drawer` is what the hamburger opens (menus, account,
  text links).

### 4.2 The element catalog (V1)

| Type | Settings | Notes |
|---|---|---|
| `logo` | height px (24–80, desktop/mobile separately) | the shop logo (existing file) |
| `menu` | menu (D3; until D3 only `categories` = today's category tree), style `links` / `dropdown` / `mega` | desktop horizontal; in the drawer as an accordion |
| `menu_toggle` | menu, icon `hamburger` (three thin lines, owner) | opens the drawer; on desktop it may sit right (owner: "menu on the right too") |
| `search` | mode `icon_row` (icon → a full-width row below the header, modal-like: focus moves in, `Esc` closes) / `field` (inline, used in the mobile drawer) | **rendered only when the search route exists (S7)**; until then the element is accepted and hidden |
| `account` | style `icon` / `icon_label` | rendered only when the customer account routes exist |
| `wishlist` | style, show count | rendered only when wishlist exists |
| `cart` | style `icon` / `icon_label`; count badge | the count filled by the script after load (cache-safe, today's R1 rule) |
| `text` | text (`PlainText` ≤ 120), optional link | e.g. a phone number in the top row |
| `html` | HTML (≤ 4 000 bytes) | cleaned by `DescriptionSanitizer` on save and on render |
| `button` | label (≤ 30), link, style `primary` / `outline` | |
| `social` | list of {network from a closed list, https URL} | inline SVG icons from a closed set |
| `spacer` | width `sm` / `md` / `lg` | |

- **Extensions add element types** through `Hook::filter('storefront.header.elements', $catalog)`: a descriptor
  `{type, label, settings schema, view}`. The admin form and the validator are built from the catalog, so an extension's element is
  edited and validated like a built-in one. A theme may override any element's view (`storefront/header/elements/{type}.blade.php`).
- Elements whose feature does not exist yet (search, account, wishlist) are **accepted and stored** so the merchant can set the final
  header now, and appear by themselves when the feature lands.

### 4.3 The admin page «Хедър» (permission `content_manage`)
- Tabs **Десктоп** / **Телефон**; per row a section with an enable toggle and three Filament `Builder` fields (left / centre / right):
  each block is an element type with its own settings form, ordered with the builder's up/down controls (no canvas, C-D4).
- Buttons: **Запази чернова**, **Преглед** (a signed, short-lived URL that renders the home page with the DRAFT header; `noindex`;
  no other visitor ever sees a draft), **Публикувай**, **Върни публикуваното** (discard the draft).
- A «Помощ: как работи →» link to a new help topic `appearance` (D1 creates the topic; D2 adds its header section).

### 4.4 Rendering and speed
- On publish, `HeaderCompiler` validates the layout against the catalog and stores `header.compiled` = the validated layout plus its
  hash. At render, the layout includes `storefront/header.blade.php`, which walks the compiled rows and includes each element's view;
  the rendered header HTML is **cached** by `hash + locale` (it contains no per-visitor data: the cart count and, later, the account
  state are filled by the script).
- Desktop and mobile markup are both in the page; CSS decides which shows at the breakpoint (no user-agent sniffing, cache-safe).
- `sticky` = CSS `position: sticky`; no scroll script in V1.
- The menus' data comes from the existing category tree (D2) or the compiled menus (D3) — no query added by the builder itself.

### 4.5 The default layout (seeded, the owner's vision)
- Desktop main row: left `menu` (categories, mega style once D3 exists), centre `logo`, right `search` (icon_row) · `account` · `cart`.
- Mobile main row: left `menu_toggle` (hamburger), centre `logo`, right `search` · `cart`; drawer: `menu` (categories) · `account` link.
- Top row off; the top banner (§3.3) is separate and off until the merchant fills it.

### 4.6 Accessibility (tested)
Landmarks (`header`, `nav` with labels), the drawer and the search row trap focus while open and return it on close, `Esc` closes,
`aria-expanded` on every toggle, 44px touch targets, visible `:focus-visible`, `prefers-reduced-motion` respected.

---

## 5. Menus and the mega menu (D3)

### 5.1 Tables
```php
Schema::create('menus', function (Blueprint $t) {
    $t->id(); $t->string('key', 40)->unique(); $t->string('name', 100); $t->timestamps();
});
Schema::create('menu_items', function (Blueprint $t) {
    $t->id();
    $t->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
    $t->foreignId('parent_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
    $t->unsignedSmallInteger('sort')->default(0);
    $t->string('type', 16);                 // link | category | page | column | image
    $t->string('label', 120)->nullable();   // plain text; category/page items default to the target's name
    $t->string('url', 500)->nullable();     // link/image: root-relative or https only
    $t->unsignedBigInteger('target_id')->nullable(); // category id / page id
    $t->boolean('with_children')->default(false);    // category: list its sub-categories automatically
    $t->unsignedBigInteger('media_asset_id')->nullable(); // image items: a ready image asset
    $t->string('image_alt', 200)->nullable();
    $t->boolean('new_tab')->default(false);
    $t->boolean('mega')->default(false);    // top-level item: opens a mega panel of its columns
    $t->boolean('visible')->default(true);
    $t->timestamps();
});
```
- Depth ≤ 3; `column` only under a `mega` item; `image` only inside a column; CHECK on `type`. A category or page item whose target is
  hidden/unpublished is skipped at render (never a dead link).
- Seeded menus: `main` (from today's top-level categories), `footer` (from the pages flagged `in_footer` — the flag is then retired by a
  migration that copies it; the footer renders the `footer` menu).

### 5.2 Admin «Менюта»
A list of menus; per menu a nested, ordered tree editor (Filament tree/repeater with up/down and indent/outdent; no canvas). The image
item uses the existing media picker. Permission `content_manage`. Help section in the `appearance` topic.

### 5.3 Rendering
`MenuCompiler` on save builds each menu's tree (labels resolved, hidden targets dropped, image renditions resolved) into
`menu.compiled.{key}`; the storefront reads compiled menus with the appearance read (§3.4) and caches the rendered HTML like the
header. The mega panel: a full-width panel under the header with the item's columns (links) and its static image(s) with an optional
link (C-D5); opens on hover with a short intent delay and on click/Enter, closes on `Esc` and on leaving; on a phone the same tree is
an accordion in the drawer.
**Invalidation:** saving a menu, publishing/unpublishing a page, renaming/hiding a category recompiles the affected menus (listeners on
the existing events; with S4 they also purge the page cache).

---

## 6. Design slots (D4 — frame, short spec before building)

- A slot is a **named place** in a view: `@designslot('product.below_price')` (not `@slot`, which is Blade's own component directive), with the page group and a human label in the theme manifest
  (`slots: [{name, page, label}]`). The registry is the union of the active theme's slots (and later extensions').
- Admin «Места в дизайна»: a searchable select of all slots grouped by page (Начало, Категория, Продукт, Футър, Акаунт, Любими); pick
  one, then add ordered blocks below it: **HTML block** (sanitized, ≤ 8 000 bytes), a **catalog block** (text, image with link, button,
  product row — D5's catalog), or an **extension block** registered via `Hook::filter('storefront.slot.blocks', …)`. Optional conditions
  (V1: product in category X / category page X). No slots on cart and checkout (C-D6).
- Compiled on save, cached like the header; a slot with no blocks prints nothing and costs nothing.

## 7. Home sections and product page slots (D5 — frame; supersedes S11)

- **Home** becomes an ordered list of sections from a catalog: hero (1–6 slides, the S11 slide fields), banner grid (S11 layouts),
  category tiles, product row (by category / tag / season, newest or featured, N items — a fixed choice, no randomness), text, HTML
  block, the editable intro (S3-V) as a section. Admin: one ordered `Builder` list, draft/preview/publish like the header.
- **Product page:** the core (gallery, title, price, variation choice, add to cart) stays fixed; slots around it
  (`product.below_title`, `product.below_price`, `product.after_add_to_cart`, `product.after_description`, `product.after_related`).
- Account dashboard and wishlist pages get their slots when those features are built.

---

## 8. Security summary
- No code from the admin (C-D1); themes and extensions are deployed files (C-D2).
- Every text field: length limit + `PlainText`; every colour: hex regex; every URL: root-relative or `https://` (the pages rule);
  every HTML field: `DescriptionSanitizer` on save AND on render; every list: bounded counts; layouts validated against the catalog on
  save and again when compiled (a hand-edited database row cannot inject an unknown element or setting).
- Previews: signed, short-lived, `noindex`, only the draft of the screen being edited.
- Permission `content_manage` (Administrator, Manager) for «Облик», «Хедър», «Менюта», «Места в дизайна»; every save and publish
  written to the activity log (who, what, never the HTML body).

## 9. Speed summary
- Defaults change nothing (D1 reproduces today's look).
- One memoised settings read per request for the whole appearance (+1 query cold, 0 warm); compiled structures; rendered header and
  menus cached by content hash; one small token stylesheet, immutable; fonts local, only the chosen ones linked, `swap`.
- No builder JavaScript on the storefront. The storefront script grows only by the drawer / search row / mega panel behaviour
  (target ≤ 4 KB gzipped for D2+D3 together).

---

## 10. Stages

### D1 — appearance, top banner, theme mechanism (one commit)
`ThemeRegistry` + manifest validation + `resources/themes/easyco/theme.json` + `ApplyStorefrontTheme` (prepends nothing for the
default) · setting keys of §3.1/§3.3 · admin page «Облик» (sections: Цветове, Шрифтове и форми, Горен банер) with contrast warnings ·
`AppearanceCompiler` + token stylesheet + font files (4 families, woff2, latin + cyrillic subsets) · `storefront.css` on CSS variables
(cart and checkout included) · banner partial · `StorefrontAppearance` reader (one `getMany`, cached) · help topic `appearance` (bg/en)
· docs (`storefront-design.md`, `site-settings-design.md`, this file "as built").
**Tests (★ mutation-checked):** an invalid colour (`red`, `#fff;}`, `url(x)`) refused ★; the compiled stylesheet contains exactly the
saved values and nothing else; defaults render today's markup and a page with no compiled file still renders ★; banner: off → absent,
on → escaped text and a validated link ★, `javascript:` refused, dates respected; one settings query for all appearance keys (pinned) ★;
no inline `<style>`/`<script>` added; the theme registry falls back to `easyco` for an unknown key and never touches the admin/mail view
paths ★; permission gate; contrast warning shown for a low-contrast pair.

### D2 — header builder (one commit)
Element catalog + `Hook::filter('storefront.header.elements')` · layout JSON (draft/published) + validator + `HeaderCompiler` + cache ·
`storefront/header.blade.php` and one view per element · drawer + search row behaviour (accessible) · admin «Хедър» with preview and
publish · default layout seeded (§4.5) · the S3-V2 hamburger becomes the `menu_toggle` element · help section · docs.
**Tests (★):** unknown element type / setting refused ★; limits (6 per slot, one logo) ★; draft never visible publicly, preview only with
a valid signature ★; publish compiles and the shop shows the new header; elements of missing features stored but not rendered ★; the
`html` element sanitized on save and render ★; an extension's element type validated and rendered through the hook; header HTML cached
by hash and contains no per-visitor data; focus trap/`Esc`/`aria-expanded` in the fake DOM; pinned page queries unchanged except D1's +1.

### D3 — menus and mega menu (one commit)
Tables + CHECKs · admin «Менюта» (tree editor, media picker) · `MenuCompiler` + invalidation listeners · `menu` element reading compiled
menus · mega panel + drawer accordion · seeded `main` and `footer` menus · footer flag migrated and retired · help · docs.
**Tests (★):** depth/type rules ★; hidden category / unpublished page skipped ★; url rule ★; mega only on top-level items; image asset
must be a ready image; compiled menu recomputed on page publish and category hide ★; footer renders the `footer` menu and the old flag's
pages are in it after the migration; query count of a storefront page unchanged by menus (compiled read) ★.

### D4, D5 — each gets a one-page spec (slot names, block catalog, admin flow) written when D3 is done, then built.

---

## 11. The owner's answers (2026-10-15)
1. No PHP from the admin; code only through extensions → **yes** (C-D1).
2. Header builder without canvas and drag-and-drop for now; the owner decides later → **yes** (C-D4).
3. No random product in the mega menu: a static image with an optional link → **decided** (C-D5).
4. Order D1 + D2, then menus → **yes** (C-D7).

### Small open points (defaults used unless the owner says otherwise)
- Top banner close button: **not in V1**.
- Fonts offered: **System, Inter, Manrope, Lora** (Lora as a serif for headings). The owner may name others.
- Account and wishlist icons appear when those features exist (the customer account stage).

---

## 12. D1 as built (2026-10)

Built as specified in §2, §3 and §10 "D1", with these decisions: (1) **only values that differ from their default are stored and compiled**, so the stylesheet link exists only after the merchant changed something and the untouched shop renders exactly as before; (2) the footer text default is **`#737373`** (today's colour) instead of `#404040`; (3) `storefront.theme` is read in the SAME `getMany` as the appearance (no query of its own); (4) the sale price takes the accent only when the merchant set one; (5) white card surfaces, link blues, error colours and the focus ring stay fixed (no token for them in §3.1); (6) the banner colours are tokens in the compiled stylesheet (`--sf-banner-*`), never a style attribute; (7) the link rule is `CheckoutFormSettings::isSafeLink` plus https only. Details: `storefront-design.md` §2.13. Idea for later, not built: «Витрина» (the home heading and text) could become a section of the «Облик» page.

---

## 13. D2 as built (2026-10)

The header builder, built as specified in §4 and §10 "D2". Decisions and facts:

- **Model.** `App\Storefront\Header\HeaderLayout`: `desktop` and `mobile`, each with `sticky`, three rows (`top`, `main`, `bottom`; each `enabled`, `height`, and three slots `left` / `center` / `right` of at most 6 elements) and, on mobile, a `drawer` (at most 8 elements, only menu / account / wishlist / search / text / html / button / social / spacer) and `drawer_side`. Elements are `{type, settings}`; exactly one `logo` and at most one `menu_toggle` per layout.
- **Catalog.** `HeaderCatalog` holds the twelve built-in types (logo, menu, menu_toggle, search, account, wishlist, cart, text, html, button, social, spacer), each with a closed settings schema (int / enum / text / url / bool / html / social). The filter `storefront.header.elements` (see the Hook Reference) lets an extension ADD a type; a filter that replaces or removes a built-in is ignored and logged once.
- **Validation in three places.** `HeaderValidator` runs on save (admin), when compiling (`HeaderCompiler::publish`) and on every read of the cached payload (`StorefrontAppearance::header()`); anything unknown (type, setting, key) is refused, and a payload that fails on read renders the seeded default. Links: root-relative or `https://`; social: `https://` only, closed network list; `html` goes through `DescriptionSanitizer`; text through `PlainText`.
- **Heights are CSS variables** (`--sf-hdr-{d|m}-{top|main|bottom}-h`, `--sf-logo-{d|m}-h`) written into the D1 token stylesheet only when they differ from the default (every `var()` carries the default as its fallback). No `style=` anywhere. An appearance (colour) save keeps the published sizes (`AppearanceCompiler::compile` reads them from `header.compiled`).
- **Settings keys:** `header.layout.draft`, `header.layout.published`, `header.compiled` (`{hash, layout}`, read in the SAME `getMany` as the rest of the appearance: no new query on any page).
- **Draft / preview / publish.** Admin «Хедър» (Admin group, `content_manage`): Save draft, Preview, Discard, Publish. Preview is `GET /header-preview`, a temporary signed URL (30 minutes), `noindex`, `Cache-Control: private, no-store`, with its own small preview stylesheet; the public pages never show a draft.
- **Features that do not exist yet** (search, account, wishlist): their elements can be placed and are stored, but print nothing (no empty row, no dead link) until the route exists.
- **No script:** the drawer is a plain block under the header (`@media (scripting: none)`), the menus stay reachable as links.
- **Drawer / search / accordion script** in `storefront.js` (focus trap, Esc, overlay, scroll lock via `html.sf-locked`, focus returns to the opener), tested in a fake DOM (`tests/Feature/Storefront/Js/header-flow.mjs`).
- **Not done (D3):** the `mega` menu style renders as `dropdown` until D3.
- **Known limit:** with very many or very long category names the `links` menu can crowd the logo in a desktop row; use `dropdown`.
