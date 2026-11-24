# Storefront dark mode (DM-1: the foundation, DM-2a: the neutral scale and the shell)

Status: **DM-1 and DM-2a are done, behind a dev flag. The shell (header, menus, drawer, footer, banner, the base form controls) and the neutral scale are migrated; the catalogue, the product page, the cart / checkout and the account area are NOT yet**, so with the switch on those pages still show white cards (with now light text on them) until DM-2b..e. Background and the full inventory: `dark-mode-inventory.md`.

## What the merchant gets

One site-wide switch in «Облик» → «Тъмен режим» (only while the dev flag is on): a toggle «Тъмен режим на сайта» and a colour for the buttons in dark mode (a free picker, two presets «Оранжево» `#f97316` and «Бяло» `#ffffff`). No visitor toggle, no cookie, no `prefers-color-scheme`, no JavaScript.

## The mechanism

| Piece | Where | Rule |
|---|---|---|
| Dev flag | `config('storefront.dark_mode')` ← env `STOREFRONT_DARK_MODE` (default `false`, `.env.example`) | Off: the «Облик» section is not in the form, `StorefrontAppearance::mode()` is `null` and the compiler ignores the mode, **whatever is stored**. A stored `dark` has zero effect. The flag is applied when the value is READ (the cached payload lives across deploys), never when it is built. **Remove the flag once DM-2 is done.** After flipping it, save «Облик» once: the token stylesheet is compiled for the state it was saved in. |
| Settings | `appearance.mode` (`light` \| `dark`, a missing or invalid value = `light`), `appearance.dark.button` (`#rrggbb`, default `#f97316`, a missing or invalid value = the default). `AppearanceSettings::MODE`, `DARK_BUTTON`, `defaults()`, `isValid()`, `READ_KEYS` (`MODE`; the colour lives in the compiled stylesheet) | Only a value that differs from its default is stored (`AppearanceWriter::save()`, like every token). Storefront-visible settings, so a write collects the surrogate key `all` (`EloquentSiteSettingsRepository::set`, and `StorefrontAppearance::refresh()`). |
| Payload | `StorefrontAppearance::build()` → `mode` (the validated STORED mode, from the same one `getMany`, no new query); `mode()` and `forLayout()['mode']` → `'dark'` or `null` | `mode` is in the `array_key_exists` chain of `data()`, so a cache entry from before DM-1 is not trusted and is rebuilt. The value is the same for every visitor: no request data, no cookie. |
| Attribute | `storefront/layout.blade.php`: `<html lang="…" data-theme="dark">` only when dark; `storefront/cookie-preview.blade.php` the same | Light: the tag is byte-identical to before. Every storefront page and every Design-screen preview (header, footer, design, page) extends the layout through the `storefront.layout` view composer, so they get the attribute for free; the cookie preview has its own `<html>` and gets it from `CookiePreviewController`. The sandbox layout (`color-scheme: light` fixed) is out of scope. |
| CSS | `resources/css/storefront.css` | New tokens (below) with LIGHT fallbacks equal to today's values, exposed through `@theme static` (`--color-surface`, …); NO existing selector uses them yet. ONE block `html[data-theme='dark'] { … }` with the dark palette and `color-scheme: dark`, shipped in the stylesheet so dark mode works with no token file. It is **unlayered** and `html[data-theme]` outweighs `:root`, so even a token stylesheet compiled for light cannot repaint a dark page. |
| Compiler | `AppearanceCompiler::css()` | See "Which colours win". Light output is byte-identical to before DM-1 (pinned by `tests/Feature/Storefront/fixtures/dm1-light-css.json`, taken from the code before the change); an untouched light shop still compiles to `null`. The file name stays a content hash, so a flip makes a new file and a new `<link>`. |
| Contrast | `App\Storefront\Cookies\Contrast` | Constants `COMPONENT_MIN = 3.0`, `DARK_BACKGROUND = '#000000'`; `Contrast::darkButton($hex)` → `{ok, reason, textColour, buttonRatio, textRatio}` is the ONE check used by the admin form (live message and field rule), the writer and the compiler. No fourth luminance function. |

## Which colours win in dark mode

- The **13 base tokens** (`appearance.color.*`: background, text, muted, primary, on-primary, accent, border, header bg/text, footer bg/text, banner text/bg) are **replaced by the dark palette**: the compiler does not emit them while the mode is dark. The merchant's light values stay stored untouched and are compiled again when the switch is turned off.
- The **dark button colour** comes only from `appearance.dark.button`, never from the light `appearance.color.primary`. The compiler writes `html[data-theme='dark'] { --sf-primary; --sf-on-primary; --sf-primary-hover }` only when it differs from the default (the default, its computed text and its hover are already in `storefront.css`). The text colour is `Contrast::bestText()`, never typed; the hover is `towardsWhite(colour, 0.1767)`, the same computation as the light primary's.
- **Zone colours the merchant set explicitly are left exactly as set**: header rows (`--sf-hdr-*`, `--sf-cart-*`), footer (`--sf-ft*`), checkout header, buy button (`--sf-buy-*`), listing cloud, styled HTML blocks, menu badges, the cookie button. Fonts, radius, button style and the other zone lists are compiled exactly as in light mode. Admin warnings for clashes are DM-4.
- Mail and the admin never read the mode: a mail is byte-identical whatever `appearance.mode` is (`DarkModeMailTest`).

## Contrast rules

The dark button colour must reach **3:1 against the black page** (`COMPONENT_MIN`) and its COMPUTED text colour **4.5:1 against the button** (`TEXT_MIN`). A colour that fails 3:1 is refused: the field's validation shows the reason and nothing at all is saved (the form saves nothing half-way), `AppearanceWriter::save()` throws before writing, and the compiler drops it (the shipped default stays). White or black text on any colour reaches 4.5:1 (the two ratios cross at 4.58:1), so the text rule can never fire on its own; it is kept as a guard. The same pattern as the cookie banner button (`CookieSettings`).

## The dark palette (values shipped in `storefront.css`)

`--sf-bg #000000`, `--sf-surface #111111`, `--sf-surface-2 #1a1a1a`, `--sf-text #ffffff`, `--sf-muted #a3a3a3`, `--sf-border #262626`, `--sf-border-strong #666666` (DM-2a: was `#404040`), `--sf-focus #38bdf8`, `--sf-error #f87171`, `--sf-accent #f87171` (the sale price colour; not in the DM-0 list: the light `#b91c1c` is too dark on black), `--sf-overlay rgb(0 0 0 / .7)`, `--sf-shadow transparent`, header follows `--sf-bg`/`--sf-text`, footer `#000000`/`#a3a3a3`, banner `#262626`/`#ffffff`, the four status groups `--sf-status-{error,warn,info,ok}-{bg,text,border}` as dark tints, `--sf-primary #f97316`, `--sf-on-primary #000000`, `--sf-primary-hover #fa8c3f`.
New tokens and their light fallbacks: `--sf-surface #ffffff`, `--sf-surface-2 #f5f5f5`, `--sf-border-strong #d4d4d4`, `--sf-focus #0369a1`, `--sf-error #b91c1c`, `--sf-overlay rgba(0,0,0,.45)`, `--sf-shadow rgba(0,0,0,.12)`, status groups = today's Tailwind pastels (`red-50/900/300`, `amber-50/900/300`, `sky-50/900/300`, `green-50/800/200`).

## DM-2a: the neutral scale, the global base and the shell

**Rule: light mode renders exactly as before.** Every new or re-pointed token has the OLD literal as its light fallback; only the dark block changes values. Proved on the BUILT stylesheet: every `var(--sf-…, X)` and `var(--color-…)` replaced by its fallback, the declarations of each rule sorted, then diffed against the build of the previous checkout: **no changed rule** (the only addition among the light rules is the unused utility class `.bg-surface`, like the `.bg-white` that already exists).

**The neutral scale** (`@theme`, one change, no selector edit; every `text-neutral-*` / `border-neutral-*` / `bg-neutral-*` use flips by itself):

| Step | Token | Light fallback (unchanged) | Dark |
|---|---|---|---|
| 100 | `--sf-surface-2` | `oklch(97% 0 none)` | `#1a1a1a` |
| 300 | `--sf-border-strong` | `oklch(87% 0 none)` | `#666666` (DM-1 had `#404040`: 2.0:1 on black, under the 3:1 a control border needs; this is the nearest value that passes) |
| 400 | `--sf-n400` (new) | `oklch(70.8% 0 none)` | `#808080` (the proposed `#737373` is 3.98:1 on a `#111111` card; `#808080` is 4.78:1) |
| 500 | `--sf-n500` (new) | `oklch(55.6% 0 none)` | `#a3a3a3` |
| 700 | `--sf-n700` (new) | `oklch(37.1% 0 none)` | `#d4d4d4` |
| 800 | `--sf-n800` (new) | `oklch(26.9% 0 none)` | `#e5e5e5` |

`white` is NOT remapped (`text-white` stays white on coloured pills). `bg-white` is re-pointed per selector (`bg-surface`, or `var(--sf-surface, #fff)` for a literal), never globally. `bg-neutral-200` used as a background is the border token (`#262626` in dark): fine on black.

**The image-frame rule (decision Q4: a product picture sits on a LIGHT frame for now).** A new token `--sf-image-frame` (utility `bg-image-frame`) has the SAME value in light and in dark mode (`oklch(97% 0 none)`). The five frames that used `bg-neutral-100` use it, so the scale remap does not turn them dark: `.sf-card-image`, `.sf-gal-placeholder`, `.sf-gal-thumb`, `.sf-cart-line-img`, `.sf-summary-line-image img`. A disc or strip that sits on such a frame (the `.sf-card-sizes` strip, the `.sf-gal-next` disc) and the subject picture frame (`.sf-subject-pic`) keep their white: they carry the marker comment `dm-keeps-white` in the stylesheet (the ratchet skips them) and their TEXT must be pinned dark when their area is migrated (it follows `--sf-text`, which is white in dark mode).

**The global base.** `:focus-visible` uses `var(--sf-focus, #0369a1)`. In dark mode ONLY (nothing here matches in light) the base layer gives text controls (`input` except checkbox / radio / range / file / the buttons / hidden / color, `select`, `textarea`) the surface, the text colour and the strong border, a muted `::placeholder`, and an autofill that paints the surface and the text colour (an inset shadow: the standard trick). It is in the BASE layer, so a component rule that sets its own look still wins. `::selection` and scrollbars are left alone.

**The shell selectors** (the Areas table, area `2a`): the desktop drop-down menu, the header menu panel, the header search input and the drawer search input, the mobile drawer panel, the mega / sub menu and the nested dropdown, and the footer newsletter input use `bg-surface` / `var(--sf-surface, #fff)`; the drawer scrim uses `var(--sf-overlay, rgba(0, 0, 0, 0.45))`; the header's `#dbdbdb` hairline uses the new `--sf-header-line` (dark `#262626`); the footer input's `#171717` ink uses the new `--sf-field-text` (dark `var(--sf-text)`); the shared checkout / account controls (`.sf-field input`, `.sf-field select`, `.sf-kind-select`) use `bg-surface`. Shadows: a dark-only, UNLAYERED rule at the end of the stylesheet removes the shadow of the shell panels (each already has its own 1px `--sf-border` line), gives the drawer a 1px edge, and draws a select's chevron light grey. The header, footer and banner already follow `--sf-header-*`, `--sf-footer-*`, `--sf-banner-*` (no raw colour left); the merchant's zone colours (`--sf-hdr-*`, `--sf-ft*`, `--sf-chdr-*`) are untouched.

**New tokens in DM-2a** (each is justified by what it would break if it reused an existing one): `--sf-n400`, `--sf-n500`, `--sf-n700`, `--sf-n800` (the scale has no other home); `--sf-image-frame` (a frame must NOT follow the surface); `--sf-header-line` (the header hairline `#dbdbdb` is not `--sf-border`, whose light default is `#e5e5e5`); `--sf-field-text` (a field's `#171717` ink is not the merchant's text colour: `--sf-text` would change light for a shop that set it).

**Guards.** `DarkModeRatchetTest`: the count of raw-white backgrounds may not grow (lower it in every DM-2 step; it must reach the allow-list: the cookie dialog until DM-4, and the `dm-keeps-white` lines). `DarkPaletteContrastTest`: every text token ≥ 4.5:1 on the page and on the cards, the status / footer / banner / button pairs, and the focus ring and the strong border ≥ 3:1, all computed with `Contrast::ratio` from the shipped values.

## Areas

Where every raw colour of `storefront.css` belongs (the hits of inventory A1/A2: `bg-white`, literal white backgrounds, the unmapped neutral steps, the hard-coded focus / error colours, drop shadows, scrims, status utilities, other raw hex without a variable fallback). `Line` is the line in the current file. A rule shared by several areas is put in **2a**. **Rows: 2a 18, 2b 35, 2c 20, 2d 80, 2e 24, left for DM-4 18.** A row marked "done" is finished. The scale remap (the neutral steps) is done everywhere and flips by itself, so for the other areas such a row needs only a look; what remains there are the white cards, the status boxes, the error rings and the scrims.

| Area | Selector | Line | What it is | Needs | Status |
|---|---|---|---|---|---|
| 2a | `:focus-visible` | 148 | global focus ring | `--sf-focus` (`var(--sf-focus, #0369a1)`) | done |
| 2a | `.sf-header` | 192 | header bar | `--sf-header-line` (new; the `#dbdbdb` hairline) | done |
| 2a | `.sf-nav > .sf-nav-inner > .sf-menu > .sf-menu-item > .sf-men` | 2719 | desktop menu dropdown | `bg-surface`; dark: no shadow; the 1px border stays | done (dark-only rule) |
| 2a | `.sf-field label` | 3045 | field label | scale remap (done in 2a, flips by itself) | done |
| 2a | `.sf-field input[type='text'], .sf-field input[type='email'],` | 3054 | text control (shared by checkout and account) | `bg-surface`; scale remap (done in 2a, flips by itself) | done |
| 2a | `.sf-hdr-menu-panel` | 4694 | header menu dropdown panel | `var(--sf-surface, #fff)` (or keep white) | done |
| 2a | `.sf-hdr-menu-panel` | 4695 | header menu dropdown panel | dark: no shadow; the 1px border stays | done (dark-only rule) |
| 2a | `html.js .sf-hdr .sf-search-row` | 4749 | header search row | dark: no shadow; the 1px border stays | done (dark-only rule) |
| 2a | `.sf-search-input` | 4784 | header search input | `var(--sf-surface, #fff)` (or keep white) | done |
| 2a | `.sf-drawer-overlay` | 4818 | mobile drawer scrim | `--sf-overlay` (or a scrim of its own) | done |
| 2a | `.sf-drawer-panel` | 4835 | mobile menu drawer | `var(--sf-surface, #fff)` (or keep white) | done |
| 2a | `.sf-drawer-search-input` | 4882 | drawer search input | `var(--sf-surface, #fff)` (or keep white) | done |
| 2a | `.sf-sub` | 5163 | mega/sub menu panel | `var(--sf-surface, #fff)` (or keep white) | done |
| 2a | `.sf-sub` | 5165 | mega/sub menu panel | dark: no shadow; the 1px border stays | done (dark-only rule) |
| 2a | `.sf-sub-dropdown .sf-menu .sf-menu` | 5310 | nested dropdown | `var(--sf-surface, #fff)` (or keep white) | done |
| 2a | `.sf-sub-dropdown .sf-menu .sf-menu` | 5311 | nested dropdown | dark: no shadow; the 1px border stays | done (dark-only rule) |
| 2a | `.sf-ft-input` | 5586 | footer newsletter input | `var(--sf-surface, #fff)` (or keep white) | done |
| 2a | `.sf-ft-input` | 5587 | footer newsletter input | `--sf-field-text` (new; the `#171717` ink) | done |
| 2b | `.sf-empty` | 294 | empty-state box | `bg-surface`; scale remap (done in 2a, flips by itself) | → 2b |
| 2b | `.sf-breadcrumbs` | 301 | breadcrumb trail | scale remap (done in 2a, flips by itself) | → 2b |
| 2b | `.sf-breadcrumb + .sf-breadcrumb::before` | 315 | breadcrumb trail | scale remap (done in 2a, flips by itself) | → 2b |
| 2b | `.sf-subcategory > a` | 348 | subcategory chip | `bg-surface`; scale remap (done in 2a, flips by itself) | → 2b |
| 2b | `.sf-card` | 368 | product card | `bg-surface`; dark: no shadow; the 1px border stays | → 2b |
| 2b | `.sf-card-image` | 383 | card picture frame | **frame**: `bg-image-frame` (same light value in dark) | done (frame) |
| 2b | `.sf-card-image > .sf-card-sizes` | 439 | size strip over a card picture | **keeps white** (a disc/strip over a light picture); its text must be pinned dark when it is migrated | → 2b |
| 2b | `.sf-card-sizes li.is-sold-out` | 449 | sold-out size | scale remap (done in 2a, flips by itself) | → 2b |
| 2b | `.sf-price-regular` | 593 | price | scale remap (done in 2a, flips by itself) | → 2b |
| 2b | `.sf-stock-in` | 615 | stock text | `--sf-status-*` tokens | → 2b |
| 2b | `.sf-stock-out` | 619 | stock text | scale remap (done in 2a, flips by itself) | → 2b |
| 2b | `.sf-pagination-status` | 758 | pagination | scale remap (done in 2a, flips by itself) | → 2b |
| 2b | `.sf-pagination-page > a, .sf-pagination-page > span` | 767 | pagination | scale remap (done in 2a, flips by itself) | → 2b |
| 2b | `.sf-sortdd-list` | 876 | sort dropdown | `bg-surface` | → 2b |
| 2b | `.sf-bctl-panel` | 956 | sort/filter bar panel | `bg-surface` | → 2b |
| 2b | `.sf-bv-list::before` | 1032 | filter chip / dot | `var(--sf-surface, #fff)` (or keep white) | → 2b |
| 2b | `.sf-bv-list.sf-bv-on::after` | 1047 | filter chip / dot | `var(--sf-surface, #fff)` (or keep white) | → 2b |
| 2b | `.sf-bv-dot.sf-bv-on` | 1066 | filter chip / dot | `var(--sf-surface, #fff)` (or keep white) | → 2b |
| 2b | `.sf-fv-mark` | 1184 | filter value mark / swatch | `var(--sf-surface, #fff)` (or keep white) | → 2b |
| 2b | `.sf-fv-input:checked + .sf-fv-mark` | 1194 | filter value mark / swatch | `var(--sf-surface, #fff)` (or keep white) | → 2b |
| 2b | `.sf-fv-circle` | 1220 | filter value mark / swatch | `--sf-overlay` (or a scrim of its own) | → 2b |
| 2b | `.sf-fv-input:checked + .sf-fv-circle` | 1224 | filter value mark / swatch | `var(--sf-surface, #fff)` (or keep white); `--sf-overlay` (or a scrim of its own) | → 2b |
| 2b | `.sf-fbtn-outline` | 1247 | «Filter» button | `var(--sf-surface, #fff)` (or keep white) | → 2b |
| 2b | `.sf-fdrawer-overlay` | 1345 | filter drawer scrim | `--sf-overlay` (or a scrim of its own) | → 2b |
| 2b | `.sf-fdrawer-panel` | 1355 | filter drawer | `bg-surface` | → 2b |
| 2b | `.sf-fgroup[open]` | 1451 | filter group dropdown | `bg-surface` | → 2b |
| 2b | `.sf-fgroup[open] > .sf-fgroup-summary` | 1464 | filter group dropdown | `var(--sf-surface, #fff)` (or keep white) | → 2b |
| 2b | `.sf-fdrawer-foot` | 1527 | filter drawer foot | `var(--sf-surface, #fff)` (or keep white) | → 2b |
| 2b | `.sf-bv-chip, .sf-fv-chip .sf-fv-text` | 1617 | filter chip / dot | `var(--sf-surface, #fff)` (or keep white) | → 2b |
| 2b | `.sf-pagination-ellipsis` | 1671 | pagination | scale remap (done in 2a, flips by itself) | → 2b |
| 2b | `.sf-pagination-prev, .sf-pagination-next` | 1684 | pagination | scale remap (done in 2a, flips by itself) | → 2b |
| 2b | `.sf-intro-text` | 2947 | category intro text | scale remap (done in 2a, flips by itself) | → 2b |
| 2b | `.sf-category-tile-link` | 2963 | category tile | `bg-surface`; scale remap (done in 2a, flips by itself) | → 2b |
| 2b | `.sf-category-tile-link:hover, .sf-category-tile-link:focus-v` | 3762 | category tile | dark: no shadow; the 1px border stays | → 2b |
| 2b | `.sf-subject-pic` | 6638 | subject (brand) picture frame | **keeps white** (a disc/strip over a light picture); its text must be pinned dark when it is migrated | → 2b |
| 2c | `.sf-gal-placeholder` | 1751 | «no image» gallery frame | **frame**: `bg-image-frame` (same light value in dark) | done (frame) |
| 2c | `.sf-gal-next` | 1795 | gallery arrow disc | **keeps white** (a disc/strip over a light picture); its text must be pinned dark when it is migrated | → 2c |
| 2c | `.sf-gal-thumb` | 1804 | gallery thumbnail frame | **frame**: `bg-image-frame` (same light value in dark) | done (frame) |
| 2c | `.sf-lightbox::backdrop` | 1951 | picture lightbox | `--sf-overlay` (or a scrim of its own) | → 2c |
| 2c | `.sf-lightbox-close, .sf-lightbox-prev, .sf-lightbox-next` | 1967 | picture lightbox | `--sf-overlay` (or a scrim of its own) | → 2c |
| 2c | `.sf-lightbox-count` | 1988 | picture lightbox | `--sf-overlay` (or a scrim of its own) | → 2c |
| 2c | `.sf-lightbox :focus-visible` | 1992 | picture lightbox | `var(--sf-surface, #fff)` (or keep white) | → 2c |
| 2c | `.sf-variation-label` | 2118 | variation select/label | scale remap (done in 2a, flips by itself) | → 2c |
| 2c | `.sf-variation-select` | 2122 | variation select/label | `bg-surface`; scale remap (done in 2a, flips by itself) | → 2c |
| 2c | `.sf-add-to-cart:disabled` | 2134 | disabled buy button | none (border token; #262626 in dark) | → 2c |
| 2c | `.sf-picker-select` | 2194 | variation select | `bg-surface` | → 2c |
| 2c | `.sf-swatch-dot` | 2205 | swatch | `--sf-overlay` (or a scrim of its own) | → 2c |
| 2c | `.sf-swatch[aria-disabled='true']::after` | 2260 | swatch | token or keep | → 2c |
| 2c | `.sf-picker.is-missing .sf-picker-label` | 2265 | «pick a value» error | `--sf-focus` / `--sf-error` | → 2c |
| 2c | `.sf-picker.is-missing .sf-picker-values` | 2269 | «pick a value» error | `--sf-focus` / `--sf-error` | → 2c |
| 2c | `:is(.sf-description, .sf-psec-body, .sf-ptab-panel) :where(p` | 2371 | product description text | scale remap (done in 2a, flips by itself) | → 2c |
| 2c | `:is(.sf-description, .sf-psec-body, .sf-ptab-panel) :where(a` | 2395 | product description text | `--sf-status-*` tokens | → 2c |
| 2c | `.sf-table-wrap` | 2420 | description table | `--sf-overlay` (or a scrim of its own) | → 2c |
| 2c | `.sf-meas-list > div` | 2486 | measurements list | token or keep | → 2c |
| 2c | `.sf-meas-list > div:first-child` | 2490 | measurements list | token or keep | → 2c |
| 2d | `.sf-cart-status` | 2747 | cart status line | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-cart-status-link` | 2753 | cart status line | `--sf-status-*` tokens | → 2d |
| 2d | `.sf-cart-noscript` | 2770 | no-JS notice | `--sf-status-*` tokens | → 2d |
| 2d | `.sf-cart-lines` | 2774 | cart lines box | `bg-surface` | → 2d |
| 2d | `.sf-cart-line-unavailable` | 2795 | cart line | `--sf-status-*` tokens | → 2d |
| 2d | `.sf-cart-line-note` | 2799 | cart line | `--sf-status-*` tokens | → 2d |
| 2d | `.sf-cart-qty, .sf-cart-promo-input` | 2812 | cart quantity input | `bg-surface`; scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-cart-remove, .sf-cart-promo-remove` | 2825 | remove button | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-cart-promo` | 2829 | cart promo box | `bg-surface` | → 2d |
| 2d | `.sf-cart-promo-applied` | 2845 | cart promo box | `--sf-status-*` tokens | → 2d |
| 2d | `.sf-promo-list-summary` | 2854 | promo list | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-promo-list-item` | 2862 | promo list | `bg-surface` | → 2d |
| 2d | `.sf-promo-list-text` | 2874 | promo list | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-promo-list-hint` | 2878 | promo list | `--sf-status-*` tokens | → 2d |
| 2d | `.sf-promo-list-unavailable .sf-promo-list-code, .sf-promo-li` | 2883 | promo list | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-promo-list-badge` | 2894 | promo list | `--sf-status-*` tokens | → 2d |
| 2d | `.sf-cart-promo-invalid` | 2898 | cart promo box | `--sf-status-*` tokens | → 2d |
| 2d | `.sf-cart-totals` | 2902 | cart totals box | `bg-surface` | → 2d |
| 2d | `.sf-cart-shipping-hint` | 2914 | shipping hint | `--sf-status-*` tokens | → 2d |
| 2d | `.sf-cart-checkout:disabled` | 2926 | disabled checkout button | none (border token; #262626 in dark) | → 2d |
| 2d | `.sf-cart-empty` | 2938 | empty cart | `bg-surface`; scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-cart-line-img` | 2993 | cart-line picture frame | **frame**: `bg-image-frame` (same light value in dark) | done (frame) |
| 2d | `.sf-cart-line-link` | 2997 | cart line | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-fieldset` | 3028 | checkout section card | `bg-surface` | → 2d |
| 2d | `.sf-checkout-form input[type='text']:focus-visible, .sf-chec` | 3072 | checkbox label | `--sf-focus` / `--sf-error` | → 2d |
| 2d | `.sf-checkout-form input[type='text']:focus-visible, .sf-chec` | 3074 | checkbox label | `--sf-focus` / `--sf-error` | → 2d |
| 2d | `.sf-field input[aria-invalid='true'], .sf-field select[aria-` | 3079 | invalid control | `--sf-status-*` tokens | → 2d |
| 2d | `.sf-checkout-form [aria-invalid='true']:focus-visible` | 3083 | checkbox label | `--sf-focus` / `--sf-error` | → 2d |
| 2d | `.sf-checkout-form [aria-invalid='true']:focus-visible` | 3084 | checkbox label | `--sf-focus` / `--sf-error` | → 2d |
| 2d | `.sf-field-error` | 3092 | field error text | `--sf-status-*` tokens | → 2d |
| 2d | `.sf-error-summary` | 3108 | form error summary | `--sf-status-*` tokens | → 2d |
| 2d | `.sf-error-summary:focus` | 3112 | form error summary | `--sf-focus` / `--sf-error`; token or keep | → 2d |
| 2d | `.sf-delivery-step-title` | 3133 | delivery step title | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-link-button` | 3146 | link-looking button | `--sf-status-*` tokens | → 2d |
| 2d | `.sf-login-hint, .sf-order-as` | 3151 | login hint | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-shipping-hint` | 3180 | shipping hint | `--sf-status-*` tokens | → 2d |
| 2d | `.sf-required` | 3191 | required star | `--sf-status-*` tokens | → 2d |
| 2d | `.sf-float:has(> :focus) > label, .sf-float:has(> input:not(:` | 3262 | floating label | token or keep | → 2d |
| 2d | `.sf-float:has(> :autofill) > label` | 3267 | floating label | token or keep | → 2d |
| 2d | `.sf-float:has(> :-webkit-autofill) > label` | 3272 | floating label | token or keep | → 2d |
| 2d | `.sf-float-up:not(:has(> select:not([hidden]))) > label` | 3283 | floating label | token or keep | → 2d |
| 2d | `.sf-float:has(> [aria-invalid='true']) > label` | 3292 | floating label | `--sf-focus` / `--sf-error` | → 2d |
| 2d | `.sf-check label` | 3313 | checkbox label | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-invoice-type-title` | 3335 | invoice option | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-invoice-type-option` | 3343 | invoice option | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-invoice-copy` | 3347 | invoice option | `bg-surface`; scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-express` | 3351 | express checkout box | `bg-surface` | → 2d |
| 2d | `.sf-express-divider` | 3356 | express checkout box | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-express-divider::before, .sf-express-divider::after` | 3362 | express checkout box | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-promo-compact-label` | 3396 | compact promo form | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-promo-compact-input` | 3404 | compact promo form | `bg-surface`; scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-promo-compact-field:focus-within` | 3413 | compact promo form | `--sf-focus` / `--sf-error` | → 2d |
| 2d | `.sf-note-field textarea` | 3427 | order-note field | `bg-surface`; scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-note-field textarea[aria-invalid='true']` | 3431 | order-note field | `--sf-status-*` tokens | → 2d |
| 2d | `.sf-note-counter[data-state='warning']` | 3439 | order-note field | `--sf-status-*` tokens | → 2d |
| 2d | `.sf-cart-status[data-state='price-changed']` | 3443 | cart status line | `--sf-status-*` tokens | → 2d |
| 2d | `.sf-place-order:disabled` | 3459 | disabled place-order button | none (border token; #262626 in dark) | → 2d |
| 2d | `.sf-checkout-summary` | 3468 | checkbox label | `bg-surface` | → 2d |
| 2d | `.sf-summary-toggle` | 3477 | summary toggle | `bg-surface` | → 2d |
| 2d | `.sf-summary-toggle-sep` | 3485 | summary toggle | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-summary-line-image img` | 3503 | summary-line picture frame | **frame**: `bg-image-frame` (same light value in dark) | done (frame) |
| 2d | `.sf-received-facts` | 3528 | order-received box | `bg-surface` | → 2d |
| 2d | `.sf-received-lines` | 3544 | order-received box | `bg-surface` | → 2d |
| 2d | `.sf-received-destination` | 3572 | order-received box | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-received-note` | 3584 | order-received box | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-bank-details` | 3589 | bank-transfer instructions | `bg-surface` | → 2d |
| 2d | `.sf-bank-deadline` | 3609 | bank-transfer instructions | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-bank-instructions` | 3613 | bank-transfer instructions | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-cod-note, .sf-received-email` | 3618 | cash-on-delivery note | scale remap (done in 2a, flips by itself) | → 2d |
| 2d | `.sf-cart-stepper` | 3867 | quantity stepper | token or keep | → 2d |
| 2d | `.sf-cart-stepper` | 3869 | quantity stepper | `var(--sf-surface, #fff)` (or keep white) | → 2d |
| 2d | `.sf-cart-step` | 3893 | quantity stepper | `var(--sf-surface, #fff)` (or keep white) | → 2d |
| 2d | `.sf-cart-step:hover:not(:disabled)` | 3900 | quantity stepper | token or keep | → 2d |
| 2d | `.sf-cart-summary` | 3924 | cart summary panel | `var(--sf-surface, #fff)` (or keep white) | → 2d |
| 2d | `.sf-cart-bar` | 3990 | cart sticky bottom bar | `var(--sf-surface, #fff)` (or keep white) | → 2d |
| 2d | `.sf-cart-empty-categories-title` | 4074 | empty cart | token or keep | → 2d |
| 2d | `.sf-cart-empty-categories a` | 4095 | empty cart | `var(--sf-surface, #fff)` (or keep white) | → 2d |
| 2d | `.sf-cart-empty-categories a` | 4097 | empty cart | token or keep | → 2d |
| 2d | `.sf-cart-empty-categories a:hover` | 4102 | empty cart | token or keep | → 2d |
| 2d | `.sf-checkout-summary[data-open='false'] .sf-summary-lines::a` | 4275 | checkbox label | `var(--sf-surface, #fff)` (or keep white) | → 2d |
| 2e | `.sf-account-status[data-state='info'], .sf-account-status[da` | 6809 | status box | `bg-surface` | → 2e |
| 2e | `.sf-account-status[data-state='error']` | 6813 | status box | `--sf-status-*` tokens | → 2e |
| 2e | `.sf-account .sf-field input[type='password'], .sf-account-ar` | 6818 | account password/e-mail control | `bg-surface`; scale remap (done in 2a, flips by itself) | → 2e |
| 2e | `.sf-account .sf-field input[type='password']:focus-visible, ` | 6825 | account password/e-mail control | `--sf-focus` / `--sf-error` | → 2e |
| 2e | `.sf-account .sf-field input[type='password']:focus-visible, ` | 6827 | account password/e-mail control | `--sf-focus` / `--sf-error` | → 2e |
| 2e | `.sf-account .sf-field input[type='password'][aria-invalid='t` | 6832 | account password/e-mail control | `--sf-status-*` tokens | → 2e |
| 2e | `.sf-account-toggle:focus-visible` | 6845 | show/hide password toggle | `--sf-focus` / `--sf-error` | → 2e |
| 2e | `.sf-account-secondary` | 6862 | secondary button | `bg-surface`; scale remap (done in 2a, flips by itself) | → 2e |
| 2e | `.sf-acc-menu-list` | 6884 | account menu list | `bg-surface` | → 2e |
| 2e | `.sf-acc-menu-link` | 6888 | account menu link | scale remap (done in 2a, flips by itself) | → 2e |
| 2e | `.sf-acc-menu-link[aria-current='page']` | 6892 | account menu link | scale remap (done in 2a, flips by itself) | → 2e |
| 2e | `.sf-acc-menu-link:focus-visible, .sf-acc-tile-link:focus-vis` | 6900 | account menu link | `--sf-focus` / `--sf-error` | → 2e |
| 2e | `.sf-acc-tile-link` | 6919 | dashboard tile | `bg-surface` | → 2e |
| 2e | `.sf-acc-tile-icon` | 6923 | dashboard tile | scale remap (done in 2a, flips by itself) | → 2e |
| 2e | `.sf-acc-orders thead th` | 6931 | orders table head | scale remap (done in 2a, flips by itself) | → 2e |
| 2e | `.sf-acc-order-link` | 6947 | order link button | `bg-surface`; scale remap (done in 2a, flips by itself) | → 2e |
| 2e | `.sf-acc-order` | 6962 | an order card | `bg-surface` | → 2e |
| 2e | `.sf-acc-pager-link` | 6991 | account pager link | `bg-surface`; scale remap (done in 2a, flips by itself) | → 2e |
| 2e | `.sf-acc-address` | 7024 | a saved address card | `bg-surface`; scale remap (done in 2a, flips by itself) | → 2e |
| 2e | `.sf-acc-badge` | 7036 | the «default» pill | own token (the pill: white text on a dark pill) | → 2e |
| 2e | `.sf-acc-btn` | 7068 | an account button | `bg-surface`; scale remap (done in 2a, flips by itself) | → 2e |
| 2e | `.sf-acc-btn-danger` | 7072 | an account button | `--sf-status-*` tokens | → 2e |
| 2e | `.sf-acc-btn:focus-visible, .sf-acc-cancel:focus-visible` | 7081 | an account button | `--sf-focus` / `--sf-error` | → 2e |
| 2e | `.sf-acc-current` | 7118 | current-value line | scale remap (done in 2a, flips by itself) | → 2e |
| DM-4 | `.sf-badge-dark` | 5357 | menu badge default colour pair | token or keep | DM-4 |
| DM-4 | `.sf-badge-grey` | 5362 | menu badge default colour pair | token or keep | DM-4 |
| DM-4 | `.sf-pc-o-medium::before` | 5889 | HTML-block hero colour class (DM-4) | `--sf-overlay` (or a scrim of its own) | DM-4 |
| DM-4 | `.sf-pc-o-strong::before` | 5890 | HTML-block hero colour class (DM-4) | `--sf-overlay` (or a scrim of its own) | DM-4 |
| DM-4 | `.sf-pc-bc-light` | 5939 | HTML-block hero colour class (DM-4) | `var(--sf-surface, #fff)` (or keep white) | DM-4 |
| DM-4 | `.sf-pc-c-light` | 5946 | HTML-block hero colour class (DM-4) | `var(--sf-surface, #fff)` (or keep white) | DM-4 |
| DM-4 | `.sf-car-arrow` | 6230 | carousel arrow disc | **keeps white** (a disc/strip over a light picture); its text must be pinned dark when it is migrated | DM-4 |
| DM-4 | `.sf-cc` | 7148 | cookie banner / dialog (DM-4) | allow-listed in the ratchet until DM-4 | DM-4 |
| DM-4 | `.sf-cc-center` | 7157 | cookie banner / dialog (DM-4) | allow-listed in the ratchet until DM-4 | DM-4 |
| DM-4 | `.sf-cc-dialog` | 7174 | cookie banner / dialog (DM-4) | allow-listed in the ratchet until DM-4 | DM-4 |
| DM-4 | `.sf-cc-bottom .sf-cc-dialog` | 7188 | cookie banner / dialog (DM-4) | allow-listed in the ratchet until DM-4 | DM-4 |
| DM-4 | `.sf-cc-text, .sf-cc-cat-text` | 7208 | cookie banner / dialog (DM-4) | allow-listed in the ratchet until DM-4 | DM-4 |
| DM-4 | `.sf-cc-policy` | 7212 | cookie banner / dialog (DM-4) | allow-listed in the ratchet until DM-4 | DM-4 |
| DM-4 | `.sf-cc-always` | 7306 | cookie banner / dialog (DM-4) | allow-listed in the ratchet until DM-4 | DM-4 |
| DM-4 | `.sf-cc-switch` | 7316 | cookie banner / dialog (DM-4) | allow-listed in the ratchet until DM-4 | DM-4 |
| DM-4 | `.sf-cc-switch` | 7318 | cookie banner / dialog (DM-4) | allow-listed in the ratchet until DM-4 | DM-4 |
| DM-4 | `.sf-cc-switch::after` | 7330 | cookie banner / dialog (DM-4) | allow-listed in the ratchet until DM-4 | DM-4 |
| DM-4 | `.sf-cc-switch::after` | 7331 | cookie banner / dialog (DM-4) | allow-listed in the ratchet until DM-4 | DM-4 |

Notes for the next steps: the `.sf-card-sizes` strip and the `.sf-gal-next` disc keep white (above). The status boxes (`bg-red-50 text-red-900 …`) go to the `--sf-status-*` tokens in **2d** (they also appear in 2c: `.sf-picker.is-missing`, and in 2e: `.sf-account-status`). The select chevron of the variation / picker selects (2c) and of the sort dropdown (2b) is the same data-URI as the shell's: draw it light in dark mode. The hero / HTML-block colour classes (`.sf-pc-*`), the cookie dialog and the menu badges' own colour pairs are DM-4.

## Cache

`mode` rides on the cached appearance payload; a flip of the mode or a change of the button colour goes through `AppearanceWriter::save()` → `StorefrontAppearance::refresh()` → surrogate key `all` (see `storefront-cache-contract.md` §3). The page is the same for every visitor and sets no cookie. The previous token file is kept for pages cached a moment earlier (one generation); see the open point below.

## Still missing

- **DM-2b..e** (DM-2a is done: the scale, the base and the shell) — move the remaining raw colours onto the tokens, area by area, using the Areas table: 2b the catalogue (cards, filters, sort, pagination, badges; lower the ratchet), 2c the product page (summary, selects, buy button, the `bg-neutral-900 text-white` pill, the lightbox), 2d cart + checkout + order-received (status boxes, error and focus rings), 2e the account area. Then remove the dev flag.
- **DM-3** — assets: `logo.dark` printed by the storefront, the image-frame rule, payment icons, badges.
- **DM-4** — merchant-colour components: HTML blocks, hero text colours, header/footer rows, the cookie banner dialog, admin warnings for clashes.
- **DM-5** — third-party surfaces (none exist today).
- Open: a page cached by Varnish before a second quick flip can outlive the one retained token file (`s-maxage` is 604800); a purge of `all` covers it, as for any token change. `UNVERIFIED` against the real Varnish.
