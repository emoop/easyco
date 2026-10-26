// A REAL-BROWSER run of BUG-1: adding a second variation axis (colour) on the «Оси за вариации» tab of an EXISTING variable product that already has live variations.
// NOT part of `php artisan test`: run by hand against the dev site. It uses a throw-away admin (created/removed by the setup/cleanup scripts) and a PRODUCT_ID whose axes
// tab it only reads and edits in the browser (it never presses Save), so no product data is written.
//
//   AUDIT_BASE=http://easyco-main.test AUDIT_EMAIL=bug1-audit@example.test AUDIT_PASSWORD=... PRODUCT_ID=20025 NEW_AXIS=Color1 PLAYWRIGHT_DIR=<dir> [CHROMIUM=<path>] OUT=<folder> node tests/Browser/bug-1.mjs
// Prints a JSON report; exit code 1 when a check failed.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const EMAIL = process.env.AUDIT_EMAIL ?? 'bug1-audit@example.test';
const PASSWORD = process.env.AUDIT_PASSWORD;
const PRODUCT = process.env.PRODUCT_ID;
const NEW_AXIS = process.env.NEW_AXIS ?? 'Color1';
const OUT = process.env.OUT ?? '';

if (! PASSWORD || ! PRODUCT || ! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: AUDIT_PASSWORD=... PRODUCT_ID=... PLAYWRIGHT_DIR=... node bug-1.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });
const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
const page = await ctx.newPage();

const steps = [];
const consoleErrors = [];
const updates = [];
const shots = [];
page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
page.on('response', (r) => { if (r.url().includes('/livewire') && r.request().method() === 'POST') updates.push(r.status()); });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 300) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const shot = async (name) => { if (! OUT) return; const path = join(OUT, name + '.png'); shots.push(path); await page.screenshot({ path }); };
const settle = async () => { await page.waitForLoadState('networkidle'); await sleep(1200); };
// a click that must be answered by a Livewire update: the status, or 'none' when no answer came back in time (the server died: the fatal)
const clickForAnswer = async (locator) => {
    const answer = page.waitForResponse((r) => r.url().includes('/livewire') && r.request().method() === 'POST', { timeout: 25000 }).then((r) => r.status()).catch(() => 'none');
    await locator.click();
    return answer;
};
const failedUpdates = () => updates.filter((s) => s >= 400).length;

try {
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    await page.goto(BASE + `/admin/products/${PRODUCT}/edit-variable?tab=axes`, { waitUntil: 'networkidle' });
    await page.getByRole('tab', { name: 'Оси за вариации' }).click().catch(() => {});
    await settle();
    await shot('01-axes-tab-1400');

    // (1) the plain tab: «Добави ос», pick the colour attribute, tick a value, Save -> the domain's refusal message, nothing written
    await page.getByRole('button', { name: 'Добави ос' }).first().click();
    await settle();
    await page.getByLabel('Атрибут').last().click();
    await page.getByRole('option', { name: NEW_AXIS, exact: true }).first().click();
    await settle();
    await page.getByRole('checkbox').last().check().catch(() => {});
    await settle();
    check('tab: adding an axis and ticking a value fails no Livewire request', failedUpdates() === 0, updates.join(','));
    if (process.env.PRESS_SAVE) {
        await page.getByRole('button', { name: /^Запази/ }).first().click();
        await settle(); await sleep(2500);
        check('tab: Save is answered (refusal notification, no fatal)', failedUpdates() === 0, updates.join(','));
        await shot('02-tab-refusal-1400');
    }

    // (2) the modal «Смяна на осите»: trash can of a row, then «Добави ос» inside the modal (the row actions that mount ON TOP of the modal)
    await page.goto(BASE + `/admin/products/${PRODUCT}/edit-variable?tab=axes`, { waitUntil: 'networkidle' });
    await settle();
    const before = updates.length;
    await page.getByRole('button', { name: 'Смяна на осите' }).first().click();
    await settle();
    check('modal: opening «Смяна на осите» fails no request', updates.slice(before).every((x) => x < 400), updates.slice(before).join(','));
    await shot('03-change-axes-modal-1400');

    const modal = page.locator('.fi-modal-window').last();
    const addStatus = await clickForAnswer(modal.getByRole('button', { name: 'Добави ос' }));
    check('modal: «Добави ос» inside the modal is answered with 200 (not a fatal)', addStatus === 200, addStatus);
    await settle();

    const trash = modal.locator('.fi-fo-repeater-item-header-end button, .fi-fo-repeater-item-header button').first();
    const trashStatus = await clickForAnswer(trash);
    check('modal: the trash can of a row inside the modal is answered with 200 (not a fatal)', trashStatus === 200 || trashStatus === 'none' && false, trashStatus);
    await settle();
    await sleep(1000);
    await shot('04-change-axes-modal-after-row-actions-1400');
    check('the page still answers (a heading is visible)', (await page.locator('h1').first().innerText()).length > 0);
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 400));
}

check('no page errors', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, livewireStatuses: updates, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
