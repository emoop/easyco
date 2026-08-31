# Inventory Domain Design

**Status:** v1.0 — domain layer, persistence, and HTTP surface all implemented in one pass. `StockLevel` (`forVariation()`/`reconstituteFromStorage()`, `setQuantity()`), `Contracts/StockLevelRepository`, `EloquentStockLevelRepository` (atomic `increase()`/`decrease()`, upserting `save()`), the `stock_levels` migration, and `InventoryServiceProvider` are all implemented and tested (25 tests: 9 in the package's own domain suite, 16 across `tests/Feature`). HTTP surface: `GET`/`PUT /api/variations/{variationId}/stock` only — see §9 for why `increase()`/`decrease()` have no HTTP surface yet. See §11 for what's still open.

**Builds on:** the domain/persistence isolation principle already established by every other `*-domain-design.md` in this project; the `Contracts/` + Laravel-backed-implementation pattern; `account-domain-design.md`'s precedent of a brand-new package scaffolded from scratch in one task.

**Relates to:** `catalog-domain-design.md`'s `catalog_variations` table — this domain is the reference that migration's own comment anticipated (*"Never hard-deleted — Orders/POS/Inventory may reference this id forever"*). `operational-sales-domain-design.md`'s `SaleLine`/`SaleLineType` — read directly during this task's design, referenced in §3 and §11, but **no code in this domain calls into `OperationalSales`, and no code in `OperationalSales` calls into this domain.** No `Catalog` file, no `OperationalSales` file was modified by this task.

---

## 1. Why a new domain, not a column on `catalog_variations`

Stock quantity is operational state that changes independently of a Variation's own catalog identity (name, price, attributes) — it changes on every sale, every return, every stock count, at a completely different cadence and from a completely different actor (a merchant doing inventory, not someone editing a product listing) than anything Catalog owns. Keeping it in its own domain, referencing `catalog_variations.id` only by a plain foreign key, follows the same cross-domain-by-id discipline `Media`/`Pricing`/`Account` all already established (CLAUDE.md rule 9) — Catalog itself never gains a `stock_quantity` column, and never will need to know Inventory exists.

---

## 2. Entity name: `StockLevel`, package: `Inventory`

The package is `EasyCo\Inventory`; the thing it manages is a `StockLevel` — the same split `EasyCo\Media` already uses for `MediaAsset` (package name names the *domain*, entity name names the *concept*, and they're not forced to match just because there's only one entity in the package today).

---

## 3. Confirmed V1 scope: a single quantity per Variation — no reservations

This is "option A" of a scope the domain owner chose explicitly, after we read `OperationalSales\SaleLine`/`Enums\SaleLineType` together. `SaleLineType::RESERVATION` exists as an enum case, but **no HTTP write-path exists anywhere in this codebase for recording any `Transaction`/`SaleLine` at all yet** — confirmed by checking `app/Http/Controllers/Api/` directly before writing this document, not assumed. Building reservation-aware stock logic (holding units against a pending reservation, releasing them on cancellation, converting them to a sale on settlement) with nothing on the other end to actually create a `RESERVATION` `SaleLine` would be speculative work with no way to verify it's even shaped correctly. `StockLevel` in V1 is exactly one number: how many units are currently available, full stop. See §11 for this recorded as a deferred, not-forgotten decision.

---

## 4. The FK is `restrictOnDelete()`, not `cascadeOnDelete()`

`stock_levels.variation_id` mirrors `catalog_variations.product_id`'s own choice one level up: a Variation is never allowed to be deleted while something still references it by id, full stop — the same "historical identity is never destroyed or reassigned" posture (CLAUDE.md rule 4) that's why `catalog_variations` rows are never hard-deleted in the first place. This is deliberately *not* the same choice Media's pivot tables (`catalog_product_media`/`catalog_variation_media`) made with `cascadeOnDelete()` — those are genuinely different relationships. A pivot row's only reason to exist is to record "this media is attached to this product," so when the product's gone, the pivot row has nothing left to say and cascading is correct. A stock count is not a pivot; it's a fact about the Variation itself, and if something ever tried to delete a Variation with stock still tracked against it, that should fail loudly, not silently vanish the count.

---

## 5. Lazy/implicit zero — the biggest departure from this codebase's usual repository pattern

Every other `find*()` method in this project returns `?Entity` — `null` means "not found," an error/absence case the caller has to branch on. `StockLevelRepository::findByVariationId()` is deliberately different: **it never returns `null`.** When no `stock_levels` row exists for a Variation, it returns a real, valid `StockLevel::forVariation($variationId, 0)` — a legitimate, not-yet-persisted domain object with `id() === null`, exactly the shape `Account::register()` produces before its first `save()`.

This is not an inconsistency to "fix" later — it reflects a genuine difference in what "no row" *means* in each case. For `Account`, no row means "this doesn't exist" — a real absence. For `StockLevel`, no row means "zero" — a real, meaningful, valid value, not the absence of one. Forcing callers to null-check `findByVariationId()` would mean every caller re-implements "treat null as zero" themselves, over and over, for a fact this repository already knows.

**Direct consequence: no coupling to Variation creation.** No hook fires, no listener exists, nothing in `EasyCo\Catalog` or its HTTP layer knows Inventory exists. A merchant provisions stock explicitly, whenever they get around to it, by calling `PUT /api/variations/{id}/stock` — there is no "every new Variation automatically gets a stock row" mechanism, by design.

---

## 6. `increase()`/`decrease()` are atomic conditional UPDATEs — the actual correctness-critical decision in this whole document

The naive implementation — load a `StockLevel`, check/mutate its quantity in PHP, call `save()` — is a textbook read-then-write race. Two near-simultaneous requests decrementing the last unit could both read `quantity = 1`, both compute `0` in PHP, and both "succeed": an oversell that's silent until someone tries to fulfil an order for stock that was never really there.

This isn't a hypothetical risk invented for this document — it's confirmed against real prior art. WooCommerce's `wc_update_product_stock()` is explicitly documented in its own source as using direct queries rather than going through its usual meta-update path specifically *"so we can do this in one query (to avoid stock issues)"* — and WooCommerce later still had to add an entirely separate "reserved stock" table with `INSERT ... SELECT ... FOR UPDATE` locking, because their original single-query approach didn't fully close every race window under real concurrent checkout traffic. That's the level of care this primitive actually needs, even for the deliberately narrow V1 scope in §3.

`decrease()`'s real implementation:

```php
$affected = StockLevelModel::where('variation_id', $variationId)
    ->where('quantity', '>=', $amount)
    ->decrement('quantity', $amount);

if ($affected === 0) {
    throw InsufficientStockException::forVariation($variationId, $amount);
}
```

Eloquent's `decrement()` is a thin wrapper over a plain `UPDATE`, and returns the number of rows it actually matched and changed. The `WHERE quantity >= ?` clause and the `$affected === 0` check together mean the database itself enforces "only decrement if there's enough," inside one atomic statement — there is no window between "check the quantity" and "write the new quantity" for a second request to land in, because there is no separate check step at all. Either the conditional `UPDATE` matches a row and the whole operation succeeds, or it matches nothing and nothing was written.

**No caller exists for `increase()`/`decrease()` yet** — see §9/§11. The primitive still has to be race-safe from day one, because retrofitting real atomicity onto a naive caller that's already shipped and already assumed a simpler read-then-write shape is a much harder bug to find (and fix without a regression) than building it correctly before any caller exists at all.

---

## 7. `increase()`'s own trap: it has to work with zero rows too

`increment()` alone is *also* a thin wrapper over `UPDATE ... SET quantity = quantity + ?` — and an `UPDATE` against a `WHERE` clause that matches zero rows is a legal, silent no-op. It does not insert. So a bare `StockLevelModel::where('variation_id', $variationId)->increment('quantity', $amount)` against a Variation that has never had stock explicitly set would return successfully, having done precisely nothing.

`increase()`'s real implementation calls `firstOrCreate(['variation_id' => $variationId], ['quantity' => 0])` first, guaranteeing a row exists (at `0` if it didn't already), and only then runs the `increment()`. This is the exact scenario an explicit test (`test_increase_against_a_variation_with_no_row_at_all_creates_one_with_the_increased_amount`) exists to catch — the kind of gap that passes every test someone would write without specifically thinking about "what if there's no row at all," and then silently does nothing the first time a real return or restock is processed for a Variation nobody ever ran `PUT .../stock` against.

---

## 8. `save()` is not `increase()`/`decrease()`, and it upserts on `variation_id`, not the surrogate id

Every other repository in this codebase branches on `$entity->id() === null` to decide insert-vs-update. `EloquentStockLevelRepository::save()` doesn't — it always calls `StockLevelModel::updateOrCreate(['variation_id' => ...], ['quantity' => ...])`, keyed on `variation_id`. This is deliberate, not a shortcut: `variation_id` *is* the real business identity here — there is exactly one `StockLevel` per Variation, by definition, backed by a real DB `unique()` constraint (§Persistence) — while the `id` auto-increment column is purely a storage detail nothing outside this repository needs to know about (contrast with `Account`/`Client`/every pivot, where the surrogate id genuinely is a durable identity other code holds onto).

`save()` is specifically the **merchant's "set an absolute quantity" operation** — a single authoritative overwrite (`PUT /api/variations/{id}/stock` in the HTTP layer), not a delta, and not something concurrent callers are racing to apply at the same instant the way a checkout flow racing against itself would be. That's why it doesn't need §6's atomic-conditional-update treatment: there's exactly one actor (the merchant, deliberately setting a number) making exactly one authoritative statement of fact, not two competing writers whose *order* matters.

---

## 9. No public HTTP endpoint for `increase()`/`decrease()`

Only `GET` (read the current quantity) and `PUT` (the merchant sets an absolute value) are exposed in `StockLevelController`. Exposing `increase()`/`decrease()` directly over HTTP would itself be a business-logic hole: those two methods are supposed to be *side effects* of something else happening — a sale being recorded, a return being processed — never a raw value some API caller can push at will. The two methods exist, fully implemented and tested, ready to be called from inside whatever app-layer code eventually orchestrates a real sale or return; see §11 for exactly which future task that is.

---

## 10. No `softDeletes()` on `stock_levels`, unlike `accounts`

`account-domain-design.md` §5 added `softDeletes()` to `accounts` specifically because a durable *id* — `Account`'s own surrogate identity — is very likely to be referenced by other domains soon. `stock_levels` doesn't have that property: per §8, nothing outside this domain (and arguably nothing inside it either) ever needs to reference a `stock_levels` row's own id as a historical fact worth preserving — the only thing that matters, ever, is "what is variation X's current quantity," which is fully captured by the live `variation_id` + `quantity` pair. If a `stock_levels` row is ever deleted (not something V1 builds a path for, but hypothetically), there's no "this used to exist" fact anyone needs to reconstruct the way there might be for a soft-deleted `Account`. This is a deliberate distinction from the `accounts` precedent, not a copy-paste omission.

---

## 11. Deferred (documented, not accidental)

- **RESERVATION handling.** §3's scope decision, restated here for visibility: `SaleLineType::RESERVATION` exists in `OperationalSales`, but nothing in this codebase can record a `SaleLine` of any type yet, reservation or otherwise. Holding/releasing stock against a pending reservation is real future work, not designed or stubbed here.
- **Low-stock thresholds/alerts.** No concept of "warn when quantity drops below N" exists — `StockLevel` only ever reports its current, exact number.
- **A "manage stock" / untracked-item toggle.** Some items a real boutique sells may never want quantity tracking at all (made-to-order, unlimited digital add-ons, etc.) — a per-Variation on/off switch for whether Inventory even applies. Not built in V1; flagged here as a real, expected future need given this project's actual physical-boutique context, not a hypothetical.
- **Multi-location stock.** `StockLevel` is a single number per Variation — no per-warehouse/per-store split.
- **Which future task actually calls `increase()`/`decrease()` — the single most important open item here.** These two methods are fully implemented, atomic, and tested, but **nothing calls them yet.** Whatever eventually records a `SALE` or `REFUND` `SaleLine` — the POS write-path or the Checkout-orchestration task, per this session's discussion with the domain owner about `OperationalSales.Transaction`/`SaleLine` — is the code that's supposed to call `StockLevelRepository::decrease()`/`increase()` as a side effect of that event. Until that write-path exists, `StockLevel` quantities only ever change through the merchant's own explicit `PUT` calls. This is an obvious, expected near-term gap for whoever picks up either Checkout or POS next — not something to silently assume is already wired up.
