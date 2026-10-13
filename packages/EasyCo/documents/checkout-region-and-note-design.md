# Checkout: the region (state) field and the customer's order note — design

Status: DESIGN ONLY (2026-10-13). Nothing here is built. Written by the architect from the code at HEAD `149897c` (after shipping 5g-2a; 5g-2b reviewed, not yet committed when this was written). Marks: **V** = read in the code, **I** = my inference.

The owner's decisions this design is built on (2026-10-13):

- **D1 Region.** A new address field "Област" (state / province / region). It is **shown by default**, and the merchant can **hide it per country** from the admin; it is required only where the merchant says so (the earlier wish: "required only if configured in the admin").
- **D2 Region is free text now.** A dropdown of each country's regions comes later, as a per-country extension.
- **D3 Order note.** A free-text field for the customer's note to the order.
- **D4 Changing the delivery choice before "Place order" must keep working** (courier, kind, address typed again). Only what is submitted with "Place order" counts. This is a checkout-UI rule, kept here so C1b tests it.
- Out of scope here: **"I want an invoice"** — already designed in `order-context-design.md` §2.2 (`order_invoice_details`, company and individual), built with stage C2.

---

## 1. What is true today (verified)

- **V — no region anywhere.** Neither `EasyCo\Address\Address` nor `EasyCo\Order\Order` nor their tables carry a region/state/province column (`grep -i "region|province|state"` over `packages/EasyCo/Address/src` and the checkout controller: no hit).
- **V — no customer note.** No `customer_note` (or similar) on orders; the only order "notes" are STAFF notes (`OrderNoteRecorder`, a `note_added` order event, `order-lifecycle-design.md` §8.1). A customer note is a different thing: the customer's own words at placement.
- **V — address fields** live on three tables with the same shape: `addresses`, `orders`, `order_placement_snapshots` (stage 4f added the pickup display snapshot to all three with one app-level migration; 5g-2a added `pickup_point_kind` the same way). The entities validate street vs pickup fields (`Address::assertFieldsMatchDeliveryType()`, the same in `Order`).
- **V — where an address is entered or shown:** checkout request (`CheckoutController::validationRules()`), address API (`AddressController`), `CheckoutInput` → `CheckoutOrchestrator` (`resolveAddress()`, `Order::create()`), `OrderPlacementSnapshotWriter`, order JSON (`orderToArray()`), admin order view (`OrderResource::deliveryAddressHtml()`), admin order editor (`OrderEditFormMapper`, `OrderDeliveryChange`, `OrderEditor`, `Order::reviseDelivery()`), the confirmation email (`app/Mail/OrderConfirmationContent.php`), the order-received page (`app/Storefront/Orders/OrderReceivedReader.php`), the storefront form (`resources/views/storefront/checkout.blade.php`, `resources/js/storefront-checkout.js`).
- **V — settings** are key/value strings in `site_settings` (`site-settings-design.md` §4): a structured value is JSON-encoded by its owner; the owner of a key also owns its default and its validation (§5). Settings pages live under `app/Filament/Pages/Settings/` (Locale, Catalog, Mail, BankTransfer), gated by `Permission::SETTINGS_MANAGE`.
- **V — `PlainText`** (`app/Rules/PlainText.php`) refuses every C0/C1 control character, **newline and tab included** ("every field this rule guards is single-line"). It cannot guard a multi-line note.
- **V — shipping does not read a region.** `ZoneMatcher` matches country, settlement and postcode; the pricing hash binds country, normalized settlement, postcode, kind. A region added to the address changes no price and no handle.

## 2. The region

### 2.1 Where it applies

**Street addresses only.** A pickup point already has its settlement and the office's own address; the customer does not type a region for an office or a locker. Entity rule and DB CHECK: `region` is NULL for a pickup point (the same shape as `city`, `postal_code`, `address_line_*`).

### 2.2 The per-country rule (D1)

Three modes: **`optional`** (shown, may be left empty), **`required`** (shown, must be filled), **`hidden`** (not shown, not stored).

- **A default mode** for every country, initially `optional` (the owner's "shown by default").
- **Per-country overrides**: a map country → mode, e.g. `{"BG": "hidden"}` or `{"US": "required"}`.
- Settings keys (owned by the new reader, §2.3): `checkout.region_default` = `optional | required | hidden` (absent = `optional`); `checkout.region_countries` = JSON object `{ISO2: mode}` (absent = `{}`). Only known ISO country codes (`CountryNames::isKnownCode`) and the three modes are accepted when saving.

### 2.3 The one reader

`App\Settings\CheckoutRegionPolicy` (app layer, beside `StoreCountry`): `modeFor(string $country): RegionMode` (enum `optional | required | hidden`) and `all(): array` (default + overrides, for the storefront page). Absent keys use the documented defaults above; a **malformed** stored value (not JSON, unknown mode, unknown country) **throws** (CLAUDE.md rule 8: fail loud, no silent guessing) — the admin page validates before saving, so this only happens after a hand edit. Every consumer (checkout request, address API, storefront page, admin editor) asks this ONE reader.

### 2.4 Server rules (the request is the truth, not the page)

For a **street address** with country C:

| mode for C | `region` absent / empty | `region` given |
|---|---|---|
| optional | stored NULL | stored (trimmed) |
| required | **422** `region` field error | stored |
| hidden | stored NULL | **ignored, stored NULL** (see Q2) |

For a **pickup point**: `region` is `prohibited_if:delivery_type,pickup_point` (422), like `city`. With `address_id`: `region` is in the `prohibits` list (the saved address's own region is used).

Validation: `nullable|string|max:100` + `PlainText` (single line). Column `VARCHAR(100) NULL`.

**A saved address and the current rule.** A saved address whose country is now `required` and which has no region (saved before the rule) is still usable at checkout (I recommend this, Q5): the rule applies to what is typed, as postal-code rules do; refusing it would break a returning customer's one-click checkout over a field the merchant added later. The admin sees "—" for the region.

### 2.5 Display

Wherever an address is shown, the region sits between the city and the country (`Sofia, 1000, Sofia-city, BG` style; each surface keeps its own line layout): admin order view, order editor (editable for a street address, like the city), order JSON and address JSON (`region`), the confirmation email, the order-received page. Labels: bg **«Област»**, en **"Region"** (one label now; a per-country label such as "State" comes with the per-country list, D2). Storefront input: `autocomplete="address-level1"`, `maxlength="100"`.

### 2.6 Not now (recorded so they are not lost)

- A per-country list of regions (dropdown) and per-country labels — an extension, D2.
- Shipping zones that match by region (e.g. US states) — `ZoneMatcher` and the pricing hash are unchanged here; a later shipping design adds a `regions` dimension to zones if needed.
- Requiring a region on a saved address retroactively.

## 3. The customer's order note (D3)

- **Column** `orders.customer_note VARCHAR(1000) NULL` and the same on `order_placement_snapshots` (the snapshot is "what was agreed at placement"; the note is part of it). Not on `addresses`.
- **Write-once.** The customer's note is set at placement and **never edited** — not by the customer, not by staff. Staff write their own notes as order events (`OrderNoteRecorder`, unchanged). So `Order::create()` takes it; `reviseDelivery()` and the editor do not touch it. (Q6.)
- **Validation:** `nullable|string|max:1000` + a NEW rule **`PlainMultilineText`**: like `PlainText` but allows `\n` and `\r` (a CRLF pair is kept as typed — nothing is rewritten, the `PlainText` posture); still refuses every other C0/C1 control character (tab included), the bidi override/isolate characters and invalid UTF-8. `PlainText` itself is unchanged. A value that is only whitespace becomes NULL (the `nullable` + trim convention of the other fields; Laravel's `TrimStrings` already trims request strings — to be confirmed, V for the middleware list at build time).
- **DB CHECKs** (MySQL/MariaDB, `supportsCheck()` pattern, names ≤ 64): `ord_customer_note_check`, `ops_customer_note_check`: `customer_note IS NULL OR TRIM(customer_note) <> ''`.
- **Display:** admin order view (its own block "Бележка от клиента" / "Customer note", escaped, line breaks kept — `e()` then `nl2br`), order JSON (`customer_note`), the confirmation email (the customer sees what they wrote; Q7) and the order-received page. Every surface escapes it: it is customer input.
- **Personal data:** a note may contain a name, a phone number or a health detail written by the customer. It is classified **`free_text`** for S16 (`storefront-design.md` "Free text that may contain a name"): the forget routine must clear it. It is never logged, never sent to a hook context, never copied into an order event.
- **Not now:** the merchant's own "note to customer" (a different feature), a note per line, rich text.

## 4. Schema change (one app-level migration, the 4f / 5g-2a shape)

`database/migrations/2026_10_14_000001_add_region_and_customer_note_to_addresses_orders_and_snapshots.php`:

| Table | Columns | CHECKs (name, length) |
|---|---|---|
| `addresses` | `region VARCHAR(100) NULL` | `addr_region_check` (17): `(region IS NULL OR TRIM(region) <> '') AND (delivery_type = 'street_address' OR region IS NULL)` |
| `orders` | `region VARCHAR(100) NULL`, `customer_note VARCHAR(1000) NULL` | `ord_region_check` (16), same expression; `ord_customer_note_check` (23) |
| `order_placement_snapshots` | `region VARCHAR(100) NULL`, `customer_note VARCHAR(1000) NULL` | `ops_region_check` (16); `ops_customer_note_check` (23) |

Existing rows stay NULL (honest: "not recorded"). Idempotent `up()` / `down()` (rule 6); run on the dev database and read back `SHOW CREATE TABLE` (CLAUDE.md). The Address and Order packages store both as plain validated strings; no package imports the settings reader (rule 9) — the policy is applied in the app layer (request validation, orchestrator).

## 5. The admin settings page

A new page **`CheckoutSettings`** under the existing "Settings" navigation group (bg «Поръчка», en "Checkout"), `Permission::SETTINGS_MANAGE`, with one section now: **"Region (state) field"**:

- "Default for all countries": select optional / required / hidden (bg «Показано, по желание» / «Показано, задължително» / «Скрито»), initial optional;
- "Exceptions by country": a repeater of (country select, mode select), each country at most once;
- a gray fact line: "Shown and optional for every country except: Bulgaria — hidden; United States — required."

A new page rather than a tab on `LocaleSettings`, because the checkout will collect more of these (the phone-call field named in `site-settings-design.md` §1, the invoice rules of C2). Audit: the save goes through the existing settings-write path and is recorded like the other settings pages (to be matched at build time to what Locale/Mail do — V at build).

## 6. API changes (additive)

- `POST /api/checkout`: `region` (street only, rules §2.4), `customer_note` (§3). Order JSON gains `region`, `customer_note` (also on an idempotent replay).
- Address API (create/update/JSON): `region` (street only, the same per-country rule; `customer_note` does not exist on an address).
- `POST /api/shipping/quote`: **no change** (region does not price).
- The storefront page gets the policy (`CheckoutRegionPolicy::all()`) as a small JSON data attribute, so the field shows, hides and becomes required as the country changes; the server rules of §2.4 are the truth either way.

## 7. Staged build

- **RN-1 — backend, admin, display (main coder).** Migration; Address/Order fields (+ `reviseDelivery` carries `region` like `city`; never `customer_note`); models, repositories, snapshot writer; `CheckoutRegionPolicy` + `RegionMode`; `PlainMultilineText`; checkout + address API rules; orchestrator; order/address JSON; admin order view (region + note block) and editor (region editable for a street address); `CheckoutSettings` page + lang bg/en + help text; confirmation email and order-received page show region and note (text only, escaped). *Tests:* the §2.4 table row by row for each mode (incl. hidden + value given → stored NULL); pickup + region → 422; address_id + region → 422; saved address without region under `required` still checks out; malformed setting → exception; settings page validation (unknown country, duplicate country, unknown mode); note: multi-line accepted byte for byte, tab/control/bidi refused, 1001 chars refused, blank → NULL; note write-once (editor and reviseDelivery leave it); DB CHECKs on MySQL; migration idempotent; email and order-received page escape the note (`<script>` shows as text); replay returns both. *Must not touch:* shipping (quote, hash, zones), storefront checkout form and JS (C1b), sandbox views.
- **C1b — the storefront form (main coder, already planned).** Adds the region input (policy-driven show/hide/required as the country changes) and the note textarea (with a live "x / 1000" counter), together with the new delivery choice (5g-2b `all` quote, dropdown of kinds) and D4's test (switch address → office → another courier → locker, then order: the order holds only the last choice and no leftover field of another kind).

## 8. Open questions, with my recommendation

- **Q1 Region for a pickup point?** *Recommend:* no — street addresses only (§2.1).
- **Q2 A region sent for a `hidden` country.** Refuse (422) or ignore? *Recommend:* ignore and store NULL — only a stale page or a script sends it, and storing it would show the merchant a value they chose not to collect; a 422 on a field the customer cannot see would be a dead end.
- **Q3 Is the default mode selectable,** or always "optional"? *Recommend:* selectable (a shop selling only in the US wants "required" everywhere), initial "optional".
- **Q4 Lengths.** *Recommend:* region 100, note 1000 characters.
- **Q5 A saved address without a region where the rule is now `required`.** *Recommend:* still usable (§2.4).
- **Q6 Can staff edit the customer's note?** *Recommend:* no — it is the customer's words; staff add their own notes as order events.
- **Q7 Is the note shown to the customer in the email and on the order-received page?** *Recommend:* yes, as "Your note".
- **Q8 Labels.** *Recommend:* bg «Област» / en "Region" now; per-country labels ("State") with the per-country list later.

## 9. What was not verified when this was designed

This is a design: no code, migration or test was written or run. Not verified: whether `TrimStrings` runs on the `api` group (§3, decides "blank → NULL" in practice), how the existing settings pages record an audit entry (§5), and the exact line layout of each address surface (§2.5). The Bulgarian labels are drafts for the owner.
