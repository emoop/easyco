# Performance & Channel Strategy

**Status:** v1.0 — planning notes only; nothing in this document is implemented. It exists to record architectural constraints and decisions *before* the corresponding work starts, the same way `catalog-domain-design.md` §6 records deferred work as a conscious choice rather than an oversight.
**Builds on:** the domain/persistence isolation principle from `catalog-domain-design.md`/`pricing-domain-design.md` (domain classes never know about Laravel, a database, or a cache) and the SQLSTATE-based constraint-retry pattern from `catalog-domain-design.md` §7.

---

## 1. Performance & Caching Strategy (not yet implemented)

**Functional reference only, not architecture to copy** — same posture as `catalog-domain-design.md` §8's WooCommerce/Bagisto comparisons: **Aimeos** is worth studying for its storage-abstraction (manager/item) pattern and its aggressive content caching, including its explicit documentation warning against disabling the cache in production (a surprisingly common misconfiguration that quietly turns every page view into a full catalog query). Nothing here is copied wholesale; it's cited because it's a mature, real-world example of a modular commerce platform that took caching seriously from the start.

**Rule: caching belongs exclusively in the `Persistence/Eloquent` layer.** Repository implementations (`EloquentProductRepository`, a future `EloquentVariationRepository` cache wrapper, etc.) are the only place a cache may be introduced — reading through it, writing through it, invalidating it. `Product`, `Variation`, `Money`, `Price`, and every other domain class must never know a cache exists, the same way they never know Eloquent exists. A domain method must behave identically whether the repository backing it is cached or not; if it doesn't, the cache has leaked into a layer it doesn't belong in.

**Concurrency-safety model: the pattern already proven in this codebase, not a new one.** `catalog-domain-design.md` §7 documents the required shape for a unique-constraint collision — a real DB constraint as the source of truth, `QueryException::$errorInfo` (SQLSTATE + driver-specific code, checked for both MySQL and SQLite) as the detection mechanism, and an application-layer retry on top. This is already implemented twice (`attribute_signature`, and `sku`/`slug` via `isPossibleUniqueConstraintViolation()`). Any future write-contention scenario — the obvious one being a stock decrement in a future `Inventory` domain — must follow the same **optimistic, constraint-first** model: attempt the write, let the database's own constraint (a unique index, a `CHECK`, an atomic `UPDATE ... WHERE quantity >= ?`) be what actually prevents the bad outcome, and retry/react to the specific violation. **Never** a read-then-write check (`SELECT quantity`, then `if quantity > 0, UPDATE`) — that has exactly the TOCTOU race window `catalog-domain-design.md` §3.1 already explains in detail for the variation-signature case, and it doesn't get safer just because the resource being contended is stock instead of a combination.

**Redis, not APCu, for any future app-level cache.** APCu is per-process/per-server — behind a load balancer with more than one app server, each server would build and hold its own independent cache, so a write on server A never invalidates the stale entry sitting on server B. Redis is a shared, external store every app server reads/writes the same data from, which is a hard requirement the moment this application runs on more than one server — not a premature optimization.

---

## 2. Admin Interface (not yet implemented)

**Recommendation: Livewire + Alpine.js (the TALL stack), most likely via Filament**, as the current (2026) best fit for a Laravel-native admin panel. Reasoning: PHP-driven reactivity means the merchant-facing admin logic lives in the same language and, largely, the same mental model as the rest of this codebase; Alpine.js keeps the client-side JS surface area small and declarative rather than requiring a separate SPA framework; the ecosystem (Filament plugins, form/table builders) is large and actively maintained; and — the deciding factor over a decoupled SPA — it needs no separate API layer or JS build pipeline of its own, both of which would be ongoing maintenance surface this project doesn't otherwise need.

**A deliberate architectural warning, stated prominently because it will be easy to get wrong by default:** Filament, and most Laravel admin-panel tooling generally, defaults to operating **directly on Eloquent models** — a generated "create product" form will, out of the box, want to call `ProductModel::create([...])` itself. **This must not be allowed to bypass the domain layer.** An admin "create product" action must still go through `Product::createSimple()` (or whichever domain method applies) and the repository, exactly like `ProductController::store()` does today — never a raw Eloquent model write from inside a Filament resource. Skipping that path silently skips every invariant Catalog exists to enforce: base_sku/slug format and uniqueness, variation-axis validation, the archived-revival rule, all of it. This is flagged here so it gets designed deliberately — a thin domain-aware adapter between Filament's resource lifecycle and the existing repositories — the moment admin UI work actually starts, rather than discovered later as a production bug where an admin-created product turns out to have bypassed validation the API path always enforced.

---

## 3. Social Media & AI Agent Channel Strategy (honest baseline, mostly unimplemented)

**What a website owner actually gets from the Meta Pixel/Conversions API — stated plainly because it is widely misunderstood:** a site does **not** get access to a visitor's Facebook profile, their location, interests, or likes, through the Pixel or CAPI. The data flow runs the opposite direction: the site sends **its own observed behavioral data** (page views, add-to-cart, purchase events, IP address, browser/user-agent info) **to** Meta. What comes back is not raw profile data — it's *targeting capability*, delivered through Meta's own black-box model, as Custom Audiences and Lookalike Audiences. The site never sees who these people are; it only gets to say "show my ad to more people who resemble the ones who already converted."

**GDPR is a hard, explicit requirement here, stated prominently because this project operates in the EU:** the site owner is the **data controller** and is legally responsible for Pixel/CAPI compliance — not Meta. Concretely, the Pixel must not fire before explicit cookie consent. This has to be a **real architectural gate** in the eventual Channel/Marketing domain — a consent check that runs before any tracking hook fires, not a checkbox added to a cookie banner while the Pixel script tag sits unconditionally in the page `<head>` regardless. With honest EU consent banners (no dark patterns nudging acceptance), industry reporting suggests roughly **40–60% of visitors decline tracking** — so whatever channel/marketing domain eventually gets built must be designed assuming it will never have full-funnel visibility, not treat that gap as a bug to eventually fix.

**Explicitly deferred** (documented here so it's a conscious choice, not an oversight — same framing as `catalog-domain-design.md` §6): no code exists for any of the following yet.
- Meta Pixel / Conversions API integration itself.
- Consent management (the banner, the consent-state storage, and the gate described above).
- AI agent product-feed readiness (structured, machine-readable catalog export for AI shopping agents).
- Structured data / schema.org markup for AI and search-engine discoverability.

This section exists only to record the constraints — the GDPR consent gate, and domain-layer isolation from whatever admin/marketing tooling gets adopted — that must be respected whenever this work actually starts, exactly as §§1–2 do for their own areas.
