# Account Domain Design

**Status:** v1.0 — domain layer, persistence, and HTTP surface all implemented in one pass. `Account` (`register()`/`reconstituteFromStorage()`, email normalized + validated at construction), `Contracts/AccountRepository`, `Contracts/PasswordHasher`, `EloquentAccountRepository`, `LaravelPasswordHasher`, the `accounts` migration, and `AccountServiceProvider` are all implemented and tested (27 tests: 7 in the package's own domain suite, 20 across `tests/Feature`). HTTP surface: `POST /api/account/register`, `POST /api/account/login`, `POST /api/account/logout`, `GET /api/account/me` — session-based via a new `customer` Laravel auth guard and Sanctum's SPA (stateful) mode. See §11 for what's still open.

**Builds on:** the domain/persistence isolation principle already established by `catalog-domain-design.md`/`pricing-domain-design.md`/`media-domain-design.md` (a domain class never imports Laravel); the `Contracts/` + Laravel-backed-implementation pattern `EasyCo\Media` already established for `MediaStorageAdapter`/`MediaImageProcessor`, reused here for password hashing (§3); the SQLSTATE-23000-plus-driver-code unique-constraint-collision pattern from `catalog-domain-design.md` §7 (CLAUDE.md rule 3), reused here for email uniqueness (§5).

**Relates to:** `operational-sales-domain-design.md` §2's `Client` entity. **No link exists between `Account` and `Client` as of this document** — that connection is explicitly deferred to a future Checkout-orchestration task, by domain-owner decision (§11). This document does not modify `OperationalSales\Client` or its persistence in any way.

---

## 1. Why a new domain, and why not `App\Models\User`

Laravel ships a `users` table and `App\Models\User` out of the box, wired to the default `web` guard. This project deliberately does **not** reuse either for storefront customers. `users`/`User`/`web` are left completely untouched, reserved for a possible future staff/admin login — conflating "the person browsing the storefront" with "the person who might eventually manage the store" would tie two identities together that have no reason to share a table, a password policy, or a guard, just because Laravel's scaffolding defaults to one users table for everything.

Instead: a new `accounts` table, a new `EasyCo\Account\Persistence\Eloquent\AccountModel` (implementing `Illuminate\Contracts\Auth\Authenticatable` directly via the `Authenticatable` trait, not by extending `App\Models\User`), and a new `customer` guard in `config/auth.php` pointing at it through a new `accounts` provider. The two systems can now diverge freely — different password policies, different registration flows, different future MFA requirements — without one accidentally constraining the other.

---

## 2. The core model

```
Account
├── id
├── email            normalized to lowercase at construction — see §5
└── passwordHash      an already-hashed string; the domain layer never
                       sees or validates a plaintext password
```

Deliberately minimal, mirroring `EasyCo\OperationalSales\Client`'s shape closely: a private-by-convention constructor reached only via `register()` (new) or `reconstituteFromStorage()` (persistence-layer only), `id()`/`assignId()` following the exact same one-time-assignment pattern as `Client::assignId()`/`ProductMedia::assignId()`/every other entity in this codebase.

---

## 3. Password hashing lives behind a contract — but login doesn't use it

`Contracts/PasswordHasher` (`hash()`/`verify()`) exists so the pure `Account` domain class never has to import `Illuminate\Support\Facades\Hash` — the same boundary `MediaStorageAdapter`/`MediaImageProcessor` already draw for storage/image-processing infrastructure. `LaravelPasswordHasher` is the one (and, for now, only) implementation, wrapping `Hash::make()`/`Hash::check()`.

**Only registration uses this contract.** `AccountRegistrationController::store()` calls `PasswordHasher::hash()` on the incoming plaintext password before constructing `Account::register()` — the domain class receives and validates only the resulting hash (non-empty), never the plaintext, and has no way to enforce a minimum length on something it never sees. That's why the 8-character minimum (§4) is validated at the HTTP layer's `$request->validate()` call, not inside `Account`'s constructor — a deliberate split, not an oversight.

**Login does *not* go through `PasswordHasher::verify()`.** `AccountSessionController::store()` calls `Auth::guard('customer')->attempt(['email' => ..., 'password' => ...])`, which resolves to Laravel's own `EloquentUserProvider` — and that provider already calls `Hash::check()` internally against `AccountModel`'s `password` column as part of its standard `retrieveByCredentials()`/`validateCredentials()` flow. Routing login through `PasswordHasher::verify()` as well would mean re-implementing (and risking a subtly different version of) exactly what `Auth::attempt()` already does correctly. `verify()` stays in the contract for a future flow that genuinely needs it outside the guard's own attempt cycle — e.g. "confirm your current password before changing your email" — but nothing calls it today.

---

## 4. V1 scope — confirmed explicitly, not to be expanded without a new decision

Email + password, nothing else. No name, no phone, no address. No email verification — an account is fully active the moment `POST /api/account/register` returns 201. No link to `OperationalSales.Client` (§11). Password minimum length is 8 characters, matching Laravel's own Breeze/Fortify default, enforced via `min:8` at the HTTP layer (see §3 for why it can't live in the domain layer). Registration also requires `password_confirmation` (Laravel's `confirmed` rule) — ordinary registration-form practice, essentially free to add, recorded here as a conscious choice rather than something that crept in unnoticed.

---

## 5. Persistence: a separate table, soft-deletes from day one, and the email-uniqueness pattern

**`accounts`** — `id`, `email` (`unique()`), `password` (Laravel's `Authenticatable` trait expects exactly this column name for `getAuthPassword()`), `remember_token`, `timestamps()`, and **`softDeletes()`**, added now even though no deactivation flow exists yet in V1. CLAUDE.md rule 4 (historical identity is never destroyed or reassigned) is a hard rule, not a situational one, and `Account` is very likely to be referenced by id from other domains soon (Cart, and eventually `OperationalSales.Client` once §11's link is designed) — adding `softDeletes()` to a brand-new, empty table costs nothing; retrofitting it onto a live table with real foreign-key references later would not.

The auto-generated unique-index name, `accounts_email_unique`, was checked directly against a real `SHOW CREATE TABLE accounts` (21 characters — nowhere near MySQL's 64-char identifier limit, so no explicit short name was needed; verified, not assumed, per CLAUDE.md rule 5).

**Email is normalized to lowercase in two places**, deliberately redundant: inside `Account`'s own constructor (so the in-memory domain object is always normalized, regardless of caller), and again in `EloquentAccountRepository::findByEmail()`'s query (`strtolower($email)` before the `WHERE`) — so a lookup with any casing finds the same row even if a row somehow existed with different casing already. This closes a real, common bug class: `Foo@x.com` and `foo@x.com` silently becoming two different accounts.

**Uniqueness is enforced twice, at two different layers, on purpose:** the DB-level `unique()` index is the actual race-condition-safe guarantee; `EloquentAccountRepository::save()` additionally catches the resulting `QueryException` and translates it into `EmailAlreadyRegisteredException` — detected via SQLSTATE `23000` plus the MySQL-specific error code `1062` (or SQLite's `19`) read from `QueryException::$errorInfo`, then narrowed further by checking `errorInfo[2]` for the real constraint name (`accounts_email_unique`) — **never** `$e->getMessage()` string matching. This is the same pattern already used for `catalog_variations.attribute_signature`, `catalog_products.slug`, `catalog_variations.sku`, and `catalog_product_media`/`catalog_variation_media`'s pivot uniqueness — CLAUDE.md rule 3, applied here for the fourth-plus time, not reinvented. `AccountRegistrationController::store()` catches `EmailAlreadyRegisteredException` and returns a clean 422, never a raw DB exception.

---

## 6. Session/cookie auth: Laravel Sanctum's SPA (stateful) mode

This is the first time this codebase's HTTP layer needs cookies or a session at all. Every route added before this task ran through Laravel's default **stateless** `api` middleware group (`throttle:api` + `SubstituteBindings` only, confirmed directly in `bootstrap/app.php` before this change) — no session, no CSRF, no cookies anywhere in the stack.

**What changed:**
- `composer require laravel/sanctum` (v4.3.3) — installed cleanly, no issues.
- Sanctum's own migration (`personal_access_tokens`) published and run. SPA/stateful mode doesn't use this table directly, but Sanctum's service provider expects it to exist.
- `bootstrap/app.php`: `\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class` prepended to the `api` middleware group via `$middleware->api(prepend: [...])`.
- `config/sanctum.php`'s `'guard'` array, published with only `['web']` by default, updated to `['web', 'customer']` — Sanctum's stateful middleware needs to know every guard it should be willing to authenticate against; leaving `customer` out would have made every `auth:customer` check fail even with a valid session cookie present.
- `.env`: `SANCTUM_STATEFUL_DOMAINS=easyco-main.test,localhost,127.0.0.1` — set explicitly for this project's real local dev hostname (Laragon's `.test` domain, confirmed as the actually-reachable address during the earlier storage/queue-worker verification task) rather than left to guesswork.
- `GET /sanctum/csrf-cookie` is registered automatically by Sanctum's own service provider — nothing hand-built for it.

**How the stateful pipeline actually engages, confirmed by reading Sanctum's own source rather than assumed:** `EnsureFrontendRequestsAreStateful` only pushes the session/cookie/CSRF middleware stack (`StartSession`, cookie encryption, CSRF validation, `AuthenticateSession`) onto a request it recognizes as `fromFrontend()` — which checks the request's `Referer` or `Origin` header against `config('sanctum.stateful')`. A request with neither header, or one from a non-listed domain, is left on the plain stateless pipeline and can never establish or read a session, regardless of what the guard's own logic does. This matters directly for the test suite (§10).

**Rate limiting:** `throttle:6,1` (6 attempts/minute — Laravel Breeze's own long-standing default) on `POST /api/account/login` only, not registration. Not something the domain owner was asked about explicitly, but cheap, standard, and consistent with the production-hardening posture already flagged in `server-stability-observations.md`.

---

## 7. HTTP surface

| Route | Middleware | Behavior |
|---|---|---|
| `POST /api/account/register` | — | Validates `email`/`password` (+`password_confirmation`), hashes, saves, logs the new account straight in (`Auth::guard('customer')->login()`), fires `account.registered` (§8), returns `201` with `id`/`email` — never the hash. |
| `POST /api/account/login` | `throttle:6,1` | `Auth::guard('customer')->attempt()`. Success: regenerates the session, returns `200` with `id`/`email`. Failure: `401` with the **identical, generic** `"Invalid credentials."` message whether the email doesn't exist or the password is wrong — deliberately never distinguishing the two, to avoid confirming which emails are registered. |
| `POST /api/account/logout` | `auth:customer` | Logs out, invalidates the session, regenerates the CSRF token, returns `204`. |
| `GET /api/account/me` | `auth:customer` | Returns the authenticated account's `id`/`email`, `200`. |

No list/update/delete-account endpoint in V1 — not asked for, not built.

---

## 8. `account.registered` Hook

Fired once, from `App\Http\Controllers\Api\AccountRegistrationController::store()` **only** — never from inside `Account` or the `EasyCo\Account` package itself, per CLAUDE.md rule 10 (domain packages never call `Hook::` directly; only app/ layer code does).

| Hook | Type | Fired from | Signature | Purpose |
|---|---|---|---|---|
| `account.registered` | Action | `App\Http\Controllers\Api\AccountRegistrationController::store()` | `(Account $account): void` | Extension point for whatever should happen after a new customer registers — a welcome email, analytics, a CRM sync. **No listener is registered in this task** — the same "purely the extension point, zero listeners" posture already established for `catalog.variation.barcode` in `extensibility-design-and-hooks.md`. |

Also added as a row to `extensibility-design-and-hooks.md`'s own Hook Reference table, in the same commit as this document, per that table's own stated rule.

---

## 9. Where the domain-layer/framework-layer boundary actually sits here

`EasyCo\Account`'s `src/` never imports Illuminate anything except inside `Persistence/` and `Security/` — the same layering every other domain package in this project follows. `Account.php` and the two `Contracts/` interfaces are pure PHP; `AccountModel`, `EloquentAccountRepository`, and `LaravelPasswordHasher` are the only files that touch Eloquent/`Hash`. `AccountServiceProvider` mirrors `MediaServiceProvider` exactly: bind the two contracts, `loadMigrationsFrom()` in `boot()`.

---

## 10. Testing notes — the non-obvious mechanics found while writing feature tests

Two things about testing session-based Sanctum auth turned out to matter and are recorded here so a future session doesn't have to rediscover them:

**A `Referer: http://localhost/` header is required on every feature-test request that needs a working session** (`AccountRegistrationControllerTest`/`AccountSessionControllerTest` both set this once in `setUp()` via `$this->withHeader(...)`). Without it, `EnsureFrontendRequestsAreStateful::fromFrontend()` returns false, `StartSession` never runs, and `Auth::guard('customer')->attempt()`/`->login()` fail the moment they try to touch `$request->session()`. `'localhost'` is already present in `config('sanctum.stateful')`'s default fallback list even without `.env.testing` setting `SANCTUM_STATEFUL_DOMAINS` explicitly, which is why that header value works without any test-environment config changes.

**CSRF validation does not need to be worked around in tests — it already excludes itself.** `Illuminate\Foundation\Http\Middleware\PreventRequestForgery::handle()` (the class `ValidateCsrfToken` now extends) contains its own `runningUnitTests()` check and passes every request straight through when true. Confirmed directly in the framework source before relying on it, rather than assumed — no `withoutMiddleware()` call was needed anywhere in the new test files, and none should be added speculatively if this ever needs revisiting for a real browser-driven test instead.

---

## 11. Deferred (documented, not accidental)

- **Email verification.** An account is active immediately on registration; there is no "verify your email" step, no unverified state, no resend-verification flow. Not designed here.
- **Name/phone/address fields.** `Account` is email + password only, by explicit V1 scope decision (§4) — adding any of these is a real, separate design decision, not a natural extension to make casually.
- **The `OperationalSales.Client` link.** `Account` and `Client` are two entirely separate, unconnected concepts as of this document. Deciding how (or whether) a logged-in `Account` maps to a `Client` for the purposes of recording a sale is explicitly the future Checkout-orchestration task's job, per `checkout-orchestration-performance-note.md`'s existing scope and the domain owner's own instruction for this task. Do not add this link speculatively.
- **Password reset / forgot-password.** Not mentioned in this task's original scope, and explicitly flagged here as a gap rather than silently left unmentioned: a customer with no way to reset a forgotten password is a real, near-term problem for any store actually taking registrations, even though it doesn't block a V1 registration/login/logout flow existing at all. Whoever picks up Account next should treat this as an expected, obvious next step, not a surprise gap.
