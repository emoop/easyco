# ACC-4 — GDPR: export, «forget me», retention, admin «Клиенти» (design, owner decisions 2026-11-20)

Status: owner decisions D1–D8 taken; D2 revised 2026-11-21 (requests only through the merchant). Technical design §3–§9 written after the read-only inventory ACC-4-0 (commit `1818ab1`).

## 1. Owner decisions — do not re-open

**D1. Orders and retention.**
- On account deletion / «forget me», orders stay intact (names, address, phone, email). They are unlinked from the account.
- After the retention period, the personal data in an order is anonymized automatically. Amounts, lines, date and number stay.
- Retention is an admin setting, default **10 years**. Under the Accounting Act, registers and financial statements are kept 10 years from 1 January of the following period; the owner confirms the exact value with his accountant.
- «Forget me» removes the person **immediately** from every mail list and ad audience.
- A **log of forget requests** («дневник на забравените») lets the merchant look people up and tick manual removals from external lists.

**D2. Who can request — REVISED 2026-11-21 (owner, after research): only through the merchant.**
- **No public page and no self-service buttons** (no «Моите лични данни», no «Изтрий профила» / «Изтегли данните ми» in «Данни»): a visible button provokes the action.
- The customer writes to the email named in the privacy policy, saying what they want. The merchant acts from admin «Клиенти» («Изтегли данните», «Забрави»), within the legal month.
- **Identity check when in doubt:** when the request comes from an address other than the one in the orders, the merchant presses «Поискай потвърждение». The system mails a signed link to the email on record; the person confirms on a no-store page (POST, no state change on GET); the request shows «Потвърдено», and the merchant then acts. This is the same model as WordPress/WooCommerce.
- **The same model as Shopify and WooCommerce:** the merchant acts from the admin, orders stay, personal data is removed at retention.
- A narrower request («stop the mails», «remove one address») is not «Забрави»: it is an unsubscribe (ACC-5) or an edit.
- The privacy-policy text must name the contact email for requests. That is the merchant's content, not code.

**D3. Export.**
- A ZIP with a readable HTML page and a machine-readable JSON.
- Content: profile, addresses, orders (lines, amounts, delivery, statuses), consents with dates, list/audience memberships, IP addresses while still kept.
- **Staff notes are NOT exported.** They are deleted on «forget me».
- **Revised by D2:** only the merchant starts it, from admin. The file is built in the background. The staff member downloads it and sends it to the customer. The file is deleted from the server after 7 days.

**D4. Account deletion.** **Revised by D2:** there is no self-service deletion; the merchant runs «Забрави», which deletes the account too. The password + 1-hour mail link flow below is dropped. What a deletion does stays as written:
- Everything happens at the click: account, addresses, consents, carts and sessions are deleted; orders are unlinked; the person leaves all lists; a log entry is written.
- A final confirmation mail is sent.
- With an unfinished order the deletion is allowed. A warning says the order continues and its mails still come.

**D5. Admin «Клиенти».**
- **One row per email, accounts and guests together.** Columns: name (from the latest order), email, phone, order count, total spent, last order, «Профил» badge.
- Search by name, email and phone.
- The customer page shows: data, addresses, orders, consents, lists, a staff note, and the buttons «Изтегли данните» and «Забрави».
- «Забрави» is **Administrator only**, with a confirmation and a required reason (goes into the log).
- Admin export gives the same ZIP; the merchant sends it to the customer.
- UI is shown to the owner before the coder prompt.

**D6. Forget log and suppression list.**
- **Log entry fields:** date, source (site / admin), staff member, reason, automatic actions (lists/audiences removed from), manual-step checkboxes (e.g. «махнат от аудиторията във Facebook»).
- **Email and phone are visible in clear for 30 days**, then only a keyed hash remains. The lookup «was there a forget request for this email?» keeps working through the hash.
- **Suppression:** the hash blocks adding the person to any list or audience. A later explicit double-opt-in signup is new consent and lifts the block. A new order without the newsletter tick adds them nowhere.
- **Admin «Табло» tile:** «Заявка за забравяне» with the remaining clear-view days.
- **Not in ACC-4:** a separate small task after it — the «Отворени поръчки» tile (count + sum).

**D7. COD order blocks on «forget me»:** the block stays until it expires (fraud prevention is a legitimate interest; otherwise anyone could lift their block with one click). The log records «блокировката е запазена до …».

**D8. Order immutability exception (owner 2026-11-20).** `Order::anonymise()` becomes the second allowed mutation after `assignId()`, documented like the rule-7 exception for `assignProductId`:
- personal fields only;
- only after the retention period;
- one-way;
- recorded in the order history as «анонимизирана на …».

Amounts, lines and statuses never change. This is lawful erasure of data whose period has ended, not a rewrite of history. ACC-4b updates `order-context-design.md` (the «orders is immutable» line) and CLAUDE.md rule 4 with this exception in the same diff.

## 2. Facts from ACC-4-0 that shape the design
- Deleting an `accounts` row cascades `addresses`, `carts` (+lines), `account_email_changes`. It sets `account_id = NULL` on `orders`, `promotion_redemptions` and (per its comment, unverified) `operational_sales_clients`. `AccountModel` uses SoftDeletes, but the prune uses `forceDelete()`.
- `account_password_reset_tokens` is keyed by email, with no FK. Its rows stay after deletion.
- **Order identity keys `email_key` / `phone_key` are plain text** (lower-cased email, last phone digits). `place_key` is sha1. They are personal data and are cleared at retention.
- `order_attributions`: only the IP is anonymized (30 days). UA, visitor_id, account_id and UTM stay.
- `mail_log.to_email` is clear text and has no pruning. Account mails are not logged; they go through `SendAccountMailJob` (encrypted).
- `cart:prune` exists but is not scheduled. There is no `queue:prune-failed` and no pruning of reset tokens.
- No consent, subscriber, list or audience tables exist. The footer newsletter block is hidden because its route does not exist.
- There is no customer resource in the admin. The dashboard has only Filament's default widgets. Permissions come from `EasyCo\Staff\Enums\Permission`.
- Hooks: `Hook::fire` is called only from the app layer (CLAUDE.md rule 10). New hooks go into `extensibility-design-and-hooks.md` §3 in the same commit.

## 3. Data model
**`privacy_requests`** (one row per request; the forget log is `kind = erase`):
- `id`, `kind` (`export` | `erase`), `source` (`storefront` | `account` | `admin`), `status` (`awaiting_confirmation` | `confirmed` | `processing` | `done` | `expired` | `failed`).
- `email` (nullable, clear), `email_hash`, `phone` (nullable, clear), `phone_hash` (nullable), `account_id` (no FK, snapshot).
- `reason` (admin erase: required), `staff_id`, `staff_name`.
- `actions` (JSON, closed-list keys: `account_deleted`, `addresses_deleted`, `carts_deleted`, `reset_tokens_deleted` (password-reset tokens only), `email_changes_deleted` (pending email-change requests), `staff_note_deleted`, `mail_log_masked`, `suppressed_email`, `suppressed_phone`, `cod_blocks_kept` (with the latest expiry date), `hook_customer_erased`, plus keys that listeners report back through the hook).
- `manual_steps` (JSON, closed list from a setting, each `{key, done_at, staff_id}`).
- `clear_until` (erase: completed_at + 30 days; then `email`/`phone` are nulled by a daily command).
- `export_path`, `export_expires_at` (export only).
- `confirmed_at`, `completed_at`, timestamps.
- Index `(kind, email_hash)`, `(status)`, `(clear_until)`.

**`privacy_suppressions`**: `kind` (`email` | `phone`), `hash`, `request_id`, `lifted_at` (nullable), `lifted_reason`, timestamps. Unique `(kind, hash)`.

**`customer_notes`**: `email_key` (unique), `body` (plain text, max 2000), `staff_id`, timestamps. Deleted on erase.

**Hashing:** HMAC-SHA256 with a dedicated key `PRIVACY_HASH_KEY`, not APP_KEY, so an APP_KEY rotation does not break suppression. `easyco-install` generates it, and it is added to `.env.docker.example` and `deploy-docker.md`. **It is never changed after go-live and is backed up together with `.env`**: a lost or changed key makes every existing suppression unmatchable. When the key is missing in production, privacy requests refuse with a clear admin error and never fall back silently. Normalization: email via `IdentityKeys::email()`, phone via `PhoneNumbers::e164()`. One class, `PrivacyHash`.

## 4. Services (app layer)
- **`SuppressionList`** — `isSuppressed(email|phone)`, `suppress(...)`, `lift(email, reason)`. ACC-5 lifts it only through a confirmed double opt-in.
- **`PrivacyEraser::erase(email, source, staff?, reason?)`**. One DB transaction:
  1. Collect the phones: from the account's addresses and from orders with this `email_key`.
  2. Account found → `forceDelete()` (cascades); `account_password_reset_tokens` deleted by email; `account_email_changes` rows where `new_email` = this email deleted.
  3. Delete the customer note.
  4. In `mail_log`, rows for this email whose `template_key` starts with `account.` get `to_email` masked. Order mail rows stay with the order (D1).
  5. Suppression rows for the email and every phone.
  6. Active COD blocks on this email or these phones are kept (D7); action `cod_blocks_kept` with the latest expiry.
  7. Log row (`done`, actions, `clear_until`).

  After commit:
  - `Hook::fire('customer.erased', ErasedCustomer{requestId, email, phones})` — listeners remove the person from their lists/audiences and add their action keys to the log.
  - Final mail «Данните ви са изтрити» through `SendAccountMailJob` (no `mail_log`).

  Orders, snapshots, invoice details, order events and attributions are **not** touched (D1).
- **`PrivacyExportBuilder`** — builds `{html, json}` for an email:
  - account, addresses, orders (lines, totals, delivery, statuses, invoice details, recipient_email, attribution IP/UA while kept), consents (order `marketing_consent` + text + date);
  - sections added by modules through the filter `privacy.export.sections`.

  No staff notes, order event reasons, staff names or block reasons. ZIP on the `local` disk `privacy-exports/{uuid}.zip`, built by a queued job. **(D2 revised: no mail and no customer link; the staff member downloads it from admin. Download checks `export_expires_at`.)**
- **`OrderAnonymiser`** — the domain method `Order::anonymise()` in the Order package (Order.php is not frozen) plus an app-layer command.
  - For orders placed before 1 January of (placement year + 1 + N years), N = `privacy.order_retention_years` (default 10, 1..30):
    - names → «Анонимизиран», phone/email/address/postcode/region/pickup/settlement/customer_note/recipient_email → NULL or a placeholder where NOT NULL;
    - identity keys → NULL;
    - `order_placement_snapshots`, `order_invoice_details`: personal fields cleared;
    - `order_attributions`: ip, user_agent, visitor_id, referrer/landing → NULL;
    - `order_events.reason` → NULL;
    - related `mail_log` rows deleted.
  - Amounts, lines, statuses, dates, number and `marketing_consent` (boolean only) stay.
  - Idempotent; chunked; `anonymised_at` column on orders.

## 5. Housekeeping (scheduled, daily)
- `privacy:expire-clear-view` — nulls `email`/`phone` in `privacy_requests` past `clear_until`.
- `privacy:expire-requests` — `awaiting_confirmation` older than 24 h → `expired`, email nulled. **ACC-4d changes this to 7 days**, so the person has time to click the merchant's confirmation link.
- `privacy:prune-exports` (ACC-4c) — deletes expired ZIPs, sets status `expired`.
- `privacy:anonymise-orders` (ACC-4b).
- `mail:prune-log` — deletes `mail_log` rows older than `privacy.mail_log_retention_days` (default 365, 30..3650).
- Schedule the existing `cart:prune`.
- Prune expired `account_password_reset_tokens`.
- `queue:prune-failed --hours=720`.
- Session GC: confirm `session.lottery` is active (database driver); otherwise schedule a prune.

## 6. Storefront (revised 2026-11-21 by D2: confirmation only)
- **No public request page and no buttons in «Данни».**
- **The only storefront piece is the identity-confirmation page.**
  - The merchant presses «Поискай потвърждение» in admin. This creates a `privacy_requests` row (`awaiting_confirmation`, kind = what the person asked for) and mails `privacy.confirm` to the email on record.
  - The mail carries a `SignedLink` (purpose `privacy-confirm`, valid 7 days).
  - The link opens a no-store page: «Потвърдете, че искането е ваше» with one button. GET changes nothing. POST sets `confirmed` + `confirmed_at`.
  - Nothing is erased or exported by the click. The merchant acts after seeing «Потвърдено».
  - Limiter on the POST; the same page for an expired or invalid link.
- **Mail templates:** `privacy.confirm` (ACC-4d); `privacy.erased` (ACC-4a). `privacy.export_ready` and `account.delete_confirm` are dropped.

## 7. Admin
- **Permissions:** `CUSTOMER_VIEW` (list + page) and `PRIVACY_MANAGE` (export, forget, log, manual steps). `PRIVACY_MANAGE` goes to Administrator only by default.
- **«Клиенти»** (Filament page over a query, not an Eloquent resource):
  - one row per `email_key` from `orders`, plus accounts without orders;
  - columns per D5; search by name, email, phone; paginated;
  - EXPLAIN in the report, tested at 20 000 orders.
- **Customer page:** data, addresses (account), orders (link), consents, lists (filter `privacy.customer.lists`), staff note, buttons «Изтегли данните» (ZIP download for the staff member), «Поискай потвърждение» (§6) and «Забрави» (confirmation + required reason). When a confirmed request exists, its state is shown next to the buttons.
- **«Поверителност → Дневник»:** the erase requests, a lookup by email (hash), manual-step checkboxes. The list of manual steps is set in Settings (free labels, closed by the merchant).
- **«Табло» tile:** open erase requests whose clear view is still on and whose manual steps are not all done, with the fewest remaining days.
- **Settings:** `privacy.order_retention_years`, `privacy.mail_log_retention_days`, the manual-step labels.
- **UI:** shown to the owner before ACC-4e.

## 8. Packages (one prompt at a time)
1. **ACC-4a** — tables, `PrivacyHash`, `SuppressionList`, `PrivacyEraser`, `customer.erased` hook (+ hooks doc), the `privacy.erased` mail, housekeeping of §5 except the anonymiser and export pruning, settings keys. No UI. Sonnet.
2. **ACC-4b** — `Order::anonymise()` + `privacy:anonymise-orders`. Sonnet.
3. **ACC-4c** — export builder, queued job, ZIP on the private disk, staff-only download (no customer link), `privacy:prune-exports`. Sonnet.
4. **ACC-4d** — the confirmation request (service + `privacy.confirm` mail + no-store confirmation page + 7-day expiry). Small; can be merged into ACC-4e if that keeps one prompt manageable. Sonnet.
5. **ACC-4e** — admin «Клиенти», customer page, log, tile, settings page, permissions. UI agreed first. Sonnet.

CI runs the full suite on each push; locally only targeted tests.

## 9. Leftovers (not ACC-4)
- `order_attributions` keeps UA/visitor_id/UTM until retention. A shorter period like the IP's is a later decision.
- Verified 2026-11-20: `operational_sales_clients.account_id` is `nullOnDelete`; session GC lottery `[2, 100]` is active.
- Until ACC-5 brings lists, `customer.erased` has no listeners; the manual steps in the log are the real effect. Intended.
