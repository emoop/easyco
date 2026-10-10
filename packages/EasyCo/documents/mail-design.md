# EasyCo Mail — Transactional First, Small Campaigns Later (Design)

**Status:** DESIGN (2026-10-09), AMENDED 2026-10-09 with the owner decisions on newsletter opt-in (§7), bank-transfer instructions (§6.1.1) and mail language (O-M3). M1 and M2 are built from this document (M2 as described in §2.1). Nothing after M2 is built. Companion of `storefront-design.md` (§11 abandoned carts depends on this document; §12 privacy tools) and of
`cart-abandoned-recovery-note.md` (whose direction — send from EasyCo's own infrastructure through a transactional provider; a full ESP only if multi-channel automation is ever wanted — this document adopts).

**Conventions.** **V** = verified by reading the code or documents named. **I** = my proposal or inference. Anything not verified is under *Unknowns* (§12.2). All text in code and documents is English; mail texts are bg/en.

---

## 0. Where we start (V)

- **No mail exists.** There is no `Mail::` call, no Mailable and no notification of customers anywhere in `app/` or `packages/`. (`Notification::make()` in the code is Filament's admin toast, unrelated.)
- Framework facts: `symfony/mailer` and `league/commonmark` are installed (Laravel dependencies). `config/mail.php` default mailer is `log` (`MAIL_MAILER=log`); `.env.example` has `MAIL_FROM_ADDRESS="hello@example.com"`. `QUEUE_CONNECTION=database` with the `jobs`, `job_batches` and `failed_jobs` tables from the default migration; a persistent queue worker is a production requirement already (`production-requirements.md`: Supervisor/NSSM, `queue:restart` after deploys).
- Hooks (Reference in `extensibility-design-and-hooks.md`): `order.placed(Order)` — fired by `CheckoutOrchestrator::place()` in Phase 2, **no listener**; `order.status_changed(Order, from, to)` — fired by `OrderStatusChanger` (confirm/ship/deliver/cancel) after commit; `order.cancelled`, `order.returned`, `order.payment_confirmed`, `order.edited`, `order.refund_*`, `account.registered`. **There is no hook for a failed payment** (`PaymentStatus` has `pending|captured|failed`, but nothing fires when an attempt is recorded FAILED).
- `order.placed` is fired only on a fresh placement, never on a replay (the replay path returns before Phase 2), and since stage 4c a throwing listener is **contained** at the call site (logged, the checkout still answers 201). So a listener can never break an order — and a crash between commit and the hook means the hook never fires (a gap §6.1 closes).
- `orders` carries `email`, the placed-at time, totals, the delivery snapshot (street fields or pickup reference/name/address, 4f), the shipping facts and amount (4a/4e), `tracking_number`, and a status (`placed|confirmed|shipped|delivered|cancelled|refunded`). `order_placement_snapshots` holds the write-once copy made at placement. The order's lines are SaleLines in the order's transaction (name, SKU, attributes, quantity, final price, discount share — read for the checkout response).
- Settings: `site_settings` (key/text value, `SiteSettingsRepository`), editable through Filament settings pages under `app/Filament/Pages/Settings/` (`CatalogSettings`, `LocaleSettings`), permission `settings_manage`. Store language: `site.locale` (bg|en), time zone `site.timezone`. Audit: `ActivityLogger`.
- **Where a bank-transfer shop's IBAN lives was not defined anywhere** (V at design time: a grep for iban/bank details found nothing). **Decided by the owner 2026-10-09:** the settings group `payment.bank_transfer.*` (§6.1.1); the mail block is built from it and omitted while it is empty.
- Orders and accounts do not store the customer's language (V: a search of the order, account and root migrations for `locale`/`language` finds nothing); the store language is the only language known.

---

## 1. Goals and non-goals

**Goals.** (1) Every customer gets the mails an e-shop must send — first the **order confirmation**, then cancelled/refused, payment failed, shipped. (2) The abandoned-cart reminder, with consent. (3) A few simple campaign templates, later. (4) Mail can **never** block or fail an order, a payment or an admin action. (5) The merchant configures the transport and edits texts in the admin without a developer. (6) Light: no new service to run beyond the queue worker that already must exist.

**Non-goals.** No mini-Mailchimp: no automations or journeys, no A/B tests, no complex segmentation, no drag-and-drop template builder, no open/click tracking pixels (privacy, deliverability and cost; only provider-side stats if the provider shows them), no per-recipient personalisation beyond name/order facts, no mail inbox or reply handling.

---

## 2. Transport

- **Laravel Mail** (`symfony/mailer`) through the queue. The transport is chosen in the admin: *Mail settings* page (permission `settings_manage`) with a Select of mailers — **SMTP** now (host, port, encryption `tls|ssl|none`, username, password); API providers (Brevo, Resend, Postmark, SES) later as additional entries behind the same form. A provider adds one `Mail::extend()` registration and its fields; the sending code never changes. Recommended for this volume: **Brevo or Resend** (the brief); the page shows a short comparison of what each needs.
- **How settings reach Laravel (I):** a `MailConfigurator` runs at the start of each mail job (not at boot — the worker boots once and keeps state, CLAUDE.md), reads the `mail.*` settings, and sets `config(['mail.mailers.easyco' => [...]])`, then sends through the mailer named `easyco`. When the settings are empty it falls back to the `.env` mailer (`log` in development). Rejected: writing `.env` from the admin (not safe, not atomic, needs a config-cache clear).
- **Secrets (I):** SMTP password / API key are stored **encrypted** (`Crypt::encryptString`, APP_KEY) in `site_settings` under `mail.smtp.password` etc.; the admin form shows "••••••• (saved)" and never the value; the field is write-only (an empty submit keeps the stored secret); the value never appears in logs, exceptions (the configurator catches and re-throws a message without it), the activity journal (the audit entry records "changed", not values), or a hook payload. A changed APP_KEY makes stored secrets unreadable — the page then says "re-enter the password" instead of failing (Unknown: whether key rotation is planned).
- **Development default:** `MAIL_MAILER=log` (V) — nothing is sent. The admin page labels this state clearly ("Emails are only written to the log").
- **"Send test email":** an action on the page sends a fixed test message to the address the staff member types (validated; limited to their own account email by default, or any address ≤ 1/min per user) **synchronously with a 10-second timeout** and shows the transport's real error text (sanitised, no secrets) so the merchant can fix credentials.
- **DNS help text (bg/en):** a collapsible help block explaining SPF, DKIM and DMARC in plain words, with the exact record *shapes* the provider will give (`TXT @ v=spf1 include:... ~all`, DKIM `CNAME`/`TXT`, `TXT _dmarc v=DMARC1; p=none; rua=mailto:...`), the recommended start (`p=none` then tighten) and a reminder that the **From domain must be the domain whose DNS you edit**. Lives in the help system (`resources/help/{bg,en}/mail.md`, new topic) — text written with the owner.
- **Can we check DNS? (I):** yes, partially, without a library: `dns_get_record()` can look up TXT for the sender domain and report *"an SPF record exists / contains the provider's include"*, *"a DMARC record exists"*; DKIM needs the provider's selector name (the admin can paste it) and is checked the same way. The check is **advisory** (a button "Check DNS", result with ✓/✗ and plain advice), runs on demand only, with a 3-second timeout, and never gates sending. Reliability caveat: DNS caches and split-horizon setups make a negative result "not seen yet", never "broken". Rejected: automatic periodic checking (noise, no benefit).

### 2.1 As built in M2 (differences and additions to the text above)

- **Page:** `App\Filament\Pages\Settings\MailSettings` ("Mail", Admin group, `settings_manage`), help topic `mail` (`resources/help/{bg,en}/mail.md`, registered in `HelpTopics`). Every public method re-checks `settings_manage` itself (Livewire exposes them all); Filament's own hydrate check is a second layer.
- **Transports are a registry**, `App\Mail\Transports\MailTransports`: an entry is a `MailTransport` (key, label, `TransportField` descriptors, `mailerConfig()`); the page renders any entry's fields and `MailConfigurator` builds the `easyco` mailer from the chosen one. An API provider is one more class plus one line in the registry. `mail.transport` empty or `env` = the `.env` mailer; an unknown value is a configuration error, not a silent fallback. Switching back to `env` keeps the SMTP fields.
- **Secret:** the Livewire state drops the typed password in `dehydrate()` (and after save), so it never rides in a snapshot; the "saved / unreadable" state comes from `MailConfigurator::secretState()`, so the page never holds a decrypted value. The journal (`ActivityLogger`, entity `mail_settings`) records `changed`/`removed` with no values; like all journal entries it is written only while `admin.activity_log_enabled` is on.
- **New settings:** `mail.sender.domain_confirmed` (`1` = "I own this domain and set up its DNS"; until then the page says "sender not verified"), `mail.dns.include` and `mail.dns.selector` (the optional inputs of the DNS check).
- **Test email:** to the staff member's own address freely; to any other address 1 per minute per staff member (`RateLimiter`, key `mail-test:{staffId}`; a refused address does not use the allowance). Sent synchronously through `MailConfigurator::apply('transactional', 10)` (10-second socket timeout), never queued, never written to `mail_log`. The error text goes through `MailErrors::sanitise()` (removes the decrypted password, the username, URLs, addresses, `password=` pairs and long token-like runs).
- **DNS check:** `DnsChecker` behind the `DnsLookup` interface (`PhpDnsLookup` = `dns_get_record`). It looks up the transactional sender domain and, when different, the marketing one. `dns_get_record()` has **no per-call timeout**, so the 3-second budget is enforced *between* lookups (remaining checks are reported as "not checked: time ran out"); a single hung lookup can still exceed it.
- **Queue health:** `QueueHealth`, one grouped query on `jobs` for the two mail queues; "not available" when the queue driver is not `database`.

---

## 3. Sender identities

Two identities, created at installation (the installer asks for the shop's own domain and proposes the addresses):
- **Transactional:** `orders@{domain}` (name = shop name) — order, payment, shipping mails. Reply-To: a monitored address (`info@{domain}`) because transactional senders are often `noreply` and customers do answer.
- **Marketing:** `news@{domain}` (name = shop name) — abandoned cart and campaigns. Optionally a different sending *subdomain* (`mail.{domain}`) so a marketing reputation problem cannot hurt transactional deliverability (recommended when campaigns start; one extra DNS setup).
Settings `mail.from.transactional.{address,name}`, `mail.from.marketing.{address,name}`, `mail.reply_to`. Validation: a single RFC address (no commas, no CR/LF); the display name is plain text ≤ 80 chars with control characters refused (header-injection guard, §10). The installer writes defaults but never invents a domain: until the merchant confirms the domain the page warns "sender not verified".

---

## 4. Queue

- **Always queued** (`ShouldQueue`); nothing sends inside a request or a domain transaction. A listener on a domain hook only **dispatches** a job (a few microseconds), after the order transaction has committed (V: the hooks fire after commit).
- **Queues (I):** `mail-transactional` (priority), `mail-marketing` (abandoned carts and campaigns). One worker with `--queue=mail-transactional,default,mail-marketing` processes strictly in that priority order, so **a campaign can never delay an order confirmation**; when campaign volume grows, a second worker is dedicated to `mail-marketing` (documented in `production-requirements.md`'s worker section in the stage that builds it). The `default` queue stays for media processing and other jobs (V: `ProcessMediaAssetJob`).
- **Retries:** transactional jobs `tries = 5`, backoff `[30, 120, 600, 1800]` seconds; marketing `tries = 3`, backoff `[300, 1800]`. The job releases on transient transport errors (connection, 4xx-temporary), and **fails permanently** (no more retries) on permanent errors (invalid recipient, 5xx-permanent) and marks `mail_log.status = failed`.
- **Failure handling — mail never blocks an order:** (1) the order is committed and answered before any mail is queued; (2) a failed dispatch (queue down) is caught at the listener, logged, and leaves the `mail_log` row `queued` or absent — the reconciliation in §6.1 finds it; (3) a failed job writes `mail_log` and increments a counter; nothing in checkout or admin reads mail state to decide anything.

  **Stuck `queued` rows (M5 must list them):** a `mail_log` row can stay `queued` because its job was lost (the queue lost the payload, or the process died between the row insert and the push) and the reconciliation deliberately ignores any order that already has a row. Stage M5's Needs-attention source must therefore list `mail_log` rows that stay `queued` longer than 30 minutes, derived on every read (nothing stored). Not built in M1.
- **Admin warnings (facts, not enforcement):** a *Needs attention* source (the page exists, `App\NeedsAttention\NeedsAttentionSource` is tagged in the container, V) lists "N emails failed in the last 24 h" and "Order confirmation was not sent for order #X" (derived on every read from `mail_log` and `orders`, nothing stored — the page's own rule); the Mail settings page shows "Not configured — emails are only logged" when the mailer is `log` outside development; a dashboard-level line when the last successful send is older than 7 days while orders exist.
- **Queue health (I):** the existing worker requirement stands; the settings page shows the number of pending `mail-*` jobs and the age of the oldest (one cheap count on `jobs`) so a dead worker is visible to the merchant.

---

## 5. Templates

### 5.1 The registry

A code registry, `MailTemplates`, lists each **template key** with its allowed variables, its sender identity, its category (`transactional|marketing`) and its default subject/body file. Keys (V1): `order.confirmation`, `order.cancelled`, `order.payment_failed`, `order.shipped`, `cart.abandoned`, `newsletter.welcome` (§7; marketing identity) and `newsletter.confirm` (used only while `mail.newsletter.require_confirmation` is on); campaign keys later (§8): `campaign.collection`, `campaign.sale`, `campaign.black_friday`, `campaign.announcement`. Adding a key is a code change; **merchants edit text, never structure**. **M1 registers ONE key, `order.confirmation`.**

### 5.2 Variables

Per key a closed list, e.g. `order.confirmation`: `shop_name`, `customer_name`, `order_number`, `order_date`, `order_total`, `payment_method_label`, `payment_instructions` (block), `delivery_summary` (block), `order_lines` (block), `order_url` (none in V1 — there is no customer order page; Unknown), `support_email`. Rules:
- **Escaped by default.** Every scalar variable is HTML-escaped when inserted, and header-safe when used in a subject (CR/LF/tab and all control characters are stripped, length ≤ 180).
- **Blocks** (`order_lines`, `delivery_summary`, `payment_instructions`, `cart_lines`) are rendered by code from shipped Blade partials with escaped values — the merchant places the block token but cannot alter its markup.
- A token not in the key's list is **left as visible text** in preview and refused at save ("unknown variable {{ foo }}"), so a typo is found when saving, not by a customer.

### 5.3 Storage and fallback

- Shipped defaults: `resources/mail/{bg,en}/{key}.md` (subject on the first line prefixed `subject:`; body Markdown-subset below) — in the repository, translated, tested.
- Merchant overrides: table **`mail_templates`** — `id`, `template_key` (varchar 64), `locale` (char(2)), `subject` (varchar 180), `body` (text ≤ 20,000), `updated_by` (staff id), `created_at`, `updated_at`; **UNIQUE `(template_key, locale)`**. No row = the shipped default. "Reset to default" deletes the row.
- The send path: `row ?? default file`, always in the **store locale** (V: the only language known), and if the stored template fails to render for any reason (a variable list changed in a deploy) the job **falls back to the shipped default** and logs the problem — a mail is never lost to a bad edit.

### 5.4 Safe markup — why admin input is never Blade or PHP

- The merchant's body is **never compiled as Blade, never `eval`ed, never passed to a template engine that can call code.** Variables are substituted by a tiny, closed parser that recognises only `{{ name }}` against the allowed list.
- Allowed formatting is a **Markdown subset** rendered by `league/commonmark` (V: installed) configured with `html_input => strip`, `allow_unsafe_links => false`, and extensions limited to: paragraphs, line breaks, bold/italic, links, unordered lists, and one image block (campaigns only). Order of operations: (1) validate that the stored template has only allowed tokens; (2) convert Markdown → HTML **with the tokens still in place** (tokens contain no Markdown-active characters beyond braces, which CommonMark leaves alone); (3) replace each token with its **already-escaped** value (text) or the shipped block HTML; (4) pass the result through a final allow-list sanitizer (`<p><br><strong><em><a href><ul><ol><li><img src alt width height><table…>` from the shipped blocks only; attributes limited; `href`/`src` schemes limited to `https:`/`mailto:`; no `style`, no event attributes, no `<script>/<iframe>/<form>`). The final sanitizer is the safety net: even a bug in steps 1–3 cannot emit active content.
- **Links:** a link's URL in a merchant body must be `https://` (or `mailto:`); relative links are expanded against the shop URL. The unsubscribe and "view in browser" links are added by code, never typed.
- **Header injection:** subject, from-name and any recipient-controlled value go through `MailHeader::clean()` (strips `\r`, `\n`, `\0`, other control characters and bidi controls; collapses whitespace) and Symfony's header API (which also refuses newlines) — two independent barriers. The recipient address is validated (`filter_var` + a stricter single-address check, ≤ 254 chars). No user value is ever placed in a raw header.
- **Preview:** the admin template editor has a "Preview" that renders the CURRENT unsaved text with **sample data** through the same pipeline into a sandboxed `<iframe srcdoc sandbox="">` (no scripts), in both locales, plus "Send a test to myself".
- Subject and body length limits are enforced in the writer and the form; non-plain characters (control/bidi) refused as in the storefront's `PlainText` rule (V: `App\Rules\PlainText` exists).

### 5.5 Plain-text part

Every mail is multipart: the HTML part and an automatically derived plain-text part (links kept as `text (url)`). Many spam filters score HTML-only mail down.

---

## 6. Transactional mails

All five share: a `MailJob` carrying `(template_key, idempotency_key, subject-line facts)`; a **`mail_log`** row inserted FIRST with a UNIQUE idempotency key; the sender; the locale; the render; the send; the status update.

**`mail_log`** — `id`, `template_key`, `idempotency_key` (varchar 120, **UNIQUE**), `category` (`transactional|marketing`), `to_email` (varchar 254), `locale` (char(2)), `subject` (varchar 180, as sent), `status` (`queued|sending|sent|failed|skipped`), `attempts` (tinyint), `last_error` (varchar 255, sanitised — no secrets, no recipient content), `related_type` (varchar 32 null), `related_id` (varchar 64 null, e.g. the order id), `queued_at`, `sent_at` null, `created_at/updated_at`. Indexes: UNIQUE `idempotency_key`; `(status, queued_at)`; `(related_type, related_id)`; `(to_email)`. Retention: rows older than 180 days (default of the setting `privacy.retention.mail_log_days`, `storefront-design.md` §12.1) are deleted by a scheduled command (the body is **not** stored — only facts; the order is the record).

**Idempotency (I):** the key is built from the event, never from a timestamp: `order.confirmation:{orderId}`, `order.cancelled:{orderId}`, `order.shipped:{orderId}:{trackingNumberOrBlank}` (a changed tracking number sends a new "shipped" update mail), `order.payment_failed:{paymentId}`, `cart.abandoned:{cartId}:{sequence}`. Inserting the log row is an `INSERT ... ` guarded by the UNIQUE index (detect with SQLSTATE 23000 + driver code 1062/19, CLAUDE.md rule 3 — never message matching): a duplicate means "already queued or sent" and the dispatch silently stops. A retried job re-uses the row (status machine `queued → sending → sent|failed`; `sending` is claimed with a conditional UPDATE so two workers cannot send the same mail). So a retried queue job, a replayed checkout or a double event never sends twice.

### 6.1 Order confirmation (M1 — first)

- **Trigger:** a listener `SendOrderConfirmation` registered in `AppServiceProvider` on the existing **`order.placed`** hook (V: fired in Phase 2, after the commit, no listener today). The listener only dispatches `SendMailJob` (it must not throw; 4c contains a throwing listener anyway, but it must not even try).
- **Recipient rule:** `orders.email` (the address typed at checkout, validated there).
- **Idempotency:** `order.confirmation:{orderId}`.
- **When the hook did not fire (the gap):** the process can die between the commit and `Hook::fire`. A scheduled reconciliation (every 10 min) queues the confirmation for orders placed in the last 24 h that have **no `mail_log` row** for `order.confirmation:{id}` and are older than 5 minutes; and the Needs-attention source lists orders still without one after 24 h.
- **Content contract:** order number and date; the **lines** (name, SKU, sold attributes, quantity, unit price, line total — from the order's sale lines, the same snapshot the checkout response uses); subtotal, discount (with promotion code), **shipping name and price**, total, in the order currency (formatted with the store's `PriceDisplayFormatter`); **delivery facts from the order/snapshot, never from live data**: street address, or the pickup point (name, address, reference, courier, settlement — the 4f display snapshot) and the shipping method name/courier/delivery type; **payment**: method label; for **bank transfer** the payment-instructions block (§6.1.1: account holder, bank, IBAN, BIC, reason for payment = order number, amount, "pay within N days", free-text instructions) read from `payment.bank_transfer.*` (decided 2026-10-09); when those settings are empty the block is omitted and the mail still goes out; for cash on delivery the amount to be collected; a "payment step needs attention" order (4c: payment still PENDING with no attempt date) gets the neutral confirmation, no payment claim and no instructions block.
- **Localisation:** store locale; amounts and dates in the store's formats and time zone (V: `site.timezone`).
- **Not included:** a link to a customer order page (none exists), card data (none exists), anything from the cost/profit columns.

### 6.1.1 Bank-transfer instructions (decided by the owner 2026-10-09)

Modelled on the WooCommerce BACS gateway: structured account details plus a free-text field, shown in the confirmation mail now and on the future customer order page later (that page does not exist yet — Unknown).

**Settings group `payment.bank_transfer.*`** (stored in `site_settings` through `SiteSettingsRepository`; all optional; plain text, never rendered as HTML):

| Key | Meaning | Validation |
|---|---|---|
| `payment.bank_transfer.account_holder` | Account holder | ≤ 120 characters, single line (`PlainText` rule: no control or bidi characters) |
| `payment.bank_transfer.bank_name` | Bank name | ≤ 120, single line |
| `payment.bank_transfer.iban` | IBAN | Normalised on save (spaces removed, upper-cased), then: 2 letters + 2 digits + 11–30 letters/digits, total length 15–34, and the **ISO 13616 mod-97 check** (move the first four characters to the end, map A–Z to 10–35, compute the remainder digit by digit — a dozen lines of integer arithmetic, no library, no bcmath). Shown to customers in groups of four. |
| `payment.bank_transfer.bic` | BIC/SWIFT | Optional; upper-cased; `^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$` (8 or 11 characters) |
| `payment.bank_transfer.deadline_days` | Payment deadline in days | Optional integer 1–60; empty = no deadline sentence |
| `payment.bank_transfer.instructions` | Free-text instructions | ≤ 1,000 characters, **multi-line plain text allowed** (newline and tab allowed; every other control character and bidi controls refused); rendered escaped with line breaks kept |

An IBAN that fails the check is refused with a field error; the IBAN is never echoed in an error message. The values are written only by the settings page below and read only through `SiteSettingsRepository` (no `.env` fallback, no second source).

**Where it is edited:** a Filament page "Payment: bank transfer" (`App\Filament\Pages\Settings\BankTransferSettings`), permission `settings_manage`, in the Admin navigation group next to Settings. Saving writes the six keys (an empty field forgets its key). Built in M1.

**What the mail shows:** a "Pay by bank transfer" block listing only the filled fields — account holder, bank, IBAN, BIC, **reason for payment = the order number** (always: it is the customer's reference), **amount = the order total**, "pay within N days" when the deadline is set — followed by the free text. **When the settings are empty** (no IBAN and no instructions) the block is omitted entirely and the confirmation is sent without it; the admin page says so ("the confirmation email will not contain payment details until you fill this in"). A Needs-attention line for it is an M5 candidate (facts, not enforcement). A block counts as present when an IBAN **or** instructions exist (a shop may say "we will send an invoice" in the free text only).

**Read at send time:** the instructions are not copied onto the order; a bank change between placement and a delayed send would show the new account. Acceptable because the instructions are the shop's current truth; kept as an open point under O-M4.

### 6.2 Order refused / cancelled

- **Trigger:** `order.cancelled` (V: fired by `OrderStatusChanger::cancel()` and return flows that empty an order). Listener dispatches `order.cancelled:{orderId}`.
- **Recipient:** `orders.email`. **Content:** order number, the lines cancelled, "your order was cancelled", refund note **only if** a refund record exists (`order.refund_recorded` — channel and amount from the refund row; never a promise that money moved). "Refused" (the shop declines an order) uses the same event with a merchant-written reason field **optional**, plain text ≤ 300 chars, escaped (O-M5). Idempotent per order.

### 6.3 Payment failed

- **Trigger:** **new hook `order.payment_failed(Order, Payment)`** (does not exist — V), fired from `CheckoutOrchestrator` Phase 2 when `recordAttemptResult` stores status FAILED (and by any future provider webhook). Added to the Hook Reference in the same commit. Today's offline methods never produce FAILED, so the mail is dormant until an online provider exists; building the hook and template now costs little and is testable with a fake adapter.
- **Recipient:** `orders.email`. **Content:** order number, amount, "the payment did not go through, your order is kept", and the way to retry as it exists (V: none yet — the template says "contact us" with `support_email`; Unknown until a retry flow exists). Idempotency `order.payment_failed:{paymentId}`.

### 6.4 Shipped

- **Trigger:** `order.status_changed` with `to = shipped` (V: the hook passes `(Order, from, to)`); the listener ignores other transitions.
- **Content:** order number, shipping courier/method (from the order), **tracking number** if set (V: `orders.tracking_number`) and a tracking link only when the courier has a known URL pattern (Unknown/not in V1: the mail shows the number without a link), the delivery address or pickup point facts. Idempotency includes the tracking number (§6) so a corrected number triggers one update mail.

### 6.5 Abandoned cart (marketing-grade: consent)

- **Trigger:** the planned `cart.abandoned` hook (`cart-abandoned-recovery-note.md`; fired by the scheduled detector of `storefront-design.md` §11) → listener dispatches `cart.abandoned:{cartId}:{n}` on queue `mail-marketing`.
- **Recipient rule:** only addresses with **recorded consent for cart reminders** (account customers who ticked it, or guests who left an email with an explicit checkbox); never when an order exists since the cart changed; never for an unsubscribed address (§7). Max count and spacing from the storefront settings (default 1 email, ~2 h after abandonment).
- **Content:** the cart lines (name, image URL, quantity, current price — re-read at send time; lines now unavailable are dropped; if nothing is left the mail is `skipped`), a link back to the cart (the storefront's cart URL with a **signed, expiring token** that restores that cart for that browser — V1 alternative: the plain shop link, because the cart is bound to a session/account (V: carts are identified by account id or session token); the signed-restore link is a later improvement, O-M7), optional single-use code (storefront O16), the unsubscribe link and one-click header. Sent from the **marketing identity**.
- **Idempotency:** per cart and sequence number.

---

## 7. Subscribers and consent

- **Table `mail_subscribers`:** `id`, `email` (varchar 254, stored lower-cased and trimmed, **UNIQUE**), `locale` (char(2)), `status` (`pending|active|unsubscribed|bounced|complained`; `pending` only while `mail.newsletter.require_confirmation` is on), `source` (`footer_form|checkout|account|import|abandoned_cart`), `consent_at` null, `consent_text_version` (varchar 20), `consent_ip_hash` null (HMAC of the IP with the app key, kept for proof without storing the raw IP — O-M6), `confirm_token_hash` (char(64) null), `confirmed_at` null, `unsubscribed_at` null, `account_id` null (FK, `nullOnDelete`), `created_at`, `updated_at`. Index `(status)`.
- **Table `mail_consent_texts`:** `purpose` (`newsletter|cart_reminder`; `cart_reminder` added 2026-10-09 for the checkout checkbox of `storefront-design.md` §11), `version`, `locale`, `text` (the exact wording shown), `created_at`, UNIQUE `(purpose, version, locale)` — the subscriber row points at the version, so the *wording the person saw* is provable.
- **Single opt-in with a welcome mail (decided by the owner 2026-10-09; replaces the earlier double opt-in recommendation).** The visitor types the address in the subscribe form and presses "Subscribe"; there is **no confirmation mail** and the row becomes `active` at once. The **first mail to a new subscriber is a WELCOME mail** (`newsletter.welcome`, marketing identity, queue `mail-marketing`) that contains: (1) which shop the address was subscribed to and when ("you subscribed on {date}"); (2) what the person will receive and how often (merchant-editable text); (3) the **unsubscribe link in the body** plus the RFC 8058 headers; (4) the shop's company line (`mail.footer.company`); and (5) **"If this was not you, unsubscribe here — the address will not be mailed again"**, so a person signed up by someone else is told and can leave in one click. Idempotency `newsletter.welcome:{subscriberId}`: a subscriber gets one welcome ever; re-subscribing after an unsubscribe sends one only if the previous welcome is older than 30 days. Every later marketing mail carries the unsubscribe link and headers (below).
- **The switch back to double opt-in:** setting `mail.newsletter.require_confirmation` (default **off**). When on, the form creates a `pending` row and sends a `newsletter.confirm` mail (transactional identity, signed single-use link, token stored hashed, 3-day expiry); the address becomes `active` only on click, and the welcome mail is sent after the confirmation. The `pending` status, `confirm_token_hash` and `confirmed_at` columns therefore stay in the table from M6 on, so flipping the switch needs no migration; M6 builds and tests both paths and ships the switch off.
- **What compensates for the missing confirmation (abuse protection, all kept):** per-IP and per-email rate limits and global caps (below); the honeypot field; the minimum-fill-time token; the neutral answer that never reveals whether an address exists; a **global daily cap on welcome mails** (`mail.newsletter.welcome_daily_cap`, default 200 — beyond it the row is stored `active` but the welcome is deferred to the next day); no welcome mail to an address that is already `active`, was `bounced`/`complained`, or unsubscribed less than 24 h ago; at most one welcome per address per 30 days; and the in-mail "not you?" unsubscribe. Residual risk, stated plainly: someone can still subscribe another person's address once; the cost to that person is one welcome mail with a one-click exit, and the proof record (`consent_at`, wording version, HMAC of the IP) shows how the row came to exist.
- **Account customers** who tick the box at registration/checkout are already verified emails: one tick is enough there, with or without the switch.
- **Signed unsubscribe URL:** `URL::signedRoute('mail.unsubscribe', ['subscriber' => id])` — Laravel's signature covers the URL and uses the app key; **no expiry** (an old mail must still unsubscribe), no personal data in the URL beyond the opaque id (use a random 26-char `public_id` column instead of the numeric id to prevent enumeration — add `public_id` to the table, UNIQUE). GET shows a confirmation page with a POST button (prefetch bots must not unsubscribe people by following links); the POST sets `unsubscribed`. Unsigned/invalid → 403 with a neutral page. The same link is the footer link in every marketing mail.
- **One-click header (RFC 8058):** every marketing mail carries `List-Unsubscribe: <https://…signed…>, <mailto:unsubscribe@{domain}>` and `List-Unsubscribe-Post: List-Unsubscribe=One-Click`; the POST endpoint accepts the signed URL with the body `List-Unsubscribe=One-Click` without a CSRF token (signed instead) and is idempotent. Transactional mails do **not** carry marketing unsubscribe headers (they are not marketing).
- **Subscribe form protection:** route `POST /newsletter/subscribe` with a named limiter (e.g. 5/hour per IP and 3/day per email), a hidden honeypot field plus a minimum-fill-time token (a signed timestamp embedded in the page), validation (single address, length, lowercase), the same neutral answer whether the address exists or not (no enumeration), no welcome mail if the address is already `active` or was `unsubscribed` less than 24 h ago (with the switch on: no confirmation mail in those cases), a global cap on rows created per hour, and the welcome-mail cap above. The form shows the consent wording (its version is recorded in `mail_consent_texts`) next to the button and has no pre-ticked box: pressing Subscribe beside the wording IS the consent act. CAPTCHA only if abuse is observed (Unknown). The form posts to an uncached endpoint; the page itself stays cacheable (storefront-design §3).
- **Right to erasure:** deleting a subscriber removes the row; the `mail_log` retention (180 days) holds addresses only for operational proof and is purged by the same command.

---

## 8. Campaigns (a LATER stage)

- **Templates:** four shipped (bg/en): *new collection grid* (a title, an intro, a grid of 2–8 chosen products with image/name/price/link), *sale with percent* (headline "-N%", one image, a button), *Black Friday* (same as sale with a dark theme variant token), *plain announcement* (big image, text, a button). The merchant fills: subject, preheader, intro text (Markdown subset, §5.4), hero image (from the Media library, `large` variant URL), chosen products (picker; the mail reads **current** name/price at send time from the storefront read layer's card read model — one source of truth), the button label and link, the audience. Fields are plain text/limited Markdown with the same sanitizer; the layout is shipped and not editable.
- **Table `mail_campaigns`:** `id`, `name` (varchar 120), `template_key`, `subject`, `preheader`, `body_json` (the filled fields), `status` (`draft|scheduled|sending|sent|cancelled`), `audience` (`all_active` in V1), `scheduled_at` null, `started_at`, `finished_at`, `created_by`, timestamps. **`mail_campaign_recipients`:** `id`, `campaign_id`, `subscriber_id`, `status` (`queued|sent|failed|skipped`), `sent_at`, UNIQUE `(campaign_id, subscriber_id)` — also the idempotency and the cancel point.
- **Flow:** test send to the staff member → "Send": a command snapshots the audience (`status = active`) into recipients (chunked inserts), then dispatches **batches** of N (default 50) jobs on `mail-marketing` with a delay between batches so the rate stays under the provider's limit (`mail.campaign.per_minute`, default 120); a cancel flag stops further batches; each job checks the subscriber is still `active` at send time.
- **Segments:** all active subscribers only in V1; "customers who ordered in the last N days / never ordered" are two cheap filters over `orders.email`, deferred (non-goal: complex segmentation).
- **Stats kept minimal:** counts of queued/sent/failed/skipped per campaign, unsubscribes attributed to the campaign (by the last campaign sent to them), and whatever the provider exposes in its own dashboard. No open/click tracking.
- **Separate queue/sender:** `mail-marketing`, marketing identity (optionally a subdomain, §3). **Transactional mail never waits behind a campaign** (strict queue priority, §4, and optionally a second worker).
- **Compliance in every campaign:** unsubscribe link + headers (§7), the sender's identity and postal address in the footer (setting `mail.footer.company`), no pre-ticked consent.

---

## 9. Deliverability and legal notes (what the owner must do; confirm with an adviser)

- **DNS at the provider:** SPF include, DKIM (provider-given keys), DMARC (start `p=none` with a report mailbox, tighten to `quarantine` after clean reports). Use the same domain in From as the one authenticated. Warm up: start with low volume; do not import an old list and blast it.
- **Transactional vs marketing separation:** different identities (and ideally subdomains) so a complaint spike on marketing does not hurt order mail. Complaint rate target < 0.1 %; bounce handling: hard bounces mark the subscriber `bounced` (provider webhook or the provider's suppression list — a later stage; until then SMTP errors set `failed`).
- **EU rules to confirm:** (1) order/shipping/payment mails are **service messages**, needing no marketing consent; (2) the abandoned-cart reminder is **marketing-like** in most readings and needs consent (some countries allow a soft opt-in for existing customers — Bulgaria's reading must be confirmed); (3) newsletters need prior opt-in, a record of when/how/with what wording (§7) and an easy unsubscribe; (4) the privacy policy must describe mail processing and the processor (the mail provider = a data processor: a DPA is needed); (5) retention of `mail_log` and subscribers; (6) the company's identification in marketing mail. **None of this is legal advice;** the owner confirms points (2), (3), (5) with an adviser before launch, and the build keeps the strict reading available at zero cost.
- **What the owner configures at installation:** the shop domain, provider account, DNS records, the two sender addresses, the bank-transfer details ("Payment: bank transfer" page), the footer company line, and the texts of the five transactional mails (we write the defaults together, bg first).

---

## 10. Security checklist

- **Input:** every recipient address validated and length-capped; template subject/body length-capped; control and bidi characters refused (`PlainText` rule); subscriber and subscribe-form inputs normalised, rate-limited, honeypotted.
- **Templates:** never Blade/PHP; closed variable parser; Markdown with `html_input=strip` and `allow_unsafe_links=false`; final allow-list sanitizer; blocks come from shipped partials; unknown variables refused at save; **stored XSS** cannot survive the final sanitizer; preview in a sandboxed iframe.
- **Headers:** `MailHeader::clean()` + Symfony header API; no raw header from user data; subjects stripped of CR/LF; From/Reply-To validated single addresses.
- **URLs:** every link in a merchant body is `https:`/`mailto:`; unsubscribe/confirm links are **signed**, opaque (`public_id`), POST-confirmed, non-enumerable; the confirm-subscription token (used only while `mail.newsletter.require_confirmation` is on) is random, stored hashed (SHA-256), single-use, expiring in 3 days.
- **Secrets:** encrypted at rest, write-only in the UI, never logged, never in hook payloads or the activity journal; the "test email" error text is sanitised.
- **Logs:** `mail_log.last_error` is sanitised (strip anything resembling credentials/URLs with userinfo); `to_email` retained 180 days; the body is not logged.
- **Abuse:** subscribe and test-send endpoints throttled; campaign send needs a permission (`settings_manage` is too broad — O-M8 proposes `mail_manage`); every send action is journalled via `ActivityLogger` (who, which campaign, how many).
- **Availability:** retries bounded; failed jobs visible; a poisoned message cannot block the queue (permanent failures are not retried).

---

## 11. Staged plan

House rules: each stage ends in a review gate and a full-suite run in a private test database; no stage commits without the owner; **Must not touch** is listed per stage.

| # | Stage | Goal | Files / areas | Tests | Must not touch | Who |
|---|---|---|---|---|---|---|
| **M1** | Base + order confirmation (**small**) | `mail_log`, `MailHeader`, `MailConfigurator` (reads `mail.*` or falls back to `.env`), `SendMailJob` on `mail-transactional`, the template registry with ONE key `order.confirmation` + shipped bg/en defaults + the renderer (Markdown subset, closed variables, sanitizer), the `order.placed` listener, the 10-minute reconciliation, the bank-transfer block read from `payment.bank_transfer.*` (§6.1.1, decided 2026-10-09) and the settings page "Payment: bank transfer" that edits them | `app/Mail/**`, one migration, `AppServiceProvider` (listener), one Settings page, lang/resources | idempotency (double event, replayed checkout, retried job → one mail); header-injection cases; XSS cases through the sanitizer; fallback to default on a broken template; contents from the snapshot after the product is renamed; `log` mailer in tests; checkout never fails when the queue is down | checkout flow, orchestrator, order domain | Main |
| **M2** | Transport admin | Mail settings page: mailer select, SMTP fields, encrypted secrets, sender identities, test email, DNS help, DNS check, queue health line | Filament page, `SiteSettingsRepository` use, help topic `mail` | secret never shown/logged; write-only field; test send error sanitised; permission | the renderer | Main (secrets) + Cheap (help text, lang) |
| **M3** | Other transactional mails | `order.cancelled`, `order.shipped`, `order.payment_failed` (new hook `order.payment_failed`, registered in the Hook Reference), templates + defaults | listeners, templates, one hook fire in the orchestrator Phase 2 | each trigger once; shipped with and without tracking; cancelled with and without refund record; fake FAILED adapter | order status changer logic | Main (hook) + Cheap (texts) |
| **M4** | Template editor | `mail_templates`, writer, admin editor with preview iframe and "reset to default", validation of variables | Filament resource/page, writer | unknown variable refused; saved override used; reset restores default; length/control-char limits; permission | the sanitizer's rules | Main |
| **M5** | Needs-attention + monitoring | sources: failed mails, confirmation missing; settings-page warnings; `mail_log` retention command | `NeedsAttention` sources, command | derived facts (nothing stored); query counts (2 reads per source) | other sources | Main |
| **M6** | Subscribers + consent | `mail_subscribers`, `mail_consent_texts`, **single opt-in + welcome mail** (decided 2026-10-09) with the `mail.newsletter.require_confirmation` switch (default off; the double opt-in path is built and tested), signed unsubscribe (GET page + POST), one-click header endpoint, subscribe form endpoint with limiter/honeypot/welcome cap | migrations, controllers, routes | signed/unsigned; enumeration; one-click idempotent; welcome mail once per subscriber and carrying the unsubscribe link; switch on = double opt-in flow; limits and welcome cap | storefront cacheable pages (form posts to an uncached route) | Main |
| **M7** | Abandoned-cart mail | `cart.abandoned` listener, template, consent rules, spacing, skip rules | listener, template (needs storefront S15 detector) | never when ordered; never without consent; dropped unavailable lines; idempotent per sequence | Cart domain internals | Main |
| **M8** | Campaigns | tables, four templates, admin builder with product picker, test send, batch sender, cancel, minimal stats | resource, commands, templates | batch/throttle; recipient uniqueness; unsubscribed skipped at send time; transactional priority test (a transactional job queued behind 1,000 marketing jobs is processed first) | transactional queue config | Main (engine) + Cheap (templates, texts) |

**Order:** M1 → M2 → M3 → M4 → M5 → (M6 → M7 → M8). M1 is deliberately small and independently useful: with only M1 the shop already confirms orders. M7 needs the detector from `storefront-design.md` S15 and the guest-email capture decision (storefront O14).

---

## 12. Decisions needed from the owner (with my recommendation)

- **O-M1 — Provider for launch:** Brevo or Resend (SMTP credentials first; API drivers later). Recommend Resend or Brevo SMTP — whichever you already trust; both give SPF/DKIM records and a free tier at this volume.
- **O-M2 — Sender addresses and subdomain:** `orders@` + `news@`; a separate sending subdomain for marketing from the first campaign. Recommend yes. Which domain and which Reply-To mailbox?
- **O-M3 — Language of mail (decided by the owner 2026-10-09):** the store language (`site.locale`) only; orders do not store the customer's language. Recommend this; adding a language per order is a small checkout change later.
- **O-M4 — Bank-transfer instructions (decided by the owner 2026-10-09):** settings group `payment.bank_transfer.*` = account holder, bank name, IBAN, BIC, payment deadline in days, plus a free-text instructions field (§6.1.1); shown in the confirmation mail and later on the order page; edited on a Filament page, written in M1. Still open: whether the instructions should be copied onto the order at placement, so a later bank change does not alter an old order's mail — recommend no for now.
- **O-M5 — "Refused" vs "cancelled":** one template with an optional reason, or two? Recommend one template, optional plain-text reason.
- **O-M6 — Newsletter opt-in (decided by the owner 2026-10-09): single opt-in** — the visitor presses "Subscribe", no confirmation mail; the first mail is a welcome mail with the unsubscribe link; every marketing mail carries the unsubscribe link; setting `mail.newsletter.require_confirmation` (default off) is the later switch back to double opt-in; abuse protection stays (§7). One tick for logged-in customers; an HMAC of the IP is kept as proof instead of the raw IP. Still to confirm with the adviser: that single opt-in with a recorded consent wording is sufficient in Bulgaria.
- **O-M7 — Cart link in the reminder:** V1 = plain link to the shop (the cart is tied to a session/account); later a signed restore link. Recommend V1 plain link.
- **O-M8 — Permissions:** a new `mail_manage` permission (Administrator by default) instead of overloading `settings_manage`. Recommend yes.
- **O-M9 — Campaigns:** accept that they are a later stage (M8) and that open/click tracking is out. Recommend yes.
- **O-M10 — Retention:** `mail_log` 180 days; subscribers until erased; confirm with the adviser.
- **O-M11 — Who writes the default texts:** the five transactional texts (bg first) are written together with you before M1 ships; until then a plain factual default is used.

### 12.2 Unknowns

- Which provider and credentials; whether DNS for the chosen domain is editable by the owner.
- Whether there will be a customer-facing order page (the confirmation has no link until there is one; the bank-transfer details question is settled — §6.1.1).
- Whether APP_KEY rotation is planned (stored secrets depend on it).
- The exact moment a payment becomes FAILED in a future online-provider flow (the hook is designed, the trigger point depends on that provider's adapter).
- Provider webhook formats for bounces/complaints (not in V1).
- The courier tracking-URL patterns (no data in the code).
- Legal points in §9, and whether the owner wants CAPTCHA on the subscribe form.
