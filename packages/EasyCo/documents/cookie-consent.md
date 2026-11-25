# Cookie / ads consent banner (CC-1)

A consent popup for EU visitors. It asks for two things: **necessary cookies** (always on, informational) and **statistics and ads** (one switch). The visitor's choice is stored **in the browser only**. Nothing is recorded on the server in this package: recording the choice with the account or the order is **AUD-1**. CC-1 only makes the choice available to it (the cookie below and a small JS API).

There are no optional cookies or scripts on the storefront today. The banner exists now so the choice is collected before the pixel / analytics modules arrive.

## The invariant

**Before a choice, after «Отказвам» and after «×», nothing optional is set or loaded.** `storefront-consent.js` only reads and writes its own cookie. It loads no script, no pixel, no font, no third-party request. A module that wants to load something optional MUST ask the API (`EasyCoConsent.has('ads')`) or listen for `easyco:consent`, and stay silent while the answer is no. Tested: `CookieBannerRenderTest` (no third-party address in the markup, nothing inline), `tests/Browser/cc-1.mjs` (no third-party request and no cookie other than the necessary ones before a choice).

## The cookie

| | |
|---|---|
| Name | `easyco_consent` |
| Value | `v=<text_version>&ads=<0\|1>&t=<unix seconds>` |
| Attributes | `Path=/`, `SameSite=Lax`, `Secure` on https, **not** HttpOnly (JS reads it), `Max-Age` = `cookies.validity_months` × 30 days. First-party only. |
| Written | **only on a click**: «Приемам всички» → `ads=1`; «Отказвам» → `ads=0` (the same save as «Настройки → Запази избора» with every switch off); «Запази избора» → the switch state |
| Read as «no choice yet» | none, a damaged value (`ads` not 0/1, `t` not digits), or another `v` than the shop's current `text_version`. The banner shows and `ads` is **not** granted. |

`text_version` is bumped **by the server only** (`CookieSettings::save`), when any text (any language) or the picked policy page changes. It is NOT bumped for position, colour, view, validity or the on/off switch. The bump re-asks every visitor.

## The one category

One optional category, `ads` («Статистика и реклами»). The code is ready for a second one: `CookieSettings::CATEGORIES` (a list), the partial loops over the categories, the JS keeps `CATEGORIES` and a switch per `data-sf-cc-switch`. A second category = one entry in each list, its two texts, and one `key=` in the cookie (the value format is already `key=value` pairs; a bump of `text_version` re-asks everyone). Not built.

## The JS API

```js
window.EasyCoConsent = { get(), has(category), open(), onChange(fn) }
```

- `get()` → `{version, ads, time}` or `null` (no valid choice).
- `has('necessary')` → always `true`; `has('ads')` → `true` only with a valid choice that granted it.
- `open()` → opens the banner at layer 2 with the current choice (the same as the footer link).
- `onChange(fn)` → `fn({ads, changed})` on every choice.
- DOM event `easyco:consent` on `document`, `detail = {ads, changed}`: fired when a choice is made (`changed: true`) and **once on load when a valid choice already exists** (`changed: false`). It is NOT fired while there is no choice.

No script loader and no pixel are in this package; only the API.

## The look and the behaviour (decisions taken)

- **Equal buttons (CC-2: three).** The first layer has THREE buttons of ONE CSS class (`.sf-cc-btn`): «Приемам всички», «Отказвам», «Настройки»: same size, colour, font, weight. «Отказвам» replaces the old «Само необходими» (text key `reject`; the key `necessary_only` is gone). There is no «accent» option. The merchant picks ONE colour for both buttons, the switch's ON state and the focus ring. The text colour inside the buttons is **computed** (white or black, whichever contrasts more), never typed.
- **Nothing is pre-ticked.** The ads switch is OFF until chosen (or shows the stored state when reopened).
- **A close button «×» and Escape (CC-2).** A real `<button>` (accessible name = the text key `close`, a 44 px target) in the corner of the dialog; Escape (while the focus is inside the banner) does the same. It closes WITHOUT a choice: nothing is saved, no storage is written, no optional cookie is set. With no saved choice the banner is back on the NEXT page load, on every page, until the visitor chooses; with a saved choice it never opens by itself.
- **The policy page (CC-2).** On the page the banner links to (the picked policy page) a visitor with no choice does not get a dialog over the text: the script shows a thin NON-modal bar fixed at the bottom (the short text `bar_text`, the same three buttons and the «×»; no focus trap, no scroll lock, the page stays usable) and gives the page the bar's height as bottom padding. The server only prints the marker `data-policy-page="1"` on the banner root of THAT URL (the same for every visitor; the cookie is still never read), and the script picks the layout from it. «Настройки» in the bar opens the normal dialog.
- **Withdrawing is as easy as giving.** A link «Настройки за бисквитки» reopens the banner at layer 2, with the current choice and no «← Назад». The MAIN way is a menu item (below); the fixed strip is only the fallback.

### The menu item «Настройки за бисквитки» and the fallback strip (owner decision 2026-11-23)

- **A real menu item kind**, `consent` (`menu_items.type`; the CHECK `menu_items_type_check` names it: migration `2026_11_23_000003`). The merchant adds it in «Дизайн → Менюта» (panel «Настройки за бисквитки») to any menu: the footer menu a footer `menu` block shows (it prints top-level links only), the header menu, the drawer. Nothing is typed: the validator drops any label, url, badge or new-tab; it may stand wherever a link may (top level, a dropdown, a nested level, a mega column), never as a mega item's direct child and never with children.
- **The compiled menus** hold a placeholder (`consent: true`, url `/`) and only while `cookies.enabled`; saving the cookie page recompiles the menus (`MenuRecompiler::now()`), so a flip of the switch adds or removes the item. `FooterCompiler::topLevel` keeps the flag.
- **At read time** (`CookieMenuLink`, called from `StorefrontAppearance::menus()` and `::footer()`; no query): banner off -> the item is dropped; banner on -> the label is the banner's «footer link» text of the shop's language and the href is the policy page's URL, or `#` when no PUBLISHED policy page is picked (renamed / unpublished / deleted: the listener refreshes the payload). Every link renderer prints `data-consent-open` (`partials/menu`, `partials/menu-accordion` (two anchors), `footer/menu`, the old footer). Without JavaScript the link still leads to the policy page.
- **The script** opens layer 2 on a click on `[data-consent-open]` by event delegation (no inline handler); `preventDefault()`.
- **The fixed strip is the FALLBACK only.** It is printed while the banner is on and NO menu a guest sees holds the item: the scan is of what the layout is about to print (the header with its drawer, the footer's blocks or the old footer's menu: `StorefrontAppearance::forLayout()['cookieMenuLink']`, no extra query). A menu that exists but is attached to no header, drawer or footer does not count. The system has no «account menu» (the account is an icon and a drawer link, not a menu the merchant fills), so nothing about it counts.
- **The admin page** shows a visible warning (not an info icon) while the banner is on and the strip is used.
- **Two layers**, the merchant chooses the opening one. Layer 2 always has both equal buttons too. Position: centre (modal, dimmed, focus trapped, scroll locked, the page behind is `inert`) or bottom (a card, non-modal).
- **Colour validation (server, in the save):** the button colour must be at least **3:1** against the white dialog. The 4.5:1 rule for the text inside the buttons is implemented too, but it can never fire: white-or-black text on any colour reaches at least ≈4.58:1 (`ContrastTest::test_the_best_text_colour_always_reaches_4_5_to_1`). It stays as a guard in case the text colour ever becomes a free choice.
- Texts per shop language (`bg`, `en`): one JSON value per language `cookies.texts.<locale>`, closed key list, plain text, length limits, **every field required on save**. At render, per key: the stored text, else the shipped default in `lang/<locale>/cookies.php` (the banner is never empty). The storefront shows the texts of `site.locale`.
- The policy link is added automatically after the text, using the picked page's title. The link is derived (`cookies.policy_link`) and is kept true by `CookiePolicyListeners` (rename / unpublish / delete of that page, one purge). A draft or deleted page leaves **no link** and the admin page shows a visible warning.

## Cache rules (the hard constraint)

Storefront pages are sessionless and identical for every visitor. The banner is part of that identical HTML. **The server never reads `easyco_consent`** (no branch on it anywhere: tested byte-for-byte, with and without the cookie, and with damaged ones). Only the JS decides whether to show the banner.

- **Cache layers must ignore `easyco_consent`.** The Varnish VCL already runs `unset req.http.Cookie` on every cacheable request (`docker/varnish/rules.vcl.tpl`, step 5), so the cookie never reaches the cache key or the backend; a response carrying `Set-Cookie` is never cached. The P8c plan must keep that: **never `vary` on the cookie, never let it into a hash, never pass a request because of it.** CC-1 did not edit the VCL.
- The banner's data (the settings, the texts of both languages, the policy link) rides in the appearance payload (`StorefrontAppearance`, key `storefront.appearance`): **no query per storefront request**. The colour rides on the compiled token stylesheet (`--sf-cc-btn`, `--sf-cc-btn-text`), so there is no inline style. A cache entry from before this package (no `cookies` key) is rebuilt once.
- A save of the cookie page goes through the same refresh as a «Дизайн» publish (`AppearanceRecompiler::run`: stylesheet, payload, one purge of `all`). The `cookies.*` keys are storefront-visible, so `EloquentSiteSettingsRepository` also collects `all` on every write. The purge goes through `CachePurger`, never Varnish directly.
- The admin preview is a signed, no-store, noindex page with the REAL partial and no script (`/cookie-preview`); it never writes the cookie.

## Settings (all through `CookieSettings`)

`cookies.enabled` (default off), `cookies.position` (`center`\|`bottom`), `cookies.initial_view` (`summary`\|`details`), `cookies.validity_months` (1–13, default 12), `cookies.policy_page_id` (a published page, nullable), `cookies.policy_link` (derived), `cookies.button_color` (`#rrggbb`, default `#111111`), `cookies.texts.bg`, `cookies.texts.en`, `cookies.text_version`. Admin: «Поверителност → Бисквитки», `PRIVACY_MANAGE`.

## What AUD-1 reads

AUD-1 records the `ads` choice with the checkout / the account. It reads **in the browser, at the moment it sends**, from the cookie or the API: `ads` (0/1), `text_version` (`v`), and the time (`t`). Send them with the checkout request / the account call; never trust a client-supplied text, store the shop's own current version too. What exists on the order today (and CC-1 did not change): `orders.analytics_consent` (`granted`\|`denied`\|`unknown`) and `orders.consent_source` (`banner`\|`api`\|`not_asked`), written by `OrderContextWriter` as `unknown` / `not_asked`.

### What AUD-1 must decide

1. **`privacy.consent_mode` (`strict` \| `notice` \| `off`)** is an existing order-context setting (`OrderContextSettings`, default `strict`). CC-1 neither reads nor changes it. AUD-1 must decide how it relates to the banner choice: for example `strict` = a missing choice is `unknown` and counts as «no»; `notice` = the banner informs and an absent choice may count as a default; `off` = the banner is not shown (should `cookies.enabled` then be forced off, or are they independent?). Today they are independent.
2. **Which `ConsentSource` case the banner maps to:** `banner`. A choice made through the banner is `consent_source = banner`; one sent through the API without the banner is `api`; no choice is `not_asked`.
3. **Mapping the choice to `analytics_consent`:** `ads=1` → `granted`, `ads=0` → `denied`, no valid choice (or an old `v`) → `unknown`.
4. Whether to keep the choice with the **account** as well (so it follows a signed-in visitor across devices), and its retention.
5. Whether the **one** category stays one (statistics and ads together) after the specialist's reading; if it splits, the cookie gets a second key and `text_version` is bumped.
