// A REAL-BROWSER run of PC-2: the sizes of the listing cards, asked for in ONE request per page view (desktop: on the first mouse hover; touch: once, when idle).
// NOT part of `php artisan test`: run by hand against the dev site. It needs the throw-away product «PC2 dress» (slug pc2-dress; S in stock, M sold out, L in stock)
// written by the setup script and removed by the cleanup script; nothing else is changed. Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/pc-2.mjs
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const SLUG = 'pc2-dress';

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node pc-2.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const consoleErrors = [];
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 300) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const shot = async (page, name, locator = null) => { if (! OUT) return; const path = join(OUT, name + '.png'); shots.push(path); await (locator ?? page).screenshot({ path }); };
const sizesRequests = (list) => list.filter((u) => u.includes('/storefront/card-sizes'));
const card = (page) => page.locator('article.sf-card', { has: page.locator(`a[href$="/product/${SLUG}"]`) }).first();
const labels = (cardLocator) => cardLocator.locator('.sf-card-sizes li');

async function openHome(page) {
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await sleep(300);
}

try {
    // ---- desktop, 1400 px, mouse -----------------------------------------------------------------------------------------------------------------
    const desktop = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
    const page = await desktop.newPage();
    const requested = [];
    page.on('request', (r) => requested.push(r.url()));
    page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));

    await openHome(page);
    const pc2 = card(page);
    check('the PC2 card is on the home page', (await pc2.count()) === 1);
    check('before any hover: the endpoint was requested 0 times', sizesRequests(requested).length === 0, sizesRequests(requested).length);
    check('before any hover: the size list is still hidden and empty', (await pc2.locator('.sf-card-sizes').getAttribute('hidden')) !== null && (await labels(pc2).count()) === 0);

    await pc2.scrollIntoViewIfNeeded();
    await pc2.hover();
    await page.waitForSelector('article.sf-card .sf-card-sizes li', { timeout: 15000 });
    await sleep(900);

    const asked = sizesRequests(requested);
    check('after the first hover: exactly one request', asked.length === 1, asked.length);
    const pc2Id = await pc2.getAttribute('data-product-id');
    const askedIds = asked.length === 1 ? new URL(asked[0]).searchParams.get('ids').split(',') : [];
    check('the request asks for the visible cards (the PC2 product id is among them)', askedIds.includes(pc2Id), JSON.stringify({ pc2Id, askedIds: askedIds.slice(0, 5), count: askedIds.length }));
    const texts = await labels(pc2).allInnerTexts();
    check('the card shows its three sizes in order: S, M, L', JSON.stringify(texts) === JSON.stringify(['S', 'M', 'L']), JSON.stringify(texts));
    const soldOut = await pc2.locator('.sf-card-sizes li.is-sold-out').allInnerTexts();
    check('the sold-out size M is marked is-sold-out and drawn crossed out', JSON.stringify(soldOut) === JSON.stringify(['M']) && (await pc2.locator('.sf-card-sizes li.is-sold-out').evaluate((el) => getComputedStyle(el).textDecorationLine)).includes('line-through'), JSON.stringify(soldOut));
    const visible = await pc2.locator('.sf-card-sizes').evaluate((el) => Number(getComputedStyle(el).opacity));
    check('on the hover the sizes are visible (opacity 1)', visible === 1, visible);
    const pe = await pc2.locator('.sf-card-sizes').evaluate((el) => getComputedStyle(el).pointerEvents);
    check('the size strip takes no pointer events (a click goes to the product)', pe === 'none', pe);
    await shot(page, '02-card-hovered-with-sizes-1400', pc2);

    await page.mouse.move(5, 5);
    await sleep(300);
    await pc2.hover();
    await sleep(400);
    check('a second hover requests nothing more', sizesRequests(requested).length === 1, sizesRequests(requested).length);

    await shot(page, '03-home-1400');
    await desktop.close();

    // ---- touch, 390 px, no hover: asked once when idle, the strips slide in -----------------------------------------------------------------------
    const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, deviceScaleFactor: 1, ignoreHTTPSErrors: true });
    const touch = await phone.newPage();
    const touchRequests = [];
    touch.on('request', (r) => touchRequests.push(r.url()));
    touch.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));

    await touch.goto(BASE + '/', { waitUntil: 'networkidle' });
    await sleep(3000); // the idle request runs within 2 s of the load
    check('touch: exactly one request after load and idle, with no tap', sizesRequests(touchRequests).length === 1, sizesRequests(touchRequests).length);
    const tcard = card(touch);
    await tcard.scrollIntoViewIfNeeded();
    await sleep(1400);
    check('touch: the sizes of the PC2 card are there', JSON.stringify(await labels(tcard).allInnerTexts()) === JSON.stringify(['S', 'M', 'L']));
    const tOpacity = await tcard.locator('.sf-card-sizes').evaluate((el) => Number(getComputedStyle(el).opacity));
    const tTransform = await tcard.locator('.sf-card-sizes').evaluate((el) => getComputedStyle(el).transform);
    check('touch: the strip has slid in (opacity 1, no transform)', tOpacity === 1 && (tTransform === 'none' || tTransform === 'matrix(1, 0, 0, 1, 0, 0)'), JSON.stringify({ tOpacity, tTransform }));
    await shot(touch, '04-home-390-sizes-visible', tcard);
    const phoneOverflow = await touch.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    check('390 px: the page does not scroll sideways', phoneOverflow <= 0, phoneOverflow);
    await shot(touch, '05-home-390');

    // a tap on a card still goes to the product page
    await tcard.click();
    await touch.waitForURL(new RegExp(`/product/${SLUG}`), { timeout: 15000 }).catch(() => {});
    check('a tap on the card goes to the product page', touch.url().includes(`/product/${SLUG}`), touch.url());
    await phone.close();

    // ---- data saver on: no request at all ------------------------------------------------------------------------------------------------------
    const saver = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, ignoreHTTPSErrors: true });
    await saver.addInitScript(() => {
        Object.defineProperty(navigator, 'connection', { value: { saveData: true }, configurable: true });
    });
    const saverPage = await saver.newPage();
    const saverRequests = [];
    saverPage.on('request', (r) => saverRequests.push(r.url()));
    await saverPage.goto(BASE + '/', { waitUntil: 'networkidle' });
    await sleep(3000);
    check('data saver on: no sizes request', sizesRequests(saverRequests).length === 0, sizesRequests(saverRequests).length);
    await saver.close();

    // ---- JavaScript blocked: nothing appears, the page works ----------------------------------------------------------------------------------
    const nojs = await browser.newContext({ viewport: { width: 1400, height: 1000 }, javaScriptEnabled: false, ignoreHTTPSErrors: true });
    const nojsPage = await nojs.newPage();
    const response = await nojsPage.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    check('without JavaScript the home page answers 200', response && response.status() === 200, response && response.status());
    check('without JavaScript no size is shown', (await nojsPage.locator('.sf-card-sizes li').count()) === 0);
    check('without JavaScript the card is still a link to the product', (await nojsPage.locator(`a[href$="/product/${SLUG}"]`).count()) >= 1);
    await nojs.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 400));
}

check('no page errors', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
