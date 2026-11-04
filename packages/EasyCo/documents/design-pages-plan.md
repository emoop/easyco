# EasyCo — «Дизайн»: Sidebar, page content, product page, badge builder (plan, owner-approved 2026-11-04)

Status: agreed direction, not started. Each part becomes its own coder task (prompt written when it starts). Everything follows the storefront security rules (closed lists, no `style=`, no inline JS, validated on save / publish / read, published data only, draft via signed preview, identical HTML for every visitor) and `admin-ui-standard.md` (narrow «Дизайн» panels: one field per row).

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

## 4. «Съдържание» zone — product page (general, one for all products)
- Not per product: one layout for every product page.
- Ordered slots BELOW the gallery and the accordion: tabs, carousel, «Подобни продукти», HTML block — the merchant chooses which and in what order.
- **«Подобни продукти» is a kind of the shared carousel** with an automatic source: same category, without the current product (later also by tags). Not a separate component.
- No title / cloud component on the product page.
- Check against the recorded product-page decisions (gallery modes, sticky info column, accordion + tabs) before writing the prompt.

## 5. Badge builder (recorded earlier)
- «Промо» is mandatory, with show / hide; «Нови»; free-text badges with conditions.
- Conditions reuse the HTML-block conditions mechanism (`ProductConditions`).
- Depends on price lists (for «Промо») → after the price task.

## Suggested order
Sidebar → season / tag / brand public pages → «Съдържание» for listing pages (defaults + inheritance + cloud) → product page layout → prices → badge builder. Small fixes («дреболии») go in between as small Haiku tasks.
