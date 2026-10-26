// A REAL-BROWSER run of CT-3: an attribute says how its values show on the product page and in the card («Как се показва на страницата на продукта»: Цветни кръгчета,
// Бутони, Падащо меню, Само в спецификации). NOT part of `php artisan test`: run by hand against the dev site. It creates a throw-away attribute (code «ct3-probe») and a
// throw-away admin; the cleanup script removes both afterwards. Nothing else is touched.
//
//   AUDIT_BASE=http://easyco-main.test AUDIT_EMAIL=ct3-audit@example.test AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder> node tests/Browser/ct-3.mjs
// Also checks the storefront home page answers 200. Exit code 1 when a check failed.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const EMAIL = process.env.AUDIT_EMAIL ?? 'ct3-audit@example.test';
const PASSWORD = process.env.AUDIT_PASSWORD;
const OUT = process.env.OUT ?? '';

if (! PASSWORD || ! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: AUDIT_PASSWORD=... PLAYWRIGHT_DIR=... node ct-3.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });
const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
const page = await ctx.newPage();

const steps = [];
const consoleErrors = [];
const shots = [];
page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 200)); });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 300) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const shot = async (name) => { if (! OUT) return; const path = join(OUT, name + '.png'); shots.push(path); await page.screenshot({ path }); };
const settle = async () => { await page.waitForLoadState('networkidle'); await sleep(700); };

// the display select, found by its label (the label points at the field's id); a native <select> or Filament's dropdown
const display = () => page.getByLabel('Как се показва на страницата на продукта').first();
const save = () => page.getByRole('button', { name: /^(Създай|Запази)$/ }).first();

async function choose(field, option) {
    const tag = await field.evaluate((el) => el.tagName);

    if (tag === 'SELECT') {
        await field.selectOption({ label: option });
    } else {
        await field.click();
        await page.getByRole('option', { name: option, exact: true }).first().click();
    }

    await sleep(400);
}

try {
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    // a throw-away attribute, created without touching the display: it must start as «Падащо меню»
    await page.goto(BASE + '/admin/attribute-definitions/create', { waitUntil: 'networkidle' });
    await page.getByLabel('Код').fill('ct3-probe');
    await page.getByLabel('Име').fill('CT3 probe');
    await choose(page.getByLabel('Вид').first(), 'Единичен избор');
    const shown = () => display().evaluate((el) => (el.tagName === 'SELECT' ? el.options[el.selectedIndex].text : el.innerText));
    check('the new form shows the display select with «Падащо меню» by default', (await shown()).includes('Падащо меню'), await shown());
    await save().click();
    await settle();

    // the edit form: «Падащо меню», then «Бутони», saved and reloaded
    if (! page.url().includes('/edit')) {
        await page.goto(BASE + '/admin/attribute-definitions', { waitUntil: 'networkidle' });
        await page.locator('a', { hasText: 'CT3 probe' }).first().click();
        await settle();
        if (! page.url().includes('/edit')) { await page.goto(page.url().replace(/\/?$/, '/edit'), { waitUntil: 'networkidle' }); }
    }

    const current = async () => display().evaluate((el) => (el.tagName === 'SELECT' ? el.options[el.selectedIndex].text : el.innerText));
    check('the stored display is «Падащо меню» on the edit form', (await current()).includes('Падащо меню'), await current());

    await choose(display(), 'Бутони');
    await save().click();
    await settle();
    await page.reload({ waitUntil: 'networkidle' });
    check('after a save and a reload the display is still «Бутони»', (await current()).includes('Бутони'), await current());
    await shot('01-attribute-form-display-1400');

    // the storefront still answers; nothing about the display is shown to visitors yet
    const home = await page.request.get(BASE + '/');
    check('the storefront home page answers 200', home.status() === 200, home.status());
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 400));
    await shot('99-error').catch(() => {});
}

check('no page errors', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
