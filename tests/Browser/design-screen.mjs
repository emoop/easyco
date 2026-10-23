// A REAL-BROWSER run of the «Дизайн» screen with the Header zone (DS-2a). It is NOT part of `php artisan test`: it is run by hand against a dev site whose data may be changed (back up
// `header.layout.draft`, `header.layout.published`, `header.compiled` and the appearance compiled key first and restore after, like tests/Browser/footer.mjs).
//
//   AUDIT_BASE=https://easyco-main.test AUDIT_EMAIL=... AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] \
//   [OUT=<folder for screenshots>] node tests/Browser/design-screen.mjs
//
// Needs: a throw-away staff account with the Administrator role (created and deleted by the person who runs this). With real clicks:
//   open «Дизайн» (the sidebar starts collapsed) -> select the top row's right cell -> add a «Текст» from the palette -> type its text -> enable the top row, give it a background and a text
//   size -> «Приложи» -> the preview frame shows the text on that background -> switch to «Телефон» (the frame is 390px wide) -> move a node down and up in the phone's main row ->
//   an unsafe link in a button is refused on its own field -> «Върни публикуваното» (after a «Публикувай» of a clean layout) restores the published layout.
// Prints a JSON report; exit code 1 when a check failed.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const EMAIL = process.env.AUDIT_EMAIL ?? 'ux-audit@example.test';
const PASSWORD = process.env.AUDIT_PASSWORD;
const OUT = process.env.OUT ?? '';

if (! PASSWORD || ! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> node design-screen.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });
const ctx = await browser.newContext({ viewport: { width: 1600, height: 1500 }, ignoreHTTPSErrors: true });
const page = await ctx.newPage();

const steps = [];
const consoleErrors = [];
page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 300)); });
page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.stack || e).replace(/\s+/g, ' ').slice(0, 600)));

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const check = (name, ok, detail = '') => { steps.push({ name, ok: !!ok, detail: String(detail).slice(0, 400) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const shot = async (name) => { if (OUT) await page.screenshot({ path: join(OUT, name + '.png'), fullPage: true }); };

async function settle() {
    await page.waitForLoadState('networkidle');
    await sleep(900);
}

async function login() {
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
}

const row = (name) => page.locator('.ds-row').filter({ has: page.locator('.ds-row-title', { hasText: name }) }).first();
const cell = (rowName, cellName) => row(rowName).locator('.ds-cell').filter({ has: page.locator('.ds-cell-title', { hasText: cellName }) }).first();
const frame = () => page.frameLocator('iframe[title="Преглед на хедъра"]');

try {
    await login();
    await page.goto(BASE + '/admin/design', { waitUntil: 'networkidle' });
    await page.waitForSelector('.ds', { timeout: 30000 });
    await sleep(800);

    // ---- the sidebar starts collapsed, the header button opens it -------------------------------------------------------------------------------
    const open = () => page.evaluate(() => window.Alpine.store('sidebar').isOpen);
    check('the admin sidebar starts collapsed', (await open()) === false);
    await page.getByRole('link', { name: 'Към админа' }).or(page.getByRole('button', { name: 'Към админа' })).first().click();
    await sleep(400);
    check('«Към админа» opens the sidebar again', (await open()) === true);
    await page.evaluate(() => window.Alpine.store('sidebar').close());
    await shot('01-start');

    // ---- the zone list: Хедър works, the others are disabled ---------------------------------------------------------------------------------------
    check('the zone list has Хедър selected and the others disabled', (await page.locator('.ds-zone[disabled]').count()) === 5 && (await page.locator('.ds-zone-on').textContent()).includes('Хедър'));
    check('the palette is disabled without a cell', (await page.locator('.ds-chip-add:not([disabled])').count()) === 0);

    // ---- add a text to the top row, right cell ------------------------------------------------------------------------------------------------------
    const topRight = cell('Горен ред', 'Дясно');
    const chipsBefore = await topRight.locator('.ds-chip').count();
    await topRight.locator('.ds-cell-title').click();
    await settle();
    check('the cell is selected (aria-pressed)', (await cell('Горен ред', 'Дясно').locator('.ds-cell-title').getAttribute('aria-pressed')) === 'true');
    await page.locator('.ds-chip-add', { hasText: /^\s*Текст\s*$/ }).click();
    await settle();
    check('a Текст chip is in the cell and selected', (await topRight.locator('.ds-chip.ds-sel').count()) === 1 && (await topRight.locator('.ds-chip').count()) === chipsBefore + 1);

    await page.locator('.ds-props input[type=text]').first().fill('Безплатна доставка над 100 евро');
    await page.locator('.ds-props input[type=text]').first().blur();
    await page.locator('.ds-props input[type=text]').nth(1).fill('/contacts');
    await sleep(400);

    // ---- enable the top row, its background, its text size ---------------------------------------------------------------------------------------------
    await row('Горен ред').locator('.ds-row-title').click();
    await settle();
    const enabled = page.locator('.ds-props').getByRole('switch').first();
    (await enabled.getAttribute('aria-checked')) !== 'true' && await enabled.click();
    await sleep(300);
    const bg = page.locator('.ds-props .fi-fo-field', { has: page.locator('label', { hasText: 'Фон' }) }).locator('input[type=text]').first();
    await bg.fill('#223344');
    await bg.blur();
    await sleep(300);
    await page.locator('.ds-props .fi-fo-field', { has: page.locator('label', { hasText: 'Размер на текста' }) }).locator('button.fi-select-input-btn').first().click();
    await sleep(300);
    await page.locator('.fi-select-input-option:visible', { hasText: 'Голям' }).first().click();
    await sleep(500);
    check('the screen says there are unapplied changes', (await page.locator('.ds-tag-warn').count()) === 1);

    // ---- «Приложи» -> the preview frame shows it -------------------------------------------------------------------------------------------------------
    const before = await page.locator('iframe[title="Преглед на хедъра"]').getAttribute('src');
    await page.locator('.ds-props button[type=submit]').click();
    await settle();
    await sleep(2500);
    const after = await page.locator('iframe[title="Преглед на хедъра"]').getAttribute('src');
    check('«Приложи» gave the frame a fresh signed url', before !== after && /signature=/.test(after), after);
    check('the draft was saved (no unapplied changes any more)', (await page.locator('.ds-tag-warn').count()) === 0);
    const text = frame().locator('.sf-hdr-desktop .sf-hdr-row-top');
    await text.waitFor({ timeout: 20000 });
    check('the frame shows the new text', (await text.textContent()).includes('Безплатна доставка над 100 евро'));
    const colors = await text.evaluate((el) => ({ bg: getComputedStyle(el).backgroundColor, size: getComputedStyle(el).fontSize, cls: el.className }));
    check('the frame row has the chosen background', colors.bg === 'rgb(34, 51, 68)', JSON.stringify(colors));
    check('the frame row has the large text size', colors.size === '17px' && colors.cls.includes('sf-ts-l'), JSON.stringify(colors));
    await shot('02-desktop-applied');

    // ---- the public page does not show a draft ---------------------------------------------------------------------------------------------------------
    const pub = await ctx.newPage();
    await pub.goto(BASE + '/', { waitUntil: 'networkidle' });
    check('the public page does not show the draft', ! (await pub.content()).includes('Безплатна доставка над 100 евро'));
    await pub.close();

    // ---- Телефон ---------------------------------------------------------------------------------------------------------------------------------------------
    await page.getByRole('button', { name: 'Телефон', exact: true }).click();
    await settle();
    const width = await page.locator('.ds-frame-mobile iframe').evaluate((el) => el.getBoundingClientRect().width);
    check('the phone frame is narrow (about 390px)', width > 300 && width <= 390, width);
    check('the phone layout lists the drawer box and its note', (await page.locator('.ds-drawer').count()) === 1 && (await page.locator('.ds-drawer .ds-note').textContent()).includes('твърд ред'));
    await sleep(1500);
    await shot('03-phone');

    const typesOf = async () => (await cell('Основен ред', 'Дясно').locator('.ds-chip-main').allTextContents()).map((t) => t.replace(/\s+/g, ' ').trim());
    const before2 = await typesOf();
    await cell('Основен ред', 'Дясно').locator('.ds-chip-main').first().click();
    await settle();
    await page.getByRole('button', { name: 'Премести надолу' }).click();
    await settle();
    const moved = await typesOf();
    check('a node moved down in the phone main row', before2.length >= 2 && moved[1] === before2[0] && moved[0] === before2[1], JSON.stringify([before2, moved]));
    await page.getByRole('button', { name: 'Премести нагоре' }).click();
    await settle();
    check('and up again', JSON.stringify(await typesOf()) === JSON.stringify(before2));
    check('the arrow that cannot act is disabled', await page.getByRole('button', { name: 'Премести надясно' }).isDisabled());

    // ---- a refused setting stays on its own field ---------------------------------------------------------------------------------------------------------
    await page.getByRole('button', { name: 'Десктоп', exact: true }).click();
    await settle();
    const leftBefore = await cell('Горен ред', 'Ляво').locator('.ds-chip').count();
    await cell('Горен ред', 'Ляво').locator('.ds-cell-title').click();
    await settle();
    await page.locator('.ds-chip-add', { hasText: /^\s*Бутон\s*$/ }).click();
    await settle();
    const fields = page.locator('.ds-props input[type=text]');
    await fields.nth(0).fill('Купи');
    await fields.nth(1).fill('javascript:alert(1)');
    await fields.nth(1).blur();
    await sleep(300);
    await page.locator('.ds-props button[type=submit]').click();
    await settle();
    const err = await page.locator('.ds-props .fi-fo-field-wrp-error-message, .ds-props [data-validation-error], .ds-props .fi-fo-field-error').allTextContents();
    check('an unsafe link is refused with a message under its field', err.join(' ').trim().length > 0 && (await page.locator('.ds-chip.ds-bad').count()) === 1, err.join('|'));
    await shot('04-error');
    await page.locator('.ds-chip.ds-bad').getByRole('button', { name: 'Премахни' }).click();
    await settle();
    check('the refused button was removed again', (await cell('Горен ред', 'Ляво').locator('.ds-chip').count()) === leftBefore);

    // ---- publish a clean layout, then change the draft, then «Върни публикуваното» ------------------------------------------------------------------
    await page.getByRole('button', { name: 'Публикувай' }).first().click();
    await sleep(500);
    await page.locator('.fi-modal-window button:visible', { hasText: /Потвърди|Публикувай|Confirm/ }).last().click();
    await settle();
    check('published (the public page shows the text)', await (async () => { const p = await ctx.newPage(); await p.goto(BASE + '/', { waitUntil: 'networkidle' }); const ok = (await p.content()).includes('Безплатна доставка над 100 евро'); await p.close(); return ok; })());

    const centerBefore = await cell('Горен ред', 'Център').locator('.ds-chip').count();
    await cell('Горен ред', 'Център').locator('.ds-cell-title').click();
    await settle();
    await page.locator('.ds-chip-add', { hasText: /^\s*Разстояние\s*$/ }).click();
    await settle();
    await page.locator('.ds-props button[type=submit]').click();
    await settle();
    check('a draft with one more component is saved', (await cell('Горен ред', 'Център').locator('.ds-chip').count()) === centerBefore + 1);
    await page.getByRole('button', { name: 'Върни публикуваното' }).first().click();
    await sleep(500);
    await page.locator('.fi-modal-window button:visible', { hasText: /Потвърди|Върни публикуваното|Confirm/ }).last().click();
    await settle();
    check('«Върни публикуваното» restores the published layout', (await cell('Горен ред', 'Център').locator('.ds-chip').count()) === centerBefore && (await cell('Горен ред', 'Дясно').locator('.ds-chip').count()) === chipsBefore + 1);
    await shot('05-discarded');

    // ---- the old Header page shows the same ----------------------------------------------------------------------------------------------------------------
    await page.goto(BASE + '/admin/header-settings', { waitUntil: 'networkidle' });
    await page.waitForSelector('form#form', { timeout: 20000 });
    check('the old Header page still opens', (await page.locator('form#form').count()) === 1);
} catch (e) {
    check('the script ran to its end', false, String(e.stack || e));
    await shot('99-failure');
}

await browser.close();
const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ steps, failed: failed.length, consoleErrors }, null, 2));
process.exit(failed.length ? 1 : 0);
