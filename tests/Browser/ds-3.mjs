// A REAL-BROWSER run of DS-3: one place for design. NOT part of `php artisan test`: run by hand against a dev site whose data may be changed. BACK UP the footer / header layout keys and the menus first and
// restore after (the setup creates the throw-away menus «DS3 used menu» (named by the footer DRAFT) and «DS3 free menu»).
//
//   AUDIT_BASE=http://easyco-main.test AUDIT_EMAIL=... AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] USED='DS3 used menu' FREE='DS3 free menu' OUT=<folder> node tests/Browser/ds-3.mjs
//
// The old «Хедър» and «Футър» urls land on the «Дизайн» screen on the right zone; the navigation lists no old entry; a menu the footer uses cannot be deleted (the reason is shown), an unused one can; the
// pages list and the edit form are checked for the footer fields when FOOTER_FIELDS=gone is set (after the columns are dropped). Exit code 1 when a check failed.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const EMAIL = process.env.AUDIT_EMAIL ?? 'ds3-audit@example.test';
const PASSWORD = process.env.AUDIT_PASSWORD;
const USED = process.env.USED ?? 'DS3 used menu';
const FREE = process.env.FREE ?? 'DS3 free menu';
const OUT = process.env.OUT ?? '';

if (! PASSWORD || ! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: AUDIT_PASSWORD=... PLAYWRIGHT_DIR=... node ds-3.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });
const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
const page = await ctx.newPage();

const steps = [];
const consoleErrors = [];
const dialogs = [];
page.on('dialog', async (d) => { dialogs.push(d.message().slice(0, 80)); await d.accept(); });
page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 300)); });
page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.stack || e).replace(/\s+/g, ' ').slice(0, 500)));
page.on('response', (r) => { if (r.status() >= 400 && ! r.url().includes('/storage/') && ! r.url().includes('favicon')) consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 160)); });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const check = (name, ok, detail = '') => { steps.push({ name, ok: !!ok, detail: String(detail).slice(0, 400) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const shots = [];
const shot = async (name) => { if (! OUT) return; const path = join(OUT, name + '.png'); shots.push(path); await page.screenshot({ path, fullPage: false }); };
const settle = async () => { await page.waitForLoadState('networkidle'); await sleep(900); };
const zoneOn = async () => (await page.locator('.ds-zone-on').first().innerText().catch(() => '')).trim();

try {
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    // the navigation: «Дизайн» is there, the old entries are not
    await page.goto(BASE + '/admin/menus', { waitUntil: 'networkidle' });
    const nav = await page.locator('nav.fi-sidebar-nav, .fi-sidebar').first().innerHTML();
    check('the navigation links «Дизайн»', nav.includes('/admin/design'));
    check('the navigation has no link to the old «Хедър» / «Футър» pages', ! nav.includes('/admin/header-settings') && ! nav.includes('/admin/footer'), (nav.match(/href="[^"]*(header-settings|\/footer)[^"]*"/g) ?? []).join(' '));
    await page.locator('.fi-sidebar-group-button').filter({ hasText: 'Дизайн' }).first().click().catch(() => {});
    await sleep(600);
    await shot('01-navigation-1400');

    // the old urls
    await page.goto(BASE + '/admin/footer', { waitUntil: 'networkidle' });
    await page.waitForSelector('.ds-zone-on', { timeout: 30000 });
    check('«Футър» url lands on the Design screen with ?zone=footer', page.url().includes('/admin/design') && page.url().includes('zone=footer'), page.url());
    check('…and the zone «Футър» is the selected one', (await zoneOn()).startsWith('Футър'), await zoneOn());
    await shot('02-design-after-footer-redirect-1400');

    await page.goto(BASE + '/admin/header-settings', { waitUntil: 'networkidle' });
    await page.waitForSelector('.ds-zone-on', { timeout: 30000 });
    check('«Хедър» url lands on the Design screen with ?zone=header', page.url().includes('/admin/design') && page.url().includes('zone=header'), page.url());
    check('…and the zone «Хедър» is the selected one', (await zoneOn()).startsWith('Хедър'), await zoneOn());

    await page.goto(BASE + '/admin/design?zone=nope', { waitUntil: 'networkidle' });
    await page.waitForSelector('.ds-zone-on', { timeout: 30000 });
    check('an unknown ?zone= is ignored (the first zone: Хедър)', (await zoneOn()).startsWith('Хедър'), await zoneOn());

    // a menu the footer (draft) uses cannot be deleted; an unused one can
    await page.goto(BASE + '/admin/menus', { waitUntil: 'networkidle' });
    await page.waitForSelector('#me-select', { timeout: 30000 });
    await page.selectOption('#me-select', { label: USED });
    await settle();
    await page.locator('.me-danger').first().click();
    await sleep(1800);
    const refusal = (await page.locator('.me-notice.me-bad').first().innerText().catch(() => '')).trim();
    check('the menu the footer uses: «Изтрий» is refused with the reason «… се използва в Дизайн — Футър (чернова)»', /се използва в Дизайн — Футър \(чернова\)/.test(refusal), refusal);
    check('…the browser asked first, and the menu is still in the list', dialogs.length >= 1 && (await page.locator('#me-select option', { hasText: USED }).count()) === 1);
    await shot('03-menu-delete-refused-1400');

    const before = await page.locator('#me-select option').count();
    await page.selectOption('#me-select', { label: FREE });
    await settle();
    await page.locator('.me-danger').first().click();
    await sleep(2000);
    check('an unused menu is deleted as before', (await page.locator('#me-select option', { hasText: FREE }).count()) === 0 && (await page.locator('#me-select option').count()) === before - 1, String(await page.locator('#me-select option').count()));

    // the pages list and the edit form
    await page.goto(BASE + '/admin/pages', { waitUntil: 'networkidle' });
    const list = await page.locator('body').innerText();
    if (process.env.FOOTER_FIELDS === 'gone') {
        check('the pages list has no «във футъра» column', ! /във футъра/i.test(list), '');
        await page.locator('table a, .fi-ta-row a').first().click().catch(() => {});
        await settle();
        const form = await page.locator('body').innerText();
        check('the page edit form has no «Покажи във футъра» / «Ред във футъра» field', ! /Покажи във футъра|Ред във футъра/.test(form), '');
        await shot('04-page-edit-form-1400');
    } else {
        await page.locator('table a, .fi-ta-row a').first().click().catch(() => {});
        await settle();
        await shot('04-page-edit-form-1400');
    }
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' '));
    await shot('99-error').catch(() => {});
}

check('no console errors or failed requests', consoleErrors.length === 0, consoleErrors.slice(0, 6).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
