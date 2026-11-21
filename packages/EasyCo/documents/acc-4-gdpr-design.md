# ACC-4 — GDPR: export, «forget me», retention, admin «Клиенти» (design, owner decisions 2026-11-20)

Status: owner decisions D1–D8 taken. Technical design §3–§9 written after the read-only inventory ACC-4-0 (commit `1818ab1`).

## 1. Owner decisions — do not re-open

**D1. Orders and retention.**
- On account deletion / «forget me», orders stay intact (names, address, phone, email). They are unlinked from the account.
- After the retention period, the personal data in an order is anonymized automatically. Amounts, lines, date and number stay.
- Retention is an admin setting, default **10 years**. Under the Accounting Act, registers and financial statements are kept 10 years from 1 January of the following period; the owner confirms the exact value with his accountant.
- «Forget me» removes the person **immediately** from every mail list and ad audience.
- A **log of forget requests** («дневник на забравените») lets the merchant look people up and tick manual removals from external lists.

**D2. Who can request.**
Both paths:
- Storefront page «Моите лични данни». The guest enters an email and gets a confirmation link by mail, then chooses «Изтегли данните ми» or «Забрави ме». Same answer whether the email exists or not; rate limit + honeypot.
- Admin «Клиенти» buttons for requests that arrive by phone or Messenger.

**D3. Export.**
- A ZIP with a readable HTML page and a machine-readable JSON.
- Content: profile, addresses, orders (lines, amounts, delivery, statuses), consents with dates, list/audience memberships, IP addresses while still kept.
- **Staff notes are NOT exported.** They are deleted on «forget me».
- The file is built in the background and sent as a signed link, valid 7 days. After that it is deleted from the server.
- Logged-in customers start it from «Данни» with their password; guests start it via the confirmation link.

**D4. Account deletion.**
- Password + confirmation link by mail, valid 1 hour.
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

  No staff notes, order event reasons, staff names or block reasons. ZIP on the `local` disk `privacy-exports/{uuid}.zip`, built by a queued job. The mail carries a temporary signed route valid 7 days; download also checks `export_expires_at`.
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

**What ACC-4b turned out different (implementation notes).**
- `Order::anonymise(DateTimeImmutable $at, DateTimeImmutable $cutoff)` lives on the aggregate (D8). It needed `email`, `customerNote` and `recipientEmail` to lose `readonly`, a trailing `anonymisedAt`, and the delivery-shape invariants (a street order needs a street, a pick-up point a settlement) are NOT applied to an anonymised order (it has deliberately lost them). The identity keys are NULL on every save of an anonymised order and the database holds it (`ord_anon_status_check`; the NULL keys are the repository's rule, pinned by tests).
- **«Finished»** is `delivered`, `cancelled`, `refunded` (`Order::ANONYMISABLE_STATUSES`). `OrderStatus::isTerminal()` is only cancelled/refunded (a delivered order can still be refunded), which would skip nearly every order; a placed, confirmed or shipped order is skipped and counted.
- **«Open money matter»** is read from the ONE place the code has for it: the tagged `NeedsAttentionSource`s (owed refund, receipt mismatch, payment step unfinished), through `App\Privacy\OpenMoneyOrders`.
- **Placeholders:** `Анонимизиран` for the NOT NULL names, phone and (pick-up) description, `anonymised@invalid` for the NOT NULL emails. A pick-up row keeps ONE non-blank description (the database requires it).
- **Also cleared** (not in the first list): the attribution's `first_landing_page_url` and `click_id_value` (an ad click id is an identifier); the invoice city/postcode/street (the country stays). **Stays:** `country`, `city`, `referrer_host`, UTM, device, counts, staff names.
- **Refund free text (ACC-4b follow-up).** The earlier text said: «Not touched, flagged: `payment_refunds.paid_out_note` / `cancelled_reason` / `deduction_reason` / `paid_out_reference` (staff free text)». It is now cleared: `paid_out_note` and `paid_out_reference` → NULL; `cancelled_reason` → the placeholder `Анонимизиран` where `pay_refunds_cancel_check` needs a text (the refund is cancelled), else NULL; `deduction_reason` → the placeholder where `pay_refunds_deduction_check` needs one (`deduction_minor <> 0`), else NULL (the owner's «NULL» is impossible there: the database refuses a deduction without a reason).
- **The admin block buttons (ACC-4b follow-up).** An anonymised order shows no «Блокирай» / «Отблокирай» for the email, the phone and the IP (it holds placeholders, never an identifier), and the action itself refuses on the server (`OrderBlockActions::anonymised()`; Filament does not mount a hidden action). No extra query: it reads the column already loaded.
- **Not touched, flagged:** `operational_sales_clients.name` (the client row made from the first order's recipient name; a leftover, owner-confirmed), `order_blocks` (D7). See the ACC-4b report.

## 5. Housekeeping (scheduled, daily)
- `privacy:expire-clear-view` — nulls `email`/`phone` in `privacy_requests` past `clear_until`.
- `privacy:expire-requests` — `awaiting_confirmation` older than 24 h → `expired`, email nulled.
- `privacy:prune-exports` (ACC-4c) — deletes expired ZIPs, sets status `expired`.
- `privacy:anonymise-orders` (ACC-4b).
- `mail:prune-log` — deletes `mail_log` rows older than `privacy.mail_log_retention_days` (default 365, 30..3650).
- Schedule the existing `cart:prune`.
- Prune expired `account_password_reset_tokens`.
- `queue:prune-failed --hours=720`.
- Session GC: confirm `session.lottery` is active (database driver); otherwise schedule a prune.

## 6. Storefront
- **`/privacy` «Моите лични данни»** (cacheable page, form posts to the API):
  - email field + honeypot + limiter `PRIVACY_REQUEST` (per IP and per email hash);
  - always the same answer;
  - the mail carries a `SignedLink` (purpose `privacy-confirm`, 24 h).
  - The link opens a no-store page with two buttons. No state change on GET; POST does it. «Забрави ме» needs a second explicit confirmation.
  - When nothing is stored for the email, the mail says so (only the mailbox owner sees it).
- **«Данни»** gets «Изтегли данните ми» (password → queued export → mail) and «Изтрий профила» (password → mail link 1 h → confirmation page → POST → `PrivacyEraser`, source `account`, then logout).
  - The warning about unfinished orders is shown on the confirmation page.
- New mail templates: `privacy.confirm`, `privacy.export_ready`, `account.delete_confirm` (ACC-4d); `privacy.erased` (ACC-4a).

## 7. Admin
- **Permissions:** `CUSTOMER_VIEW` (list + page) and `PRIVACY_MANAGE` (export, forget, log, manual steps). `PRIVACY_MANAGE` goes to Administrator only by default.
- **«Клиенти»** (Filament page over a query, not an Eloquent resource):
  - one row per `email_key` from `orders`, plus accounts without orders;
  - columns per D5; search by name, email, phone; paginated;
  - EXPLAIN in the report, tested at 20 000 orders.
- **Customer page:** data, addresses (account), orders (link), consents, lists (filter `privacy.customer.lists`), staff note, buttons «Изтегли данните» (ZIP download for the staff member) and «Забрави» (confirmation + required reason).
- **«Поверителност → Дневник»:** the erase requests, a lookup by email (hash), manual-step checkboxes. The list of manual steps is set in Settings (free labels, closed by the merchant).
- **«Табло» tile:** open erase requests whose clear view is still on and whose manual steps are not all done, with the fewest remaining days.
- **Settings:** `privacy.order_retention_years`, `privacy.mail_log_retention_days`, the manual-step labels.
- **UI:** shown to the owner before ACC-4e.

## 8. Packages (one prompt at a time)
1. **ACC-4a** — tables, `PrivacyHash`, `SuppressionList`, `PrivacyEraser`, `customer.erased` hook (+ hooks doc), the `privacy.erased` mail, housekeeping of §5 except the anonymiser and export pruning, settings keys. No UI. Sonnet.
2. **ACC-4b** — `Order::anonymise()` + `privacy:anonymise-orders`. Sonnet.
3. **ACC-4c** — export builder, job, ZIP, signed download, `privacy:prune-exports`. Sonnet.
4. **ACC-4d** — storefront `/privacy` and «Данни» (export + account deletion), mails. Sonnet.
5. **ACC-4e** — admin «Клиенти», customer page, log, tile, settings page, permissions. UI agreed first. Sonnet.

CI runs the full suite on each push; locally only targeted tests.

## 9. Leftovers (not ACC-4)
- `order_attributions` keeps UA/visitor_id/UTM until retention. A shorter period like the IP's is a later decision.
- Verified 2026-11-20: `operational_sales_clients.account_id` is `nullOnDelete`; session GC lottery `[2, 100]` is active.
- Until ACC-5 brings lists, `customer.erased` has no listeners; the manual steps in the log are the real effect. Intended.
