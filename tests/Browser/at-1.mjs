// A REAL-BROWSER run of AT-1: an attribute is shown as «Цвят (кръгчета)», every value of it gets a colour (a picker, «Без цвят», a dot in the list, the muted «няма цвят» hint), and the colours
// stay while the type is changed to «Бутони» and back. NOT part of `php artisan test`: run by hand against the dev site. It creates a throw-away attribute (code «at1-probe», name «AT1 Цвят») with
// three values and a throw-away admin; the cleanup script removes them afterwards. Nothing else is touched.
//
//   AUDIT_BASE=https://easyco-main.test AUDIT_EMAIL=at1-audit@example.test AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder> node tests/Browser/at-1.mjs
// Exit code 1 when a check failed.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const EMAIL = process.env.AUDIT_EMAIL ?? 'at1-audit@example.test';
const PASSWORD = process.env.AUDIT_PASSWORD;
const OUT = process.env.OUT ?? '';

if (! PASSWORD || ! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: AUDIT_PASSWORD=... PLAYWRIGHT_DIR=... node at-1.mjs');
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
const shot = async (name) => { if (! OUT) return; const path = join(OUT, name + '.png'); shots.push(path); await page.screenshot({ path, fullPage: true }); };
const settle = async () => { await page.waitForLoadState('networkidle'); await sleep(700); };
const save = () => page.getByRole('button', { name: /^(Създай|Запази)$/ }).first();

async function choose(field, option) {
    const tag = await field.evaluate((el) => el.tagName);

    if (tag === 'SELECT') {
        await field.selectOption({ label: option });
    } else {
        await field.click();
        await page.getByRole('option', { name: option, exact: true }).first().click();
    }

    await sleep(500);
}

const colourInput = () => page.getByLabel('Цвят', { exact: true });
const setColour = async (hex) => { await colourInput().fill(hex); await colourInput().blur(); await page.locator('h1').first().click(); await sleep(500); };
const display = () => page.getByLabel('Как се показва', { exact: true }).first();

async function addValue(name, hex) {
    await page.goto(BASE + '/admin/attribute-values/create', { waitUntil: 'networkidle' });
    await choose(page.getByLabel('Атрибут').first(), 'AT1 Цвят');
    await page.locator('input[id="form.value"]').fill(name);
    hex && await setColour(hex);
    await save().click();
    await settle();
}

async function openValue(name) {
    await page.goto(BASE + '/admin/attribute-values', { waitUntil: 'networkidle' });
    await page.locator('input[type=search]').first().fill(name);
    await sleep(1200);
    const edit = page.locator('tr', { hasText: name }).first().locator('a[href$="/edit"]').first();
    await page.goto(await edit.getAttribute('href'), { waitUntil: 'networkidle' });
}

try {
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    // the attribute, shown as «Цвят (кръгчета)»
    await page.goto(BASE + '/admin/attribute-definitions/create', { waitUntil: 'networkidle' });
    await page.getByLabel('Код').fill('at1-probe');
    await page.getByLabel('Име').fill('AT1 Цвят');
    await choose(page.getByLabel('Вид').first(), 'Единичен избор');
    await display().click();
    const listed = (await page.locator('[role=option], option').allInnerTexts()).join('|');
    await page.keyboard.press('Escape');
    check('the select offers «Падащ списък», «Бутони» and «Цвят (кръгчета)»', ['Падащ списък', 'Бутони', 'Цвят (кръгчета)'].every((t) => listed.includes(t)), listed);
    check('the hint «подходящо за размери» is on the page', (await page.content()).includes('подходящо за размери'));
    await choose(display(), 'Цвят (кръгчета)');
    await save().click();
    await settle();

    // the values: two coloured, one without
    await addValue('AT1 Червен', '#c62828');
    await addValue('AT1 Син', '#1565c0');
    await addValue('AT1 Без', null);

    // the picker, the dot, the hint
    await page.goto(BASE + '/admin/attribute-values/create', { waitUntil: 'networkidle' });
    check('on a new value the picker is hidden until a colour attribute is chosen', await colourInput().count() === 0);
    await choose(page.getByLabel('Атрибут').first(), 'AT1 Цвят');
    check('the picker appears for the colour attribute', await colourInput().count() > 0);
    check('the muted hint «няма цвят — ще се покаже като текст» shows while there is no colour', (await page.content()).includes('няма цвят — ще се покаже като текст'));
    await shot('01-value-form-colour-1400');

    await page.goto(BASE + '/admin/attribute-values', { waitUntil: 'networkidle' });
    await page.locator('input[type=search]').first().fill('AT1');
    await sleep(1200);
    check('the list shows the two colour dots', (await page.content()).includes('#c62828') && (await page.content()).includes('#1565c0'));
    await shot('02-value-list-dots-1400');

    // a stored colour is on the edit form
    await openValue('AT1 Червен');
    check('the edit form holds the stored colour #c62828', (await colourInput().inputValue()).toLowerCase() === '#c62828', await colourInput().inputValue());

    // «Без цвят» clears the picker (not saved); put it back
    await page.getByRole('button', { name: 'Без цвят' }).first().click();
    await sleep(500);
    check('«Без цвят» empties the picker', (await colourInput().inputValue()) === '', await colourInput().inputValue());

    // the type to «Бутони» and back: the colours stay
    await page.goto(BASE + '/admin/attribute-definitions', { waitUntil: 'networkidle' });
    await page.locator('a', { hasText: 'AT1 Цвят' }).first().click();
    await settle();
    if (! page.url().includes('/edit')) { await page.goto(page.url().replace(/\/?$/, '/edit'), { waitUntil: 'networkidle' }); }
    const attributeEdit = page.url();
    await choose(display(), 'Бутони');
    await save().click();
    await settle();

    await openValue('AT1 Червен');
    check('with the type «Бутони» the picker is hidden', await colourInput().count() === 0);
    await page.goto(BASE + '/admin/attribute-values', { waitUntil: 'networkidle' });
    await page.locator('input[type=search]').first().fill('AT1');
    await sleep(1200);
    check('and the list shows no dots', ! (await page.content()).includes('#c62828'));

    await page.goto(attributeEdit, { waitUntil: 'networkidle' });
    await choose(display(), 'Цвят (кръгчета)');
    await save().click();
    await settle();
    await openValue('AT1 Червен');
    check('back to «Цвят (кръгчета)» the colour #c62828 is still there', (await colourInput().inputValue()).toLowerCase() === '#c62828', await colourInput().inputValue());
    await shot('03-value-form-colour-back-1400');

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
