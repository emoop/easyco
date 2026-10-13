# Checkout Form v2 — names, the pick-up point, required markers, optional choices, consent, payment methods from extensions

**Status:** design only, accepted by the owner 2026-10-13 (§12). Nothing here is built. Two stages, C1d-A and C1d-B (§10), build it, one reviewed commit each.
**Date:** 2026-10-13. **Owner decisions:** recorded in §0, taken in conversation on that date.
**Builds on:** `checkout-domain-design.md` §12 (terms, the phone-call choice, the two form hooks), `order-context-design.md`
§2.3 / §9 (the terms proof, the call-before-shipping column, the invoice request), `checkout-region-and-note-design.md`
(region and note, built in RN-1 / C1b), `storefront-design.md` §2.10–2.11 (the checkout page as built in C1 / C1b),
`payment-domain-design.md` §4 (the adapter contract).
**Amends:** `checkout-domain-design.md` §12.1 and `order-context-design.md` §9.1 rule 1 and §9.2 (§11 lists every edit).

---

## 0. Owner decisions (2026-10-13)

| # | Decision |
|---|---|
| D1 | **First name and last name are two separate fields**, side by side. The old single `recipient_name` is **removed** from the API without a compatibility shim (no external clients exist). |
| D2 | **No salutation** (no Mr/Mrs). Messages greet by first name: "Здравейте, Емил". |
| D3 | **One field for the pick-up point** when there is no courier integration: "Офис или автомат" — the customer types the address, the name or the code, whatever they know. Code and name are not asked. With a courier integration (later) code, name and address come from the courier's system. |
| D4 | **Required fields carry an asterisk.** |
| D5 | **"Call me before shipping"** — an optional checkbox under the phone field, shown only when the merchant turns it on. |
| D6 | **"Subscribe me to the newsletter"** — an optional checkbox under the names, shown only when the merchant turns it on. |
| D7 | **A privacy notice** ("Your personal data will be used to process your order … described in our [privacy policy]") and **a terms checkbox** ("I have read and agree to the website's [terms and conditions] *"). |
| D8 | **No checkbox is ever ticked by default** — the terms one least of all. |
| D9 | **The terms checkbox can be switched off by a setting**: somewhere in the world a store may not need it. |
| D10 | **Payment methods are added by the extension that brings them**, never hard-coded: a card or virtual-POS extension must be able to add its method (and its button / logo) to the checkout and send the customer to its payment page. |
| D11 | Order of work: C1b (done, `27351f8`) → C1c promo code → **C1d form v2 (this document)** → C2 (order context: attribution, IP, invoice, blocking). |
| D12 | The terms checkbox stands **before** its sentence: `[ ] Прочетох и се съгласявам с правилата и условията на сайта *`. No "send me an order confirmation" checkbox is shown (§6.5). |
| D13 | The order button keeps the wording shops use: **«Поръчване»** (§7). |
| D14 | The pick-up field: label «Офис или автомат», help line «Адрес, име или код на офиса». |
| D15 | Two build stages instead of four, so the full suite runs twice, not four times (§10). |

**What D8 and D9 change in earlier designs** (both were decided before the owner looked at a real checkout):
- `checkout-domain-design.md` §12.1 and `order-context-design.md` §9.1 rule 1 say the terms acceptance is "always mandatory,
  never settings-controlled". **Superseded by D9:** it is on by default and can be switched off (§6.3).
- `order-context-design.md` §9.2 has a `confirmation_requested` checkbox "ticked by default". **Superseded by D8:** see §6.5
  — the recommendation is not to show that checkbox at all.

---

## 1. Scope and non-goals

**In scope:** the checkout form's fields and their server rules; the order / address / snapshot columns they need; the admin
settings that switch the optional parts; the payment section's rendering from a registry that extensions fill.

**Not in scope** (each named so it is not assumed):
- The **invoice request** — C2, `order-context-design.md` §9.3, unchanged.
- **Attribution, IP, consent mode, blocking** — C2.
- A **newsletter / marketing domain** (subscriber list, double opt-in, unsubscribe, sending). This design records the
  customer's consent on the order and nothing else (§6.2).
- **Online payment flows themselves** (redirect, return URL, webhook, refund). §8 defines only the slot and the contract the
  checkout page needs; the provider adapters are a separate design (payments/virtual POS — Opus, with the provider's docs).
- **A pick-up point picker** (list / map from the courier). §3.3 says what changes when it arrives.
- **Editable legal texts in the admin.** The texts are lang keys now (§6.4); a merchant-editable, per-language text is a
  later store-settings task.

---

## 2. First name and last name (D1, D2)

### 2.1 Why two fields

Every platform the owner's customers know asks for them separately (Shopify's default is "Require first and last name";
WooCommerce has `billing_first_name` / `billing_last_name`). Two fields are what lets the shop greet by first name (D2), sort
and search by last name, print a courier label in the courier's own order, and fill an individual's invoice later. Splitting
one field afterwards cannot be done reliably (double first names, multi-part surnames, "Surname Name" order), so the split
happens at the source.

### 2.2 Schema

On **`addresses`, `orders`, `order_placement_snapshots`** (one `add_…` migration per package, the creating migrations are not
edited):

```php
$table->string('recipient_first_name', 100)->after('recipient_name');
$table->string('recipient_last_name', 100)->after('recipient_first_name');
// backfill (below), then
$table->dropColumn('recipient_name');
```

- **NOT NULL, 100 characters each.** 100 is ample for a name and keeps the combined label well inside what couriers accept;
  the exact courier limits are the courier adapters' job (they truncate or refuse with their own message).
- **Backfill of existing rows** (test data only — D1 says nothing real is lost): split `recipient_name` at the **last** run of
  whitespace — `"Иван Петров Иванов"` → first `"Иван Петров"`, last `"Иванов"` (the Bulgarian three-name form keeps the
  family name last). A **single word** goes whole into the first name and the last name is the empty string `''`.
- **The empty last name is legal only for a row that existed before the split** (the same "a historical row must still load"
  rule as the pick-up display fields in stage 4f): `Address::reconstituteFromStorage()` / `Order::reconstituteFromStorage()`
  accept it; `create()` refuses an empty value for either field.
- No index (nothing filters by them yet; the admin search in §2.5 is a `LIKE` on the order list; until C1d-A the list did NOT search by name, and C1d-A adds it).

### 2.3 Domain

- `Address`, `Order`, the placement snapshot: `recipientName` is replaced by `recipientFirstName` + `recipientLastName`,
  both `assertNotEmpty` on create, both trimmed, both `PlainText`-clean (the request layer's rule, as today).
- **One derived accessor, no stored copy:** `recipientFullName(): string` = first + `' '` + last, trimmed. This is what a
  courier label, the admin list and the bank-transfer payer name use. A third stored column would be a second truth that can
  drift.
- `OrderEditFormMapper` / the order editor edit both fields (staff edits follow the same non-empty rule).

### 2.4 API

`POST /api/checkout` and the address endpoints:

| Field | Rule |
|---|---|
| `recipient_first_name` | `required|string|max:100`, `PlainText` |
| `recipient_last_name` | `required|string|max:100`, `PlainText` |
| `recipient_name` | **removed.** Not in the rules any more, so a client that still sends it gets the two `required` errors. |

Every response that carried `recipient_name` (checkout `orderToArray`, the address resource, the admin API if any) carries
the two fields instead. The `address_id` path is unchanged: a saved address brings its own two fields.

### 2.5 Where the name is shown

| Surface | Shows |
|---|---|
| Storefront checkout | Two inputs side by side on desktop, stacked on a phone: «Име» (`autocomplete="given-name"`), «Фамилия» (`autocomplete="family-name"`) |
| Order-received page, confirmation email | Greeting **"Здравейте, {first name}"** (D2); the destination block shows the full name |
| Admin order view / list / editor | Full name in the list; the view and the editor show the two fields; the list's search finds an order by the name (every word of the term must match the first OR the last name; an owner decision of C1d-A that replaces the earlier "id and email only" rule, see admin-panel-design.md §14) |
| Sandbox views | Updated to the two fields (they are the API's manual test surface) |
| Courier label / shipment request (later) | `recipientFullName()` |

**Name order** is "first last" everywhere in this design. A locale where the family name comes first is a display rule for
later (a per-locale format in one helper), not a schema question.

---

## 3. The pick-up point: one field (D3)

### 3.1 What is true today

The checkout asks for three things: code, name, address of the office / locker. The API **requires all three** for a
pick-up order (`required_if:delivery_type,pickup_point` on `pickup_point_reference`, `pickup_point_name`,
`pickup_point_address`), and **both domains require the code**: `Address` and `Order` throw
`"… pickupPointReference must not be empty when deliveryType is PICKUP_POINT"`. A customer does not know an office code.

### 3.2 The model: a pick-up point is either *identified* or *described*

| Kind of point | Comes from | `pickup_point_reference` | `pickup_point_name` | `pickup_point_address` |
|---|---|---|---|---|
| **Described** (no integration — today, and any courier without one) | What the customer typed | NULL | NULL | **required** — the customer's text: address, name or code |
| **Identified** (a courier integration picked it, later) | The courier's system (`PickupPointProvider`) | **required** — the courier's id | from the courier | from the courier |

The invariant becomes: **a pick-up point has a reference OR a description (`pickup_point_address`), at least one; a new
described point has a non-empty description.** `carrier_code` and `settlement` stay required as today (the courier is the
one the customer chose; the settlement is the place field).

### 3.3 What changes, layer by layer

- **Domain (`Address`, `Order`):** `pickupPointReference` is removed from the "must not be empty for a PICKUP_POINT" list and
  replaced by the rule above. `assertPickupDisplay` keeps its 1–255 shape when given.
- **`PickupKindSource::kindFor()`** takes `?string $pickupPointReference` (a described point has none). The client-claimed
  source ignores it already; the future courier source derives the kind from the reference and, for a described point,
  falls back to the claim — stated in its docblock.
- **`ShipmentRequest`** already treats "no reference" as "not an office delivery". With a described point the shipment cannot be
  created automatically for an office; staff pick the office in the courier's system. That is the honest state without an
  integration, and the integration stage removes it.
- **API:** `pickup_point_address` stays `required_if:delivery_type,pickup_point`; `pickup_point_reference` and
  `pickup_point_name` become `nullable` (still `prohibited_if:delivery_type,street_address`, `max:255`, `PlainText`). When an
  integration exists, its stage makes the reference required **for the methods that have one** — a rule per method, not a
  global one.
- **Storefront:** one input, label **«Офис или автомат»**, help line under it: «Адрес, име или код на офиса» (bg) /
  "The office's address, name or code" (en) (D14). The two other inputs and the note "not
  selected from a map yet" are removed. The C1b rule "clear the point when the courier or kind changes" applies to this one
  field.
- **Admin order view and editor:** show the described text; the editor offers the one field for a described point.
- **Existing rows:** nothing to migrate — they have all three values, which is still legal.

---

## 4. Required markers (D4)

- Every required field's label ends with a visible **`*`** (`<span class="sf-required" aria-hidden="true">*</span>`) and the
  input carries `required` / `aria-required="true"`; a line at the top of the form: «Полетата, отбелязани с *, са
  задължителни.»
- **The marker follows the rule, it is not typed by hand.** The server renders it from the same facts the API enforces (the
  region policy for the current country, the terms setting); fields whose requirement changes on the page (region by
  country; street vs pick-up fields by kind) get the marker toggled by the script from the same state that toggles
  `required`. A static test fails if a `required` input has no marker or a marker sits on an optional one.
- Optional inputs say nothing extra (no "(optional)" suffix next to the asterisk convention), except where C1b already wrote
  it into the label.

---

## 5. Field order on the page

```
Контакт
  Имейл *
  Име *            Фамилия *
  [ ] Абонирай ме за бюлетина                 ← only when enabled (§6.2)
  Телефон *
  [ ] Позвънете ми преди изпращане             ← only when enabled (§6.1)
Доставка          (as C1b: country, place, courier, kind, street fields + region | the one pick-up field)
Бележка към поръчката
Плащане           (rendered from the registry, §8)
Privacy notice                                ← only when a privacy URL is set (§6.4)
[ ] Прочетох и се съгласявам с правилата и условията *   ← when the terms setting is on (§6.3)
[ Поръчване ]                                             (§7)
```

The promo code box (C1c) stays in the summary column.

---

## 6. The optional choices and the consent

### 6.1 Call before shipping (D5)

- **Setting:** `checkout.call_before_shipping_enabled` — `'1'` / absent (absent = **off**). This *is* the key
  `checkout-domain-design.md` §12.3 sketched as `checkout.phone_call_field_enabled`; the new name says what the customer
  asks for, and it is the only one built.
- **Column:** `orders.call_before_shipping` (and the snapshot), nullable boolean, exactly as `order-context-design.md` §9.2.1
  decided: `true` asked, `false` offered and not ticked, **NULL not offered** (setting off, or an API caller that did not send
  it). Never ticked by default (D8).
- **API:** `call_before_shipping` — `sometimes|boolean`. Sent while the setting is off → ignored and stored NULL (the field
  was not offered; a client cannot record a choice the shop did not ask for).
- **Admin:** the order view's "Order details" card already has the row (`ctx_call_before_shipping`, "n/a" today); it starts
  showing the value. When `true`, the order list shows a small phone icon next to the order number and the future printable
  sheet prints it — a flag nobody sees is useless.

### 6.2 Newsletter consent (D6)

- **Setting:** `checkout.newsletter_enabled` — `'1'` / absent (absent = **off**).
- **Columns on `orders` (and the snapshot):** `marketing_consent` nullable boolean (same three meanings as §6.1) and
  `marketing_consent_text` `VARCHAR(500)` nullable — **the exact sentence the customer ticked, in the language she saw**,
  written only when `marketing_consent = true`. The order's `placed_at` is the time. That pair is the proof of consent
  (GDPR Art. 7(1): the controller must be able to demonstrate consent), and it is order-scoped — no global consent log, the
  same proportionate choice as `order-context-design.md` §8.4 item 5.
- **Never ticked by default (D8).** In the EU a pre-ticked box is not valid consent (CJEU *Planet49*, C-673/17). Shopify
  lets merchants pre-select the box per region; EasyCo does not offer that option at all.
- **What it does today: nothing but the record.** No subscriber list exists. The future marketing domain reads
  `marketing_consent = true` orders (email + text + time) as its import source, and owns double opt-in and unsubscribe.
  `order-context-design.md` §9.2's "no marketing field exists anywhere in this design" stays true for *that* design; this one
  adds it, deliberately.
- **API:** `marketing_consent` — `sometimes|boolean`; the server writes the text itself from the lang key of the order's
  locale (the client never supplies the text). Setting off → ignored, NULL.

### 6.3 Terms acceptance (D7, D8, D9)

- **Settings:** `checkout.terms_enabled` — `'1'` / `'0'`, **absent = on** (the EU default, as `privacy.consent_mode` defaults
  to `strict`); `checkout.terms_url`; `checkout.terms_version`.
- **The checkbox is shown only when the setting is on AND a URL is set** — a sentence asking to accept terms nobody can
  open is worse than none. The admin settings page shows a warning when terms are on without a URL, and the storefront then
  shows nothing (and the server does not require it: §6.3 rule below follows what was shown).
- **Never ticked by default (D8).** Unticked → the browser stops the submit (`required`) and the server refuses
  `terms_not_accepted` (422, translated) — `order-context-design.md` §9.7, unchanged.
- **Proof:** `orders.terms_accepted_at` (placement instant) and `orders.terms_version` — `order-context-design.md` §2.3 /
  §9.1 rules 2–3, unchanged, including `terms_version_changed` when the page showed an older version.
- **Who bumps the version** (`order-context-design.md` §15 Q7): the settings page writes `checkout.terms_version` = today's
  date automatically when the URL changes, and has a button **«Правилата са обновени»** that sets it to today. The merchant
  never types a version string.
- **Off (or no URL):** the checkbox is not rendered, `terms_accepted` / `terms_version` are **prohibited** in the request
  (a client cannot record an acceptance of terms the shop does not show), and both columns are NULL — "not asked", the same
  meaning NULL has for orders placed before the field existed.

### 6.4 The privacy notice (D7)

- **Not a checkbox** — information, placed directly above the terms checkbox and the button (WooCommerce's own place for its
  "Your personal data will be used to process your order…" text, which is the wording the owner gave).
- **Setting:** `checkout.privacy_url`. Notice shown only when it is set; the admin page warns when it is not.
- **Text:** lang keys with a `:link` placeholder; the link text and the URL are inserted by Blade as an escaped `<a>` — never
  a merchant string rendered as HTML. Links open in a new tab with `rel="noopener"` so the half-filled form is not lost.
  - bg: «Вашите лични данни ще бъдат използвани за обработка на поръчката, за подобряване на вашето потребителско изживяване
    на този сайт и за други цели, описани в нашата :link.» — link text «политика за поверителност».
  - terms bg: «Прочетох и се съгласявам с :link на сайта» — link text «правилата и условията».
  - en: the WooCommerce-equivalent sentences.
- Editable texts per store and per language are a later store-settings task (§1).

### 6.5 The order-confirmation choice — not shown (D12)

`order-context-design.md` §9.2 designs a `confirmation_requested` checkbox, ticked by default. D8 forbids the default, and
an unticked "send me a confirmation" box is a trap: the confirmation is a transactional message every customer expects, and
in some jurisdictions a legal one (that design's own §15 Q9). **Recommendation:** the storefront does not render it; the
column stays as designed and is NULL for storefront orders, which that design already defines as "send it". An API caller
may still send the field. **Decided (D12):** not rendered.

### 6.6 Settings page

`CheckoutSettings` gains a second section, **«Полета при поръчка»** (same page, `SETTINGS_MANAGE`, one Save, no audit beyond
the notification — as RN-1):

| Control | Key | Default |
|---|---|---|
| Show "Call me before shipping" | `checkout.call_before_shipping_enabled` | off |
| Show "Subscribe me to the newsletter" | `checkout.newsletter_enabled` | off |
| Require acceptance of the terms | `checkout.terms_enabled` | on |
| Terms and conditions page (URL) | `checkout.terms_url` | — (warning when terms on and empty) |
| Terms version (read-only) + «Правилата са обновени» | `checkout.terms_version` | set automatically |
| Privacy policy page (URL) | `checkout.privacy_url` | — (warning when empty) |

URLs: `http(s)` only, ≤ 2048, validated; a relative path (`/politika`) is allowed for a page of the same store. All the
checkout keys (these six and the two region keys) are read with **one** `getMany` call per page render and per checkout
request — one small reader (`CheckoutFormSettings`) that the region policy reads through too — so the new settings cost no
extra query (RN-1b's lesson). If the stage cannot keep the shell at 7 and the checkout pin where it is, it stops and reports
rather than raising a pin.

---

## 7. The order button's label (D13)

**Decided: «Поръчване»** (en: "Place order"), a lang key, so a store anywhere can change it.

**The legal risk, recorded once so it is a known decision and not a surprise.** The EU Consumer Rights Directive (2011/83/EU,
Art. 8(2)) requires the button that places an order to say **"order with obligation to pay" or a corresponding unambiguous
formulation**, else the consumer is not bound by the order; Bulgarian commentary renders it «поръчка със задължение за
плащане». The Court of Justice held that **only the words on the button count** — not the terms, the page around it or
anything the customer accepted before (CJEU C-249/21, *Fuhrmann-2*, 7 April 2022). So the terms acceptance does not cure a
label that is not unambiguous. In practice the risk is an unpaid order that the customer may refuse; for cash on delivery it
is small, for a card payment larger. **Not legal advice** — the owner's lawyer may confirm «Поръчване», and changing the
label later is one lang line.

A payment method that sends the customer to a payment page may change the label through its descriptor (§8)
(e.g. «Поръчване и плащане с карта»).

---

## 8. Payment methods added by extensions (D10)

### 8.1 What is true today

`PaymentMethodAdapterResolver::availableMethods()` returns a hard-coded `['cash_on_delivery', 'bank_transfer']`, and the
checkout page renders one radio per code with the label `storefront.payment_methods.{code}`. An extension cannot add a method
without editing that list and the lang file. Adapters are bound as `payment.adapter.<code>` by `PaymentServiceProvider`.

### 8.2 The registry

A **`PaymentMethodRegistry`** (app layer) replaces the hard-coded list. Every payment extension (the two built-in ones
included) registers a **descriptor** from its own service provider, next to its adapter binding:

```php
$registry->register(new PaymentMethodDescriptor(
    code: 'cash_on_delivery',
    label: fn (string $locale): string => __('payment.methods.cash_on_delivery', [], $locale),
    description: fn (string $locale): ?string => …,          // one line under the label, optional
    icon: null,                                               // a public asset URL of the provider's logo, optional
    flow: PaymentFlow::OFFLINE,                               // OFFLINE | REDIRECT | EMBEDDED
    placeOrderLabel: null,                                    // optional, must keep the obligation wording (§7)
    sortOrder: 10,
    availability: fn (PaymentAvailabilityContext $c): bool => true,  // country, currency, total, delivery kind
));
```

- **`availableMethods()`** asks the registry; the resolver's test "every listed code resolves, every bound adapter is listed"
  becomes "every descriptor has an adapter and every adapter a descriptor".
- **`availability`** is how a method says "not for this order" (a card method only in EUR, cash on delivery not for a locker
  if a courier forbids it, a limit on the total). The checkout page and the API ask the same function; the API refuses a
  method that is not available for the order (`payment_method_unavailable`, 422) — never trusting the page.
- **The merchant's switch:** an extension being installed does not make its method appear by force — a future admin row per
  method ("enabled", order) sits on top of the descriptor's `sortOrder`. Not built in C1d (two built-in methods only); named
  so the registry has the hook (`isEnabled` read from a setting `payment.methods.<code>.enabled`, absent = on).

### 8.3 The storefront

- The payment section is a list of **cards**, one per available method: radio + label + logo (when the descriptor has one) +
  the description line. Logos are `<img>` with `alt` = label, size-limited by CSS; nothing from a descriptor is HTML.
- **An express-checkout slot** at the top of the checkout (above "Контакт"), for one-click wallets (PayPal, Apple Pay,
  Google Pay): a container the extension fills through a storefront hook (`storefront.checkout.express` — a list of rendered
  partial names an extension contributes). **Empty → not rendered**, so today nothing changes on the page.
- The **place-order button's label** follows the chosen method's `placeOrderLabel` when it has one, else §7's default.
- The first available method is pre-selected (a choice of method, not a consent — D8 is about checkboxes; Shopify and
  WooCommerce do the same). Recorded here so it is a decision, not an accident.

### 8.4 The contract the checkout response will carry (designed, not built)

For `flow = REDIRECT`, after the order is placed (the Payment row is PENDING, `payment-domain-design.md` §4), the checkout
response carries **`next_action: {type: "redirect", url}`** instead of only the confirmation URL; the page goes to that URL
(an `https` URL the adapter produced, never one taken from the request). The provider sends the customer back to a return
route that shows the order-received page with the payment state. For `EMBEDDED` (a card form inside the page), the
descriptor names a partial and a script the extension ships. **None of this is built in C1d** — it belongs to the first real
provider adapter's design, with that provider's documentation (Opus stage). C1d builds only the registry, the descriptor and
the rendering, so that stage plugs in without touching the checkout page's markup.

---

## 9. Personal data

New personal data introduced here: `recipient_first_name`, `recipient_last_name` (replacing `recipient_name`),
`marketing_consent` + `marketing_consent_text`. They join the list the future "forget this customer" routine must clear
(`order-context-design.md` §10; `checkout-region-and-note-design.md` already lists region and note). The terms proof and the
call-before flag are not personal data on their own.

---

## 10. Stages (D15: two, so the full suite runs twice)

Each stage: one commit; targeted tests while working; **one** full suite at the end; mutation-checked where its prompt marks it.

**C1d-A — field shapes (Opus: it changes domain invariants).** Everything of §2 (first and last name: migrations + backfill on
the three tables, domain, snapshot, API, admin view / list / editor / search, order-received page and confirmation greeting,
sandbox views, storefront inputs), §3 (one pick-up field: the identified / described invariant, `PickupKindSource`, API rules,
storefront, admin) and §4 (the required markers for the whole form). Tests: backfill cases (two words, three words, one word,
extra spaces); `create()` refusing an empty name part; a historical empty last name loading; the old `recipient_name` refused;
the greeting by first name; the full name on every surface; a described point checking out with address text only; a pick-up
order with no description refused on `pickup_point_address`; a reference alone still legal in the domain; a street address
still refusing all pick-up fields; existing rows unchanged; marker ⇄ `required` parity; the C1b fake-DOM scenarios updated.

**C1d-B — choices, consent, payment registry (Sonnet).** §6 (the `orders` / snapshot columns `terms_accepted_at`,
`terms_version`, `call_before_shipping`, `marketing_consent`, `marketing_consent_text`; the settings section; the API rules and
refusals `terms_not_accepted` / `terms_version_changed` with `lang/*/checkout.php`; the storefront checkboxes, notice and
button label; the admin "Order details" values and the list's phone icon) and §8 (`PaymentMethodRegistry`, descriptor,
`PaymentFlow`, `PaymentAvailabilityContext`, the two built-in descriptors, `payment_method_unavailable`, the method cards, the
empty express slot, the label per method). **This pulls `order-context-design.md` §2.3 and the terms part of its stage 4
forward**; C2 builds the rest of that design on top. Tests: every checkbox unticked on render (fails on a `checked`
attribute); each setting on/off × field sent/not sent → NULL / false / true; terms refused when on and unticked, prohibited
when off, version mismatch refused; the consent text taken from the order's locale, never from the request; the double-submit
replay storing the values once; a test-only extension adding a third payment method with no core file edited; an unavailable
method absent from the page and refused by the API; descriptor ⇄ adapter parity; nothing from a descriptor rendered as HTML;
the query pins.

---

## 11. Edits to other documents (land with the stage that makes them true)

| Document | Edit | Stage |
|---|---|---|
| `checkout-domain-design.md` §12.1 | Terms: "on by default, can be switched off (D9); never pre-ticked (D8)"; `requiresPhoneCallBeforeShipping` → `call_before_shipping`, setting renamed | C1d-B |
| `checkout-domain-design.md` §12.3 | Key `checkout.call_before_shipping_enabled` replaces `checkout.phone_call_field_enabled` | C1d-B |
| `order-context-design.md` §9.1 rule 1, §9.2, §13 | D9 / D8; confirmation box not rendered (D12); stage 4's terms part and §2.3's columns built in C1d-B | C1d-B |
| `order-context-design.md` §15 Q7 | Answered by §6.3 (automatic date + button) | C1d-B |
| `address-domain-design.md` | Names; described vs identified pick-up point | C1d-A |
| `shipping-domain-design.md` (pick-up point sections) | Described points; reference required per integrated method | C1d-A |
| `payment-domain-design.md` §4 | The registry and the descriptor beside the adapter | C1d-B |
| `storefront-design.md` §2.11 | The form as built | each stage |
| `site-settings-design.md` | The six new keys | C1d-B |
| `extensibility-coverage-design.md` | `storefront.checkout.express`; the registry as the payment extension point | C1d-B |

---

## 12. The owner's answers (2026-10-13)

- **Q1** confirmation checkbox → not shown (D12).
- **Q2** terms on by default with the URL warning → yes.
- **Q3** button label → «Поръчване» (D13; risk recorded in §7).
- **Q4** pick-up field → «Офис или автомат» / «Адрес, име или код на офиса» (D14).
- **Q5** keep the ticked newsletter sentence as proof → yes.
- **Q6** stages → two (D15).

---

## Sources

- Shopify Help Center — Checkout form options: <https://help.shopify.com/en/manual/checkout-settings/checkout-form-options>
- WooCommerce — Customizer › Checkout (field settings, privacy text, terms): <https://woocommerce.com/document/woocommerce-customizer/checkout/>
- CJEU C-673/17 *Planet49* (pre-ticked boxes are not valid consent), summary: <https://cms.law/en/int/legal-updates/time-to-update-your-cookie-consent-and-policy!-cjeu-says-pre-ticked-checkboxes-are-not-valid>
- Directive 2011/83/EU, Art. 8(2) (the "order with obligation to pay" button); CJEU C-249/21 *Fuhrmann-2* (only the button's words count).
- Bulgarian commentary on the button label: <https://www.da-law.eu/blog/dlzhite-li-pari-sled-otkazana-onlayn-porchka>
- Active (not pre-filled) consent to terms, Bulgarian practice: <https://www.innovires.com/blog/obshti-usloviq.html>
