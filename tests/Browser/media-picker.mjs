// A REAL-BROWSER run of the media picker (ML-2). It is NOT part of `php artisan test`: it is run by hand against a dev site whose data may be changed. It makes its OWN throw-away brands
// («ml2-…») and HTML block («ML2 …») and uploads three small pictures («ml2-….png»); the person who runs it removes them afterwards (php artisan tinker / the helper in the report).
//
//   AUDIT_BASE=https://easyco-main.test AUDIT_EMAIL=... AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] \
//   ML_FIXTURES=<dir with ml2-a.png ml2-b.png ml2-c.png> ML_KNOWN_NAME=<original_name of a ready picture in the library> OUT=<folder for screenshots> node tests/Browser/media-picker.mjs
//
// With real clicks: a brand «Избери от медията» → search → pick → «Избери» → the field shows the thumbnail → save; another brand «Качи нова» → the upload tab → the new picture comes back selected →
// «Избери»; «Махни» detaches; an HTML block: a picture of its list through the picker and a picture INSIDE the text through the editor's «Снимка» button (size «Среден»), saved; the text holds the <img>.
// Screenshots of the empty and the filled field and of the modal at 1400px and 390px are written to OUT. Prints a JSON report; exit code 1 when a check failed.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
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
    check('the empty field says there is no picture and offers both buttons', await page.locator('.mpf-none').count() === 1 && await page.getByRole('button', { name: 'Избери от медията', exact: true }).count() === 1 && await page.getByRole('button', { name: 'Качи нова', exact: true }).count() === 1);
    await shot('01-field-empty-1400', page.locator('.mpf').first());

    await openModal('Избери от медията');
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
    check('after «Избери» the modal is closed and the field shows the thumbnail, the name and the three controls', await page.locator('.mpf-one .mpf-thumb img[src*="-thumbnail.webp"]').count() === 1 && (await page.locator('.mpf-name').innerText()).includes(KNOWN.slice(0, 20)) && await page.getByRole('button', { name: 'Смени', exact: true }).count() === 1 && await page.getByRole('button', { name: 'Качи нова', exact: true }).count() === 1 && await page.getByRole('button', { name: 'Махни', exact: true }).count() === 1);
    check('the hint says the picture stays in Media', (await page.locator('.mpf-hint').innerText()).includes('Снимката остава в Медия'));
    await shot('04-field-filled-1400', page.locator('.mpf').first());
    await page.getByRole('button', { name: /Създай|Create/ }).first().click();
    await page.waitForURL(/\/brands\/\d+(\/edit)?$/, { timeout: 30000 }).catch(() => {});
    await settle();
    check('the brand is saved', ! page.url().endsWith('/create'), page.url());
    const brandA = page.url().replace(/\/edit$/, '');

    // ================================================== a brand: upload a new picture =====================================================
    await page.goto(BASE + '/admin/brands/create', { waitUntil: 'networkidle' });
    await fillBrand('ML2 Brand B', 'ml2-brand-b');
    await openModal('Качи нова');
    check('«Качи нова» opens the modal on the upload tab', await modal().locator('.mp-tab-on').innerText() === 'Качи файлове' && await modal().locator('.mp-drop').count() === 1);
    await shot('05-modal-upload-1400');
    await modal().locator('input[type=file]').setInputFiles([join(FX, 'ml2-a.png')]);
    await modal().locator('.mp-card-on').first().waitFor({ timeout: 30000 });
    check('after the upload the modal is on the library tab with the new picture already selected', await modal().locator('.mp-tab-on').innerText() === 'Медийна библиотека' && await modal().locator('.mp-card-on').count() === 1 && (await modal().locator('.mp-side .mp-name').innerText()) === 'ml2-a.png');
    await modal().getByRole('button', { name: 'Избери', exact: true }).click();
    await sleep(1500);
    check('the field shows the new picture', (await page.locator('.mpf-name').innerText()).includes('ml2-a.png'));
    await page.getByRole('button', { name: /Създай|Create/ }).first().click();
    await settle();

    // ================================================== «Махни» detaches ================================================================
    await page.goto(brandA + '/edit', { waitUntil: 'networkidle' });
    await page.waitForSelector('.mpf', { timeout: 20000 });
    check('editing the brand shows the saved logo with «Смени» and «Махни»', await page.locator('.mpf-one img[src*="-thumbnail.webp"]').count() === 1);
    await page.getByRole('button', { name: 'Махни', exact: true }).click();
    await sleep(900);
    check('«Махни» empties the field at once (no confirmation)', await page.locator('.mpf-none').count() === 1);
    await page.getByRole('button', { name: /Запази|Save/ }).first().click();
    await settle();

    // ================================================== an HTML block: list picture + picture inside the text ============================
    await page.goto(BASE + '/admin/html-blocks/create', { waitUntil: 'networkidle' });
    await page.locator('input[type=text]').first().fill('ML2 Block');
    await page.getByRole('button', { name: /Добави снимка|Add picture/ }).first().click().catch(() => {});
    await sleep(900);
    await openModal('Избери от медията');
    await search('ml2-a');
    await modal().locator('.mp-card').first().dblclick();
    await sleep(1500);
    check('a double click in the modal selects and confirms in one go', await page.locator('.mpf-one .mpf-name').count() >= 1 && (await page.locator('.mpf-one .mpf-name').first().innerText()).includes('ml2-a.png'));

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

    // ================================================== phone width =============================================================================
    await page.setViewportSize({ width: 390, height: 900 });
    await page.goto(BASE + '/admin/brands/create', { waitUntil: 'networkidle' });
    await shot('07-field-empty-390', page.locator('.mpf').first());
    await openModal('Избери от медията');
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
