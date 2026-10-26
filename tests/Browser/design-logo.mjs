// A REAL-BROWSER run of LG-1, the site logo as a picture chosen in the «Дизайн» screen (the «Лого» element of the header and the checkout header's logo). NOT part of `php artisan test`: run by hand
// against a dev site whose data may be changed. BACK UP the design settings first (the `site_settings` table) and restore them after; the pictures it uploads («lg1-…») are deleted afterwards.
//
//   AUDIT_BASE=http://easyco-main.test AUDIT_EMAIL=... AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] \
//   LG_FIXTURES=<dir with lg1-logo.png (transparent) lg1-logo2.png lg1-logo.jpg> OUT=<folder for screenshots> node tests/Browser/design-logo.mjs
//
// With real clicks: «Дизайн» → Хедър → the «Лого» element → the empty field → «Добави изображение» → a JPEG is refused with its reason → a transparent PNG is uploaded → «Избери» → «Приложи» → the preview
// shows it → «Публикувай» → the home page shows it (desktop and phone) → another picture applied as a draft and «Върни публикуваното» → the published one again; «Количка и поръчка»: the same field,
// the cart page shows it; «Медия» → the picture → «Къде се ползва» lists both uses. Prints a JSON report; exit code 1 when a check failed.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const EMAIL = process.env.AUDIT_EMAIL ?? 'lg1-audit@example.test';
const PASSWORD = process.env.AUDIT_PASSWORD;
const FX = process.env.LG_FIXTURES;
const OUT = process.env.OUT ?? '';

if (! PASSWORD || ! process.env.PLAYWRIGHT_DIR || ! FX) {
    console.error('usage: AUDIT_PASSWORD=... PLAYWRIGHT_DIR=... LG_FIXTURES=... node design-logo.mjs');
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
const panel = () => page.locator('.mpf').first();
// the screen has the actions twice (the top bar and the panel's own submit): the first one does the same
const act = (name) => ({ click: () => page.getByRole('button', { name, exact: true }).first().click() });
/** «Публикувай» and «Върни публикуваното» ask first: confirm the modal if there is one */
const confirmIfAsked = async () => { await sleep(900); if (await page.locator('.fi-modal-window:visible').count() > 0) { await modal().getByRole('button').filter({ hasNotText: /Отказ|Cancel/ }).last().click(); } await settle(); };
const closed = async () => { await page.waitForSelector('.fi-modal-window:visible', { state: 'detached', timeout: 15000 }).catch(() => {}); await sleep(1200); };

async function login() {
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
}

/** the header logo as the page prints it: {src, w, h, natural, alpha corner} */
async function logoOf(url, selector) {
    await page.goto(url, { waitUntil: 'networkidle' });
    return await page.evaluate(async (sel) => {
        const img = document.querySelector(sel + ' img');
        if (! img) return null;
        await img.decode().catch(() => {});
        return { src: img.getAttribute('src'), width: img.getAttribute('width'), height: img.getAttribute('height'), natural: img.naturalWidth, lazy: img.getAttribute('loading'), style: img.getAttribute('style') };
    }, selector);
}

async function uploadInModal(file) {
    await modal().getByRole('tab', { name: 'Качи файлове' }).click();
    await sleep(500);
    await modal().locator('input[type=file]').setInputFiles([join(FX, file)]);
    await sleep(3500);
}

/** The queue worker makes the sizes: until it has, a picture is «Обработва се» and cannot be a logo (the screen says so on save). Waits for the grid to show none processing. */
async function untilReady() {
    for (let i = 0; i < 30; i++) {
        if (await modal().locator('.mp-card .mp-wait').count() === 0) return;
        await sleep(1500);
        await modal().locator('.mp-search').fill('lg1-').catch(() => {});
    }
}

try {
    await login();

    // ================================================== the header: the «Лого» element ====================================================
    await page.goto(BASE + '/admin/design', { waitUntil: 'networkidle' });
    await page.waitForSelector('.ds-chip-main', { timeout: 30000 });
    await page.locator('.ds-chip-main', { hasText: 'Лого' }).first().click();
    await settle();
    await page.waitForSelector('.mpf', { timeout: 20000 });

    check('the logo element has the picture field «Лого (снимка)»', (await page.getByText('Лого (снимка)').count()) >= 1);
    check('the empty field says the standard logo is used and offers ONE button', (await panel().innerText()).includes('Ползва се стандартното лого') && await panel().getByRole('button', { name: 'Добави изображение', exact: true }).count() === 1);
    const fieldText = await page.locator('body').innerText();
    check('under the field ONE grey help line with the owners text', fieldText.includes('PNG или WebP, най-добре с прозрачен фон.') && fieldText.includes('около 80–100 px'));
    check('no file name and no dimensions in the empty field', ! /×|\.png|\.webp/i.test(await panel().innerText()));
    await shot('01-logo-field-empty-1400', page.locator('.mpf').first().locator('xpath=ancestor::div[contains(@class,"fi-fo-field")][1]'));

    // the modal: a JPEG is refused with the reason
    await panel().getByRole('button', { name: 'Добави изображение', exact: true }).click();
    await page.waitForSelector('.fi-modal-window:visible .mp', { timeout: 20000 });
    await sleep(600);
    await uploadInModal('lg1-logo.jpg');
    const refusal = await modal().locator('.mp-refused').innerText().catch(() => '');
    check('a JPEG is refused with the reason «тук се приемат само PNG и WebP»', refusal.includes('тук се приемат само PNG и WebP'), refusal);
    check('the file input accepts only PNG and WebP', (await modal().locator('input[type=file]').getAttribute('accept')) === 'image/png,image/webp');
    await shot('02-modal-upload-refused-1400');

    // the transparent PNG is accepted and comes back selected
    await uploadInModal('lg1-logo.png');
    await modal().locator('.mp-card-on').first().waitFor({ timeout: 40000 });
    check('the PNG comes back selected on the library tab', await modal().locator('.mp-card-on').count() === 1);
    await sleep(9000); // the worker makes the sizes; the picture is READY after that
    await modal().locator('.mp-search').fill('lg1-logo.png');
    await sleep(1500);
    await untilReady();
    await modal().locator('.mp-card').first().click().catch(() => {});
    await sleep(500);
    if (await modal().locator('.mp-card-on').count() === 0) await modal().locator('.mp-card').first().click();
    await sleep(500);
    await modal().getByRole('button', { name: 'Избери', exact: true }).click();
    await closed();
    check('the field shows the logo whole on a checker, with «Смени» and «Премахни изображението»', await panel().locator('.mpf-big-checker img').count() === 1 && await panel().getByRole('button', { name: 'Смени', exact: true }).count() === 1 && await panel().getByRole('button', { name: 'Премахни изображението', exact: true }).count() === 1);
    check('no name and no dimensions in the filled field', ! /×|\.png/i.test(await panel().innerText()));
    await shot('03-logo-field-filled-1400', page.locator('.mpf').first().locator('xpath=ancestor::div[contains(@class,"fi-fo-field")][1]'));

    // «Приложи» -> the preview shows it
    await act('Приложи').click();
    await settle();
    await sleep(2500);
    const frame = page.frameLocator('iframe').first();
    const previewSrc = await frame.locator('.sf-logo-desktop img').first().getAttribute('src', { timeout: 20000 }).catch(() => null);
    check('the preview frame shows the new logo (a WebP rendition, not the static file)', previewSrc !== null && previewSrc.endsWith('.webp'), previewSrc);
    await shot('04-design-preview-1400');

    // the phone has its OWN «Лого» element: the same picture is chosen there (from the library, no second upload)
    await page.getByRole('button', { name: 'Телефон', exact: true }).click();
    await settle();
    await page.locator('.ds-chip-main', { hasText: 'Лого' }).first().click();
    await settle();
    await panel().getByRole('button', { name: 'Добави изображение', exact: true }).click();
    await page.waitForSelector('.fi-modal-window:visible .mp', { timeout: 20000 });
    await sleep(600);
    await modal().locator('.mp-search').fill('lg1-logo.png');
    await sleep(1500);
    await modal().locator('.mp-card').first().click();
    await sleep(700);
    await modal().getByRole('button', { name: 'Избери', exact: true }).click();
    await closed();
    await act('Приложи').click();
    await settle();
    // «Публикувай» -> the home page
    await act('Публикувай').click();
    await confirmIfAsked();
    const home = await logoOf(BASE + '/', '.sf-logo-desktop');
    check('the home page prints the published logo: a WebP, width and height present, not lazy, no style', home && home.src.endsWith('.webp') && home.width && home.height && home.lazy === null && home.style === null && home.natural > 0, JSON.stringify(home));
    const publishedSrc = home?.src;
    await shot('05-home-header-1400');
    await page.setViewportSize({ width: 390, height: 800 });
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    check('the phone header prints it too', (await page.locator('.sf-logo-mobile img').first().getAttribute('src'))?.endsWith('.webp') === true);
    check('the page does not scroll sideways at 390px', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    await shot('06-home-header-390');
    await page.setViewportSize({ width: 1400, height: 1000 });

    // a second picture as a DRAFT, then «Върни публикуваното»
    await page.goto(BASE + '/admin/design', { waitUntil: 'networkidle' });
    await page.waitForSelector('.ds-chip-main', { timeout: 30000 });
    await page.locator('.ds-chip-main', { hasText: 'Лого' }).first().click();
    await settle();
    await panel().getByRole('button', { name: 'Смени', exact: true }).click();
    await page.waitForSelector('.fi-modal-window:visible .mp', { timeout: 20000 });
    await sleep(600);
    await uploadInModal('lg1-logo2.png');
    await modal().locator('.mp-card-on').first().waitFor({ timeout: 40000 });
    await sleep(9000);
    await untilReady();
    await modal().getByRole('button', { name: 'Избери', exact: true }).click();
    await closed();
    await act('Приложи').click();
    await settle();
    await sleep(2500);
    const draftSrc = await page.frameLocator('iframe').first().locator('.sf-logo-desktop img').first().getAttribute('src', { timeout: 20000 }).catch(() => null);
    check('the draft shows the second picture in the preview', draftSrc !== null && draftSrc !== publishedSrc, draftSrc);
    const stillPublished = await logoOf(BASE + '/', '.sf-logo-desktop');
    check('the public page keeps the published logo meanwhile', stillPublished?.src === publishedSrc, stillPublished?.src);
    await page.goto(BASE + '/admin/design', { waitUntil: 'networkidle' });
    await page.waitForSelector('.ds-chip-main', { timeout: 30000 });
    await act('Върни публикуваното').click();
    await sleep(800);
    await confirmIfAsked();
    await page.locator('.ds-chip-main', { hasText: 'Лого' }).first().click();
    await settle();
    check('«Върни публикуваното»: the field holds the published picture again', await panel().locator('.mpf-big img').count() === 1);
    await act('Приложи').click().catch(() => {});

    // ================================================== the checkout header ===================================================================
    await page.goto(BASE + '/admin/design', { waitUntil: 'networkidle' });
    await page.waitForSelector('.ds-zone', { timeout: 30000 });
    await page.locator('.ds-zone', { hasText: 'Количка и поръчка' }).click();
    await settle();
    await page.waitForSelector('.mpf', { timeout: 20000 });
    check('the checkout zone has the same field and texts', (await panel().innerText()).includes('Ползва се стандартното лого') && await panel().getByRole('button', { name: 'Добави изображение', exact: true }).count() === 1);
    await shot('07-checkout-field-empty-1400', page.locator('.mpf').first().locator('xpath=ancestor::div[contains(@class,"fi-fo-field")][1]'));
    await panel().getByRole('button', { name: 'Добави изображение', exact: true }).click();
    await page.waitForSelector('.fi-modal-window:visible .mp', { timeout: 20000 });
    await sleep(600);
    await modal().locator('.mp-search').fill('lg1-logo2');
    await sleep(1500);
    await modal().locator('.mp-card').first().click();
    await sleep(700);
    await modal().getByRole('button', { name: 'Избери', exact: true }).click();
    await closed();
    // the simplified header must be enabled for the page to use it
    const toggle = page.getByLabel(/Включен|Включи|Enabled/i).first();
    await toggle.check().catch(() => {});
    await act('Приложи').click();
    await settle();
    await act('Публикувай').click();
    await confirmIfAsked();
    await shot('08-checkout-field-filled-1400', page.locator('.mpf').first().locator('xpath=ancestor::div[contains(@class,"fi-fo-field")][1]'));
    const cart = await logoOf(BASE + '/cart', '.sf-chdr-logo');
    check('the cart page prints the picture in the simplified header', cart && cart.src.endsWith('.webp') && cart.width && cart.lazy === null && cart.style === null, JSON.stringify(cart));
    await shot('09-checkout-header-1400');
    await page.setViewportSize({ width: 390, height: 800 });
    await page.goto(BASE + '/cart', { waitUntil: 'networkidle' });
    await shot('10-checkout-header-390');
    await page.setViewportSize({ width: 1400, height: 1000 });
    const homeAfter = await logoOf(BASE + '/', '.sf-logo-desktop');
    check('the header and the checkout header are independent', homeAfter?.src === publishedSrc && cart?.src !== publishedSrc, JSON.stringify([homeAfter?.src, cart?.src]));

    // ================================================== «Медия»: where it is used =================================================================
    await page.goto(BASE + '/admin/media', { waitUntil: 'networkidle' });
    await page.getByPlaceholder(/Търси|Search/).first().fill('lg1-logo').catch(() => {});
    await sleep(2500);
    const cards = page.locator('.fi-ta-record, .fi-ta-content-grid > *');
    check('«Медия» lists the uploaded logos', await page.getByText('lg1-logo.png').count() >= 1);
    await page.getByText('lg1-logo.png').first().click();
    await sleep(1500);
    const uses = await page.locator('.fi-modal-window:visible, .fi-slide-over-window:visible').last().innerText().catch(() => '');
    check('«Къде се ползва» lists the header logo', uses.includes('Дизайн — Хедър, лого'), uses.slice(0, 300));
    await shot('11-media-where-used-1400');
    await page.keyboard.press('Escape');
    await page.getByText('lg1-logo2.png').first().click();
    await sleep(1500);
    const uses2 = await page.locator('.fi-modal-window:visible, .fi-slide-over-window:visible').last().innerText().catch(() => '');
    check('«Къде се ползва» lists the checkout header logo', uses2.includes('Дизайн — Хедър на поръчката, лого'), uses2.slice(0, 300));
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
