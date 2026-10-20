# Menu editor v2 (MENU-2): a WordPress-style editor, mega menus from the structure, HTML blocks, menu locations

**Status:** design, from the owner's feedback of 2026-10-19 ("the menus are confusing, settings seem not to save; WordPress is the
example") and his screenshots (the WordPress menu screen of his live shop; a WoodMart "Boys menu" HTML block: three headed columns of
links and a picture on the right). Builds after **UX-0** (the save audit). Replaces the D3 admin editor and simplifies the D3 model;
the storefront rendering, compile-and-cache and auto-rebuild of D3 stay.
**Builds on:** `storefront-customization-design.md` §5 and "D3 as built", `pages-design.md` (`DescriptionSanitizer`), D2 header.
**Studied:** WordPress *Appearance → Menus* (select a menu; left "add items" panels with checkboxes and tabs Most recent / View all /
Search; right "Menu structure", drag to reorder, drag right to make a sub-item, expandable item with label / link target / remove; one
"Save Menu"; display locations). Shopify navigation (nested links up to 3 levels by drag; a link picker searching products,
collections, pages or a URL; **the theme turns level 2 into column headings and level 3 into the links under them**; promos are attached
outside the menu). WoodMart (a "Design" choice on the parent item: default dropdown or full-width mega; the mega content is an
**HTML Block**; small **labels** like "New"/"Sale" with a colour).

---

## 0. Decisions

| # | Decision |
|---|---|
| M-D1 | **One screen, the WordPress layout.** Top: a select of all menus + «Създай ново меню». Left: add-items panels. Right: the menu structure, the menu name, the locations, «Запази менюто», «Изтрий менюто». Nothing else on the page. |
| M-D2 | **The structure is the mega menu (Shopify).** On a top-level item the merchant picks **Подменю: Падащо / Мега**. With «Мега», its level-2 items are the **column headings** and their level-3 items the **links** under them. No "column" type, no "image" item, no automatic sub-categories. What is in the menu is what shows. |
| M-D3 | **The picture is an HTML block (WoodMart).** A mega item may name one **HTML блок**, shown to the right of the columns (the owner's "Boys menu" layout). HTML blocks are their own small content type, reusable (D4 design slots will use them too). |
| M-D4 | **Locations are chosen on the menu screen (WordPress).** Checkboxes «Хедър — главно меню», «Телефон — меню в чекмеджето», «Футър». One menu per location. The header no longer has a "source" setting: its menu element shows the menu of its location. |
| M-D5 | **Drag like WordPress:** drag up/down to reorder, drag right (≥ 30px) under the item above to make it a sub-item, drag left to bring it out; depth ≤ 3. The same moves are buttons inside the opened card («Нагоре», «Надолу», «Под предишния», «Извади от подменюто») for keyboard users. |
| M-D6 | **One save, all or nothing.** «Запази менюто» validates the whole menu; on success: written in one transaction, compiled, cache refreshed, a green notification; on an error: nothing written, the faulty card opens and scrolls into view with the message. Leaving with unsaved changes asks first. |
| M-D7 | **Small labels (WoodMart):** an optional badge per item (text ≤ 16, colour from 6 presets or a hex). |
| M-D8 | The drag library (**SortableJS**) is loaded on this admin page only. The storefront gets nothing new. |

---

## 1. The screen

```
[ Меню: (Главно меню ▾) ]  [ Създай ново меню ]

┌ Добавяне на елементи ─────┐  ┌ Структура на менюто ─────────────────────────────┐
│ ▸ Категории                │  │ Име на менюто [ Главно меню            ]         │
│   [Търсене…]               │  │                                                    │
│   ☐ Дрехи                  │  │ ┌ Момчета              Категория · Мега       ▾ ┐ │
│     ☐ Ризи                 │  │ │  ┌ Дрехи            Заглавие                ▾ ┐│ │
│     ☐ Панталони            │  │ │  │  ┌ Якета         Категория              ▾ ┐││ │
│   ☐ Обувки                 │  │ │  │  └ Бермуди       Категория              ▾ ┘││ │
│   [☐ заедно с подкатегориите]│  │ │  └ Обувки          Заглавие                ▾ ┘│ │
│   [ Добави в менюто ]      │  │ └──────────────────────────────────────────────┘ │
│ ▸ Страници                 │  │ ┌ Отстъпки  [Sale]     Връзка                 ▾ ┐ │
│ ▸ Продукти                 │  │                                                    │
│ ▸ Собствена връзка         │  │ Местоположение: ☑ Хедър  ☐ Чекмедже  ☐ Футър     │
│ ▸ Заглавие (без връзка)    │  │ [ Запази менюто ]                 Изтрий менюто   │
└────────────────────────────┘  └────────────────────────────────────────────────────┘
```

### 1.1 Left panels (one open at a time)
- **Категории:** a searchable tree with checkboxes (indent shows the path); «заедно с подкатегориите» adds the ticked category with
  its sub-categories nested under it (once, as explicit items: they can be removed one by one later); **Добави в менюто**.
- **Страници:** tabs «Най-нови» (10) / «Всички» / «Търсене»; published pages only; checkboxes; Добави.
- **Продукти:** «Търсене» only (name or SKU, 20 results); checkboxes; Добави.
- **Собствена връзка:** URL (root-relative or https) + text; Добави.
- **Заглавие (без връзка):** text only — for a mega column heading that is not a page (the owner's "ДРЕХИ", "ОБУВКИ").
- Added items go to the end at the top level, are highlighted for a moment, and the panel's ticks are cleared.

### 1.2 The structure (right)
- One line per item: **label**, an optional **badge**, the **type** in grey («Категория», «Страница», «Продукт», «Връзка»,
  «Заглавие»), on a top-level item also «· Падащо» / «· Мега», and the chevron. Sub-items are indented and show «под-елемент».
- A target that no longer exists or is hidden shows a red «(скрита)» / «(изтрита)» on its line: the merchant sees why it is missing in
  the shop, instead of the item silently disappearing.
- **Opened card fields** (only these):
  - Текст в менюто (empty = the target's own name, shown as placeholder);
  - Връзка (only for «Връзка»); «Отвори в нов раздел»;
  - Етикет: text + colour (optional);
  - **top level only:** Подменю: Няма / Падащо / Мега; with «Мега»: HTML блок (select of blocks, «+ нов блок» opens the blocks page in a
    new tab);
  - «Оригинал: Категория Дрехи → /product-category/drehi» (a link to the target, read-only);
  - the move buttons (M-D5) and «Премахни» (with its sub-items, after a confirm when it has any).
- «Избор на много» (as in WordPress): checkboxes on the lines + «Премахни избраните».

### 1.3 Rules the screen enforces (and the server checks again)
Depth ≤ 3; «Заглавие» only at level 2 under a Mega item (elsewhere it would be a dead text); a Mega item's level-2 items are its
columns (≤ 6), each with ≤ 15 links; top level ≤ 12; menu ≤ 300 items; the crowded-menu warning stays (> 7 top-level items or a label
> 20 characters → «излишните ще отидат в „Още“»).

---

## 2. HTML blocks (new, small)

- Table `html_blocks`: `id`, `title` (≤ 120, plain), `body` (mediumText, **sanitized HTML**), `timestamps`. Admin «HTML блокове» (same
  group as Menus): list, create, edit, delete (refused while a menu or, later, a design slot uses it — the message names the menu).
- Editor: Filament RichEditor with headings (H2–H4), bold, italic, lists, link, and **«Вмъкни снимка»** (upload into the public disk
  folder `html-blocks/`, or pick an existing media asset).
- **Sanitizer:** `HtmlBlockSanitizer` = the `DescriptionSanitizer` allow-list **plus** `img` (attributes `src`, `alt`, `width`,
  `height` only; `src` must be our own `/storage/…` path or `https:`), `figure`, `figcaption`, `br`. No `style`, `class`, `on*`,
  `iframe`, `script`, `svg`, `video`. On save AND on render. Rendered images get `loading="lazy" decoding="async"`.
- Owner decision (2026-10-19): images and links in blocks are enough; no scripts, styles or embeds.

---

## 3. Data model changes (from D3)

- `menu_items.type`: `link | category | page | product | heading` (**new:** `product`, `heading`; **removed:** `column`, `image`).
- **New columns:** `html_block_id` (nullable, no FK; top level + `submenu = mega` only), `badge_text` (≤ 16), `badge_color` (`#RRGGBB`).
- **Removed columns** (after data migration): `with_children`, `children_limit`, `media_asset_id`, `image_alt`, `caption`.
- **Locations:** setting `menus.locations` = `{"header": <menu id>, "drawer": <menu id>, "footer": <menu id>}`; `drawer` empty = the
  header's menu. The D2 header `menu` element loses `source`; it renders the `header` location (desktop) or `drawer` (in the drawer).
- **Data migration of D3 menus (idempotent, reported in counts):**
  - a `column` under a mega item → a `heading` item (its label) at level 2; its children move under it (level 3);
  - an `image` item → collected into a new HTML block per mega item («Снимка: <label>», `<figure><a><img></a><figcaption>`) and the
    block set on that mega item; the image items are removed;
  - `with_children = 1` on a category → its CURRENT visible sub-categories become explicit child items (so nothing disappears);
  - `menus.locations` from the D3 seed: `main` → header, `footer` → footer; header layouts drop `source`.

---

## 4. Storefront (changes only)

- Mega panel: columns = the mega item's level-2 items (heading text, a link when the heading has one), links = their level-3 items; the
  HTML block on the right (the owner's layout); up to 6 columns, equal width; the block takes ~30% when present.
- Dropdown: level 2 as the list; level 3 as a nested flyout.
- Badges: a small pill after the label (`--sf-badge-bg` from the item's colour via a class from the 6 presets; a custom hex goes into
  the compiled token stylesheet as a per-menu class — never `style=`).
- **Phone drawer (owner, 2026-10-19: "on a phone there is no mega menu — separate sections one under another"):** a top-level item with
  a submenu opens in place (one tap); for a **mega** item its columns are shown as **sections stacked one under another** — the heading
  as a small section title, its links listed under it, no second-level accordion; for a **dropdown** item its links are listed (a level-3
  flyout becomes an indented list). The HTML block is not shown in the drawer.
- **The two real shops this must serve (owner):** the children's shop — top level «Момичета», «Момчета», «Марки», «За нас», where
  «Момичета»/«Момчета» are Mega (headed columns + a picture block) and the rest plain links or dropdowns; the boutique shop — plain
  dropdowns only. Both are built with the same editor; a shop that never picks «Мега» never sees a mega field.
- Compile, cache, re-validation on read, auto-rebuild on category/page/product changes: as D3 (product items added to the triggers:
  a product hidden or deleted is skipped).

---

## 5. Stage MENU-2 (one commit, after UX-0)

Migrations (types, columns, html_blocks, data migration, locations) · `HtmlBlockSanitizer` + «HTML блокове» resource · the menu editor
page (custom Livewire page + Alpine + SortableJS, replacing `MenuResource`'s forms) · validator/writer for the flat-with-depth list ·
compiler + views for heading/product/badge/html block · header `menu` element by location · help sections rewritten (menus,
HTML blocks) · docs.

**Tests (★ mutation-checked):** add from every panel (category with/without sub-categories, page, product search, custom link, heading)
★; drag/move semantics on the flat list: reorder, indent under previous, outdent, depth > 3 refused ★; heading allowed only at level 2
of a mega item ★; save is all-or-nothing and reports the faulty item ★; unsaved-changes guard; locations: one menu per location,
changing it moves the header/footer ★; the data migration (column → heading, image → HTML block, with_children → explicit items,
idempotent, counts) ★; HTML block sanitizer (script, style, onerror, iframe, `javascript:` src, data: src refused; own and https images
kept) ★; block in use cannot be deleted; storefront: mega columns from level 2/3 and the block on the right; badges without `style=`;
query pins unchanged; a real-browser script (`tests/Browser/menu-editor.mjs`) that builds the owner's "Boys" menu by clicking and
dragging, saves, reloads and compares.

**Owner check:** build «Момчета» as Mega with columns «Дрехи» (Якета, Панталони, Бермуди…), «Дрехи» (Връхни дрехи, Блузи и ризи…),
«Обувки» (Затворени, Отворени, Пантофи) and an HTML block with the picture; a top-level «Отстъпки» link with a «Sale» badge; tick
«Хедър»; save; reload; look at the shop at 1280px and on the phone.

---

## 6. As built - part 1 (data, HTML blocks, storefront, header locations)

Part 2 (the WordPress-style editor page, SortableJS, the «Дизайн» navigation group, the help sections and the browser script) is not built yet; until then the D3 repeater editor
(`MenuResource` / `EditMenu`) edits the new model: it knows the types heading / product, the badge, the HTML block of a mega item and the menu's locations.

- **Migrations** (`2026_10_23_000001..3`): 1 = `html_blocks` + `menu_items.html_block_id / badge_text / badge_color` (+ `menu_items_badge_check`), the type CHECK widened to old and new
  types together; 2 = the data step (`App\Content\Menus\Menu2Migrator`, also what the tests call): columns -> headings, the pictures of a mega item -> ONE HTML block
  («Снимка: <label>», a `<figure>` per picture, links and captions kept, pictures still processing dropped as D3 did), `with_children` -> explicit child items (the shown
  sub-categories of that moment, cut at the old limit, a "see all" item when the old menu had one; only where the new depth allows it - a top-level dropdown item or an item
  of its first sub-level, anything deeper is counted as `skipped`), `menus.locations` seeded (the header's menu element decides `header`, a different drawer element `drawer`, the
  `footer` menu `footer`), `source` stripped from the draft / published / compiled header layouts, the type CHECK narrowed to the final five, the menus compiled again; 3 = the
  five D3 columns and `menu_items_limit_check` dropped, and it REFUSES to run while a column / image / with_children row exists. All three are idempotent; the counts go to the log
  (`menu2.migrated`). The D3 seed migration (`2026_10_21_000002`) is now empty: on a fresh database `main` and `footer` are seeded by step 2, in the final shape.
- **Dev run (2026-10-20):** 0 columns, 0 images (0 blocks), 2 categories expanded into 4 explicit items, 0 skipped, 2 locations (header = Main menu, footer = Footer), 2 layouts
  stripped (draft, published). The shop's link list is identical before and after. Backup: `storage/app/menu2-backup-20261020-060528.json`.
- **Validator** (`MenuValidator`): top = link | category | page | product; under a dropdown the same four, one more level of them; under a mega item = COLUMNS (<= 6): `heading` or any of
  the four (a link used as the heading), each with <= 15 links of the four; `heading` anywhere else is refused with its own message; `html_block_id` only on a top-level mega item;
  badge text <= 16, colour = preset (`red green orange blue dark grey`) or `#rrggbb` (stored lower-case), at most 12 distinct custom colours per menu.
- **Writer:** one transaction for name + items + locations + compile; an error writes nothing; `save()` returns the locations that moved (location => the menu that had it).
  A menu that holds a location cannot be deleted (the message names the place). Targets checked: category, page, product, HTML block.
- **Compiled value** (`menus.compiled`): `{"menus": {key: [...]}, "locations": {header, drawer, footer => key|null}}`. A link node = `{type: link, label, url, new_tab, submenu,
  badge: {text, color}|null, html: string|null, children}`, a heading node = `{type: heading, label, children}`. `CompiledMenus::read()` validates every key again, and cleans the
  block again (`HtmlBlockSanitizer::render`). A product item is skipped when the product is not visible; the rebuild is asked for by a product's status / visibility / name / slug
  change (only when a menu holds a product item) and by an HTML block's save / delete (only when a menu uses one).
- **Badges:** a class on a `<span class="sf-badge ...">` (six presets in `storefront.css`); a custom colour is `.sf-badge-c-<hex>` in the token stylesheet (`AppearanceCompiler`
  reads the colours from the stored compiled menus; saving a menu rebuilds the stylesheet when the set of colours changed). Never a `style` attribute.
- **HTML blocks:** `HtmlBlockSanitizer extends DescriptionSanitizer` (the same walker; the description sanitizer only got `protected` lists and `static::`), model `HtmlBlockModel`
  sanitizes on every save, `HtmlBlockResource` («HTML блокове», `content_manage`, in the Content group beside Menus until part 2 moves it), delete refused with the menu named.
- **Header:** the `menu` element has only `style`; the desktop bar prints the `header` location, the drawer the `drawer` location or else the header's; no location, no menu (the
  category tree is never a fallback). A stored layout that still has `source` validates and loses it. `MenuView` (the category-tree helper) is removed.
- **Storefront markup:** mega = columns from the structure + the block on the right (`.sf-mega:has(.sf-mega-html)`, 7fr / 3fr; 1-6 equal columns through `data-cols`), dropdown
  second level = a flyout (`.sf-sub-dropdown .sf-menu .sf-menu`; an indented list inside "More"), drawer = stacked sections for a mega item (`.sf-acc-section`, no second accordion,
  no block). `storefront.js` is unchanged.
