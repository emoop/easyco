// A REAL-BROWSER run of the menu editor (MENU-2, «Дизайн» -> «Менюта»). It is NOT part of `php artisan test`: it is run by hand against a dev site whose data may be changed
// (export first, restore after, like tests/Browser/admin-save-audit.mjs).
//
//   AUDIT_BASE=https://easyco-main.test AUDIT_EMAIL=... AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] \
//   [OUT=<folder for screenshots>] node tests/Browser/menu-editor.mjs
//
// Needs: a staff account with the Administrator role, the 12 categories of the dev shop («Дрехи» with its sub-categories, «Обувки/Аксесоари» with «Обувки» and «Аксесоари»),
// one published page, and the HTML block «MENU-2 browser test picture» (make it with the prep script of the task).
//
// MENU-3: a mega item has «Колони» (1-4); the editor shows that many column boxes under it; every column has an optional heading.
//  A. the children's shop (3-level): «Момичета» and «Момчета» are Мега with 3 columns with headings «Дрехи», «Дрехи», «Обувки» (links added into the boxes), «Марки» is a dropdown,
//     «За нас» is a page; real drags (a link from column 1 to column 3, a column box, a block), the buttons, lowering the number of columns (the warning), Мега -> Падащо, a refused
//     save; ticks «Хедър», saves, hard-reloads and compares.
//  B. the boutique (2-level): «Дрехи» is Мега with 4 columns WITHOUT headings; 12 categories are added into the first column, «Раздели равномерно» (3, 3, 3, 3), one link is dragged
//     from column 1 to column 3; saves, reloads, compares.
// Then it looks at the shop at 1280px (hover) and 375px (the drawer). Prints a JSON report; exit code 1 when a check failed.
import { createRequire } from 'node:module';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const EMAIL = process.env.AUDIT_EMAIL ?? 'ux-audit@example.test';
const PASSWORD = process.env.AUDIT_PASSWORD;
const OUT = process.env.OUT ?? '';

if (! PASSWORD || ! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> node menu-editor.mjs');
    process.exit(2);
}

if (OUT) {
    mkdirSync(OUT, { recursive: true });
}

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });
const ctx = await browser.newContext({ viewport: { width: 1500, height: 2600 }, ignoreHTTPSErrors: true });
const page = await ctx.newPage();

const steps = [];
const consoleErrors = [];
const dialogs = [];
page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 300)); });
page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.stack || e).replace(/\s+/g, ' ').slice(0, 600)));
page.on('dialog', async (d) => { dialogs.push(d.type() + ': ' + d.message().slice(0, 80)); await d.accept(); });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const check = (name, ok, detail = '') => { steps.push({ name, ok: !!ok, detail: String(detail).slice(0, 400) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };

async function login() {
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
}

async function openEditor() {
    await page.goto(BASE + '/admin/menus', { waitUntil: 'networkidle' });
    await page.waitForSelector('.me-root', { timeout: 20000 });
    await page.waitForFunction(() => window.Alpine && document.querySelector('.me-root') && window.Alpine.$data(document.querySelector('.me-root')).items !== undefined, null, { timeout: 20000 });
    await sleep(400);
}

/** The editor's own state: [depth, type, text, submenu, block, badge] per item, plus the name and the locations. */
async function editorState() {
    return page.evaluate(() => {
        const d = window.Alpine.$data(document.querySelector('.me-root'));

        return {
            name: d.name,
            locations: d.selectedLocations.slice().sort(),
            dirty: d.dirty,
            items: d.items.map((i) => [i.depth, i.type, (i.label && i.label.trim()) || i.target_name || '', i.submenu, i.html_block_id, i.badge_text, i.mega_columns]),
        };
    });
}

const cards = () => page.locator('li.me-card');

async function openPanel(label) {
    const head = page.locator('.me-panel-head', { hasText: label });

    if ((await head.getAttribute('aria-expanded')) !== 'true') {
        await head.click();
        await sleep(150);
    }
}

async function panelAdd(label) {
    const body = page.locator('.me-panel-head', { hasText: label }).locator('xpath=following-sibling::div[1]');
    await body.getByRole('button', { name: 'Добави в менюто' }).click();
    await sleep(250);
}

async function addCategories(names, withChildren = false) {
    await openPanel('Категории');
    const body = page.locator('.me-panel-head', { hasText: 'Категории' }).locator('xpath=following-sibling::div[1]');
    const box = body.locator('label', { hasText: 'заедно с подкатегориите' }).locator('input');
    withChildren ? await box.check() : await box.uncheck();

    for (const name of names) {
        await body.locator('.me-list-scroll label.me-check').filter({ hasText: new RegExp('^\\s*' + name.replace(/[/]/g, '\\/') + '\\s*$') }).first().locator('input').check();
    }

    await panelAdd('Категории');
}

async function addLink(text, url) {
    await openPanel('Собствена връзка');
    await page.locator('#me-link-url').fill(url);
    await page.locator('#me-link-text').fill(text);
    await panelAdd('Собствена връзка');
}

async function addPage(title) {
    await openPanel('Страници');
    const body = page.locator('.me-panel-head', { hasText: 'Страници' }).locator('xpath=following-sibling::div[1]');
    await body.getByRole('tab', { name: 'Всички' }).click();
    await body.locator('label.me-check', { hasText: title }).first().locator('input').check();
    await panelAdd('Страници');
}

/** A real mouse drag of a card by its handle: `levels` to the right (30px each), `rows` rows down (negative = up). */
async function drag(index, levels, rows = 0) {
    const handle = cards().nth(index).locator('.me-handle');
    // the row in the middle of the window: near the edge SortableJS scrolls by itself while dragging and the pointer would end up over another row
    await handle.evaluate((el) => el.scrollIntoView({ block: 'center' }));
    await sleep(150);
    const box = await handle.boundingBox();
    const row = (await cards().nth(index).boundingBox()).height + 6;
    const x = box.x + box.width / 2;
    const y = box.y + box.height / 2;
    await page.mouse.move(x, y);
    await page.mouse.down();
    await page.mouse.move(x + 4, y + (rows === 0 ? 0 : Math.sign(rows) * 4), { steps: 3 });
    await page.mouse.move(x + levels * 30 + 4, y + rows * row, { steps: 12 });
    await sleep(120);
    await page.mouse.up();
    await sleep(300);
}

/** A real mouse drag of a card by its handle ONTO another card (its middle), `levels` to the right: rows differ in height (a box header is taller), so the target is measured. */
async function dragOnto(index, targetIndex, levels = 0) {
    const handle = cards().nth(index).locator('.me-handle');
    await handle.evaluate((el) => el.scrollIntoView({ block: 'center' }));
    await sleep(150);
    const box = await handle.boundingBox();
    const target = await cards().nth(targetIndex).boundingBox();
    const x = box.x + box.width / 2;
    const y = box.y + box.height / 2;
    const toY = target.y + target.height / 2;
    await page.mouse.move(x, y);
    await page.mouse.down();
    await page.mouse.move(x + 4, y + Math.sign(toY - y) * 4, { steps: 3 });
    await page.mouse.move(x + levels * 30 + 4, toY, { steps: 14 });
    await sleep(150);
    await page.mouse.up();
    await sleep(300);
}

async function openCard(index) {
    const card = cards().nth(index);

    if (! (await card.locator('.me-card-body').isVisible())) {
        await card.locator('.me-title').click();
        await sleep(150);
    }

    return card;
}

async function save() {
    const before = Date.now();
    await page.getByRole('button', { name: 'Запази менюто' }).click();
    await page.waitForFunction(() => ! window.Alpine.$data(document.querySelector('.me-root')).busy, null, { timeout: 30000 });
    await sleep(500);

    return Date.now() - before;
}

async function createMenu(name) {
    await page.getByRole('button', { name: 'Създай ново меню' }).click();
    await page.getByPlaceholder('Име на новото меню').fill(name);
    await page.getByRole('button', { name: 'Създай', exact: true }).click();
    await page.waitForFunction((n) => window.Alpine.$data(document.querySelector('.me-root')).name === n, name, { timeout: 15000 });
    await sleep(300);
}

const wanted = (rows) => JSON.stringify(rows);

/** The sizes of the columns of the mega item at `at` (links per column), from the editor's own state. */
async function sizesOf(at) {
    return page.evaluate((index) => {
        const items = window.Alpine.$data(document.querySelector('.me-root')).items;
        const sizes = [];

        for (let i = index + 1; i < items.length && items[i].depth > 0; i++) {
            if (items[i].type === 'column') {
                sizes.push(0);
            } else {
                sizes[sizes.length - 1]++;
            }
        }

        return sizes;
    }, at);
}

const indexOf = async (text, type = null) => (await editorState()).items.findIndex((i) => i[2] === text && (type === null || i[1] === type));

/** Chooses where the next added items go («Новите елементи отиват в»): the option whose text contains `fragment`; '' = the end of the menu. */
async function target(fragment) {
    const select = page.locator('#me-target');

    if (fragment === '') {
        await select.selectOption({ index: 0 });
    } else {
        await select.selectOption({ label: (await select.locator('option').allTextContents()).find((t) => t.includes(fragment)) });
    }

    await sleep(150);
}

/** A mega item: opens its card and chooses «Мега» and the number of columns. */
async function makeMega(text, columns, blockTitle = null) {
    const card = await openCard(await indexOf(text));
    await card.locator('select[id^="sm-"]').selectOption('mega');
    await sleep(200);
    await card.locator('select[id^="mc-"]').selectOption(String(columns));
    await sleep(200);

    if (blockTitle) {
        await card.locator('select[id^="hb-"]').selectOption({ label: blockTitle });
    }
}

/** The headings of the columns of one mega item, typed into the boxes. */
async function headings(megaText, texts) {
    const start = await indexOf(megaText);

    for (let n = 0; n < texts.length; n++) {
        const items = (await editorState()).items;
        let seen = -1;
        let at = -1;

        for (let i = start + 1; i < items.length && items[i][0] > 0; i++) {
            if (items[i][1] === 'column' && ++seen === n) {
                at = i;
                break;
            }
        }

        await cards().nth(at).locator('.me-colhead input').fill(texts[n]);
    }
}


// ------------------------------------------------------------------------------------------------------------------------------------------------ the run
const BLOCK = 'MENU-2 browser test picture';

/** A hard reload of the editor on a menu: the state that comes back from the database. */
async function reloadOn(id) {
    await page.goto(BASE + '/admin/menus?menu=' + id, { waitUntil: 'networkidle' });
    await page.waitForSelector('.me-root');
    await page.waitForFunction(() => window.Alpine && window.Alpine.$data(document.querySelector('.me-root')).items !== undefined);
    await sleep(500);

    return editorState();
}

const menuId = () => page.evaluate(() => window.Alpine.$data(document.querySelector('.me-root')).menuId);
const shape = (state) => state.items.map((i) => [i[0], i[1], i[2]]);

await login();
await openEditor();
check('the editor opens with SortableJS attached', await page.evaluate(() => !! window.Alpine.$data(document.querySelector('.me-root')).sortable));

// =================================================================================================================================================
// A. the children's shop (3 levels)
// =================================================================================================================================================
await createMenu('Детски магазин');
await addLink('Момичета', '/momicheta');
await addLink('Момчета', '/momcheta');
await addPage('Общи условия'); // becomes the last item; «Марки» is added after it and dragged above it
await addLink('Марки', '/marki');

await makeMega('Момичета', 3, BLOCK);
let s = await editorState();
check('choosing «Мега» and 3 columns shows three boxes under the item', JSON.stringify(s.items.slice(0, 4).map((i) => i[1])) === JSON.stringify(['link', 'column', 'column', 'column']) && s.items[0][6] === 3 && s.items[4][0] === 0, JSON.stringify(s.items.slice(0, 5)));
check('the boxes are indented under the mega item (depth 1)', s.items.slice(1, 4).every((i) => i[0] === 1));
await makeMega('Момчета', 3, BLOCK);
s = await editorState();
check('the second mega item gets its three boxes too', s.items.filter((i) => i[1] === 'column').length === 6);

await headings('Момичета', ['Дрехи', 'Дрехи', 'Обувки']);
await headings('Момчета', ['Дрехи', 'Дрехи', 'Обувки']);
s = await editorState();
check('every column has an optional heading typed in its box', JSON.stringify(s.items.filter((i) => i[1] === 'column').map((i) => i[2])) === JSON.stringify(['Дрехи', 'Дрехи', 'Обувки', 'Дрехи', 'Дрехи', 'Обувки']));

// the links go INTO the boxes («Новите елементи отиват в»)
for (const mega of ['Момичета', 'Момчета']) {
    await target(mega + ' › Колона 1');
    await addCategories(['Блузи и суичъри', 'Панталони', 'Ризи']);
    await target(mega + ' › Колона 2');
    await addCategories(['Рокли']);
    await target(mega + ' › Колона 3');
    await addCategories(['Обувки/Аксесоари']);
}

check('the links landed in their boxes', JSON.stringify(await sizesOf(await indexOf('Момичета'))) === JSON.stringify([3, 1, 1]) && JSON.stringify(await sizesOf(await indexOf('Момчета'))) === JSON.stringify([3, 1, 1]), JSON.stringify([await sizesOf(await indexOf('Момичета')), await sizesOf(await indexOf('Момчета'))]));

// «Марки»: a dropdown with two categories at the end of the menu, dragged one level right
await target('');
await addCategories(['Ризи', 'Рокли']);
let at = await indexOf('Марки');
await drag(at + 1, 1);
await drag(at + 2, 1);
s = await editorState();
check('two items dragged one level right become the sub-items of «Марки» (a dropdown)', s.items[at][3] === 'dropdown' && s.items[at + 1][0] === 1 && s.items[at + 2][0] === 1, JSON.stringify(s.items.slice(at, at + 3)));

// the block of «Марки» (with its two sub-items) is below the page: drag its row one row UP; the block goes with its items
at = await indexOf('Марки');
await drag(at, 0, -1);
s = await editorState();
const iPage = s.items.findIndex((i) => i[1] === 'page');
check('a vertical drag moves a block with its sub-items (the page is now last)', s.items[iPage - 3][2] === 'Марки' && s.items.length - 1 === iPage, JSON.stringify(s.items.slice(iPage - 3)));

// a REAL drag of one link from column 1 to column 3 of «Момчета»: the first link of the first box dropped on the header of the third box
const boys = await indexOf('Момчета');
const before = await sizesOf(boys);
const firstLink = boys + 2; // [Момчета, Колона 1, Блузи …]
const col3 = boys + 1 + 1 + before[0] + 1 + before[1]; // the header of column 3
await dragOnto(firstLink, col3 + 1); // onto the first link of box 3: the drop lands in that box (on its header edge it could land at the end of box 2)
s = await editorState();
check('a link dragged onto a box lands in that column (3,1,1 -> 2,1,2)', JSON.stringify(await sizesOf(await indexOf('Момчета'))) === JSON.stringify([2, 1, 2]), JSON.stringify(await sizesOf(await indexOf('Момчета'))) + ' ' + JSON.stringify(s.items.slice(boys, boys + 10).map((i) => i[1] + ':' + i[2])));
check('and every link is still inside a box (depth 2) with the boxes at depth 1', s.items.slice(boys + 1, boys + 9).every((i) => (i[1] === 'column' ? i[0] === 1 : i[0] === 2)));

// the same move with the buttons: the link goes back to the previous column («В предишната колона»)
const moved = (await editorState()).items.findIndex((i, k) => k > boys && i[2] === 'Блузи и суичъри');
const movedCard = await openCard(moved);
await movedCard.getByRole('button', { name: 'В предишната колона', exact: true }).click();
await sleep(250);
check('the button moves a link to the previous column', JSON.stringify(await sizesOf(await indexOf('Момчета'))) === JSON.stringify([2, 2, 1]), JSON.stringify(await sizesOf(await indexOf('Момчета'))));

// a column box is dragged by its header among the boxes of its own mega item: «Колона 1» below «Колона 2»
let girls = await indexOf('Момичета');
const head1 = girls + 1;
const sizes = await sizesOf(girls); // [3, 1, 1]: the header of the third box is at girls + 1 + 1 + 3 + 1 + 1
await dragOnto(head1, head1 + 1 + sizes[0] + 1 + sizes[1]);
check('a column box dragged by its header moves among the boxes of its own mega item (3,1,1 -> 1,3,1)', JSON.stringify(await sizesOf(girls)) === JSON.stringify([1, 3, 1]), JSON.stringify(await sizesOf(girls)));
// …and the buttons: «←» on the box that is second now brings it back
await cards().nth(girls + 1 + 1 + 1).getByRole('button', { name: 'Колоната наляво' }).click();
await sleep(250);
check('the buttons ← and → reorder the boxes (1,3,1 -> 3,1,1)', JSON.stringify(await sizesOf(girls)) === JSON.stringify([3, 1, 1]), JSON.stringify(await sizesOf(girls)));
// a box never leaves its mega item: dragged far down below the whole menu it is refused with a hint, nothing changes
const stateBefore = JSON.stringify((await editorState()).items);
await dragOnto(girls + 1, (await editorState()).items.length - 1);
check('a box dropped outside its mega item is refused (the list is unchanged, a hint is shown)', JSON.stringify((await editorState()).items) === stateBefore && await page.locator('.me-notice.me-bad').isVisible(), '');

// lowering the number of columns: the links of the removed boxes go to the end of the last remaining one, with a visible warning
girls = await indexOf('Момичета');
const linksBefore = (await editorState()).items.filter((i, k) => k > girls && k < girls + 9 && i[1] !== 'column').map((i) => i[2]);
let gc = await openCard(girls);
await gc.locator('select[id^="mc-"]').selectOption('1');
await sleep(300);
s = await editorState();
const linksAfter = s.items.filter((i, k) => k > girls && k <= girls + 6 && i[1] !== 'column').map((i) => i[2]);
check('lowering 3 -> 1 column: all the links are in the one box, in the same order, nothing lost', JSON.stringify(await sizesOf(girls)) === JSON.stringify([5]) && JSON.stringify(linksBefore) === JSON.stringify(linksAfter), JSON.stringify(await sizesOf(girls)) + ' ' + JSON.stringify([linksBefore, linksAfter]));
check('with a visible warning on the card', (await gc.locator('.me-notice').filter({ hasText: 'преместени' }).count()) === 1 && await gc.locator('.me-notice').first().isVisible());
// raising adds empty boxes again and loses nothing
await gc.locator('select[id^="mc-"]').selectOption('3');
await sleep(300);
check('raising the number adds empty boxes (5,0,0)', JSON.stringify(await sizesOf(girls)) === JSON.stringify([5, 0, 0]), JSON.stringify(await sizesOf(girls)));
// «Раздели равномерно»: 5 over 3 = 2, 2, 1
await gc.getByRole('button', { name: 'Раздели равномерно', exact: true }).click();
await sleep(300);
check('«Раздели равномерно» spreads the links evenly in order (5 over 3 = 2, 2, 1)', JSON.stringify(await sizesOf(girls)) === JSON.stringify([2, 2, 1]) && JSON.stringify((await editorState()).items.filter((i, k) => k > girls && k <= girls + 8 && i[1] !== 'column').map((i) => i[2])) === JSON.stringify(linksBefore), JSON.stringify(await sizesOf(girls)));

// Мега -> Падащо on «Марки»: the columns are dissolved, its links become the dropdown items in order (a confirm says so)
at = await indexOf('Марки');
const brands = await openCard(at);
await brands.locator('select[id^="sm-"]').selectOption('mega');
await sleep(250);
check('«Марки» as a mega item gets its boxes and the two links join the first', (await sizesOf(at)).reduce((a, b) => a + b, 0) === 2 && (await sizesOf(at))[0] === 2, JSON.stringify(await sizesOf(at)));
dialogs.length = 0;
await brands.locator('select[id^="sm-"]').selectOption('dropdown');
await sleep(300);
s = await editorState();
check('Мега -> Падащо asks first, then dissolves the columns: the two links are its items', dialogs.some((d) => d.includes('разтворени')) && s.items[at][3] === 'dropdown' && s.items[at + 1][0] === 1 && s.items[at + 2][0] === 1 && ! s.items.slice(at, at + 4).some((i) => i[1] === 'column'), JSON.stringify(s.items.slice(at, at + 4)) + ' ' + dialogs.join('|'));

// cards: the page gets its own text, «Марки» a green label
const iUs = (await editorState()).items.findIndex((i) => i[1] === 'page');
let card = await openCard(iUs);
await card.locator('input[id^="lab-"]').fill('За нас');
card = await openCard(await indexOf('Марки'));
await card.locator('input[id^="bt-"]').fill('Нови');
await card.locator('select[id^="bc-"]').selectOption('green');

// a refused save: nothing written, the faulty card opens and says why
await page.locator('#loc-header').check();
await target('');
await addLink('Лоша връзка', 'javascript:alert(1)');
await save();
const bad = page.locator('li.me-card.me-error');
check('a refused save marks the faulty card, opens it and says why in words', (await bad.count()) === 1 && await bad.locator('.me-card-body').isVisible() && /започва с/.test((await bad.textContent()) ?? ''), await bad.count());
const probe = await ctx.newPage();
probe.on('dialog', (d) => d.accept());
await probe.goto(BASE + '/admin/menus?menu=' + (await menuId()), { waitUntil: 'networkidle' });
await probe.waitForFunction(() => window.Alpine && document.querySelector('.me-root') && window.Alpine.$data(document.querySelector('.me-root')).items !== undefined);
check('nothing was stored by the refused save (a second window sees an empty menu)', (await probe.evaluate(() => window.Alpine.$data(document.querySelector('.me-root')).items.length)) === 0);
await probe.close();
await (await openCard(await indexOf('Лоша връзка'))).getByRole('button', { name: 'Премахни', exact: true }).click();
await sleep(200);

const beforeSave = await editorState();
await save();
const saved = await editorState();
check('the good menu is saved (no longer dirty)', ! saved.dirty && saved.items.length === beforeSave.items.length, 'dirty=' + saved.dirty);
check('the notification says it', await page.locator('.fi-no-notification').filter({ hasText: 'Менюто е запазено' }).count() > 0);

const kidsId = await menuId();
const after = await reloadOn(kidsId);
check('after a hard reload the menu is the same (columns, headings, links, depths)', JSON.stringify(shape(after)) === JSON.stringify(shape(beforeSave)), JSON.stringify(shape(after).filter((r, i) => JSON.stringify(r) !== JSON.stringify(shape(beforeSave)[i])).slice(0, 3)));
check('the number of columns and the block survive the reload', after.items[after.items.findIndex((i) => i[2] === 'Момичета')][6] === 3 && after.items.filter((i) => i[0] === 0 && i[3] === 'mega').every((i) => i[4] !== null));
check('«Хедър» is ticked after the reload', after.locations.includes('header'));
await page.screenshot({ path: OUT ? join(OUT, 'editor-kids-1500.png') : undefined, fullPage: true }).catch(() => {});

// =================================================================================================================================================
// B. the boutique (2 levels): «Дрехи» is a mega item with 4 columns and NO headings
// =================================================================================================================================================
await createMenu('Бутик');
await addLink('Дрехи', '/drehi');
await makeMega('Дрехи', 4, BLOCK);
s = await editorState();
check('«Дрехи» as a mega item: four boxes with no headings', s.items.filter((i) => i[1] === 'column').length === 4 && s.items.filter((i) => i[1] === 'column').every((i) => i[2] === ''));
await target('Дрехи › Колона 1');
await addCategories(['Дрехи', 'Блузи и суичъри', 'Жилетки и пуловери', 'Комплекти', 'Панталони', 'Ризи', 'Рокли', 'Тениски', 'Якета и сака', 'Обувки/Аксесоари', 'Аксесоари', 'Обувки']);
let dres = await indexOf('Дрехи', 'link');
check('12 categories are added directly into the first box', JSON.stringify(await sizesOf(dres)) === JSON.stringify([12, 0, 0, 0]), JSON.stringify(await sizesOf(dres)));
const mega = await openCard(dres);
const order12 = (await editorState()).items.filter((i) => i[1] === 'category').map((i) => i[2]);
await mega.getByRole('button', { name: 'Раздели равномерно', exact: true }).click();
await sleep(300);
check('«Раздели равномерно»: 12 over 4 = 3, 3, 3, 3, in the same order', JSON.stringify(await sizesOf(dres)) === JSON.stringify([3, 3, 3, 3]) && JSON.stringify((await editorState()).items.filter((i) => i[1] === 'category').map((i) => i[2])) === JSON.stringify(order12), JSON.stringify(await sizesOf(dres)));
// drag the first link of column 1 onto the header of column 3 (a real drag)
const link1 = dres + 2;
const colHead3 = dres + 1 + 4 + 4; // [Дрехи, К1, 3 links, К2, 3 links, К3]
await dragOnto(link1, colHead3 + 1);
check('a link dragged from column 1 to column 3 (3,3,3,3 -> 2,3,4,3)', JSON.stringify(await sizesOf(await indexOf('Дрехи', 'link'))) === JSON.stringify([2, 3, 4, 3]), JSON.stringify(await sizesOf(await indexOf('Дрехи', 'link'))));
await page.locator('#loc-header').check();
check('ticking «Хедър» names the menu it moves from', (await page.locator('fieldset .me-meta').allTextContents()).join(' ').includes('Детски магазин'));
const boutiqueBefore = await editorState();
await save();
const boutiqueId = await menuId();
const boutiqueAfter = await reloadOn(boutiqueId);
check('the boutique menu survives a hard reload (4 columns, 2/3/4/3 links, no headings, the block)', JSON.stringify(shape(boutiqueAfter)) === JSON.stringify(shape(boutiqueBefore)) && boutiqueAfter.items[0][6] === 4 && boutiqueAfter.items[0][4] !== null && boutiqueAfter.locations.includes('header'), JSON.stringify(await sizesOf(0)));

// ---- the shop with the boutique in the header: 1280px ---------------------------------------------------------------------------------------------
const shop = await ctx.newPage();
await shop.setViewportSize({ width: 1280, height: 900 });
await shop.goto(BASE + '/', { waitUntil: 'networkidle' });
await shop.locator('.sf-bar-item', { has: shop.locator('.sf-bar-link', { hasText: 'Дрехи' }) }).hover();
await sleep(600);
let panel = shop.locator('.sf-sub-mega.sf-sub-open');
check('1280px boutique: hovering «Дрехи» opens the mega panel with FOUR equal columns', await panel.count() === 1 && (await panel.locator('.sf-mega-cols').getAttribute('data-cols')) === '4' && (await panel.locator('.sf-mega-col').count()) === 4);
check('1280px boutique: no heading anywhere (the 2-level case)', (await panel.locator('.sf-mega-heading').count()) === 0);
const widths = await panel.locator('.sf-mega-col').evaluateAll((els) => els.map((e) => Math.round(e.getBoundingClientRect().width)));
check('1280px boutique: the four columns are equal', Math.max(...widths) - Math.min(...widths) <= 1, JSON.stringify(widths));
check('1280px boutique: the HTML block is a column of its own on the right, about 30%', await panel.locator('.sf-mega-html img').count() === 1 && (await panel.locator('.sf-mega-html').boundingBox()).x > (await panel.locator('.sf-mega-col').last().boundingBox()).x);
const ratio = await panel.evaluate((p) => { const m = p.querySelector('.sf-mega').getBoundingClientRect(); const b = p.querySelector('.sf-mega-media').getBoundingClientRect(); return b.width / m.width; });
check('1280px boutique: the block takes 20-32% of the panel (as before MENU-3)', ratio > 0.2 && ratio < 0.32, ratio.toFixed(3));
await shop.screenshot({ path: OUT ? join(OUT, 'shop-1280-boutique.png') : undefined }).catch(() => {});

await shop.setViewportSize({ width: 375, height: 800 });
await shop.goto(BASE + '/', { waitUntil: 'networkidle' });
await shop.locator('[data-sf-menu-toggle]').first().click();
await sleep(400);
await shop.locator('#sf-drawer .sf-acc-item', { has: shop.locator('.sf-acc-link', { hasText: 'Дрехи' }) }).locator('.sf-acc-toggle').click();
await sleep(300);
let drawer = shop.locator('#sf-drawer');
const sections = await drawer.locator('.sf-acc-sub.sf-acc-open .sf-acc-section').count();
check('375px boutique: the columns are stacked sections (the empty ones are left out), no headings, no picture', sections >= 1 && sections <= 4 && (await drawer.locator('.sf-acc-sub .sf-mega-heading').count()) === 0 && (await drawer.locator('.sf-mega-html').count()) === 0, sections);
await shop.screenshot({ path: OUT ? join(OUT, 'shop-375-boutique.png') : undefined }).catch(() => {});

// ---- back to the children's shop: the header location moves back, and the 3-level case in the shop ---------------------------------------------
await page.goto(BASE + '/admin/menus?menu=' + kidsId, { waitUntil: 'networkidle' });
await page.waitForSelector('.me-root');
await sleep(500);
await page.locator('#loc-header').check();
check('the note says the header is at «Бутик» and moves', (await page.locator('fieldset .me-meta').allTextContents()).join(' ').includes('Бутик'));
await save();

await shop.setViewportSize({ width: 1280, height: 900 });
await shop.goto(BASE + '/', { waitUntil: 'networkidle' });
await shop.locator('.sf-bar-item', { has: shop.locator('.sf-bar-link', { hasText: 'Момчета' }) }).hover();
await sleep(600);
panel = shop.locator('.sf-sub-mega.sf-sub-open');
const kidsHeadings = await panel.locator('.sf-mega-col > .sf-mega-heading').allTextContents();
check('1280px children: three columns with the headings Дрехи, Дрехи, Обувки (an empty column keeps its place)', (await panel.locator('.sf-mega-cols').getAttribute('data-cols')) === '3' && (await panel.locator('.sf-mega-col').count()) === 3 && JSON.stringify(kidsHeadings) === JSON.stringify(['Дрехи', 'Дрехи', 'Обувки']), JSON.stringify(kidsHeadings));
check('1280px children: the picture block is on the right', await panel.locator('.sf-mega-html img').count() === 1 && (await panel.locator('.sf-mega-html').boundingBox()).x > (await panel.locator('.sf-mega-col').last().boundingBox()).x);
check('1280px children: the «Нови» label next to «Марки»', await shop.locator('.sf-bar-link', { hasText: 'Марки' }).locator('.sf-badge-green').count() === 1);
await shop.screenshot({ path: OUT ? join(OUT, 'shop-1280-children.png') : undefined }).catch(() => {});

await shop.setViewportSize({ width: 375, height: 800 });
await shop.goto(BASE + '/', { waitUntil: 'networkidle' });
await shop.locator('[data-sf-menu-toggle]').first().click();
await sleep(400);
await shop.locator('#sf-drawer .sf-acc-item', { has: shop.locator('.sf-acc-link', { hasText: 'Момичета' }) }).locator('.sf-acc-toggle').click();
await sleep(300);
drawer = shop.locator('#sf-drawer');
const ys = await drawer.locator('.sf-acc-sub.sf-acc-open .sf-acc-section').evaluateAll((els) => els.map((e) => Math.round(e.getBoundingClientRect().top)));
// «Момичета» lost the headings of its 2nd and 3rd box when the editor lowered the number of columns to 1 and raised it again (the warning said so): only «Дрехи» of box 1 is left
check('375px children: the sections are one under another, a heading only where there is one, no picture', ys.length === 3 && ys.every((y, i) => i === 0 || ys[i - 1] < y) && (await drawer.locator('.sf-acc-sub.sf-acc-open .sf-mega-heading').count()) === 1 && (await drawer.locator('.sf-mega-html').count()) === 0, JSON.stringify(ys));
check('375px: nothing scrolls sideways', await shop.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
await shop.screenshot({ path: OUT ? join(OUT, 'shop-375-children.png') : undefined }).catch(() => {});

check('no console errors in the admin', consoleErrors.length === 0, consoleErrors.slice(0, 3).join(' | '));

const report = { steps, consoleErrors, dialogs, failed: steps.filter((x) => ! x.ok).map((x) => x.name) };
console.log(JSON.stringify(report, null, 2));
await browser.close();
process.exit(report.failed.length === 0 ? 0 : 1);
