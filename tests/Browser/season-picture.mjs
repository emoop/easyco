// A REAL-BROWSER run of CT-1.1, a picture for a season (`catalog_seasons`), chosen with the media picker. NOT part of `php artisan test`: run by hand against a dev site whose data may be changed. BACK UP the
// picture column of the season it touches first and restore it after.
//
//   AUDIT_BASE=http://easyco-main.test AUDIT_EMAIL=... AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] SEASON=<id of a season> OUT=<folder for screenshots> node tests/Browser/season-picture.mjs
//
// With real clicks: a season → the empty field → «Добави изображение» → a picture from «Медия» → save → reload, it is there → the list shows a small thumbnail → «Медия» → the picture → «Къде се ползва»
// lists the season, «Изтрий» is refused with the reason → «Премахни изображението» → save → reload, the field is empty and the picture is still in «Медия». Exit code 1 when a check failed.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const EMAIL = process.env.AUDIT_EMAIL ?? 'ct11-audit@example.test';
const PASSWORD = process.env.AUDIT_PASSWORD;
const SEASON = process.env.SEASON;
const OUT = process.env.OUT ?? '';

if (! PASSWORD || ! process.env.PLAYWRIGHT_DIR || ! SEASON) {
    console.error('usage: AUDIT_PASSWORD=... PLAYWRIGHT_DIR=... SEASON=5 node season-picture.mjs');
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
const fieldBox = () => field().locator('xpath=ancestor::div[contains(@class,"fi-fo-field")][1]');
const closed = async () => { await page.waitForSelector('.fi-modal-window:visible', { state: 'detached', timeout: 15000 }).catch(() => {}); await sleep(1200); };
const srcOf = async () => await field().locator('.mpf-big img').first().getAttribute('src').catch(() => null);

async function open(path) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    await page.waitForSelector('.mpf', { timeout: 30000 });
}

try {
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    await open(`/admin/seasons/${SEASON}/edit`);
    const text = await page.locator('body').innerText();
    check('the field «Снимка» sits after the slug', text.indexOf('Снимка') > text.indexOf('Слъг'));
    check('empty: «Няма избрана снимка» and ONE button «Добави изображение»', (await field().innerText()).includes('Няма избрана снимка') && await field().getByRole('button', { name: 'Добави изображение', exact: true }).count() === 1);
    check('under the field the help line', text.includes('Показва се в страницата на сезона. Снимката остава в Медия, ако я махнете оттук.'));
    await shot('01-season-empty-1400', fieldBox());

    await field().getByRole('button', { name: 'Добави изображение', exact: true }).click();
    await page.waitForSelector('.fi-modal-window:visible .mp', { timeout: 20000 });
    await sleep(700);
    await modal().locator('.mp-card').first().click();
    await sleep(700);
    await modal().getByRole('button', { name: 'Избери', exact: true }).click();
    await closed();
    const picked = await srcOf();
    check('after «Избери» the picture is shown whole with «Смени» and «Премахни изображението»', picked !== null && await field().getByRole('button', { name: 'Смени', exact: true }).count() === 1 && await field().getByRole('button', { name: 'Премахни изображението', exact: true }).count() === 1);
    await shot('02-season-filled-1400', fieldBox());
    await page.getByRole('button', { name: /Запази|Save/ }).first().click();
    await settle();
    await open(`/admin/seasons/${SEASON}/edit`);
    check('after a save and a reload the picture is there', (await srcOf()) === picked);

    await page.goto(BASE + '/admin/seasons', { waitUntil: 'networkidle' });
    check('the list shows a small thumbnail', await page.locator('img[src*="-thumbnail.webp"]').count() >= 1);
    await shot('03-season-list-1400');

    // «Медия»: where it is used, and the refused delete
    await page.goto(BASE + '/admin/media', { waitUntil: 'networkidle' });
    await sleep(1500);
    const used = page.locator('.fi-ta-record, .fi-ta-content-grid > *').filter({ hasText: /места|място/ }).first();
    await used.click();
    await sleep(1800);
    const details = page.locator('.fi-modal-window:visible, .fi-slide-over-window:visible').last();
    const uses = await details.innerText().catch(() => '');
    check('«Къде се ползва» lists the season', /Сезон „[^“]+“/.test(uses), uses.slice(0, 300));
    await shot('04-media-where-used-1400');
    check('«Изтрий» is refused with the reason', /Използва се на \d+ места?/.test(uses), uses.slice(0, 300));
    await shot('05-media-delete-refused-1400');
    await page.keyboard.press('Escape');

    // remove: only detached
    await open(`/admin/seasons/${SEASON}/edit`);
    await field().getByRole('button', { name: 'Премахни изображението', exact: true }).click();
    await sleep(700);
    await page.getByRole('button', { name: /Запази|Save/ }).first().click();
    await settle();
    await open(`/admin/seasons/${SEASON}/edit`);
    check('after «Премахни изображението», a save and a reload the field is empty', (await field().innerText()).includes('Няма избрана снимка'));
    await page.goto(BASE + '/admin/media', { waitUntil: 'networkidle' });
    await sleep(1500);
    check('the picture is still in «Медия»', await page.locator('.fi-ta-record, .fi-ta-content-grid > *').count() >= 1);

    // phone width
    await page.setViewportSize({ width: 390, height: 900 });
    await open(`/admin/seasons/${SEASON}/edit`);
    await shot('06-season-empty-390', fieldBox());
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
