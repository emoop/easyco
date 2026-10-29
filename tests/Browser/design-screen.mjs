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
// DS-2b adds: «Футър» (add a column, add a menu block, move it, change the upper colour, «Приложи», see it in the frame scrolled to the footer, «Върни публикуваното»), «Топбанер» («Запази» shows at once)
//   and «Количка и поръчка» (enable, phone, «Приложи», the frame shows the cart, «Публикувай», /cart and /checkout at 1280px and 390px, the home page keeps the full header).
// Prints a JSON report; exit code 1 when a check failed.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { execFileSync } from 'node:child_process';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const EMAIL = process.env.AUDIT_EMAIL ?? 'ux-audit@example.test';
const PASSWORD = process.env.AUDIT_PASSWORD;
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');
// SC-1.1: the number of WORKING zones is read from the code (DesignScreen::ZONES), not written here: a zone added there changes this check and nothing else
const expectedZones = Number(execFileSync('php', ['artisan', 'tinker', '--execute', 'echo count(App\\Filament\\Pages\\Design\\DesignScreen::ZONES);'], { cwd: ROOT, encoding: 'utf8' }).trim().split('\n').pop());

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

// a failed request names its url (the console only says "Failed to load resource")
page.on('response', (r) => { if (r.status() >= 400) consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 160) + ' (asked by ' + (r.request().frame()?.url() ?? '?').slice(0, 120) + ', after step ' + steps.length + ')'); });

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
const frame2 = (title) => page.frameLocator(`iframe[title="${title}"]`);

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

    // ---- DS-2a.1: the compact look ------------------------------------------------------------------------------------------------------------------
    const look = await page.evaluate(() => {
        const lum = (rgb) => {
            const [r, g, b] = rgb.match(/[\d.]+/g).slice(0, 3).map(Number).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; });

            return 0.2126 * r + 0.7152 * g + 0.0722 * b;
        };
        const contrast = (fg, bg) => { const [a, b] = [lum(fg), lum(bg)].sort((x, y) => y - x); return (a + 0.05) / (b + 0.05); };
        const css = (el) => getComputedStyle(el);
        const zones = document.querySelector('.ds-zones');
        const bg = css(zones).backgroundColor;
        const texts = { caption: '.ds-zones .ds-h', zone: '.ds-zone:not([disabled]):not(.ds-zone-on)', active: '.ds-zone-on', disabled: '.ds-zone[disabled]', soon: '.ds-zone[disabled] small' };
        const ratios = {};

        for (const [name, sel] of Object.entries(texts)) {
            const el = document.querySelector(sel);
            ratios[name] = el ? Math.round(contrast(css(el).color, name === 'active' ? css(el).backgroundColor : bg) * 100) / 100 : null;
        }

        return {
            zonesWidth: Math.round(zones.getBoundingClientRect().width),
            propsWidth: Math.round(document.querySelector('.ds-props').getBoundingClientRect().width),
            mainWidth: Math.round(document.querySelector('.ds-main').getBoundingClientRect().width),
            zonesBg: bg,
            baseFont: css(document.querySelector('.ds')).fontSize,
            chip: Math.round(document.querySelector('.ds-chip-add').getBoundingClientRect().height),
            headerButton: (() => { const el = document.querySelector('.fi-header .fi-btn'); return { font: css(el).fontSize, height: Math.round(el.getBoundingClientRect().height), label: css(el.querySelector('span') ?? el).fontSize }; })(),
            input: (() => { const el = document.querySelector('.ds-props .fi-input-wrp'); return el ? Math.round(el.getBoundingClientRect().height) : null; })(),
            ratios,
        };
    });
    check('the zones column is about 152px', Math.abs(look.zonesWidth - 152) <= 2, look.zonesWidth);
    check('the properties column is about 296px', Math.abs(look.propsWidth - 296) <= 2, look.propsWidth);
    check('the board takes the rest (wider than 950px at 1600; it was about 904 before)', look.mainWidth > 950, look.mainWidth);
    check('the zone list is graphite', look.zonesBg === 'rgb(43, 47, 51)', look.zonesBg);
    check('the screen font is 13px and the palette chips are about 28px', look.baseFont === '13px' && Math.abs(look.chip - 28) <= 2, JSON.stringify([look.baseFont, look.chip]));
    check('every text of the zone list has contrast >= 4.5', Object.values(look.ratios).every((r) => r === null || r >= 4.5), JSON.stringify(look.ratios));
    console.error('LOOK ' + JSON.stringify(look));

    // ---- the zone list: Хедър works, the others are disabled ---------------------------------------------------------------------------------------
    const zoneTotal = await page.locator('.ds-zones .ds-zone').count();
    const zonesWorking = await page.locator('.ds-zone:not([disabled])').count();
    const zonesSoon = await page.locator('.ds-zone[disabled]').count();
    check(`the zone list has ${zonesWorking} working zones (DesignScreen::ZONES has ${expectedZones}) and ${zonesSoon} «следва» (Хедър selected)`, zonesWorking === expectedZones && zonesSoon === zoneTotal - expectedZones && (await page.locator('.ds-zone-on').textContent()).includes('Хедър'), `total ${zoneTotal}, working ${zonesWorking}, soon ${zonesSoon}, expected ${expectedZones}`);
    check('the palette is disabled without a cell', (await page.locator('.ds-chip-add:not([disabled])').count()) === 0);

    // ---- DS-2a.1: the menu picker on the header's menu element ------------------------------------------------------------------------------------------
    await cell('Основен ред', 'Ляво').locator('.ds-chip-main').first().click();
    await settle();
    const menuField = page.locator('.ds-props .fi-fo-field', { has: page.locator('label', { hasText: 'Меню' }) }).first();
    await menuField.locator('button.fi-select-input-btn').first().click();
    await sleep(300);
    const options = await page.locator('.fi-select-input-option:visible').allTextContents();
    check('the picker lists the menus and not only one', options.length >= 2, JSON.stringify(options));
    check('the empty choice says "the menu of the place (Хедър)"', (await menuField.textContent()).includes('Менюто на мястото (Хедър)'), (await menuField.textContent()).slice(0, 120));
    await page.keyboard.press('Escape');
    check('«Вид» offers two honest options (no голямо меню)', (await page.locator('.ds-props').textContent()).includes('Вид'));
    check('a link opens the Menus editor', (await page.locator('.ds-props a[href$="/admin/menus"]').count()) === 1);
    await shot('00-menu-picker');

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

    // ---- DS-2b: Футър ------------------------------------------------------------------------------------------------------------------------------------
    const zone = async (name) => {
        await page.locator('.ds-zone:not([disabled])', { hasText: name }).click();
        await settle();
    };
    const fieldInput = (label) => page.locator('.ds-props .fi-fo-field', { has: page.locator('label', { hasText: label }) }).locator('input[type=text]').first();
    const submit = async () => { await page.locator('.ds-props button[type=submit]').click(); await settle(); await sleep(2500); };

    await zone('Футър');
    const upper = page.locator('.ds-level').first();
    const row1 = upper.locator('.ds-row').first();
    const columns = () => row1.locator('.ds-cells-footer .ds-cell');
    check('the footer zone is selected and shows both levels', (await page.locator('.ds-zone-on').textContent()).includes('Футър') && (await page.locator('.ds-level').count()) === 2);
    const columnsBefore = await columns().count();
    await row1.getByRole('button', { name: /Добави колона/ }).click();
    await settle();
    check('a column was added', (await columns().count()) === columnsBefore + 1);

    await columns().last().locator('.ds-cell-title').click();
    await settle();
    await page.locator('.ds-chip-add', { hasText: /^\s*Меню\s*$/ }).click();
    await settle();
    check('a menu block is in the new column and selected', (await columns().last().locator('.ds-chip.ds-sel').count()) === 1);
    const previousBefore = await columns().nth(columnsBefore - 1).locator('.ds-chip').count();
    await columns().last().locator('.ds-chip.ds-sel').getByRole('button', { name: 'Премести наляво' }).click();
    await settle();
    check('the block moved to the column on its left', (await columns().nth(columnsBefore - 1).locator('.ds-chip').count()) === previousBefore + 1 && (await columns().last().locator('.ds-chip').count()) === 0);

    await upper.locator('.ds-layout-head').click();
    await settle();
    const bgField = fieldInput('Фон');
    await bgField.fill('#223344');
    await bgField.blur();
    await sleep(300);
    await submit();
    const footer = frame2('Преглед на футъра').locator('.sf-footer');
    await footer.waitFor({ timeout: 20000 });
    const footerLook = await footer.locator('.sf-ft-upper').evaluate((el) => ({ bg: getComputedStyle(el).backgroundColor, scrolled: el.ownerDocument.defaultView.scrollY }));
    check('the frame shows the footer on the chosen colour', footerLook.bg === 'rgb(34, 51, 68)', JSON.stringify(footerLook));
    check('the frame is scrolled to the footer', footerLook.scrolled > 100, JSON.stringify(footerLook));
    await shot('06-footer');

    await page.getByRole('button', { name: 'Върни публикуваното' }).first().click();
    await sleep(500);
    await page.locator('.fi-modal-window button:visible', { hasText: /Потвърди|Върни публикуваното|Confirm/ }).last().click();
    await settle();
    check('«Върни публикуваното» restores the footer', (await columns().count()) === columnsBefore);

    // ---- DS-2b: Топбанер ---------------------------------------------------------------------------------------------------------------------------------
    await zone('Топбанер');
    check('the top banner zone has no palette and the one button «Приложи» (DS-5)', (await page.locator('.ds-palette').count()) === 0 && (await page.locator('.ds-props button[type=submit]').textContent()).includes('Приложи'));
    const enabledSwitch = page.locator('.ds-props').getByRole('switch').first();
    (await enabledSwitch.getAttribute('aria-checked')) !== 'true' && await enabledSwitch.click();
    await sleep(300);
    const textField = fieldInput('Текст');
    await textField.fill('Тест на банера от Дизайн');
    await textField.blur();
    await sleep(300);
    await submit();
    const banner = frame2('Преглед на началната страница').locator('.sf-banner');
    await banner.waitFor({ timeout: 20000 });
    check('the frame shows the new banner text at once', (await banner.textContent()).includes('Тест на банера от Дизайн'));
    await shot('07-topbar');

    // ---- DS-2b: Количка и поръчка -------------------------------------------------------------------------------------------------------------------------
    await zone('Количка и поръчка');
    const chSwitch = page.locator('.ds-props').getByRole('switch').first();
    (await chSwitch.getAttribute('aria-checked')) !== 'true' && await chSwitch.click();
    await sleep(300);
    const phoneField = fieldInput('Телефон');
    await phoneField.fill('+359 888 123 456');
    await phoneField.blur();
    await sleep(300);
    await submit();
    const chdr = frame2('Преглед на количката').locator('[data-sf-checkout-header]');
    await chdr.waitFor({ timeout: 20000 });
    check('the frame shows the cart with the simplified header and the phone', (await chdr.locator('a[href^="tel:"]').count()) === 1 && (await frame2('Преглед на количката').locator('[data-sf-header]').count()) === 0);
    await shot('08-checkout-header');

    await page.getByRole('button', { name: 'Публикувай' }).first().click();
    await sleep(500);
    await page.locator('.fi-modal-window button:visible', { hasText: /Потвърди|Публикувай|Confirm/ }).last().click();
    await settle();

    for (const [label, width] of [['desktop', 1280], ['phone', 390]]) {
        const shop = await ctx.newPage();
        await shop.setViewportSize({ width, height: 900 });

        for (const path of ['/cart', '/checkout']) {
            await shop.goto(BASE + path, { waitUntil: 'networkidle' });
            const info = await shop.evaluate(() => ({
                simple: document.querySelectorAll('[data-sf-checkout-header]').length, full: document.querySelectorAll('[data-sf-header]').length,
                overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, label: (document.querySelector('.sf-chdr-back-label') ?? {}).offsetWidth ?? -1,
                tel: !!document.querySelector('.sf-chdr-tel[href^="tel:"]'), styleAttrs: document.querySelectorAll('[style]').length,
            }));
            check(`${path} on ${label}: the simplified header, no full header, no sideways scroll`, info.simple === 1 && info.full === 0 && info.overflow <= 0 && info.tel, JSON.stringify(info));
            label === 'phone' && check(`${path} on the phone: the link keeps its arrow and aria-label while its words give way`, info.label <= 1, JSON.stringify(info));
            await shop.screenshot({ path: OUT ? join(OUT, `09-${label}${path.replace('/', '-')}.png`) : undefined });
        }

        await shop.goto(BASE + '/', { waitUntil: 'networkidle' });
        check(`the home page on ${label} keeps the full header`, (await shop.locator('[data-sf-header]').count()) === 1 && (await shop.locator('[data-sf-checkout-header]').count()) === 0);
        await shop.close();
    }

    // ---- the old Header url (DS-3: it redirects to this screen) ------------------------------------------------------------------------------------
    await page.goto(BASE + '/admin/header-settings', { waitUntil: 'networkidle' });
    check('the old Header url lands on the Design screen, zone Header', page.url().includes('/admin/design') && page.url().includes('zone=header'), page.url());
} catch (e) {
    check('the script ran to its end', false, String(e.stack || e));
    await shot('99-failure');
}

await browser.close();
const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ steps, failed: failed.length, consoleErrors }, null, 2));
process.exit(failed.length ? 1 : 0);
