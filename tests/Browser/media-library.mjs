// A REAL-BROWSER run of the «Медия» page (ML-1a). It is NOT part of `php artisan test`: it is run by hand against a dev site whose data may be changed (it changes ONE alt text and puts it back).
//
//   AUDIT_BASE=https://easyco-main.test AUDIT_EMAIL=... AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] \
//   ML_ASSET_ID=<id of a picture that a product uses and whose file exists> ML_ASSET_NAME=<its original_name> ML_PRODUCT_ID=<that product's id> [OUT=<folder for screenshots>] node tests/Browser/media-library.mjs
//
// Needs: a throw-away staff account with the Administrator role (created and deleted by the person who runs this) and `media:backfill-meta --execute` run once. With real clicks:
//   open «Медия» -> the cards are there, some say «Файлът липсва» -> search the known name (one card) -> filter «Неизползвани» (the product photo is gone from the grid) and «Файлът липсва»
//   -> open the product photo's details -> «Къде се ползва» links to the product (same tab) -> change the alt text, save, open again (it is there) -> put it back.
// Prints a JSON report; exit code 1 when a check failed.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const EMAIL = process.env.AUDIT_EMAIL ?? 'ml1-audit@example.test';
const PASSWORD = process.env.AUDIT_PASSWORD;
const ASSET_NAME = process.env.ML_ASSET_NAME;
const PRODUCT_ID = process.env.ML_PRODUCT_ID;
const OUT = process.env.OUT ?? '';

if (! PASSWORD || ! process.env.PLAYWRIGHT_DIR || ! ASSET_NAME || ! PRODUCT_ID) {
    console.error('usage: AUDIT_PASSWORD=... PLAYWRIGHT_DIR=... ML_ASSET_NAME=... ML_PRODUCT_ID=... node media-library.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });
const ctx = await browser.newContext({ viewport: { width: 1400, height: 1100 }, ignoreHTTPSErrors: true });
const page = await ctx.newPage();

const steps = [];
const consoleErrors = [];
page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 300)); });
page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.stack || e).replace(/\s+/g, ' ').slice(0, 600)));
page.on('response', (r) => { if (r.status() >= 400 && ! r.url().includes('/storage/')) consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 160)); });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const check = (name, ok, detail = '') => { steps.push({ name, ok: !!ok, detail: String(detail).slice(0, 400) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const shot = async (name) => { if (OUT) await page.screenshot({ path: join(OUT, name + '.png'), fullPage: false }); };
const settle = async () => { await page.waitForLoadState('networkidle'); await sleep(700); };
const cards = () => page.locator('.ml-card');

async function login() {
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
}

async function search(text) {
    const box = page.locator('.fi-ta-search-field input').first();
    await box.fill(text);
    await sleep(1300); // the table search is debounced
    await settle();
}

async function openFilters() {
    const trigger = page.locator('.fi-ta-filters-dropdown, [x-ref="trigger"]').filter({ hasText: /Филтри|Filters/ }).first();
    if (await page.locator('.fi-dropdown-panel:visible').count() === 0) {
        await page.getByRole('button', { name: /Филтри|Filters/ }).first().click();
        await sleep(400);
    }
}

async function setFilter(label, optionText) {
    await openFilters();
    const field = page.locator('.fi-dropdown-panel:visible').locator('.fi-fo-field').filter({ hasText: label }).first();
    await field.locator('select').selectOption({ label: optionText });
    await settle();
}

async function resetFilters() {
    await openFilters();
    await page.locator('.fi-dropdown-panel:visible [wire\\:click="resetTableFiltersForm"]').first().click();
    await settle();
}

async function closeFilters() {
    await page.keyboard.press('Escape');
    await sleep(300);
}

try {
    await login();
    await page.goto(BASE + '/admin/media', { waitUntil: 'networkidle' });
    await page.waitForSelector('.ml-card', { timeout: 30000 });
    await sleep(500);
    await shot('01-grid');

    const n = await cards().count();
    check('the grid shows 48 cards on the first page (the library holds more)', n === 48, `cards: ${n}`);
    check('the title and the navigation entry exist', (await page.locator('h1').first().innerText()).includes('Медия') && (await page.locator('.fi-sidebar-item', { hasText: 'Медия' }).count()) > 0);
    check('some cards say the file is missing and show no image', (await page.locator('.ml-thumb-missing').count()) > 0 && (await page.locator('.ml-thumb-missing img').count()) === 0);

    // ---- search the known name -------------------------------------------------------------------------------------------------------------
    await search(ASSET_NAME);
    const found = await cards().count();
    check('searching the known file name finds exactly that card', found === 1 && (await cards().first().getAttribute('title')) === ASSET_NAME, `cards: ${found}`);
    check('the card shows the thumbnail variant, not the original', (await cards().first().locator('img').getAttribute('src'))?.includes('-thumbnail.webp') === true);
    check('the card has a usage badge with a place count', /място|места/.test(await cards().first().innerText()));
    await shot('02-search');
    await search('');

    // ---- the filters -----------------------------------------------------------------------------------------------------------------------
    await setFilter('Използване', 'Неизползвани');
    await closeFilters();
    const unused = await cards().count();
    check('«Неизползвани» keeps only pictures with the badge «неизползвана»', unused > 0 && (await cards().locator('.ml-badge-unused').count()) === unused, `cards: ${unused}`);
    await search(ASSET_NAME);
    check('the product photo is not among the unused ones', (await cards().count()) === 0);
    await search('');
    await shot('03-unused');

    await setFilter('Използване', 'Използвани');
    await closeFilters();
    check('«Използвани» shows the product photo', (await cards().filter({ hasText: /място|места/ }).count()) > 0 && (await cards().locator('.ml-badge-unused').count()) === 0);

    await resetFilters();
    await setFilter('Състояние', 'Файлът липсва');
    await closeFilters();
    const missing = await cards().count();
    check('«Файлът липсва» shows only cards without an image', missing > 0 && (await cards().locator('.ml-thumb-missing').count()) === missing && (await cards().locator('img').count()) === 0, `cards: ${missing}`);
    await shot('04-missing');
    await resetFilters();
    await closeFilters();

    // ---- the details of the product photo ----------------------------------------------------------------------------------------------------
    await search(ASSET_NAME);
    await cards().first().click();
    await page.waitForSelector('.ml-details', { timeout: 15000 });
    await sleep(500);
    await shot('05-details');
    const details = page.locator('.ml-details');
    check('the details name the file and show the medium preview', (await details.innerText()).includes(ASSET_NAME) && (await details.locator('.ml-preview img').getAttribute('src'))?.includes('-medium.webp') === true);
    check('the details show «Изтриване — скоро» as plain text (nothing to click)', (await details.getByText('Изтриване — скоро').count()) === 1 && (await details.locator('a, button').filter({ hasText: 'Изтриване' }).count()) === 0);
    const link = details.locator('.ml-usage a').first();
    const href = await link.getAttribute('href');
    check('«Къде се ползва» lists the product with a link to its edit page', href !== null && href.includes(`/products/${PRODUCT_ID}/edit`), href ?? 'no link');
    check('the link opens in the same tab (no target)', (await link.getAttribute('target')) === null);

    // ---- the alt text: change, save, open again, restore ---------------------------------------------------------------------------------------
    const altInput = page.locator('.fi-modal-window:visible input[type=text]').last();
    const original = await altInput.inputValue();
    const probe = 'ML-1a проба ' + Date.now();
    await altInput.fill(probe);
    await page.locator('.fi-modal-window:visible').getByRole('button', { name: 'Запази' }).click();
    await settle();
    check('saving shows a notification', (await page.locator('.fi-no-notification').count()) > 0);
    await cards().first().click();
    await page.waitForSelector('.ml-details', { timeout: 15000 });
    await sleep(400);
    check('the saved alt text is there when the details are opened again', (await page.locator('.fi-modal-window:visible input[type=text]').last().inputValue()) === probe);

    // restore the original alt text through the same screen
    await page.locator('.fi-modal-window:visible input[type=text]').last().fill(original);
    await page.locator('.fi-modal-window:visible').getByRole('button', { name: 'Запази' }).click();
    await settle();
    await cards().first().click();
    await page.waitForSelector('.ml-details', { timeout: 15000 });
    await sleep(400);
    check('the original alt text is back', (await page.locator('.fi-modal-window:visible input[type=text]').last().inputValue()) === original);
    await page.keyboard.press('Escape');
    await sleep(300);

    // ---- the link goes to the product ---------------------------------------------------------------------------------------------------------
    await cards().first().click();
    await page.waitForSelector('.ml-details', { timeout: 15000 });
    await page.locator('.ml-details .ml-usage a').first().click();
    await page.waitForURL(new RegExp(`/products/${PRODUCT_ID}/edit`), { timeout: 30000 });
    check('the link opened the product edit page in the same tab', page.url().includes(`/products/${PRODUCT_ID}/edit`), page.url());
    await shot('06-product');
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' '));
    await shot('99-error').catch(() => {});
}

check('no console errors or failed requests', consoleErrors.length === 0, consoleErrors.slice(0, 6).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
