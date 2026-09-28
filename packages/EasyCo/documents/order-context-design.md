# Order Context Design

**Status:** Draft v1 — design only, nothing implemented. This document defines
*where an order came from*, *who placed it* as a machine-visible identity, *what
the customer agreed to* at checkout, and *what she asked for* (an invoice) — plus
the retention and erasure rules the personal data in that record needs. It adds
two tables, three columns on `orders`, one enum set, one capture middleware, one
resolver, two commands and one admin section. No code, no migration, and no UI
change is part of this pass.

**Builds on:** `checkout-domain-design.md` §12.1 (the mandatory terms acceptance
decided there and **never built** — this document implements that decision and
adds the proof that field does not carry), §6 (the claim), §8.3 (the placement
transaction the new writes join), §10 (the deferrals this document closes, and
the ones it deliberately leaves open). `order-lifecycle-design.md` §6 (the
`order_events` shape, writer and reader — the pattern the origin record and the
timeline's first row follow), §8.4 and §6.3 (the View page the Origin section
joins), §10 (the staged build order and the review gate this document copies,
including its "one stage, one commit, reviewed on its own" rule), §11 (the
standing commitments this document joins — no derived column, one reader per
section, no permission added lightly), §13 (the landing rule this document's §14
obeys). `cart-domain-design.md` §7/§14.1/§14.2 (the session token as the guest's
*strictly necessary* identity, the claim, and why a visitor id is **not** one).
`site-settings-design.md` (the mechanism every configurable list here lives in,
and the document whose §1 consumer list §14 amends). `admin-panel-design.md` §14
(D1 read-only, D2 snapshot-never-live, D5 one reader, D6 latest payment — the
Orders section §11 extends). `staff-access-domain-design.md` §3 (`ORDER_VIEW` /
`ORDER_MANAGE` — the two permissions used; no new permission is proposed).
`ai-collaboration-protocol.md` ("boutique, not feature-for-feature").

**Relates to:** `channel-native-commerce-vision.md` §10/§11 — the owner's own
`Order #1234 / Source: AI / Agent: ChatGPT / Attribution: chatgpt.com` sketch.
§5 here is where the *attribution* half of that becomes schema, and where the
*agent* half is explicitly left to the channel work. `storefront-frontend-design.md`
§4/§7 (the consent contract, and where a capture middleware sits relative to
Varnish). `production-requirements.md` (§7's trusted-proxy requirement lands
there). `account-domain-design.md` §11 (a saved billing profile and a marketing-
consent domain are both named future work, linked by email/visitor id, not built
here). `checkout-prerequisites-note.md`.

**Origin:** One owner brief (O1–O7), recorded here in the order it was written:
the order must show **where it came from** (advert, search engine, AI assistant,
referral, organic, direct — *and* the page the visitor entered on); the visitor's
web identifier must be visible on the order — **both** the account id (when
logged in) and an anonymous visitor id — and the IP address recorded too; cookie
consent *will* exist, with the EU regime as the conservative default but the
regime itself a per-installation setting, because the platform ships worldwide;
checkout consents are terms acceptance (mandatory, refused without it, proof
recorded) and a confirmation preference, while marketing consent is a **separate
future domain**; the aim is to make later marketing easy (campaign results, new
vs returning customers, AI-assistant traffic); the customer may request an invoice
with a billing snapshot, while the invoice **document** is a future Invoicing
domain that may be EasyCo's or an external accounting program's; and nothing
jurisdiction-specific may be hard-coded — consent regime, IP retention, legal
texts and tax-id rules are configuration or data, with Bulgarian specifics
(ЕИК/Булстат, МОЛ) as examples of country data, never schema.

Every "today" claim in §0 was re-checked against the installed source, the live
development database and the route table in this pass — not recalled from the
earlier documents. Where that check contradicted the brief or an earlier note,
the difference is written here rather than smoothed over.

---

## 0. What is true today (verified in this pass)

1. **Nothing anywhere in the application captures attribution — and that is a
   negative result, so it is stated with the search that produced it.** A scan of
   every `.php` file under `app/` and `packages/EasyCo/`, excluding `vendor/` and
   `documents/`, for `utm_*`, `gclid|fbclid|msclkid|ttclid`, `visitor`,
   `referrer|referer`, `user_agent|userAgent` and
   `REMOTE_ADDR|getClientIp|ip_address` returns **zero matches**. The only UTM
   strings in the repository are inside `channel-native-commerce-vision.md`'s own
   quoted source links. There is no first-party analytics code, no cookie banner,
   no consent mode, and no table with a place to put any of it.

2. **A guest is identified by the cart's own token, and checkout's carrier carries
   no context at all.** `carts.session_token` is server-generated (`Str::uuid()`),
   held in the Laravel session and never accepted from a client
   (`cart-domain-design.md` §8); the claim moves it to `claimed_session_token`
   (§14.1), and replay is answered per `cart_id` **plus** requester (§14.2).
   `App\Services\CheckoutInput` (`app/Services/CheckoutInput.php:33-51`) carries
   exactly seventeen arguments: `cartId`, `email`, `recipientName`, `phone`,
   `paymentMethod`, `accountId`, `guestCartToken`, and the delivery snapshot
   (`addressId`, `deliveryType`, `country`, `city`, `postalCode`, `addressLine1`,
   `addressLine2`, `carrierCode`, `pickupPointReference`, `settlement`). No
   attribution, no consent, no invoice field, no clock.

3. **`orders` is immutable and its history is FK-protected.** All 23 constructor
   parameters are `private readonly` and the only post-construction mutation is
   `assignId()` (`order-lifecycle-design.md` §0 item 3). `client_id` and
   `transaction_id` are `restrictOnDelete()`, `account_id` and `address_id` are
   `nullOnDelete()`
   (`packages/EasyCo/Order/database/migrations/2026_09_06_000001_create_orders_table.php:57-88`).
   The row already snapshots contact and delivery (`email`, `recipient_name`,
   `phone`, the eleven delivery columns) and carries no attribution, consent,
   identity or IP column of any kind. Its only indexes are the four named FK
   indexes — **there is no index on `orders.email`**, which §12 has to take into
   account rather than assume.

4. **Hooks fire only from `app/` layer code, and there are exactly two firing
   sites in the whole repository.** `Hook::fire('order.placed', $order)` at
   `app/Services/CheckoutOrchestrator.php:197` and
   `Hook::fire('account.registered', $account)` at
   `app/Http/Controllers/Api/AccountRegistrationController.php:57`; `Hook::apply`
   is called from `app/Http/Controllers/Api/*` and `app/Providers/*` only. **A
   difference from the brief, reported rather than repeated:** the brief says
   hooks are fired "only from `app/Services`" — one controller also fires one. The
   rule that actually holds is CLAUDE.md rule 10 and
   `extensibility-design-and-hooks.md` §2 — *only `app/` layer code calls
   `Hook::`, never a domain package* — and that holds without exception.

5. **Site Settings is the place for a configurable value, and it is live.** One
   `site_settings` table (`key` unique, `value` text, timestamps), a deliberately
   thin `App\Settings\Contracts\SiteSettingsRepository` with no fallback logic of
   its own, and per-call-site code defaults (`site-settings-design.md` §4-§5).
   Real keys in use today: `site.locale`, `site.currency_symbol_position`,
   `admin.activity_log_enabled`, `admin.activity_log_retention_months`,
   `catalog.season_field_enabled`, `catalog.brand_field_enabled`,
   `catalog.tags_field_enabled`, `catalog.product_group_field_enabled`,
   `catalog.product_group_required`, edited through Filament Settings pages
   (`app/Filament/Pages/Settings/{LocaleSettings,CatalogSettings}.php`).
   `config/services.php` is the other half of that convention — the values a
   *developer* sets per environment, not the merchant.

6. **There is no storefront.** `routes/web.php` contains exactly one route (`GET /`
   → `view('welcome')`). The only customer-facing pages that exist are the
   sandbox's, registered by `App\Providers\SandboxServiceProvider` under the
   `_sandbox` prefix and gated in exactly one place — `config('sandbox.enabled')`
   **and** `! app()->isProduction()` — with tests proving the routes are absent
   when either half is false (`SandboxRoutesDisabledTest`,
   `SandboxRoutesProductionTest`). The sandbox writes nothing itself: every write
   on `/_sandbox/cart` and `/_sandbox/checkout` goes to `/api/...`.

7. **`order_events` exists, and this pass checked the live database rather than
   the migration file.** `php artisan migrate:status` reports
   `2026_09_28_000002_create_order_events_table ......... [32] Ran`, and
   `php artisan db:table order_events` returns ten columns with the three FKs
   exactly as designed (`oe_order_id_foreign` → `orders` **restrict**,
   `oe_transaction_id_foreign` → `operational_sales_transactions` **restrict**,
   `oe_staff_id_foreign` → `staff` **set null**), collation `utf8mb4_unicode_ci`.
   **Nothing writes it in production yet**: `OrderEventRecorder`'s own docblock
   says "nothing in production calls this class yet", and a scan of `app/` finds
   no caller. `App\Enums\OrderEventType` has six values, **none of which is
   `placed`** — so an order's timeline is empty until a merchant acts on it, and
   the first row an order ever shows is somebody else's action (§11).

8. **No reverse-proxy trust is configured anywhere.** A scan of `app/`,
   `bootstrap/` and `config/` for `TrustProxies|setTrustedProxies|X-Forwarded`
   returns zero matches, and the framework's default is no trusted proxies at all.
   `$request->ip()` is therefore the direct socket peer today. That is harmless
   until an IP is recorded (§7) — and it matters a great deal then, because
   production is specified with Varnish in front of the app
   (`production-requirements.md`; `storefront-frontend-design.md` §4) and every
   order would otherwise record the proxy's own address.

9. **`checkout-domain-design.md` §12.1 decided the terms acceptance and never
   built it.** That section (lines 333-347) says `termsAccepted: bool` is
   "**always mandatory, not settings-controlled**", that checkout fails if it is
   not `true`, and that "both fields live on `Order` (§3) alongside the existing
   snapshotted fields — captured once, at placement". A scan for `termsAccepted`,
   `terms_accepted`, `requiresPhoneCallBeforeShipping`, `phone_call_field_enabled`,
   `checkout.form.fields` and `checkout.request.data` across `app/`,
   `packages/EasyCo/`, `lang/`, `tests/`, `config/` and `resources/` finds
   **nothing at all**: that section, both hooks and the Site Settings key are
   design only. §9 below implements the terms half of that decision, and §14
   records exactly which half is left.

10. **The permissions and the read path this design needs already exist.**
    `Permission` is a PHP enum — code, not data (`staff-access-domain-design.md`
    §3) — and its Orders block is `ORDER_VIEW` / `ORDER_MANAGE` / `REFUND_CASH` /
    `REFUND_BANK` (`packages/EasyCo/Staff/src/Enums/Permission.php:35-41`), with
    `REPORT_VIEW`, `SETTINGS_MANAGE` and `AI_MANAGE` elsewhere in the same enum.
    Roles are data (three shipped). The Orders list today has five columns and one
    filter (`OrderResource.php:166-203`), and `OrderAdminReader` is the single
    cross-table read for the whole section (D5), memoized per instance and bound
    `scoped()`. **Nothing in the panel shows an order's origin, because nothing
    records one.**

---

## 1. What this document decides, and its five boundaries

The context record answers four questions about **one order**, all of them
placement-time facts: *where the visitor came from*, *which web identifiers
connect this order to a visitor or an account*, *what the customer agreed to*, and
*what she asked for*. Everything here is written once, inside checkout's existing
transaction (`checkout-domain-design.md` §8.3), and never re-derived.

**The five things this document is not:**

1. **Not analytics.** No reports, no dashboards, no exports. §12 states which
   questions the schema must answer *cheaply* and the indexes that justifies — and
   nothing else. A report is a future read behind `REPORT_VIEW`.
2. **Not a tracker.** No JavaScript, no third-party pixel, no GA/Meta integration,
   no `document.cookie` write anywhere in this design. The storefront reports
   **raw facts**; the rules that turn facts into a classification live on the
   server, in one place (§4). The single piece of client-side storage this
   document defines is the consent-gated first-party visitor id cookie (§6).
3. **Not a consent domain.** The banner, its copy, its legal texts and the
   newsletter/marketing consent are a future, separate domain
   (`account-domain-design.md` §11, linked by email and visitor id). This document
   defines only the *modes* an installation can be in, what is written under each,
   and the consent snapshot that makes an absent attribution explainable (§8).
4. **Not an invoicing domain.** No document, no number, no date, no tax line, no
   PDF, no credit note, no export format. §9 defines the *request* and a billing
   *snapshot* — and deliberately adds no `invoice_number` column anywhere, because
   a number issued here would lock the owner into one of the two futures O6 keeps
   open ("EasyCo issues it" vs "an external accounting program does").
5. **Not channel work.** "The visitor came **from** an AI assistant" is
   attribution (§5). "The order was **placed by** an agent" is a channel question
   owned by `channel-native-commerce-vision.md`. No agent checkout is designed
   here; `source_type = agent` is a reserved value with no producer (§5).

**And one thing it is not permitted to be: jurisdiction-specific.** Every list
this design reads — AI platforms, search engines, social networks, email
providers, the referral rule's session boundary, the consent mode, the IP
retention period, the per-country invoice rules — is configuration or data, with
the EU/GDPR posture as the conservative default (O3, O7). Bulgarian specifics
(ЕИК/Булстат, МОЛ, a nine-digit company number) appear in §9 **only** as examples
of what a country's rule set can contain, never in a schema or in code.

| The question | Where the answer lives | Written by |
|---|---|---|
| Where did this order come from? | `order_attributions` — one row per order | checkout, inside its transaction (§2, §4) |
| Which visitor / account is this? | `attributions.visitor_id` + `attributions.account_id`, snapshotted | the same row (§6) |
| What did the customer agree to? | three columns on `orders` — `terms_accepted_at`, `terms_version`, `confirmation_requested` | checkout, refused without them (§9) |
| What did she ask for? | `order_invoice_details` — one row per order; **its existence means "requested"** | checkout, only when asked (§9) |

---

## 2. Storage: two tables, and three columns on `orders`

**Decision: `order_attributions` and `order_invoice_details` are new tables, and
the terms proof is three nullable columns on `orders` itself.** Which fact goes
where is decided by one question — *can this fact be legitimately absent, and does
it have a lifetime of its own?* — the same question `order-lifecycle-design.md`
§6 asks before giving `order_events` its own table.

| Fact | Home | The reason, stated as the difference between the three homes |
|---|---|---|
| Origin, identifiers, IP, analytics-consent snapshot | `order_attributions` | It is **legitimately absent** (every order placed before the feature; every order where consent forbids it), its **shape will grow**, and it is **PII with its own expiry and its own erasure path** (§7, §10) |
| Terms proof + confirmation preference | `orders` (three nullable columns) | It is **always present** from the day it ships, it is **fixed shape**, and it is **PII-free** (a timestamp and a version string) — so it never enters the erasure path, and `checkout-domain-design.md` §12.1 already decided it lives on `Order` |
| Invoice request + billing snapshot | `order_invoice_details` | It is **optional by construction** — the row's existence *is* the request — and it is a snapshot of a **second address block**, never a live read of Account |

**Why not columns on `orders`, and why a table for the terms proof was refused
too.** Twenty-five columns on a frozen aggregate whose 23 constructor parameters are
`private readonly`, half of them NULL for every order placed before the feature, on a
record whose shape is still growing — and personal data that must be erasable and
expirable (§7, §10) sitting on the row the erasure path must never touch. For the
terms proof, symmetry argued for a third table and two facts argued it away: it can
never be absent (checkout refuses without it — `checkout-domain-design.md` §12.1), so
a table would cost a join for a row that is always there, and it is PII-free, so the
"different retention" argument that justifies the other two tables does not apply.
**Two kinds of consent, two different homes:**
consent *to cookies* (`attributions.consent_mode`,
`attributions.analytics_consent`) can legitimately be absent, governs the
attribution row's own contents, and belongs with it (§8); consent *to the terms of
sale* is always present and belongs on the order (§9).

**Why the IP address is two columns, not a table of its own.** "Its own retention"
(§7) is a column-level rule, one command and one index — not a fourth table holding
two columns that would be written, read, anonymised and erased together with the row
they sit on anyway. The alternative (`order_ips`, kept deliberately narrow so the
sweep never touches another column) was considered and refused for exactly that
reason. What the requirement wants — *the IP must not live as long as the rest of the
record* — is satisfied by the pair plus §7's command, and §13.1 item 1 makes it
permanent.

**Both tables live in the root application's `database/migrations/`**, exactly
where `2026_09_28_000002_create_order_events_table.php` lives: these are app-level
context tables belonging to no domain package, and CLAUDE.md rule 9's
cross-domain-by-id posture means `EasyCo\Order` gains no knowledge of either. All
index and FK names are explicit and short (CLAUDE.md rule 5), prefixed `attr_` and
`oid_` — the convention `ord_`/`oe_`/`pay_`/`cart_` already follow.

### 2.1 `order_attributions` — one row per order, always written

```php
Schema::create('order_attributions', function (Blueprint $table) {
    $table->id();

    $table->foreignId('order_id')
        ->constrained('orders', indexName: 'attr_order_id_foreign')
        ->restrictOnDelete();
    $table->unique('order_id', 'attr_order_id_unique');

    // What the resolver decided, and why the record is what it is (§4, §8).
    $table->string('source_type');
    $table->string('capture_status');

    // The LAST touch — the one that produced this order.
    $table->string('last_utm_source')->nullable();
    $table->string('last_utm_medium')->nullable();
    $table->string('last_utm_campaign')->nullable();
    $table->string('last_utm_content')->nullable();
    $table->string('last_utm_term')->nullable();
    $table->string('last_utm_id')->nullable();
    $table->string('referrer_host')->nullable();
    $table->string('referrer_url', 500)->nullable();
    $table->string('click_id_type', 32)->nullable();
    $table->string('click_id_value')->nullable();
    $table->string('ai_platform', 64)->nullable();

    // The visit itself, as facts — never a browsing history (§3).
    $table->string('landing_page_url', 500)->nullable();
    $table->unsignedSmallInteger('session_pages')->nullable();
    $table->unsignedSmallInteger('session_count')->nullable();
    $table->string('device_type', 16)->nullable();
    $table->string('user_agent')->nullable();
    $table->timestamp('last_touch_at')->nullable();

    // The FIRST touch — what WooCommerce does not keep (§3).
    $table->string('first_source_type')->nullable();
    $table->string('first_utm_source')->nullable();
    $table->string('first_utm_medium')->nullable();
    $table->string('first_utm_campaign')->nullable();
    $table->string('first_landing_page_url', 500)->nullable();
    $table->timestamp('first_touch_at')->nullable();

    // Identity (§6) and consent (§8).
    $table->char('visitor_id', 36)->nullable();
    $table->unsignedBigInteger('account_id')->nullable();
    $table->string('consent_mode', 16);
    $table->string('analytics_consent', 16);
    $table->string('consent_source', 24);

    // Personal data with its own clock (§7).
    $table->string('ip', 45)->nullable();
    $table->timestamp('ip_anonymised_at')->nullable();

    $table->timestamp('captured_at');
    $table->timestamps();

    $table->index('source_type', 'attr_source_type_index');
    $table->index('visitor_id', 'attr_visitor_id_index');
    $table->index('captured_at', 'attr_captured_at_index');
});
```

Column by column, with the reason for the shape rather than a restatement of it:

| Column(s) | Why it is shaped this way |
|---|---|
| `source_type`, `capture_status`, `consent_mode`, `analytics_consent`, `consent_source`, `device_type` | Plain string columns holding a PHP enum's `->value`, never a native DB enum — the convention `orders.status`, `order_events.type` and `sale_lines.type` already set. The enums themselves are code (§2.1.1), so an exhaustive value list exists in one place and the label-parity test `OrderEventTypeLabelsTest` established can walk them. |
| `capture_status` | **The column that makes an absent attribution explainable**, which is why it is NOT NULL and every order gets a row: `captured` (the facts were there and were written), `skipped_no_consent` (the installation's mode forbade the persistent identity, or the visitor refused), `skipped_no_data` (a browser session that carried nothing — a typed URL, a stripped referrer). Without it, "these fields are NULL" is one state with three causes, and the merchant cannot tell a privacy decision from a lost fact (§11 renders the difference). |
| `last_utm_*`, `referrer_host`, `referrer_url`, `click_id_type`, `click_id_value`, `ai_platform` | The last touch's own evidence — exactly the set WooCommerce's Order Attribution keeps. `click_id_type`/`click_id_value` are two columns rather than a JSON map of all four click identifiers: the resolver elects **one** winning touch, so a map would hold values nothing queries. Rejected alternative: a JSON column — unqueried, unbounded, and it would make a click identifier (personal data, §10) harder to find at erasure time. |
| `referrer_url` / `landing_page_url` as `varchar(500)` | Truncated at write. The query string is deliberately preserved up to that bound — the UTM parameters live there — and a URL longer than 500 characters is a data-quality problem, not a fact worth storing. Rejected: `text` — unbounded personal data, and no length guarantee for a value that is only ever displayed. |
| `landing_page_url`, `session_pages`, `session_count`, `device_type`, `user_agent`, `last_touch_at` | **The visit, as facts the owner asked for (O1), and nothing more.** `landing_page_url` is "which page the visitor entered on"; `session_pages` and `session_count` are two integers (pages viewed in the final session; sessions this visitor has had) in WooCommerce's own meaning; `device_type` is derived from the user agent, never a classification the client asserts. **There is deliberately no list of visited URLs**: storing every page a visitor looked at would be a browsing history, it is disproportionate to the purpose, and the owner's requirement names the *entry* page, not the path through the site. That is a decision this document makes rather than inherits, and §15 Q2 keeps it visible. |
| `user_agent` as `varchar(255)`, truncated at write | Enough for diagnostics (browser/OS/device class) and deliberately not a stable fingerprint; the truncation length is `privacy.user_agent_max_length` (default 255). It is dropped in full on erasure (§10). |
| `first_source_type`, `first_utm_source`, `first_utm_medium`, `first_utm_campaign`, `first_landing_page_url`, `first_touch_at` | **The first touch — the half WooCommerce does not store** (D2), kept as its own small column set rather than by duplicating every diagnostic field twice or by writing a second row. The duplicated set is deliberately limited to the four values a report groups by, plus the entry page and its instant; `utm_content`, `utm_term`, `utm_id`, the referrer and the click identifiers are **last-touch only**, because they are diagnostic detail, not report dimensions. Rejected alternative: a second row per order (`touch = first|last`) — cleanest to normalise, but it breaks D1's "exactly one row per order" and turns the unique index into a composite, for a shape no query needs. |
| `visitor_id` as `char(36)`, nullable | A UUIDv4 with dashes, NULL for every visitor the consent rules forbade identifying (§6). There is no placeholder value: "no visitor id" is NULL, never `''` and never a zero UUID. |
| `account_id` as `unsignedBigInteger`, nullable, **no FK** | A deliberate snapshot, and the reason is the difference from `orders.account_id`: that column is `nullOnDelete()`, so deleting an account erases the only record that the order was placed while logged in. The fact "logged in, account X" is part of this order's context and must survive the account's own life; §10 nulls it on an erasure request, deliberately and audibly, rather than the database doing it silently. Cross-domain by id only (CLAUDE.md rule 9). |
| `ip` as `varchar(45)` + `ip_anonymised_at` | 45 characters is the longest possible IPv6 form, so the column is never too small for either family. `ip_anonymised_at` is set by §7's command and is what the admin renders as "anonymised on …" instead of a value — the anonymisation is a recorded event, not a silent edit. |
| `captured_at` + `timestamps()` | `captured_at` is the caller's clock — the placement instant, the same posture `order_events.occurred_at` takes and for the same reason (`order-lifecycle-design.md` §6.1: never the class's own `now()`). The timestamps exist because two commands legitimately UPDATE this row (§7, §10) and the row must be able to say when it last changed. |
| `attr_order_id_foreign` **and** `attr_order_id_unique` on the same column | Both are explicit and both are deliberate: one proves the order exists (the `restrictOnDelete()` posture `orders`, `order_events` and `sale_lines` all take toward their parents), the other proves **at most one origin record per order** — D1's rule as a DB constraint, not an application habit (CLAUDE.md rule 2). The redundancy is the point, exactly as `order-lifecycle-design.md` §4.4 argues for its two settled-payment constraints. |
| Indexes deliberately **not** added | No index on `ai_platform` — the only query using it first narrows by `source_type='ai_assistant'`, which `attr_source_type_index` already serves (§12). No index on the consent columns (nothing filters by them). No index on `account_id` (the customer-history read enters through `orders.account_id`, which already has its own FK index). No `deleted_at`, no soft deletes, no deletion path (§10). |

#### 2.1.1 The enums, and the labels that must ship with them

Six PHP enums in `app/Enums/`, each with a parity-tested label group in
`lang/en/orders.php` **and** `lang/bg/orders.php` — the exact pattern
`App\Enums\OrderEventType` + `orders.event_type_options` +
`OrderEventTypeLabelsTest` established in the lifecycle's stage 2:

| Enum | Values | Label group |
|---|---|---|
| `AttributionSourceType` | `utm`, `organic`, `referral`, `direct`, `ai_assistant`, `social`, `email`, `admin`, `agent`, `unknown` | `orders.attribution_source_options.*` |
| `AttributionCaptureStatus` | `captured`, `skipped_no_consent`, `skipped_no_data` | `orders.attribution_capture_options.*` |
| `ConsentMode` | `strict`, `notice`, `off` | `orders.consent_mode_options.*` |
| `AnalyticsConsent` | `granted`, `denied`, `unknown` | `orders.analytics_consent_options.*` |
| `ConsentSource` | `banner`, `api`, `not_asked` | `orders.consent_source_options.*` |
| `DeviceType` | `desktop`, `mobile`, `tablet`, `bot`, `unknown` | `orders.device_type_options.*` |

**`unknown` is defined by the writer that could use it, not by a wish to be
complete.** Two of the ten source values have no producer today and the document
says so rather than pretending otherwise: `admin` (an order created from the panel
— no such path exists, `admin-panel-design.md` §11 defers order creation to
checkout only) and `agent` (the channel work, §5). The value a resolver must never
produce is `unknown`: for a browser session that carried no signal at all the
answer is **`direct`** — "we looked, and there was none" — while `unknown` is
reserved for an order placed with **no browser request at all** (a console or
integration caller), where the honest statement is "the origin was never observed".
An order with **no attribution row** is neither: it is "this order predates context
capture", rendered as such and never backfilled (§11).

**`source_type` is derived, never accepted.** No API field, no header and no cookie
lets a caller assert it (§9's field-ownership table); if a client sends one, it is
ignored, not validated.

### 2.2 `order_invoice_details` — the row's existence *is* the request

```php
Schema::create('order_invoice_details', function (Blueprint $table) {
    $table->id();

    $table->foreignId('order_id')
        ->constrained('orders', indexName: 'oid_order_id_foreign')
        ->restrictOnDelete();
    $table->unique('order_id', 'oid_order_id_unique');

    $table->timestamp('requested_at');
    $table->string('recipient_type');   // App\Enums\InvoiceRecipientType: company | individual

    $table->string('legal_name');
    $table->string('tax_id')->nullable();
    $table->string('company_registration_number')->nullable();
    $table->string('representative')->nullable();

    $table->string('country');
    $table->string('city');
    $table->string('postal_code');
    $table->string('address_line_1');
    $table->string('address_line_2')->nullable();

    $table->string('invoice_email');
    $table->string('country_rule_version')->nullable();

    $table->timestamps();
});
```

- **No `requested` boolean.** The row's existence is the request; a boolean beside
  it would be a second source of truth able to say "requested" about an empty row.
- **`country` is `string`, not `char(2)`** — matching `orders.country` and
  `addresses.country` as they already are. ISO-3166 alpha-2 is a *validation* rule
  (§9), never a column width.
- **`requested_at` is the caller's clock**, the placement instant — the same
  posture as `captured_at` and `order_events.occurred_at`.
- **`country_rule_version` records which version of that country's rule set
  accepted this row.** Country rules are data (§9) and data changes; without this
  column a later tightening of a rule makes a past, correctly-accepted row
  unexplainable. It is a string the configuration owns, not a hash of anything.
- **`invoice_email` is stored, not joined.** It defaults to the order's own
  `email`, but it is a copy — the customer may want the invoice at a different
  address (an accountant's), and every other value on an order is a snapshot
  (`admin-panel-design.md` §14 D2). A live join back to `orders` would also make
  the row unreadable on its own, which is the only way it is ever read.
- **`legal_name`, `city`, `postal_code`, `address_line_1`, `invoice_email` are NOT
  NULL when the row exists**, because the request cannot exist without them;
  `address_line_2` is optional by the same logic every address in this project
  applies. `tax_id`, `company_registration_number` and `representative` are
  nullable because whether they are *required* is a per-country rule (§9), never
  the schema's business.

### 2.3 The three columns `orders` gains

```php
Schema::table('orders', function (Blueprint $table) {
    $table->timestamp('terms_accepted_at')->nullable()->after('placed_at');
    $table->string('terms_version')->nullable()->after('terms_accepted_at');
    $table->boolean('confirmation_requested')->nullable()->after('terms_version');
});
```

- **All three nullable, and NULL means "placed before this field existed"** — the
  honest value for the orders already in the database, and the only shape that does
  not invent a fact (a `NOT NULL ... DEFAULT 0` would assert that every existing
  order did not accept terms *and* did not want a confirmation, one true statement
  and one fabricated one).
- **No indexes.** Nothing filters or sorts by them.
- **This is an `add_..._to_orders_table` migration in
  `packages/EasyCo/Order/database/migrations/`** — the creating migration is never
  edited, the convention `order-lifecycle-design.md` §4.4's own migration docblock
  states.
- **The real cost, stated rather than hidden:** three new parameters on `Order`'s
  constructor, its `create()` factory, `reconstituteFromStorage()`, the Eloquent
  mapper and the fixtures. That is the price of `checkout-domain-design.md` §12.1's
  own decision that these fields live on `Order`, and §9 argues why paying it is
  still better than a third table.

---

## 3. The origin record: what each value means, and the rules that pick it

### 3.1 The ten source values, each defined by what produces it

A merchant reading a report has to know what the words mean, so the values are
defined by their **producer**, not by their vibe:

| `source_type` | Produced when | Example |
|---|---|---|
| `utm` | The touch that produced the order carried **any** `utm_*` parameter. The `utm_medium` carries the "how" (`email`, `cpc`, `social`) without this document having to guess it. | `?utm_source=facebook&utm_campaign=spring` |
| `ai_assistant` | The referrer host **or** the `utm_source` matches the configured AI-platform list (§5), which is data. `ai_platform` is set to the matched platform's label. | `utm_source=chatgpt.com` → `ai_platform = ChatGPT` |
| `organic` | The referrer host matches the configured search-engine list. | `google.com`, `bing.com`, `duckduckgo.com` |
| `social` | The referrer host matches the configured social-network list **and** carried no `utm_*` (a UTM'd social link is `utm`, which is more precise, not worse). | `facebook.com`, `instagram.com`, `tiktok.com` |
| `email` | The referrer host matches the configured email-tracker list — a newsletter or campaign link that arrives with a referrer and no UTM. A `utm_medium=email` link stays `utm`, which is why nothing is lost by keeping this value narrow. Ships with an **empty** default list. | a Mailchimp/Sendgrid click host |
| `referral` | An external referrer host that matches **none** of the configured lists. This is WooCommerce's own catch-all for "somebody linked to us". | a blog, a forum, a partner site |
| `direct` | A browser session that carried **no** referrer and **no** UTM. | a typed URL, a bookmark, a mobile app that stripped the referrer |
| `admin` | Reserved: an order created from the panel. No such path exists (`admin-panel-design.md` §11). | — |
| `agent` | Reserved: an order placed by an agent over a channel. Owned by `channel-native-commerce-vision.md`, not designed here. | — |
| `unknown` | An order placed with **no browser request at all** — a console or integration caller. Never produced by the resolver, which must answer `direct` instead. | — |

### 3.2 The precedence rules — and the two that matter most

The classification is one ordered walk, applied **server-side** (§4), never by the
client. The first rule that matches decides:

| # | Rule | Why it is in this position |
|---|---|---|
| P1 | **Any `utm_*` parameter → `utm`.** It overrides every other signal. | A UTM is the visitor's own explicit label for the visit; nothing else in the request is a stronger statement of intent. |
| P2 | Then the **AI list** → `ai_assistant` (with `ai_platform`). | Checked before the search list on purpose: some assistants pass a `utm_source` and some pass only a referrer, and the owner's own vision document treats AI as a first-class discovery channel (`channel-native-commerce-vision.md` §10). |
| P3 | Then the **search-engine list** → `organic`. | WooCommerce's own precedence: an organic search result overrides a non-UTM stored source. |
| P4 | Then the **social list** → `social`. | Same shape as P3, a different list. |
| P5 | Then the **email-tracker list** → `email`. | Same shape again; ships empty, so it changes nothing until a merchant fills it in. |
| P6 | Then **any other external referrer host** → `referral`. | The catch-all, and deliberately last among the "there is a referrer" rules. |
| P7 | Then **nothing** → `direct`. | Only reached when the request carried neither a referrer nor a UTM. |
| **P8** | **`direct` never overrides a known source.** A session that carries no signal is *not* recorded as a new touch over one that carried a real one. | **The rule that matters most.** Without it, the customer's second visit — a typed URL to finish the order — erases the campaign that brought her, and every report under-counts paid traffic. This is WooCommerce's rule and the reason `direct` is described there as "the absence of evidence". |
| **P9** | **A `referral` overrides the stored source only after the session boundary has passed** — `attribution.session_boundary_minutes`, default **30**, the industry-standard session window. A UTM or organic signal (P1/P3) overrides immediately. | The second rule that matters: a customer who lands from a blog, leaves, and comes back from the blog an hour later has had two sessions and the second one is real; a customer who lands from a blog and clicks an internal link has not been re-acquired. |
| P10 | **The first touch is written once and never overwritten; the last touch is replaced by every qualifying touch.** Both are read at placement and written onto the order's row. | The two-touch model D2 asks for, expressed as one write rule. |

### 3.3 What the rules cannot do, stated as limits rather than gaps

The chain these rules walk is the visitor's **own browser memory plus the server
session, and nothing else** — there is no visitor table, no identity graph and no
cross-device store anywhere in this design. Four consequences follow, and they are
recorded because a merchant will otherwise meet them as surprises:

1. **No cross-device or cross-browser attribution.** A customer who discovers a
   product on a phone and orders on a laptop has two unrelated chains. Multi-touch,
   cross-session and cross-device tracking are explicitly out of scope.
2. **A cleared cookie or a private window loses the first touch.** The last touch
   survives, because it travels in the request itself.
3. **P9's 30-minute rule is only evaluable where the browser still remembers the
   previous touch's instant.** In a fresh chain the current touch becomes both
   first and last. That is a real limit of a two-touch model without a device
   identity, and it is why the rule is described as an override test rather than a
   session reconstruction.
4. **A stripped referrer is indistinguishable from `direct`, and this design
   refuses to guess.** No heuristic ("is this IP range a known assistant?") is
   applied: a heuristic that invents a source is worse than an honest `direct`
   (fail-loud over silent guessing). This is the mechanical reason AI-assistant
   counts are a **lower bound** (§5).
5. **A campaign value is a claim, not a fact, and this design does not pretend
   otherwise.** Anyone can append `?utm_source=…` to a link — a competitor, a
   merchant's own staff testing checkout, a customer forwarding a URL — and the next
   order will carry it. Verifying it would mean operating a click-tracking database,
   which is a different product and a different consent story. The design's answer is
   the merchant's own knowledge plus the reason line in the admin (§11.1), not a
   validation rule that cannot exist.

**The resolver is not a hook, and no new hook is added by this design.** Every list
it consults is data (§2.1.1, §5); a `Hook::filter` over the *classification* would
let a listener contradict the rules and make "where did this order come from" have
two answers — the outcome `order-lifecycle-design.md` §11 item 18 avoids for the
transition matrix. `checkout-domain-design.md` §12.2's `checkout.request.data`
filter is also not the place for any of this: that section already states that
EasyCo persists nothing a listener adds there, and the context rows are written
inside the orchestrator's transaction, where no filter runs.

---

## 4. One resolver, fed by raw facts — and where each fact comes from

**Decision: `App\Services\OrderAttributionResolver` is the only place a
`source_type` is ever decided (D2's own recommendation), and the storefront's only
job is to report raw facts.** The rules of §3.2 therefore live in one testable
class, and the storefront — which does not exist yet — inherits a contract instead
of a policy.

### 4.1 The three inputs, and why each is trustworthy

| Input | Where it comes from | Why it can be trusted |
|---|---|---|
| The touch that produced the order: its URL and query string (`utm_*`, click identifiers), the `Referer` header, the `User-Agent`, and the client address | The HTTP request itself, read server-side | Nothing is stored on or read from the visitor's device — this is data the visitor herself sent, in the request that placed the order |
| The session's chain: first touch, last touch, pages viewed, session start | The Laravel session (`cart-domain-design.md` §8's own strictly-necessary session) | Written by the capture middleware (§4.2), never accepted from a client body |
| The **first** touch and the visitor id, when they outlive the session | The consent-gated first-party cookie (§6) | The one client-supplied input in the whole design — and it carries **raw facts only**. The server re-derives the classification from them; a `source_type` arriving from a browser is ignored (§9) |

**No field of the checkout request body contains any of this** (§9's ownership
table), which is what makes "no trust in client-supplied `source_type`" a
structural property rather than a validation rule.

### 4.2 The capture point: one middleware, and the session is its only store

**Decision: `App\Http\Middleware\CaptureAttribution` runs on the storefront's
customer-facing `GET` routes, and is the only writer of the visit chain** (D2's
"the storefront only reports raw facts"). While the real storefront does not exist
(§0 item 6), it is registered on the sandbox's `/_sandbox` routes so the manual
checklist can exercise it end to end (§12):

1. It builds an `AttributionTouch` — a small readonly DTO of **raw facts only**
   (the request's full URL, the `Referer` header, the `utm_*` parameters, the first
   configured click identifier present, the `User-Agent`, the client address, the
   instant). No classification happens here.
2. It applies §3.2's rules through the resolver against the chain the session
   already holds, and writes the chain back: `first` (written once, ever), `last`
   (replaced by every qualifying touch), the distinct page count for the session,
   and the session's start instant.
3. **It runs on `GET` only.** The placement `POST` is not a touch: the ordering
   request's own `Referer` is our own checkout page and carries no campaign, so
   letting it into the chain would only ever add noise. The orchestrator instead
   reads the chain at placement, and merges the placing request's own UTM
   parameters if it carried any (a campaign link that posts straight through, where
   P1 makes that touch win).
4. **It writes nothing to the database.** Browsing touches only the Laravel
   session; the single DB write in this whole design happens at placement. That is
   a deliberate property, not an accident of the first implementation: no browser
   request in this design creates a row anywhere.

### 4.3 The one interaction with Varnish, named before it bites

A cacheable storefront page served by Varnish **never reaches PHP**
(`production-requirements.md`; `storefront-frontend-design.md` §4), so the
middleware never sees the campaign URL on a cache hit — a real, concrete way to
lose a first touch that a naive reading of §4.2 would miss. Two answers were
considered:

- **(a) Accept it.** The first PHP-executed request of the same visit usually
  carries the same campaign in its `Referer` anyway, and a single-visit purchase
  loses nothing. Simple, and honest about the trim.
- **(b) Recover it from the storefront's own hydration call — recommended.** The
  storefront's header hydration already performs a same-origin `fetch('/api/cart')`
  on every page load (`storefront-frontend-design.md` §4), and a same-origin
  `fetch` sends the **full** referring URL by default — including the campaign's
  query string — so the middleware can capture the landing touch from that request
  even when the HTML came from cache. It costs nothing extra (the request happens
  anyway) and it needs no new endpoint.

**(b) is the recommendation, with its dependency stated:** it depends on the
storefront's hydration call existing, so it is not a guarantee this design can
make on the storefront's behalf — and in the sandbox, where there is no Varnish,
capture is unconditional. The landing page URL of a cache-hit visit is therefore
"best effort" in production until the storefront exists and is measured, and §15
Q4 keeps that visible rather than asserted away.

### 4.4 The classes, and the fact that none of them needs a provider

| Class | Role |
|---|---|
| `App\Http\Middleware\CaptureAttribution` | Reads the request, applies the rules, maintains the session chain. Registered on the storefront's (and today the sandbox's) `GET` routes. |
| `App\Services\AttributionTouch` | Readonly DTO of raw facts. No behaviour. |
| `App\Services\OrderAttributionResolver` | The only place `source_type`/`ai_platform` are decided. Pure, stateless, unit-testable without a database — it reads the configured lists through the injected `SiteSettingsRepository` and nothing else. |
| `App\Services\ResolvedAttribution` | Readonly DTO the resolver returns: the winning source, both touch column sets, the capture status and the consent snapshot. |
| `App\Models\OrderAttributionModel`, `App\Models\OrderInvoiceDetailsModel` | Plain Eloquent models, the shape `OrderEventModel`/`ActivityLogModel` already take — no relations, no domain entity, no repository (these are factual records, not protected business invariants) |

**No new service provider binding.** The resolver and the middleware are
constructor-injected and container-resolved like `PromotionValidator`, and the only
stateful dependency they take (`SiteSettingsRepository`) is already bound
`scoped()` with its own memoization tests.

**A writer that has no resolver must say so in the row.** `source_type` and
`capture_status` are `NOT NULL`: a console or integration caller writes
`unknown` + `skipped_no_data` — an explicit statement that the origin was never
observed — and cannot leave the row ambiguous or half-filled.

---

## 5. AI traffic is a discovery channel; an agent is a channel

**Decision (D3): `ai_platform` is derived from a configured list in Site Settings,
never from a hardcoded one.** `attribution.ai_platforms` holds a JSON list of
`{"label": …, "hosts": […], "utm_sources": […]}` entries, shipped with the
assistants that matter today as **data** — ChatGPT (`chatgpt.com`,
`chat.openai.com`), Perplexity (`perplexity.ai`), Claude (`claude.ai`), Gemini
(`gemini.google.com`), Copilot (`copilot.microsoft.com`) — and editable by the
merchant without a release. A new assistant appears every few months; a hardcoded
list makes this column wrong within a release cycle, which is exactly the failure
mode O7 forbids.

**`ai_assistant` is a `source_type` of its own (P2), not a generic referral with a
label on the side.** Rejected alternative: leave AI visits as `referral` and keep
`ai_platform` as an annotation — it costs nothing in the schema and reads fine on
one order, but it makes the owner's own question ("orders and revenue from AI
assistants") unanswerable by the column every report groups by, and
`channel-native-commerce-vision.md` §10 treats AI as a first-class discovery
channel on purpose.

### 5.1 The numbers are a lower bound, and that is stated everywhere they appear

How much of an AI visit is even *visible* depends on the assistant, not on us:
ChatGPT appends `utm_source=chatgpt.com` to some links (the behaviour the owner's
own vision document already cites from OpenAI's product-discovery material),
Perplexity passes a referrer for many links, Claude and Gemini do so
inconsistently, and native mobile apps commonly strip the referrer entirely — with
the linking page's own `Referrer-Policy` able to strip it as well, outside our
control. Per §3.3 item 4, a visit with no signal is recorded as `direct`, not
guessed at. Three consequences, all deliberate:

1. **AI-assistant orders are a lower bound**, and the admin renders that sentence
   next to the number (§11) rather than letting the figure be read as complete.
2. **No heuristic is applied** — no "known assistant IP range" guessing, for the
   same reason `ai-collaboration-protocol.md` prefers a loud failure to a silent
   guess.
3. **The list stays current by being data**, so the day an assistant starts sending
   a new `utm_source`, the owner adds a line to a setting instead of waiting for a
   release.

### 5.2 The boundary with agents, and why it is drawn here

**Attribution answers "the visitor came *from* an AI assistant". It does not
answer "the order was *placed by* an agent".** The second question is a channel
matter, owned by `channel-native-commerce-vision.md` §11 ("the AI agent is not a
human"), and nothing about agent checkout is designed here:

- `source_type = agent` is a **reserved value with no producer** (§2.1.1). When the
  channel work lands, an agent-placed order writes it **directly** — never through
  this resolver, which never invents it — and `ai_platform` then carries the
  platform the agent ran on. That is the one mapping the two documents have to
  agree on, and this document states it now so the channel work inherits a slot
  rather than a conflict.
- **A bot user-agent is never evidence of an agent order.** `device_type = bot` is
  a diagnostic fact about the request; it is never promoted into `source_type`.
  Conflating "a machine read the page" with "a machine bought the order" would make
  the one number the owner actually wants (`agent` orders) meaningless.
- Nothing here reserves a channel column, a channel table or a channel enum: an
  order's channel is the channel work's own concern, and this design adds none.

---

## 6. Visitor identity, and the history that is always derived

**Decision (D4): one first-party `visitor_id`, one consent rule, and no stored
customer counters.**

- **What it is:** a UUIDv4 (`Str::uuid()`) in a first-party cookie named
  `visitor_id` — `HttpOnly` (the storefront never needs to read it; the browser
  sends it), `SameSite=Lax`, `Secure` in production, `path=/`, lifetime
  `privacy.visitor_cookie_lifetime_days`, recommended **365**. The EDPB/ICO
  guidance ceiling is 13 months; the merchant sets the number, and the design
  recommends staying at or under a year.
- **When it exists is entirely §8's decision, and it is the only cookie this
  design introduces:** under `strict` it is written only when
  `analytics_consent = granted`; under `notice` it is written unless the visitor
  opted out; under `off` it is always written. When it is not written, the order's
  `visitor_id` is NULL and `capture_status` says why (`skipped_no_consent`) — never
  a placeholder value.
- **Nothing about the cart depends on it, and that is a commitment, not an
  observation.** `carts.session_token` is *strictly necessary*, session-scoped and
  server-generated, and it keeps working exactly as it does today with no consent
  at all (`cart-domain-design.md` §7, §14.1). A visitor who refuses everything can
  still fill a cart and order; she simply has no visitor id, which is precisely
  what O3 implies. §14 schedules the one sentence `cart-domain-design.md` §7 gains,
  so nobody later "unifies" the visitor id and the cart token into one identity.
- **What the order then shows (O2), in the admin's Origin section:** the account id
  as a snapshot (`attributions.account_id`, resolved from `Auth::guard('customer')`
  — the same check `CheckoutController` already performs), and the visitor id
  beside it. Both identifiers are the point: "an account ordered, and this visitor
  had ordered before" and "a guest ordered, but this browser is a returning
  visitor" are two different merchant questions, and one identifier alone answers
  neither.

### 6.1 Customer history is derived by query — one grouped query, never a counter

**Decision: nothing about a customer's history is stored, anywhere.** No
`orders_count`, no `total_spent`, no `last_order_at` column on `accounts`, on
`orders` or on the attribution row: each would be a second source of truth for a
fact a read already answers correctly — `order-lifecycle-design.md` §11 item 11's
own rule, applied to a customer rather than to a return.

- **The identity, in precedence order:** `account_id` when the order has one, else
  `email`, else `visitor_id`. Email is the middle key because it is the only
  identifier a guest leaves behind, and it is compared with the column's own
  collation — `utf8mb4_unicode_ci`, verified against the live database (`db:table`,
  §0 item 7) — so equality is already case-insensitive and **no `LOWER()` is
  wrapped around it**, which would defeat the index §12 adds.
- **The query:** one grouped read over `orders` (left-joined to its attribution row
  for the visitor-identity case), returning **one row**: orders count, cancelled
  count, refunded count, first order, last order, total spent.
- **The counting rule is not invented here** — it is
  `order-lifecycle-design.md` §11 item 20's rule reused verbatim: a **cancelled**
  order was never a purchase and is excluded from the count and the total; a
  **refunded** one happened and stays in both, counted separately as refunded.
  Reusing it is what stops "new vs returning customer" and the promotion rule
  `PromotionUsageContextAssembler` already applies ("has this customer bought
  before?") from meaning two different things about the same order.
- **Read once per View page, never on the list.** It joins
  `OrderAdminReader::forOrder()`'s existing set of targeted reads (D5's single
  reader), so the Orders list's query shape is unchanged: no per-row history query,
  no N+1 (§11).
- **Admin-only, behind `ORDER_VIEW`.** Matching orders by email associates a person
  with their purchases; `checkout-domain-design.md` §10 owns "guest order lookup by
  order number + email" as a still-deferred gap, and this design deliberately does
  not open it on the storefront.

---

## 7. The IP address: purpose, one clock, one permission — and the proxy nobody configured

**Decision (D5): the IP is recorded on the attribution row, for a stated purpose,
with its own shorter clock and its own anonymisation command — and the design says
plainly that its retention period and legal basis are for the owner and his lawyer
to confirm.**

### 7.1 Purpose, and the permission that may see it

- **Purpose, stated once and then enforced by having been written down:** fraud,
  security and dispute evidence — "who placed this order" when a chargeback, an
  abuse report or a broken delivery is investigated. **It is never used for
  marketing, never for profiling, and never joined to derive behaviour.** That
  sentence belongs in the column's own docblock and in the admin's own label,
  because an IP collected for security and later read for targeting is a purpose
  creep nobody would notice from the schema alone.
- **`ORDER_VIEW` alone is not enough to see it.** The full value is read **only**
  when the requesting staff member holds `Permission::ORDER_MANAGE` — the existing
  permission, no new one (§0 item 10; `order-lifecycle-design.md` §11 item 16's "no
  new permission" posture). The gate decides **whether the read happens**, not
  whether a rendered value is hidden: `OrderAdminReader::forOrder()` deliberately
  does **not** carry the IP, and the Origin section calls one additional,
  explicitly permission-gated reader method for it — so a staff member without
  `ORDER_MANAGE` causes no query and has no IP value in memory at all. Rejected
  alternative: read it always and hide it in the view — a hidden value is still a
  value, and on a Livewire page "hidden" is one render-flag away from being shipped
  to the browser.
- **An anonymised value is not personal data, and ordinary staff may see that it
  happened.** Once §7.3 has run, the section shows "anonymised on <date>" (plus the
  anonymised network) to anyone with `ORDER_VIEW`: a date and a network are not
  identifying, and hiding the fact would leave ordinary staff wondering why the
  field is empty. Only the **full** value is gated.
- **No `PRIVACY_VIEW` permission is proposed.** It is the obvious next refinement —
  a role that may investigate fraud without being allowed to change an order — and
  §15 Q6 records it as the owner's decision rather than this document inventing a
  permission the Roles section would then have to carry.

### 7.2 Retention: full for a configurable window, then anonymous

- **`privacy.ip_full_retention_days`, default 30.** A merchant setting (O7: never
  hardcoded, and the conservative default is the short one). The recommendation is
  deliberately modest: an IP's forensic value is almost entirely in the first days
  of an order.
- **After the window the value is replaced by its anonymised form, in place, and
  `ip_anonymised_at` is stamped.** IPv4's last octet is zeroed and IPv6 keeps its
  routing prefix — **the project already ships the tool:**
  `Symfony\Component\HttpFoundation\IpUtils::anonymize()` (available through
  Laravel's own dependency; the installed version even exposes the
  `$v4Bytes`/`$v6Bytes` parameters). Nothing hand-rolled, and if the owner's lawyer
  prefers a coarser IPv6 prefix (a /48 rather than the default /64), that is a
  parameter change rather than new code.
- **Two states, one column, and no third one.** `ip` is either the full address
  (`ip_anonymised_at` NULL), the anonymised form (`ip_anonymised_at` set), or NULL
  (erased — §10 — or never captured, which `capture_status` explains). A separate
  `anonymised_ip` column was considered and refused: it would let a row hold both,
  which is exactly the state that must never exist.
- **A full IP never leaves this one column.** No log line, no `activity_log` row,
  no exception message carries a full address; §10's command logs *that* a row was
  anonymised, never the value.

### 7.3 The command, and the one place it is scheduled

**`privacy:anonymise-order-ips`** — a plain `Command` in `app/Console/Commands/`,
built in `PruneActivityLog`'s own shape (a settings read, one `where` clause, an
`->info()` summary line, `self::SUCCESS`), selecting rows where `ip IS NOT NULL AND
ip_anonymised_at IS NULL AND captured_at < cutoff` — the `attr_captured_at_index` of
§2.1 paying for itself here (§12). Scheduled **daily** in `routes/console.php`, the
one place any schedule exists in this project today
(`Schedule::command('activity-log:prune')->daily()`), and deliberately daily for
`PruneActivityLog`'s own reason: the command is a cheap no-op when nothing has aged
out.

### 7.4 Behind a reverse proxy, the recorded IP is the *client* IP only if production configures it

**This is the one thing about the IP that is neither the merchant's to configure nor
this design's to assume.** §0 item 8 records the fact: nothing in `app/`,
`bootstrap/` or `config/` trusts a proxy today, so `$request->ip()` is the socket
peer — behind Varnish (which `production-requirements.md` places in front of the
app) or any load balancer that is **the proxy's own address, identically, on every
order**. What production must configure, stated exactly:

- **Laravel's `TrustProxies` middleware with the real proxy addresses** — the
  specific proxy IPs/CIDRs, never `'*'` — and the forwarded headers actually in use
  (`X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Host`, `X-Forwarded-Port`).
- **Never blindly trust `X-Forwarded-For`.** The header is client-spoofable; it is
  trustworthy only when it arrives from a proxy we control, which is why the
  middleware is configured with addresses rather than a wildcard.
- **A test, because this failure is invisible otherwise:** with the app's proxy
  configuration absent, a request carrying a forged `X-Forwarded-For` must **not**
  have that value recorded; with the proxy chain configured, a request arriving
  through it must record the real client address rather than the proxy's. Without
  that pair of tests a misconfigured deployment records a plausible-looking wrong
  answer on every order — the worst kind of wrong, because nothing looks broken.
- **This is operator configuration, not a Site Setting** (`config/*` and `.env`,
  the developer half of the convention, §0 item 5): a merchant cannot be asked to
  know their load balancer's addresses. §14 schedules the bullet
  `production-requirements.md` gains.

### 7.5 What this document will not pretend to know

The lawful basis for recording an IP, the retention period that satisfies it, and
whether a data-protection impact assessment is required are **the owner's and his
lawyer's decisions, not this design's**. What this document provides is the
machinery to comply with whichever answer comes back — a configurable window, an
automatic anonymisation, an erasure path, and a purpose written down — with a
recommended default of 30 days and the option of **zero**, meaning anonymise on
write, for an installation that would rather never hold a full address at all.
§15 Q5 keeps the question visible.

---

## 8. Consent: a per-installation regime, and exactly what is written under it

**Decision (D6): the consent regime is a setting — `privacy.consent_mode`, default
`strict` — and the regime *and* the visitor's own state are snapshotted on the row
they governed, so an absent field always has a recorded reason.**

### 8.1 Three modes, one default, and one of them explicitly not recommended

| Mode | What it means | Where it fits |
|---|---|---|
| `strict` **(default)** | Opt-in. Nothing is stored on, or read from, the visitor's device except what the service strictly needs to work; the visitor id and the cross-session first touch are written **only** after an explicit grant. | The EU/GDPR posture (O3), and therefore the default of every installation until its owner says otherwise |
| `notice` | Notice with opt-out. Non-essential storage is written on the first visit; the visitor can refuse, and refusing stops it. | Jurisdictions (and merchants) that require notice rather than prior consent |
| `off` | No regime: everything is written unconditionally. | For an installation whose owner accepts it, in a jurisdiction that does not require either. **Not a recommendation, and stated as such**: it is here because the platform ships worldwide (O7), not because it is equal in standing to the other two |

The EU default is a *default*, not a hardcoded assumption: no jurisdiction is named
anywhere in code, and the mode is changeable by the merchant (`SETTINGS_MANAGE`).

### 8.2 Three states, and why `unknown` must be storable

`analytics_consent` is `granted`, `denied` or `unknown`, with
`consent_source` recording how the answer arrived (`banner`, `api`, `not_asked`).
**`unknown` is a real state and never inferred as `denied`:** a sandbox or API
caller, a browser with JavaScript disabled, and a `notice`-mode installation's
visitor who has not acted yet all share it honestly. Collapsing `unknown` into
`denied` would make a merchant's reports say that customers refused something they
were never asked.

### 8.3 What is written under each mode — the table this decision exists for

| Storage item | `strict` | `notice` | `off` |
|---|---|---|---|
| Session cookie + CSRF token (`XSRF-TOKEN`) | Always — strictly necessary | Always | Always |
| `carts.session_token`, the guest's cart identity (held in the session, `cart-domain-design.md` §8) | Always — strictly necessary, and unchanged by this design | Always | Always |
| The visit chain in the server session (landing page, UTM, referrer host, device class, page count, session start) | **Written** — see §8.4 item 2, where this is flagged | Written | Written |
| `visitor_id` cookie | **Only** when `analytics_consent = granted` | Written unless the visitor opted out | Always |
| First touch remembered beyond the session | Travels in that same cookie, so: consent-gated identically | Opt-out | Always |
| `session_count` greater than 1 (a cross-session count) | Only with consent; otherwise the value is always exactly `1`, which is truthful | Opt-out | Always |
| The `Referer` header, the request's own URL and query, the device class, the IP | Read from the request that arrives (server-side) | Same | Same |
| The order's own record: `source_type`, the touch columns, the landing page, the IP | Written from the request and the session — this is the design's reading of ePrivacy, flagged in §8.4 item 1 | Written | Written |
| The consent snapshot itself (`consent_mode`, `analytics_consent`, `consent_source`, `capture_status`) | Written on every order, including when everything else is skipped | Written | Written |

### 8.4 Where this design is legally uncertain, and says so

**No lawyer has read this.** The following five points are the places where the
design's reading could be wrong, each with what the correction would cost — which
is deliberately small, because the machinery is a setting and a gate, not a
structure:

1. **Recording the landing page, the UTM parameters and the referrer on the order
   without consent.** The design's reading: the visitor transmitted them in the
   request that placed the order, they are processed first-party for the order's own
   record, and **nothing is stored on or read from her device** — so this is not the
   ePrivacy "storage" the directive governs. **If the lawyer disagrees**, the
   correction is the resolver's consent gate refusing the request-derived facts under
   `strict` + `denied`, which writes `capture_status = skipped_no_consent` and
   nothing else: one branch, one test, no schema change.
2. **Whether the visit chain in the server-side session counts as device storage.**
   The design's reading: no — the cookie carries only the session id, which is
   strictly necessary for the cart that already exists, and the chain lives on the
   server. This is the least certain of the five, and the correction is the same
   shape as item 1's.
3. **The visitor id is *not* strictly necessary**, and this design refuses to treat
   it as if it were: it exists to link a customer's own visits and orders, which is
   exactly the "analytics" purpose consent is for. That is why `strict` writes it
   only after a grant rather than arguing an exemption.
4. **The IP's lawful basis and retention** belong to §7.5, and are the lawyer's.
5. **Whether a consent *record* must be kept, and for how long.** This design stores
   the consent state **on the order it governed** (proportionate, order-scoped, and
   it is what makes the order's own data explainable) and deliberately keeps **no
   global consent log** with a row per visit. If the merchant's lawyer requires
   longer proof than the order's own life, that is a separate record with its own
   retention — a new decision, not a tweak to this one.

**What is *not* uncertain, and is therefore not asked about:** the banner's own
requirements (granularity, a reject option as prominent as accept, re-consent
intervals) are the future storefront/consent domain's. The contract this design
defines is only the three states and one setting; building the banner is not part
of it (non-goal), and nothing here presumes how it will be built.

### 8.5 What the snapshot is *for*

`consent_mode`, `analytics_consent`, `consent_source` and `capture_status` are
written on **every** order, including orders with nothing else in the row, because
they are the only thing that makes this record honest later: an order whose
`visitor_id` is NULL and whose `capture_status` is `captured` had no visitor id
because none could be written (or the visitor id simply was not there yet), while
`skipped_no_consent` says a privacy decision produced the empty field. A merchant
reading a report that says "12% of orders have no source" can then tell the three
causes apart instead of guessing — which is the whole point of recording consent
*state* rather than a boolean.

---

## 9. Checkout: the terms proof, the confirmation preference, the invoice request

### 9.1 The terms proof — implementing `checkout-domain-design.md` §12.1, with the proof it lacks

§12.1 already decided the substance: `termsAccepted` is mandatory, checkout fails
without it, and it lives on `Order`. What that decision does **not** carry is the
*proof* O4(a) asks for — **when** the customer accepted and **which text** she
accepted — so this design adds the two columns §2.3 defines and three small rules:

1. **Mandatory, and never settings-controlled** — §12.1's own words, unchanged.
   Missing or `false` is a `422` with the machine reason `terms_not_accepted`, and
   the API *requires* the field (`required|accepted`) so an old client cannot
   silently place an order without it.
2. **`terms_accepted_at` is the placement instant**, taken from checkout's own clock
   argument (`$placedAt`) — never a client-supplied timestamp, the same discipline
   `order_events.occurred_at` follows.
3. **The version is the merchant's own string, and the server verifies it.** Two
   Site Settings keys: `checkout.terms_version` (the current version's string, e.g.
   `2026-09-28`) and `checkout.terms_url` (where the text lives — the storefront's
   own page). The storefront sends the version it *displayed*; if it does not match
   the current setting, checkout refuses with `terms_version_changed` (422) and the
   customer re-reads and re-accepts. **Rejected alternatives, both worse:**
   recording the *current* version regardless of what the page displayed (silently
   claims the customer accepted a text that was never on her screen) and not sending
   a version at all (the server then has no way to notice a stale page). **Also
   rejected:** storing a copy of the terms text per order, or hashing it — the text
   lives in the storefront, the server cannot hash what it does not hold, and a
   per-order copy would be a second source of truth for a legal document. The
   version string plus the URL is the proportionate proof; §15 Q7 asks the owner who
   bumps the version and whether a dated changelog of texts is wanted.

### 9.2 The confirmation preference — what it controls, and what it does not

- **`confirmation_requested`, a checkbox on the checkout form, ticked by default.**
  Whether the field is *offered* is not a Site Setting: O4(b) is a customer choice,
  the same way the terms checkbox is not optional. When the field is **absent** (an
  API or integration caller), the value stored is **NULL — "not asked" — and not
  `true`**: the schema supports the distinction (§2.3), and inventing a preference
  the customer never expressed is exactly what "never fabricate" forbids.
- **What it controls:** the future order-confirmation **email**, through the
  `order.placed` listener that stage will register. A `NULL` (never asked) is
  treated by that listener as **send it** — the transactional default — while the
  record stays honest that nobody was asked.
- **What it does not control, stated because the conflation is easy:** it does not
  control the order's own record, the panel, the timeline, or any document a
  jurisdiction requires. An order confirmation is a **transactional** message —
  which is why it needs no marketing consent, and why it is not the newsletter.
  Marketing consent is a separate future domain (§1 item 3), linked by email and
  visitor id, and **no marketing field exists anywhere in this design**.
- **A legal flag, not an assumption:** some jurisdictions treat an order
  confirmation as mandatory regardless of any preference. If the owner's lawyer says
  so, the correct change is that the email stage ignores the flag entirely for the
  message a law requires — a decision in *that* document, recorded here so it is not
  discovered later (§15 Q9).

### 9.3 The invoice request — a snapshot, and never a document

**Decision (D12): the request and a billing snapshot live in
`order_invoice_details` (§2.2); the document itself stays a future domain.** The
whole rule set, in the order it runs at checkout:

1. **Whether the field is offered at all:** `invoice.enabled` (Site Settings,
   default `'1'`). Default on, because "the customer may request an invoice" is the
   decided behaviour (O6) and, in the EU, being unable to ask for one is a legal
   problem for the merchant; a B2C-only merchant who does not want the field turns
   it off. Off means the checkbox is not rendered **and** the API refuses a request
   that arrives anyway (`invoice_not_supported`, 422).
2. **What is asked, per `recipient_type`:**
   - `company` — `legal_name`, `tax_id`, `company_registration_number` (optional),
     `representative` (optional), the billing address, `invoice_email`.
   - `individual` — `legal_name`, the billing address, `invoice_email`.
     **No tax-id or national-ID field is offered for an individual**, per O7's
     "no national-ID collection for individuals": if a jurisdiction requires a
     personal tax number on a B2C invoice, that is a deliberately separate country
     rule the owner adds with his accountant, never something this design invents.
     A request carrying `tax_id` with `recipient_type = individual` is refused
     (`invoice_details_invalid`) rather than silently dropping the value.
3. **Validation is per-country DATA, and the design says where its authority ends.**
   `invoice.country_rules` (Site Settings, JSON, keyed by ISO-3166 alpha-2) with
   `invoice.country_rules_version` recorded onto every row (§2.2). One entry may
   carry `tax_id_label`, `tax_id_required_for_company`, `tax_id_pattern`,
   `registration_number_required`, `representative_label` and
   `individual_tax_id_enabled`. A Bulgarian entry would look like
   `{"tax_id_label": "ЕИК/Булстат", "tax_id_required_for_company": true,
   "tax_id_pattern": "^[0-9]{9,13}$", "representative_label": "МОЛ"}` — **an example
   of country data, never schema and never code, and every pattern field carries the
   settings-screen hint "confirm the format with your accountant"**. The design
   asserts no national format as fact (§15 Q10).
4. **With no rule for a country — the worldwide default —** the generic rule applies:
   name, address and email required; `tax_id` required for a company with **no
   format check at all**; nothing else asserted. That is the honest default for a
   jurisdiction nobody has researched.
5. **A format the merchant entered is enforced; a format nobody entered is not.**
   The project's "warning over blocking" principle applies to *guesses*, and this is
   not one: a merchant who typed a pattern into his own settings means it, and an
   invoice carrying an invalid tax number is a document that has to be corrected
   afterwards. So a configured pattern is checked and a mismatch is a `422` naming
   the field and quoting the configured label; an absent pattern is not checked.
6. **`invoice_email` defaults to the order's own email** and is stored as a copy
   (§2.2), because the invoice may legitimately go to an accountant's address.
7. **The row is written inside the placement transaction** (§9.5), only when a
   request was made. The row's existence *is* the request, and there is **no status
   column, no "issued" flag and no invoice number** (§1 item 4).

### 9.4 The boundaries this design keeps, and the two it names for later

- **A future Invoicing domain owns the document.** Whichever way the owner decides
  (O6), the input is the same row: an EasyCo-issued invoice reads
  `order_invoice_details` + `orders` + `sale_lines` and writes its own tables, its
  own numbering and its own PDFs; an external accounting program gets an export of
  the same rows. Nothing here constrains either — the deliberate absence of a number
  is what keeps both open, and no tax amount, rate or legal wording is computed or
  stored anywhere in this design.
- **A future saved billing profile on `Account`** (§11's own deferral list) would
  become the *source of a form's defaults*; this row stays the snapshot, exactly as
  an order's delivery address is a copy rather than a live read of the address book.
- **Editing: the details stay editable while the order is editable, and close for
  money-changing edits once an invoice exists.** Stated as a **future constraint,
  because nothing about an order is editable today** (`order-lifecycle-design.md`
  §9): no lines, prices or addresses are edited after placement. The constraint is
  written here so the first editing feature reads it rather than rediscovers it, and
  which permission may edit is `ORDER_MANAGE` — the same one that owns every other
  write on an order.
- **The customer cannot correct her own invoice details after checkout today.** No
  storefront account surface exists; she contacts the merchant, who (once editing
  exists) corrects the row. §15 Q8 records the customer-facing correction path as a
  real, named gap rather than leaving it implied.
- **Personal data for an individual:** `legal_name`, the address and `invoice_email`
  are personal data and follow §10's erasure path; a company's details largely are
  not. The row's *existence*, its `requested_at` and its country survive either way,
  because the financial record needs them — and where tax law requires the invoice
  data itself to be kept, the lawyer's retention requirement overrides an erasure
  request for those fields. That tension is real, it is named in §10.3, and this
  design does not resolve it by guessing.

### 9.5 The API contract: four new fields, and five values the client may not own

**New request fields on `POST /api/checkout`** (all validation in
`CheckoutController::validationRules()`, the one place rules live today):

| Field | Rule | Why it is shaped this way |
|---|---|---|
| `terms_accepted` | `required|accepted` | §12.1's mandatory floor; `accepted` also rejects `"false"`, `0` and `""` without the controller inventing a boolean cast |
| `terms_version` | `required|string|max:32` | The version the page displayed (§9.1); 32 characters is ample for a date or a short string, and the bound is enforced rather than trusted |
| `confirmation_requested` | `sometimes|boolean` | Absent means "not asked" and stores NULL (§9.2) — not a defaulted `true` |
| `invoice` | `sometimes|array` | The whole block, only when the customer asked: `invoice.recipient_type` (`required_with:invoice|in:company,individual`), `invoice.legal_name` (`required_with:invoice|string|max:255`), `invoice.tax_id` (`sometimes|string|max:64`), `invoice.company_registration_number` (`sometimes|string|max:64`), `invoice.representative` (`sometimes|string|max:255`), `invoice.country` (`required_with:invoice|string|size:2`), `invoice.city` (`required_with:invoice|string|max:128`), `invoice.postal_code` (`required_with:invoice|string|max:32`), `invoice.address_line_1` (`required_with:invoice|string|max:255`), `invoice.address_line_2` (`sometimes|string|max:255`), `invoice.email` (`sometimes|email|max:255`) |

**The five values the request body never owns** — the "no trust in
client-supplied `source_type`" requirement, written as a table so no future caller
has to infer it:

| Value | Who owns it | What happens if a client sends it |
|---|---|---|
| `source_type`, and every `first_*`/`last_*` column | `OrderAttributionResolver`, from the request's own URL, `Referer` and the session chain | Ignored — the key is not in the validation rules and is never read |
| `visitor_id` | The consent-gated cookie, read server-side (§6) | Ignored — a body value can never set or override it |
| `ip` | `$request->ip()` under §7.4's proxy configuration | Ignored |
| `captured_at` / `terms_accepted_at` / `requested_at` | Checkout's own clock argument (`$placedAt`) | Ignored — no API field exists for any of them |
| `consent_mode` / `analytics_consent` / `consent_source` | The installation's setting and the visitor's own cookie/banner state (§8) | Ignored; `consent_source = api` is reserved for an authenticated API caller that states consent through its own dedicated flag — a decision §15 Q1 keeps open |

**Unknown keys are ignored, and that is deliberate rather than sloppy.**
`$request->validate()` returns only the keys its rules name, which is today's
behaviour and stays; the reason it must stay is
`checkout-domain-design.md` §12.2's own extension point — a merchant's custom field,
added through `checkout.form.fields` and read through `checkout.request.data`, must
not make checkout 422 because EasyCo's rules did not name it. **Rejected
alternative:** a strict "reject unknown keys" rule, which would break that hook on
purpose (`ai-collaboration-protocol.md`'s boutique posture is a smaller surface, not
a brittle one).

### 9.6 Where the three writes happen, and the replay trap they must not fall into

All three writes belong to the **placement transaction**
(`checkout-domain-design.md` §8.3), immediately after the `Order` row is inserted and
**before** the cart claim:

1. One `order_attributions` insert (§2.1) — always, whatever the consent answer, so
   every order placed after this ships can explain its own emptiness.
2. The three `orders` columns are set **on the row that is being inserted** — not by
   a second `UPDATE` — because they are create-time facts and the order is written
   once.
3. One `order_invoice_details` insert, only when the customer asked.

**The trap, named because a unique index turns it from a nuisance into a bug:** the
claim's zero-affected-rows path rolls back everything and returns the *pre-existing*
order (`cart-domain-design.md` §14.2's double-submit protection). If the context rows
were written **after** that rollback — or in a separate transaction, or in an
`order.placed` listener — a double-clicked "Pay" button would try to insert a second
row for the same `order_id` and hit `attr_order_id_unique` / `oid_order_id_unique`,
turning an idempotent replay into a 500. Writing them inside the transaction, before
the claim, means the rollback discards them and the replay path never inserts one.
**Two tests exist for exactly this:** a double submit produces one order with one
attribution row and one invoice row and no duplicate-key error, and the
pre-existing order keeps the context it was placed with.

**And they are not hook work.** They run inside the transaction; hooks fire after
commit (§4's boundary; `order-lifecycle-design.md` §12). A listener that wants the
context reads it by `order_id` afterwards.

### 9.7 The four refusal reasons, and why they are translatable

O4(a) requires a *translatable* refusal, and today checkout's refusals are English
exception messages inside a `{message, reason}` body (`promotion_no_longer_valid`,
`insufficient_stock`, `price_not_available`, `unknown_payment_method`). This design
keeps that shape and adds machine reasons, with the sentences themselves in two new
files, `lang/en/checkout.php` and `lang/bg/checkout.php`:

| Reason | HTTP | Refused when |
|---|---|---|
| `terms_not_accepted` | 422 | The field is missing or not `true` |
| `terms_version_changed` | 422 | The version the page displayed is not the current `checkout.terms_version` — the terms were edited mid-session |
| `invoice_not_supported` | 422 | An invoice was requested while `invoice.enabled` is off |
| `invoice_details_invalid` | 422 | A per-country rule (§9.3) failed, or `tax_id` arrived for an individual; the response names the field and quotes the configured label |

The API keeps returning its English `message` alongside the `reason` (today's
convention, and what the sandbox renders), while the storefront renders the
localized sentence by reason code — so no customer ever reads a raw
`terms_version_changed`.

---

## 10. Erasure: anonymise the context, never the financial record

**Decision (D8): an erasure request is answered by anonymising the context
in place, field by field, with no row ever deleted — and the command refuses to run
without saying exactly whose context it is about.**

### 10.1 Why it is anonymisation and not deletion

`restrictOnDelete()` on `orders.client_id`/`transaction_id` and on
`order_attributions.order_id` is the schema saying plainly that an order is not
deletable (CLAUDE.md rule 4; `order-lifecycle-design.md` §11 item 1). An erasure
request therefore has exactly one lawful shape: **the identifiers go, the record
stays.** Which is also what a merchant needs — the sale, its amount and its status
are the shop's books, and the person is not in them once the context row has been
emptied.

### 10.2 The command, and the service behind it

- **`App\Services\OrderContextAnonymiser`** holds the work, so a future panel action
  ("erase this customer's context") reuses it instead of re-implementing it — the
  same split `PruneActivityLog` (command) and `ActivityLogger` (writer) already have.
- **`privacy:erase-order-context`**, a plain `Command`, takes **exactly one** of
  `--order=<id>`, `--visitor=<uuid>`, `--account=<id>` or `--email=<address>`, plus
  `--dry-run`. Identity flags resolve through the same precedence §6.1's history read
  uses, and anonymise **that person's whole context** (a real request is "erase me",
  not "erase order 412"), inside one transaction. **With no argument it refuses**:
  there is deliberately no "erase everything" mode, because a mistyped flag that
  wiped every order's context would be unrecoverable — fail-loud over convenient.
- **`--dry-run` prints the affected order ids and the fields it would clear**, which
  is what a merchant actually needs before touching live data.

### 10.3 Field by field — what goes, what stays, and the judgement behind each

| Field | On erasure | Why |
|---|---|---|
| `order_attributions.ip` | **NULL**, `ip_anonymised_at` stamped | The one field whose whole value is identity; §7's anonymisation is a weaker version of the same act |
| `order_attributions.user_agent` | **NULL** | A device fingerprint in all but name |
| `order_attributions.visitor_id` | **NULL** | The pseudonymous identifier itself |
| `order_attributions.account_id` | **NULL** | A link to a person; §2.1 explains why the snapshot exists at all, and an erasure is the one case where it must go |
| `referrer_url`, `landing_page_url`, `first_landing_page_url` | **Rewritten to the path only** — the query string is dropped, the host and path kept | The query string is where personal data hides (a search term, a click identifier, a session token); the path is what the merchant's "which page did they enter on" question needs, and it is not identifying on its own |
| `click_id_value` (type kept) | **NULL** | A click identifier is an ad-platform identity — personal data by the platforms' own position — while the *type* is what a report would ever group by |
| `referrer_host`, `utm_*`, `ai_platform`, `device_type` | **Kept** | Campaign parameters, a referring host and a device class are not personal data on their own; they are the entire point of the record, and deleting them would destroy the second half of what a sales report is |
| `consent_mode`, `analytics_consent`, `consent_source`, `capture_status` | **Kept** | The record of the privacy decision itself, PII-free — and keeping it is what lets the emptied row still explain itself |
| `captured_at`, `created_at`, `updated_at` | **Kept** | Dates are not identifying, and the record's age is what retention rules are applied to |
| `orders.terms_accepted_at`, `orders.terms_version`, `orders.confirmation_requested` | **Never touched** | PII-free proof that the sale was lawful; erasing it would destroy the merchant's own defence (§2's "two kinds of consent, two homes") |
| `order_invoice_details` | **Untouched by default** | See §10.4 — whether these may be erased depends on a legal retention obligation nobody has stated yet |
| `orders.email`, `recipient_name`, `phone`, the address snapshot | **Never touched by this design** | See §10.5 — the boundary is drawn deliberately |

### 10.4 Invoice details: the flag exists, and it ships off for a reason

`privacy:erase-order-context` takes **`--include-invoice-details`, which is off by
default.** Whether an individual's billing details may be erased depends on a tax
retention obligation nobody has stated yet (EU practice is commonly five to ten
years for invoice data), and **anonymising a record the law requires the merchant to
keep would be a larger mistake than keeping it**: the merchant would lose the
document's data without having answered whether he was allowed to. So the machinery
ships, the default is conservative, and turning it on is a decision for the owner
and his lawyer (§15 Q11). When a future Invoicing domain exists, that domain owns
its own document retention and this flag's scope shrinks to nothing.

### 10.5 The boundary: the order's own contact fields are not this design's to erase

`orders.email`, `recipient_name`, `phone` and the delivery snapshot are **not**
anonymised by this command, and that is a boundary rather than an oversight. They
are the order's own delivery and financial record: a courier dispute, a tax audit, a
warranty claim and a re-delivery all need them, and deciding *when* they may be
removed is a retention decision about the **order**, not about its context. Making
that decision inside a document about attribution would silently widen this design's
blast radius to the financial record itself — the one thing §1 item 4 and §10.1
exist to avoid. §15 Q12 records it as a real, named gap with a recommendation
(do not build it here; when it is built it needs the owner's retention period and a
command shaped like this one, over the order rather than over its context).

### 10.6 What the command logs, and what it never does

- **Logging: `ActivityLogger::write()`** with a new action constant
  (`order_context.anonymised`) carrying the order id and **the list of fields that
  were cleared — never a value.** `activity_log`'s own retention
  (`admin.activity_log_retention_months`, default 12) therefore applies to that
  row, which is acceptable because the durable, PII-free proof that the
  anonymisation happened lives on the record itself (`ip_anonymised_at` plus
  `updated_at`); the log is the readable detail. **Rejected alternative:** a
  dedicated erasure-log table — a second journal beside the one this project already
  has, which `order-lifecycle-design.md` §6.4's "one journal, one job" posture
  refuses.
- **Idempotent by predicate, not by a flag.** The selection is expressed on the very
  columns it would clear (`visitor_id IS NOT NULL OR user_agent IS NOT NULL OR ip IS
  NOT NULL OR click_id_value IS NOT NULL OR account_id IS NOT NULL OR the URLs still
  carry a query string`), so a second run finds nothing, reports "nothing to
  anonymise" and writes no log row. There is deliberately **no `anonymised` boolean**
  anywhere: a flag would need keeping in sync with the fields it describes, which is
  the second-source-of-truth mistake `order-lifecycle-design.md` §11 item 11 names.
- **It never:** deletes a row, inserts a row, changes a status, a line or an amount,
  touches `payments`/`sale_lines`/`clients`/`carts`/`orders`, or rebuilds the
  attribution row. Its entire write set is one `UPDATE` per affected
  `order_attributions` row (plus `order_invoice_details` only when §10.4's flag is
  passed) inside one transaction.

---

## 11. Admin: the Origin section, one column, one filter, and the timeline's first row

### 11.1 The Origin section on the order's own View page — read-only, and it explains its own gaps

**Decision (D9): one new Section on `ViewOrder`, read-only, added to
`OrderAdminReader::forOrder()`'s existing single read** (D5) — no new reader class,
no second query per field, and no write anywhere:

| Line | Source | Rendered when empty as |
|---|---|---|
| Source | `source_type`, through the enum's label | '—' plus the reason line below |
| AI platform | `ai_platform`, with §5.1's one-line "AI visits are a lower bound" note beside it | (line omitted) |
| Campaign | `last_utm_source` / `last_utm_medium` / `last_utm_campaign` / `last_utm_content` | '—' |
| First touch | `first_source_type` + `first_utm_source`/`medium`/`campaign` + `first_landing_page_url` + `first_touch_at` — shown **only when it differs from the last touch**, which is the only case where it adds information | (line omitted) |
| Referrer / landing page | `referrer_host`, `landing_page_url` | '—' |
| Device and visits | `device_type`, `session_pages`, `session_count` (`orders.device_type_options`, the plural labels) | '—' |
| Identifiers | `account_id` (labelled as the account the order was placed with) and `visitor_id` | '—' |
| Consent | `consent_mode` + `analytics_consent` + `consent_source`, as one sentence (`Strict — analytics declined — nothing stored on the visitor's device`) | (always present — it is NOT NULL) |
| Why this is empty | `capture_status`, rendered as the reason line: *"Not stored — strict mode, analytics declined"*, *"Not captured — the visit carried no referrer and no campaign"*, or — when there is **no row at all** — **"Not recorded — this order was placed before context capture."** | — |
| Client address | the §7.1-gated line: the full IP for `ORDER_MANAGE`, "anonymised on <date>" for anyone with `ORDER_VIEW`, "not recorded" when NULL and never anonymised | '—' |
| Customer history | §6.1's derived block: orders, refunded, cancelled, total spent, first and last order | (omitted when the identity resolves to this order alone) |

**The reason line is the section's most important line, not a detail.** It is what
turns "the field is empty" into an answer a merchant can act on, and it is why
`capture_status` is NOT NULL on every row (§8.5). A section that renders empty
fields with no explanation is how a merchant concludes the feature is broken.

**No action, no form, no edit** — D1's read-only posture holds for this pass without
amendment, unlike `order-lifecycle-design.md` §8, which had to amend it because it
shipped writers. The Origin and invoice sections are reads, and the only writes in
this whole design happen at checkout or in the two console commands.

### 11.2 The Orders list: one column, one filter, and the same query count for 5 and 25 rows

- **A `source` column** (the enum's label, '—' when there is no row) fed by a
  **`LEFT JOIN` to `order_attributions` on Filament's table query** — not a
  correlated subquery per row, not a lazy-loaded model relation (the Orders list is
  a read built from real columns, and `applyListAggregates()`'s correlated-subquery
  approach was already removed from this table for exactly this reason, §0 item 10).
- **A `source` filter** — a `SelectFilter` whose options are the enum's ten labels
  **plus one extra, distinct option, `not_recorded`** (translated), because "which
  orders have no origin?" is the filter a merchant will actually use while the
  feature is new. Selecting a value applies one condition to the same joined query;
  selecting `not_recorded` is `whereNull` on the joined column. **No second query
  either way**, and the select's options come from the enum rather than from a
  `SELECT DISTINCT` over the table.
- **The query-count requirement is asserted, not assumed:** one real test asserts the
  page's query count is identical for a page of 5 rows and a page of 25 (the same
  discipline `admin-panel-design.md` §14's own real query-count test established for
  this list), and that neither counts a query per row.
- **Fail-soft, and never a fabricated value:** an order with no attribution row
  renders '—' in the column and is matched by `not_recorded` in the filter. **It is
  never coerced to `direct`.** An order placed before this feature existed did not
  come "directly" from anywhere in particular — nobody looked — and rendering
  `direct` would be the one lie §2.1.1's definitions exist to prevent.
- **Nothing else about the list changes:** still no row actions, still no per-row
  detail read, still a read (§11.3's own reason line aside, the list gains no new
  query shape beyond the join).

### 11.3 The timeline's first row: a `placed` event, written by checkout

**Decision: yes — `OrderEventType` gains `placed`, and checkout writes it inside its
own transaction.** Recommended, and the reasons are concrete:

- **An order's timeline is empty until a merchant touches it today** (§0 item 7):
  the first row a merchant ever sees is somebody else's action. `placed` gives every
  order the one event every order has, so "what happened here" starts at the
  beginning instead of at the first intervention.
- **It goes where the order does.** `OrderEventRecorder::record($orderId,
  OrderEventType::PLACED, null, null, null, null, $placedAt)` is called immediately
  after the `Order` row is inserted, inside the same transaction — so the event
  cannot be missing while the order exists, and a rolled-back checkout leaves no
  orphan event (`order-lifecycle-design.md` §6.2's own rule). This is also the
  recorder's **first production caller**: its docblock says "nothing in production
  calls this class yet", and this stage is what makes that sentence false (the
  docblock is corrected in the same commit, per §14's landing rule).
- **Both statuses NULL, and the alternative is refused rather than overlooked.**
  `null → placed` would be more literally true, but it would change a shipped,
  tested guard: `OrderEventRecorder` refuses a half-set pair today, and exempting
  `PLACED` from that rule is a change to the lifecycle's own contract. **Nothing is
  lost by following it:** the next transition's `status_changed` row carries
  `from_status = placed`, so the chain is unbroken, and the reader already renders
  three other event types with both statuses empty.
- **What it deliberately does not change:** the `order.placed` **hook** stays exactly
  where it is — fired after commit, at `CheckoutOrchestrator.php:197`, with the same
  payload (§12.3). An event and a hook are different things and this design adds no
  second fire.
- **Orders placed before the stage ships keep an empty timeline.** No backfill — the
  same rule as the attribution row, for the same reason (§11.4).

### 11.4 The invoice section, and the summary of who sees what

- **An `Invoice` section on the same View page**, read-only, behind `ORDER_VIEW`
  like every other section: whether a request exists (`requested_at`), the recipient
  type, `legal_name`, `tax_id` with the **configured label** for that country (so
  "ЕИК/Булстат" is what the merchant reads, not `tax_id`), the registration number,
  the representative, the billing address, `invoice_email` and the rule version that
  accepted it. No tax-id value is *validated* here — validation happened at
  checkout, under the rules of that day, and the section states which version that
  was (§2.2).
- **No invoice column and no invoice filter in the Orders list in V1.** A merchant
  who wants "which orders are waiting for a document" is asking for the future
  Invoicing domain's own queue, not a seventh column on a read-only orders list —
  and adding one would put a second, weaker version of that screen here. §15 Q13
  records it as the owner's call.
- **The permission summary, stated once:** every read in this design is behind
  `ORDER_VIEW` (which Administrator and Manager hold, Product Entry does not);
  **the full IP is the single exception**, behind `ORDER_MANAGE` (§7.1); and **no
  new permission and no new role is proposed anywhere** — `RoleResource`'s existing
  `'Orders'` group already holds exactly the four values this design uses.

### 11.5 Backfill: there is none, and there never will be

Every order placed before these stages ships has **no** context row, no terms proof
and no `placed` event. The design's answer is uniform and deliberate: **represent
the absence, explain it, and never fabricate it.** No backfill command, no
"best-effort `direct`", no inferred terms acceptance, no synthetic timeline rows —
because every one of those would write a claim about a customer's session that
nobody observed. The merchant sees "Not recorded — placed before context capture"
and knows exactly what he is looking at, which is strictly more useful than a
plausible-looking guess.

---

## 12. What the record must answer cheaply — and the indexes that justifies

**Decision (D10): four questions must be answerable by query, and only their indexes
are added.** No report, dashboard or export is built here (O5's "make later
marketing easy" is served by the *shape* being right, not by a screen nobody has
asked for yet):

| # | The question (O5) | The query | The index that serves it |
|---|---|---|---|
| 1 | Orders and revenue **by source and campaign** | `orders` join `order_attributions`, grouped by `source_type` + `last_utm_campaign` | `attr_source_type_index`, plus `attr_order_id_unique` for the join |
| 2 | **AI-assistant share**, and by platform | the same query filtered on `source_type = 'ai_assistant'`, grouped by `ai_platform` | the same index — it narrows the set first |
| 3 | **New vs returning customers** | §6.1's derived read (per order, on the View page) and, for a report, a `GROUP BY` over `orders` by identity | `ord_account_id_foreign` (existing) for the account path; the **new** `ord_email_index` for the guest path |
| 4 | **Refunds by source** | the same join as (1), narrowed by `orders.status` / `payment_refunds` | `attr_source_type_index` again; no new index |
| 5 | The retention sweep (§7.3) | `captured_at < cutoff` over un-anonymised rows | `attr_captured_at_index` |

**Two indexes are new beyond the two constraints of §2.1** — and each is justified by
a query that really runs, which is the only reason this document adds any:

- **`attr_visitor_id_index`** — the identity path of §6.1's history read, which runs
  on every order View page a merchant opens.
- **`ord_email_index` on `orders.email`** — the same read's guest path. **The honest
  counter-argument is recorded rather than hidden:** a single merchant's `orders`
  table is small, and a full scan would survive a long time. It is added because the
  query runs on every order page a merchant opens, and because the column's own
  collation (`utf8mb4_unicode_ci`) means plain equality is case-insensitive — so the
  index is usable exactly as written, with no `LOWER()` (§6.1).

**Indexes deliberately refused:** on `ai_platform` (query 2 already narrows by
`source_type`), on the consent columns (nothing filters by them), on the attribution
row's `account_id` (query 3's account path enters through `orders`), and anything
beyond `oid_order_id_unique` on `order_invoice_details` (nothing queries it by
anything else — it is read one row at a time by order id).

### 12.1 The `order.placed` hook payload is deliberately unchanged

`Hook::fire('order.placed', $order)` keeps its single-argument payload. **Rejected
alternative:** passing the attribution object (or a nullable DTO) into the payload —
it would make every listener null-check a value the hook never promised, because a
context row may legitimately be absent (§11.5), and it would make checkout build a
DTO for a listener that may not exist. A listener that wants the context reads it by
`$order->id()` afterwards, which is one query it can skip when it does not care. The
row for this hook in `extensibility-design-and-hooks.md` §3 is already scheduled by
`order-lifecycle-design.md` §13 — **this document does not schedule it a second time
or claim it** (§14).

### 12.2 What the sandbox must be able to do, and what it must not grow

- **The capture middleware is registered on the sandbox's `GET` routes** (§4.2), so
  `/_sandbox?utm_source=facebook&utm_campaign=spring` is a complete manual test of
  the whole path: the chain is recorded, the order carries it, and the admin's Origin
  section shows `utm` / `facebook` / `spring`.
- **No client-side work is needed, and none may be added.** The `visitor_id` cookie
  is set by the middleware's own response — not by page scripts — so the sandbox
  gains no JavaScript and this design's "no tracker" boundary (§1 item 2) holds on
  the one surface that exists today.
- **The checkout API's response shape gains nothing.** The context is the merchant's
  own read, not the customer's, so the sandbox cannot display it — and should not:
  the manual checklist's new scenario sends the tester to the **admin panel** (and to
  the raw row via `php artisan tinker` for the columns). Keeping the response
  unchanged also keeps the confirmation page's contract untouched
  (`sandbox-manual-test-checklist.md` scenario 11's own "the confirmation page never
  calls an order endpoint" posture).
- **The checklist gains three checks:** (a) a UTM'd sandbox visit produces a `utm`
  order with the campaign visible in the panel; (b) an existing, pre-feature order
  renders "Not recorded — placed before context capture" and is matched by the
  `not_recorded` filter; (c) with `privacy.consent_mode` set to `strict`, an order
  placed without a visitor id shows the reason line rather than an empty field.

---

## 13. Staged build order and the review gate

**Every stage is one commit, reviewed on its own** — the shape
`order-lifecycle-design.md` §10 established, and for the same reason: each stage
leaves the project green by itself, and several are *retroactive* (a docblock or a
document sentence that becomes false), which is exactly what must be read next to
the change that forced it.

**Stage 1 — the enums and their labels.** The six enums of §2.1.1, the six label
groups in `lang/en/orders.php` **and** `lang/bg/orders.php`, and the parity test that
walks every case against both languages — the shape `OrderEventTypeLabelsTest`
already established. Nothing reads them yet, which is the point: the vocabulary
lands before the schema that stores it.

**Stage 2 — the schema, and nothing that writes it.** The two `Schema::create`
migrations of §2.1/§2.2, the `add_..._to_orders_table` migration of §2.3, and
`OrderAttributionModel` / `OrderInvoiceDetailsModel`. Tests: the two FKs' restrict
behaviour proven by a real delete attempt (not by reading the migration), both
`unique` constraints proven by a real duplicate insert, the types and lengths
confirmed with a real `SHOW CREATE TABLE`, and **every identifier's length asserted
≤ 64** (CLAUDE.md rule 5, checked rather than trusted). No writer exists yet, so the
tables are inert.

**Stage 3 — capture and resolution.** `AttributionTouch`, `OrderAttributionResolver`,
`CaptureAttribution`, the `visitor_id` cookie and the `privacy.*` /
`attribution.*` settings keys, and the middleware's registration on the sandbox's
`GET` routes. Tests: §3.2's rules walked as a truth table in both directions (every
listed input maps to its value, and nothing else does), `direct` never overriding a
known source (P8's own test), the 30-minute boundary asserted at 29 and 31 minutes,
the three consent modes' cookie behaviour, the AI list resolving to `ai_assistant` +
`ai_platform`, a client-supplied `source_type` ignored (§9.5), and **the two proxy
tests of §7.4** (a forged `X-Forwarded-For` never recorded with no proxy configured;
the real client address recorded through a configured chain).

**Stage 4 — checkout writes and refusals.** The four request fields with their rules
(§9.5), the three columns set on the inserted order row, the attribution insert and
the invoice insert (§9.6), the four refusal reasons and `lang/*/checkout.php`. Tests:
each refusal, each bound, unknown keys ignored, **every order gets its attribution
row whatever the consent answer**, the double-submit/replay case (one order, one
attribution row, one invoice row, no duplicate-key error), and the existing checkout
suite unchanged in behaviour.

**Stage 5 — the timeline's first row.** `OrderEventType::PLACED`, its two language
labels, the checkout call (§11.3), and the correction of `OrderEventRecorder`'s
docblock (the stage that makes "nothing in production calls this class yet" false).
Tests: exactly one `placed` event per order, its `occurred_at` equal to the order's
`placed_at`, it being the timeline's oldest row, and a rolled-back placement leaving
no event behind.

**Stage 6 — the admin surface.** The Origin section (§11.1), the `source` column and
filter (§11.2), the customer-history read (§6.1), the invoice section (§11.4), and
the gated IP line (§7.1). Tests: the query count identical for 5 and 25 rows, the
`not_recorded` filter matching exactly the orders with no row, '—' rendering with the
correct reason line per `capture_status`, and the IP line present for `ORDER_MANAGE`
and **absent and unqueried** without it.

**Stage 7 — retention and erasure.** `privacy:anonymise-order-ips` + its schedule
(§7.3), `OrderContextAnonymiser` + `privacy:erase-order-context` (§10.2), and the new
`ActivityLogger` action. Tests: the retention boundary at the day before and the day
after, an idempotent second run changing nothing and logging nothing, `--dry-run`
writing nothing, the no-argument refusal, **no log row ever containing a value**, and
`--include-invoice-details` defaulting to off.

**Stage 8 — the documentation pass, deliberately last.** Every edit §14 schedules,
every corrected docblock, and the sandbox checklist's three new checks (§12.2). Docs
only, one commit — and the edits §14 assigns to earlier stages do **not** wait for it:
they ship with the code that makes each sentence true, per the landing rule.

**The review gate, stated once.** A stage is done when its own tests pass *and* its
diff has been read by someone other than its author — on the understanding that an
implementation which contradicts this document is reported and argued, never quietly
absorbed by editing the document afterwards to match. Anything the implementation
genuinely forces a decision on belongs in §15, with the reason.

### 13.1 Permanent commitments and standing constraints

Not every decision here is a preference a later pass may tune. Each states what it is
and what breaks if it is quietly changed, and each follows from an argument made
earlier in this document rather than from taste:

1. **A full IP never outlives `privacy.ip_full_retention_days`** (§7.2), and
   `ip_anonymised_at` is the only record that it was ever there.
2. **No customer counter is ever stored** (§6.1) — no `orders_count`, no
   `total_spent`, no `last_order_at`, no "returning customer" flag. Each would be a
   second source of truth for a fact one grouped query answers correctly
   (`order-lifecycle-design.md` §11 item 11).
3. **No browser request writes a row** (§4.2): browsing touches the session, and the
   only inserts happen inside the placement transaction.
4. **`source_type` is never client-supplied** (§2.1.1, §9.5) — ignored, not
   validated; the resolver stays the only decider.
5. **Absence is never fabricated** (§11.5): no backfill, no inferred `direct`, no
   synthetic history — for orders, events or consent.
6. **No invoice number, tax line or document is ever created here** (§9.3). Both of
   O6's futures stay open precisely because this document refuses to be either.
7. **Every window is a setting with a conservative default** (§7.2, §8.1, §6, §2.1):
   IP retention, cookie lifetime, session boundary, user-agent truncation, consent
   mode. No duration is hardcoded — the failure CLAUDE.md rule 8 records for a
   hardcoded default, applied to time instead of currency.
8. **The context data is never used for marketing** (§7.1), and no marketing-consent
   field exists here (§1 item 3, §9.2).
9. **The context rows are written inside the placement transaction, never after
   commit** (§9.6) — the replay path depends on it.
10. **The Origin section stays a read**, the four existing permissions are used as
    they are (§11.4), and the one place a narrower permission is wanted is §15 Q6
    rather than an invention.
11. **An erasure never deletes a row and never touches the financial record**
    (§10.1, §10.6).
12. **The terms proof is PII-free and is never erased** (§10.3): it is the merchant's
    own record that the sale was lawful, and a feature that "tidied" it away would be
    destroying evidence.

---

## 14. The documents this changes, and when each edit lands

**This pass adds exactly one file and edits nothing else** — the brief's own edit
set, and the reason there is no "already applied" row like
`order-lifecycle-design.md` §13's. Everything below is **scheduled**, written out in
full so the owner approves the actual sentence rather than a description of it. The
landing rule is the lifecycle's own: **an edit ships in the stage that makes it true,
and nothing waits for stage 8 that could be true earlier.**

| Stage | Cross-reference edits that land there |
|---|---|
| 2 | `channel-native-commerce-vision.md` §10 — one sentence, because the attribution schema exists |
| 3 | `storefront-frontend-design.md` §4/§7 (the capture contract and the consent cookie), `production-requirements.md` (the trusted-proxy bullet), `cart-domain-design.md` §7 (the visitor id is not a cart identity), `site-settings-design.md` §1 (the attribution/privacy key group) |
| 4 | `checkout-domain-design.md` §8.3 / §10 / §12.1 / §12.2 / §12.3, `site-settings-design.md` §1 (the checkout/invoice key group), `sandbox-manual-test-checklist.md` (the three new checks), `account-domain-design.md` §11 (the two named boundaries) |
| 5 | `order-lifecycle-design.md` §6.1's `type` row and `App\Enums\OrderEventType`'s own docblock — the two sentences that "six facts" makes false |
| 6 | `admin-panel-design.md` §14 (D1's clarifying sentence, the list's new column/filter), `staff-access-domain-design.md` §3's Orders block (the IP gate) |
| 8 | Everything else that is documentation-only, and the sandbox checklist's "Before you start" settings line |

### `checkout-domain-design.md`

- **§8.3 (the step sequence, lines 201-225) — one new step, after the `Order` insert
  and before the claim:** *"Insert the order's context rows (`order-context-design.md`
  §9.6): the `order_attributions` row, the `terms_accepted_at`/`terms_version`/
  `confirmation_requested` columns on the `Order` row being inserted, and the
  `order_invoice_details` row when the customer asked for an invoice. They are part
  of this transaction and come before the claim, so a replayed submit leaves exactly
  one set."*
- **§10's deferral list (line 309, "Guest cross-order deduplication…") — one sentence
  appended:** *"Partially answered by `order-context-design.md` §6.1: the same
  identity resolution (account → email → visitor id) now backs the admin's own
  customer-history read. It stays admin-only — the storefront lookup this bullet
  defers is still deferred."*
- **§12.1 (lines 345-347) — one sentence appended to "Both fields live on `Order`
  (§3)…":** *"`termsAccepted`'s proof — `terms_accepted_at` and `terms_version` —
  ships with it; `requiresPhoneCallBeforeShipping` is still unbuilt. See
  `order-context-design.md` §9.1-§9.2."*
- **§12.2's first hook row (line 358) — the default set named there gains two
  members:** *"…plus, as of `order-context-design.md` §9, the invoice request block
  (`invoice.enabled`) and the order-confirmation preference."*
- **§12.3 (lines 375-380) — the Site Settings list gains a pointer:** *"…
  `order-context-design.md` §9 adds `checkout.terms_version`, `checkout.terms_url`
  and `invoice.enabled` to this same mechanism."*

### `admin-panel-design.md`

- **§14's D1 (lines 1005-1013) — one clarifying sentence, because the section's own
  wording stays true:** *"The Origin and Invoice sections added by
  `order-context-design.md` §11 are reads — D1's read-only posture is unamended, and
  no permission is added."*
- **§14's list description (the five columns / one filter, lines 1014-1051) — one new
  decision line:** *"**D7 — the list gains one column and one filter**
  (`order-context-design.md` §11.2): a `source` column and a `source` filter, both fed
  by a single `LEFT JOIN` to `order_attributions`, with the query count identical for
  a page of 5 and a page of 25 rows."*

### `staff-access-domain-design.md`

- **§3's Orders block (lines 74-78) — one sentence, and no new value:** *"As of
  `order-context-design.md` §7.1, `ORDER_MANAGE` also gates the full client IP
  address on an order's own View page, while `ORDER_VIEW` sees only whether the
  address has been anonymised."*

### `site-settings-design.md`

- **§1's confirmed-consumer list — two additions, landing in two stages**, because a
  sentence that is half-true is worse than no sentence:
  - **Stage 3:** *"Attribution and privacy keys (`order-context-design.md` §6-§8):
    `attribution.ai_platforms`, `attribution.session_boundary_minutes`,
    `privacy.consent_mode`, `privacy.visitor_cookie_lifetime_days`,
    `privacy.ip_full_retention_days`, `privacy.user_agent_max_length`."*
  - **Stage 4:** *"Checkout's legal and invoice keys (`order-context-design.md` §9):
    `checkout.terms_version`, `checkout.terms_url`, `invoice.enabled`,
    `invoice.country_rules`, `invoice.country_rules_version`."*

### `storefront-frontend-design.md`

- **§4/§7 — one new subsection's worth of sentences, landing in stage 3**, because the
  capture middleware exists then: *"The attribution contract
  (`order-context-design.md` §4) is the storefront's only obligation here: the capture
  middleware runs on the customer-facing `GET` routes and maintains a visit chain in
  the session. The `visitor_id` cookie (`order-context-design.md` §6) is the
  storefront's only non-essential cookie and is consent-gated by the installation's
  `privacy.consent_mode`; the banner that asks for it is this document's own future
  work, not the attribution design's. On a Varnish cache hit the middleware never
  runs, which is why the header's own hydration call matters
  (`order-context-design.md` §4.3)."*

### `production-requirements.md`

- **A new "Required" bullet, landing in stage 3** — the first stage that reads the
  client address: *"**A configured trusted-proxy list whenever the app runs behind a
  reverse proxy or Varnish.** Without it, every order records the proxy's own address
  instead of the client's, because `order-context-design.md` §7.4 records the client
  IP on each order. Configure Laravel's `TrustProxies` with the real proxy
  addresses/CIDRs and the forwarded headers in use — never a wildcard, and never
  trusting `X-Forwarded-For` blindly."*

### `cart-domain-design.md`

- **§7 — one sentence, landing in stage 3:** *"A visitor id
  (`order-context-design.md` §6) is **not** a cart identity and never becomes one:
  this section's exactly-one-of rule, and §14.1/§14.2's claim and replay, are
  untouched by it."*

### `account-domain-design.md`

- **§11's deferral list — one sentence, landing in stage 4:** *"A saved billing
  profile (company details reused at checkout) and any marketing/newsletter consent
  both remain deferred and unbuilt; `order-context-design.md` §9.4 and §1 item 3
  define only their boundaries and the link key (email and visitor id)."*

### `channel-native-commerce-vision.md`

- **§10, after the `Order #1234` sketch — one sentence, landing in stage 2**, the
  stage that makes the schema real: *"The **attribution** half of this sketch is
  schema as of `order-context-design.md` §2-§5 (`source_type`, `ai_platform`, the
  `last_utm_*` columns, the landing page); the **channel** and **agent** halves remain
  this document's own, and `source_type = agent` is a reserved value waiting for
  them."*

### `order-lifecycle-design.md`

- **§6.1's `type` row — the list becomes seven values, landing in stage 5:** the row
  gains *"`placed` — the order was placed, written by checkout inside its own
  transaction; both statuses NULL, per the recorder's own rule
  (`order-context-design.md` §11.3)"*, and the row's "six" wording becomes "seven".
- **`App\Enums\OrderEventType`'s class docblock (not a document, and the same edit
  principle)** — "The six facts an order's own history records" becomes seven, with
  `PLACED`'s meaning written beside the others. This is the correction §11.3 refers
  to, and it belongs to the stage that makes it true.

### `sandbox-manual-test-checklist.md`

- **The three new checks of §12.2, landing in stage 4**, plus one line in "Before you
  start" (stage 8, documentation-only): *"`privacy.consent_mode` is whichever mode you
  want to exercise; `checkout.terms_version` must be non-empty or checkout refuses
  every order."*

### Not amended, and why

- **`README.md`** — not amended: this design adds no package and no package HTTP
  surface, and both new tables belong to the root application. The convention
  `ai-collaboration-protocol.md` states ("update it when a domain's HTTP or
  persistence layer reaches a committed milestone") therefore does not apply.
- **`extensibility-design-and-hooks.md`** — not amended by this document: it adds no
  hook, and the one row it needs for `order.placed` is already scheduled by
  `order-lifecycle-design.md` §13 (§12.1).

**Every other document in this folder was checked for a sentence this design makes
false**, by searching the folder for the vocabulary it introduces
(`attribution`, `consent`, `visitor`, `utm`, `IP`, `invoice`, `terms`) rather than
from memory. Nothing outside the files above matches. Four near misses, named so
nobody "fixes" them later:

- **`address-domain-design.md`** — the invoice's billing address is a snapshot on the
  order, exactly like the delivery one, and is never an `Address` row. That document
  claims nothing to the contrary (it only says order-time snapshotting is a future
  Order/Checkout concern), so it stays exactly as it is.
- **`checkout-orchestration-performance-note.md`** — the placement transaction gains
  three inserts and still performs no network call, which is that note's actual
  principle. Nothing in it becomes false.
- **`bot-traffic-and-rate-limiting-note.md`** — it is about unauthenticated scanning
  traffic reaching dynamic endpoints, which this design does not change. Worth
  stating: the IP this design records on an order is **not** a bot-detection input,
  and using it as one would be a new decision and a new document.
- **`admin-panel-design.md` §14's "Deferred, explicitly" list** — the entries about
  order editing, order creation from the panel and refunds are untouched by this
  design: it adds no writing surface to the panel at all.

**The rule to hold onto when reading this section:** only the sentences carrying a
stage number are edits. Everything else above is a check that came back negative, and
is written down because a cross-reference section that lists only its hits reads as
though it stopped looking.

---

## 15. Open questions for the owner and his lawyer

§0–§14 decide. This section is the other half: thirteen questions whose answer is not
this design's to give. Each states what the document does by default, what the fork
actually is, and a recommendation with its reason — so an unanswered question still
has a deliberate default behind it instead of a gap. **None blocks the pass.**

**The legal ones (five of the thirteen) are the only ones with a real deadline**, and
it is the same deadline: before the first real customer's data is ever stored.

**Q1 — May an authenticated API caller state analytics consent?**
**Default:** no dedicated flag ships; such a caller is `unknown` + `not_asked`.
**The fork:** a `consent` field on the checkout request (trusted only for an
authenticated caller), or nothing.
**Recommendation:** nothing yet. The only caller that will ever have a banner is the
storefront, and it reports through the session/cookie path §8 already defines; a
consent field on the request body is a second door into a decision that must have one
owner. **Answer lands in:** before the storefront ships (stage 3 onward is unaffected
either way).

**Q2 — Should the visited-page list be stored after all?**
**Default:** no — only the landing page, the page count and the session count (§2.1).
**The fork:** the full list (a browsing history) or the summary.
**Recommendation:** keep the summary. It answers O1's question ("from which page did
the visitor enter") and stays proportionate; a page-by-page record is the kind of data
that turns an attribution table into a surveillance log. **Answer lands in:** stage 2
(one column), so it stays cheap to add if the owner wants it.

**Q3 — Does the design's ePrivacy reading hold?** (lawyer)
**Default:** §8.4 item 1 — request-derived facts (the URL, its query string, the
`Referer` header) are written to the order regardless of consent, because nothing is
stored on or read from the visitor's device. **The fork:** the stricter reading, under
which a visitor who has denied leaves no contextual facts at all under `strict`.
**Recommendation:** the default, with the strict reading as a documented one-gate
fallback (§8.4). **Answer lands in:** stage 3 — and it is a lawyer's answer, not a
technical one.

**Q4 — Is the Varnish cache-hit recovery adopted?** (owner)
**Default:** the capture middleware on the customer-facing `GET` routes, with the
landing-page recovery riding the storefront's own hydration call (§4.3(b)). **The
fork:** accept that a cache-hit landing page is never captured (`strict`, simple, and
it loses the campaign on exactly the visits a campaign produces), or add a dedicated
capture call to the storefront.
**Recommendation:** the default, measured against real traffic once the storefront
exists; a dedicated call is a storefront decision, not an attribution one.
**Answer lands in:** stage 3, re-examined when the storefront is built.

**Q5 — What is the IP's retention period and its lawful basis?** (lawyer)
**Default:** `privacy.ip_full_retention_days = 30`, then automatic anonymisation, with
`zero` available for an installation that would rather never hold a full address
(§7.2, §7.5). **The fork:** any period from zero to the order's own lifetime, each
with a different legal basis (security/fraud legitimate interest, contract necessity,
or legal-claim defence).
**Recommendation:** 30 days, on the explicit basis of fraud and dispute evidence —
short enough to be defensible, long enough to cover a chargeback window opening, and
it flips to zero with one setting if the lawyer prefers. **Answer lands in:** stage 3,
before real data is stored (the setting exists from then on; only its value changes).

**Q6 — Who may see the full IP: `ORDER_MANAGE`, or a new permission?**
**Default:** `ORDER_MANAGE` (§7.1) — no new permission, following
`order-lifecycle-design.md` §11 item 16's posture.
**The fork:** a new `PRIVACY_VIEW` (or `ORDER_PII_VIEW`) permission, so a role can
investigate fraud without being trusted to change an order.
**Recommendation:** the default for now, because the permission vocabulary is code and
every added value needs a role that holds it (`staff-access-domain-design.md` §3-§4);
add `PRIVACY_VIEW` the first time a merchant actually wants such a role.
**Answer lands in:** stage 6 (the panel line), later if the owner wants the split.

**Q7 — Who bumps `checkout.terms_version`, and is a dated changelog wanted?**
**Default:** the merchant, by hand, in Settings, whenever the terms text changes; the
version is an opaque string, and checkout refuses a page that displayed a stale one
(§9.1). **The fork:** an automatic version (a content hash, or a timestamp the
storefront computes), or a dated list of the texts themselves kept in the settings or
in a small table.
**Recommendation:** the merchant-set string now, and a changelog of texts **only if**
his lawyer wants the exact wording retrievable years later — at which point the texts
become their own versioned record and `terms_version` points at it. That is a bigger
piece of work than this design, so it should be asked for explicitly.
**Answer lands in:** stage 4 for the string; a changelog would be its own small pass.

**Q8 — Can the customer correct her own invoice details after checkout?**
**Default:** not built — no storefront account surface exists, and today the merchant
is the only one who can ever correct the row, once order editing exists at all (§9.4).
**The fork:** a customer-facing correction path (a link in the confirmation email, or a
page behind guest order lookup — `checkout-domain-design.md` §10's deferred gap).
**Recommendation:** not yet, and note the honest consequence: a customer who mistypes
her ЕИК must contact the merchant. Building the path means opening the guest order
lookup gap, which is explicitly deferred and would be its own design.
**Answer lands in:** a future storefront/account pass, not this design's stages.

**Q9 — Is an order confirmation legally mandatory regardless of the preference?**
(lawyer)
**Default:** `confirmation_requested` governs the courtesy email only; a `NULL` (never
asked) is treated as "send it" (§9.2). **The fork:** a jurisdiction where the
confirmation is itself a legal document, in which case the preference cannot suppress
it.
**Recommendation:** the default, and if the lawyer says a confirmation is mandatory,
the email stage ignores the flag for that one message and the panel says so — a
decision in that stage's document, recorded here so it is not discovered late.
**Answer lands in:** the email stage, informed by the lawyer before it ships.

**Q10 — Who maintains the per-country invoice rules, and does a Bulgarian set ship?**
**Default:** the mechanism ships with an empty map and the generic worldwide rule
(§9.3 item 4); the owner adds his country's entry with his accountant's answer.
**The fork:** EasyCo ships researched starter sets (BG first, with ЕИК/Булстат/МОЛ) or
each merchant writes their own.
**Recommendation:** the default, because a shipped pattern is an assertion of law this
project has no standing to make — and if the owner wants a Bulgarian starter set it is
one JSON value added deliberately, reviewed by his accountant, not code.
**Answer lands in:** stage 4 (the settings surface), any time after.

**Q11 — May an erased individual's invoice details be anonymised?** (lawyer)
**Default:** no — `--include-invoice-details` is off, and the row survives an erasure
(§10.4). **The fork:** erase the individual's billing fields when tax law permits, or
keep them for the retention period.
**Recommendation:** keep them until the lawyer states the retention period and what it
covers; losing a document the merchant was required to keep is unrecoverable, and the
flag exists so no code change is needed when the answer arrives.
**Answer lands in:** stage 7 (the flag), then whenever the answer comes.

**Q12 — When may an order's own contact and delivery fields be anonymised?**
(owner + lawyer)
**Default:** never by this design (§10.5) — they are the order's financial and delivery
record. **The fork:** an order-level retention rule (anonymise `email`,
`recipient_name`, `phone` and the address snapshot N years after delivery), which is
the shape most jurisdictions end up requiring.
**Recommendation:** a separate decision with the owner's retention period, and probably
one command shaped like `privacy:erase-order-context` but over the order — **not** an
extension of this design's command, because it needs the retention answer first and it
touches the financial record.
**Answer lands in:** its own pass; nothing in stages 1-8 blocks it.

**Q13 — Is an "invoice requested" filter wanted in the Orders list?**
**Default:** no — the request shows on the order's own page, and a documents-to-issue
queue is the future Invoicing domain's screen (§11.4).
**The fork:** a filter now (cheap: one `whereExists` condition on the same list query,
and no new index is needed at a single merchant's scale).
**Recommendation:** the default — the filter is one line, but it advertises a workflow
this design deliberately does not own.
**Answer lands in:** stage 6 if wanted; it changes nothing else.
