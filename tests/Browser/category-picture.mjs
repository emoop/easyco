// A REAL-BROWSER run of CT-1, a picture for a category and for an attribute value (a season, a colour ...), chosen with the media picker. NOT part of `php artisan test`: run by hand against a dev site whose
// data may be changed. BACK UP the picture columns and sort orders of the rows it touches first, and restore them after; the pictures it uploads («ct1-…») are deleted afterwards.
//
//   AUDIT_BASE=http://easyco-main.test AUDIT_EMAIL=... AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] \
//   CT_FIXTURES=<dir with ct1-a.jpg ct1-b.jpg> CT_CATEGORY=<id of a category> CT_VALUES=<id,id,id of three values of one attribute> OUT=<folder for screenshots> node tests/Browser/category-picture.mjs
//
// With real clicks: a category → the empty field → «Добави изображение» → a picture from «Медия» → save → reload, it is there → «Смени» (a new picture uploaded in the modal) → save → reload →
// «Премахни изображението» → save → reload, the field is empty and the file is still in «Медия». Two values of an attribute get a picture each → save → reload; one value is moved in the order and keeps its
// picture. «Медия» → the picture → «Къде се ползва» lists the category and the value; «Изтрий» is refused with the reason. Prints a JSON report; exit code 1 when a check failed.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const EMAIL = process.env.AUDIT_EMAIL ?? 'ct1-audit@example.test';
const PASSWORD = process.env.AUDIT_PASSWORD;
const FX = process.env.CT_FIXTURES;
const CATEGORY = process.env.CT_CATEGORY;
const VALUES = (process.env.CT_VALUES ?? '').split(',').filter(Boolean);
const OUT = process.env.OUT ?? '';

if (! PASSWORD || ! process.env.PLAYWRIGHT_DIR || ! FX || ! CATEGORY || VALUES.length < 2) {
    console.error('usage: AUDIT_PASSWORD=... PLAYWRIGHT_DIR=... CT_FIXTURES=... CT_CATEGORY=6 CT_VALUES=1,16,17 node category-picture.mjs');
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
const settle = async () => { await page.waitForLoadState('networkidle'); await sleep(900); };
const modal = () => page.locator('.fi-modal-window:visible').last();
const field = () => page.locator('.mpf').first();
const closed = async () => { await page.waitForSelector('.fi-modal-window:visible', { state: 'detached', timeout: 15000 }).catch(() => {}); await sleep(1200); };
const fieldBox = () => field().locator('xpath=ancestor::div[contains(@class,"fi-fo-field")][1]');

async function login() {
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
}

async function open(path) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    await page.waitForSelector('.mpf', { timeout: 30000 });
}

async function save() {
    await page.getByRole('button', { name: /Запази|Save/ }).first().click();
    await settle();
}

/** the first card of the library (the newest ready picture) */
async function pickFirst(buttonName) {
    await field().getByRole('button', { name: buttonName, exact: true }).click();
    await page.waitForSelector('.fi-modal-window:visible .mp', { timeout: 20000 });
    await sleep(700);
    await modal().locator('.mp-card').first().click();
    await sleep(700);
    await modal().getByRole('button', { name: 'Избери', exact: true }).click();
    await closed();
}

const srcOf = async () => await field().locator('.mpf-big img').first().getAttribute('src').catch(() => null);

try {
    await login();

    // ================================================== a category ========================================================================
    await open(`/admin/categories/${CATEGORY}/edit`);
    const text = await page.locator('body').innerText();
    check('the field «Снимка» sits after the slug', text.indexOf('Снимка') > text.indexOf('Слъг') && text.indexOf('Слъг') > text.indexOf('Име'));
    check('empty: «Няма избрана снимка» and ONE button «Добави изображение»', (await field().innerText()).includes('Няма избрана снимка') && await field().getByRole('button', { name: 'Добави изображение', exact: true }).count() === 1);
    check('under the field the owners help line', text.includes('Показва се в страницата на категорията и в плочките с категории. Снимката остава в Медия, ако я махнете оттук.'));
    await shot('01-category-empty-1400', fieldBox());

    await pickFirst('Добави изображение');
    const picked = await srcOf();
    check('after «Избери» the picture is shown whole under «Смени» and «Премахни изображението»', picked !== null && await field().getByRole('button', { name: 'Смени', exact: true }).count() === 1 && await field().getByRole('button', { name: 'Премахни изображението', exact: true }).count() === 1);
    await shot('02-category-filled-1400', fieldBox());
    await save();
    await open(`/admin/categories/${CATEGORY}/edit`);
    check('after a save and a reload the picture is there', (await srcOf()) === picked, await srcOf());

    // change it by uploading a new one in the modal
    await field().getByRole('button', { name: 'Смени', exact: true }).click();
    await page.waitForSelector('.fi-modal-window:visible .mp', { timeout: 20000 });
    await sleep(600);
    await modal().getByRole('tab', { name: 'Качи файлове' }).click();
    await sleep(500);
    await modal().locator('input[type=file]').setInputFiles([join(FX, 'ct1-a.jpg')]);
    await modal().locator('.mp-card-on').first().waitFor({ timeout: 40000 });
    await sleep(9000); // the worker makes the sizes
    await modal().getByRole('button', { name: 'Избери', exact: true }).click();
    await closed();
    await save();
    await open(`/admin/categories/${CATEGORY}/edit`);
    const uploaded = await srcOf();
    check('«Смени» with an uploaded picture: after a reload it is the new one', uploaded !== null && uploaded !== picked, uploaded);

    // a save that does not touch the field keeps it
    await page.getByLabel(/Име/).first().fill(await page.getByLabel(/Име/).first().inputValue());
    await save();
    await open(`/admin/categories/${CATEGORY}/edit`);
    check('a save that does not touch the field keeps the picture', (await srcOf()) === uploaded);

    // remove it: only detached
    await field().getByRole('button', { name: 'Премахни изображението', exact: true }).click();
    await sleep(700);
    check('«Премахни изображението» empties the field at once', (await field().innerText()).includes('Няма избрана снимка'));
    await save();
    await open(`/admin/categories/${CATEGORY}/edit`);
    check('after a reload the field is empty', (await field().innerText()).includes('Няма избрана снимка'));
    await page.goto(BASE + '/admin/media', { waitUntil: 'networkidle' });
    await page.getByPlaceholder(/Търси|Search/).first().fill('ct1-a');
    await sleep(2500);
    check('the removed picture is still in «Медия»', await page.getByText('ct1-a.jpg').count() >= 1);

    // the category list: a small thumbnail like the brand list
    await open(`/admin/categories/${CATEGORY}/edit`).catch(() => {});
    await field().getByRole('button', { name: 'Добави изображение', exact: true }).click();
    await page.waitForSelector('.fi-modal-window:visible .mp', { timeout: 20000 });
    await sleep(600);
    await modal().locator('.mp-search').fill('ct1-a');
    await sleep(1500);
    await modal().locator('.mp-card').first().click();
    await sleep(600);
    await modal().getByRole('button', { name: 'Избери', exact: true }).click();
    await closed();
    await save();
    await page.goto(BASE + '/admin/categories', { waitUntil: 'networkidle' });
    check('the category list shows a small thumbnail', await page.locator('img[src*="-thumbnail.webp"]').count() >= 1);
    await shot('03-category-list-1400');

    // ================================================== attribute values ==================================================================
    const [v1, v2, v3] = VALUES;
    await open(`/admin/attribute-values/${v1}/edit`);
    check('the value form has the same single field after the other fields', (await field().innerText()).includes('Няма избрана снимка') && (await page.locator('body').innerText()).indexOf('Снимка') > (await page.locator('body').innerText()).indexOf('Позиция'));
    await shot('04-value-empty-1400', fieldBox());
    await pickFirst('Добави изображение');
    const p1 = await srcOf();
    await shot('05-value-filled-1400', fieldBox());
    await save();
    await open(`/admin/attribute-values/${v2}/edit`);
    await field().getByRole('button', { name: 'Добави изображение', exact: true }).click();
    await page.waitForSelector('.fi-modal-window:visible .mp', { timeout: 20000 });
    await sleep(600);
    await modal().locator('.mp-card').nth(1).click();
    await sleep(600);
    await modal().getByRole('button', { name: 'Избери', exact: true }).click();
    await closed();
    const p2 = await srcOf();
    await save();
    check('two values got two different pictures', p1 !== null && p2 !== null && p1 !== p2, JSON.stringify([p1, p2]));

    // reorder: the first value goes to the end; every picture stays with its value
    await open(`/admin/attribute-values/${v1}/edit`);
    const sort = page.getByLabel(/Позиция|Ред|Sort/i).first();
    await sort.fill('99');
    await save();
    await open(`/admin/attribute-values/${v1}/edit`);
    check('after the value was moved in the order it keeps its own picture', (await srcOf()) === p1, await srcOf());
    await open(`/admin/attribute-values/${v2}/edit`);
    check('and the other value keeps its own', (await srcOf()) === p2, await srcOf());

    // ================================================== «Медия»: where it is used, and the refused delete ==================================
    // the category and the first value use the same (first) picture? the category uses ct1-a; give the first value ct1-a too
    await open(`/admin/attribute-values/${v1}/edit`);
    await field().getByRole('button', { name: 'Смени', exact: true }).click();
    await page.waitForSelector('.fi-modal-window:visible .mp', { timeout: 20000 });
    await sleep(600);
    await modal().locator('.mp-search').fill('ct1-a');
    await sleep(1500);
    await modal().locator('.mp-card').first().click();
    await sleep(600);
    await modal().getByRole('button', { name: 'Избери', exact: true }).click();
    await closed();
    await save();

    await page.goto(BASE + '/admin/media', { waitUntil: 'networkidle' });
    await page.getByPlaceholder(/Търси|Search/).first().fill('ct1-a');
    await sleep(2500);
    await page.getByText('ct1-a.jpg').first().click();
    await sleep(1800);
    const details = page.locator('.fi-modal-window:visible, .fi-slide-over-window:visible').last();
    const uses = await details.innerText().catch(() => '');
    check('«Къде се ползва» lists the category', /Категория „[^“]+“/.test(uses), uses.slice(0, 300));
    check('«Къде се ползва» lists the value with its attribute', /Стойност „[^“]+“ на атрибут „[^“]+“/.test(uses), uses.slice(0, 300));
    await shot('06-media-where-used-1400');
    const del = details.getByRole('button', { name: /Изтрий/ }).first();
    check('«Изтрий» is refused with the reason', (await del.isDisabled().catch(() => false)) || /Използва се на \d+ места/.test(uses), uses.slice(0, 300));
    await shot('07-media-delete-refused-1400');
    await page.keyboard.press('Escape');

    // ================================================== phone width ====================================================================
    await page.setViewportSize({ width: 390, height: 900 });
    await open(`/admin/categories/${CATEGORY}/edit`);
    await shot('08-category-filled-390', fieldBox());
    check('at 390px nothing scrolls sideways', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    await page.setViewportSize({ width: 1400, height: 1000 });
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' '));
    await shot('99-error').catch(() => {});
}

check('no console errors or failed requests', consoleErrors.length === 0, consoleErrors.slice(0, 6).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
