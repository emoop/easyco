# Site Settings Design

**Status:** v1.0 — design only; nothing in this document is implemented yet. Same framing as `media-domain-design.md`/`performance-and-channel-strategy.md`: records decisions and constraints before the corresponding code is written.

**Builds on:** the config-driven, fail-appropriately pattern established by `EasyCo\Pricing\DefaultCurrency`; `catalog-domain-design.md` §3.3's "smallest model that satisfies the actual need" principle.

**Relates to:** `media-domain-design.md` §2.2 (Hero Slider on/off toggle) and §2.3 (logo/favicon) — both explicitly deferred their storage to this concept rather than inventing their own. This document exists because a third, independent need (an admin-configurable aspect ratio for Media's admin-grid image tier) surfaced during Media pipeline work, making three confirmed consumers — enough to justify designing this properly rather than improvising it a third time.

---

## 1. Scope

A small, generic mechanism for **admin-editable, site-wide configuration values** that need to be changeable at runtime through the Admin UI — not through editing `.env`/config files and redeploying. This is distinct from this project's existing `config/services.php` convention: that convention is for values a *developer* sets per-environment; Site Settings is for values a *merchant* changes through the Admin UI without touching code or redeploying.

**Confirmed initial consumers** (none implemented yet — this document only designs the mechanism itself):
- Media's Hero Slider feature-wide on/off toggle (`media-domain-design.md` §2.2)
- Site logo/favicon (`media-domain-design.md` §2.3)
- Media's admin-grid image tier aspect ratio (surfaced during pipeline design, this session)

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
