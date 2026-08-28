# CLAUDE.md — EasyCo

Persistent project context for Claude Code. Read this before touching
any file. This captures stable, cross-session principles — not
task-specific decisions (those live in packages/EasyCo/documents/*.md,
read the relevant one before working in that domain).

## What this project is

EasyCo: a modular Laravel commerce platform. Business domains are
independent composer packages under packages/EasyCo/{DomainName}/, each
with its own composer.json, src/, tests/, database/migrations/, wired
into the main app via Laravel path repositories and a
{Domain}ServiceProvider. Rebuilt from a clean Laravel install after an
earlier Bagisto-based attempt — see README.md's "Architecture note."

Domains so far: Pricing (Money/Currency/Price, PriceResolver contract,
DefaultCurrency), Catalog (Product/Variation aggregate, SIMPLE/VARIABLE
model), Extensibility (WordPress-style Hook actions/filters), Operational
Sales (Client/Transaction/SaleLine/InstallmentPlan — domain layer done,
persistence in progress). Read each domain's
packages/EasyCo/documents/{domain}-domain-design.md before working in it.

## Hard architectural rules (read the reasoning in the domain docs before deviating)

1. **Domain layer is pure PHP, framework-agnostic.** Product, Variation,
   Money, Client, SaleLine, InstallmentPlan etc. never import
   Illuminate\Support, never know about Eloquent, never know a cache or
   a Hook system exists. Persistence lives in a separate
   Persistence/Eloquent/ subfolder implementing Contracts/ interfaces
   the domain layer defines.

2. **Every business invariant enforced by app code must ALSO be a real
   DB constraint wherever physically possible** (unique indexes, FKs).
   Application-layer validation alone is never sufficient for
   correctness-critical invariants (uniqueness, referential integrity).
   See catalog-domain-design.md §"Variation combination uniqueness".

3. **Unique-constraint collision detection: check SQLSTATE (23000) +
   driver-specific error code (MySQL 1062, SQLite 19) via
   QueryException::$errorInfo — never $e->getMessage() string matching.**
   This is now the required pattern for any future unique-constraint
   handling (catalog-domain-design.md §7). Implemented twice already
   (attribute_signature, sku/slug) — both had to be fixed after an
   initial MySQL-only version silently failed against SQLite.

4. **Historical identity is never destroyed or reassigned.** No hard
   deletes of anything another domain might reference by id (Orders,
   POS, Inventory). Soft-delete / archive-status / append-only-new-row
   patterns only. See Catalog's Variation lifecycle and
   operational-sales-domain-design.md §3.2 (SaleLine immutability - a
   correction is always a NEW row referencing the old one, never an
   in-place rewrite).

5. **MySQL/MariaDB identifiers have a 64-char limit.** Laravel's
   auto-generated names can exceed it, especially on long table names
   with multi-word FK columns. ALWAYS give explicit short names to
   unique()/index()/foreignId()->constrained() calls proactively —
   don't wait for a migration to fail. Verify with a quick length check
   before writing the migration.

6. **MySQL's non-transactional DDL means a failed migration can leave
   partial state behind.** Never blindly re-run a fixed migration —
   check for and clean up any partially-created table/column first.

7. **A structural/ownership reference (e.g. an aggregate-owned child's
   parent-id) may get a narrow, one-time backfill method even on an
   otherwise-immutable class** (e.g. Variation::assignProductId(),
   SaleLine::assignTransactionId()) — this is NOT a violation of business
   -fact immutability, it's a different category of field. Document the
   distinction explicitly wherever it's used.

8. **Never hardcode a single default currency/locale/language.** Bulgaria
   adopted the euro on 1 Jan 2026 — a hardcoded BGN default already broke
   once in this codebase. Use EasyCo\Pricing\DefaultCurrency (fail-loud,
   configurable via config('services.pricing.default_currency')), and
   apply the same "configurable, fail-loud, no silent guessing" posture
   to any future default-value decision.

9. **Cross-domain references are always by id/string contract, never by
   direct package dependency**, UNLESS the referenced thing is a pure
   value object being legitimately reused (e.g. OperationalSales depends
   on Pricing for Money — a value object, not a domain aggregate — but
   OperationalSales must NEVER depend on Catalog; Variation references
   stay plain priceableId strings). Catalog never depends on Pricing
   either, for the same reason.

10. **Hooks (packages/EasyCo/Extensibility) are the extensibility
    mechanism** for anything a merchant should be able to customize
    without touching core code (see extensibility-design-and-hooks.md).
    Actions (Hook::fire/action) for "something happened, react" — filters
    (Hook::apply/filter) for "transform this value". Domain packages
    NEVER call Hook:: directly — only app/ layer code does. Add new hooks
    to the Hook Reference table in the same commit.

## Working relationship / expected behavior

- **Scope discipline: do NOT expand scope beyond what was explicitly
  asked**, even if you notice an adjacent problem. Instead, REPORT what
  you found and stop — flag it, don't silently fix it or silently ignore
  it. This has repeatedly caught real bugs before they compounded.
- **Never silently adjust a test's expected behavior to make it pass**
  if the actual behavior seems wrong — report the discrepancy and ask.
- **Never fabricate a test result.** Always actually run the suite and
  show real output.
- **Push back, in writing, when something looks like a real risk** —
  don't default to compliance for its own sake.
- When finishing a unit of work, report: what changed, why, what you
  explicitly did NOT do (and why), real test output, and any open
  concern needing a decision.
- Migrations, new columns, new unique constraints: run them against the
  real dev database and confirm (e.g. SHOW CREATE TABLE), don't just
  trust that the migration file "looks right."

## Build / test

- `composer update {package} --with-dependencies` to wire a new/changed
  EasyCo package into the root app (not a full `composer update`).
- `php artisan migrate` for the app; each package's own PHPUnit suite can
  also run standalone via its own phpunit.xml (see
  packages/EasyCo/{Domain}/tests/bootstrap.php — a manual PSR-4
  autoloader, since this sandbox/environment has no packagist access for
  fresh `composer install` on new packages in some contexts — check
  whether that's still true before assuming it).
- Full app test suite: `php artisan test`.

## Deferred, tracked (don't rebuild speculatively, don't lose track)

- Pricing persistence: PriceList/PriceListScope/PriceListItem domain
  layer implemented (see pricing-persistence-domain-design.md). Still
  deferred: EloquentPriceResolver + migrations (replacing
  InMemoryPriceResolver), the two reserved system PriceLists' seeding
  mechanism, and the price-list health-check report — §8 items 2-4.
- VariationRepository details / SKU & Barcode generators (as Hook
  listeners, per catalog-domain-design.md and vertical-slice-notes.md §6).
- Commerce Knowledge Layer — concept only, see
  commerce-knowledge-layer-concept.md.
- i18n / localization strategy — not yet started, explicitly deferred by
  the project owner.
- Admin UI (Filament/Livewire likely) — see
  performance-and-channel-strategy.md's explicit warning: must go through
  the domain layer, never raw Eloquent writes from admin resources.
- Cart domain, including abandoned-cart recovery via a `cart.abandoned`
  Hook — see cart-abandoned-recovery-note.md (also flags a Pricing-owned
  single-use discount-code generation need, triggered by Cart, not owned
  by Cart).
- Checkout orchestration — performance/reliability risk where every
  domain converges at once; needs a dedicated orchestration layer, async
  non-blocking side effects, and explicit external-API timeouts — see
  checkout-orchestration-performance-note.md.
- Embedded AI setup/config assistant — must route every suggested
  action through the domain layer like any other caller, never raw
  Eloquent; suggest-then-confirm only — see
  embedded-ai-assistant-note.md.
- Product/Brand slider widget (dynamic, Catalog/Pricing-backed, distinct
  from the static Hero Slider) — see
  product-brand-slider-widget-note.md.
- Barcode bulk-entry UX (scanner-driven, admin UI) — distinct from the
  catalog.variation.barcode generation Hook — see
  barcode-bulk-entry-ux-note.md.
- Production hardening/deployment pass: rate limiting and bot-scanning
  mitigation at the infrastructure layer — see
  server-stability-observations.md.

## Do not touch without explicit instruction

Product.php, Variation.php, VariationSignature.php, and the SIMPLE↔VARIABLE
transition methods in Catalog are considered frozen/stable as of the v1
hardening pass — treat any request to modify them as needing extra
confirmation, not routine work.