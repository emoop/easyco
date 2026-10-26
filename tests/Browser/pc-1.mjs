// A REAL-BROWSER run of PC-1: the product card shows the whole photo in the shop's 3:4 frame, and a product with a second photo shows it on the first mouse hover (desktop) or
// keyboard focus. NOT part of `php artisan test`: run by hand against the dev site. It creates and changes NOTHING: it uses the products the dev catalog already has (a card with
// four photos, a card with one photo). Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/pc-1.mjs
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node pc-1.mjs');
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

// The card with a hover photo, and the one without.
const twoPhotoCard = (page) => page.locator('article.sf-card', { has: page.locator('img.sf-card-image-hover') }).first();
const onePhotoCard = (page) => page.locator('article.sf-card:not(:has(img.sf-card-image-hover)):has(img.sf-image)').first();
const hoverImage = (card) => card.locator('img.sf-card-image-hover');

const opacity = (locator) => locator.evaluate((el) => getComputedStyle(el).opacity);

try {
    // ---- desktop, 1400 px -------------------------------------------------------------------------------------------------------------------
    const desktop = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
    const page = await desktop.newPage();
    const requested = [];
    page.on('request', (r) => requested.push(r.url()));
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 200)); });
    page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));

    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const cards = page.locator('article.sf-card');
    check('the home page shows product cards', (await cards.count()) >= 2, await cards.count());

    const two = twoPhotoCard(page);
    const one = onePhotoCard(page);
    check('a card with a second photo is on the page', (await two.count()) === 1);
    check('a card with one photo is on the page', (await one.count()) === 1);

    // the frame: 3:4 (portrait) and the picture fills it
    const frame = await two.locator('.sf-card-image').boundingBox();
    check('the frame is portrait 3:4 (width / height = 0.75)', frame && Math.abs(frame.width / frame.height - 0.75) < 0.02, JSON.stringify(frame));
    const main = await two.locator('img.sf-image').boundingBox();
    check('the main photo fills the frame width', main && Math.abs(main.width - frame.width) < 1, JSON.stringify([main, frame]));

    await two.scrollIntoViewIfNeeded();
    await sleep(400);
    const hover = hoverImage(two);
    const dataSrc = await hover.getAttribute('data-src');
    check('before any hover the second photo has no src and is not fetched', (await hover.getAttribute('src')) === null && dataSrc !== null, dataSrc);
    check('the hover image is invisible at rest', (await opacity(hover)) === '0', await opacity(hover));
    const fetchedBefore = requested.filter((u) => u === dataSrc).length;
    check('before the hover: the second photo was requested 0 times', fetchedBefore === 0, fetchedBefore);
    await shot(page, '01-card-at-rest-1400', two);

    await two.hover();
    await page.waitForSelector('.sf-card-image-hover.is-loaded', { timeout: 15000 });
    await sleep(1100); // the zoom takes 800 ms: the screenshot is taken after it has ended
    check('after the first hover: the second photo was requested once', requested.filter((u) => u === dataSrc).length === 1, requested.filter((u) => u === dataSrc).length);
    check('after the hover the second photo is visible', (await opacity(hover)) === '1', await opacity(hover));
    const zoom = await hover.evaluate((el) => getComputedStyle(el).transform);
    const scaleX = zoom === 'none' ? 1 : Number(zoom.match(/matrix\(([^,]+)/)?.[1] ?? 1);
    check('after the hover the photo has grown to 1.1 (desktop, motion allowed)', Math.abs(scaleX - 1.1) < 0.01, zoom);
    await shot(page, '02-card-hovered-1400', two);

    await page.mouse.move(5, 5);
    await sleep(300);
    await two.hover();
    await sleep(400);
    check('a second hover requests nothing more', requested.filter((u) => u === dataSrc).length === 1, requested.filter((u) => u === dataSrc).length);

    // keyboard focus shows it too (a fine pointer)
    const second = page.locator('article.sf-card:has(img.sf-card-image-hover)').nth(1);
    if ((await second.count()) === 1) {
        const other = await hoverImage(second).getAttribute('data-src');
        await second.locator('a.sf-card-link').focus();
        await sleep(600);
        check('keyboard focus on a card requests its second photo once', requested.filter((u) => u === other).length === 1, requested.filter((u) => u === other).length);
    }

    // the one-photo card: no hover image at all, nothing extra
    const oneHTML = await one.evaluate((el) => el.outerHTML);
    check('a one-photo card has no hover image and no wrapper', ! oneHTML.includes('sf-card-image-hover'));
    await one.scrollIntoViewIfNeeded();
    await shot(page, '04-one-photo-card-1400', one);

    // the page at 1400
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await shot(page, '05-home-1400');
    const categoryLink = page.locator('a[href^="/product-category/"]:visible').first();
    if ((await categoryLink.count()) === 1) {
        await categoryLink.click();
        await page.waitForLoadState('networkidle');
        await shot(page, '06-category-1400');
    }
    await desktop.close();

    // ---- phone, 390 px, touch --------------------------------------------------------------------------------------------------------------------
    const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, deviceScaleFactor: 1, ignoreHTTPSErrors: true });
    const touch = await phone.newPage();
    const touchRequests = [];
    touch.on('request', (r) => touchRequests.push(r.url()));
    touch.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));

    await touch.goto(BASE + '/', { waitUntil: 'networkidle' });
    const phoneTwo = twoPhotoCard(touch);
    check('on the phone the card with a second photo is there', (await phoneTwo.count()) === 1);
    await phoneTwo.scrollIntoViewIfNeeded();
    await sleep(300);
    const phoneDataSrc = await hoverImage(phoneTwo).getAttribute('data-src');

    // a touch 'pointerover' (what a finger produces) must not load it
    await hoverImage(phoneTwo).evaluate((el) => el.closest('article').dispatchEvent(new PointerEvent('pointerover', { bubbles: true, pointerType: 'touch' })));
    await sleep(800);
    check('touch: the second photo is never requested', touchRequests.filter((u) => u === phoneDataSrc).length === 0, touchRequests.filter((u) => u === phoneDataSrc).length);
    check('touch: no is-loaded and the photo stays hidden', (await hoverImage(phoneTwo).getAttribute('class')).includes('is-loaded') === false && (await opacity(hoverImage(phoneTwo))) === '0');
    await shot(touch, '03-card-touch-390', phoneTwo);

    const phoneFrame = await phoneTwo.locator('.sf-card-image').boundingBox();
    const phoneOverflow = await touch.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    check('390 px: the frame is 3:4 and the page does not scroll sideways', phoneFrame && Math.abs(phoneFrame.width / phoneFrame.height - 0.75) < 0.02 && phoneOverflow <= 0, JSON.stringify({ phoneFrame, phoneOverflow }));
    await touch.goto(BASE + '/', { waitUntil: 'networkidle' });
    await shot(touch, '07-home-390');
    const phoneCategory = touch.locator('a[href^="/product-category/"]:visible').first();
    if ((await phoneCategory.count()) === 1) {
        await phoneCategory.click();
        await touch.waitForLoadState('networkidle');
        await shot(touch, '08-category-390');
    }
    await phone.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 400));
}

check('no page errors', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
