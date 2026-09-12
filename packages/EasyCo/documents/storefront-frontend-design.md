# EasyCo Storefront — Frontend Design

**Status:** Draft — architecture and decisions only, nothing implemented
yet. Written after `performance-and-channel-strategy.md` §2 (admin UI
direction) and `channel-native-commerce-vision.md` (Web as one channel
among several) were already in place; this document is the equivalent
decision record for the customer-facing storefront specifically.

**Confirmed empty starting point (read directly, not assumed):**
`routes/web.php` has only the default `/` route serving
`resources/views/welcome.blade.php`. No other storefront view exists.
Tailwind CSS is already wired through Vite (`vite.config.js`,
`resources/css/app.css`); Livewire, Alpine.js, and Filament are not
installed anywhere in `composer.json` or `package.json` yet. This
document assumes a genuine greenfield start, not a partial
implementation to reconcile against.

---

## §1. Where this lives architecturally

The storefront is **not a new domain package under `packages/EasyCo/`**.
It owns no aggregate of its own — it renders and orchestrates data that
already lives in Catalog, Pricing, Cart, Account, and Media. This is the
exact same reasoning `checkout-domain-design.md` §1 already gives for
why Checkout is an `app/Services/` orchestration layer rather than a
domain package, and it applies here without modification.

Concretely: `app/Http/Controllers/Web/`, `resources/views/storefront/`,
and any storefront-specific view-model/composer classes live in `app/`,
never in `packages/EasyCo/`. A storefront controller is a *consumer* of
domain repositories (`ProductRepository`, `PriceResolver`,
`CartRepository`, and so on) — it never becomes a place where domain
logic is reimplemented for rendering convenience. If a storefront page
needs a computation that doesn't already exist as a domain method, that
computation is added to the domain layer and called from the
controller, not inlined into a Blade view or a controller method.

---

## §2. Rendering strategy — Blade first, Alpine for client state, Livewire narrowly

**Default: plain, server-rendered Blade.** Every anonymous, high-traffic,
SEO/AI-relevant page — home, category listing, product detail — is
built as a plain Blade view returning fully-formed HTML from the
controller. No client-side framework decides whether content exists;
the HTML the server sends *is* the content.

**Alpine.js for interactivity that never needs the server.** Tabs,
modals, an image gallery/lightbox, a mobile nav toggle, a quantity
stepper before "add to cart" is pressed — anything that's pure UI state
with no database read/write — uses Alpine directly in the Blade markup.
No AJAX round trip, no Livewire component overhead.

**Livewire only where a real server round trip is unavoidable**, and
only after checking that Blade+Alpine+the existing JSON API genuinely
can't do it. Two concrete candidates, not a blanket policy:
- Live search-as-you-type against the catalog (debounced, needs a real
  query against Catalog/Pricing on every keystroke).
- A stock-aware "notify me" or similar form that needs immediate
  server validation before submission.

The default for anything resembling a form submission, however, is a
plain HTML form or a small Alpine-driven `fetch()` call against the
**existing JSON API** (`POST /api/cart/lines`, `PUT /api/cart/promotion`,
and so on) — these endpoints already exist, are already tested, and
building a second, Livewire-specific path to the same domain logic
would be exactly the kind of premature abstraction/duplication this
project's own principles reject. **The storefront should consume its
own product's API wherever a JSON round trip is all that's needed,
Livewire only where genuine server-side reactivity earns its keep.**

Rationale for defaulting away from Livewire, not just for defaulting
away from a full SPA: Livewire renders its initial output server-side,
so it carries no SEO/AI-crawler penalty by itself — that concern is
fully addressed by "no client-side-only content" in §3. The reason to
still prefer plain Blade on high-traffic pages is *performance*, not
crawlability: a Livewire component has real per-request overhead
compared to a Blade component when no interactivity is actually needed,
and "fast and lightweight" was stated as a hard requirement for this
storefront, independent of the SEO question.

---

## §3. Why not a decoupled SPA

Confirmed directly, not assumed: as of this research, none of the major
AI crawlers (GPTBot, ClaudeBot, PerplexityBot) execute JavaScript — they
read only the raw HTML a server returns. Google/Gemini are the
exception, inheriting Googlebot's rendering infrastructure. A
client-rendered SPA sending an empty `<div id="app">` shell as its
initial response is therefore invisible to most AI shopping agents even
while ranking normally on Google — a gap that doesn't show up in normal
analytics because Google-driven traffic looks fine.

This is additive to, not a replacement for, `architecture-and-product-
foundation.md` §4.3's existing API-first principle ("frontend
applications should not have special status compared with other
clients"). That principle is preserved here: the storefront calls the
*same* `/api/cart`, `/api/account/me` and so on that a future mobile
app or any other client would call (see §6 below) — it simply *also*
renders server-side HTML for the pages where being read by a browser,
a search engine, and an AI agent all matter simultaneously.

---

## §4. Caching and the personalization problem

**The caching architecture itself** (Varnish, host-agnostic, no CDN
dependency, Redis for the application-level cache) is already decided
in `performance-and-channel-strategy.md` §1 and `production-
requirements.md`. This section covers the one genuinely new design
question caching raises for the storefront specifically:

**The problem:** if `GET /product-category/clothing/dress` is cached and
served by Varnish without ever reaching PHP, every visitor — logged in
or not — receives the *identical* cached HTML. But the page header
needs to show "Hello, [Name]" and a real cart item count for a
logged-in customer. Baking that into the cached HTML is a real bug
waiting to happen: it means one customer's session data could be served
to a completely different visitor.

**Decision: the cached HTML is always fully anonymous. Personalized UI
fragments are hydrated client-side, after page load, via the existing
JSON API.** Concretely: the header markup ships with an empty cart-count
badge and a generic "Account" link; a small Alpine component runs
`fetch('/api/cart')` and `fetch('/api/account/me')` on page load and
fills in the real count / logged-in name once the responses come back.
Both endpoints already exist (`Cart` and `Account` domains) and are
already tested — no new API surface is needed for this.

This was chosen over the alternative (Edge Side Includes — letting
Varnish itself stitch a small server-rendered fragment into the cached
page at the edge) specifically for the same reason Blade is preferred
over Livewire in §2: it's simpler to build, reason about, and debug.
ESI requires Varnish-side template authoring (VCL) and a second,
ESI-aware code path; client-side hydration requires nothing Varnish
doesn't already do (cache the anonymous HTML, full stop) and reuses
API endpoints that already exist and are already correct. **Flagged
explicitly because it's a real trade-off, not a free choice:** the
personalized header fragment will have a brief (sub-second, normally
imperceptible) flash of the anonymous state before hydration completes.
If that's ever unacceptable for a specific page, ESI remains available
as a documented alternative for that one page — not the default.

**Consequence for routing:** any route that must reflect
account-specific or cart-specific state *in the initial HTML itself*
(not just via client-side hydration) — an actual account dashboard
page, for instance — is **not** a cacheable route and must never be
placed behind Varnish. §7 lists which storefront routes are cacheable
and which aren't.

---

## §5. Structured data (JSON-LD)

Every product detail page renders a `<script type="application/ld+json">`
block, generated server-side from the same Catalog/Pricing data already
used to render the visible page — **never a second, independently
maintained data source**, per §1's "no reimplementing domain logic for
rendering convenience" rule and per the "content parity" requirement
research surfaced (structured data disagreeing with visible content is
treated as a red flag by Google, not a bonus).

Minimum fields per the current (2026) baseline for AI-agent product
retrieval, confirmed against current sources rather than an older,
name+image-only standard:
- `Product`: name, description (meaningfully descriptive, not a
  truncated stub), image(s), `brand` (a nested `Brand` object, not a
  bare string), sku, gtin/mpn where the merchant has them, category.
- `Offer`: price, priceCurrency, availability, `hasMerchantReturnPolicy`
  (flagged in research as the single most commonly missing field, and
  the one AI shopping surfaces weight most heavily), shippingDetails.
- `AggregateRating`, only once/if EasyCo has a real reviews feature —
  not fabricated ahead of that existing.

This is generated by a dedicated view-composer/Blade component in the
`app/` layer (e.g. `App\View\Composers\ProductStructuredData` or
equivalent), not inside `EasyCo\Catalog` — Catalog's domain classes
must not know that schema.org or JSON-LD exist, for the same reason
they must not know a specific frontend exists (`architecture-and-
product-foundation.md` §3).

**Explicitly deferred, not part of this document's scope:** `llms.txt`
(research found it currently carries negligible real traffic — not
worth prioritizing over getting JSON-LD right first), any
`ItemList`/category-page schema, any dedicated machine-readable feed
format for a specific agentic-commerce protocol (ACP/UCP/MCP) —
`channel-native-commerce-vision.md` §6/§13 already frames these as
future channel adapters, not core storefront work.

---

## §6. Consuming the existing API, not duplicating it

Every storefront interaction that changes state — add to cart, apply a
promotion code, register, log in — calls the existing JSON API
endpoints directly from a small Alpine/vanilla-JS call, exactly as any
other API client would. The storefront introduces **no new business
logic and no new HTTP endpoints for anything the API surface already
covers.** Where the storefront needs something the API doesn't expose
today (for instance, a category page needing a paginated, filtered
product listing shaped for display), that becomes a new *read* endpoint
on the relevant domain's existing controller, following that domain's
existing conventions — not a storefront-only shortcut.

---

## §7. Routes and view organization

Mirrors the URL structure of raf.bg (the real store this platform will
eventually replace), specifically so existing indexed URLs, inbound
social links, and customer bookmarks keep working unchanged the day a
real domain migrates from WooCommerce to EasyCo — confirmed against the
real, current raf.bg URLs rather than assumed:

- `mysite.com/product/roklia-kare`
- `mysite.com/product-category/clothing/dress`
- `mysite.com/product-tag/dreses`
routes/web.php
GET / → HomeController@index (cacheable)
GET /product-category/{path} → CategoryController@show (cacheable)
where('path', '.*') — accepts any depth of nested category segments
(e.g. clothing/dress). Resolves by the LAST segment only (category
slugs are globally unique — DB-enforced, confirmed in
EloquentCategoryRepositoryTest); always renders a
<link rel="canonical"> pointing at the category's real full
ancestor path, so an outdated or incorrect parent prefix in the URL
still resolves rather than 404ing, while search engines and AI
crawlers converge on one canonical URL.
GET /product-tag/{slug} → TagController@show (cacheable)
GET /product/{slug} → ProductController@show (cacheable)
GET /cart → CartPageController@show (NOT cacheable — see §4)
GET /account → (deferred — no storefront account UI in this doc's scope)

resources/views/storefront/
layouts/app.blade.php — shared header/footer, Alpine cart/account hydration
home.blade.php
category/show.blade.php
tag/show.blade.php
product/show.blade.php
cart/show.blade.php
partials/product-card.blade.php
partials/structured-data.blade.php — the JSON-LD block from §5

`resources/views/welcome.blade.php` and its default route in
`routes/web.php` are replaced, not kept alongside the real homepage.

Catalog's slug generator already produces native-script slugs
(confirmed in `CatalogSlugGeneratorTest` — a Bulgarian product name
produces a Cyrillic slug, not a transliteration), so real URLs under
this structure look like `/product/лятна-рокля-каре`, matching how
`raf.bg` itself would render a Cyrillic product name — nothing further
to decide here, this follows automatically from a decision already made
in Catalog.

---

## §8. Testing strategy — new territory for this suite

Every existing test in this project asserts against JSON responses.
Rendering HTML is genuinely new, and needs its own convention rather
than improvising per test file:

- **Rendered-content assertions**: `$response->assertSee(...)` /
  `assertDontSee(...)` against real, known values (a product's actual
  name/price as seeded in the test), not against markup structure —
  mirrors this project's existing "assert real values, not shape"
  discipline from the API test suite.
- **Structured data assertions**: extract the `<script
  type="application/ld+json">` block's contents from the response body,
  `json_decode()` it for real, and assert against the decoded array
  (correct `@type`, price, availability) — never a substring match on
  the raw JSON text, which would pass on subtly wrong JSON.
- **Cacheability assertions**: for every route marked cacheable in §7,
  a real test asserts the response carries no `Set-Cookie` header and a
  `Cache-Control` header with a real `public`/`s-maxage` directive; for
  every route marked NOT cacheable, a test asserts the opposite. This
  is the storefront's own version of `MerchantRoutesRequirePermission-
  Test`'s "audit the real thing, not the source text" discipline from
  `staff-access-domain-design.md` §11 — cacheability is a per-route
  contract worth protecting with a real regression test, not just
  correct today by construction.
- **Category path-resolution assertions**: given §7's "resolve by last
  segment, canonicalize the rest" rule, a real test hits
  `/product-category/wrong-parent/dress` and asserts it still renders
  the real "dress" category (200, not 404) with a
  `<link rel="canonical" href=".../product-category/clothing/dress">`
  tag pointing at the true ancestor path.

---

## §9. Explicitly deferred / out of scope for this document

Recorded here so it's a conscious choice, matching this project's own
`catalog-domain-design.md` §6 convention:

- Actual Varnish installation/VCL configuration on any real server —
  covered operationally by `production-requirements.md`, not by
  application code this document specifies.
- Any admin-facing storefront content management (hero sliders, CMS
  pages) — `site-settings-design.md` already notes this infrastructure
  exists but nothing reads from it yet; out of scope here too.
- A storefront account dashboard, order history page, or address
  management UI — the API for all of this already exists (`Account`,
  `Address`, `Order`); only the storefront *pages* consuming them are
  deferred.
- Search (a real product search page/box) — no `Search` domain exists
  yet per `architecture-and-product-foundation.md`'s own domain list.
- Any AI-agent-specific feed format (ACP/UCP/MCP) — channel-adapter
  work per `channel-native-commerce-vision.md`, not storefront work.
- Internationalization/multi-currency display — not raised in any prior
  conversation as a V1 requirement; flagged here only so its absence is
  a decision, not an oversight.

---

## §10. Decisions confirmed by the domain owner

1. **§4's caching/personalization trade-off** — client-side hydration
   (with a brief, sub-second anonymous flash before the real cart
   count/name load in) confirmed as the default over Edge Side
   Includes.
2. **§7's URL structure** — confirmed to mirror `raf.bg`'s real,
   current structure (`/product/{slug}`, `/product-category/{path}`,
   `/product-tag/{slug}`) rather than a shorter, invented scheme,
   specifically to preserve existing indexed URLs and inbound links
   across a future WooCommerce → EasyCo migration.
3. **Implementation order** — home + category + product pages first
   (highest-traffic, most SEO/AI-relevant surface), cart and account
   pages staged as later, separate work.