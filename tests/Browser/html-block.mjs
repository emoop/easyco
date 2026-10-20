// A REAL-BROWSER run of «Снимки с връзки» (MENU-5, «Дизайн» -> «HTML блокове»). It is NOT part of `php artisan test`: it is run by hand against a dev site whose data may be changed
// (back up first and restore after: html_blocks, catalog_media, the settings, the menus and the uploaded files).
//
//   PHASE=admin AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> PICTURE=<a png file> LINK=<a path of the shop> [OUT=<folder for screenshots>] node tests/Browser/html-block.mjs
//   ... (put the new block on the mega item: the printed id goes to menu_items.html_block_id, then MenuRecompiler::now()) ...
//   PHASE=shop  AUDIT_PASSWORD=... PLAYWRIGHT_DIR=... MEGA=<the label of the mega item> LINK=<the same path> [OUT=...] node tests/Browser/html-block.mjs
//
// admin: creates a block (title, ONE picture: upload, description, «Връзка при щракване»; no body: a block of pictures only), saves, reopens it and compares what came back, tries a
//        `javascript:` address (refused on its own field), prints the block's id as `BLOCK <id>`.
// shop : opens the shop at 1280px, hovers the mega item, checks the picture in the panel is wrapped in a link to the address, CLICKS the picture and checks the browser went to the address;
//        waits (up to 90 s) for the queue worker to finish the picture and checks the shop then prints its WebP variant (the asset-READY recompile).
// Prints a JSON report; exit code 1 when a check failed.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const EMAIL = process.env.AUDIT_EMAIL ?? 'ux-audit@example.test';
const PASSWORD = process.env.AUDIT_PASSWORD;
const PHASE = process.env.PHASE ?? 'admin';
const OUT = process.env.OUT ?? '';
const LINK = process.env.LINK ?? '/';

if (! PASSWORD || ! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PHASE=admin|shop AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> node html-block.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });
const ctx = await browser.newContext({ viewport: { width: 1500, height: 2000 }, ignoreHTTPSErrors: true });
const page = await ctx.newPage();

const steps = [];
const consoleErrors = [];
page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 300)); });
page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.stack || e).replace(/\s+/g, ' ').slice(0, 600)));

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const check = (name, ok, detail = '') => { steps.push({ name, ok: !!ok, detail: String(detail).slice(0, 400) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const shot = async (p, name) => { if (OUT) await p.screenshot({ path: join(OUT, name + '.png'), fullPage: false }); };

async function login() {
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
}

const settle = async () => { await page.waitForLoadState('networkidle'); await sleep(900); };

try {
    if (PHASE === 'admin') {
        await login();
        await page.goto(BASE + '/admin/html-blocks/create', { waitUntil: 'networkidle' });
        await page.fill('input[id$="title"]', 'MENU-5 browser test');
        check('the hint says where a link on text and on a picture is set', (await page.content()).includes('бутона „връзка“ в редактора; връзката на снимка — тук'));

        await page.getByRole('button', { name: 'Добави снимка' }).click();
        await settle();
        await page.locator('input[type=file]').first().setInputFiles(process.env.PICTURE);
        await page.waitForSelector('.filepond--item', { timeout: 30000 });
        await sleep(2500);
        await page.locator('input[id$=".alt"]').first().fill('MENU-5 тестова снимка');
        await page.locator('input[id$=".url"]').first().fill('javascript:alert(1)');
        await shot(page, 'html-block-create');
        await page.getByRole('button', { name: /^Създай$/ }).first().click();
        await settle();
        const refused = await page.getByText(/https:\/\/ или път в магазина/).first().isVisible().catch(() => false);
        check('a javascript: address is refused on its own field, with a Bulgarian message', refused);
        check('nothing was saved yet (still on the create page)', page.url().includes('/create'));

        await page.locator('input[id$=".url"]').first().fill(LINK);
        await sleep(500);
        await page.getByRole('button', { name: /^Създай$/ }).first().click();
        await page.waitForURL(/\/html-blocks\/\d+\/edit/, { timeout: 30000 });
        await settle();
        const id = (page.url().match(/html-blocks\/(\d+)\/edit/) ?? [])[1];
        check('a block of pictures only (no body) is saved', !!id, page.url());
        console.log('BLOCK ' + id);

        await page.goto(BASE + `/admin/html-blocks/${id}/edit`, { waitUntil: 'networkidle' });
        const alt = await page.locator('input[id$=".alt"]').first().inputValue();
        const url = await page.locator('input[id$=".url"]').first().inputValue();
        check('after a reload the description and the address are the same', alt === 'MENU-5 тестова снимка' && url === LINK, `${alt} | ${url}`);
        check('the uploaded picture is shown in the field', (await page.locator('.filepond--item').count()) === 1, await page.locator('.filepond--item').count());
        await shot(page, 'html-block-edit');
    } else {
        const phone = page;
        await phone.setViewportSize({ width: 1280, height: 900 });
        await phone.goto(BASE + '/', { waitUntil: 'networkidle' });
        const item = phone.locator('[data-sf-bar-item]', { hasText: process.env.MEGA ?? 'Дрехи' }).first();
        await item.hover();
        await sleep(600);
        const panel = item.locator('.sf-sub-mega');
        const anchor = panel.locator('.sf-mega-block a:has(img), .sf-mega-picture a:has(img), a:has(img)').first();
        check('the mega panel has the picture wrapped in a link', (await anchor.count()) === 1);
        check('the link goes to the address', (await anchor.getAttribute('href')) === LINK, await anchor.getAttribute('href'));
        check('the link has rel="noopener nofollow"', (await anchor.getAttribute('rel')) === 'noopener nofollow');
        await shot(phone, 'mega-with-linked-picture');
        await anchor.click();
        await phone.waitForLoadState('load');
        check('clicking the picture goes to the address', new URL(phone.url()).pathname === LINK.split('?')[0], phone.url());
        await shot(phone, 'after-click');

        // the asset becomes READY (the queue worker): the compiled menu then prints the WebP variant
        let variant = false;

        for (let i = 0; i < 18 && ! variant; i++) {
            await phone.goto(BASE + '/', { waitUntil: 'networkidle' });
            const src = await phone.locator('.sf-sub-mega img').first().getAttribute('src').catch(() => '');
            variant = /-(thumbnail|medium|large)\.webp$/.test(src ?? '');
            variant || await sleep(5000);
        }

        check('once the picture is processed the shop prints its WebP variant', variant);
    }
} catch (error) {
    check('the run finished', false, String(error.stack || error).slice(0, 500));
    await shot(page, 'html-block-failure').catch(() => {});
}

check('no console errors', consoleErrors.length === 0, consoleErrors.join(' | '));
console.log(JSON.stringify({ phase: PHASE, passed: steps.filter((s) => s.ok).length, failed: steps.filter((s) => ! s.ok), total: steps.length }, null, 2));
await browser.close();
process.exit(steps.every((s) => s.ok) ? 0 : 1);
