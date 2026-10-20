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

---

## 7. As built - part 2 (the editor page, drag, navigation group, help)

- **Page** `App\Filament\Pages\Design\Menus` (route `/admin/menus`, `content_manage`, group «Дизайн» sort 30; every public method checks the permission again, a Livewire call does not pass the
  route's check). `MenuResource`, `EditMenu`, `ListMenus` and `MenuFormState` (the D3 repeater editor) are gone. The view is `resources/views/filament/pages/design/menus.blade.php`
  (one `wire:ignore` root with the Alpine component `menuEditor`); the page loads `resources/js/admin-menu-editor.js` through its own `@vite` and nothing else does (an admin entry in
  `vite.config.js`; no other admin page, never the storefront).
- **State:** the structure is a FLAT list with `depth` (0-2) in Alpine; no round trip per drag or field. Calls: `saveMenu(id, name, items, locations)` (the whole list in one call),
  `loadMenu`, `createMenu(name)` (the key is made from the name, a number is added when taken), `deleteMenu`, `searchProducts(query)` (20 at most, id / name / SKU / address). The categories
  (as a tree), the published pages and the HTML blocks come once with the page.
- **Save:** `MenuFlat::toTree()` derives the parents from the depths (a first item that is not at depth 0, a jump of more than one level and a depth above 2 are refused with the index of the
  item before anything else), `MenuWriter::save()` validates the tree, writes name + items + locations in ONE transaction and compiles before the commit. The answer is the saved menu, or the
  errors BY ITEM INDEX (`MenuFlat::errorsByItem` maps the validator's tree paths back); the page then opens the faulty cards, scrolls to the first and shows the message there. Nothing is
  written on an error. A green notification says it was saved; a location that moved is named in it.
- **Drag** (SortableJS, forced fallback so the pointer's x is known): vertical = order; the horizontal offset at the drop sets the depth (30px a level), clamped to `previous.depth + 1` and
  to 2; a block moves with its sub-items. The rules are plain functions in `resources/js/menu-editor-core.js` (`moveBlock`, `moveUp`, `moveDown`, `indent`, `outdent`, `normalize`, ...), run
  under node by `MenuEditorCoreJsTest`. The same moves are buttons in the opened card with an `aria-live` announcement. The fallback clone SortableJS puts on the body gets `x-ignore`
  (it carries the row's Alpine bindings without its `x-for` scope).
- **Cards / panels / locations / guard:** as the brief (collapsed line with badge, type, Падащо / Мега, под-елемент, red «(скрита)» / «(изтрита)»; opened fields; add-panels for categories
  with «заедно с подкатегориите», pages with three tabs, products by server search, custom link, heading; location checkboxes with the «премества се от „…“» note; a dot next to «Запази
  менюто» and a `beforeunload` + `livewire:navigate` guard). Not built: WordPress's «Избор на много» (bulk select), which the brief did not list.
- **Navigation:** `NavigationGroup::DESIGN` between Съдържание and Админ holds Облик (10), Хедър (20), Менюта (30), HTML блокове (40), Витрина (50).
- **Help:** `{#action-appearance-menus}` rewritten, `{#action-appearance-html-blocks}` added (bg / en); the pages link to them.
- **Browser script:** `tests/Browser/menu-editor.mjs` builds the children's shop by clicking and dragging, refuses a bad link, saves, reloads and compares, builds a boutique of plain dropdowns,
  and looks at the shop at 1280px and 375px (35 checks, run by hand on dev with a backup and restore).

---

## 8. MENU-3 as built - columns as boxes (2-level and 3-level mega menus in one model)

Owner decisions (2026-10-20): a mega item has **«Колони» 1-4** (default 3, at most 4); the editor shows that many **column boxes** under it; every column has an **optional heading** (empty =
the 2-level case: just links; a heading = the 3-level case); **«Раздели равномерно»**; lowering the number moves the links to the last column with a warning; the HTML block stays a column of its
own on the right, exactly as wide as before. It replaces section 3's "heading" and section 4's "columns = the first level"; the `heading` item type is gone.

- **Data** (`2026_10_24_000001/2`): `menu_items.mega_columns` (tinyint, 1-4, `menu_items_mega_cols_check`; only on a top-level mega item), type `column` (label = the optional heading, <= 60, no target /
  link / badge / submenu), `heading` removed. A `column` lives only at depth 1 under a mega item. `Menu3Migrator` (the step the tests call): a `heading` becomes a `column` (same label, same children); a
  link / category / page / product at level 1 becomes a `column` whose heading is that item's label (or its target's name) and whose FIRST link is that item, then its former children, so every link that
  showed before still shows, in the same order; columns 5 and up are merged into the 4th (their links are appended in order, their headings go); a mega item with nothing under it gets 3 empty columns; a
  stray heading outside a mega item is dissolved; then the type CHECK is narrowed to link | category | page | product | column and the menus are compiled again. Idempotent; the counts go to the log
  (`menu3.migrated`). `Menu2Migrator` no longer narrows the CHECK once `mega_columns` exists, and converts only D3 columns (under a mega item without `mega_columns`).
- **Dev run (2026-10-20):** one mega item («Дрехи», 5 first-level categories, two of them with a sub-category, HTML block 2): 5 items promoted to columns, columns 5+ merged into the 4th (1 column, 1 link), 4
  columns; the 7 links stay in the same order and the shop shows the same 4 links (the hidden categories are skipped as before); the block is identical. Backup `storage/app/menu3-backup-20261020-091805.json`.
  The migrated columns carry their first link's name as heading: clear the headings for a boutique-style panel.
- **Validator:** a mega item holds EXACTLY `mega_columns` `column`s (1-4; anything else is refused with the message on `children`), each with <= 15 links of link | category | page | product; a link directly
  under a mega item and a column anywhere else are refused (`column_here`); `mega_columns` only on a top-level mega item.
- **Compiler / storefront:** the compiled mega node holds its columns `{type: column, label|null, children}`; an EMPTY column is kept (it keeps its place), a mega item whose columns are all empty is a plain
  link. `menu-mega.blade.php`: `data-cols` equal columns (1-4), a heading `<p>` only when there is one, the block column unchanged. Drawer: sections one under another, a heading only when present, an empty
  column left out, no picture.
- **Editor:** the flat list keeps exactly `mega_columns` column entries (depth 1) after a mega item, links at depth 2. The rules live in `menu-editor-core.js` (`reconcile`, `setMegaColumns`, `setSubmenu`,
  `splitEvenly`, `toColumn`, `moveBlock`, `columnTargets`): after every change the list is brought to the valid shape (a link dropped under the mega item joins a column, a column outside a mega item is
  dissolved, a column only moves among the columns of its own mega item and the drop is refused otherwise, a top-level block is never dropped into the middle of another block). The mega card has «Колони»,
  «Раздели равномерно» (the first columns take the remainder: 10 over 4 = 3, 3, 2, 2) and a warning after lowering; Мега -> Падащо asks first and dissolves the columns in order. «Новите елементи отиват в»
  puts what the panels add into a chosen column. Column boxes: dashed, indented, a heading input inline, «←» / «→» and the handle. The move buttons also move a link to the previous / next column.
- **Tests:** `MegaColumnsTest`, `Menu3MigrationTest`, the node rules (36), `MenusPageTest`, `MenusTest`; mutation-checked; `tests/Browser/menu-editor.mjs` (46 checks, run by hand on dev with a backup and restore).
