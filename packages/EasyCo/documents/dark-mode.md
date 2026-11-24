# Storefront dark mode (DM-1: the foundation)

Status: **DM-1 is done, behind a dev flag. No selector is migrated yet, so with the switch on the page is NOT presentable** (white cards with white text, white dropdowns, drawers, cart, checkout, account). That is expected and is fixed by DM-2. Background and the full inventory: `dark-mode-inventory.md`.

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

`--sf-bg #000000`, `--sf-surface #111111`, `--sf-surface-2 #1a1a1a`, `--sf-text #ffffff`, `--sf-muted #a3a3a3`, `--sf-border #262626`, `--sf-border-strong #404040`, `--sf-focus #38bdf8`, `--sf-error #f87171`, `--sf-accent #f87171` (the sale price colour; not in the DM-0 list: the light `#b91c1c` is too dark on black), `--sf-overlay rgb(0 0 0 / .7)`, `--sf-shadow transparent`, header follows `--sf-bg`/`--sf-text`, footer `#000000`/`#a3a3a3`, banner `#262626`/`#ffffff`, the four status groups `--sf-status-{error,warn,info,ok}-{bg,text,border}` as dark tints, `--sf-primary #f97316`, `--sf-on-primary #000000`, `--sf-primary-hover #fa8c3f`.
New tokens and their light fallbacks: `--sf-surface #ffffff`, `--sf-surface-2 #f5f5f5`, `--sf-border-strong #d4d4d4`, `--sf-focus #0369a1`, `--sf-error #b91c1c`, `--sf-overlay rgba(0,0,0,.45)`, `--sf-shadow rgba(0,0,0,.12)`, status groups = today's Tailwind pastels (`red-50/900/300`, `amber-50/900/300`, `sky-50/900/300`, `green-50/800/200`).

## Cache

`mode` rides on the cached appearance payload; a flip of the mode or a change of the button colour goes through `AppearanceWriter::save()` → `StorefrontAppearance::refresh()` → surrogate key `all` (see `storefront-cache-contract.md` §3). The page is the same for every visitor and sets no cookie. The previous token file is kept for pages cached a moment earlier (one generation); see the open point below.

## Still missing

- **DM-2** — move the raw colours onto the tokens, area by area: the shell (header, nav, dropdowns, footer, banner, drawers, focus rings, placeholder, autofill), the catalogue (cards, filters, sort, pagination, gallery, image frames, badges), the product page (summary, selects, buy button, the `bg-neutral-900 text-white` pill), cart + checkout + order-received (status boxes), the account area; a ratchet test against new `bg-white` / `#fff` backgrounds. Then remove the dev flag.
- **DM-3** — assets: `logo.dark` printed by the storefront, the image-frame rule, payment icons, badges.
- **DM-4** — merchant-colour components: HTML blocks, hero text colours, header/footer rows, the cookie banner dialog, admin warnings for clashes.
- **DM-5** — third-party surfaces (none exist today).
- Open: a page cached by Varnish before a second quick flip can outlive the one retained token file (`s-maxage` is 604800); a purge of `all` covers it, as for any token change. `UNVERIFIED` against the real Varnish.
