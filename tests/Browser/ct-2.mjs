// A REAL-BROWSER run of CT-2: an attribute can be a shop filter («Може да се филтрира в магазина») and says how its values show in one
// («Как се показват стойностите във филтъра», visible only while the switch is on). NOT part of `php artisan test`: run by hand against the dev site.
// It creates a throw-away attribute (code «ct2-probe») and a throw-away admin; both are removed by the cleanup script afterwards. Nothing else is touched.
//
//   AUDIT_BASE=http://easyco-main.test AUDIT_EMAIL=ct2-audit@example.test AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder> node tests/Browser/ct-2.mjs
// Also checks the storefront home page answers 200. Exit code 1 when a check failed.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const EMAIL = process.env.AUDIT_EMAIL ?? 'ct2-audit@example.test';
const PASSWORD = process.env.AUDIT_PASSWORD;
const OUT = process.env.OUT ?? '';

if (! PASSWORD || ! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: AUDIT_PASSWORD=... PLAYWRIGHT_DIR=... node ct-2.mjs');
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
const shot = async (name, locator = null) => { if (! OUT) return; const path = join(OUT, name + '.png'); shots.push(path); await (locator ?? page).screenshot({ path }); };
const settle = async () => { await page.waitForLoadState('networkidle'); await sleep(700); };

// the switch and the select of the form, found by their labels (the label points at the field's id)
const toggle = () => page.getByLabel('Може да се филтрира в магазина').first();
const displayField = () => page.getByLabel('Как се показват стойностите във филтъра').first();
const save = () => page.getByRole('button', { name: /^(Създай|Запази)$/ }).first(); // the visible form submit: «Създай» on create, «Запази» on edit

// a native <select> or Filament's own dropdown: both are picked by their option's label
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

    // the list has the «Филтър» column
    await page.goto(BASE + '/admin/attribute-definitions', { waitUntil: 'networkidle' });
    check('the list has the «Филтър» column', (await page.locator('th', { hasText: 'Филтър' }).count()) >= 1);
    await shot('01-attribute-list-1400');

    // a throw-away attribute: a select, created with the switch off
    await page.goto(BASE + '/admin/attribute-definitions/create', { waitUntil: 'networkidle' });
    await page.getByLabel('Код').fill('ct2-probe');
    await page.getByLabel('Име').fill('CT2 probe');
    await choose(page.getByLabel('Вид').first(), 'Единичен избор');
    check('the new form has no display select while the switch is off', (await displayField().count()) === 0 || ! (await displayField().isVisible()));
    await save().click();
    await settle();

    // the edit form: switch off, display hidden
    const url = page.url();
    if (! /attribute-definitions\/\d+\/edit/.test(url)) {
        await page.goto(BASE + '/admin/attribute-definitions', { waitUntil: 'networkidle' });
        await page.locator('a', { hasText: 'CT2 probe' }).first().click();
        await settle();
    }
    const editHref = page.url().includes('/edit') ? page.url() : page.url().replace(/\/?$/, '/edit');
    if (! page.url().includes('/edit')) { await page.goto(editHref, { waitUntil: 'networkidle' }); }

    check('the switch is off and the display select is hidden', (await toggle().isChecked().catch(() => false)) === false && ! (await displayField().isVisible().catch(() => false)));
    await shot('02-form-switch-off-1400');

    // switch on: the select appears; buttons; save; reload
    await toggle().click();
    await sleep(600);
    check('switching it on shows the display select', await displayField().isVisible().catch(() => false));
    await shot('03-form-switch-on-1400');

    await choose(displayField(), 'Бутони');
    await save().click();
    await settle();
    await page.reload({ waitUntil: 'networkidle' });
    check('after a save and a reload: the switch is on and «Бутони» is kept', (await toggle().isChecked().catch(() => false)) === true && (await displayField().isVisible().catch(() => false)) && (await displayField().innerText()).includes('Бутони'));

    // switch off: saved; reload: display hidden, switch off
    await toggle().click();
    await sleep(500);
    await save().click();
    await settle();
    await page.reload({ waitUntil: 'networkidle' });
    check('after switching it off, a save and a reload: switch off and the select hidden', (await toggle().isChecked().catch(() => true)) === false && ! (await displayField().isVisible().catch(() => false)));

    // the storefront still answers (a single GET; nothing about the filters is shown to visitors)
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
