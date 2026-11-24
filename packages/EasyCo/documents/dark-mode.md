# Storefront dark mode (DM-1: the foundation, DM-2a: the neutral scale and the shell, DM-2b: the catalogue, DM-2c: the product page)

Status: **DM-1, DM-2a, DM-2b and DM-2c are done, behind a dev flag. The shell, the neutral scale, the catalogue and the product page (gallery, selects, swatches, description, tables, the lightbox) are migrated; the cart / checkout / order-received and the account area are NOT yet**, so with the switch on those pages still show white cards (with now light text on them) until DM-2d and DM-2e. Background and the full inventory: `dark-mode-inventory.md`.

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

## DM-2b: the catalogue

**Light mode is unchanged** (the same proof as DM-2a, on the built stylesheet: 2012 rules compared, none changed in effect; the two differences the comparison prints are artefacts, see the report: a shorthand's overridden width and an empty media block left by dropping a dark-only rule).

- **White cards and panels** (`bg-white` -> `bg-surface`): the product card, the subcategory chip, the empty state, the category tile, the sort dropdown list, the bar panel, the filter drawer panel and the open filter group. **Literal `#fff` fills** -> `var(--sf-surface, #fff)`: the checkbox/radio ground (`.sf-bv-list::before`, `.sf-fv-mark`), the chips, the outline «Filter» button, the filter group summary and the drawer foot. The scrim of the filter drawer -> `var(--sf-overlay, rgba(0, 0, 0, 0.45))`. The stock-in text -> `var(--sf-status-ok-text, <emerald-700>)`.
- **Classification of the white that is a mark or a ring** (`#fff` is not always a surface):
  - the CHECK MARK of a ticked list filter (`.sf-bv-list.sf-bv-on::after`) sits on a box that is filled with the TEXT colour (white in dark): the mark must be the page's own colour, so `var(--sf-surface, #fff)` (dark `#111111` on a white box). It is not `--sf-on-primary`: the box is not primary-coloured.
  - the radio/checkbox INNER RING of a ticked value (`.sf-fv-input:checked + .sf-fv-mark`, `inset 0 0 0 4px`): the same box, the same reason: `--sf-surface`.
  - the GAP RING between a colour swatch and its outer ring (`.sf-bv-dot.sf-bv-on`, `.sf-fv-input:checked + .sf-fv-circle`): it sits on the panel's surface, so `--sf-surface` (the gap shows the card, as in light it shows the white panel).
  - the swatch's own EDGE (`inset 0 0 0 1px rgb(0 0 0 / 0.12)`, a dark hairline that keeps a white swatch visible on white): a dark hairline is invisible on black, so it is a token: `--sf-swatch-edge` (light fallback = the old rgba; dark `rgb(255 255 255 / 0.28)`).
- **Kept white (`dm-keeps-white`)**: the size strip over a card picture, the carousel arrow disc (moved here from DM-4), the subject picture frame. The strip's text is PINNED dark: `li` uses `var(--sf-on-light, var(--color-neutral-900))` and the sold-out size `var(--sf-on-light-muted, var(--color-neutral-400))`, so light is the old value (the merchant's text colour) and dark is `#171717` / `#6b6b6b` (both ≥ 4.5:1 on white and on `#f5f5f5`, `DarkPaletteContrastTest`). The carousel arrow's glyph already is a literal dark (`oklch(20.5% 0 none)`), so nothing to pin; the gallery arrow (2c) will reuse the two `--sf-on-light*` tokens.
- **The listing cloud** (found in 2b, not in the table): a chip colour the merchant did NOT set fell back to a literal white while its text follows the page (white in dark) = invisible text. The unset fallbacks now go through `--sf-surface` (light value unchanged); a colour the merchant set is untouched.
- **Shadows**: the card hover shadow and the category tile's hover shadow are removed in dark by dark-only unlayered rules (both keep their 1px border); the filter drawer gets a 1px edge below 64rem (the breakpoint where it slides in). The sort list and the bar panel already have a `--sf-pdp-line` border and no shadow.
- **Chevrons**: the sort dropdown and the bar controls draw their arrow with borders in `currentColor`, so they follow the text; there is no data-URI chevron in the catalogue (the one at the «no image» placeholder is a mid-grey picture on the always-light frame). Nothing to change.
- **New tokens (3):** `--sf-on-light`, `--sf-on-light-muted` (the text on something that keeps a light ground), `--sf-swatch-edge`.

## DM-2c: the product page

**Light mode is unchanged** (the same proof as DM-2a/2b on the built stylesheet: 2013 rules compared, none changed). Re-pointed colours keep the old literal as the fallback; nothing in light mode defines the new tokens (the compiler emits only the 13 base tokens and the zone variables), so the fallback is always what applies.

- **The gallery arrow disc** keeps its white (`dm-keeps-white`); its glyph is pinned with `var(--sf-on-light, var(--color-neutral-900))` (before: `text-neutral-900`, the merchant's text colour, which is white in dark). The **play triangle** of a video thumbnail drew with `--sf-text` on the always-light frame (white on light grey in dark): it uses `var(--sf-on-light, var(--sf-text, …))`; found in 2c, not in the table.
- **Selects** (`.sf-variation-select`, `.sf-picker-select`): `bg-surface`. They have NO data-URI chevron (only the checkout / account `select` has, done in 2a): the native arrow follows `color-scheme: dark`, and the base layer gives the text its colour.
- **Swatches.** The swatch dot's inner edge uses `--sf-swatch-edge` (the same classification as the catalogue's colour circle). The ring around a SELECTED swatch (`0 0 0 3px var(--sf-bg, #fafafa)`) already was the page background token, so it follows the page: nothing to do. The diagonal cross over an unavailable swatch (`#737373`) is mid-grey on a half-transparent swatch: kept.
- **«Pick a value» error** (`.sf-picker.is-missing`): `var(--sf-error, #b91c1c)` (the hard-coded error red; the token's light fallback is the very same value). The `--sf-status-*` tokens are for boxes; this is a text and an outline colour.
- **Text links** in the description / accordion / tabs: new token `--sf-link` (light: Tailwind `sky-700` as before; dark `#38bdf8`, 9:1 on black; `sky-700` is 2.9:1). The cart's links (`sky-800`, 2d) will use it with their own fallback.
- **The inverted pill `bg-neutral-900 text-white`** is NOT on the product page: it is `.sf-acc-badge` (the account area's «default» pill, row `2e`), so it is left for DM-2e. Findings for it: in dark `bg-neutral-900` is the text token (white) and `text-white` stays white = white on white; it needs a fill/text pair of its own. The only other white-on-black pairs are the lightbox controls: fixed literals (`bg-black/60`, `text-white`), readable on the scrim and on the picture in both modes, so they were kept.
- **The lightbox**: the scrim `rgb(0 0 0 / .88)` is already dark in both modes; `--sf-overlay` would be LIGHTER in dark (`.7`) and `::backdrop` does not inherit custom properties in every browser, so it is kept. The white focus ring is on the dark scrim in both modes. The picture is never put on a dark frame.
- **Description tables and the measurements list**: dark-only rules (unlayered, at the end of the file): the scroll shadow of `.sf-table-wrap` is drawn light (a black shadow is invisible on black); the dashed hairlines of `.sf-meas-list` (`#ddd`) take `--sf-border`. The sticky first column already follows `--sf-bg`.
- **No shadow** on the product page needed removing: the gallery disc keeps its small shadow (it floats over a picture); no sticky bar, popover or zoom panel carries a colour of its own.
- **New token (1):** `--sf-link`. `--sf-on-light`, `--sf-on-light-muted` and `--sf-swatch-edge` (from 2b) are reused.

## Areas

Where every raw colour of `storefront.css` belongs (the hits of inventory A1/A2: `bg-white`, literal white backgrounds, the unmapped neutral steps, the hard-coded focus / error colours, drop shadows, scrims, status utilities, other raw hex without a variable fallback). `Line` is the line in the current file. A rule shared by several areas is put in **2a**. **Rows: 2a 18, 2b 39, 2c 21, 2d 80, 2e 24, left for DM-4 17.** A row marked "done" is finished. The scale remap (the neutral steps) is done everywhere and flips by itself, so for the other areas such a row needs only a look; what remains there are the white cards, the status boxes, the error rings and the scrims.

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
| 2b | `.sf-empty` | 294 | empty-state box | `bg-surface`; scale remap | done |
| 2b | `.sf-breadcrumbs` | 301 | breadcrumb trail | scale remap (done in 2a, flips by itself) | done (scale remap) |
| 2b | `.sf-breadcrumb + .sf-breadcrumb::before` | 315 | breadcrumb trail | scale remap (done in 2a, flips by itself) | done (scale remap) |
| 2b | `.sf-subcategory > a` | 348 | subcategory chip | `bg-surface`; scale remap | done |
| 2b | `.sf-card` | 368 | product card | `bg-surface`; dark: no hover shadow (the 1px border stays) | done (dark-only rule) |
| 2b | `.sf-card-image` | 383 | card picture frame | **frame**: `bg-image-frame` (same light value in dark) | done (frame) |
| 2b | `.sf-card-image > .sf-card-sizes` | 439 | size strip over a card picture | **keeps white**; its text pinned dark: `--sf-on-light` (old value as the light fallback) | done (kept white) |
| 2b | `.sf-card-sizes li.is-sold-out` | 449 | sold-out size | text pinned: `--sf-on-light-muted` (old value as the light fallback) | done |
| 2b | `.sf-price-regular` | 593 | price | scale remap (done in 2a, flips by itself) | done (scale remap) |
| 2b | `.sf-stock-in` | 615 | stock text | `--sf-status-ok-text` (old `emerald-700` as the fallback) | done |
| 2b | `.sf-stock-out` | 619 | stock text | scale remap (done in 2a, flips by itself) | done (scale remap) |
| 2b | `.sf-pagination-status` | 758 | pagination | scale remap (done in 2a, flips by itself) | done (scale remap) |
| 2b | `.sf-pagination-page > a, .sf-pagination-page > span` | 767 | pagination | scale remap (done in 2a, flips by itself) | done (scale remap) |
| 2b | `.sf-sortdd-list` | 876 | sort dropdown | `bg-surface` | done |
| 2b | `.sf-bctl-panel` | 956 | sort/filter bar panel | `bg-surface` | done |
| 2b | `.sf-bv-list::before` | 1032 | filter chip / dot | checkbox/radio ground and its mark/ring: `--sf-surface` (the mark sits on a text-coloured box; see below) | done |
| 2b | `.sf-bv-list.sf-bv-on::after` | 1047 | filter chip / dot | checkbox/radio ground and its mark/ring: `--sf-surface` (the mark sits on a text-coloured box; see below) | done |
| 2b | `.sf-bv-dot.sf-bv-on` | 1066 | filter chip / dot | checkbox/radio ground and its mark/ring: `--sf-surface` (the mark sits on a text-coloured box; see below) | done |
| 2b | `.sf-fv-mark` | 1184 | filter value mark / swatch | checkbox/radio ground and its mark/ring: `--sf-surface` (the mark sits on a text-coloured box; see below) | done |
| 2b | `.sf-fv-input:checked + .sf-fv-mark` | 1194 | filter value mark / swatch | checkbox/radio ground and its mark/ring: `--sf-surface` (the mark sits on a text-coloured box; see below) | done |
| 2b | `.sf-fv-circle` | 1220 | filter value mark / swatch | the swatch edge `--sf-swatch-edge` (light on black); the selected ring `--sf-surface` | done |
| 2b | `.sf-fv-input:checked + .sf-fv-circle` | 1224 | filter value mark / swatch | the swatch edge `--sf-swatch-edge` (light on black); the selected ring `--sf-surface` | done |
| 2b | `.sf-fbtn-outline` | 1247 | «Filter» button | `var(--sf-surface, #fff)` (or keep white) | done |
| 2b | `.sf-fdrawer-overlay` | 1345 | filter drawer scrim | `--sf-overlay` (or a scrim of its own) | done |
| 2b | `.sf-fdrawer-panel` | 1355 | filter drawer | `bg-surface`; dark: a 1px edge on a phone | done |
| 2b | `.sf-fgroup[open]` | 1451 | filter group dropdown | `bg-surface` | done |
| 2b | `.sf-fgroup[open] > .sf-fgroup-summary` | 1464 | filter group dropdown | `var(--sf-surface, #fff)` (or keep white) | done |
| 2b | `.sf-fdrawer-foot` | 1527 | filter drawer foot | `var(--sf-surface, #fff)` (or keep white) | done |
| 2b | `.sf-bv-chip, .sf-fv-chip .sf-fv-text` | 1617 | filter chip / dot | checkbox/radio ground and its mark/ring: `--sf-surface` (the mark sits on a text-coloured box; see below) | done |
| 2b | `.sf-pagination-ellipsis` | 1671 | pagination | scale remap (done in 2a, flips by itself) | done (scale remap) |
| 2b | `.sf-pagination-prev, .sf-pagination-next` | 1684 | pagination | scale remap (done in 2a, flips by itself) | done (scale remap) |
| 2b | `.sf-intro-text` | 2947 | category intro text | scale remap (done in 2a, flips by itself) | done (scale remap) |
| 2b | `.sf-category-tile-link` | 2963 | category tile | `bg-surface`; scale remap | done |
| 2b | `.sf-category-tile-link:hover, .sf-category-tile-link:focus-v` | 3762 | category tile | dark: no hover shadow (the 1px border stays) | done (dark-only rule) |
| 2b | `.sf-subject-pic` | 6638 | subject (brand) picture frame | **keeps white** (a picture frame, no text on it) | done (kept white) |
| 2c | `.sf-gal-placeholder` | 1751 | «no image» gallery frame | **frame**: `bg-image-frame` (same light value in dark) | done (frame) |
| 2c | `.sf-gal-next` | 1795 | gallery arrow disc | **keeps white** (a disc over the picture); the glyph is pinned dark: `--sf-on-light` (old value as the light fallback) | done (kept white) |
| 2c | `.sf-gal-thumb` | 1804 | gallery thumbnail frame | **frame**: `bg-image-frame` (same light value in dark) | done (frame) |
| 2c | `.sf-lightbox::backdrop` | 1951 | picture lightbox | **kept as is**: already a dark scrim in both modes (`.88`); `--sf-overlay` is lighter in dark (`.7`) and `::backdrop` does not inherit custom properties everywhere | done (kept) |
| 2c | `.sf-lightbox-close, .sf-lightbox-prev, .sf-lightbox-next` | 1967 | picture lightbox | **kept as is**: `bg-black/60` + `text-white` are fixed literals, readable on the scrim and on the picture in both modes | done (kept) |
| 2c | `.sf-lightbox-count` | 1988 | picture lightbox | **kept as is**: `bg-black/60` + `text-white` are fixed literals, readable on the scrim and on the picture in both modes | done (kept) |
| 2c | `.sf-lightbox :focus-visible` | 1992 | picture lightbox | **kept as is**: a white ring on a dark scrim in both modes | done (kept) |
| 2c | `.sf-variation-label` | 2118 | variation select/label | scale remap (done in 2a, flips by itself) | done (scale remap) |
| 2c | `.sf-variation-select` | 2122 | variation select/label | `bg-surface`; scale remap; the native arrow follows `color-scheme: dark` (no data-URI here) | done |
| 2c | `.sf-add-to-cart:disabled` | 2134 | disabled buy button | none (`bg-neutral-200` is the border token `#262626`, its text the muted token: readable) | done (looked at) |
| 2c | `.sf-picker-select` | 2194 | variation select | `bg-surface`; the native arrow follows `color-scheme: dark` | done |
| 2c | `.sf-swatch-dot` | 2205 | swatch | the swatch edge: `--sf-swatch-edge` | done |
| 2c | `.sf-swatch[aria-disabled='true']::after` | 2260 | swatch | **kept as is**: a mid-grey cross (`#737373`) over a half-transparent swatch, visible on any colour | done (kept) |
| 2c | `.sf-picker.is-missing .sf-picker-label` | 2265 | «pick a value» error | `--sf-error` (its light fallback is the same `#b91c1c`) | done |
| 2c | `.sf-picker.is-missing .sf-picker-values` | 2269 | «pick a value» error | `--sf-error` (its light fallback is the same `#b91c1c`) | done |
| 2c | `:is(.sf-description, .sf-psec-body, .sf-ptab-panel) :where(p` | 2371 | product description text | scale remap (done in 2a, flips by itself) | done (scale remap) |
| 2c | `:is(.sf-description, .sf-psec-body, .sf-ptab-panel) :where(a` | 2395 | product description text | `--sf-link` (light fallback: Tailwind `sky-700`, as before) | done |
| 2c | `.sf-table-wrap` | 2420 | description table | dark-only rule: the scroll shadow is drawn light (a black one is invisible on black) | done (dark-only rule) |
| 2c | `.sf-meas-list > div` | 2486 | measurements list | dark-only rule: the dashed hairline takes `--sf-border` | done (dark-only rule) |
| 2c | `.sf-meas-list > div:first-child` | 2490 | measurements list | dark-only rule: the dashed hairline takes `--sf-border` | done (dark-only rule) |
| 2c | `.sf-gal-thumb[data-gal-kind='video']::after` | 1832 | the play triangle on a video thumbnail | the triangle follows `--sf-text` (white in dark) on the always-light frame = invisible: now `var(--sf-on-light, var(--sf-text, …))` | done (found in 2c) |
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
| 2b | `.sf-car-arrow` | 6230 | carousel arrow disc | **keeps white** (a disc over pictures); its glyph is already pinned dark (`oklch(20.5% 0 none)`); marked `dm-keeps-white` | done (kept white) |
| 2b | `.sf-cloud-site .sf-bv-chip` | 1646 | listing cloud chip (a merchant zone colour) | unset: `var(--sf-cloud-bg, var(--sf-surface, #fff))`; a colour the merchant set is untouched | done (found in 2b) |
| 2b | `.sf-cloud-pill .sf-subcategory > a` | 1651 | listing cloud pill (a merchant zone colour) | unset: `var(--sf-cloud-bg, var(--sf-surface, var(--color-white)))` | done (found in 2b) |
| 2b | `.sf-cloud-site .sf-bv-chip:hover` / `.sf-cloud-pill … :hover` | 1660 | the cloud's colour swap on hover | the swap's second colour follows the same fallbacks | done (found in 2b) |
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

- **DM-2d..e** (DM-2a, 2b and 2c are done: the scale, the base, the shell, the catalogue and the product page) — 2d cart + checkout + order-received (the white cards, the status boxes onto `--sf-status-*`, the error and focus rings, the scrims, the cart text links onto `--sf-link`), 2e the account area (with the `bg-neutral-900 text-white` pill). Then remove the dev flag.
- **DM-3** — assets: `logo.dark` printed by the storefront, the image-frame rule, payment icons, badges.
- **DM-4** — merchant-colour components: HTML blocks, hero text colours, header/footer rows, the cookie banner dialog, admin warnings for clashes.
- **DM-5** — third-party surfaces (none exist today).
- Open: a page cached by Varnish before a second quick flip can outlive the one retained token file (`s-maxage` is 604800); a purge of `all` covers it, as for any token change. `UNVERIFIED` against the real Varnish.
