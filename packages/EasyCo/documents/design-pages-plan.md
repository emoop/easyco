# EasyCo — «Дизайн»: Sidebar, page content, product page, badge builder (plan, owner-approved 2026-11-04)

Status (2026-11-06): P1, P1.1, P2, P3, P3.1, P4, P4b done; P4b.1 (corrections) in progress. Each part becomes its own coder task (prompt written when it starts). Everything follows the storefront security rules (closed lists, no `style=`, no inline JS, validated on save / publish / read, published data only, draft via signed preview, identical HTML for every visitor) and `admin-ui-standard.md` (narrow «Дизайн» panels: one field per row).

## 1. Sidebar zone (filters)
- The merchant switches each filter on / off and orders them.
- ONE list drives both places: the side panel and the top filter bar (when there is no side panel). No second list to maintain.
- Smallest of the four parts; can go first.

## 2. Public pages for season, tag and brand
- Do not exist yet (only categories have a public page). They use the category page template (listing, filters, sort, pagination).
- Prerequisite for part 3. Also unblocks the brand links in the HP-5 brand-logo carousel (today logos have no link because there is no brand route).

## 3. «Съдържание» zone — listing pages (category, tag, season, brand)
Layout of a listing page, top to bottom:
1. **Title component** with a **cloud for quick navigation**:
   - category page: its sub-categories;
   - season / tag / brand page: the categories that contain products of that season / tag / brand (e.g. «Есен 2026: Рокли · Палта · Обувки»). Costs a few queries; covered by the planned S4 caching.
2. **HTML block on top** (optional; best a «Снимка с текст» block, HB-5).
3. The product listing (unchanged).
4. **Bottom component** (optional): a carousel (the shared HP-5 carousel) or an HTML block.

**Default templates and inheritance (owner-approved):**
- One DEFAULT template per page kind: «всички категории», «всички етикети», «всички сезони», «всички марки».
- Through a search field the merchant finds a SPECIFIC category / tag / season / brand and may give it its own settings.
- A page without its own settings inherits the default template of its kind. (Avoids configuring 80 categories one by one.)
- Removing the specific settings returns the page to the default.

## 4. Zone «Под галерията» — product page (general, one for all products)
- Not per product: one layout for every product page. Decided 2026-11-06: a NEW zone «Под галерията» placed right after «Продуктова страница» in the «Дизайн» menu (the existing `ProductPageZone` is a fixed-field form and cannot hold a slot repeater; Step 0 of P4 found that no accordion / sections / tabs exist yet).
- P4 (now): ordered slots BELOW the main area: carousel (incl. «Подобни продукти»), HTML block; optional heading with lines per slot; brand name on the product page becomes a link. Season and tag are NOT added to the product page.
- P4b (later, separate): product «sections» (title, icon, source = global text / per-product field / fallback; generate fields in a product-edit «Информация» tab), the accordion under the purchase block, and the «Табове» slot kind over the same sections. Needs migration + product admin work.
- **«Подобни продукти» is a kind of the shared carousel** with an automatic source: same category, without the current product (later also by tags). Not a separate component.
- No title / cloud component on the product page.
- Check against the recorded product-page decisions (gallery modes, sticky info column, accordion + tabs) before writing the prompt.

## 5. Badge builder (recorded earlier)
- «Промо» is mandatory, with show / hide; «Нови»; free-text badges with conditions.
- Conditions reuse the HTML-block conditions mechanism (`ProductConditions`).
- Depends on price lists (for «Промо») → after the price task.
- **Two positions on the product card** (owner, 2026-11-04): TOP over the picture (Промо, Нови) and BOTTOM under the price (informative: «Безплатна доставка», «Последни бройки», «Само онлайн»…). No empty row is reserved when a card has no bottom badge (only if it turns out visible: reserve it when at least one bottom badge is enabled).
- **«Безплатна доставка» badge** depends ONLY on the product (the card is identical for every visitor, cached): shown when the product's shipping class gives free shipping OR its current price (with promotion) is at or above the shop's free-shipping threshold (owner's example: threshold 80.00 € → a product at 80.00 € gets the badge, at 79.99 € it does not). The badge means «this product has a free shipping option», not that every method is free. Reuse the threshold the cart already uses (do not store a second one).
- The cart keeps working as today: a cart total at or above the threshold (e.g. 50.00 € + 30.00 €) shows the free methods together with the paid ones. That is a cart feature, NOT a card badge.

## 6. Product card name: one or two lines (small task)
- Setting in «Дизайн» → Каталог: «Име на картата: 1 ред / 2 реда», default 1 line.
- One line = cut visually with an ellipsis (`text-overflow: ellipsis`); the FULL name stays in the HTML (SEO), in the picture `alt` and in the link. Price always at the same place; frees the third line for the bottom badge of part 5.

## 7. Public promo codes in the cart («Налични промокоди») — owner's idea, 2026-11-04
Goal: a visitor always knows a small discount is available, so he does not leave the cart to search coupon sites («Google: промокод [магазин]»), and the shop has a «bargain» feel. Seen on AliExpress / Temu («available coupons»), rare in Bulgaria.
- A promo code gets a flag **«Публичен»** and a short customer-facing description (e.g. «5% над 100 €»). Personal codes are NEVER listed.
- In the cart, next to the code field: a link «Виж наличните промокоди» (the owner's wording: «Потърси промокод») opens a dropdown list of the public codes; a click applies the code through the existing promo flow (no new discount logic).
- A public code whose condition the cart does not meet yet is still listed, with a hint: «Остават ви 20 € до този код» (nudges a bigger order).
- **Risk and rule:** a public code without a condition is just a permanent price cut. Public codes should carry a condition that brings something: a minimum order, first order, a category to clear, an end date. The admin form shows a short warning when «Публичен» is set on a code with no condition.
- The cart is dynamic (not cached), so the list does not conflict with the planned S4 caching. The list is served through the cart API, only active public codes (valid dates, usage limits not exhausted).
- Depends on: the existing promo-code domain (conditions, dates, limits). Check against it before writing the prompt. Medium task (admin flag + description, API list, cart UI).

## Work packages (owner, 2026-11-05: group small tasks so the FULL suite runs once per package)
Each package is ONE coder task: targeted tests while working, folder runs after the last change, and ONE full-suite run (`./rt.sh --full`) at the very end of the package (not per sub-task). Grouped by the area they touch, so one full run covers related changes.

| # | Package | Contents | Model |
|---|---|---|---|
| P1 | Catalog listing extras | Sidebar zone (part 1: filters on/off + order, one list for side panel and top bar) + «Препоръчани» (the `is_featured` flag in the domain — the owner explicitly allows the `Product.php` change for this — the product form toggle, the `featured` source for the home product grid and carousel; flip `test_featured_is_not_a_source`) | Sonnet |
| P2 | Public pages | Season / tag / brand public pages on the category template (part 2) + brand links in the HP-5 brand-logo carousel | Sonnet |
| P3 | «Съдържание» — listing pages | Part 3: default templates per kind + per-page override via search, inheritance, title with the quick-navigation cloud, top HTML block, bottom carousel / block | Sonnet |
| P4 | Zone «Под галерията» | Part 4: ordered slots under the product page's main area (carousel, «Подобни продукти» as a carousel source, HTML block) + brand link | Sonnet |
| P4b | Product sections | Accordion sections (product-edit tab, global texts), accordion component, «Табове» slot kind | Sonnet |
| P4b.1 | Sections corrections | Remove the `sf-description` block (description becomes a section source `description`), tab slot heading only when typed, right column without inner scroll (sticky) | Sonnet |
| P5 | Cart | Cart performance (122 queries for 10 lines: remove the N+1, before caching) + `max_quantity` per line from the same grouped read + public promo codes in the cart (part 7) | Sonnet |
| P6 | Prices | `price_from_minor`, sort by price, price lists — the base for the «Промо» badge | Sonnet |
| P7 | Badge builder | Part 5: top / bottom positions on the card, «Промо» (mandatory, show/hide), «Нови», «Безплатна доставка» (shipping class OR price ≥ the cart's threshold), free-text badges with `ProductConditions` | Sonnet |
| P8 | Before production | S4 caching + the security stage | Opus review, Sonnet build |

Order: P1 → P2 → P3 → P4 → P4b → P4b.1 → P5 → P6 → P7 → P8. P5 may move earlier if needed (it touches only the cart). A package that turns out too big for one session is split at a working point with a HANDOVER file; the full run then happens at the end of the last part only.

Part 6 (card name 1/2 lines) is DONE (DRIBNI-4, 2026-11-04).

Leftovers noted: `LimitFilteredCategoryRequests` does not cover `featured=1` (→ P8); listing-page signed preview shows the first category, not the edited page (small improvement); carousel kinds with pictures / brand logos are not offered in the listing bottom slot; brand / season / tag names as links on cards and filters (product page brand link is in P4).
Section icons: not done (no closed storefront icon set yet; with the ICN-1 icons task). Idea parked by the owner (2026-11-06): a section source «HTML блокове» (e.g. size tables per brand as HTML blocks with conditions) — the owner will design how it is switched on/off per product before it is built; no new brand field.
