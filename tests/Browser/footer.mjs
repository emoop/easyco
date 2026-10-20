// A REAL-BROWSER run of the footer builder (FT-1, «Дизайн» -> «Футър»). It is NOT part of `php artisan test`: it is run by hand against a dev site whose data may be changed
// (back up the footer keys / menus first and restore after, like tests/Browser/menu-editor.mjs).
//
//   AUDIT_BASE=https://easyco-main.test AUDIT_EMAIL=... AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] \
//   [OUT=<folder for screenshots>] node tests/Browser/footer.mjs
//
// Needs: a staff account with the Administrator role and the test data of the task's prep script: the menus «FT1 Shop», «FT1 Help», «FT1 Company», «FT1 Legal» and the HTML block
// «FT1 browser banner».
//
// It builds THE OWNER'S LAYOUT with real clicks (structure: columns, rows, blocks; values: typing and choosing in the selects):
//   upper level, row 1 = four menu columns (each with a heading) + a newsletter column; row 2 = social networks at the start + payment methods at the end (a wide column);
//   lower level = «Fetish Харманли © [year] – управляван от МериНед ООД»; colours and a border on both levels.
// Then: save the draft -> reload the page and compare what came back -> «Преглед» (the draft shows in a new tab, the public page does NOT) -> «Публикувай» -> the public page shows it
// (1280px and 375px screenshots, the year is the clock's, no `style=`, no sideways scroll at 320 and 375px). Prints a JSON report; exit code 1 when a check failed.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const EMAIL = process.env.AUDIT_EMAIL ?? 'ux-audit@example.test';
const PASSWORD = process.env.AUDIT_PASSWORD;
const OUT = process.env.OUT ?? '';

if (! PASSWORD || ! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> node footer.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });
const ctx = await browser.newContext({ viewport: { width: 1500, height: 2600 }, ignoreHTTPSErrors: true });
const page = await ctx.newPage();

const steps = [];
const consoleErrors = [];
page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 300)); });
page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.stack || e).replace(/\s+/g, ' ').slice(0, 600)));

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const check = (name, ok, detail = '') => { steps.push({ name, ok: !!ok, detail: String(detail).slice(0, 400) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const shot = async (p, name) => { if (OUT) await p.screenshot({ path: join(OUT, name + '.png'), fullPage: true }); };

// Items of the nested repeaters / the builder are told apart by their wire:key.
const ROWS = 'li[wire\\:key*=".rows."][wire\\:key$=".item"]:not([wire\\:key*=".columns."])';
const COLUMNS = 'li[wire\\:key*=".columns."][wire\\:key$=".item"]:not([wire\\:key*=".blocks."])';
const BLOCKS = 'li[wire\\:key*=".blocks."][wire\\:key$=".item"]';
const row = (r) => page.locator(ROWS).nth(r);
const column = (r, c) => row(r).locator(COLUMNS).nth(c);
const block = (r, c, b) => column(r, c).locator(BLOCKS).nth(b);

async function login() {
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
}

async function openFooter() {
    await page.goto(BASE + '/admin/footer', { waitUntil: 'networkidle' });
    await page.waitForSelector('form#form', { timeout: 20000 });
    await sleep(500);
}

async function settle() {
    await page.waitForLoadState('networkidle');
    await sleep(700);
}

/** Types into a field found by the end of its wire:model path, inside a scope. */
async function type(scope, path, value) {
    const input = scope.locator(`input[id$="${path}"], textarea[id$="${path}"]`).first();
    await input.fill(value);
    await input.blur();
    await sleep(700);
}

/** Chooses an option of a Filament (non-native) select: the field is found by its label inside the scope. */
async function choose(scope, label, option) {
    const field = scope.locator('.fi-fo-field', { has: page.locator('label, .fi-fo-field-label', { hasText: label }) }).first();
    await field.locator('button.fi-select-input-btn').first().click();
    await sleep(300);
    await page.locator('.fi-select-input-option:visible').filter({ hasText: new RegExp('^\\s*' + option.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*$') }).first().click();
    await settle();
}

async function addBlock(scope, typeLabel) {
    await scope.getByRole('button', { name: 'Добави блок' }).last().click();
    await sleep(350);
    await scope.locator('.fi-dropdown-list-item:visible').filter({ hasText: new RegExp('^\\s*' + typeLabel + '\\s*$') }).first().click();
    await settle();
}

async function expand(blockLocator) {
    const collapsed = await blockLocator.evaluate((el) => el.classList.contains('fi-collapsed'));
    collapsed && await blockLocator.locator('.fi-fo-builder-item-header').first().click();
    await sleep(300);
}

const wireData = () => page.evaluate(() => {
    const id = document.querySelector('[wire\\:id]')?.getAttribute('wire:id');
    const found = window.Livewire.all().find((c) => c.name.toLowerCase().includes('footer'));

    return JSON.parse(JSON.stringify((found ?? window.Livewire.find(id)).$wire.data));
});

/** A structural summary of the form's data: per row, per column: [span, align, [block types]] (keys are random ids, so only the order is compared). */
const shape = (data) => Object.values(data.upper.rows).map((r) => Object.values(r.columns).map((c) => [String(c.span), c.align, Object.values(c.blocks).map((b) => b.type)]));

try {
    await login();
    await openFooter();

    // ---- the upper level: look and feel -------------------------------------------------------------------------------------------------------
    await choose(page.locator('section, .fi-section').first(), 'Отстояние отвътре', 'Широко');
    await type(page, '.upper.bg', '#111111');
    await type(page, '.upper.text', '#f5f5f5');
    await type(page, '.upper.border_color', '#c0392b');
    await choose(page.locator('section, .fi-section').first(), 'Дебелина на рамката', '2 px');

    // ---- row 1: four menu columns + a newsletter column ----------------------------------------------------------------------------------------
    for (let i = 0; i < 4; i++) {
        await row(0).getByRole('button', { name: 'Добави колона' }).click();
        await settle();
    }

    check('row 1 has five columns', (await row(0).locator(COLUMNS).count()) === 5, await row(0).locator(COLUMNS).count());
    check('a new column starts with a width and an alignment (1 дял, В началото)', (await column(0, 4).textContent()).includes('1 дял') && (await column(0, 4).textContent()).includes('В началото'));

    const menus = ['FT1 Shop', 'FT1 Help', 'FT1 Company', 'FT1 Legal'];
    const headings = ['Магазин', 'Помощ', 'Фирма', 'Правила'];

    for (let c = 0; c < 4; c++) {
        if (c > 0) {
            await addBlock(column(0, c), 'Меню');
        }

        const b = block(0, c, 0);
        await expand(b);
        await choose(b, 'Меню', menus[c]);
        await type(b, '.heading', headings[c]);
    }

    await addBlock(column(0, 4), 'Бюлетин');
    const news = block(0, 4, 0);
    await expand(news);
    await type(news, '.heading', 'Бюлетин');
    await type(news, '.placeholder', 'Вашият имейл');
    await type(news, '.button', 'Запиши ме');
    await type(news, '.consent', 'Съгласявам се с условията.');
    check('the newsletter block tells it shows only once subscription exists', (await news.textContent()).includes('Показва се в сайта, когато има записване за бюлетин'));
    await shot(page, 'footer-admin-row1');

    // ---- row 2: social at the start, payments at the end (a wide column) -----------------------------------------------------------------------
    await page.getByRole('button', { name: 'Добави ред' }).click();
    await settle();
    check('two rows', (await page.locator(ROWS).count()) === 2);
    await row(1).getByRole('button', { name: 'Добави колона' }).click();
    await settle();
    check('row 2 has two columns', (await row(1).locator(COLUMNS).count()) === 2);

    await addBlock(column(1, 0), 'Социални мрежи');
    const social = block(1, 0, 0);
    await expand(social);

    await type(social, '.n_instagram', 'https://instagram.com/fetish.harmanli');
    await type(social, '.n_facebook', 'https://facebook.com/fetish.harmanli');

    await choose(column(1, 1), 'Ширина', '2 дяла');
    await choose(column(1, 1), 'Подравняване', 'В края');
    await addBlock(column(1, 1), 'Начини на плащане');
    const pay = block(1, 1, 0);
    await expand(pay);

    for (const method of ['Mastercard', 'Visa', 'Наложен платеж']) {
        await pay.getByLabel(method, { exact: true }).check();
        await sleep(250);
    }

    await shot(page, 'footer-admin-row2');

    // ---- the lower level ---------------------------------------------------------------------------------------------------------------------
    await type(page, '.lower.text_template', 'Fetish Харманли © [year] – управляван от МериНед ООД');
    await type(page, '.lower.bg', '#000000');
    await type(page, '.lower.text', '#dddddd');
    await choose(page, 'Подравняване на реда', 'В началото');

    const built = await wireData();
    const builtShape = shape(built);
    check('the form holds the owner\'s layout', JSON.stringify(builtShape) === JSON.stringify([
        [['1', 'start', ['menu']], ['1', 'start', ['menu']], ['1', 'start', ['menu']], ['1', 'start', ['menu']], ['1', 'start', ['newsletter']]],
        [['1', 'start', ['social']], ['2', 'end', ['payments']]],
    ]), JSON.stringify(builtShape));

    // ---- a refused save: a social address that is not https ------------------------------------------------------------------------------------------
    const bad = block(1, 0, 0).locator('input[id$=".n_facebook"]').first();
    await bad.fill('javascript:alert(1)');
    await bad.blur();
    await sleep(400);
    await page.getByRole('button', { name: 'Запази чернова' }).click();
    await settle();
    check('a javascript: address is refused on its own field', (await block(1, 0, 0).textContent()).includes('https://'), '');
    await bad.fill('https://facebook.com/fetish.harmanli');
    await bad.blur();
    await sleep(400);

    // ---- save the draft, reload, compare ---------------------------------------------------------------------------------------------------------
    await page.getByRole('button', { name: 'Запази чернова' }).click();
    await settle();
    check('the draft is saved (notice)', await page.getByText('Черновата е запазена').first().isVisible().catch(() => false));
    await openFooter();
    const reloaded = await wireData();
    check('after a reload the shape is the same', JSON.stringify(shape(reloaded)) === JSON.stringify(builtShape), JSON.stringify(shape(reloaded)));
    check('after a reload the values are the same', reloaded.upper.bg === '#111111' && reloaded.upper.border_color === '#c0392b' && String(reloaded.upper.border_width) === '2' && reloaded.upper.padding === 'spacious'
        && reloaded.lower.text_template === 'Fetish Харманли © [year] – управляван от МериНед ООД' && reloaded.lower.align === 'start' && reloaded.lower.bg === '#000000', JSON.stringify(reloaded.lower));
    const reloadedPay = Object.values(Object.values(reloaded.upper.rows)[1].columns)[1];
    check('the payment methods and their order survive', JSON.stringify(Object.values(reloadedPay.blocks)[0].data.methods) === JSON.stringify(['mastercard', 'visa', 'cash_on_delivery']), JSON.stringify(Object.values(reloadedPay.blocks)[0].data.methods));

    // ---- the public page does NOT show the draft; the preview does -----------------------------------------------------------------------------
    const anon = await browser.newContext({ viewport: { width: 1280, height: 900 }, ignoreHTTPSErrors: true });
    const pub = await anon.newPage();
    await pub.goto(BASE + '/', { waitUntil: 'networkidle' });
    check('the public page still has the old footer (a draft is not public)', (await pub.locator('footer.sf-footer.sf-ft').count()) === 0 && (await pub.locator('footer .sf-footer-inner').count()) === 1);

    const [popup] = await Promise.all([ctx.waitForEvent('page'), page.getByRole('button', { name: 'Преглед' }).click()]);
    await popup.waitForLoadState('networkidle');
    check('the preview shows the draft footer', (await popup.locator('footer.sf-ft .sf-ft-col').count()) >= 5, await popup.locator('footer.sf-ft .sf-ft-col').count());
    await popup.locator('footer').scrollIntoViewIfNeeded();
    OUT && await popup.locator('footer.sf-footer').screenshot({ path: join(OUT, 'footer-preview-1280.png') });
    await popup.close();

    // ---- publish -------------------------------------------------------------------------------------------------------------------------------------
    await page.getByRole('button', { name: 'Публикувай' }).click();
    await sleep(500);
    await page.locator('.fi-modal-window button', { hasText: /Потвърди|Публикувай|Confirm/ }).last().click();
    await settle();
    check('published (notice)', await page.getByText('Футърът е публикуван').first().isVisible().catch(() => false));

    await pub.goto(BASE + '/', { waitUntil: 'networkidle' });
    const footer = pub.locator('footer.sf-footer.sf-ft');
    check('the public page shows the new footer', (await footer.count()) === 1);
    check('four columns in row 1 (the newsletter column has no output yet, so it is not printed) and two in row 2', (await footer.locator('.sf-ft-row').first().locator('.sf-ft-col').count()) === 4 && (await footer.locator('.sf-ft-row').nth(1).locator('.sf-ft-col').count()) === 2);
    check('the four menus have their headings', (await footer.locator('.sf-ft-menu .sf-ft-heading').allTextContents()).join('|') === 'Магазин|Помощ|Фирма|Правила', (await footer.locator('.sf-ft-menu .sf-ft-heading').allTextContents()).join('|'));
    check('the newsletter block is NOT printed while there is no subscription route', (await footer.locator('.sf-ft-newsletter').count()) === 0);
    check('three payment marks and two social icons', (await footer.locator('.sf-pay-icon').count()) === 3 && (await footer.locator('.sf-ft-social li').count()) === 2);
    const year = new Date().getFullYear();
    check('the lower line has the current year', (await footer.locator('.sf-ft-line').textContent()).trim() === `Fetish Харманли © ${year} – управляван от МериНед ООД`, await footer.locator('.sf-ft-line').textContent());
    const html = await footer.evaluate((el) => el.outerHTML);
    check('no style= anywhere in the footer', ! /\sstyle=/.test(html));
    const colours = await pub.evaluate(() => {
        const up = getComputedStyle(document.querySelector('.sf-ft-upper'));
        const lo = getComputedStyle(document.querySelector('.sf-ft-lower'));

        return { upBg: up.backgroundColor, upColor: up.color, border: up.borderTopWidth + ' ' + up.borderTopColor, loBg: lo.backgroundColor, loColor: lo.color };
    });
    check('colours and the border come from the token stylesheet', colours.upBg === 'rgb(17, 17, 17)' && colours.border.startsWith('2px rgb(192, 57, 43)') && colours.loBg === 'rgb(0, 0, 0)' && colours.loColor === 'rgb(221, 221, 221)', JSON.stringify(colours));

    // ---- the shop at 1280 and 375, and the overflow check at 320 / 375 ------------------------------------------------------------------------
    await pub.locator('footer.sf-footer').scrollIntoViewIfNeeded();
    OUT && await pub.locator('footer.sf-footer').screenshot({ path: join(OUT, 'footer-owner-1280.png') });

    for (const width of [375, 320]) {
        const phone = await (await browser.newContext({ viewport: { width, height: 900 }, ignoreHTTPSErrors: true })).newPage();
        await phone.goto(BASE + '/', { waitUntil: 'networkidle' });
        await phone.locator('footer.sf-footer').scrollIntoViewIfNeeded();
        OUT && await phone.locator('footer.sf-footer').screenshot({ path: join(OUT, `footer-owner-${width}.png`) });
        const overflow = await phone.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
        check(`no sideways scroll at ${width}px`, overflow.sw <= overflow.cw, JSON.stringify(overflow));
        const stacked = await phone.evaluate(() => { const cols = [...document.querySelectorAll('.sf-ft-row')[0].querySelectorAll('.sf-ft-col')]; return new Set(cols.map((c) => Math.round(c.getBoundingClientRect().left))).size === 1; });
        check(`the columns stack at ${width}px`, stacked);
        await phone.context().close();
    }
} catch (error) {
    check('the run finished', false, String(error.stack || error).slice(0, 500));
    await shot(page, 'footer-failure').catch(() => {});
}

check('no console errors in the admin page', consoleErrors.length === 0, consoleErrors.join(' | '));
console.log(JSON.stringify({ passed: steps.filter((s) => s.ok).length, failed: steps.filter((s) => ! s.ok), total: steps.length }, null, 2));
await browser.close();
process.exit(steps.every((s) => s.ok) ? 0 : 1);
