# DM-0 — Storefront dark mode: inventory and plan

> DM-1 done: see `dark-mode.md`.

Read-only inventory. Nothing in the code, CSS, Blade, settings or tests was changed. Counts come from `grep`/`rg` over the checkout at the time of writing (branch `mail-2`, on top of `main` with LOGO-1). Anything not confirmed in the code is marked `UNVERIFIED`. Line numbers are of `resources/css/storefront.css` unless a file is named.

Decisions already taken by the owner: one merchant switch (like the admin has); no visitor toggle, no `prefers-color-scheme`; black background, white main text, greyer secondary text; menus/logos recoloured, not redesigned; merchant-selectable button colour (default orange, white also wanted) with a COMPUTED text colour (`Contrast`: button vs background >= 3:1, text vs button >= 4.5:1); a dark logo may come later; mail and admin are out of scope.

---

## 1. Merchant setting -> pixel, today

1. «Облик» (`Filament/Pages/Settings/AppearanceSettings`) and the Design screen's «Топбар» both call `AppearanceWriter::save()`. It stores one `site_settings` row per token (`appearance.color.*`, `appearance.font.*`, `appearance.radius`, `appearance.button`; keys and CSS variables in `AppearanceSettings::COLORS`, 13 colours).
2. `AppearanceCompiler::css()` re-validates every value (`#rrggbb`, closed lists) and writes `:root { --sf-…: …; }` for ONLY the tokens that differ from the default, plus the `@font-face` of the chosen families, plus the closed variable lists of the other zones (footer `--sf-ft*`, header rows `--sf-hdr-*`/`--sf-cart-*`, checkout header `--sf-chdr-*`, page design, buy button `--sf-buy-*`, listing cloud `--sf-cloud-*`, cookie banner `--sf-cc-btn*`, styled HTML blocks `.sf-hb-{id}`, custom menu-badge classes). An untouched shop compiles to NOTHING (no file, no `<link>`).
3. The file is `storage/app/public/appearance/tokens-{sha1-12}.css` (public disk, `/storage/appearance/…`). The name changes with the content; the previous file is kept for pages cached a moment earlier, older ones are pruned. `appearance.compiled` (`{hash, fonts, body, preload?}`) records it.
4. `StorefrontAppearance` (request-shared, cached in the Laravel cache under `CACHE_KEY`, built from ONE `getMany` of `AppearanceSettings::READ_KEYS`) hands `forLayout()` to `storefront/layout.blade.php`, which prints `<link rel="stylesheet" href="{tokensUrl}">` AFTER the Vite-built `storefront.css`. `refresh()` rebuilds the payload and collects the surrogate key `all` (a purge of every cached page). See `storefront-cache-contract.md` §3.
5. In `storefront.css` (Tailwind v4, `@source '../views/storefront/**/*.blade.php'`, no `dark:` variant) every colour is a fallback of a variable, e.g. `--color-neutral-900: var(--sf-text, oklch(20.5% 0 none))`. So `bg-neutral-50` -> `var(--sf-bg, …)` -> pixel. Only FOUR Tailwind neutral steps are mapped (50 = page, 200 = border, 600 = muted, 900 = text); the other steps and `white` are Tailwind's own constants (see §3-A2).
6. The Design-screen previews (`DesignPreviewController`, header/footer/cookie previews) feed `previewCss` / `previewStylesheet` into the same layout slot.

Build facts: Tailwind `^4.0.0` via `@tailwindcss/vite`, Vite `^8`; entries listed in `vite.config.js` (`storefront.css`, `storefront.js`, `storefront-consent.js`, …). No `tailwind.config.*`, no `@custom-variant dark`, no `dark:` classes in the storefront. `@source` scans only `resources/views/storefront/**`. `storefront.css` is 7298 lines.

---

## 2. Mechanism questions (E)

**E1 — per-request variation and invalidation.** The compiled CSS depends only on settings (`AppearanceCompiler::compile()` reads `SiteSettingsRepository` and the published `*.compiled` values), never on the request, the visitor or a cookie. The cacheable storefront routes are sessionless and the cache contract forbids per-visitor content (`storefront-cache-contract.md` §5). A site-wide dark switch therefore gives the same page to everyone.
Invalidation: a new setting written through `SiteSettingsRepository::set` collects `all` (any storefront-visible key), and `StorefrontAppearance::refresh()` collects `all` explicitly. The page's Laravel cache payload is replaced by `refresh()`; `data()` also rebuilds when the cached array lacks a required key (the `array_key_exists` chain in `data()`), so ADDING a key to the payload (e.g. `mode`) self-heals old cache entries. The token file name is a content hash, so a change of mode/colours makes a new file name and a new `<link>`.
Caveat: `all` purges pages; pages already in a visitor's browser keep the old tokens file until reload (the previous file is kept on disk for that reason). Dark CSS shipped in `storefront.css` is a Vite hash file (new hash per build); a deploy and a mode flip are independent.

**E2 — where `data-theme` goes.** `<html lang="…">` in `storefront/layout.blade.php:2` is the one place. `forLayout()` already returns merchant-level data from the cached payload with no extra query; add `'mode' => 'dark'|null` there (read from the same `getMany`, so no new query). Server-rendered attribute, so no flash and no JS. The attribute must also be printed by the other full-page storefront templates that do not extend the layout: `storefront/cookie-preview.blade.php` (own `<html>`, line ~8) and the sandbox layout (out of scope, `color-scheme: light` is fixed there, `resources/views/sandbox/layout.blade.php:23`). `UNVERIFIED`: whether any other storefront template has its own `<html>`; grep `<html` before DM-1.

**E3 — existing tests that pin CSS or token lists** (DM-1/DM-2 will touch them): `tests/Feature/Storefront/FontsTest.php` (asserts `css($set)` of the compiler for many sets, line ~76; an untouched shop must still compile to `null`), `Feature/Admin/AppearanceSettingsPageTest.php`, `AppearanceBannerOwnershipTest.php`, `Feature/Cookies/CookieSettingsPageTest.php`, `ContrastTest.php`, `Feature/Storefront/{BadgeContrastTest,CheckoutHeaderTest,DribniFiveStorefrontTest,HeaderBuilderTest,HeaderCartColorsTest,HeaderRowLookTest,HomeImageGridTest,HomeTilesTest,CatalogListingTest}.php`, `Feature/Admin/Design/*` (Catalog zone, Draft model, Page sections, Topbar, Dribni five), `Feature/HtmlBlocks/HtmlBlock{Style,StylePrinting,Background,Card,PictureReady}Test.php`, `Feature/Logo/SiteLogo*Test.php`. Browser scripts that read tokens: `tests/Browser/{admin-save-audit,cd-1c,cd-1d,ct-4,dribni-6,gap-1,gl-1,hb-1,hp-6,meas-1,p3-1,p4b-1,pc-1}.mjs` (by hand, not in `php artisan test`). `UNVERIFIED`: which of these assert the exact text of a variable list versus only presence; DM-1 must run them and report.

**E4 — where a text colour is computed from a background (reusable in DM-1):**
- `App\Storefront\Cookies\Contrast` (`isHex`, `luminance`, `ratio`, `bestText`, `TEXT_MIN` 4.5, `AGAINST_DIALOG_MIN` 3.0, `DIALOG_BACKGROUND` `#ffffff`). Callers: `Settings/CookieSettings` (`vars()` computes `--sf-cc-btn-text`, validation at lines ~311-319), `CookiePreviewController`.
- `AppearanceSettings::contrastRatio()` (+ its own private `luminance`; `MIN_CONTRAST` 4.5, `CONTRAST_PAIRS`): used for ADMIN WARNINGS only (Appearance page, Topbar, Footer, Header cart, Product summary, HTML block style). It warns, it does not compute.
- `AppearanceCompiler::luminance()` (private duplicate): menu badge text (`.sf-badge-c-*`, `#111111` or `#ffffff`, threshold 0.179).
- `PurchaseStyle` (line ~111): `contrastRatio($hex,'#000000') < 5.0` decides a dark/light hover for the buy button.
Three copies of the WCAG luminance exist (`Contrast`, `AppearanceSettings`, `AppearanceCompiler`). DM-1 should use `Contrast` and not add a fourth.

---

## 3. Inventory

Severity: **S1** breaks readability or hides content; **S2** looks wrong but readable; **S3** fine/neutral. Counts are grep counts and mean "occurrences", not "distinct components".

### A. Colours not coming from a token

| # | Category | Count | Where | Sev |
|---|---|---|---|---|
| A1a | Hex literals in `storefront.css` | 227 occurrences (most are the FALLBACK inside `var(--sf-x, #…)`); 86 lines are literals with NO variable fallback | see A1 detail | S1/S2 |
| A1b | `rgb()/rgba()` | 21 | scrims, shadows, gradients (A5) | S2 |
| A1c | `oklch()` | 31 | the Tailwind-equivalent fallbacks inside `@theme` and `var(--sf-…, oklch(…))` | S3 (token fallback) |
| A1d | `<style>` blocks in storefront Blade | 0 | — | S3 |
| A1e | Hex in storefront Blade | 26 occurrences on 20 lines, all in `footer/payment-icon.blade.php` (SVG fills and strokes) | see B3 | S1 (B3) |
| A2a | Raw Tailwind palette utilities in storefront Blade | **0** | the views carry only `sf-*` classes; all colour lives in `storefront.css` through `@apply` | S3 |
| A2b | Raw palette utilities via `@apply` in `storefront.css` | 175 | see A2 detail | S1/S2 |
| A2c | Arbitrary `bg-[#…]` / `text-[…]` | 0 in Blade | — | S3 |
| A3 | Inline `style="…"` colour in storefront Blade | **0**. The 2 grep hits for `style="` in `footer/payment-icon.blade.php` are `font-style="italic"` SVG attributes, not inline styles | — | S3 |
| A3b | PHP that builds colour CSS/HTML | `Storefront/Badges/BadgeSet.php` (6 hex: the 6 badge pairs), `Content/HtmlBlocks/HtmlBlockCard.php` (4), `Storefront/ProductSummary/PurchaseStyle.php` (3), `Storefront/Menu/MenuCompiler.php` (1), `Settings/GallerySettings.php` (1), `AppearanceSettings` (the 13 defaults), `CookieSettings` (default `#111111`) | all validated `#rrggbb`, emitted as closed variable lists or classes (no `style=` attribute anywhere) | S2 |
| A4 | Inline SVG | 15 Blade files, 18 `<svg>`: `stroke="currentColor"` 16, `fill="none"` 16 (icons: cart, search, account, wishlist, menu toggle, filters, toolbar, drawer, social); fixed fills in `payment-icon` only (B3); `fill="{{ $value['colour'] }}"` 2 (attribute swatches) | icons are `currentColor`-driven | S3 (icons), S1 (payment icons) |
| A4b | SVG files | `public/images/logo.svg` (`stroke="currentColor"`, `fill="none"`: dark-capable), `public/favicon*.svg`, `public/images/easyco-logo-white.svg` | — | S3 |
| A5 | `box-shadow` | 33 lines; 14 are `inset 0 0 0 … var(--sf-primary…)` rings (the outline button) = S3; the true drop shadows (`rgba(0,0,0,.12)`, `rgb(0 0 0 / 8%)`, `shadow`, `shadow-md`, `shadow-lg`): lines 261, 1688, 2612, 3655, 4588, 4642, 5058, 5204, 6123, 7081 | S2 |
| A5b | Gradients | 4: 2153 (a sold-out diagonal line `#737373`), 2312-2313 (horizontal scroll shadows: `var(--sf-bg, #fff)` + `rgb(0 0 0 / .16)`), 4168 (`linear-gradient(to bottom, transparent, #fff)`: a white fade) | S1 (4168), S2 (2312-3) |
| A5c | Scrims | 1238 (`rgba(0,0,0,.45)`), 1844 (lightbox `rgb(0 0 0 / .88)`), 4711 (`.45`), 5782-5783 (hero overlays `.4`/`.6`), 7050 (cookie scrim `rgba(17,17,17,.55)`), `bg-black/60` (1860, 1881) | S3 on a black page (a scrim over a black page is invisible but harmless) / S2 (a modal over black loses its edge) |
| A6 | JS-emitted colours | **1**: `storefront-cart.js:466` sets a swatch dot's `backgroundColor` from a `#rrggbb` regex-checked value. Every other `style.setProperty` sets a number (`--sf-summary-top`, `--sf-lb-*`, …). No charts, sliders, toasts or skeletons emit colour | S3 |
| A7a | `:focus-visible` outlines | 59 lines mention `:focus-visible`; most use `var(--sf-pdp-text)` or `var(--sf-primary)` (flip with the tokens, S3). Hard-coded: 61 (global, `#0369a1` on `:focus-visible`), 2965, 6718, 6793 (`#0369a1`), 2162 and 2977 (`#b91c1c` error) | S2 (blue ring on black is readable; the red `#b91c1c` error ring/border on black: contrast to be measured) |
| A7b | `color-scheme`, `accent-color`, `caret-color`, `::selection`, `scrollbar-color` | **none** in `storefront.css` (UNVERIFIED for `app.css`, which the storefront does not load). Without `color-scheme: dark`, native controls (select popup, checkbox, date, scrollbars, autofill) stay LIGHT on a black page | S1 for any native control with transparent background; S2 otherwise |
| A7c | `::placeholder` / `:placeholder-shown` | no `::placeholder` colour rule found; the 2 `:placeholder-shown` hits (3151, 3154) are floating-label logic. Placeholders use the browser default grey | S2 |
| A7d | Autofill | no `:-webkit-autofill` rule found: Chrome paints a pale-yellow/blue fill with dark text on inputs | S2 |
| A8 | `<meta name="theme-color">`, web manifest | **none** (no manifest in `public/`) | S3 (could add, optional) |

**A1 detail.** The 12 most frequent literals: `#fff` x65, `#171717` x26, `#e5e5e5` x22, `#1a1a1a` x19, `#404040` x14, `#737373` x12, `#d9d9d9` x9, `#525252` x9, `#0369a1` x9, `#ffffff` x8, `#b91c1c` x5, `#d4d4d4` x4. Of the literals with NO variable fallback, the white surfaces are `background: #fff` at 925, 1077, 1140, 1510, 3762, 3786, 3817, 3883, 3988, 4587, 4677, 4728, 4775, 5056, 5203, 5479, 6531, 7067 (`#ffffff`), 7223 (`#ffffff`) and `box-shadow … 0 0 0 3px #fff` rings at 959, 1087, 1117 (the white gap between a swatch and its ring), `color: #fff` at 5839 (`.sf-pc-c-light`), `border-color: #fff` at 5832, `--sf-badge-fg: #fff / #111`, `--sf-badge-bg: #15803d / #171717` at 5235-5256, `color: #171717` at 5480, 7041, 7105 (dark text with no variable), `color: #404040` at 7199, `color: #737373` at 3160.

**A2 detail (the main finding).** `storefront.css` maps only `neutral-50/200/600/900`. The raw (NOT token-driven) utilities used through `@apply`:
- `bg-white` x38 (+ `bg-white/90` x2): cards, dropdowns, panels, drawers, the whole cart, checkout, order-received and account area. Selectors: `.sf-empty`(187) `.sf-subcategory>a`(241) `.sf-card`(261) `.sf-card-sizes`(332) `.sf-sortdd-list`(769) `.sf-bctl-panel`(849) `.sf-fdrawer-panel`(1248) `.sf-fgroup[open]`(1344) `.sf-gal-next`(1688) `.sf-variation-select`(2015) `.sf-picker-select`(2087) `.sf-menu-it`(2612) `.sf-cart-lines`(2667) `.sf-cart-promo*`(2705-2755) `.sf-cart-totals`(2795) `.sf-cart-empty`(2831) `.sf-category-tile-link`(2856) `.sf-fieldset`(2921) `.sf-kind-select`(2947) `.sf-invoice-copy`(3240) `.sf-express`(3244) `.sf-promo-compact-input`(3297) `.sf-note-field textarea`(3320) `.sf-checkout-summary`(3361) `.sf-summary-toggle`(3370) `.sf-received-facts/-lines`(3421/3437) `.sf-bank-details`(3482) `.sf-account-*`, `.sf-acc-*` (6702-6961).
- `text-neutral-800` x14, `-700` x11, `-500` x10, `-400` x1; `border-neutral-300` x18, `-400` x1; `bg-neutral-100` x9, `bg-neutral-200` x3 (these two are Tailwind's raw greys, `200` IS mapped to the border token, so `bg-neutral-200` flips with the border token, which is wrong for a background; see the proposal).
- Status colours: `bg-red-50` x5, `text-red-800/900/700` x7, `border-red-300/700` x6, `bg-amber-50`, `text-amber-800/900`, `border-amber-300`, `bg-sky-50`, `text-sky-700/800/900`, `text-green-800`, `text-emerald-700` — the message boxes (`partials/messages`, form errors, notices). Light pastel boxes with dark text: on a black page they are bright patches but READABLE (S2); the failure is if only the text token flips and not the box.
- `divide-neutral-200` x4, `decoration-neutral-900/400`, `bg-neutral-900 text-white` (6929, a counter pill: with `--sf-text` white and `text-white` it becomes white-on-white: **S1**).
- Token-driven (S3): `text-neutral-900` x41, `border-neutral-200` x36, `text-neutral-600` x26, `bg-neutral-50` x3.

**Quantified risk.** About 38 `bg-white` + ~22 literal `#fff` surfaces (≈ 60 white surfaces) are NOT driven by any token. If only the four mapped tokens flip to dark, then on those surfaces the text (`--sf-text` becomes white) lands on a white card = white on white. That is the S1 class: cards (`.sf-card`), dropdowns, drawers, cart, checkout, account, the cookie dialog (`.sf-cc-dialog`, `background: #ffffff`, 7067/7223).

### B. Assets that assume a light background

| # | What | Finding | Sev |
|---|---|---|---|
| B1 | Header logo | `header/elements/logo.blade.php`: the merchant's `logo.main` PNG/WebP (LOGO-1/LG-1: a raster rendition with width/height) or `public/images/logo.svg` (`currentColor`). The stock SVG follows the text colour (S3). The merchant's raster is usually dark artwork: invisible on black (**S1**) until a dark variant exists (`logo.dark` exists already but is used by the ADMIN only, `site-logo.md`; the storefront does not print it). Footer/checkout/account logos: the same element (`sf-logo`, `checkout-header.blade.php`) | S1 |
| B2 | Product/category/banner images | `.sf-card-image` has `bg-neutral-100` (line 276), `.sf-gal-placeholder` `bg-neutral-100` (1644), `.sf-gal-thumb` (1697), `.sf-cart-line-img` (2886), `.sf-summary-line-image img` (3396): a light grey box behind the picture. Transparent PNG/WebP products would show on that box. If the box turns dark, a transparent product with dark artwork vanishes; if it stays light, the card is a light tile on a black page (S2, and arguably desirable for product photography). Whether the media pipeline flattens transparency when it makes WebP variants: `UNVERIFIED` (`LaravelMediaImageProcessor`, no `imagecolorallocatealpha`/flatten found by grep in `packages/EasyCo/Media/src`) | S2 (decision needed) |
| B2b | «No image» placeholder | `.sf-image-placeholder` (line 463): an inline data-URI SVG, stroke `#a3a3a3` (mid-grey), on the box's background. Readable on both light and dark (S3). Used by cards, gallery and subject tiles | S3 |
| B2c | Hero / HTML-block pictures with overlays | `.sf-pc-o-medium/strong` `rgba(0,0,0,.4/.6)`; text colour classes `.sf-pc-c-light` (`#fff`), `.sf-pc-c-dark` (`var(--sf-text)`: becomes WHITE in dark mode on a LIGHT merchant picture = **S1**), `.sf-pc-bc-light/dark` | S1 |
| B3 | Payment icons | `footer/payment-icon.blade.php`: visa, mastercard, maestro, google_pay, bank_transfer, cash_on_delivery = white rounded rectangle with `#d4d4d4` border; apple_pay `#000000` fill, paypal `#003087` fill. Each is a SELF-CONTAINED badge with its own white plate, so glyphs do not vanish: a white tile on black (S2). Apple Pay (black plate, white text) and PayPal (navy) on black: the plate edge is invisible but the text is readable (S2) | S2 |
| B3b | Courier / trust badges | no courier-logo or trust-badge asset found in the storefront views (grep for `speedy|econt|boxnow` finds none in `resources/views/storefront` or `resources/js/storefront*.js`) | S3 |
| B4 | Icon set | all functional icons are inline SVG with `stroke="currentColor"` + `fill="none"` (16 of 16): they follow the colour of their button/text. Fixed fills exist only in payment icons and the swatch `fill="{{ colour }}"` | S3 |
| B5 | Product badges | `BadgeSet::PAIRS`: red `#be0f2c/#fff`, orange `#f59e0b/#000`, blue `#1e40af/#fff`, green `#15612f/#fff`, black `#111111/#fff`, grey `#e5e5e5/#111111` — each a self-contained pair; the `black` badge (`#111111`) on a black card has no edge; `grey` is a bright pill | S2 |

### C. Third-party and embedded surfaces

Searched the storefront views, `storefront*.js` and `app/Storefront` for `iframe|recaptcha|turnstile|hcaptcha|stripe|paypal|speedy|econt|boxnow|leaflet|google.maps|youtube|vimeo|<script src`. Findings:
- **No iframe, no external script, no map, no bot-check widget, no payment widget** is printed by the storefront views. The HTML sanitisers (`DescriptionSanitizer`, `HtmlBlockSanitizer`) drop `iframe`, `script`, `style`, `object`, `embed`, `svg`, `video`, `form`, `input` from merchant content. The only bot control is a honeypot (`partials/honeypot.blade.php`), not a widget.
- `social-icon.blade.php` and the header «social» element print inline SVG icons with network names (youtube, etc.), no embed.
- Native browser surfaces we cannot style: `<select>` option popups, `<dialog>::backdrop` (we do style it), date pickers, autofill, scrollbars. They follow `color-scheme` (A7b).
- Courier pickers and payment redirects: handled server-side/with the storefront's own JS (`storefront-checkout.js`, 2139 lines). `UNVERIFIED`: whether the checkout shows an external office picker or a hosted payment page; none was found in the storefront code (it may come with a future carrier/payment integration, which would then be a new row here).

### D. Merchant-controlled colour

| # | Item | How stored / emitted | Validation | Assumes light? | Sev |
|---|---|---|---|---|---|
| D1a | Shop tokens (13) | `appearance.color.*` -> `:root --sf-*` | `#rrggbb`, compiler re-checks; admin WARNS (not blocks) for 4 pairs below 4.5:1 (`CONTRAST_PAIRS`) | defaults are light; a merchant who already set a DARK `background` + light `text` has a working dark theme today for the mapped tokens only | S2 |
| D1b | Header rows, cart element | `header.layout.published` -> `--sf-hdr-{d|m}-{top|main|bottom}-{bg|fg}`, `--sf-cart-*` | `#rrggbb`, closed regex | a row with a set colour keeps it in dark mode (the merchant chose it); an UNSET row falls back to `--sf-header-bg`/`-text` (white/dark by default) | S2 |
| D1c | Footer | `--sf-ftu-*`, `--sf-ftl-*`, `--sf-ftu-bc` | `#rrggbb`/`Npx` | same as header; the old `--sf-footer-bg` `#fff` default | S2 |
| D1d | Checkout header | `--sf-chdr-bg/-fg` | `#rrggbb` | same | S2 |
| D1e | Top banner | `banner.color.text/bg` (`#fff` on `#171717` default) | `#rrggbb` | already dark by default (S3) | S3 |
| D1f | Buy button | `--sf-buy-bg/-bg-hover/-color/-border` (`PurchaseStyle`) | `#rrggbb`/`currentcolor` | merchant colour wins; the default is the `--sf-primary` button (`#171717` on black = invisible: **S1** until `--sf-primary` is remapped) | S1 |
| D1g | Listing cloud | `--sf-cloud-bg/-color` (`ContentSet`) | closed list | merchant colour | S2 |
| D1h | HTML blocks | `HtmlBlockStyle` -> `.sf-hb-{id}{…}`: bg, text colour, border colour, card colours; overlay 0/20/40/60 | `isHex`, closed template | a block with an unset text colour inherits `--sf-text` (white) on its own bg: fine; a block with a SET light bg and unset text = white on light = **S1**; a block with SET dark text and no bg = dark on black = **S1** | S1 |
| D1i | Menus | per-item badge colour `#rrggbb` -> class `.sf-badge-c-xxxxxx` with computed `#111111/#ffffff` text; no per-item link colour | `isHex`, max 48 classes | the badge is self-contained (S3) | S3 |
| D1j | Attribute swatches | `attribute_values.colour`, printed as `fill="{{ colour }}"` / `style`-free dot; `storefront-cart.js:466` | regex `#rrggbb` | the colour IS the product colour; a black swatch on black needs a ring (the existing white ring at 959/1087/1117 is `#fff`: fine on black) | S2 |
| D1k | Cookie banner (CC-1) | `cookies.button_color` -> `--sf-cc-btn` + COMPUTED `--sf-cc-btn-text` via `Contrast::bestText`; the dialog and bar are hard-coded white: `.sf-cc-dialog { background: #ffffff }` (7067, 7223), text `#171717`/`#404040`, scrim `rgba(17,17,17,.55)` | `Contrast::TEXT_MIN` 4.5 and `AGAINST_DIALOG_MIN` 3.0 against `#ffffff` (`DIALOG_BACKGROUND`) | By design a WHITE dialog with its own text colours. It stays readable on a black page (a white dialog) = S2; flipping it needs `DIALOG_BACKGROUND` to become the page surface | S2 |
| D2 | Rich text (product description, pages, banners) | `DescriptionSanitizer` / `HtmlBlockSanitizer`: attributes allowed ONLY `href` on `a`; **no `style`, no `class`, no `color`**; `svg`, `iframe`, `script`, `style` dropped | allow-list | Merchant rich text cannot carry an inline colour, so it inherits `--sf-text`: black-on-black cannot happen from pasted `<span style="color:#000">` (the style is stripped). Pasted `<font color>`: `UNVERIFIED` that `font` is not in the allow-list | S3 |
| D3 | Colour pickers that feed the storefront | `Settings/AppearanceSettings` (13 via `ColorPicker`), `Design/Zones/TopbarZone` (2), `HeaderZone` (row colours, cart), `FooterZone` (4), `ProductSummaryZone` (buy button), `ContentZone` (`cloud_bg`, `cloud_color`), `Settings/CookieSettingsPage` (1), `HtmlBlockResource` (`card.*`, `style.*`), `AttributeValueResource` (`colour`), `Design/menus` (`<input type="color">` for badges), generic `FieldForms` (`color` field type) | each re-validated at compile | — | — |

### E. Mechanism questions

Answered in §2 (E1-E4).

---

## 4. Top problems by severity

1. **S1 — ~60 white surfaces not token-driven** (`bg-white` x38 + literal `#fff`/`#ffffff` backgrounds ≈ 22). Flipping `--sf-text` to white without them gives white-on-white cards, dropdowns, drawers, cart, checkout, account.
2. **S1 — primary button on black**: `--sf-primary` `#171717` on `--sf-bg` black is invisible (the button's own `--sf-btn-bg`/`--sf-on-primary`, the PDP buy button, outline rings `inset 0 0 0 … var(--sf-primary)`: 14 `box-shadow` rules, 38 `var(--sf-primary…)` uses).
3. **S1 — no `color-scheme`**: native controls (select popups, inputs, scrollbars, autofill) stay light; plus no `::placeholder` and no autofill rule.
4. **S1 — the merchant's raster logo** (dark artwork on black). `logo.dark` exists but the storefront does not print it.
5. **S1 — HTML blocks** with a SET bg and unset text (white on light) or a SET dark text and no bg; **S1 — `.sf-pc-c-dark`** text (`var(--sf-text)`) on a light merchant picture.
6. **S1 — `bg-neutral-900 text-white`** counter pill (6929): `--color-neutral-900` becomes white.
7. **S2 — Tailwind `neutral-200` is mapped to the BORDER token but also used as a background (`bg-neutral-200` x3) and `divide`;** `neutral-100/300/400/500/700/800` (≈ 90 uses) are unmapped raw greys (borders `#d4d4d4`, texts `#404040`…) that look too bright or too dim on black.
8. **S2 — fixed-tone status boxes** (red/amber/sky/green pastel backgrounds, ~35 utilities): readable bright patches; they need dark variants for coherence, not for readability.
9. **S2 — shadows and scrims** (10 drop shadows, 6 scrims): invisible on black; dropdowns and drawers lose their edge unless a border/lighter surface is added.
10. **S2 — product-image frames** (`bg-neutral-100` behind photos) and payment icons (white plates): decision, not bug.

Also: `tokensUrl` is null for an untouched shop: dark mode must work with NO token file (dark values shipped inside `storefront.css`, switched by `html[data-theme='dark']`); the Design-screen previews need the attribute too.

---

## 5. Proposed token set

Existing storefront colour tokens (13, `AppearanceSettings::COLORS`): `--sf-bg`, `--sf-text`, `--sf-muted`, `--sf-primary` (+ computed `--sf-primary-hover`), `--sf-on-primary`, `--sf-accent`, `--sf-border`, `--sf-header-bg`, `--sf-header-text`, `--sf-footer-bg`, `--sf-footer-text`, `--sf-banner-text`, `--sf-banner-bg`. Derived in CSS: `--sf-pdp-text` (= `--sf-text`), `--sf-pdp-muted` (= `--sf-muted`), `--sf-pdp-line*`, `--sf-btn-bg`, `--sf-btn-text`, `--sf-btn-bw` (outline button), `--sf-buy-*`, `--sf-cc-btn*`.

Proposed (names are suggestions for DM-1; every value below is a PROPOSAL for the owner, not a decision). "Light" = today's value (must stay byte-identical when the mode is off).

| Token | Role | Light (today) | Dark (proposed) | Status |
|---|---|---|---|---|
| `--sf-bg` | page background | `#fafafa` | `#000000` | exists |
| `--sf-surface` | cards, dropdowns, drawers, inputs (replaces `bg-white` / `#fff` fills) | `#ffffff` | `#111111` (a step above the page so cards and borders read; pure black cards would merge) | **new** |
| `--sf-surface-2` | sunken/placeholder areas (replaces `bg-neutral-100`, image frames) | `#f5f5f5` (neutral-100) | `#1a1a1a` | **new** |
| `--sf-text` | main text | `#171717` | `#ffffff` | exists |
| `--sf-muted` | secondary text | `#525252` | `#a3a3a3` (>= 7:1 on black) | exists |
| `--sf-border` | borders | `#e5e5e5` | `#2e2e2e` | exists |
| `--sf-border-strong` | the `border-neutral-300`/`#d4d4d4`/`#d9d9d9` family (inputs, selects) | `#d4d4d4` | `#4a4a4a` | **new** |
| `--sf-primary` | button / ink colour | `#171717` | the merchant's button colour; default orange (owner: «orange»; value to confirm, e.g. `#f97316`) | exists, dark default differs |
| `--sf-on-primary` | text on the button | `#ffffff` | COMPUTED: `Contrast::bestText(--sf-primary)` (probably `#000000` on orange); never typed | exists; becomes computed in dark |
| `--sf-focus` | focus ring | `#0369a1` (hard-coded in 4 rules) | `#38bdf8` (>= 3:1 on black) | **new** (variabilises the 5 hard-coded outlines) |
| `--sf-error` | error text/ring (`#b91c1c`, 5 uses) | `#b91c1c` | `#f87171` | **new** |
| `--sf-overlay` | modal scrims | `rgba(0,0,0,.45)` | `rgba(0,0,0,.7)` | **new** (replaces 6 scrims) |
| `--sf-shadow` | drop-shadow colour | `rgba(0,0,0,.12)` | `none` + a 1px `--sf-border` on the panel | **new** (replaces ~10 shadows) |
| `--sf-header-bg` / `-text` | header | `#ffffff` / `#171717` | `--sf-bg` / `--sf-text` unless the merchant set a row colour | exists |
| `--sf-footer-bg` / `-text` | footer | `#ffffff` / `#737373` | `#000000` / `#a3a3a3` | exists |
| `--sf-banner-bg` / `-text` | top banner | `#171717` / `#ffffff` | `#262626` / `#ffffff` (so it does not vanish on black) | exists |
| `--sf-status-*` (error/warn/info/ok, bg + text + border) | message boxes (the ~35 red/amber/sky/green utilities) | Tailwind pastels | dark tints, e.g. error `#2a0f0f` / `#fca5a5` | **new** (4 groups) |
| `color-scheme` | native controls | not set | `dark` on `html[data-theme='dark']` | **new** (property, not a variable) |

Button contrast rules for DM-1 (owner's): the button colour must reach **>= 3:1 against `--sf-bg`** (component contrast; orange `#f97316` on black ≈ 7.6:1 passes, a white button 21:1, a dark-grey button fails and is refused with a message), and the COMPUTED text colour must reach **>= 4.5:1 against the button** (`Contrast::bestText` always gives white or black, whichever is higher: for orange black wins ≈ 8.7:1). Save-time validation follows the cookie banner's pattern (`CookieSettings` lines ~311-319: compute, check, refuse with a message; the colour is stored, the text colour never). Constants to reuse: `Contrast::TEXT_MIN` (4.5) and a new `COMPONENT_MIN` (3.0; `AGAINST_DIALOG_MIN` has the same value but its name is about the cookie dialog, so add a neutral constant rather than reuse that name). `UNVERIFIED`: orange's exact hex; the ratios above are arithmetic from the WCAG formula and must be re-run by `ContrastTest` in DM-1.

Setting: ONE new key, e.g. `appearance.mode` = `light` (default, missing key = light) | `dark`, in `AppearanceSettings::defaults()` and `READ_KEYS`; a second key for the dark button colour (e.g. `appearance.dark.button`, `#rrggbb`, default orange). Both are storefront-visible, so `SiteSettingsRepository::set` collects `all`. Not a visitor cookie, not a media query.

---

## 6. Proposed steps (each one reviewable diff)

**DM-1 — switch, tokens, `data-theme`, contrast check (no visual change when off).**
- Setting `appearance.mode` and `appearance.dark.button` (+ «Облик» fields: a switch and a button-colour picker with the live contrast message, the admin already has `Text::make` warning components to copy).
- `AppearanceSettings`: defaults, `READ_KEYS`, `isValid`; `StorefrontAppearance`: `mode` in the payload (and in the `array_key_exists` chain, so old cache entries rebuild), `forLayout()['mode']`.
- `layout.blade.php`: `<html lang="…" data-theme="dark">` only when dark (nothing printed when off, so the page is byte-for-byte what it is today). Also `cookie-preview.blade.php`.
- `storefront.css`: the NEW variables with their LIGHT fallbacks equal to today's values (so light mode renders identically), plus ONE block `html[data-theme='dark'] { --sf-bg: …; … color-scheme: dark; }`. No selector is rewritten yet.
- `AppearanceCompiler`: in dark mode the compiled sheet must always exist (the dark button colour and its computed text are written like the cookie colour, closed list re-checked, `Contrast::bestText`); an untouched LIGHT shop still compiles to `null`.
- Tests: `ContrastTest` additions (component 3:1 / text 4.5:1 for orange, white, a refused dark grey); compiler test for dark, light-untouched = null (the FontsTest invariant); payload test (`mode` in `forLayout`, old cache heals); layout test (attribute printed only when dark, byte-identical page when off); cache test (a mode flip collects `all`). Browser script by hand: home + product + cart in both modes, screenshots.
- Touches: 1 CSS block, 4 PHP files, 2 Blade files, the «Облик» page, tests. No selector migration, so it ships safely with the switch hidden or flagged.

**DM-2 — move raw colours to tokens, in areas (several PRs, each testable in both modes):**
- DM-2a shell: header, nav, dropdowns, footer, banner, `.sf-layout`, drawers, `:focus-visible`, `::placeholder`, autofill, `color-scheme` fine-tuning.
- DM-2b catalogue: `.sf-card`, tiles, filters (`.sf-fdrawer-panel`, `.sf-fgroup`, sort dropdown, `.sf-bctl-panel`), pagination, gallery, `.sf-card-image` frame, badges.
- DM-2c product page: summary, variation selects (`.sf-variation-select`, `.sf-picker-select`), buy button (`--sf-buy-*` default = `--sf-primary`), tabs, sections, the `bg-neutral-900 text-white` pill.
- DM-2d cart + checkout + order-received: `.sf-cart-*`, `.sf-checkout-*`, `.sf-received-*`, `.sf-fieldset`, `.sf-bank-details`, status message boxes.
- DM-2e account area (`.sf-acc-*`, `.sf-account-*`).
- Rule: `bg-white` -> `bg-[var(--sf-surface)]` style via `@theme` tokens (`--color-surface`), unmapped neutral steps -> the new tokens, `#fff` fills -> `var(--sf-surface, #fff)` (light value unchanged). Tests: a CSS lint test that fails on a NEW `bg-white`/`#fff` background outside an allow-list (a ratchet; the count above is the starting point), the existing storefront tests, and a browser screenshot per area in both modes.

**DM-3 — assets:** `logo.dark` printed by the storefront when mode is dark (reuse the LOGO-1 `logo.dark` payload; fallback `logo.main`; the header element prints both or swaps the `src` server-side: the mode is site-wide, so ONE `<img>`, no `<picture>`); decide the image-frame rule (B2); payment icons (leave plates, or add an outline); the placeholder; product badge `black` edge. Tests: logo payload/selection (like `SiteLogoPayloadTest`), a template test per mode.

**DM-4 — merchant-colour components:** HTML blocks (a SET bg with an unset text gets a computed text colour; a SET dark text with no bg gets a surface), `.sf-pc-c-dark/-light`, the hero overlay, header/footer/checkout-header rows (unset rows follow the mode; set rows are the merchant's), listing cloud, buy button, the cookie banner (dialog surface from `--sf-surface`, `Contrast::DIALOG_BACKGROUND` becomes the mode's surface; the preview too), admin warnings for merchant colours that clash with the mode (extend `CONTRAST_PAIRS`).

**DM-5 — third-party surfaces:** none exist today (§3-C). Reserved: an external office picker, a hosted payment page, any future embed. Add a row to this document and a `color-scheme`/theme parameter check whenever one is introduced. Also the Design-screen previews (`DesignPreviewController`, `previewCss`) and the mail-independence check (a mail never reads `appearance.mode`).

---

## 7. Risks and questions for the owner

1. **Merchant HTML blocks with a fixed background** (white on light vs dark): the plan is to compute the text colour when a bg is set. Alternative: leave merchant-styled blocks entirely as the merchant made them, and warn in the admin. Which?
2. **Merchants who already run a custom dark scheme** through the 13 tokens (set `--sf-bg` dark): when the switch is OFF nothing changes for them; when it is ON, do the dark values override their custom colours, or do their set colours win? (Proposal: set tokens win over the dark defaults, as every other merchant token does today.)
3. **Dark-glyph raster logos:** until `logo.dark` is uploaded, what should the storefront do: show `logo.main` as is (invisible if dark artwork), or invert/brighten with a CSS filter (a hack, never exact)? Proposal: no filter; show `logo.main` and make the admin warn «не е качено тъмно лого» when the switch is turned on without one.
4. **Transparent product images:** keep the light grey frame (photos look as designed, the cards are light tiles on black) or a dark frame (transparent product shots with dark artwork vanish)? Proposal: a dark frame ONLY after the media pipeline is confirmed to flatten onto white (`UNVERIFIED`); otherwise keep a light frame.
5. **Payment icons:** keep the white plates (proposal) or draw outlines?
6. **Dark button default:** the exact orange (e.g. `#f97316`) and whether a preset list (orange, white, plus a free picker) is wanted, or the free picker alone.
7. **Surface step:** cards/inputs at `#111111` on a pure-black page (proposal) or the same black with only a border? The `--sf-border` of `#2e2e2e` is a visibility compromise to confirm by eye in DM-2b.
8. **Status boxes (error/warn/info/ok):** one dark tint set (proposal) or keep the light pastels as bright "notice cards"?
9. **Cookie banner:** dark dialog in dark mode (proposal) or always white (works today, S2)? It is CC-1 and has its own preview; touching it belongs in DM-4.
10. **`color-scheme: dark`** changes the look of every native control (select popups, scrollbars) site-wide: is that wanted? (Proposal: yes, it is the only way to keep them readable.)
11. **Cached pages:** a mode flip purges `all` (the cache contract's rule for any «Дизайн» publish). Old cached HTML that references a pruned token file is kept for one generation (the compiler keeps the previous file). Is one generation enough when a shop flips twice quickly? (`UNVERIFIED` against the real Varnish TTL, `s-maxage=604800`: a page cached before the second flip can outlive the retained file: the same risk exists today for any token change and is covered by the purge, but it is worth one line in DM-1's report.)
12. **Test churn:** DM-1 should change NO existing test; DM-2 will change the ones that assert the colour of a moved selector (listed in §2-E3). The ratchet test in DM-2 is new.
