# Cart Domain Design

**Status:** v1.0 — domain layer, persistence, and HTTP surface all implemented in one pass. `Cart`/`CartLine` (mirroring `OperationalSales\Transaction`/`SaleLine`'s aggregate shape), `Contracts/CartRepository`, `CartLineAdder` (the one place this package touches another domain's published contract), `EloquentCartRepository`, `CartServiceProvider` are implemented and tested (75 tests: 29 in the package's own domain suite, 46 across `tests/Feature`). HTTP surface: `GET`/`POST /api/cart(/lines)`, `PATCH`/`DELETE /api/cart/lines/{variationId}`. A `cart:prune` Artisan command and a guest→account merge-on-login listener round out the write paths. See §13 for what's still open — most importantly, nothing calls Checkout yet, because Checkout doesn't exist.

**Builds on:** `account-domain-design.md` and `inventory-domain-design.md` — this is the domain both were built for. `OperationalSales\Transaction`/`SaleLine`'s aggregate pattern (accept-a-child-provisionally-before-the-parent-has-an-id, back-fill on `assignId()`) is followed closely for `Cart`/`CartLine`. `pricing-domain-design.md` §4.1/§7 (§7 corrected in this same commit — see below).

**Relates to:** `catalog-domain-design.md`'s `catalog_variations` table (referenced by id only, never a package dependency — no `Catalog`, `OperationalSales`, `Account`, or `Inventory` source file was modified by this task); `cart-abandoned-recovery-note.md` (the reason guest carts are persisted at all — see §1); `pricing-domain-design.md` §7, which this task corrects in the same commit (see §3 below).

---

## 1. Why guest carts are real, DB-persisted rows — not just browser storage

Both guests and logged-in customers get a genuine `carts` row (the same model Bagisto uses, confirmed with the domain owner) — not a guest cart held only in a cookie or `localStorage` until they create an account. This is deliberate, and tied directly to a decision made before Cart itself existed: `cart-abandoned-recovery-note.md` already established that abandoned-cart recovery is a real, near-term priority for this merchant (unlike bulk email campaigns), and recovery fundamentally requires a server-side cart to exist and be findable later — a cart that only ever lived in someone's browser and was never sent to the server can't be the subject of a "you left something in your cart" email. Persisting every cart from the first line added, guest or not, is what makes that future feature possible at all; it's not incidental to this task.

---

## 2. The core model: `Cart`/`CartLine`, keyed by `variation_id` — never `product_id`

```
Cart                                 (aggregate root)
├── id
├── accountId | sessionToken          exactly one, never both, never neither — §7
├── expiresAt                         §9
└── CartLine[]

CartLine
├── id, cartId
├── variationId                       never product_id — see below
├── quantity                          mutable — see §4
├── priceAtAddMinor / priceAtAddCurrency   display-only — see §5
```

Every `CartLine` references a `variation_id`, never a `product_id`. This isn't a simplification that loses information: `Product::createSimple()` guarantees a SIMPLE product always has exactly one UNIVERSAL variation ("a SIMPLE product without its Universal variation is not a valid state to construct at all," per that method's own docblock), so `variation_id` already covers both SIMPLE and VARIABLE products with zero special-casing anywhere in Cart. Anything else a caller needs — base SKU, display name, media — is reachable from the Variation itself (or its parent Product) via Catalog's own repositories; Cart doesn't need to duplicate any of it.

`Cart`/`CartLine` closely mirror `OperationalSales\Transaction`/`SaleLine`'s aggregate shape: a not-yet-persisted `Cart` accepts a `CartLine` provisionally (empty-string `cartId` placeholder), and `Cart::assignId()` back-fills that placeholder on every line still holding it, exactly like `Transaction::assignId()` back-filling `SaleLine::transactionId()`. The one real difference: `Cart::addLine()` enforces an aggregate invariant `Transaction::addSaleLine()` doesn't have — at most one `CartLine` per `variationId`. Adding a line for a variation already in the cart increases that existing line's quantity instead of appending a second line for the same item.

---

## 3. Pricing is resolved LIVE on every cart read — the cart never stores a price used for payment

This is the single most heavily-researched decision in this document, so the reasoning is recorded in full.

**The precedent, checked directly rather than assumed:** WooCommerce recalculates cart totals on every page load — `WC_Cart_Session::get_cart_from_session()` calls `calculate_totals()` on the `wp_loaded` hook, every single request. Shopify behaves the same way; its own community documentation states a price change *"will reflect real-time in the checkout of all customers who added that product,"* and Shopify's cart UI surfaces an explicit *"Prices for these items have changed and are updated in your cart"* notice when that happens. Neither platform treats "the price when it was added to the cart" as the price the customer actually pays — the price becomes a permanent, payment-relevant fact only when an **order** is created, not when an item lands in a cart.

**What this means concretely:** `CartLine` stores no authoritative price at all. `GET /api/cart` calls `EasyCo\Pricing\Contracts\PriceResolver::resolve()` for every line, every single time it's read — the same live call `POST /api/cart/lines` and `PATCH /api/cart/lines/{variationId}` make when adding or changing a line. There is no cached/snapshotted price anywhere in this domain that a total is ever computed from.

**This corrects `pricing-domain-design.md` §7**, which — written before Cart existed — said Cart should resolve a price once at add-time and snapshot it. That guidance is now wrong for Cart specifically (right instinct, wrong domain): the snapshot-once behavior it described is exactly what **Checkout/Orders** should do (once that domain exists — see §13), not Cart. `pricing-domain-design.md` §7 has been rewritten in this same commit to state both halves clearly, and to say explicitly that it supersedes the earlier, now-incorrect wording rather than silently rewording around it.

---

## 4. `price_at_add` is stored, but strictly for display — nothing may compute a total from it

`CartLine.priceAtAddMinor`/`priceAtAddCurrency` capture what the price was at the moment a line was added. They exist for exactly one reason: letting a future storefront show the Shopify-style "this got cheaper/more expensive since you added it" notice, by comparing them against the live-resolved price on every `GET /api/cart` (surfaced as `price_changed_since_add` in that response). The accessor is deliberately named `priceAtAdd()`, never `price()`/`unitPrice()`, specifically so nobody reaches for it as if it were authoritative. This distinction is cheap to get right now and awkward to retrofit later, so it's enforced by naming, not just a comment.

---

## 5. Stock check on add is a SOFT check, add-time only — by explicit domain-owner decision

`POST /api/cart/lines` rejects a quantity greater than `StockLevelRepository::findByVariationId()->quantity()` at the moment of the request. That's the entire extent of Cart's involvement with stock: **no reservation, no hold, no decrement.** `Cart` never calls `StockLevelRepository::decrease()` — the authoritative check happens at Checkout finalization, a future task, not here.

Two consequences follow directly, and both are accepted and intentional, not bugs to design around:
- Two customers can simultaneously hold the last unit of something in their carts at the same time. Neither cart is "wrong" — the soft check only ever looks at stock at the instant of the add.
- A cart can go stale: stock can drop below what's in someone's cart after they added it, with nothing in Cart itself noticing or correcting it.

This is exactly how WooCommerce behaves by default (no cart-level stock hold without a dedicated plugin), and matches the domain owner's explicit instruction not to build reservation/holding logic here.

---

## 6. Guest→account cart merge on login: merge, never replace

On a successful `customer`-guard login (see §8 for exactly which event this hooks), if a guest cart (found by session token) and an account cart both already exist, their lines are merged into the account cart, not replaced by it. Same `variation_id` in both → **quantities are summed**, then the result is **clamped to currently-available stock** if the sum would exceed it — the merge never fails the login over a stock conflict; it silently clamps instead. The guest cart row is deleted once the merge completes.

**A CLAIMED cart is never merged, in either direction — see §14.3.** A claimed cart has already produced an order; merging it (or deleting it as the source) would both re-add purchased lines to a live cart and destroy the claim a legitimate double-submit replay depends on.

The domain owner's own reasoning, recorded verbatim because it explains the "merge, not replace" choice better than a paraphrase would: *"we're simply making sure they get their own choices back — if they don't want something, they can remove it."*

**A real tension worth being explicit about, not silently resolved either way:** the original guidance for this decision also said clamping should "ideally" be visible in the login/registration HTTP response. That's not done here — making it visible would require editing `AccountSessionController`/`AccountRegistrationController`'s response shape, and this task's scope explicitly forbids touching any `Account` source file (that domain "shouldn't grow Cart knowledge," per the same instruction that specified this merge). The merge itself still happens correctly and safely; the clamped result simply isn't visible until the customer's very next `GET /api/cart`, not inside the login/register response itself. Flagging this rather than either silently breaking the "don't touch Account" rule or silently dropping the "make it visible" request.

---

## 7. Exactly one of `accountId`/`sessionToken`, enforced in the domain layer, not the schema

A `Cart` row has either an `account_id` or a `session_token`, never both, never neither. There is no portable, cross-database way to express "exactly one of these two nullable columns is set" as a single DB-level constraint (a MySQL `CHECK` constraint could do it, but wouldn't have been portable to SQLite, which this project's own test suite still uses for some standalone package tests — see `catalog-domain-design.md` §7 on why constraints are verified against both drivers elsewhere in this project). So the real, authoritative guard is `Cart`'s own constructor, throwing `InvalidArgumentException` on either violation — the schema's two independent `unique()` columns (at most one cart per account, one per token) are a real, useful constraint in their own right, but they don't and can't enforce the XOR by themselves.

---

## 8. Cart identification over HTTP — and why a client can never supply its own token

If `Auth::guard('customer')->check()` is true, the cart is unambiguously the account's — found (or created) by `account_id`. Otherwise, the cart is keyed by a session token the **server** generates (`Str::uuid()`), stored in the Laravel session (`$request->session()->get('cart_token')`), created only on the first *write*, never on a mere read. A request never supplies its own token — not in the body, not in a header. Accepting a client-supplied token would let anyone read (or add to) anyone else's cart just by guessing or intercepting a token; the server-generated, session-bound token is the only thing standing between a guest cart and being a completely open resource.

This relies on Sanctum's stateful session pipeline, already wired up for the `customer` guard during the Account task (`account-domain-design.md` §6/§10) — no new session/cookie infrastructure was needed for Cart. The same test-suite requirement documented there applies here too: a feature test needs a `Referer: http://localhost/` header (set once in `setUp()`) for `EnsureFrontendRequestsAreStateful::fromFrontend()` to engage the session pipeline at all, or `$request->session()` never actually starts and the guest `cart_token` can't persist between requests within a test.

**The merge trigger, verified against Laravel's own source rather than assumed:** `Illuminate\Auth\SessionGuard::login()` always calls `fireLoginEvent()`, which dispatches `Illuminate\Auth\Events\Login`. `attempt()` (used by `AccountSessionController::store()`'s explicit login) calls `login()` internally on success; `AccountRegistrationController::store()`'s auto-login (`Auth::guard('customer')->login()`) calls it directly. Both paths were read line-by-line in `vendor/laravel/framework/src/Illuminate/Auth/SessionGuard.php` before writing this, not assumed from general Laravel knowledge — so a listener on `Illuminate\Auth\Events\Login` (§6's merge) correctly fires for both a guest who explicitly logs in **and** a guest who registers a brand-new account, keeping their cart either way. The listener (`App\Listeners\MergeGuestCartIntoAccountCart`) is registered via `Event::listen()` in a new `App\Providers\CartMergeServiceProvider`, not by editing either Account controller — see §6.

---

## 9. Expiry: two numbers, a column, and a manual command — deliberately no scheduler

30 days for account carts, 10 for guest carts (the domain owner's own numbers). Implemented as a plain `expires_at` column, set on cart creation and **refreshed on every write** (add/update/remove a line) so an actively-used cart never expires out from under someone mid-session — a read never refreshes it. Cleanup itself is `php artisan cart:prune` (`App\Console\Commands\PruneExpiredCarts`, calling `CartRepository::deleteExpired()`), **not** a job wired into Laravel's scheduler. This project has no scheduler configured at all yet, and quietly introducing one as a side effect of Cart would be a separate infrastructure decision this task has no business making unilaterally. Nothing runs `cart:prune` automatically today — that's a deliberate, flagged gap for a future deployment/scheduling task (§13), not an oversight.

---

## 10. Persistence: `carts.account_id` cascades, `cart_lines.variation_id` restricts — deliberately different choices, not an inconsistency

`carts.account_id` is `cascadeOnDelete()` — if an `Account` is ever deleted, its cart should simply disappear with it. A cart is disposable working state with no historical value of its own; nothing outside this domain should ever need to reference an old cart's id the way `Inventory`/other future domains reference a Variation's id.

`cart_lines.variation_id`, by contrast, is `restrictOnDelete()` — mirroring `stock_levels.variation_id`'s own choice one level up (`inventory-domain-design.md` §4), which itself mirrors `catalog_variations.product_id`. A real Variation is never allowed to vanish out from under something that still references it, cart line or not; CLAUDE.md rule 4 (historical identity never destroyed or reassigned) doesn't stop applying just because a cart is short-lived. `cart_lines.cart_id` is `cascadeOnDelete()` too, for the same reason `account_id` is — a line has no reason to survive its own parent cart being deleted.

The composite `unique(cart_id, variation_id)` (named `cart_lines_cart_variation_unique`, confirmed via a real `SHOW CREATE TABLE cart_lines` rather than assumed) is the DB-level backstop for `Cart::addLine()`'s one-line-per-variation invariant. `EloquentCartRepository` handles a genuine collision on it (the SQLSTATE-23000/1062 pattern, CLAUDE.md rule 3) not by surfacing an error, but by self-healing: it treats the collision as proof a concurrent request already inserted that exact line, and increments the existing row's quantity by this line's quantity instead — mirroring exactly what `Cart::addLine()` would have done in-memory had it seen the other request's line first, rather than showing a confused "duplicate" error to someone who only clicked "add to cart" once.

---

## 11. A new kind of allowed cross-domain dependency: `CartLineAdder` consumes Inventory's published contract directly

`CartLineAdder` (inside the `EasyCo\Cart` package) takes both `Contracts\CartRepository` (its own domain) and `EasyCo\Inventory\Contracts\StockLevelRepository` (a different domain's) via constructor injection — the one place in this package that reaches outside its own package boundary for anything beyond a plain id string.

**Worth being precise about what kind of exception this is**, because it doesn't fit CLAUDE.md rule 9's existing carve-out cleanly. That rule's only stated exception to "cross-domain references are always by id/string, never a direct package dependency" is a **pure value object** (`Money`, reused directly by `OperationalSales` — not a domain aggregate, not a repository). `StockLevelRepository` is neither of those things — it's a repository *contract* (an interface, never its Eloquent implementation, never Inventory's concrete classes). This task's own instructions authorized this specific pattern explicitly, drawing the analogy to `RestrictedPriceWriteGuard` consuming its own domain's contracts — an imperfect analogy, since that guard's dependency is on its *own* package's contract, not another domain's. Recorded here plainly rather than silently treated as if it already fit the existing rule: depending on another domain's *published* `Contracts/` interface (never its concrete implementation, never instantiated directly) is being extended, by explicit decision for this task, as a second allowed category of cross-domain dependency alongside the value-object one — not something Cart invented unilaterally.

`CartLineAdder` deliberately does **not** touch Pricing or Catalog. Building a `PriceContext` needs Catalog data (`matchingScopeReferenceIds` — see §12) that only the app-layer controller has access to; that assembly stays in `CartController`, which passes an already-resolved `priceAtAddMinor`/`priceAtAddCurrency` into `CartLineAdder::addLine()` purely to attach to the new line for later display (§4). Cart never depends on Catalog or Pricing at the package level, only at the app/ composition-root level, in the controller.

---

## 12. One remaining flagged limitation; the price-not-configured gap is now fully closed

- **No `matchingScopeReferenceIds` scope matching — still open.** `PriceContext::$matchingScopeReferenceIds` lets a `PriceList` scope itself to a brand/category/tag, but computing that array needs Catalog data the controller doesn't currently assemble. `CartController` passes an empty array for V1. A price list scoped by brand/category/tag will simply not match for cart pricing until a Catalog-reading helper is built to compute this — not designed or stubbed in this task, and explicitly not pretended away.
- **An unpriced Variation — both being ADDED and already SITTING in a cart — is now handled cleanly. FIXED, in two passes.** `EloquentPriceResolver::resolve()` had two distinct `RuntimeException` throw sites doing two genuinely different jobs — the "Regular Prices" system list itself missing (real system misconfiguration) versus a specific `priceableId` simply having no price yet (a normal, expected business state). Only the second now throws a dedicated, catchable `EasyCo\Pricing\Exceptions\PriceNotConfiguredException` (`extends RuntimeException`, so nothing that already caught the generic type breaks); the first throw site was deliberately left untouched — see `pricing-persistence-domain-design.md` §4.6.
  - **Pass 1 (add-to-cart):** `CartController::store()` catches `PriceNotConfiguredException` around the line being actively added and returns a `422` naming the variation — you cannot add an unpriced variation to a cart at all.
  - **Pass 2 (lines already in the cart):** `CartController::serializeCart()` — used by `GET /api/cart` and the response body of every write — now catches `PriceNotConfiguredException` **per line, individually**, for a line whose price was added successfully but has since been fully removed (a real scenario: a merchant deletes a `PriceListItem` for a product still sitting in someone's cart). That one line degrades gracefully rather than taking the whole response down with it: `unit_price`/`line_total` become `null`, a new `price_available: false` field marks it (every other line gets `price_available: true`), `price_changed_since_add` is forced `false` (nothing live to compare `price_at_add` against), and — critically — **the line is excluded from the cart-level `total` entirely, never treated as zero**, which would have silently understated what the customer owes. Confirmed by real Feature tests: a `GET` with one priced and one since-unpriced line returns `200` with the priced line normal and the unpriced line degraded as above, and a `PATCH` on the *priced* line in that same cart succeeds without being taken down by the *other*, unpriced line.
  - Neither pass touches `EloquentPriceResolver`'s untouched, genuinely-uncatchable throw site — it still fails loudly, uncaught, everywhere, as intended.

---

## 13. Deferred (documented, not accidental)

- **Promotions/discount codes.** A separate future domain per `pricing-domain-design.md` §1 — explicitly **not** part of Pricing, and not Cart's job either. Cart resolves the catalog price only; any cart-level discount adjustment is a future Promotions concern layered on top, the same separation `pricing-domain-design.md` §7 already draws.
- **Checkout finalization** — the future task that will (a) run the *authoritative* stock check (as opposed to Cart's soft, add-time-only one, §5), (b) snapshot each line's live-resolved price exactly once into a real `OperationalSales.Transaction`/`SaleLine.amount` (§3), and (c) finally decide how a logged-in `Account` maps to an `OperationalSales.Client` — the link `account-domain-design.md` §11 explicitly deferred to this same future task. None of this exists yet; Cart only produces the live-priced, stock-soft-checked line data Checkout will eventually consume.
- **`matchingScopeReferenceIds` scope matching** — §12's first flagged limitation, restated here for visibility in the deferred list a future session will scan first.
- **Scheduling `cart:prune`.** §9 — the command exists and works; nothing calls it automatically. A future deployment/scheduling task needs to wire it into Laravel's scheduler or an OS-level cron/Task Scheduler entry.
- **Abandoned-cart recovery** (`cart-abandoned-recovery-note.md`) — the actual reason guest carts are persisted at all (§1), but the recovery mechanism itself (a `cart.abandoned` Hook action, email/push listeners, the single-use discount-code generation it would need from Pricing) is not built in this task. Genuinely next in line per that note's own stated priority, not merely a someday item.

---

## 14. A claimed cart stops being the current cart — the fix, and how double-submit protection survives it

**The defect, stated precisely** (found in real use, tracked as its own task): `carts.order_id` marks a cart as claimed (`checkout-domain-design.md` §6), but nothing on the *lookup* path cares. `findByAccountId()`/`findBySessionToken()` return a claimed row like any other, so after a successful checkout the claimed cart stays the customer's current cart **forever**: `GET /api/cart` keeps replaying the lines that were just bought, and a second `POST /api/checkout` in the same session or account returns the **first** order instead of placing a second one. A customer who buys twice in one session cannot buy the second thing at all.

The two `unique()` indexes on `account_id`/`session_token` make the obvious one-line idea — filter `order_id IS NULL` into the lookups — impossible as well as insufficient: they forbid the very row the next purchase needs, so the permanently-missing filter would turn the second purchase into a 1062 duplicate-key error rather than merely the wrong order.

### 14.1 Identity moves to `claimed_*` columns at claim time

**Decision:** the claim, in the same transaction as today (`checkout-domain-design.md` §8.3 step 11), also **moves the identity**. `account_id`/`session_token` are copied into new, non-unique, indexed `claimed_account_id`/`claimed_session_token` and set to NULL on the row. The live columns keep their `unique()` indexes, which now mean exactly what they should always have meant: *at most one live cart per identity, ever*.

**Why this shape:** current-cart lookups stay what they already are — a plain `where('account_id', …)` — and become correct **by construction**. There is no `order_id IS NULL` filter for a future caller, join or report to forget, because there is nothing to filter: a claimed row no longer carries an identity any lookup asks about. The defect cannot silently come back.

**Alternatives evaluated, and rejected:**

- **Filter the lookups only (`whereNull('order_id')`), no schema change.** Impossible on its own (the unique indexes, above), and weak even combined with the generated-column option below: correctness would live in every caller's memory.
- **Move the unique indexes onto generated columns** — `live_account_id = IF(order_id IS NULL, account_id, NULL)`, with the two `UNIQUE` indexes on those. This is MySQL's nearest equivalent to PostgreSQL's partial unique index, which MySQL does not have. It works, and it keeps the identity readable in place on a claimed row. Rejected because the row still holds a *real* `account_id`/`session_token` value: every existing lookup, join and report would still match a claimed cart unless each one remembers the `order_id IS NULL` rule — the same forgetting risk that produced this defect, moved one column over. Recorded here as the fallback if a future need ever requires the identity to stay in place.
- **A `status`/`claimed_at` column plus an application-level invariant.** Strictly worse: no DB-level uniqueness for the live case at all, and the same forgetting risk.
- **A separate `cart_claims` table.** A whole table for one nullable column, plus a join on the checkout hot path, to express a fact that belongs to the cart row itself. Rejected.

**Deliberately NOT moving: the `Cart` aggregate.** `accountId`/`sessionToken` stay `readonly` and the domain's XOR rule (§2/§7) is untouched; the claim remains a raw, model-level atomic operation, exactly as `claimForOrder()`'s own docblock describes it. Neither `reconstituteFromStorage()` nor any aggregate method ever sees a claimed row — the one thing that must read one (the replay check, §14.2) reads a small value object instead, never a `Cart`.

**`Cart` can never be reconstituted from a claimed row — enforced, not merely intended.** `CartRepository::findById()` returns `null` for a cart that has a claim (`whereNull('order_id')` at the SQL level) **and for a row with no live identity at all**, so no code path can build an aggregate out of a row whose live identity is NULL — which `Cart`'s own XOR invariant would reject anyway, with an `InvalidArgumentException` that would surface as a 500. Every aggregate-returning read (`findById()`, `findByAccountId()`, `findBySessionToken()`) therefore excludes claimed rows by construction, and the replay check reads `CartClaim`, never a `Cart`.

The second exclusion is not hypothetical: `carts.order_id` is `nullOnDelete` (`checkout-domain-design.md` §6), so deleting an Order un-claims the row **without** restoring the identity it moved away — leaving a row that is neither claimed nor a cart anybody could add to. It is disposable history, and `findById()` treats it as absent rather than as an aggregate it cannot build.

The one race this leaves — a cart claimed between the replay check and the transaction's own load of it — is closed by resolving that not-found outcome exactly like a lost claim, never by loading the claimed row (`CheckoutOrchestrator` resolves the claim and answers `already_placed`; a genuinely unknown cart still `404`s).

**A guest's next cart REUSES the same session `cart_token`.** The claim moved the token to `claimed_session_token`, so the live `session_token` unique index is free again: `CartController::currentOrNewCart()` finds no live cart by that token and creates a new one **with the same token** — no new token is generated and nothing in the session changes. That is what keeps §14.2's replay working for a guest who has already started their next purchase: the late replay matches `claimed_session_token`, which is still the token their session holds.

### 14.2 Double-submit protection: the page's `cart_id`, and who is allowed to ask

**Decision: `POST /api/checkout` REQUIRES `cart_id`** — the id of the cart the page displayed — and `GET /api/cart` returns `cart_id` (an additive display field, `null` when the identity has no live cart). The semantics, exhaustively:

| `cart_id` in the request | state of that cart | result |
|---|---|---|
| given | claimed, and the identity recorded at claim time matches the requester (the account, or the session's own `cart_token`) | `201`, `already_placed: true`, **that** order — nothing written, never re-charged |
| given | claimed, identity does **not** match | `404`, exactly as if the id were unknown — never another customer's order, never a hint that it exists |
| given | live and owned by the requester | checked out normally |
| given | live but owned by someone else, or unknown | `404` |
| **absent** | — | **`422`** — `cart_id` is a required field of this request, not a fallback path |

**Why REQUIRED, and not optional — amended after review of this section's first draft.** An optional `cart_id` with an identity-resolved fallback looks harmless and is not: the fallback can only ever find a *live* cart, so a **sequential** second click after a completed checkout — the customer pressing "place order" again rather than double-clicking it — would find no live cart and get `404` instead of `already_placed`. The double-submit protection would then depend on the client choosing to send the field, i.e. it would silently disappear exactly when the client is oldest or the page is oldest. Requiring it makes the protection unconditional: every checkout names the cart it is buying, and every replay can be answered. The cost is zero today — no external client exists (the sandbox sends it), and every client already receives `cart_id` from `GET /api/cart`, so there is nothing to be compatible with. A request without it is a **`422`** from request validation, before any cart is looked at.

**Why the ownership check lives in the repository** — `findClaimForIdentity(string $cartId, ?string $accountId, ?string $sessionToken): ?CartClaim`: one method that cannot be called without stating who is asking. A controller, orchestrator or test that wants a claim must hand over an identity, and gets `null` for anything that is not the requester's — so "someone else's `cart_id`" can only ever produce `404`. Same "one place, impossible to forget" posture as §14.1, applied to the read side.

**What does not change:** the atomic claim itself; the zero-affected-rows → rollback → resolve-idempotently shape (`checkout-domain-design.md` §6); and §8's rule that a guest's token is server-generated and session-bound. A `cart_id` is an id the client is already told about its **own** cart — never a credential, and a foreign one buys nothing and reveals nothing.

**Returning `cart_id` from `GET /api/cart` is part of this decision, not a nicety.** Without it no client can ever name the cart it displayed, so the replay protection above would have no way to be triggered; and it is the same class of additive, display-only field the sandbox's cart/checkout pages already consume (variation display data), with no change to how a cart is priced, claimed or identified.

### 14.3 The guest→account merge never touches a claimed cart

**Decision** — in `MergeGuestCartIntoAccountCart` (§6's own flow, app layer):

- a **claimed guest cart** is neither merged from nor deleted. The listener forgets the session's `cart_token` and returns, so the guest's purchased lines are never re-added to a live cart and the claim — its `order_id` link, and with it §14.2's replay answer — survives;
- a **claimed account cart** is never merged *into*. The merge target is found by `findByAccountId()`, which after §14.1 can no longer see a claimed row, so a live target is created when the account has none. The listener therefore needs no target-side special case at all; only the source side does.

Without the source-side check, logging in after a guest purchase would have merged the just-bought lines back into the account's cart **and deleted the claimed row**, turning a legitimate replay into a `404` (`findClaimForIdentity()` would find nothing) — a second, quieter defect of exactly the same family. Closed here rather than left to be discovered.

**NO LISTENER CHANGE WAS NEEDED to achieve either half**, and that is worth stating rather than leaving to be inferred: after §14.1, `findBySessionToken()` cannot see a claimed guest cart (it has no live token) and `findByAccountId()` cannot see a claimed account cart (it has no live account id). So the listener's existing "no guest cart → forget the session token and return" branch *is* the §14.3 behaviour for the source side, and its merge target is freshly created when the account has none. The two listener tests added with this change pin exactly that, instead of pinning a new code path that does not exist.

### 14.4 Expiry and cleanup: claimed carts stay disposable

**Decision:** `deleteExpired()` — and therefore `cart:prune` (§9, §13) — keeps deleting claimed carts on exactly the same rules. A claimed cart is still working state with no historical value of its own (§10's own reasoning, the same one `checkout-domain-design.md` §6 quotes). No exemption, no separate retention policy, no second command.

**Consequence, documented rather than discovered:** once a claimed cart is pruned, a replay carrying its `cart_id` finds no claim and is answered like any other unknown cart — `404`, not `already_placed`. That window is the cart's own expiry (10 days guest / 30 days account, §9), orders of magnitude longer than any double-click. The alternative — exempting claimed carts from pruning — would keep every checked-out cart forever while no customer can see it, which is precisely what §10 says this table's rows should not become. Since `cart:prune` is deliberately manual and unscheduled (§13's deferred item), the window is in practice whatever an operator's schedule makes it; noted here because that is now a replay-relevant fact and not only a disk-space one.

### 14.5 The migration (Cart package, after `2026_09_06_000002`)

- **Schema.** Adds `claimed_account_id` (nullable, `cascadeOnDelete` toward `accounts` — a claimed cart must not outlive its account either, and for a claimed row this is now the only FK that can carry that rule) and `claimed_session_token` (nullable, indexed, **not** unique — several claimed carts may legitimately share a token's history). The live columns and their `unique()` indexes are untouched; they simply hold `NULL` on a claimed row. `carts_order_id_unique` and its `nullOnDelete` FK are untouched (`checkout-domain-design.md` §6's own guarantees).
- **Data.** In the same migration's `up()`, every already-claimed row (`order_id IS NOT NULL`) moves its `account_id`/`session_token` into the claimed columns and NULLs the live ones — the dev database included, verified by a test against a claimed fixture rather than assumed.
- **`down()` reverses it where it can, and says so.** Live identity is restored only for rows whose identity does not collide with a *live* cart created since (after this change one identity legitimately holds one claimed **and** one live cart). A row that cannot be restored keeps its claimed values until the columns are dropped. "Reversible" must mean "reversible without silently failing", not "reversible after deleting the customer's new cart" — failing the rollback, or dropping data to force it, would both be worse than a documented, partial inverse.

### 14.6 Tests the implementation must have

**Repository level** (`EloquentCartRepositoryTest`):

1. Claiming NULLs the live identity and fills the claimed columns — asserted on the raw row, never through the aggregate.
2. `findByAccountId()`/`findBySessionToken()` return `null` after a claim (the claimed cart is no longer the identity's current cart).
3. A new cart for the same identity then saves **without** a duplicate-key error, and is the one both lookups return — the unique indexes' new meaning, proven rather than assumed.
4. `findClaimForIdentity()` answers for a matching account **and** for a matching session token, and returns `null` for a foreign identity, for an unknown id and for a live cart.
5. `deleteExpired()` still removes an expired claimed cart, after which the same lookup returns `null` (the §14.4 window, pinned).
6. The `SHOW CREATE TABLE carts` assertions extended to the new columns, their indexes and the claimed FK; the existing `order_id` uniqueness/`nullOnDelete` assertions unchanged.

**HTTP level** (`CheckoutControllerTest` plus a focused new file, one test per row of §14.2's table):

7. A **guest** orders, then adds again → a **new** cart id holding only the new line, and a second checkout places a **second order** (`already_placed: false`, different id) — the reported defect, gone.
8. The same flow for a **logged-in account**.
9. A **double-click** — two identical requests carrying the same `cart_id` → second response `already_placed: true` with the same order id, exactly one order row and one payment row, stock decremented once (the protection §14.2 exists to keep).
10. Another customer's `cart_id` → `404`, whose body contains neither their order id nor any trace of their cart.
11. An unknown `cart_id` → `404`.
12. No `cart_id` with a live cart → unchanged behaviour; no `cart_id` with only a claimed cart → `404` with the documented message.
13. `GET /api/cart` returns `cart_id` equal to the live cart's id, and `null` when the identity has none.

**Merge** (`CartMergeOnLoginTest`):

14. A claimed guest cart is neither merged nor deleted: its lines are absent from the account cart, and its claim still resolves afterwards.
15. A claimed account cart is not merged into: the guest's lines land in a **new** live account cart.
16. The session's `cart_token` is forgotten in both cases, so the claimed cart can never become "current" again.

**Sandbox and docs** (`SandboxCheckoutFlowTest`, the manual checklist):

17. The checkout page posts the `cart_id` it displayed (asserted on the rendered page and by the flow test), after a successful order the cart page shows an empty cart instead of the purchased lines, and replaying the same `cart_id` still returns the first order.
18. `sandbox-manual-test-checklist.md` scenario 10 rewritten — the defect note there is deleted in the same change that removes the defect.

**Regression:** the whole existing suite, with `CheckoutOrchestratorTest`'s unknown-cart, claim-lost and replay cases unchanged in behaviour (their shapes stay; only the identity check inside them is new).




