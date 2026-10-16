# Site Settings Design

**Status:** v1.2 — ~~still design-only until the first implementation prompt is written~~ — **done as of v1.2**: the mechanism itself (`site_settings` migration, `SiteSettingModel`, `EloquentSiteSettingsRepository`, `SiteSettingsServiceProvider`) is implemented and tested exactly as designed below. Its first real consumer — a merchant-changeable storefront locale (`site.locale`, BG/EN) — is now live too, via `ApplyStoreLocale` middleware and an admin panel settings page. The other three confirmed consumers (§1) remain unbuilt.

**Builds on:** the config-driven, fail-appropriately pattern established by `EasyCo\Pricing\DefaultCurrency`; `catalog-domain-design.md` §3.3's "smallest model that satisfies the actual need" principle.

**Relates to:** `media-domain-design.md` §2.2 (Hero Slider on/off toggle) and §2.3 (logo/favicon) — both explicitly deferred their storage to this concept rather than inventing their own. This document exists because a third, independent need (a store-wide, admin-configurable product image aspect ratio) surfaced during Media pipeline work, making three confirmed consumers — enough to justify designing this properly rather than improvising it a third time.

---

## 1. Scope

A small, generic mechanism for **admin-editable, site-wide configuration values** that need to be changeable at runtime through the Admin UI — not through editing `.env`/config files and redeploying. This is distinct from this project's existing `config/services.php` convention: that convention is for values a *developer* sets per-environment; Site Settings is for values a *merchant* changes through the Admin UI without touching code or redeploying.

**Confirmed initial consumers** (none implemented yet — this document only designs the mechanism itself):
- Media's Hero Slider feature-wide on/off toggle (`media-domain-design.md` §2.2)
- Site logo/favicon (`media-domain-design.md` §2.3)
- The store-wide product image aspect ratio — one of 1:1, 4:5, 3:4, 2:3 (`media-domain-design.md` §3.3), a layout hint for templates rather than a processing input
- Checkout's optional phone-call-required field visibility (`checkout-domain-design.md` §12.3) — the first non-Media consumer, confirming this is a genuinely reusable mechanism, not Media-specific config in disguise.

## 2. Prior art

Researched three established platforms to confirm this isn't a novel problem needing a novel solution:

- **WooCommerce:** `wp_options`, a flat key-value table — the simplest version of this pattern.
- **Bagisto:** `core_config` table, admin-saved values, with an explicit fallback chain: (1) database row if present, (2) merged Laravel config file, (3) a hardcoded default in the field's own definition array.
- **Aimeos:** settings stored as JSON in per-domain tables (e.g. `mshop_plugin.config`), admin-editable through the same data-access layer as everything else.

All three converge on the same shape: **a database-backed key-value store, with code-level defaults as a fallback when no row exists.** This document adopts that same shape, simplified to two layers (database row, or a code-level default) rather than Bagisto's three — this project's `config/services.php` convention already provides the "developer default" layer.

## 3. Not a domain package

**Decision:** this lives in the root application (`app/Settings/`), not as a new `EasyCo\*` domain package.

Every existing `EasyCo\*` package (`Catalog`, `Pricing`, `OperationalSales`, `Extensibility`, `Media`) exists because it owns real business logic — invariants, guarded transitions, a resolution algorithm, a hook-priority system. A generic key-value store has none of that: "store a value under a key, retrieve it later" carries no domain rules of its own. Even `Extensibility` — the closest precedent for "foundational, cross-cutting, not owned by one business domain" — still has real behavior (action/filter registration and priority ordering); this doesn't. Giving this the same `EasyCo\*` package treatment as a genuine domain would overstate what it is.

## 4. Storage shape

**One table, `site_settings`:**
- `key` (string, unique) — e.g. `media.hero_slider_enabled`, `media.admin_grid_aspect_ratio`. Dot-namespaced by convention (mirrors `config/services.php`'s own nesting), not enforced by any code-level validation — a plain string.
- `value` (text, nullable) — always stored as a plain string. A setting needing a compound/structured value (e.g. a list) is the *caller's* responsibility to JSON-encode/decode; this table has no opinion on that.
- timestamps.

No `type` column, no schema-level validation of what a given key's value *should* look like — the same reasoning WooCommerce/Bagisto/Aimeos all apply: the storage layer stays deliberately dumb, and whichever feature defines a given key (Media, in the three confirmed cases) owns the meaning and validation of that key's value.

## 5. Repository contract — deliberately thin

```php
interface SiteSettingsRepository
{
    public function get(string $key): ?string;
    public function set(string $key, string $value): void;
    public function forget(string $key): void;
}
```

**No built-in fallback-to-config logic inside this repository.** A generic settings mechanism can't know, per key, what an appropriate code-level default is or where it lives — that's specific knowledge belonging to whoever defines the key. The calling code is responsible for its own fallback:

```php
$enabled = $siteSettingsRepository->get('media.hero_slider_enabled')
    ?? config('services.media.hero_slider_enabled_default', 'true');
```

This keeps the mechanism itself fully generic and keeps "what's the sensible default for *this* setting" exactly where Bagisto/Aimeos/WooCommerce all put it too — with the feature, not with the storage layer.

## 6. No rich domain class

**Deliberate departure from this project's usual pattern** (e.g. `PriceList`, `MediaAsset`): no `SiteSetting.php` domain class wrapping a single key-value pair. There are no invariants to guard beyond "key is a non-empty string" (enforced trivially in the Eloquent repository's `set()`, not worth a dedicated domain entity) — mirrors `catalog-domain-design.md` §3.3's "smallest model that satisfies the actual need," applied here more aggressively than anywhere else in the project because the actual need is genuinely this small.

## 7. Migration

`site_settings`: `id`, `key` (string, unique), `value` (text, nullable), timestamps. Lives in the root app's `database/migrations/`, not a package (consistent with §3 — this isn't a package).

## 8. What this document does not cover

- The Admin UI screen(s) for editing these values — out of scope, same posture as every other domain design doc in this project (design the mechanism, not the UI).
- Per-environment or per-channel settings (e.g. a different hero-toggle value per storefront channel) — not a confirmed need yet; the three known consumers are all genuinely global, single-value settings. Revisit if that changes.
- Caching — a `site_settings` read on every request is a single indexed lookup by primary key; not a performance concern at this scale. Revisit only if evidence says otherwise (same "don't optimize speculatively" posture as `performance-and-channel-strategy.md`).

## The checkout form's keys (checkout form v2, C1d-B)

Owned by `App\Settings\CheckoutFormSettings`, which reads ALL of them (the two region keys of checkout RN-1 included) with ONE `getMany` call per page render and per checkout request:

| Key | Values | Default |
|---|---|---|
| `checkout.call_before_shipping_enabled` | `'1'` | absent = off |
| `checkout.newsletter_enabled` | `'1'` | absent = off |
| `checkout.terms_enabled` | `'0'` | absent = ON |
| `checkout.terms_url` | an http(s) URL or a path of the store (`/terms`), at most 2048 characters | none: the terms box is not shown |
| `checkout.terms_version` | a string, set automatically (today's date when the URL changes, or by the settings page's button) | none (the page and the API then use the word `unversioned`) |
| `checkout.privacy_url` | the same kind of link | none: the privacy notice is not shown |
| `checkout.terms_page_id` | the id of a content page (pages-design.md §5); when set it WINS over `checkout.terms_url`; the version is then `p{page_id}r{revision}` | absent = the address mode above |
| `checkout.privacy_page_id` | the same, for the privacy notice | absent = the address mode above |
| `content.drafts_seeded` | `'1'` once `ContentDraftsSeeder` ran (the two empty drafts are created once) | absent = not yet seeded |
| `checkout.region_default`, `checkout.region_countries` | see checkout-region-and-note-design.md | optional / none |
| `invoice.enabled` | `'0'` | absent = ON (the invoice box is offered) |
| `invoice.country_rules` | a JSON object `{ISO2: {tax_id_label, tax_id_required_for_company, tax_id_pattern, registration_number_label, registration_number_required, representative_label}}` (order-context-design.md 9.3); owner of validation: `App\Settings\InvoiceSettings` | absent = `{}`: no country has a rule, the generic rule applies (a company's tax id required, no format check) |
| `invoice.country_rules_version` | a counter, bumped by the settings page by itself whenever the rules change; recorded on an invoice row whose country has a rule | none |
| `privacy.consent_mode` | `strict` \| `notice` \| `off` (`App\Settings\OrderContextSettings`) | absent or unreadable = `strict` |
| `privacy.user_agent_max_length` | 1-255 | 255 |
| `privacy.ip_full_retention_days` | 0-3650; 0 = never keep the full client address | 30 |

The settings page (Settings > Checkout, `SETTINGS_MANAGE`) forgets a key that holds its default, like the region keys. The `invoice.*` and `privacy.*` keys above are in the same `CheckoutFormSettings::KEYS` list as the rest, so they cost no query of their own. A page id that points at a draft or at a page that no longer exists is no link and no error (no checkbox, no notice) — it does NOT fall back to the address.

### Storefront home page (stage S3-V)

| Key | Values | Absent means |
|---|---|---|
| `storefront.home.title` | plain text, at most 120 characters (`App\Settings\StorefrontHomeSettings`; the admin form enforces `PlainText` and the limit, the reader cuts at it) | the language default «Добре дошли в :shop» |
| `storefront.home.text` | plain multi-line text, at most 400 characters (`PlainMultilineText`) | the language default text |

Edited in Admin > Storefront (`content_manage`). Saving an empty field FORGETS its key. Both keys are read in one `getMany` together with `site.currency_symbol_position` (a key the home page reads anyway), so they cost the page no query.

### Appearance, banner and theme (stage D1)

Owned by `App\Settings\AppearanceSettings` (the keys, the defaults and the validation). A key that holds its default is FORGOTTEN, never stored.

| Key | Values | Absent means |
|---|---|---|
| `appearance.color.{background,text,muted,primary,on_primary,accent,border,header_bg,header_text,footer_bg,footer_text}` | `#RRGGBB` only | the default of storefront-customization-design.md 3.1 (footer text `#737373`) |
| `appearance.font.body` | `system` \| `inter` \| `manrope` \| `lora` | `system` |
| `appearance.font.heading` | the same list or `same` | `same` |
| `appearance.radius` | `none` \| `sm` \| `md` \| `lg` \| `pill` | `md` |
| `appearance.button` | `solid` \| `outline` | `solid` |
| `appearance.compiled` | JSON `{"hash": 12 hex, "fonts": [...], "body": key or null}` written by the compiler | nothing compiled: no link, the fallbacks of storefront.css |
| `banner.enabled` | `'1'` | off |
| `banner.text` | plain text, 1-160 characters | no banner |
| `banner.url` | a root-relative path or an `https://` address | no link |
| `banner.link_label` | plain text, at most 40 | the whole bar is the link |
| `banner.color.text` / `banner.color.bg` | `#RRGGBB` | `#ffffff` / `#171717` |
| `banner.starts_at` / `banner.ends_at` | `Y-m-d H:i` in the store time zone | no limit |
| `storefront.theme` | a theme folder under `resources/themes` (no UI in V1) | `easyco` |

All of them are read by `StorefrontAppearance` in ONE `getMany` and cached in the application cache (`storefront.appearance`); saving on the Appearance page replaces that entry.

### Header (stage D2)

Owned by `App\Settings\AppearanceSettings` (the keys) and `App\Storefront\Header\*` (the validation).

| Key | Values | Absent means |
|---|---|---|
| `header.layout.draft` | JSON layout, validated on save | no draft: the page starts from the published layout |
| `header.layout.published` | JSON layout, validated on publish | the seeded default layout |
| `header.compiled` | JSON `{"hash", "layout"}` written by `HeaderCompiler`, re-validated on every read | the seeded default layout |

`header.compiled` is part of `AppearanceSettings::READ_KEYS`: it is read in the same single `getMany` as the appearance, never with a query of its own.
