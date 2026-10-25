// A REAL-BROWSER run of the media picker (ML-2; ML-3 when ML_PRODUCT / ML_VARIABLE_PRODUCT are set). It is NOT part of `php artisan test`: it is run by hand against a dev site whose data may be changed. It makes its OWN throw-away brands
// («ml2-…») and HTML block («ML2 …») and uploads three small pictures («ml2-….png»); the person who runs it removes them afterwards (php artisan tinker / the helper in the report).
//
//   AUDIT_BASE=https://easyco-main.test AUDIT_EMAIL=... AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] \
//   ML_FIXTURES=<dir with ml2-a.png ml2-b.png ml2-c.png> ML_KNOWN_NAME=<original_name of a ready picture in the library> OUT=<folder for screenshots> node tests/Browser/media-picker.mjs
//
// With real clicks: a brand «Избери от медията» → search → pick → «Избери» → the field shows the thumbnail → save; another brand «Качи нова» → the upload tab → the new picture comes back selected →
// «Избери»; «Махни» detaches; an HTML block: a picture of its list through the picker and a picture INSIDE the text through the editor's «Снимка» button (size «Среден»), saved; the text holds the <img>.
// ML-3 (ML_PRODUCT = the id of an EXISTING simple product, ML_VARIABLE_PRODUCT = a variable one; fixtures ml3-a.png ml3-b.png ml3-c.png in ML_FIXTURES): upload three pictures through the gallery picker,
// change the main photo from «Медия», pick one into the gallery, reorder with ← → and by drag and drop, save, remove one, save; a variation photo. BACK UP and RESTORE the products' pivots first.
// Screenshots of the empty and the filled field and of the modal at 1400px and 390px are written to OUT. Prints a JSON report; exit code 1 when a check failed.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const EMAIL = process.env.AUDIT_EMAIL ?? 'ml1-audit@example.test';
const PASSWORD = process.env.AUDIT_PASSWORD;
const FX = process.env.ML_FIXTURES;
const KNOWN = process.env.ML_KNOWN_NAME;
const OUT = process.env.OUT ?? '';

if (! PASSWORD || ! process.env.PLAYWRIGHT_DIR || ! FX || ! KNOWN) {
    console.error('usage: AUDIT_PASSWORD=... PLAYWRIGHT_DIR=... ML_FIXTURES=... ML_KNOWN_NAME=... node media-picker.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });
const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
const page = await ctx.newPage();

const steps = [];
const consoleErrors = [];
page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 300)); });
page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.stack || e).replace(/\s+/g, ' ').slice(0, 500)));
page.on('response', (r) => { if (r.status() >= 400 && ! r.url().includes('/storage/')) consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 160)); });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const check = (name, ok, detail = '') => { steps.push({ name, ok: !!ok, detail: String(detail).slice(0, 400) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const shots = [];
const shot = async (name, locator = null) => { if (! OUT) return; const path = join(OUT, name + '.png'); shots.push(path); await (locator ?? page).screenshot({ path, ...(locator ? {} : { fullPage: false }) }); };
const settle = async () => { await page.waitForLoadState('networkidle'); await sleep(700); };
const modal = () => page.locator('.fi-modal-window:visible').last();

async function login() {
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
}

async function openModal(label) {
    await page.getByRole('button', { name: label, exact: true }).first().click();
    await page.waitForSelector('.fi-modal-window:visible .mp', { timeout: 20000 });
    await sleep(600);
}

async function search(text) {
    await modal().locator('.mp-search').fill(text);
    await sleep(1400);
    await page.waitForLoadState('networkidle');
}

async function fillBrand(name, slug) {
    await page.locator('input[id$=".name"], input[type=text]').first().fill(name);
    await page.locator('input[id$=".slug"]').first().fill(slug);
}

try {
    await login();

    // ================================================== a brand: pick from the library ==================================================
    await page.goto(BASE + '/admin/brands/create', { waitUntil: 'networkidle' });
    await fillBrand('ML2 Brand A', 'ml2-brand-a');
    check('the empty field says there is no picture and offers ONE button', await page.locator('.mpf-none').count() === 1 && await page.getByRole('button', { name: 'Добави изображение', exact: true }).count() === 1 && await page.getByRole('button', { name: 'Качи нова', exact: true }).count() === 0);
    await shot('01-field-empty-1400', page.locator('.mpf').first());

    await openModal('Добави изображение');
    await shot('02-modal-library-1400');
    check('the modal has the two tabs and one search and one toggle', await modal().locator('.mp-tab').count() === 2 && await modal().locator('.mp-search').count() === 1 && await modal().locator('.mp-toggle').count() === 1);
    check('«Избери» is disabled until something is selected', await modal().getByRole('button', { name: 'Избери', exact: true }).isDisabled());
    check('the library lists at most 24 squares', await modal().locator('.mp-card').count() <= 24 && await modal().locator('.mp-card').count() > 0);

    await search(KNOWN);
    check('searching the known name leaves exactly that picture', await modal().locator('.mp-card').count() === 1, await modal().locator('.mp-card').count());
    await modal().locator('.mp-card').first().click();
    await sleep(900);
    check('a click selects: the frame and the check mark, and the side column shows name, size and alt', await modal().locator('.mp-card-on').count() === 1 && await modal().locator('.mp-check').count() === 1 && await modal().locator('.mp-side .mp-name').innerText() === KNOWN && await modal().locator('#mp-alt').count() === 1);
    await shot('03-modal-selected-1400');
    await modal().locator('.mp-card').first().click();
    await sleep(700);
    check('a second click unselects', await modal().locator('.mp-card-on').count() === 0 && await modal().locator('.mp-side').count() === 0);
    await modal().locator('.mp-card').first().click();
    await sleep(700);
    await modal().getByRole('button', { name: 'Избери', exact: true }).click();
    await page.waitForSelector('.fi-modal-window:visible', { state: 'detached', timeout: 15000 }).catch(() => {});
    await sleep(900);
    check('after «Избери» the modal is closed and the field shows the picture LARGE, with «Смени» and «Премахни изображението» only', await page.locator('.mpf-one .mpf-big img[src*="-medium.webp"]').count() === 1 && await page.locator('.mpf-name, .mpf-dim').count() === 0 && await page.getByRole('button', { name: 'Смени', exact: true }).count() === 1 && await page.getByRole('button', { name: 'Качи нова', exact: true }).count() === 0 && await page.getByRole('button', { name: 'Премахни изображението', exact: true }).count() === 1);
    const big = await page.locator('.mpf-one .mpf-big').boundingBox();
    const card = await page.locator('.mpf').first().boundingBox();
    check('the picture box is the full width of the card', big && card && Math.abs(big.width - card.width) < 2, JSON.stringify([big?.width, card?.width]));
    check('the hint is only the title of the remove link, not a visible line', (await page.getByRole('button', { name: 'Премахни изображението', exact: true }).getAttribute('title')) === 'Снимката остава в Медия.' && ! (await page.locator('.mpf').first().innerText()).includes('Снимката остава'));
    await shot('04-field-filled-1400', page.locator('.mpf').first());
    await page.getByRole('button', { name: /Създай|Create/ }).first().click();
    await page.waitForURL(/\/brands\/\d+(\/edit)?$/, { timeout: 30000 }).catch(() => {});
    await settle();
    check('the brand is saved', ! page.url().endsWith('/create'), page.url());
    const brandA = page.url().replace(/\/edit$/, '');

    // ================================================== a brand: upload a new picture =====================================================
    await page.goto(BASE + '/admin/brands/create', { waitUntil: 'networkidle' });
    await fillBrand('ML2 Brand B', 'ml2-brand-b');
    await openModal('Добави изображение');
    await modal().getByRole('tab', { name: 'Качи файлове' }).click();
    await sleep(500);
    check('the upload tab is one click away inside the same modal', await modal().locator('.mp-tab-on').innerText() === 'Качи файлове' && await modal().locator('.mp-drop').count() === 1);
    await shot('05-modal-upload-1400');
    await modal().locator('input[type=file]').setInputFiles([join(FX, 'ml2-a.png')]);
    await modal().locator('.mp-card-on').first().waitFor({ timeout: 30000 });
    check('after the upload the modal is on the library tab with the new picture already selected', await modal().locator('.mp-tab-on').innerText() === 'Медийна библиотека' && await modal().locator('.mp-card-on').count() === 1 && (await modal().locator('.mp-side .mp-name').innerText()) === 'ml2-a.png');
    await modal().getByRole('button', { name: 'Избери', exact: true }).click();
    await sleep(1500);
    check('the field shows the new picture', await page.locator('.mpf-one .mpf-big').count() === 1);
    await page.getByRole('button', { name: /Създай|Create/ }).first().click();
    await settle();

    // ================================================== «Махни» detaches ================================================================
    await page.goto(brandA + '/edit', { waitUntil: 'networkidle' });
    await page.waitForSelector('.mpf', { timeout: 20000 });
    check('editing the brand shows the saved logo with «Смени» and «Премахни изображението»', await page.locator('.mpf-one img[src*="-medium.webp"]').count() === 1);
    await page.getByRole('button', { name: 'Премахни изображението', exact: true }).click();
    await sleep(900);
    check('«Премахни изображението» empties the field at once (no confirmation)', await page.locator('.mpf-none').count() === 1);
    await page.getByRole('button', { name: /Запази|Save/ }).first().click();
    await settle();

    // ================================================== an HTML block: list picture + picture inside the text ============================
    await page.goto(BASE + '/admin/html-blocks/create', { waitUntil: 'networkidle' });
    await page.locator('input[type=text]').first().fill('ML2 Block');
    await page.getByRole('button', { name: /Добави снимка|Add picture/ }).first().click().catch(() => {});
    await sleep(900);
    await openModal('Добави изображение');
    await search('ml2-a');
    await modal().locator('.mp-card').first().dblclick();
    await sleep(1500);
    check('a double click in the modal selects and confirms in one go', await page.locator('.mpf-one .mpf-big').count() >= 1);

    const tool = () => page.locator('.fi-fo-rich-editor button[aria-label="Снимка"]').first();
    await page.locator('.ProseMirror').first().click();
    await page.keyboard.type('Text before ');

    // the text's picture: first upload it from the editor's modal, wait until its sizes exist, then pick it again with the size
    await tool().click();
    await page.waitForSelector('.fi-modal-window:visible .mp', { timeout: 20000 });
    await sleep(600);
    await modal().getByRole('tab', { name: 'Качи файлове' }).click();
    await modal().locator('input[type=file]').setInputFiles([join(FX, 'ml2-b.png')]);
    await modal().locator('.mp-card-on').first().waitFor({ timeout: 30000 });
    check('the editor modal asks for the size, «Голям» by default', await modal().locator('.mp-size input[value="large"]').isChecked());
    await shot('06-editor-modal-1400');
    await modal().getByRole('button', { name: 'Отказ', exact: true }).click();
    await sleep(7000); // the worker makes the sizes

    await tool().click();
    await page.waitForSelector('.fi-modal-window:visible .mp', { timeout: 20000 });
    await sleep(600);
    await search('ml2-b');
    await modal().locator('.mp-card').first().click();
    await sleep(900);
    await modal().locator('.mp-size input[value="medium"]').check();
    await modal().getByRole('button', { name: 'Избери', exact: true }).click();
    await sleep(2500);
    const inserted = await page.locator('.ProseMirror img').count() > 0;
    const src = inserted ? await page.locator('.ProseMirror img').first().getAttribute('src') : '';
    check('the picture is inserted into the text at the cursor', inserted);
    check('its address is our own storage, the medium size', src.includes('/storage/uploads/') && src.endsWith('-medium.webp'), src);
    await page.getByRole('button', { name: /Създай|Create/ }).first().click();
    await settle();
    check('the block is saved', ! page.url().endsWith('/create'), page.url());

    // ================================================== ML-3: product photos ============================================================
    const PRODUCT = process.env.ML_PRODUCT;
    const stateOf = (name) => page.evaluate((n) => window.Livewire.all().map((c) => c.$wire.get(n)).find((v) => v !== undefined && v !== null && ! (Array.isArray(v) && v.length === 0)), name);
    const closed = async () => { await page.waitForSelector('.fi-modal-window:visible', { state: 'detached', timeout: 15000 }).catch(() => {}); await sleep(1200); };
    if (PRODUCT) {
        await page.setViewportSize({ width: 1400, height: 1000 });
        await page.goto(BASE + `/admin/products/${PRODUCT}/edit`, { waitUntil: 'networkidle' });
        await page.waitForSelector('.mpf', { timeout: 30000 });
        const mainF = () => page.locator('.mpf').nth(0);
        const galF = () => page.locator('.mpf').nth(1);
        const vidF = () => page.locator('.mpf').nth(2);
        const order = async () => await galF().locator('.mpf-tile').evaluateAll((els) => els.map((e) => e.getAttribute('x-sortable-item')));
        const mainSrc = async () => await mainF().locator('.mpf-big img').first().getAttribute('src');

        check('the product has three pickers: main, gallery, video', await page.locator('.mpf').count() === 3);
        check('the main photo is shown large', await mainF().locator('.mpf-one .mpf-big img').count() === 1);
        check('the gallery is a drag-and-drop grid with ONE «Добави изображения» button', await galF().locator('[x-sortable]').count() === 1 && await galF().getByRole('button', { name: 'Добави изображения', exact: true }).count() === 1);
        const countBefore = (await order()).length;
        await mainF().scrollIntoViewIfNeeded();
        await shot('20-product-main-before-1400', mainF());
        await shot('20b-product-gallery-before-1400', galF());

        // three pictures uploaded through the gallery picker
        await galF().getByRole('button', { name: 'Добави изображения', exact: true }).click();
        await page.waitForSelector('.fi-modal-window:visible .mp', { timeout: 20000 });
        await sleep(600);
        await modal().getByRole('tab', { name: 'Качи файлове' }).click();
        await modal().locator('input[type=file]').setInputFiles([join(FX, 'ml3-a.png'), join(FX, 'ml3-b.png'), join(FX, 'ml3-c.png')]);
        await modal().locator('.mp-card-on').first().waitFor({ timeout: 40000 });
        check('three pictures uploaded in the gallery modal come back selected', await modal().locator('.mp-card-on').count() === 3, await modal().locator('.mp-card-on').count());
        await modal().getByRole('button', { name: /Избери \(3\)/ }).click();
        await closed();
        check('the gallery gained the three uploaded pictures', (await order()).length === countBefore + 3, JSON.stringify(await order()));

        // the MAIN photo from the library
        const mainBefore = await mainSrc();
        await mainF().getByRole('button', { name: 'Смени', exact: true }).click();
        await page.waitForSelector('.fi-modal-window:visible .mp', { timeout: 20000 });
        await sleep(600);
        await search('ml3-a');
        check('the product modal lists the big enough picture (ml3-a is 720px)', await modal().locator('.mp-card').count() === 1);
        await modal().locator('.mp-card').first().click();
        await sleep(700);
        await modal().getByRole('button', { name: 'Избери', exact: true }).click();
        await closed();
        check('the main photo changed', (await mainSrc()) !== mainBefore);
        const mainId = String(await stateOf('data.main_photo_id'));
        check('the main photo id is a plain number in the state', /^\d+$/.test(mainId), mainId);
        const tileForMain = galF().locator(`.mpf-tile[x-sortable-item="${mainId}"]`);
        check('the picture picked as main is also in the gallery (it was uploaded there first)', await tileForMain.count() === 1);
        await tileForMain.locator('.mpf-x').click();
        await sleep(900);
        check('«×» takes a thumbnail out of the gallery', (await order()).length === countBefore + 2 && await tileForMain.count() === 0);

        // one picked from the library: take the last one out and pick it again
        const cId = (await order()).slice(-1)[0];
        await galF().locator(`.mpf-tile[x-sortable-item="${cId}"] .mpf-x`).click();
        await sleep(800);
        await galF().getByRole('button', { name: 'Добави изображения', exact: true }).click();
        await page.waitForSelector('.fi-modal-window:visible .mp', { timeout: 20000 });
        await sleep(600);
        await search('ml3-c');
        await modal().locator('.mp-card').first().click();
        await sleep(700);
        await modal().getByRole('button', { name: /Избери/ }).click();
        await closed();
        const afterPick = await order();
        check('a picture picked from the library is added at the end of the gallery', afterPick.length === countBefore + 2 && afterPick.slice(-1)[0] === cId, JSON.stringify(afterPick));

        // keyboard reorder: ← → only while a thumbnail has focus
        const lastTile = galF().locator('.mpf-tile').last();
        const opacityOf = async () => await lastTile.locator('.mpf-order').evaluate((e) => getComputedStyle(e).opacity);
        await page.mouse.move(5, 5);
        await page.evaluate(() => document.activeElement && document.activeElement.blur());
        check('the ← → buttons are hidden while nothing in the thumbnail has focus', (await opacityOf()) === '0', await opacityOf());
        await lastTile.locator('.mpf-x').focus();
        check('and visible while a button of the thumbnail has focus', (await opacityOf()) === '1', await opacityOf());
        await shot('21-gallery-focus-1400', galF());
        const beforeKeys = await order();
        await lastTile.getByRole('button', { name: 'Премести наляво' }).click();
        await sleep(900);
        const afterKeys = await order();
        check('← moves the thumbnail one place left', afterKeys[afterKeys.length - 2] === beforeKeys[beforeKeys.length - 1] && afterKeys[afterKeys.length - 1] === beforeKeys[beforeKeys.length - 2], JSON.stringify([beforeKeys, afterKeys]));

        // drag and drop: the last thumbnail to the first place
        const tiles = galF().locator('.mpf-tile');
        const beforeDrag = await order();
        // real mouse movements in small steps (SortableJS starts a drag only after the pointer has moved)
        const from = await tiles.last().boundingBox();
        const to = await tiles.first().boundingBox();
        await page.mouse.move(from.x + from.width / 2, from.y + from.height / 3);
        await page.mouse.down();
        await page.mouse.move(from.x + from.width / 2 - 8, from.y + from.height / 3 - 8, { steps: 4 });
        await page.mouse.move(to.x + 6, to.y + to.height / 3, { steps: 25 });
        await page.mouse.up();
        await sleep(1200);
        const afterDrag = await order();
        check('drag and drop moves the last thumbnail to the first place', afterDrag[0] === beforeDrag[beforeDrag.length - 1], JSON.stringify([beforeDrag, afterDrag]));
        const stateOrder = await stateOf('data.gallery_photo_ids');
        check('the dragged order reached the form state as ids', JSON.stringify((stateOrder ?? []).map(String)) === JSON.stringify(afterDrag), JSON.stringify([stateOrder, afterDrag]));
        await shot('22-product-gallery-ordered-1400', galF());

        await page.getByRole('button', { name: /Запази|Save/ }).first().click();
        await settle();
        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForSelector('.mpf', { timeout: 30000 });
        check('after saving and reloading the main photo and the gallery order are the saved ones', JSON.stringify(await order()) === JSON.stringify(afterDrag) && String(await stateOf('data.main_photo_id')) === mainId, JSON.stringify(await order()));
        console.log('ML3_SAVED ' + JSON.stringify({ main: mainId, gallery: afterDrag }));

        // remove one photo and save
        const victim = (await order())[0];
        await galF().locator(`.mpf-tile[x-sortable-item="${victim}"] .mpf-x`).click();
        await sleep(800);
        await page.getByRole('button', { name: /Запази|Save/ }).first().click();
        await settle();
        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForSelector('.mpf', { timeout: 30000 });
        check('the removed photo is gone from the product after saving', ! (await order()).includes(victim));
        console.log('ML3_REMOVED ' + victim);
        await shot('23-product-gallery-after-1400', galF());

        check('the video field offers «Добави видео» (or shows the video)', await vidF().getByRole('button', { name: /Добави видео|Смени/ }).count() === 1);
        await shot('24-video-field-1400', vidF());

        await page.setViewportSize({ width: 390, height: 900 });
        await page.goto(BASE + `/admin/products/${PRODUCT}/edit`, { waitUntil: 'networkidle' });
        await page.waitForSelector('.mpf', { timeout: 30000 });
        await mainF().scrollIntoViewIfNeeded();
        await shot('25-product-main-390', mainF());
        await galF().scrollIntoViewIfNeeded();
        await shot('26-product-gallery-390', galF());
        check('at 390px the product page does not scroll sideways', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
        await page.setViewportSize({ width: 1400, height: 1000 });
    }

    const VARIABLE = process.env.ML_VARIABLE_PRODUCT;
    if (VARIABLE) {
        await page.goto(BASE + `/admin/products/${VARIABLE}/edit-variable`, { waitUntil: 'networkidle' });
        await page.waitForSelector('.mpf', { state: 'attached', timeout: 30000 });
        await page.getByRole('tab', { name: /Варианти|Variations/ }).first().click().catch(() => {});
        await sleep(800);
        const rowPicker = () => page.locator('[wire\\:key$=".variation_photo_ids"] .mpf').first();
        if (! await rowPicker().isVisible().catch(() => false)) {
            await page.locator('.fi-fo-repeater-item-header:visible').first().click().catch(() => {});
            await sleep(800);
        }
        check('a variation row has its photo picker (a list, ONE «Добави изображения» button)', await rowPicker().getByRole('button', { name: 'Добави изображения', exact: true }).count() === 1);
        await rowPicker().scrollIntoViewIfNeeded();
        await rowPicker().getByRole('button', { name: 'Добави изображения', exact: true }).click();
        await page.waitForSelector('.fi-modal-window:visible .mp', { timeout: 20000 });
        await sleep(600);
        // ml3-b is in the library already after the product stage; on a stand-alone run the modal's upload tab puts it there
        await search('ml3-b');
        if (await modal().locator('.mp-card').count() === 0) {
            await modal().getByRole('tab', { name: 'Качи файлове' }).click();
            await modal().locator('input[type=file]').setInputFiles([join(FX, 'ml3-b.png')]);
            await modal().locator('.mp-card-on').first().waitFor({ timeout: 40000 });
        } else {
            await modal().locator('.mp-card').first().click();
            await sleep(700);
        }
        await modal().getByRole('button', { name: /Избери/ }).click();
        await closed();
        check('the picked photo is in the variation row', await rowPicker().locator('.mpf-tile').count() >= 1);
        await shot('27-variation-photos-1400', rowPicker());
        await page.getByRole('button', { name: /Запази|Save/ }).first().click();
        await settle();
        console.log('ML3_VARIATION_SAVED');
    }

    // ================================================== phone width =============================================================================
    await page.setViewportSize({ width: 390, height: 900 });
    await page.goto(BASE + '/admin/brands/create', { waitUntil: 'networkidle' });
    await shot('07-field-empty-390', page.locator('.mpf').first());
    await openModal('Добави изображение');
    await modal().locator('.mp-card').first().click();
    await sleep(900);
    await shot('08-modal-selected-390');
    check('at 390px the side column sits under the grid and nothing scrolls sideways', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    await page.keyboard.press('Escape');
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' '));
    await shot('99-error').catch(() => {});
}

check('no console errors or failed requests', consoleErrors.length === 0, consoleErrors.slice(0, 6).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
