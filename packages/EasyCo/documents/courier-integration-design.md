# Courier Integration Design — Econt, Speedy, BOX NOW: pickup points, the checkout picker, shipments and waybills

**Status:** DESIGN ONLY (COUR-0, 2026-11-25). Nothing here is built. No code, migration or test was written for it.
**COUR-0.1 (2026-11-25):** the architect's corrections and the owner's answers to Q1–Q8 are applied in place (§13.1 now lists decisions; launch = 1a → 1b → 2a → 2b → 3a).
**Builds on:** `shipping-domain-design.md` §6 (the three carrier contracts, `CarrierRegistry`, `CarrierCallGuard`, the label idempotency obligation §6.4), §9.1.6 (the pickup-point data contract), §9.3.6–9.3.7 (the pickup kind and the `PickupKindSource` seam built for this stage), `order-lifecycle-design.md` (the six statuses, `OrderStatusChanger`), `mail-design.md` §6.4 (the shipped mail, M3), `security-p8d.md` (`CspSources`, `SecurityHeaders`), `mail-design.md` §2 (the encrypted write-only secret pattern), `acc-4-gdpr-design.md` (anonymisation), `storefront-cache-contract.md`.
**Supersedes:** shipping §9.1.11 **O7** ("bind the pickup `carrier_code` to a method's courier in the courier-map design"), decided here in §3.2.
**Origin:** the architect's brief COUR-0 (2026-11-25), built from the owner's two WordPress plugins (the owner's own «Custom Speedy & Econt Office Lookup» v3.4 and the official BOX NOW plugin v3.0.0), the couriers' public documentation and the owner's decisions.

**Markers.** **V** = verified by reading the repo (file given). **D** = verified in the courier's public documentation on 2026-11-25. **P** = observed in production (the owner's live WooCommerce shop), still to be checked against the courier's docs. **(verify)** = not confirmed; it is either a question to the courier (§13.2) or must be checked by the coder against a test account before the stage that needs it. **I** = my inference or recommendation.

**Not a port.** The plugins are examples of function and flow. The BOX NOW plugin is GPLv2: **no code from it enters EasyCo**. Every adapter is written from the couriers' API documentation. The coder of COUR-3 works from this document and BOX NOW's docs, not from the plugin's source.

---

## 0. In plain words (for the owner)

- **Each courier is an extension that is switched off until you switch it on**, separately for Econt, Speedy and BOX NOW, on a new admin page «Куриери». A shop with a courier switched off has no cost from it: no nightly download, no script in the checkout, no request to the courier.
- **Offices and lockers are kept in our own database** and refreshed every night (plus a «Обнови сега» button). The checkout never asks the courier live. So it is fast, and it keeps working when a courier's site is down.
- **In the checkout the customer chooses the settlement, then the office or locker from a list.** Next to the list is a button «Избери от картата», which opens the courier's own map. The map is loaded only when the button is pressed. On a phone it fills the screen. The choice from the map comes back into the same list.
  - **Speedy's map:** we first try Speedy's official map (widget v2, over https). If it does not work in a real browser, Speedy has the list only until Speedy answers. We do not build our own map.
- **What the customer picked is checked on our side when the order is placed:** the office must exist, be open and belong to that courier and that kind (office or locker). Its name and address come from our list, not from what the browser sends.
- **Waybills («товарителници») work as in your shop today.** On the order page, the BOX NOW block shows the locker and the warehouse and three buttons «Създай ваучер (Малко / Средно / Голямо)». One click creates the voucher. Then a green button with the voucher number opens the label, and a red one cancels it. «Промени» lets you change the locker, warehouse, weight and (for a prepaid order) the number of parcels. Econt and Speedy get one button «Създай товарителница» with the same pattern. Many orders at once are possible from the orders list. A double click never creates two waybills. **Nothing is created automatically:** you create every voucher by hand, as today.
- **Order of work:**
  - **Launch:** the courier base and the BOX NOW lockers, then the settlements and the Econt / Speedy offices, then the checkout picker, then the maps, then BOX NOW vouchers (create, print, cancel, bulk), so BOX NOW works as today and the customer can choose an Econt or Speedy office.
  - **Right after launch:** BOX NOW's automatic statuses (webhooks).
  - **After that:** Econt and Speedy waybills, then automatic statuses and tracking links, then live prices from the courier and the cash-on-delivery reconciliation.
- **What I could not confirm and must ask the couriers** (§13.2):
  - Speedy: how its map hands back the chosen office (we test v2 ourselves first).
  - Econt asks integrators to use a «custom» map address, and does not say how to get one.
  - BOX NOW's webhook signature scheme.

---

## 1. What the code says, and where it disagrees with the brief

| # | The brief says | The code says (V) | Consequence in this design |
|---|---|---|---|
| 1 | "a courier-integration contract (interface) with three adapters" (§6.1) | **The contracts already exist** in `packages/EasyCo/Shipping/src/Contracts`: `ShippingRateProvider::quote(ShippingContext, CallBudget)`, `PickupPointProvider::pickupPointsIn(country, settlement, CallBudget)`, `ShipmentLabelProvider::createLabel(ShipmentRequest)`, plus `CarrierRegistry`/`CarrierRegistration`/`CarrierCapability` (named container keys `shipping.carrier.<code>.<rate\|pickup\|label>`), `app/Services/ShippingProviderResolver.php`, `app/Services/CarrierCallGuard.php` and the idempotency obligation (shipping §6.4). They have zero implementations. | This design **extends** them rather than adding a parallel set (§2.2). `PickupPointProvider` is a *live, per-settlement* call. That contradicts the owner's rule that the checkout reads only a local table, so it is replaced by a *catalogue* contract used by the nightly sync. It has no implementation, so nothing breaks. |
| 2 | "the P8 design's `CspSources` registry", a CSP `frame-src` "on the checkout only" | The document is `security-p8d.md`. `App\Security\Csp\CspSources` is **one process-wide registry** for every storefront page, and the CSP is sent **report-only**. `SecurityHeaders` also sends **`Permissions-Policy: … geolocation=() …`**, which is **enforced** on every storefront page, the checkout included (`app/Storefront/Support/SecurityHeaders.php:27`). | (a) `CspSources` gains a **route-scoped** source set so a map origin is added to the checkout only (§4.6). (b) **Without a change, the couriers' «use my location» cannot work inside their iframes**, whatever `allow="geolocation"` says: a page with `geolocation=()` cannot delegate it. The checkout gets its own Permissions-Policy that delegates geolocation to the enabled map origins only (§4.6). |
| 3 | "outbound HTTP guard / allowlist", "`security-ideas` item 7 (SSRF: one HTTP client with a host allowlist)" | **No such client exists.** The only outbound call in `app/` is `VarnishPurger` (`Http::timeout()`). `security-ideas-2026-10-25.md` is named in `roadmap-v1.md` but **is not in the repo**. | COUR-1 builds the one client (§10.2). It is the first one; it becomes the pattern. |
| 4 | the method model has "courier + delivery type" | `shipping_methods.courier` is **free text** (a display/grouping name, stage 5f). `shipping_methods.carrier_code` exists but the domain allows it **only for kind `CARRIER`** (`ShippingMethod.php:376`, `carrierCodeNotAllowed`). The storefront sends the courier's **display name** as the order's `carrier_code` (`resources/js/storefront-checkout.js:1535`). `addresses.carrier_code` is free text ≤ 255. | §3.2: `carrier_code` becomes "the integrated courier" on **any** kind (an invariant relaxed, existing tests listed). For an integrated method the server, never the client, writes the order's `carrier_code`. |
| 5 | "the order stores a shipping snapshot (check its fields)" | `orders` carry `shipping_minor`, `shipping_method_name`, `shipping_method_code` (= method id), `shipping_courier`, `shipping_delivery_type`, `shipping_service_code`, `delivery_type` (`street_address\|pickup_point`), `carrier_code`, `pickup_point_reference`, `pickup_point_name`, `pickup_point_address`, `pickup_point_kind`, `settlement`, `parcel_weight_grams` (MEAS-1, `2026_11_13_000001`), **`tracking_number` (a column no app code writes today**: shown in the admin and the account area only; it is **not a field of the `Order` aggregate**: `packages/EasyCo/Order/src` never mentions it. It was added for order-editing-design §3 E6, which also added `OrderEventType::TRACKING_RECORDED`, still without a writer), `recipient_email` (ACC-3c: delivery mails only). The same fields are in `order_placement_snapshots`. | **No new order column is needed for COUR-1…3.** `tracking_number` gets its one writer, an app-layer `OrderTrackingRecorder` (§6.6). |
| 6 | the order becomes «completed» (BOX NOW plugin auto-create) | EasyCo has **no «completed»**: `placed, confirmed, shipped, delivered, cancelled, refunded` (`OrderStatus.php`). `OrderStatusChanger::ship()` takes no tracking number. | Moot: **no auto-create** (Q3, decided). Vouchers are created by hand (§6.4). |
| 7 | "M3 status mails" | **M3 is not built.** Only the confirmation mail exists. `OrderMailRecipients::DELIVERY_MAILS = ['order.shipped', 'order.tracking']` reserves the keys. | The tracking link is provided by the adapter (§8.3). The mail itself stays M3's. |
| 8 | "Postcode → region lookup exists (find the dataset)" | `resources/data/bg-postcodes.php` (GeoNames, CC BY 4.0, **4359 postcodes**, `postcode → [[settlement, admin1 region code]]`) read by `App\Support\BgPostcodes` (with `normalise()`: prefix strip and Latin transliteration). **No municipality**, and several settlements may share one postcode. | Reused as the settlement backbone (§3.3). Same-name settlements are told apart by postcode + region, never by name alone. |
| 9 | "settings storage; encrypted like the other secrets" | V: `mail.smtp.password` is `Crypt::encryptString` in `site_settings`, **write-only** in the form, dropped from the Livewire snapshot in `dehydrate()`, "saved / unreadable" state from `MailConfigurator::secretState()`, no value in the journal (`app/Filament/Pages/Settings/MailSettings.php`, `mail-design.md` §2.1). | The courier credentials use exactly this pattern (§10.1). |
| 10 | "the «needs attention» list" | V: `App\NeedsAttention\NeedsAttentionSource`, sources tagged in `AppServiceProvider.php:110`. | Three new sources (§6.8, §3.5). |
| 11 | label idempotency | Shipping §6.4 obliges a label provider to use a carrier-side idempotency key **or** a lookup by our reference, **else refuse to run**. None of the three APIs is documented to offer either for creation (verify). | Strictly read, §6.4 would forbid all three adapters. §6.3 replaces it with an equally safe rule: the attempt is written *before* the call, never retried automatically, and an unknown outcome is resolved by staff. **§6.4 must be amended when COUR-3 is built**; this is flagged, not silently changed. |

---

## 2. Architecture

### 2.1 Where it lives

| Layer | Where | What |
|---|---|---|
| Courier-neutral contracts and value objects | `packages/EasyCo/Shipping` (exists) | `CarrierRegistry`, the contracts of §2.2, `PickupPoint` (extended), `CourierHttp` (a port: the adapters' only way out) |
| **Shipments domain** (new package) | `packages/EasyCo/Shipments` | `Shipment` (aggregate), `Parcel`, `ShipmentStatus`, `ShipmentEvent`, repository contracts, `Persistence/Eloquent`. Pure PHP (rule 1). It references the order **by id string** (rule 9), never by importing `Order`. This is the owner's queued idea: "waybills, not invoices". |
| One package per courier | `packages/EasyCo/CourierBoxNow`, `packages/EasyCo/CourierEcont`, `packages/EasyCo/CourierSpeedy` | The adapters: request building, response mapping, the map payload parser, status mapping, hosts, tracking URL. They depend on `Shipping` (+ `Shipments` contracts) **only**, and call out only through `CourierHttp`. No Laravel facade, no settings read, no Hook. So each can be unit-tested with a fake `CourierHttp` and recorded responses. |
| App glue | `app/Couriers/` | `CourierSettings` (+ credentials vault), `AllowlistedCourierHttp` (the `CourierHttp` implementation), the sync command and job, the point / settlement readers, the checkout endpoints, `ShipmentService`, the webhook controller, the status poller, needs-attention sources. Filament: the «Куриери» settings page, the order-page actions, the bulk action, the «Пратки» list. |

**Why per-courier packages and not `app/Couriers/BoxNow`** (I): the owner wants "an extension". A package with its own service provider is the unit that can be left out of a build entirely, and the precedent is `EasyCo\Payment`'s adapters. The cost is three `composer.json` files and path repositories, as for every other EasyCo package.

### 2.2 The contracts (sketch; the existing ones change only where noted)

```php
// Shipping package. REPLACES PickupPointProvider (no implementation exists; it was live per settlement).
interface PickupPointCatalogue                       // capability key: shipping.carrier.<code>.pickup
{
    /** Every point the courier lists for the country, for the nightly sync. Never called by the checkout. */
    public function allPoints(CourierCredentials $credentials, string $countryCode): PickupPointBatch;
    /** The courier's settlements (Speedy sites, Econt cities); BOX NOW returns an empty list. */
    public function settlements(CourierCredentials $credentials, string $countryCode): array;
}

// Shipping package. WIDENS ShipmentLabelProvider (createLabel only) into the full waybill lifecycle.
interface ShipmentGateway                            // capability key: shipping.carrier.<code>.label
{
    public function create(ShipmentRequest $request, CourierCredentials $credentials): CreatedShipment;
    public function cancel(string $externalId, CourierCredentials $credentials): void;
    /** One PDF for one or many shipments of this courier (merged when the courier can; see §6.5). */
    public function labels(array $externalIds, LabelFormat $format, CourierCredentials $credentials): LabelDocument;
    /** @return list<CarrierStatusEvent> — polling couriers only; BOX NOW answers by webhook. */
    public function track(array $externalIds, CourierCredentials $credentials): array;
    public function trackingUrl(string $trackingNumber): ?string;
}

// Shipping package. The adapters' ONLY way to the network (implemented in app/ by AllowlistedCourierHttp, §10.2).
interface CourierHttp
{
    public function postJson(string $host, string $path, array $body, HttpCall $call): CourierResponse;
    public function get(string $host, string $path, array $query, HttpCall $call): CourierResponse;
}
```

- `ShippingRateProvider` **is unchanged** (live quotes, stage COUR-5 §9).
- `ShipmentRequest` (exists) gains the fields a real courier needs: parcels (`count`, `weightGrams`, `size` code), the origin reference (warehouse, office or address id), the payer, `declaredValueMinor`, `options` (open-before-payment, SMS), recipient e-mail. It loses nothing (§6.6 of the shipping doc already foresaw this).
- `PickupPoint` (exists) gains `kind` (`office|locker`), `code`, `postcode`, `latitude`/`longitude`, `courierSettlementRef` (Speedy `siteId`, Econt `city.id`) and `features` (card/cash COD, open-before-payment allowed). Already proposed in shipping §6.6 item 6.
- `CourierCredentials` is a value object built in `app/` from the vault at call time, **never** logged, never in an exception message (its `__toString` / `__debugInfo` hide every field).
- **The registry:** each courier package registers one `CarrierRegistration` (code, name, capabilities) in its service provider. Registration means *installed*, not *enabled*. **Enabled** is `couriers.<code>.enabled` in site settings (default **off**). `ShippingProviderResolver` treats an installed-but-disabled courier exactly as unknown (`not_configured`), so a disabled courier is unreachable from every path.

### 2.3 Core vs adapter

| Concern | Core (app + Shipping + Shipments) | Adapter (courier package) |
|---|---|---|
| Points cache | tables, sync job, diff, deactivation, circuit breaker, admin report | fetch and map one courier's list |
| Settlement mapping | the national backbone, the join rules, the ambiguity report | the courier's settlement ids and postcodes |
| Checkout picker | endpoints, list UI, dialog, CSP / Permissions-Policy, `postMessage` gate, server validation | map URL builder, map payload parser (`parseMapMessage(array): ?string` → external id or null), map origin |
| Shipments | aggregate, idempotency, locks, states, bulk, label storage, order effects, permissions, audit | request mapping, response mapping, error mapping to closed codes, label bytes |
| Status | event store, de-duplication, mapping to `ShipmentStatus`, scheduling, webhook endpoint | event mapping table, webhook signature verification |
| Security | HTTP allowlist, timeouts, retries, logging, secrets vault | declares its hosts per environment |

---

## 3. Pickup points and settlements

### 3.1 Tables (Shipping package; names short on purpose, rule 5)

**`shipping_pickup_points`** — one row per courier point, never hard-deleted (a point that disappears is deactivated: orders and shipments name it by reference, and the admin must still read what it was).

| Column | Type | Note |
|---|---|---|
| `id` | bigint PK | |
| `carrier_code` | varchar(32) | `ShippingCode` charset (`boxnow`, `econt`, `speedy`) |
| `external_ref` | varchar(64) | **the identifier the courier's shipment API takes** (§3.2 table) |
| `external_id` | varchar(64) NULL | the courier's internal id when it differs from the reference (Econt `id` vs `code`) |
| `kind` | varchar(10) | `office` \| `locker`, CHECK |
| `courier_type` | varchar(16) | raw: Speedy `OFFICE`/`APT`, Econt `office`/`aps`/`mps` (verify field), BOX NOW `apm` |
| `name` | varchar(255) | |
| `settlement_id` | FK NULL → `shipping_settlements` | NULL = not mapped (reported, still listed under the courier's own settlement name) |
| `settlement_name` | varchar(120) | as the courier spells it (display + fallback) |
| `postcode` | varchar(12) NULL | |
| `address_line` | varchar(255) | |
| `latitude`, `longitude` | decimal(9,6) NULL | |
| `features` | json NULL | e.g. `{"cod_cash":true,"cod_card":false,"obpd":true,"max_weight_g":…}` |
| `opening_hours` | json NULL | display only |
| `is_active` | bool | |
| `first_seen_at`, `synced_at`, `deactivated_at` | timestamps | |

Unique `spp_carrier_ref_uq (carrier_code, external_ref)`. Index `spp_lookup_idx (carrier_code, kind, is_active, settlement_id)`.

**`shipping_settlements`** — the courier-neutral list the customer searches.

| Column | Note |
|---|---|
| `id`, `country_code` (2), `name` (120), `name_key` (120, `BgPostcodes::normalise()`: lower case, prefix-free, Latin) | |
| `region_code` (8) | GeoNames admin1 (`SOF`, `PDV`, …), the region names from `App\Support\BgRegions` |
| `postcodes` | json list (Sofia has many) |
| `speedy_site_id`, `econt_city_id` | bigint NULL each: the courier's settlement id when matched |
| `source` | `postcodes` \| `courier` (a courier settlement that matched nothing becomes its own row, flagged) |

Unique `ss_identity_uq (country_code, region_code, name_key)`, plus a split rule (§3.3).

**`courier_sync_runs`** (app): carrier, started/finished, outcome (`ok|partial|failed|refused_shrink`), counts (seen, new, changed, deactivated, unmapped), error code. It is the admin's sync report and the needs-attention source. No personal data.

**Size (I, to be measured in COUR-1):** low thousands of points per courier (offices plus lockers in Bulgaria), so under ~10,000 rows and a few MB in all. Settlements: ~5,000 rows (4,359 postcodes, grouped). The checkout's queries are indexed lookups of a few hundred rows at most.

### 3.2 What `pickup_point_reference` holds, per courier

| Courier | Reference = | Why | Kind from |
|---|---|---|---|
| BOX NOW | locker id (`boxnowLockerId`; the locker list's id field) | `destination.locationId` in `delivery-requests` | always `locker` |
| Econt | office **code** (`code`) | `receiverOfficeCode` in `LabelService` (D). The office locator returns `{id, code, name, address}` (D). | the office's type field: `aps`/`mps` → `locker`, else `office` (verify the field name in `getOffices`) |
| Speedy | office **id** | `recipient.pickupOfficeId` (D) | `type`: `APT` → `locker`, `OFFICE` → `office` (D: `location/office` filters `officeType` `["OFFICE"]` / `["APT"]`, `api_examples.php`) |

**Decision (closes O7 of shipping §9.1.11):** `shipping_methods.carrier_code` becomes **"the integrated courier of this method"**, allowed on **every** kind, and still required for `CARRIER`. When it is set and the courier is enabled:
- the checkout shows the picker for that method's pickup kinds;
- the order's `carrier_code` is **the method's** code, written by the server; a client value is ignored for an integrated method;
- `pickup_point_reference` is required.

A method with no `carrier_code`, or whose courier is disabled, behaves **exactly as today**: free text, `pickup_point_address`. The admin method form gets a Select «Интеграция с куриер» listing enabled couriers only, empty by default. The free-text `courier` (the group name) stays: it is display. **Existing-test impact:** the tests that assert `carrierCodeNotAllowed` for FLAT / FREE / PER_CLASS change expectation. They are listed in COUR-2's report, never adjusted silently.

### 3.3 Settlement mapping

The owner's plugin joined a national postcode file with the couriers' lists and collapsed Sofia to 1000. Its weak point was matching by name + postcode only. The rules here:

1. **Backbone:** `BgPostcodes` rows grouped by `(region_code, name_key)`, one settlement each, with all its postcodes. "Град София" with dozens of postcodes is one row (the plugin's collapse, done generally).
2. **Speedy sites** (`location/site` for country 100; verify the path and its `postCode`, `region`, `municipality` fields) and **Econt cities** (`getCities` for `BGR`: `id`, `name`, `postCode`, `regionName`; D for the service, verify the fields) are matched in this order:
   - (a) the courier's postcode is in the row's postcodes **and** `name_key` equals → match;
   - (b) else, `name_key` and region equal, and exactly one row → match;
   - (c) else **no match**: a `source = courier` row is created and reported.
   **Never fuzzy, never by name alone across regions.**
3. **Split rule:** if one backbone row matches **two different** Speedy site ids (or two Econt city ids), it really is two settlements that share a name in one region. The row is split by postcode and the split is reported.
4. **BOX NOW lockers** carry a postcode and a city name (verify in `apms_bg-BG.json`) and are mapped to a settlement with the same rules (a), (b). Unmapped lockers are still selectable under their own city name.
5. Points are mapped to settlements through the courier's own settlement id (Speedy `siteId`, Econt `city.id`), not through their address text.

The checkout's settlement search shows `Name (обл. X)` whenever two rows share a name, so the customer can tell them apart. The admin sync report lists unmapped and split rows. **I:** this has no effect on zone matching (§4 of the shipping doc), which still uses the settlement **name** through `SettlementNameNormalizer`.

### 3.4 Sync

- **Command** `couriers:sync {carrier?} {--settlements}`. It is scheduled **nightly at 03:40** (`routes/console.php`, next to the existing 02:10–03:20 jobs), `withoutOverlapping()`, **only for enabled couriers**: a disabled courier is not scheduled at all. Admin «Обнови сега» dispatches the same job for one courier (permission `shipping_manage`, at most once per 5 minutes per courier).
- **Steps per courier:**
  1. Fetch everything through `CourierHttp` (BOX NOW: the public locker JSON, no credentials; Econt: `getOffices` with `countryCode: BGR`, **no credentials** (P: the owner's plugin calls it so on production; verify against the docs); Speedy: `location/office` with `countryId: 100` (D: it also filters `siteId`, `name`, `officeType`, `officeFeatures`; `https://services.speedy.bg/api/api_examples.php`), credentials). A response over the size cap (§10.2) is refused.
  2. Validate each item into a `PickupPoint`. A malformed item is skipped and counted, and never stops the run.
  3. **Circuit breaker:** if the valid count is under **80 %** of the currently active count, the run is `refused_shrink`. Nothing is deactivated, and it is reported. A courier that returns a truncated or empty list must never empty the checkout.
  4. Upsert by `(carrier_code, external_ref)`, in chunks of 500, in short transactions. Rows not seen are deactivated (`is_active = 0`, `deactivated_at`).
  5. Record the run; purge the cache tags of the public read endpoints (§4.3).
- **Failure:** keep the last good data (the table is only changed by a successful run). After **2 consecutive failed nights**, or the last success being older than **48 h**, the courier appears in «Нуждае се от внимание» (`CourierSyncStaleSource`): one row per courier, not one per night. The checkout keeps working on the old list. An office closed in the meantime is caught when the waybill is created (§6.4).
- **Settlements** are rebuilt with `--settlements`, weekly (Sunday 04:00) and on the first enable. They change rarely.

### 3.5 What the sync never does

It never deletes a point or a settlement row. It never calls a courier from a web request. It never writes a file per request (the owner's plugin's `csl_cache_city` AJAX writer). It never stores credentials in the run record.

---

## 4. Checkout

### 4.1 Fit with the existing method model (no twin methods)

The courier is the method's `carrier_code` (§3.2). Where it goes (address / office / locker) is still the method's scope and the customer's kind choice (5g, `destination_scope`, `pickup_point_kind`). The free-from threshold and its kinds are unchanged. **One method «Еконт» with scope `any` serves address, office and locker.** The picker appears only for the pickup kinds of a method whose courier is integrated and enabled.

The quote response gains, per method, `integration: {carrier: "econt", map: true|false} | null`. It is additive, and the `shipping.quotes` filter may not change it (`changed_field`, as for the other row facts).

### 4.2 The picker (UX)

```
Доставка:  (•) Еконт   ( ) Speedy   ( ) BOX NOW
Къде:      [ До офис ▾ ]                        ← the existing kind dropdown (C1b)

Населено място  [ Плов|                    ]    ← combobox, settlements with points of THIS courier and kind
                  Пловдив (обл. Пловдив)
                  Пловдивци (обл. Смолян)

Офис            [ Търси по име или адрес   ]    ← filter box over the list below
                ( ) Пловдив Тракия — ул. …       ← radio list (≤ 8 visible, scrolls), or a native <select>
                ( ) Пловдив Център — бул. …         under 30 points
                [ Избери от картата ]            ← only when the courier's map is enabled
Избрано: Еконт офис «Пловдив Тракия», ул. …     ← aria-live summary
```

- **Settlement first** (the owner's current flow).
  - The combobox searches `name_key` by prefix (Cyrillic or Latin input, through the same `normalise()`). It returns 10 results, and only settlements that have an **active** point of the chosen courier **and** kind (Q5, decided).
  - When a street address was typed earlier, its city preselects the settlement if it matches exactly one row.
- **The list is the primary control:** fully keyboard- and screen-reader-usable, with the filter box narrowing it live. The map is an optional accelerator, never the only way.
- **Phone:** one column; the list is a native `<select>` on narrow screens if the count is ≤ 30 (the OS picker is the best phone UI), and the radio list with the filter box otherwise.
- **Choosing a point re-quotes** with the point's settlement (already required: "quote again after the customer chooses a point", shipping §6.8), because the settlement can move the zone and the price. The existing changed-price confirmation (C1b rule A) applies.
- **Without an integration** (method without `carrier_code`, or the courier disabled): the existing free-text field «Офис или автомат» exactly as today.

### 4.3 Read endpoints (public, sessionless, cacheable)

- `GET /api/shipping/settlements?carrier=econt&kind=office&q=плов` returns at most 10 `{id, name, region}`.
- `GET /api/shipping/pickup-points?carrier=econt&kind=office&settlement=123` returns `{ref, name, address, kind, lat, lng}` for the active points.
- `GET /api/shipping/pickup-points/{carrier}/{ref}` returns one point. The map path uses it when the map returns a point outside the loaded settlement.

They carry no cart and no identity, so they are **the same for every visitor**. They are served `public, max-age=300`, tagged `pickup-points:<carrier>` and purged by the sync (the existing tag purge, `storefront-cache-contract.md`). A disabled courier answers 404 for all three. There is a named limiter `pickup-points` (60/min per IP) in `App\Http\ApiRateLimits`. `q` is 1–40 characters of plain text.

### 4.4 The map dialog

- **Nothing third-party loads before the click.** The button is rendered only when the courier's map is enabled (a per-courier setting, default on once the courier is enabled; for Speedy only if COUR-2b's v2 check passes, Q1). On click, a `<dialog>` is opened and **only then** is the `<iframe>` created with `src` = the adapter's map URL:

  | Courier | Map URL (D unless marked) | Parameters |
  |---|---|---|
  | BOX NOW | `https://map.boxnow.gr/iframe.html` | `partnerId` (required by the global widget doc), `countryCode=bg`, `language=bg`, `gps=yes`, `autoselect=no`, `zip=<settlement postcode>` |
  | Econt | `https://officelocator.econt.com/` (staging `staging.officelocator.econt.com`) — **or the shop's «custom Econt URL»** (verify) | `shopUrl` (our site URL), `officeType` = `office` or `aps` (verify `mps`), `city`, `lang=bg` |
  | Speedy, **first choice (Q1)** | widget **v2**: `https://services.speedy.bg/office_locator_widget_v2/office_locator.php` (demo `…/office_locator_widget_v2/demo.html`) | `lang`, `showAddressForm`, `showOfficesList`, `selectOfficeButtonCaption`. **COUR-2b verifies in a real browser:** it loads over https inside an https page (no redirect to `http://`), how it returns the chosen office, the sending origin, and whether lockers (APT) can be filtered |
  | Speedy, alternative | widget v3: `https://services.speedy.bg/office_locator_widget_v3/` | not used while its URL redirects to plain `http://` (an https checkout would block it as mixed content); parameters unknown (Q-SP1) |

  **If v2 fails the check, Speedy stays list-only** (the button is not rendered) until Speedy answers Q-SP1. No own map is built (Q1, decided).

- **Layout:**
  - **Phone (< 640 px):** full screen (`100vw × 100dvh`), a 56 px header with the title («Избери офис на Еконт») and a 44 px «Затвори» button.
  - **Desktop:** 90vw × 80vh, at most 1100 × 760.
  - **Behaviour:** `iframe title` set; Esc and the close button return focus to «Избери от картата»; the page behind is `inert`.
- **The choice coming back:** one `message` listener, installed when the dialog opens and removed when it closes.
  1. Accept a message only if `event.source === iframe.contentWindow` **and** `event.origin` is exactly the adapter's declared map origin; otherwise ignore it silently.
  2. The courier's parser extracts **only the external reference** from the payload. BOX NOW: `boxnowLockerId` (**P**: the map's `postMessage` carries `boxnowLockerId` and `boxnowCountry`, which the plugin's listener reads; the public doc names the id only for the legacy v5 callback, so verify against the docs). Econt: `event.data.office.code` (D). Speedy: as found by COUR-2b's v2 check. The parser rejects anything that is not a short `[A-Za-z0-9_-]` string.
  3. **The name and address in the message are never used.** The page finds the reference in the loaded list, or asks `GET /pickup-points/{carrier}/{ref}`. Found and active: it selects it in the list (switching the settlement if needed), closes the dialog, announces it in the live region and re-quotes. Not found: the dialog stays open with «Тази точка не е налична за доставка. Изберете друга.».
  4. BOX NOW's close message `boxnowClose` closes the dialog (**P**: sent on close and handled by the plugin's listener; verify against the docs).
- **Failure:** if the iframe does not load in 10 s, or the courier sends nothing, the list still works. The dialog says «Картата не се зареди. Изберете от списъка.».

### 4.5 What the order stores, and the server check at placement

The request for an integrated method carries `shipping_method_id`, the handle, `delivery_type=pickup_point` and **`pickup_point_reference`**. `pickup_point_name`, `pickup_point_address`, `pickup_point_kind`, `settlement` and `carrier_code` are **prohibited** for it: a value is refused (422), never trusted. This is the flip foreseen in shipping §9.3.7.

**`RecordDerivedPickupPointSource`** replaces `ClientClaimedPickupKindSource` (one binding in `AppServiceProvider.php:39`). It runs once, before the transaction (step 0, as today), and loads the point by `(method.carrier_code, reference)`. It refuses:

| Reason (422) | When |
|---|---|
| `pickup_point_unknown` | no such point for this courier |
| `pickup_point_inactive` | deactivated by a sync |
| `pickup_point_kind_not_served` | the point's kind is not a kind the method serves (`servesKind`) |
| `pickup_point_courier_disabled` | the courier was switched off since the quote |

The point supplies `kind`, `name`, `address_line` → `pickup_point_address`, and `settlement_name` → `settlement`, and the order snapshots them (shipping §9.1.6: the order still reads correctly after the courier renames or closes the office). The quote hash binds the kind, as today. A derived kind that differs from the quoted one gives `shipping_price_changed` / `shipping_quote_expired`, unchanged. **No courier is called at placement** (a local read only). A saved pickup address is re-validated the same way; a historical one that no longer resolves is offered for re-selection, not placed.

### 4.6 CSP and Permissions-Policy (checkout only)

- `CspSources` gains `allowOn(string $scope, string $directive, string $origin)` and `policy(?string $scope = null)`. `SecurityHeaders` passes the scope `checkout` on `storefront.checkout` only.
- At boot, each **enabled** courier package adds its map origin to `frame-src` in scope `checkout` (BOX NOW `https://map.boxnow.gr`, Econt `https://officelocator.econt.com` or the custom origin, Speedy `https://services.speedy.bg`). The enabled flags are read from the settings cache that boot already uses (I: verify that no extra query per request is added; the cache contract test pins it).
- **Permissions-Policy on the checkout:** `geolocation=(self "<each enabled map origin>")`, everything else unchanged. Every other storefront page keeps `geolocation=()`.
- The CSP stays report-only; the `frame-src` entries are there for the day it is enforced.
- **Cache:** the checkout is `private, no-store`, so a per-route header is safe. The headers depend only on settings, never on the visitor.

### 4.7 Accessibility checklist

The settlement combobox follows the ARIA combobox pattern (as the checkout's region field does). The list is radio buttons with visible labels. The map button names the courier. The dialog traps focus and restores it. The chosen point is announced (`aria-live=polite`). Error messages are tied to the field (`aria-describedby`, the existing `sf-field-error`). Targets are ≥ 44 px. Nothing depends on the map.

---

## 5. Admin: «Куриери» settings page

One page (permission `settings_manage`), one section per installed courier:
- **Enable:** «Включен», default off.
- **Environment:** «Среда» test / production.
- **Credentials:** write-only (§10.1).
- **Map:** «Карта в касата» on / off.
- **Courier-specific fields:**
  - BOX NOW: partner id (the map), client id / secret (the API).
  - Econt: user / password (the API), the custom office-locator URL.
  - Speedy: user / password, client id (sender).
- **«Провери връзката»:** one harmless authenticated call (BOX NOW `auth-sessions`; Econt a nomenclature call with auth; Speedy a client call; verify which). It shows OK or the sanitised error code, never the response body.
- **Sync:** the last sync run, «Обнови сега», and the unmapped-settlements report.
- **Sender profile** (used by waybills, §6/§7): name, phone, e-mail, and per courier the default origin (BOX NOW warehouse from `GET origins`; Econt sender office code or address; Speedy drop-off office or the contract's pickup address).
- **Defaults:**
  - label format: **A4 by default** for every courier (Q7: an office Brother printer; Econt and Speedy labels take half an A4 sheet). A6 as the alternative; Speedy also `A4_4xA6` (four labels on one A4 sheet), never the default; BOX NOW the formats it offers (verify, Q-BN5);
  - default parcel size and weight rule;
  - who pays the courier;
  - open-before-payment;
  - SMS;
  - «Маркирай като изпратена при създаване», **default off** (Q2, §6.4).

Switching a courier **off** stops its schedule, hides its picker (methods fall back to free text), and removes its CSP and Permissions-Policy entries. It keeps its points, shipments and history.

---

## 6. Shipments domain (`packages/EasyCo/Shipments`)

### 6.1 Tables

**`shipments`**

| Column | Note |
|---|---|
| `id` | ULID (string 26) |
| `order_id` | varchar(40), the order's id, **no FK into another package's table** (rule 9; the app layer guards existence) |
| `carrier_code` | varchar(32) |
| `attempt` | smallint ≥ 1 |
| `reference` | varchar(64): `"<orderId>#<attempt>"` (the existing `ShipmentRequest` reference) |
| `state` | `requesting` \| `created` \| `cancel_requested` \| `cancelled` \| `failed` \| `unknown` (§6.3) |
| `carrier_status` | `pending` \| `in_transit` \| `at_point` \| `delivered` \| `returning` \| `returned` \| `lost` \| null (§8) |
| `external_id` | varchar(64) NULL: BOX NOW delivery-request id, Econt `shipmentNumber`, Speedy shipment id |
| `tracking_number` | varchar(64) NULL |
| `service_code` | varchar(64) NULL |
| `destination_kind` | `address` \| `office` \| `locker` |
| `destination_ref` | varchar(64) NULL: the pickup reference at creation (may differ from the order's after an admin change, §6.4) |
| `origin_ref` | varchar(64) NULL: warehouse / office / address id |
| `cod_minor` | bigint NULL (null = prepaid) |
| `currency` | char(3) |
| `declared_value_minor` | bigint NULL |
| `weight_grams` | int |
| `parcel_count` | smallint |
| `payer` | `sender` \| `recipient` \| `third_party` |
| `options` | json: size codes, open-before-payment, SMS. No personal data. |
| `label_path` | varchar(255) NULL: on the **private** disk, never public |
| `created_by` | staff id NULL (NULL = a bulk job or a future automatic rule; none is built, Q3) |
| `cancelled_by` | staff id NULL |
| `request_snapshot`, `response_snapshot` | json NULL, redacted (§10.4), pruned (§11) |
| `error_code` | varchar(64) NULL: a closed list per adapter (`invalid_point`, `auth_failed`, `timeout`, `rejected`, …) |
| `error_at` | timestamp NULL |
| `created_at`, `updated_at`, `cancelled_at` | |

Unique `shp_order_attempt_uq (order_id, attempt)`; unique `shp_carrier_ext_uq (carrier_code, external_id)`; index `shp_open_idx (carrier_code, state, carrier_status)`.

**`shipment_parcels`:** `id`, `shipment_id` FK (cascade on the aggregate's own table, inside the package), `seq`, `external_parcel_id`, `tracking_number`, `size_code`, `weight_grams`, `carrier_status`, `last_event_at`. Unique `(shipment_id, seq)`.

**`shipment_events`** (append-only):
- columns: `id`, `shipment_id`, `parcel_id` NULL, `source` (`webhook|poll|staff|system`), `carrier_event` (varchar 64, raw code), `mapped_status`, `occurred_at`, `received_at`, `dedupe_key` (varchar 128), `cod_collected_minor` NULL (for R4b), `detail` (varchar 255, no personal data);
- unique `shpe_dedupe_uq (dedupe_key)`.

Historical identity (rule 4): a shipment is never deleted. A cancelled one stays as history and a new attempt is a new row.

### 6.2 The aggregate (sketch)

`Shipment::request(orderId, attempt, carrier, spec)` → `requesting`.
- `markCreated(externalId, tracking, parcels)` → `created`.
- `markFailed(code)` → `failed` (nothing was created at the courier; safe to try again with attempt + 1).
- `markUnknown(code)` → `unknown` (we cannot tell; §6.3).
- `requestCancel()` → `cancel_requested`, then `markCancelled()`.
- `recordCarrierEvent(event)` moves `carrier_status` forward only (§8.2).

Every transition validates the source state and **refuses rather than normalises**, as `Order` does.

### 6.3 Idempotency, locking, and the unknown outcome

1. **Lock:** `Cache::lock("shipment:order:<id>", 30)`; a second click while held gets «Пратката се създава…».
2. **Short DB transaction:**
   - if a shipment in `requesting | created | unknown | cancel_requested` exists for the order, **return it and stop**. A double click, a retry or a bulk re-run never creates a second waybill;
   - else insert attempt = max + 1 in `requesting` (the unique index is the last net);
   - commit.
3. **Outside any transaction** (shipping §6.3 / orchestration rule), call `ShipmentGateway::create()`. Our reference goes where the courier takes one: BOX NOW `orderNumber`, Speedy `ref1`, Econt a client reference field (verify).
4. Record the result:
   - **success** → `created` (+ parcels, tracking, label fetched and stored, §6.5);
   - **a definite refusal** (4xx with a validation error, or an auth failure before sending) → `failed` with the code;
   - **anything ambiguous** (timeout after sending, 5xx, a connection drop) → **`unknown`**.
5. **`unknown` is never retried automatically.** It appears in «Нуждае се от внимание» («Неизвестен резултат при създаване на пратка»). The staff member checks the courier's own portal (or, where the API offers a lookup by our reference, the button «Провери при куриера» does it) and resolves it in one of two ways:
   - «Създадена е» (types the waybill number; we fetch the label);
   - «Не е създадена» (→ `failed`, a new attempt allowed).

   This replaces shipping §6.4's "refuse to run" for couriers without an idempotency key. It has the same safety: no automatic double creation, ever. **§6.4 is amended in COUR-3** (flag, §1 row 11).

A new attempt after `cancelled` or `failed` is a **deliberate** staff action, never automatic.

### 6.4 The order-page block: one click, and what is editable before creation

There is **no «Създай пратка» dialog.** The order page gets one block per integrated courier (the order's method's `carrier_code`), modelled on the owner's current one-click flow (screenshots of his shop).

**BOX NOW block, before creation:**

```
BOX NOW
Автомат:  «Пловдив — Тракия» · ул. … · ID 4711          (read-only)
Склад:    «Основен склад»                               (read-only)
[ Създай ваучер (Малко) ] [ Създай ваучер (Средно) ] [ Създай ваучер (Голямо) ]
Промени
Поръчка с наложен платеж: винаги 1 товарителница, за да се предотвратят дублирани заявки за плащане.   ← COD orders only
```

- **The three buttons** = BOX NOW compartment size 1 / 2 / 3 (60×45×8 / 17 / 36 cm). **One click creates the voucher** with that size and the defaults of the table below (weight, COD). Each click goes through §6.3: lock, the attempt row before the call, `unknown` never retried.
- **«Промени»** (a small link) opens the editable fields of the table below. The read-only lines show the result.
- **A cash-on-delivery order always has exactly one voucher:** there is no parcel-count field, and the block shows the shop's own sentence above. **A prepaid order** may have 1–10 parcels (set in «Промени»).

**After creation:**
- The three size buttons are **disabled** (greyed).
- A **green button showing the voucher number** opens the stored label (§6.5). It IS the print button; there is no separate «Печат». With several parcels: one green button per parcel, plus «Печат на всички» (the merged PDF).
- A **red «Анулирай ваучера»** asks for confirmation («Анулирането при BOX NOW не може да се върне») and then runs the cancel.
- **Q2 off (the default):** a button «Маркирай като изпратена» → `OrderStatusChanger::ship()` with its existing rules.
- **Q2 on** («Маркирай като изпратена при създаване» on for the courier): each size button creates the voucher **and** then marks the order shipped, **only if the creation succeeded** (`created`). A `failed` or `unknown` attempt never moves the order.

**After cancel:** the number leaves the order (§6.6), the size buttons are enabled again (a new click is a new attempt, attempt + 1), and the order history keeps who cancelled and when.

**Econt / Speedy block (COUR-4):** one button «Създай товарителница», with the weight and the parcel count shown above it (editable through «Промени»), and the same after-creation pattern: the green number = the label, the red cancel, «Маркирай като изпратена» while Q2 is off.

**What «Промени» edits, and the defaults a click uses:**

| Field | Default | Rule |
|---|---|---|
| Point (office / locker) | the order's | changeable with the same picker (list + map) as the checkout; the server check of §4.5 runs again. The new point goes to the shipment's `destination_ref`, and the order's delivery is revised through `Order::reviseDelivery()` (the existing operation, so name / address / kind follow the record). **V: `reviseDelivery()` is allowed only while `placed` or `confirmed`** (`Order::assertEditable()`); on a later status the point is read-only in «Промени» |
| Origin | courier default | BOX NOW: a warehouse from `GET origins`; Econt / Speedy: the sender office or address |
| Parcel count | 1 | prepaid: 1–10; **cash on delivery: always 1, no field** |
| Size | — | BOX NOW: the button pressed (1/2/3); Speedy / Econt: weight only, or dimensions when known |
| Weight | `orders.parcel_weight_grams` (MEAS-1); if null, the courier default rule (BOX NOW plugin: 1 kg + products) | > 0, ≤ the courier's maximum |
| COD amount | the order's **open** amount for a cash-on-delivery order (the pending payment's expected amount); 0 / prepaid otherwise | ≥ 0. A value different from the payment's expected amount needs a confirmation and is written to the order history |
| Declared value | off | ≤ goods value |
| Payer, open-before-payment, SMS | courier defaults | open-before-payment is refused for lockers (Speedy: OBPD not for APT; Econt: `payAfterTest` ignored for APS; D) |

The values set in «Промени» travel with the next click and are stored only in the shipment row that click creates (I: no separate draft record; a point change is the exception, it revises the order at once). After `created`, nothing is edited: the path is to cancel and create again (attempt + 1).

### 6.5 Labels and printing

- **One shipment:** the green number button (§6.4) opens the stored PDF. It is fetched once at creation, in the courier's configured paper format (A4 by default, Q7), and stored on the private disk (`shipments/<yyyy>/<mm>/<id>.pdf`), streamed to staff with `Content-Disposition: inline` and `no-store`.
  - BOX NOW: `GET parcels/{id}/label.pdf`.
  - Speedy: `POST print` with `parcels[{parcel:{id}}]` (the first parcel id is the waybill number) → the response is the PDF bytes (D, `api_examples.php`); `paperSize` `A4|A6|A4_4xA6` (D), `zpl` for A6 per a summary (verify).
  - Econt: `pdfURL` from the response, fetched through the allowlisted client (verify that the PDF host is in Econt's declared hosts).
- **Bulk:** from the orders list, «Създай пратки» for the selected orders. Each order goes through the same service (§6.3) in a queued job, in chunks of 20. The result: «Създадени 18, пропуснати 2 (вече има пратка), грешки 1 (…)». Then «Отпечатай етикети» with one PDF per courier:
  - BOX NOW `labels:search` (merged by the courier);
  - Speedy `print` with many parcels in one request (verify the per-request limit);
  - **Econt has no documented merged print:** one PDF per waybill, opened one after another (Q6, decided; revisit after Q-EC4).
- **Bulk size:** a bulk run uses each courier's default size and weight rule, and the COD rule of §6.4 (one voucher per COD order).
- **Reprint** is always the stored file. If the file is missing, it is fetched again; there is no new creation.

### 6.6 Effects on the order

- On `created`, in one app-layer step after the shipment is saved:
  - `orders.tracking_number` is set to the shipment's tracking number (**its first writer**, see «Who writes the number» below);
  - an `order_events` entry of the existing type `TRACKING_RECORDED` is written (`reason` = «Създадена товарителница BOX NOW № …»);
  - the hook `shipment.created` fires.
- **«Изпратена»:** the button «Маркирай като изпратена» (Q2 off, the default) or the size button itself (Q2 on, success only) runs `OrderStatusChanger::ship()`, so `order.status_changed` → the M3 shipped mail when M3 exists, with the tracking link from `trackingUrl()`.
- On `cancelled`: the tracking number is cleared only if it was this shipment's (**the number leaves the order**), the history gets an entry with who cancelled and when, `shipment.cancelled` fires, and **the order status is not moved back automatically**: staff decide.

**Who writes the number (B3, needs a decision in COUR-3a).** The brief's sketch was a new `Order::recordTracking(string)` on the Order aggregate. What the repo says:
- **V: `Order.php` is not frozen.** CLAUDE.md's «Do not touch» list names only `Product.php`, `Variation.php`, `VariationSignature.php` and the Catalog SIMPLE↔VARIABLE methods; `acc-4-gdpr-design.md` says "Order.php is not frozen". Adding a method needs no special approval under the repo's rules. The architect may still want the owner's yes, because the Order aggregate is history-bearing (rule 4).
- **V: `tracking_number` is not a field of the aggregate.** `packages/EasyCo/Order/src` never mentions it; the column was added by order-editing-design §3 (E6) and only read (admin, account, privacy export). So `Order::recordTracking()` is not a one-line method: it means a new aggregate field, its constructor and repository mapping.
- **V: order-editing-design §3 (E6) already designed the manual entry:** «entered or corrected only while `shipped`», each entry a new `TRACKING_RECORDED` event, the latest one shown. The enum case exists (`app/Enums/OrderEventType.php:70`); no writer does.
- **V: `reviseDelivery()` cannot carry it.** It has no tracking parameter and is refused outside `placed` / `confirmed`; misusing it for tracking would also re-run the delivery-field checks for a fact that is not a delivery change.

| Option | What | Cost |
|---|---|---|
| (a) **`OrderTrackingRecorder` in `app/Services`** — *recommended* | one app-layer writer, the same shape as `OrderNoteRecorder` / `PaymentReceiptRecorder`: in one `DB::transaction`, set `orders.tracking_number` (or clear it on cancel) and append a `TRACKING_RECORDED` event; the only caller besides a future manual-entry action is `ShipmentService` | no Order package change; matches how the column is treated today (outside the aggregate) |
| (b) `Order::recordTracking(?string)` | the field moves into the aggregate, with its own status rule | Order package change (field, constructor, repository), needs the approval above; justified only if the order's tracking rules should live in the domain |

**Status rule to reconcile (flag):** E6 allows tracking **only while `shipped`**, but a voucher is normally created while `confirmed` (Q2 off: «Маркирай като изпратена» comes after). The recorder therefore allows the courier-written number while `placed`, `confirmed` or `shipped`, and refuses it once `delivered`, `cancelled` or `refunded`; the manual E6 entry keeps its «only while `shipped`» rule. This widens E6 for the courier path and is stated here so order-editing-design §3 is updated in COUR-3a, not silently contradicted.

### 6.7 Permissions, audit, hooks

- **Permissions:**
  - create, cancel and print: `order_manage`;
  - courier settings and sync: `settings_manage` / `shipping_manage`;
  - resolving an `unknown`: `order_manage`.
  - No new permission is needed (I; the owner may want a separate «пратки» permission later).
- **Audit:** each create, cancel and resolve writes an activity-journal entry with the shipment id and carrier, never the recipient.
- **Hooks** (rule 10: app layer only, listed in the Hook Reference in the same commit):
  - actions `shipment.created`, `shipment.cancelled`, `shipment.status_changed`, all after commit;
  - filter `shipment.request` (a merchant extension may change the options or COD before sending, under the same closed-contract posture as `shipping.quotes`: it may not change the order, the courier or the destination).

### 6.8 «Нуждае се от внимание» sources

`ShipmentUnknownOutcomeSource` (any `unknown`), `ShipmentStuckSource` (`created` and no carrier event for 5 days; `returning`), `CourierSyncStaleSource` (§3.4).

---

## 7. Courier specifics

### 7.1 BOX NOW (launch)

| Step | API | Notes |
|---|---|---|
| Token | `POST /api/v1/auth-sessions` (`grant_type=client_credentials`, `client_id`, `client_secret`) | Cached in the app cache for `expires_in − 60 s`, under a key holding a hash of the client id. The token is a secret: never logged, never in a snapshot. |
| Warehouses | `GET /api/v1/origins` | Cached for 1 h; shown in the settings page and the order-page block («Склад», §6.4) |
| Create | `POST /api/v1/delivery-requests` | `orderNumber` = our reference, `invoiceValue`, `paymentMode` `cod`\|`prepaid`, `amountToBeCollected`, `allowReturn` (setting), `origin{contactName, contactNumber, contactEmail, locationId}`, `destination{contactName, contactNumber (E.164), contactEmail, locationId = locker}`, `items[{value, weight, compartmentSize}]`, **one item per parcel** (`compartmentSize` = the size button pressed, §6.4; a COD order sends exactly one item). Response `id` + `parcels[].id`. |
| Label | `GET /api/v1/parcels/{id}/label.pdf`; merged `POST /api/v1/labels:search` | the green number button; «Печат на всички» = the merged call. Paper formats offered: verify (Q-BN5); A4 by default if offered (Q7) |
| Cancel | `POST /api/v1/parcels/{id}:cancel` per parcel | The shipment is `cancelled` when every parcel is cancelled; a partial cancel is `cancel_requested`, shown as such |
| Points | public `https://locationapi-production.boxnow.bg/v1/apms_bg-BG.json` (stage: `locationapi-stage…`) | no credentials |
| Map | §4.4 | `partnerId`; returns `boxnowLockerId`, `boxnowCountry`, close = `boxnowClose` (P) |

- **Hosts:** the API host per environment is verified with BOX NOW (Q-BN1); the brief gives `https://{api host}/api/v1/`.
- **Phone:** already E.164 at the order (libphonenumber is installed, `composer.json:27`; I: verify that the stored order phone is E.164 for every path); a non-mobile number is refused before sending.
- **E-mail:** the order's e-mail is required by BOX NOW's destination.
- **No auto-create (Q3, decided):** the owner creates every voucher by hand, as today. It can be added later as a queued listener of the `order.status_changed` hook calling the same `ShipmentService` (idempotent by §6.3); it is **not built** in any stage here.
- **The UI:** the one-click block of §6.4 (three size buttons, green number, red cancel). **Admin locker change:** «Промени», with the same picker and BOX NOW map.
- **Webhooks:** §8.1.

### 7.2 Econt (after launch, COUR-4)

| Concern | API (D unless marked) |
|---|---|
| Host, auth | `https://ee.econt.com/services/` (docs show `http://`; we use https only, verify), demo `https://demo.econt.com/ee/services/`; HTTP Basic |
| Create | `LabelService.createLabel` `mode=create`; `validate` first is optional (I: run `validate` when «Промени» is saved, `create` on the «Създай товарителница» click). `createLabels` for bulk |
| Fields | sender client + `senderOfficeCode` or sender address; receiver client (name, phone, e-mail); `receiverOfficeCode` or receiver address; `packCount`, `shipmentType`, `weight`, `shipmentDescription`; services `cdAmount`, `cdType`, `cdCurrency` (COD), `declaredValueAmount`, `smsNotification` (boolean in ShippingLabelServices); `paymentSenderMethod` / `paymentReceiverMethod` |
| Open before payment | **`payAfterAccept`** («checked before payment») and **`payAfterTest`** («tested before payment»); `payAfterTest` is ignored for automatic stations (APS) and Econt Drive; the response flags `payAfterAcceptIgnored` |
| Response | `label.shipmentNumber` (= external id and tracking), `pdfURL`, `totalPrice`, `expectedDeliveryDate` |
| Cancel | `deleteLabels` |
| Status | `ShipmentService.getShipmentStatuses` (events, `cdCollectedAmount`) |
| COD payouts | `PaymentReportService` (R4b, §9.2) |
| Points / settlements | `NomenclaturesService.getOffices`, `getCities` |

### 7.3 Speedy (after launch, COUR-4)

| Concern | API |
|---|---|
| Host, auth | `https://api.speedy.bg/v1/`, `userName` + `password` in every JSON body (D). The body is built in the adapter, and the snapshot redactor removes both (§10.4). |
| Create | `POST shipment`: `recipient.pickupOfficeId` or an address; `service.serviceId` (e.g. 505, per settings); `additionalServices.cod{amount, processingType CASH\|POSTAL_MONEY_TRANSFER}`; `declaredValue{amount, fragile}`; `content{parcelsCount, contents, package, totalWeight, parcels[…]}`; `payment{courierServicePayer, declaredValuePayer}`; `ref1` = our reference |
| Open before payment | **`obpd{option OPEN\|TEST, returnShipmentServiceId, returnShipmentPayer}`** (D: `OPEN`, `returnShipmentServiceId: 505`, `returnShipmentPayer SENDER\|RECIPIENT\|THIRD_PARTY`; `TEST` and "not for APT" from the docs' summary, verify) |
| SMS | verify (no SMS field confirmed) |
| Label | `POST print` (`paperSize A4\|A6\|A4_4xA6`, `parcels[{parcel:{id}}]`; the first parcel id is the waybill number) → the response body is the PDF bytes (D, `api_examples.php`). Default `A4` (Q7) |
| Cancel | `POST shipment/cancel` (`shipmentId`, `comment`) |
| Status | `POST track` (≤ 10 parcels per call, per the docs' summary, verify) |
| Quote | `POST calculate` (D: path; fields as the shipment) |
| Points / settlements | `location/office` filters `countryId` (100), `siteId`, `name`, `officeType` (`["OFFICE"]` / `["APT"]`), `officeFeatures` (D, `https://services.speedy.bg/api/api_examples.php`); `location/site` (verify) |

---

## 8. Statuses and tracking

### 8.1 BOX NOW webhooks

- **Endpoint:** `POST /webhooks/couriers/boxnow`.
  - It sits outside every storefront and api group: no session, no cookie, CSRF-exempt, its own named limiter (120/min per IP).
  - Body cap **64 KB**; `Content-Type: application/json` only.
  - It answers 404 when BOX NOW is disabled.
- **Verification:** the signature / integrity key per BOX NOW's tracking guide (**verify the scheme**: header name, algorithm, what is signed). The secret is stored in the vault. It is compared in constant time, and an invalid signature answers 401 and is logged without the body.
- **Replay:** de-duplicated by `dedupe_key = boxnow:<subject parcel id>:<type>:<data.time>` (unique index). An event older than 30 days is stored but cannot move the status back (§8.2).
- **Processing:** the controller stores the raw minimum (parcel id, event code, time, COD facts) and answers **2xx at once**; a queued job maps and applies it. An unknown parcel id answers 2xx and logs (BOX NOW must not learn which ids exist; it also stops pointless retries).
- **Registration:** the URL is registered with BOX NOW per environment (Q-BN3). The settings page shows the URL to give them.

### 8.2 Econt and Speedy polling

- **Command** `couriers:track`, every 30 min, `withoutOverlapping()`, enabled couriers only.
  - It takes **open** shipments only (`created`, `carrier_status` not final, created within 60 days), in batches: Speedy 10 parcels per call; Econt N shipment numbers per call (verify the limit).
  - Back-off: on errors, the next run skips that courier for 2 h, with a needs-attention row after 24 h.
- **Mapping:** each adapter maps the courier's codes to `pending, in_transit, at_point, delivered, returning, returned, lost`. The maps are tables in the adapter, tested per code.
  - The status only moves **forward** along `pending → in_transit → at_point → delivered`, or into the return branch.
  - A late, earlier event is stored in `shipment_events` but does not move `carrier_status` back.

### 8.3 Order effects and the customer's link

- `carrier_status = delivered`:
  - it only **suggests** «Маркирай като доставена» (a badge on the order and the list filter); staff confirm (Q4, decided), because a cash-on-delivery order's money is not in hand yet (R4b);
  - no automatic `OrderStatusChanger::deliver()`; revisit with R4b.
- `returned`: a needs-attention row; nothing automatic (a return is `recordReturn`, with stock and refunds, which staff decide).
- **Tracking link:** `ShipmentGateway::trackingUrl(number)`. The patterns are each courier's public tracking page (verify each URL pattern in COUR-3/4). It is shown in the account order page (`AccountOrderReader` already returns `tracking.number` + `courier`, and gains `url`), and passed to the M3 shipped mail.

---

## 9. Later: live quotes and the R4b door

### 9.1 Live quotes (COUR-5, optional)

The pipeline exists: kind `CARRIER` → `CarrierQuoteCache` (10 min answer, 30 s unavailable) → `CarrierCallGuard` with a 3000 ms `CallBudget` → `ShippingRateProvider`. What COUR-5 adds:
- the adapters' `quote()` (Econt `createLabel mode=calculate`; Speedy `calculate`);
- **a fallback amount on a `CARRIER` method** (a new nullable `fallback_minor`): when the courier is unavailable, the method is priced at the fallback instead of being "unavailable". The customer is never left without a price and never waits more than the budget;
- the threshold question for carrier prices (shipping §5.2, still open, Q-later).

The checkout's step 0 already uses the handle, never a live call inside the transaction. **I:** the budget should drop to 1500 ms for the checkout path.

### 9.2 R4b (cash on delivery reconciliation) door

`shipment_events.cod_collected_minor` (Econt `cdCollectedAmount`; BOX NOW COD facts in its events, verify), plus the hook `shipment.status_changed`, are the per-order facts R4b needs. Econt `PaymentReportService` (and Speedy's equivalent, verify) gives the batch payout with the courier's fee.
- R4b will add a `courier_remittances` import keyed by the courier's report id, matched to shipments by `external_id`.
- **Nothing in COUR-1…4 decides R4b.** The shipments domain only keeps the facts and the external ids R4b will join on.

---

## 10. Credentials and security

### 10.1 Secrets

- **Storage:** `site_settings` keys `couriers.<code>.<field>`. Secrets (passwords, client secret, webhook key) are `Crypt::encryptString` and **write-only** in the form, exactly the `MailSettings` pattern:
  - never filled back into the form;
  - dropped in `dehydrate()`;
  - state shown as «запазена» / «нечетима — въведете отново»;
  - journal entries record "changed", never values;
  - never in a hook payload.
- Test and production credentials are **separate keys**, so switching the environment never sends test credentials to production or the reverse.
- **Never in the repo or `.env.example`**, never in a log, an exception message, a snapshot, a sync record or a queue payload: jobs carry the carrier code and the shipment id, and the credentials are read from the vault inside the job.

### 10.2 One outbound client

`AllowlistedCourierHttp` implements the Shipping package's `CourierHttp` port:
- **Allowlist:** the union of the **enabled** couriers' declared hosts for their **current** environment. Exact host match, `https` only, port 443. **Redirects are not followed** (a 3xx is an error). Any other host is refused before DNS.
- **Timeouts:** connect 3 s. Total: sync 60 s, admin actions 15 s, webhook-triggered or poll 20 s, live quote = the `CallBudget`.
- **Retries:** only for **reads** (nomenclatures, labels, track, token), at most 2 retries with back-off 1 s / 3 s on connect errors and 502/503/504. **Writes (create, cancel) are never retried by the client** (§6.3 decides).
- **Response cap:** 20 MB (the full office lists), 5 MB for everything else.
- **Logging:** carrier, operation name, HTTP status, duration and our correlation id. Never the URL query, a body or a header.

### 10.3 Public endpoints

The read endpoints (§4.3) have a limiter, are cacheable and carry no personal data. The webhook (§8.1) has a size cap, a signature, a de-dup key, a limiter and an immediate 2xx with async processing. The checkout's `pickup_point_reference` is shape-checked (`[A-Za-z0-9_-]{1,64}`) before any query.

### 10.4 Snapshot redaction

The request and response snapshots pass a redactor that removes, at any depth:
- the keys `password`, `userName`, `client_secret`, `access_token`, `Authorization`;
- the courier's raw PDF / base64 blobs.

**Personal data stays in the snapshot** (it is what was sent and is needed to answer «какво изпратихме на куриера?»), so snapshots are pruned (§11).

---

## 11. GDPR

| Courier | Personal data sent | When |
|---|---|---|
| BOX NOW | recipient name, mobile phone, e-mail, locker id; COD amount; order number | waybill creation |
| Econt | name, phone, e-mail (optional), office code or address; COD; order number | waybill creation; `validate` / `calculate` without personal data where possible (I) |
| Speedy | name, phone, e-mail (optional), office id or address; COD; `ref1` | waybill creation |
| All three maps | **no personal data from us.** The courier sees the customer's IP and, if allowed, geolocation inside its own iframe | only after the customer presses «Избери от картата» |
| Live quotes (later) | settlement, weight, COD amount; no identity (`ShippingContext` carries none by design) | checkout |

- **Roles:** for the DPA question see Q-legal in §13.1. Whether each courier acts as an independent controller (the usual reading for postal services) or a processor determines whether a DPA is needed. That is for the owner's legal adviser; the privacy policy must name the couriers either way.
- **Retention:**
  - request / response snapshots are deleted after **90 days** (Q8, decided), by a daily `shipments:prune-snapshots`;
  - shipment rows themselves stay (history) and are covered by the order anonymisation: `OrderAnonymiser` (ACC-4b) must also blank the snapshots and the `destination_ref`-independent personal fields of an anonymised order's shipments (there are none besides the snapshots by design);
  - the privacy export (`PrivacyExportBuilder`) adds the shipments' courier, tracking number and status.
- **The map iframe** is a third party in the checkout. The cookie and privacy texts should say that the courier's map is loaded only on request (owner and legal; no text is written here).

---

## 12. Stages

Each stage is one coder task with targeted tests and a review gate. None commits without the owner. Never the full suite; one test process at a time on `easyco_testing`.

| Stage | Goal | Main files | Tests (targeted) | Must not touch |
|---|---|---|---|---|
| **COUR-1a — Core + BOX NOW points** | the Shipping contracts reshaped (§2.2), `CourierHttp` + `AllowlistedCourierHttp`, the «Куриери» settings page (enable, environment, write-only secrets, connection test), `shipping_pickup_points` + `courier_sync_runs`, the sync command / job / schedule / «Обнови сега», the circuit breaker, `CourierSyncStaleSource`, the BOX NOW package (points only) | Shipping package, `app/Couriers`, a Filament page, migrations (Shipping + app), `routes/console.php` | the allowlist refuses other hosts and redirects; secrets never in the form, journal or logs; a disabled courier is never scheduled or called; a sync upserts, deactivates and refuses a shrink; a malformed item is skipped; `SHOW CREATE TABLE` read back | checkout, orders, `ShippingQuoteService` |
| **COUR-1b — Settlements + Econt / Speedy points** (launch) | `shipping_settlements`, the mapping rules of §3.3 and the report, the Econt and Speedy packages (points + settlements only) | the same + 2 packages | the match matrix (postcode + name, name + region, unmatched, split), Sofia as one row, recorded courier responses | the same |
| **COUR-2a — Checkout list picker + server check** | method `carrier_code` on any kind + the admin Select, `integration` in the quote JSON, the 3 read endpoints (cache tags, limiter), `RecordDerivedPickupPointSource`, the prohibited fields, the storefront list picker | `ShippingMethod`, the method resource, `ShippingQuoteService`, `CheckoutController` rules, `storefront-checkout.js`, `AppServiceProvider` binding | refusals of §4.5; the kind is derived; client name / address are refused; a non-integrated method is byte-for-byte as today; the existing `carrierCodeNotAllowed` tests are listed as changed; the JS flow test | the hash, the handle store, the order schema |
| **COUR-2b — Maps** | the dialog, BOX NOW + Econt parsers, `CspSources` scopes, the checkout Permissions-Policy, the mobile layout; **Speedy widget v2 check in a real browser** (https in an https page, the returned office, the origin, a locker filter): pass → its parser; fail → Speedy list-only, reported (Q1) | `CspSources`, `SecurityHeaders`, `storefront-checkout.js`, CSS, courier packages | the origin and source gate (a forged message is ignored); no iframe before the click; headers only on the checkout; a browser proof on phone and desktop width | other storefront pages' headers (pinned identical) |
| **COUR-3a — Shipments + BOX NOW vouchers** (launch) | the `Shipments` package, `ShipmentService` (§6.3), **the one-click order-page block of §6.4** (three size buttons, «Промени», green number = label, red cancel with confirmation, «Маркирай като изпратена» / the Q2 switch), COD = one voucher, bulk + merged PDF, the `unknown` resolution, the tracking writer (§6.6: `OrderTrackingRecorder` recommended, or `Order::recordTracking` if chosen), hooks + Hook Reference, the needs-attention sources, the amended shipping §6.4 and order-editing §3 (E6) | Shipments package, BOX NOW package, `OrderResource` (the block), the orders-list bulk action, `app/Services` (the recorder) | double click → one shipment; a concurrent pair → one; timeout → `unknown`, never retried; size buttons disabled after `created`, enabled after cancel; COD → never two vouchers; Q2 on + failure → order not shipped; cancel clears the number; bulk with skip; label stored privately; tracking written once; the snapshot redactor | checkout, other couriers, the Order package (unless option (b) of §6.6 is approved) |
| **COUR-3b — BOX NOW webhooks** (right after launch) | the endpoint (§8.1), the event store, the mapping. **No auto-create** (Q3) | the controller, a job, routes | signature (valid, invalid, missing), size cap, de-dup, out-of-order, unknown parcel → 2xx | — |
| **COUR-4 — Econt / Speedy waybills** | §7.2 / §7.3 on the COUR-3 core; the «Създай товарителница» block (§6.4); the sender profile; open-before-payment; SMS; Econt print = one PDF per waybill (Q6); paper A4 default, A6, Speedy `A4_4xA6` (Q7) | 2 packages, the settings page | per adapter, recorded responses; refusals mapped to closed codes; OBPD refused for lockers | the core service (only additive) |
| **COUR-5 — Statuses, tracking, live quotes, R4b facts** | polling (§8.2), the tracking URL in the account page, the M3 hand-off, `fallback_minor` + `quote()`, `cod_collected_minor` | — | per-courier status maps; forward-only; quote fallback within the budget | R4b itself |

**Launch set:** **COUR-1a → COUR-1b → COUR-2a → COUR-2b → COUR-3a.** COUR-1b is in the launch set: the owner wants the Econt and Speedy office choice at launch (COUR-0.1). COUR-3b follows **right after launch**, because without it BOX NOW statuses are manual.

**Why this cut differs slightly from the brief's** (I): the brief's COUR-1 (core + points sync) is two tasks here, because the settlement mapping is the riskiest data work and deserves its own review. COUR-2 is split so that the list (which the owner already uses) can ship even if a map is delayed by a courier's answer.

**As built (COUR-1a), where the code made a decision the design left open:**
- **Contracts.** `PickupPointProvider` and `ShipmentLabelProvider` are deleted (no production implementation; only test fakes), and `CarrierCallGuard::pickupPoints()` with them (nothing called it). `ShippingProviderResolver` now has `pickupPointCatalogue()` and `shipmentGateway()`. `ShipmentGateway` is an interface only. A new `CourierDescriptor` (fields, exact hosts per environment, the connection check) is bound at `shipping.carrier.<code>.descriptor` (`CarrierRegistration::descriptorKey()`); the settings page and the allowlist read it, so core lists no courier. A carrier registered **without** a descriptor is not gated (it has no switch); an installed courier **with** one is unknown to the resolver until enabled.
- **Hosts.** The public locker list hosts are fixed per environment (`locationapi-production|stage.boxnow.bg`). The BOX NOW **API host** was not confirmed, so it is a per-environment setting accepted only as a bare host name on `boxnow.bg` / `boxnow.gr` (checked on save and again on every read). The allowlist is **per calling courier** (stricter than the union in §10.2): a courier can only use its own hosts, and none while disabled.
- **Settings keys.** `couriers.<code>.enabled | enabled_at | environment | map_enabled`; every credential field (API host, partner id, client id, secret) is per environment: `couriers.<code>.<env>.<field>`. `enabled_at` is the clock for a courier that has never synced (`CourierSyncStaleSource`).
- **Tables.** `shipping_pickup_points` has no `country_code` and no `courier_settlement_ref` yet (Bulgaria only; 1b adds the settlement reference with the mapping), and 1b adds the foreign key `spp_settlement_fk` on `settlement_id`. Timestamps are microsecond (`synced_at` marks "seen in this run"). The CHECKs compare in a binary collation (the column's collation is case-insensitive). `courier_sync_runs` gains `trigger` and `skipped`; `unmapped` is 0 until 1b.
- **Needs attention.** `NeedsAttentionItem` now allows a row with no order and no amount and a `subject` (the courier). `OpenMoneyOrders` skips such rows.
- **Sync.** One schedule entry `couriers:sync` (03:40, `withoutOverlapping`) that queues one job per **enabled** courier; `couriers:sync {carrier} --now` runs in-process. The cache purge of §3.4 step 5 is the plain event `App\Couriers\Events\PickupPointsSynced` (no listener until COUR-2a; it is not a Hook). A secret that cannot be decrypted does not stop the download of BOX NOW's public list.
- **Unconfirmed.** The BOX NOW locker field names and the `auth-sessions` request shape are assumed (see `BoxNowCatalogue` and `BoxNowDescriptor`); a real run on dev is the check (COUR-1a report, `UNVERIFIED`).
- **Update (COUR-1b, 2026-11-26):** the BOX NOW locker list's shape is now **confirmed** (P: read live): `name` is the display name (`title` the fallback), `addressLine2` is the city only, `lat` / `lng` are strings, `state` (`boxnow-ready` / `only-delivery`) is kept in `features.state` and neither is skipped. The API hosts `api-production.boxnow.bg` / `api-stage.boxnow.bg` are the pre-filled defaults (P: a second implementation), editable within `boxnow.bg` / `boxnow.gr`.

**As built (COUR-1b), where the code made a decision the design left open:**
- **`shipping_settlements`.** The design's unique key `(country_code, region_code, name_key)` "plus a split rule" is `(country_code, region_code, name_key, part)`: `part` is `''` for an ordinary row, the postcode (or `x<id>`) for a split part, and `s<id>` / `e<id>` for a row made from a courier settlement that matched nothing (`source = courier`, `region_code` `''` when its region name is not exactly one oblast). Added `is_split`. `speedy_site_id` and `econt_city_id` are UNIQUE, so one courier id can never sit on two rows. Rows are never deleted: a rebuild inserts, updates, and clears an id that left the courier's list. A courier whose list is not in the input keeps its ids.
- **Matching** is exactly §3.3 (a) → (b) → (c) and the split rule, on `BgPostcodes::normalise()` / `normaliseRegion()`. A courier region name is read as oblast codes; «София» alone is ambiguous (the city and the province), «София (столица)» and its Latin forms are the city. The postcode file gives Sofia city ONE postcode (1000), so «Sofia is one row» is trivially true; most Sofia postcodes (1xxx) are not in the file.
- **Points.** `shipping_pickup_points` gained `courier_settlement_ref` (the courier's city / site id) and the foreign key `spp_settlement_fk`; there is no country column (Bulgaria only). `settlement_id` is set after every points sync: Econt / Speedy through their own id (one UPDATE ... JOIN, a stale id gives NULL), BOX NOW through postcode + name by (a), then (b); when the postcode is not in the file (most of Sofia's) BOX NOW falls back to "the name exists in exactly ONE region of the backbone". An ambiguous name (five Lozens) stays unmapped. That name-only fallback is kept on purpose (owner's decision): Sofia's postcodes are missing from the file, and a name that exists in one region only cannot be confused with another. Unmapped points stay listed and are counted in `courier_sync_runs.unmapped`.
- **When settlements are built.** Before the points, inside the same job, when the courier never had them (`couriers.<code>.settlements_synced_at`), on a page save that switches a courier on for the first time (it queues the job at once), and weekly (`couriers:sync --settlements`, Sunday 04:00). A failing settlement download never fails the points sync.
- **Econt.** Hosts `ee.econt.com` (`/services/…`) and `demo.econt.com` (`/ee/services/…`), https, fixed; `getOffices.json` / `getCities.json` with `countryCode: BGR`; Basic auth only if a user and password are entered. Kind: `isAPS` → locker, everything else (`isMPS` included) → office. «Провери връзката» calls `Profile/ProfileService.getClientProfiles.json` (UNVERIFIED).
- **Speedy.** Host `api.speedy.bg`, `userName` / `password` in the body, `countryId` 100, `location/office` and `location/site` (UNVERIFIED shapes, below). `CourierRedactor` (Shipping package) is the snapshot redactor of §10.4, built now because Speedy's bodies carry credentials; COUR-3a applies it to snapshots.
- **Safety (COUR-1b.1).** A settlement list is refused with `settlements_shrank` (nothing written, `settlements_synced_at` kept, points keep their links) when, after the builder's own filter, it is empty or holds under 80 % of the ids the courier has on the table (the points sync's share, `PickupPointSyncer::MIN_SHARE_PERCENT`); the first build refuses an empty list too. All builds run under ONE global lock (`couriers:settlements-build`, waits up to 120 s), because a build rewrites the table from a snapshot. A point's `courier_settlement_ref` is 1 to 18 digits or null (the catalogues drop a bad id, not the point).
- **Confirmed vs assumed.** *Econt* (P: read live on production, public, 2026-11-26, counts only): the office keys `id, code, isMPS, isAPS, name, address{city{id, postCode, name, regionName…}, fullAddress, street, num, location…}` and `getCities`' city keys are as assumed. **23 mobile points (`isMPS`) have codes like `2951@2932`**, which the `[A-Za-z0-9_-]` reference rule of §10.3 refuses, so they are skipped and counted; COUR-2a decides whether `@` may be allowed. *Speedy* (D: the official API reference, https://services.speedy.bg/api/docs/, read 2026-11-26; nothing is confirmed live yet, no account): `FindOfficeResponse` root `offices` (+ optional `error`, even with HTTP 200); `Office` `id, name, nameEn, siteId, type (OFFICE / APT), address, pickUpAllowed, dropOffAllowed, cardPaymentAllowed, cashPaymentAllowed, palletOffice, maxParcelWeight, validFrom, validTo`; `Address` `siteId, siteName, postCode, x (longitude), y (latitude), fullAddressString, siteAddressString, localAddressString`; `GetContractClientsResponse` root `clients` (+ optional `error`). The settlement list is the **CSV export** `POST /v1/location/site/csv/100` (`location/site` is a search, not a full list), read by the header's column names; every Speedy location request sends `"language": "BG"`. `pickUpAllowed`, `cardPaymentAllowed`, `cashPaymentAllowed` and `validTo` are kept in the point's `features` and NOT filtered on: an office with `pickUpAllowed: false` must not be offered to a customer, which is a checkout rule for COUR-2a. The raw CSV text is read from `CourierResponse::$body` through the same allowlisted client (nothing was added to the `CourierHttp` port).
- **Size (measured 2026-11-26, one-off local run, nothing kept).** BOX NOW: 845 lockers, 811 mapped, 34 unmapped (GeoNames gaps and shared names). Econt production: 623 points (569 offices, 54 automatic stations), 23 skipped, 1,014 cities, all points mapped. Settlements: 5,370 rows in all: 5,290 from the postcode file (5,289 name + region groups; the split parts included), 80 Econt cities that matched nothing (own rows) and 2 split parts. 1,014 Econt city ids were placed.

---

## 13. Open questions

### 13.1 For the owner: decided 2026-11-25

No owner question is open. The answers, as applied in this document:

| Q | Decision | Where |
|---|---|---|
| Q1 — Speedy's map | **First try the official widget v2 over https.** COUR-2b checks it in a real browser (https in an https page, the returned office, the origin, a locker filter). If it fails, **Speedy is list-only** until Speedy answers Q-SP1. **No own map.** | §0, §4.4, §12 |
| Q2 — «Изпратена» after creation | A per-courier switch «Маркирай като изпратена при създаване», **default off**. Off: a separate «Маркирай като изпратена» button after creation. On: the create button also ships, only on success. | §5, §6.4, §6.6 |
| Q3 — BOX NOW auto-create | **None.** The owner creates vouchers by hand, today and after launch. It can be added later through the `order.status_changed` hook and the same `ShipmentService`; not built. | §1 row 6, §7.1, §12 |
| Q4 — «Доставена» from the courier | **A suggestion only;** staff confirm. | §8.3 |
| Q5 — the settlement search | Only settlements with an active point of the chosen courier **and** kind. | §4.2 |
| Q6 — Econt bulk print | **One PDF per waybill** for now; revisit after Q-EC4. | §6.5, §12 |
| Q7 — label paper | **A4 by default** for every courier (an office Brother printer; Econt and Speedy labels take half an A4 sheet). Per-courier A4 / A6; Speedy also `A4_4xA6`, never the default; BOX NOW per its formats (Q-BN5). | §5, §6.5, §7.1, §7.3 |
| Q8 — snapshot retention | **90 days.** | §11 |

**Still open, for COUR-3a (not an owner question but needs a yes):** the tracking writer, §6.6: (a) `OrderTrackingRecorder` (recommended) or (b) `Order::recordTracking`, and the widened status rule for the courier-written number.

(A legal point for the owner's adviser, not a design choice: the courier roles and DPA, and the privacy-policy mention of the couriers and of the map iframe, §11.)

### 13.2 For the couriers

**Priority.** Questions whose answer is already observed in production (P) are kept but **lowered to "confirm"**: the design does not wait for them. They are marked *(low: P)*.

**BOX NOW**
- Q-BN1: the production and test API hosts, and a test (stage) account with a partner id.
- Q-BN2 *(low: P)*: confirm how the **global map widget** (`map.boxnow.gr/iframe.html`) returns the selection: observed `postMessage` with `boxnowLockerId`, `boxnowCountry` and `boxnowClose` on close; still to confirm the other fields and the sending origin.
- Q-BN3: webhook registration, per environment; the **signature scheme** (header, algorithm, signed content, key rotation); the retry policy; the full event list.
- Q-BN4: does `delivery-requests` de-duplicate on `orderNumber`? Is there a lookup by `orderNumber`?
- Q-BN5: rate limits; how often the public locker JSON may be downloaded; whether it carries postcode and city per locker; COD facts in events; the label paper formats offered (A4 / A6; the shop prints A4, Q7).

**Econt**
- Q-EC1: the **custom office-locator URL** for our shop, and how a merchant obtains it; the `postMessage` origin; `officeType` values (`office`, `aps`, `mps`) and what `mps` is.
- Q-EC2: a test account on `demo.econt.com` for a merchant integration; https on `ee.econt.com/services`. *(low: P)* confirm that `getOffices` / `getCities` on production need no credentials (observed for `getOffices`).
- Q-EC3: a client reference / idempotency field on `createLabel`, and a lookup by it.
- Q-EC4: a merged label PDF for many shipments; label formats; the host of `pdfURL`.
- Q-EC5: rate limits; how often the office list may be downloaded; the `getShipmentStatuses` batch limit; the `PaymentReportService` format for COD payouts and fees.

**Speedy**
- Q-SP1: the **office locator widget** (v2 is tried first, Q1; this question decides Speedy's map only if v2 fails): an https URL with no redirect to http (v2 and v3), how the chosen office is returned (`postMessage` shape, `returnedOfficeJsonObject`?), the sending origin, a locker-only / office-only filter, a preselected `siteId`.
- Q-SP2: a test account; the `location/site` fields (postcode, municipality, region).
- Q-SP3: is OBPD `TEST` allowed, and is it refused for APT? The SMS service field.
- Q-SP4: the `track` and `print` per-request limits (the `print` request shape itself is D); `zpl` availability; rate limits; how often `location/office` may be downloaded.
- Q-SP5: de-duplication or lookup by `ref1`; the COD payout report.

---

## 14. What was not verified (COUR-0)

- **Not run:** no code was run; no courier endpoint was called; no test account was used.
- **From the couriers' public pages, read on 2026-11-25:**
  - Econt: the service and mode names, the office-locator parameters and message shape;
  - Speedy: `obpd`, `print`, `calculate`; and (COUR-0.1, the owner's link `https://services.speedy.bg/api/api_examples.php`) the `location/office` filters and the `print` request / PDF response;
  - BOX NOW: the map URLs and parameters.
  Some Speedy details (OBPD `TEST`, "not for APT", `zpl`, track ≤ 10) come only from a summary of its large docs page and are marked (verify). The Speedy widget v2 parameters are from the architect's brief (COUR-0.1), not yet loaded in a browser (COUR-2b).
- **BOX NOW's global widget page does not show how the selection is returned.** The `postMessage` fields in this design (`boxnowLockerId`, `boxnowCountry`, `boxnowClose`) are observed in the owner's live shop (P), not documented.
- **Econt `getOffices` without credentials** is observed in the owner's live shop (P), not documented.
- **Size estimates** (§3.1) are estimates.
- **From the brief, not re-checked:** the owner's plugin behaviour (§2 of the brief).
