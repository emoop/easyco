# Extensibility Design — Hooks

**Status:** v1.0 — initial design; the mechanism is implemented (`packages/EasyCo/Extensibility`) and proven end-to-end via one demo listener (see `vertical-slice-notes.md`).
**Builds on:** nothing — `easyco/extensibility` is a foundational, framework-agnostic package with no dependency on Catalog, Pricing, or Laravel in its core logic.
**Consumed by:** `app/` layer code only (controllers, listeners, other service providers) — see §2 for why domain packages themselves never touch it.

---

## 1. Why this exists

EasyCo's domain packages (Catalog, Pricing, and whatever follows) are deliberately closed for modification and open for extension: a merchant-specific rule, a plugin, or a future first-party feature should be able to hook into "a product's base_sku is about to be assigned" or "a price was just resolved" without editing `Product.php` or `PriceResolver`. That requires a runtime extension point mechanism, and Laravel doesn't ship one that fits: `Illuminate\Events` is a one-way, fire-and-forget pub/sub system — listeners react to an event, but nothing threads a return value through a chain of listeners and hands the final result back to the caller. There is no built-in "let N listeners each adjust this value, in order, and give me what's left" primitive in the framework.

**The actions/filters distinction, and the naming, is deliberately borrowed from WordPress** (and its `do_action`/`apply_filters`, which WooCommerce builds its own extensibility on top of) — not reinvented:

- **An action** (`doAction()` / `Hook::fire()`) is a notification: "this happened." Every registered callback runs, in order, for its side effects. Nothing is returned; nothing is expected to change what already happened.
- **A filter** (`applyFilters()` / `Hook::apply()`) is a transformation: "here is a value, adjust it if you care to." Each registered callback receives the current value (and, on the first call, the caller's original input) and returns what should become the new value for the *next* callback in the chain. The final callback's return value is what the original caller gets back.

This distinction is the entire reason the two methods exist as separate, differently-shaped operations rather than one generic "dispatch" call: mixing them (e.g. an event whose listeners *might* return something that *might* get used) is how frameworks end up with ambiguous, hard-to-reason-about extension points. Keeping them syntactically distinct means a developer reading `Hook::fire('order.placed', $order)` versus `Hook::apply('pricing.line_total', $total, $order)` already knows, without reading any listener, whether a return value matters.

---

## 2. The two-layer design, and the architectural boundary

```
EasyCo\Extensibility\HookRegistry     ← pure PHP, zero framework dependency
    │
    │  used by
    ▼
EasyCo\Extensibility\Hook             ← the ONLY class in this package that touches Laravel
    │                                    (resolves HookRegistry as a singleton via app())
    │  used by
    ▼
app/ layer code only                  ← controllers, listeners, other service providers
```

**Decision:** `HookRegistry` is a plain, instantiable PHP class with no framework imports at all — it could run under any PHP application, a CLI script, or a test, with no container involved. `Hook` is a thin static facade that resolves `HookRegistry` from Laravel's container and delegates every call to it. This mirrors exactly how `easyco/pricing` and `easyco/catalog` are structured: framework-agnostic domain logic (`Product`, `Variation`, `Price`, `Money`) with a thin, separately-named Laravel adapter layer (`EloquentProductRepository`, `PricingServiceProvider`) sitting on top, never mixed into the same class.

**The architectural boundary — and why it matters here specifically:** `catalog-domain-design.md` and `pricing-domain-design.md` both establish that their domain classes never import or depend on Laravel. That principle would be silently violated the moment `Product::createSimple()` called `Hook::apply(...)` internally — `Hook` touches the container, and the container is a Laravel concept. **Domain packages (Catalog, Pricing, and any future one) must never call `Hook::action()`/`Hook::filter()`/`Hook::fire()`/`Hook::apply()` directly, and must never depend on `EasyCo\Extensibility` at all.** Only `app/` layer code — the layer that is already allowed to know about Laravel — calls into the hook system. Concretely, today: `ProductController::store()` calls `Hook::apply('catalog.product.base_sku', ...)` *around* its call to `Product::createSimple()`, not `Product::createSimple()` calling it itself. If a domain package ever needs to *be* hookable from the inside (not just wrapped from the outside), the correct shape is to accept a `HookRegistry` instance as an explicit collaborator — the same way `EloquentProductRepository` depends on `Contracts\ProductRepository`, never the other way around — not to reach for the static `Hook` facade.

---

## 3. Naming convention and the Hook Reference

**Convention:** `{domain}.{entity}.{event|filter}` — lowercase, dot-separated, no verb tense ambiguity. `domain` is the owning bounded context (`catalog`, `pricing`, ...), `entity` is the aggregate/concept the hook is about, and the final segment names the specific moment or value being filtered. Prefer a name that reads correctly in both `Hook::action('catalog.product.created', ...)` ("when a catalog product is created") and `Hook::filter('catalog.product.base_sku', ...)` ("the catalog product's base_sku [is being filtered]") — i.e. the same pattern serves both kinds without needing a different grammar for each.

**Hook Reference** — every hook that exists anywhere in the codebase, kept current the same way WordPress's own hook reference docs are: **whenever a new `Hook::action()`/`Hook::filter()` call site or listener is added anywhere in the project, add a row here in the same commit.** This table is the single source of truth for "what can I hook into" — do not make a developer grep the codebase for `Hook::` to find out.

| Hook | Type | Fired from | Signature | Purpose |
|---|---|---|---|---|
| `catalog.product.base_sku` | Filter | `App\Http\Controllers\Api\ProductController::store()` | `(string $baseSku): string` | Lets a listener adjust/generate the merchant-supplied `base_sku` before it becomes the Product's identity. Currently has one demo listener (`App\Providers\DemoHooksServiceProvider`) proving the mechanism fires end-to-end through a real HTTP request — see `vertical-slice-notes.md` §6. **Not** the real SKU-generator feature, which remains deferred (`catalog-domain-design.md` §6). |
| `catalog.product.slug` | Filter | Not yet wired into any HTTP/application call site — currently invoked only directly (`Hook::apply('catalog.product.slug', $value, $name)`) from `tests/Feature/CatalogSlugGeneratorTest.php`. `ProductController::store()` does not yet collect or apply a slug at all (a known, separately-flagged gap). | `(string $value, string $name): string` | The **real, production-intended** slug generator (unlike the `base_sku` demo above) — one listener, `App\Providers\CatalogSlugGeneratorServiceProvider`. `$value` is either `""` (auto-generate from `$name`) or a merchant-typed candidate; `$name` is always the Product's name, passed as unchanged filter context. Lowercases with `mb_strtolower()`, replaces whitespace and anything outside `\p{Ll}\p{M}\d` with a hyphen, collapses/trims hyphens, falls back to `"product"` if that produces an empty string, then deduplicates via `ProductRepository::findBySlug()` with an incrementing `-1`, `-2`, ... suffix (bounded to 50 attempts). **Deliberately does NOT transliterate to ASCII** — a Cyrillic or Turkish name produces a Cyrillic/Turkish slug (`"Червена рокля"` → `"червена-рокля"`), matching WordPress's `sanitize_title()` philosophy. If ASCII transliteration is ever wanted, it must be a *separate*, independently-registered listener on this same hook at a different priority — never folded into this one. This listener's own dedup is best-effort only; `EloquentProductRepository::save()`'s own SQLSTATE/error-code-driven retry against the real `catalog_products_slug_unique` constraint is the authoritative, race-condition-safe guarantee. |

---

## 4. Error-handling policy

**Decision:** an exception thrown by any action or filter callback propagates immediately and is never caught, logged, or swallowed by `HookRegistry`. For an action, every callback after the throwing one for that `doAction()` call simply does not run. For a filter, the same — and critically, `applyFilters()` does **not** fall back to the pre-exception value; the caller gets the exception, not a partially-filtered (or unfiltered) result silently passed through as if nothing happened.

**Why this is the only acceptable behavior, not just the simplest one:** a hook system that swallows listener exceptions turns a bug in a third-party or merchant-specific listener into silent, undebuggable data corruption elsewhere in the system — e.g. a `catalog.product.base_sku` filter that throws would, under a swallow-and-continue policy, let the *original, unfiltered* `base_sku` through with no indication anything went wrong. Propagating means the failure surfaces exactly where it happened, with its real stack trace, the moment it happens — consistent with how the rest of EasyCo's domain layer treats invariant violations (see e.g. `catalog-domain-design.md`'s `LogicException`/`InvalidArgumentException` usage: fail loudly and immediately, never degrade silently). This is proven directly in `HookRegistryTest` (`test_an_exception_from_one_action_propagates_and_stops_subsequent_callbacks`, `test_an_exception_from_one_filter_propagates_immediately_without_falling_back`).

---

## 5. Deferred to a later version (documented, not accidental)

- **Priority-based removal of individual listeners** (WordPress's `remove_action`/`remove_filter`). `HookRegistry::clear()` only removes *everything* for a hook (or everything entirely) — there is no way to unregister one specific callback while leaving others in place. `clear()` exists specifically for test isolation, not as a general-purpose listener-management API.
- **Hook introspection/debugging tools** — no equivalent of WordPress's `has_action()`-with-callback-matching, no way to list what's registered on a given hook at runtime beyond the boolean `hasListeners()`, no admin UI or CLI command to inspect the current registry state.
- **Async/queued action dispatch** — `doAction()` calls every listener synchronously, inline, in the current request. There is no equivalent of dispatching an action's listeners onto a queue; a slow or failing listener blocks (and, per §4, can fail) the entire request that fired it.

None of these are gaps discovered after the fact — they are simply not needed yet by the one real use case this mechanism has proven itself against (§3's Hook Reference), and adding them now would be speculative generality ahead of an actual second consumer.
