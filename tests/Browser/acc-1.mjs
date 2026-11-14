// A REAL-BROWSER check of ACC-1: the product page's accordion (native <details class="sf-psec">) opens and closes SMOOTHLY.
//   One existing product with accordion sections (PRODUCT, default «jacke-kare») at 1440 and 390: the first section is clicked with a real mouse click, its height is sampled every animation frame for 300 ms
//   (the open and then the close); the heights must pass through intermediate values (no jump) and settle. At 1440 two screenshots are kept (the section open, then closed). A third run at 1440 with
//   `prefers-reduced-motion: reduce` must show NO intermediate value (the instant change). No horizontal scroll at 390.
// READS only: the script writes no data (no checksums, no snapshot, no watchdog needed). NOT part of `php artisan test`: run by hand against the dev site.
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] [PRODUCT=<slug>] OUT=<folder outside the project> node tests/Browser/acc-1.mjs
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const PRODUCT = process.env.PRODUCT ?? 'jacke-kare';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node acc-1.mjs'); process.exit(2); }
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) { console.error('refused: OUT is inside the project folder; the screenshots must be kept outside it'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const fails = [];
const check = (ok, text) => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${text}`); if (! ok) { fails.push(text); } };

// one click on the section's <summary> (a real mouse click); every animation frame the section's height is recorded from the click on
async function flip(page, index) {
    await page.evaluate((i) => {
        const d = document.querySelectorAll('details.sf-psec')[i];
        window.__acc = [];
        const t0 = performance.now();
        const tick = () => {
            const t = performance.now() - t0;
            window.__acc.push({ t: Math.round(t), h: Math.round(d.getBoundingClientRect().height * 10) / 10, open: d.open });
            if (t < 600) { requestAnimationFrame(tick); }
        };
        requestAnimationFrame(tick);
    }, index);
    await page.locator('details.sf-psec > summary.sf-psec-title').nth(index).click();
    await page.waitForTimeout(700);

    return page.evaluate(() => window.__acc);
}

// the samples nearest to the given moments (ms), for the report
const at = (samples, ms) => samples.reduce((best, s) => (Math.abs(s.t - ms) < Math.abs(best.t - ms) ? s : best));

async function openPage(width, reducedMotion) {
    const context = await browser.newContext({ viewport: { width, height: 900 }, reducedMotion, ignoreHTTPSErrors: true });
    const page = await context.newPage();
    await page.goto(`${BASE}/product/${encodeURIComponent(PRODUCT)}`, { waitUntil: 'load' });

    return { context, page };
}

// ---- 1. 1440 and 390: the animation goes through intermediate heights, both ways -------------------------------------------------------------------------------------------------
for (const width of [1440, 390]) {
    const { context, page } = await openPage(width, 'no-preference');
    const sections = await page.locator('details.sf-psec').count();
    check(sections >= 1, `${width}px: the product has accordion sections (${sections})`);

    if (sections >= 1) {
        const start = await page.evaluate(() => document.querySelectorAll('details.sf-psec')[0].open);
        // two flips: the section goes to the other state, then back
        for (const [label, idx] of [['first click', 0], ['second click', 0]]) {
            const samples = await flip(page, idx);
            const h0 = samples[0].h;
            const h1 = samples[samples.length - 1].h;
            const lo = Math.min(h0, h1);
            const hi = Math.max(h0, h1);
            const inWindow = samples.filter((s) => s.t <= 300);
            const between = inWindow.filter((s) => s.h > lo + 1 && s.h < hi - 1);
            const dir = h1 > h0 ? 'grows' : 'shrinks';
            const maxStep = Math.max(...inWindow.slice(1).map((s, k) => Math.abs(s.h - inWindow[k].h)));
            console.log(`      ${width}px ${label}: ${dir} ${h0}px -> ${h1}px; at ~0ms ${at(samples, 0).h}px, ~120ms ${at(samples, 120).h}px, ~240ms ${at(samples, 240).h}px; intermediate samples ${between.length}; largest step ${maxStep}px`);
            check(hi - lo > 20, `${width}px ${label}: the height really changes (${h0} -> ${h1})`);
            check(between.length >= 2, `${width}px ${label}: the height passes through intermediate values within 300 ms (${between.length} samples)`);
            check(maxStep < (hi - lo) * 0.9, `${width}px ${label}: no single jump (largest step ${maxStep}px of ${(hi - lo).toFixed(1)}px)`);

            if (width === 1440 && label === 'first click') {
                await page.screenshot({ path: join(OUT, 'acc-1-1440-open.png') });
            }
            if (width === 1440 && label === 'second click') {
                await page.screenshot({ path: join(OUT, 'acc-1-1440-closed.png') });
            }
        }
        check(start === (await page.evaluate(() => document.querySelectorAll('details.sf-psec')[0].open)), `${width}px: after two clicks the section is back in its starting state`);
    }

    if (width === 390) {
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        check(overflow <= 0, `390px: no horizontal scroll (overflow ${overflow}px)`);
    }
    await context.close();
}

// ---- 2. reduced motion: the change is instant (no intermediate height) ----------------------------------------------------------------------------------------------------------
{
    const { context, page } = await openPage(1440, 'reduce');
    const samples = await flip(page, 0);
    const h0 = samples[0].h;
    const h1 = samples[samples.length - 1].h;
    const lo = Math.min(h0, h1);
    const hi = Math.max(h0, h1);
    const between = samples.filter((s) => s.t <= 300 && s.h > lo + 1 && s.h < hi - 1);
    console.log(`      reduced motion 1440px: ${h0}px -> ${h1}px; intermediate samples ${between.length}`);
    check(hi - lo > 20, `reduced motion: the height really changes (${h0} -> ${h1})`);
    check(between.length === 0, `reduced motion: no intermediate height (the change is instant)`);
    await context.close();
}

// ---- 3. MEAS-5.1: the icon is «+» when the section is closed and «−» (U+2212, not a hyphen) when it is open; no rotation of the icon -------------------------------------------------------
{
    const { context, page } = await openPage(1440, 'no-preference');
    const summary = page.locator('details.sf-psec > summary.sf-psec-title').nth(0);
    const icon = () => summary.evaluate((s) => { const cs = getComputedStyle(s, '::after'); return { content: cs.content, transform: cs.transform }; });
    check((await page.locator('details.sf-psec').count()) >= 1, 'icon: the product has an accordion section');

    const closed = await icon();
    check(closed.content === '"+"', `icon: closed shows a plus (${closed.content})`);
    await summary.click();
    await page.waitForTimeout(700);
    const opened = await icon();
    check(opened.content === '"−"', `icon: open shows a minus sign U+2212 (${opened.content})`);
    check(closed.transform === 'none' && opened.transform === 'none', `icon: not rotated (${closed.transform} / ${opened.transform})`);
    await summary.click();
    await page.waitForTimeout(700);
    check((await icon()).content === '"+"', 'icon: closed again shows the plus');
    await context.close();
}

await browser.close();

console.log(fails.length === 0 ? '\nALL CHECKS PASSED' : `\n${fails.length} CHECK(S) FAILED`);
process.exit(fails.length === 0 ? 0 : 1);
