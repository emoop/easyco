# Protection against fake cash-on-delivery orders (C2-B): blocks by IP / phone / email, and "Съвпада с …"

**Status:** design only. One build stage, C2-B (§8). **Date:** 2026-10-14.
**Owner decisions it builds on (2026-10-12):** a block stops **cash on delivery only**, never the whole site; blocking by IP, phone or
email; default **180 days** with a **permanent** option; the order view shows **"Съвпада с …"** when earlier orders share the phone,
email, name or delivery address/office, a name-only match being a weak, "possible" match; a "risky" badge in the order list later;
the full IP is kept per the order-context retention, a blocked IP for the block's duration; when no payment method is left for a
blocked visitor the order is stopped with «Свържете се посредством методите на контакт за възможността да поръчате от сайта».
**Builds on:** `order-context-design.md` (C2-A: `order_attributions.ip`, the ORDER_MANAGE gate on the full IP),
`checkout-form-v2-design.md` §8 (payment registry), `order-lifecycle-design.md` (statuses, refusals), `staff-access-domain-design.md`.

---

## 1. The real problem, and what this design does about it

People place COD orders they never collect, under different names and IPs, often reusing a phone or an email. The shop pays the
courier both ways. Today the owner blocks IPs by hand on the server. The design gives the staff two tools:

1. **See it coming** — on every order, "Съвпада с …" lists earlier orders that share an identifier, with their outcome, so a
   repeat offender is obvious before the parcel leaves.
2. **Stop it** — one click on the order blocks the IP, the phone or the email for cash on delivery. The person can still pay by
   bank transfer (or any method not marked as blockable); if nothing is left, they are asked to contact the shop.

Non-goals: no automatic scoring or automatic blocking (staff decide); no site-wide ban; no device fingerprinting; no sharing of
block lists between shops.

---

## 2. Identifiers and how they are compared (the "keys")

Matching only works if the same person typed the same thing in different ways and still matches. Each identifier gets one
normalised **key**, computed by ONE class (`IdentityKeys`) used by both the matcher and the block check:

| Identifier | Key | Notes |
|---|---|---|
| Phone | digits only; then the **last 9 digits** when there are at least 9 | `0888 123 456`, `+359 888 123456`, `00359888123456` → `888123456`. A heuristic chosen for the shop's market; documented as such. Real phone validation (the planned design) can replace it later without changing the table. |
| Email | trimmed, lower-cased | No provider-specific tricks (dots/plus in Gmail are NOT stripped: too clever, false positives). |
| IP | the canonical text form (`inet_pton`/`inet_ntop`) | Exact address. IPv6 compared as a /64 prefix (one household/device rotates inside it). |
| Name (weak) | first + last, lower-cased, inner spaces collapsed | Only for "possible match", never for a block. |
| Delivery place | street: country + city + address line 1, lower-cased, spaces and punctuation collapsed; pick-up: carrier + `pickup_point_address` text, same normalisation; hashed (sha1) | One key per order, for "same address/office". |

**Stored keys on `orders`** (new nullable columns, written at placement, back-filled for existing rows by the migration):
`phone_key VARCHAR(20)`, `email_key VARCHAR(255)`, `name_key VARCHAR(201)`, `place_key CHAR(40)`, each with an index
(`ord_phone_key_index`, …). They are derived data: changing the normalisation means re-computing them with a command
(`orders:rebuild-identity-keys`), never a hand edit. The order editor (address/phone changes) recomputes them.

---

## 3. Blocks

### 3.1 Table

```php
Schema::create('order_blocks', function (Blueprint $table) {
    $table->id();
    $table->string('type', 8);                    // ip | phone | email
    $table->string('value_key', 255);             // the KEY of §2 (an IP's canonical form / its /64 prefix for IPv6)
    $table->string('value_display', 255);         // what staff saw when blocking (masked in lists, §3.3)
    $table->string('reason', 500)->nullable();    // staff note, plain text
    $table->unsignedBigInteger('source_order_id')->nullable();   // the order it was created from (no FK: survives erasure)
    $table->unsignedBigInteger('created_by')->nullable();        // staff id
    $table->timestamp('expires_at')->nullable();  // NULL = permanent
    $table->timestamp('revoked_at')->nullable();
    $table->unsignedBigInteger('revoked_by')->nullable();
    $table->timestamps();
    $table->index(['type', 'value_key'], 'oblk_type_key_index');
});
```

- **Active** = `revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now)`. Re-blocking an identifier that is already
  actively blocked extends/replaces that block (one active block per type+key), never adds a duplicate.
- CHECK (MySQL/MariaDB): `oblk_type_check` (`type IN ('ip','phone','email')`).
- **Retention:** a block is personal data with its own clock = its duration. A daily command `order-blocks:prune` deletes rows
  expired or revoked more than 30 days ago (the 30 days let staff see "this was blocked until last week"). Permanent blocks stay
  until revoked. The blocked IP is kept here for the block's duration even after the order's own IP has been anonymised (owner
  decision).

### 3.2 Creating a block (admin)

- On the order view, next to the **phone**, the **email** and the **IP** (the IP only for staff who may see it, ORDER_MANAGE), a
  button **«Блокирай»** → modal: duration **180 дни** (default) / **Завинаги** / custom number of days; reason (optional). The modal
  says plainly: «Блокирането спира само наложения платеж за този клиент. Плащане по банков път остава възможно.»
- An identifier that is already blocked shows **«Блокиран до <дата>»** / **«Блокиран завинаги»** with **«Отблокирай»**.
- Permission: **ORDER_MANAGE** (the same staff who may change an order). Every block and unblock is written to the activity log
  (type, masked value, who, duration) — never the full IP in the log line (C2-A rule).

### 3.3 The list «Блокирания»

An admin page (Orders group): type, masked value (phone `*** *** 456`, email `m***@gmail.com`, IP shown in full only to
ORDER_MANAGE), reason, from order (link), by, until, status (active/expired/revoked); filters by type/status; actions «Отблокирай»,
«Удължи». Manual add (without an order) is allowed for ORDER_MANAGE — the owner already knows some offenders.

---

## 4. The checkout rule

- A payment descriptor gains a flag **`blockable`** (C1d-B registry): **true for cash on delivery**, false for bank transfer; an
  extension decides for its own method (a card payment captured up front does not need it).
- At placement, after the method's own availability check and before anything is written: if the chosen method is blockable and
  the request matches an **active** block — the request IP (by its key), the phone key or the email key of the order — the API
  refuses with **422 `payment_method_blocked`**. Nothing is written, no cart is claimed.
- **What the customer sees** (lang keys, neutral wording — the page never says "you are blocked", and never which identifier
  matched, so the list cannot be probed):
  - another non-blockable method is available → the page marks the blocked method unavailable, selects nothing, and shows:
    «Този начин на плащане не е наличен за тази поръчка. Моля, изберете друг.»
  - no other method → the page locks «Поръчване» and shows the owner's sentence: «Свържете се посредством методите на контакт за
    възможността да поръчате от сайта» (with the shop's contact page link when one is set — the P1 page picker can supply it later).
- **No pre-check endpoint.** Asking "is this phone blocked?" before placing would let anyone test numbers; the answer comes only as
  the refusal of a real placement attempt (rate-limited like every checkout request).
- **Replay safety:** a double submit of an order placed before the block existed returns that order (unchanged C1 behaviour).

---

## 5. "Съвпада с …" on the order view

- A card on the order view, **«Съвпадения»**, computed when the page is opened (not stored): earlier and later orders (excluding
  this one) that share `phone_key`, `email_key`, `place_key` (strong) or `name_key` (weak).
- One query per key type, each by its index, **limited to the 10 most recent** per type; the card shows:
  - per match: order number (link), date, name, status (placed/confirmed/shipped/delivered/cancelled/refunded — so "cancelled"
    and "refunded" stand out), which keys matched («телефон», «имейл», «адрес/офис», «име — възможно съвпадение»);
  - a summary line: «Съвпада с 4 поръчки (2 отказани)» and, when any matched identifier is actively blocked, a red line
    «Телефонът е блокиран до …».
- Name-only matches are shown greyed, labelled «възможно съвпадение», and never trigger the red line.
- Who sees it: ORDER_VIEW (it shows only data the viewer can already open order by order). The IP is NOT a matching key here — an
  IP is shared by whole households/offices and is anonymised after 30 days; the IP is for blocking only.
- The order list's "risky" badge is later (owner decision) and will reuse the same keys.

---

## 6. Privacy and law (stated, not decided by the design)

- The keys and blocks are personal data processed for **fraud prevention** (a legitimate interest in the EU reading); that purpose
  is written in the docblocks and the admin labels, and they are never used for marketing.
- A customer's erasure request (future privacy routine) nulls the order's keys with the rest of the order context; blocks created
  from that order keep only `value_key` while active (the fraud purpose), then age out.
- **Not legal advice** — the owner's lawyer confirms the purpose wording and the retention periods (180 days default, 30-day
  prune).

---

## 7. Edge cases

- Same phone typed by a legitimate family member → staff see the match and decide; nothing is automatic.
- A blocked person switches to bank transfer → allowed by design (the owner's rule: block COD only).
- A block is revoked while a refused customer still has the page open → the next attempt simply succeeds.
- An order without a phone key (short or odd number) → no phone match, no phone block for it; the email/IP still work.

---

## 8. Stage C2-B (one commit)

Migration (`order_blocks`, the four key columns + indexes on `orders` with back-fill) · `IdentityKeys` · key writing at placement
and in the order editor · `orders:rebuild-identity-keys` · block service + admin actions on the order view + the «Блокирания» page +
activity log · descriptor flag `blockable` + the checkout refusal + storefront handling of `payment_method_blocked` · «Съвпадения»
card · `order-blocks:prune` scheduled daily · lang bg/en · docs.

Tests (★ mutation-checked): key normalisation table (phone forms, email case, IPv6 /64, place for street and pick-up) ★; a blocked
phone/email/IP refuses COD ★ and allows bank transfer ★; an expired or revoked block refuses nothing ★; the refusal never names the
identifier; no other method → the locked page and the owner's sentence (fake DOM); re-blocking extends instead of duplicating; the
matches card: strong vs weak, limits, counts, the blocked line, query count bounded ★; IP shown/blocked only with ORDER_MANAGE;
prune deletes only rows past the 30 days; activity log never contains a full IP; back-fill of existing orders.

---

## 9. For the owner to confirm

1. Phone match on the **last 9 digits** (fits Bulgarian numbers; a later phone design can refine it)?
2. **IPv6** blocked by its /64 prefix (one device/household), IPv4 exact?
3. The **IP is used for blocking only, not for "Съвпада с"** (shared by families/offices)?
4. «Съвпадения» visible to everyone with ORDER_VIEW; block/unblock for ORDER_MANAGE?
5. Expired/revoked blocks deleted **30 days** after they end?
6. Manual blocks from the «Блокирания» page (without an order), for offenders you already know?

---

## 10. Stage C2-B — as built (2026-10-19)

**Built as designed** (sections 2-5, 8), with the owner's answers of §9 taken as YES (last 9 digits; IPv6 as a /64; the IP for blocking only;
«Съвпадения» for ORDER_VIEW and block / unblock for ORDER_MANAGE; 30-day prune; manual blocks from the list).

- **`EasyCo\Order\IdentityKeys`** — the ONE normaliser, pure PHP, in the Order package (so the repository that writes the keys and the app-layer matcher
  and block check use the same class): `phone` (digits, the last nine, else NULL), `email` (trim + lower-case), `ip` (IPv4 exact, IPv6 → its /64 prefix,
  an IPv4-mapped IPv6 address is the IPv4 one), `name`, `place` (street: country | city | address line 1; pick-up: carrier | the point's text; lower-case,
  spaces and punctuation collapsed, sha1; NULL without the identifying text) and `forRow()` for the back-fill and the rebuild command. Table-tested.
- **Orders:** `phone_key`, `email_key`, `name_key`, `place_key` + `ord_*_index` (migration `2026_10_19_000001`, with CHECKs `ord_phone_key_check` /
  `ord_place_key_check`). Written by `EloquentOrderRepository::save()` **in the same INSERT** (no query of its own) and recomputed by every save, so the
  order editor refreshes them by itself. The migration back-fills existing rows in chunks and is idempotent; `orders:rebuild-identity-keys` repairs all of
  them (counts only in its output and its log). The placement snapshot table does not get the keys (derived, current data; the snapshot is what was agreed).
- **`order_blocks`** (`2026_10_19_000002`, `oblk_type_check`, `oblk_type_key_index`) and **`OrderBlockService`** — block, extend by re-blocking (one active
  block per type + key, never a duplicate), revoke, `activeFor()` (ONE query for the three keys), `prune()` (rows that ended more than 30 days ago);
  `order-blocks:prune` scheduled daily. Every write logs type, MASKED value, who and the duration (never a full IP) — through `ActivityLogger`, which is
  the merchant's opt-in `admin.activity_log_enabled` setting; the row also keeps `created_by` / `revoked_by`.
- **Checkout:** `PaymentMethodDescriptor::$blockable` (cash on delivery true, bank transfer false, an extension's default false). `CheckoutOrchestrator`
  asks ONE lookup of the active blocks for the request IP key, the order's phone key and email key, **after the availability check and before the order is
  saved** (inside the placement transaction, so nothing is written), only for a blockable method. The refusal is `422 payment_method_blocked` with the
  neutral sentence of `lang/*/checkout.php` and nothing else in the body (never the identifier, never which kind). A replay of an earlier order returns that
  order before any of this runs. **Cost:** a cash-on-delivery placement gains exactly one query (the lookup); a bank-transfer placement gains none.
- **Storefront:** the cards carry `data-blockable`; on `payment_method_blocked` the page marks every blockable method unavailable and unchosen and says
  «Този начин на плащане не е наличен за тази поръчка. Моля, изберете друг.»; when none is left it locks «Поръчване» and shows «Свържете се посредством
  методите на контакт за възможността да поръчате от сайта». No pre-check endpoint. Fake-DOM tested both ways.
- **Admin:** «Блокирай» / «Отблокирай» as suffix actions of the email and phone entries and of an IP row (visible only to ORDER_MANAGE and only when the
  address can be read through `OrderContextReader::fullClientAddress()`); the modal (180 days / forever / another number of days, a reason, the note);
  «Блокиран до …» / «Блокиран завинаги» under the value. The list «Блокирания» (`OrderBlockResource`, Orders group): masked values, the full IP for
  ORDER_MANAGE only, type and state filters, «Отблокирай», «Удължи» and the manual add.
- **«Съвпадения»** (`OrderMatchReader`, `OrderMatchesCard`): at most four key reads (phone, email, place, name), each by its index, newest first, limited to 10,
  plus the single block lookup shared with the buttons; the summary counts the strong matches and those cancelled or refunded; weak (name-only) matches are
  greyed and never counted; the red line appears only for a phone or email the order SHARES with another and that is actively blocked.

**Not built:** the «risky» badge in the orders list (the owner's later step; it will reuse the keys), a contact-page link in the locked-checkout sentence,
and the keys' erasure with the order context (the future privacy routine, §6).
