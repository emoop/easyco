// A REAL-BROWSER check of DRIBNI-6: the product page's quantity counter (−/+ and the field) next to the add-to-cart button, at 1440px (one line) and 390px (two full-width rows).
// READ-ONLY: it changes no setting and creates nothing. The button's light / dark colours and the corner radius are set on the page's own <html> element by the script (never saved).
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> [PRODUCT=pantaloni-rips] node tests/Browser/dribni-6.mjs
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const PRODUCT = process.env.PRODUCT ?? 'pantaloni-rips';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node dribni-6.mjs'); process.exit(2); }
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) { console.error('refused: OUT is inside the project folder'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });
const steps = []; const shots = []; const report = {}; const consoleErrors = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + String(detail).slice(0, 300)); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// the counter, the field and the add-to-cart button of the purchase row, as measured on the page
const measure = (page) => page.evaluate(() => {
    const row = document.querySelector('.sf-buy-row');
    const box = document.querySelector('.sf-qty-box');
    const minus = document.querySelector('[data-sf-qty-minus]');
    const plus = document.querySelector('[data-sf-qty-plus]');
    const btn = document.querySelector('.sf-buy-row .sf-add-to-cart');
    if (! row || ! box || ! minus || ! plus || ! btn) return null;
    const r = (el) => el.getBoundingClientRect();
    const cs = (el) => getComputedStyle(el);
    const rowRect = r(row); const boxRect = r(box); const btnRect = r(btn);

    return {
        innerW: innerWidth,
        scrollW: document.documentElement.scrollWidth,
        rowW: Math.round(rowRect.width),
        boxTop: Math.round(boxRect.top), boxW: Math.round(boxRect.width), boxH: Math.round(boxRect.height),
        btnTop: Math.round(btnRect.top), btnW: Math.round(btnRect.width), btnH: Math.round(btnRect.height),
        minusW: Math.round(r(minus).width), plusW: Math.round(r(plus).width),
        minusBg: cs(minus).backgroundColor, minusColor: cs(minus).color,
        plusBg: cs(plus).backgroundColor, plusColor: cs(plus).color,
        btnBg: cs(btn).backgroundColor, btnColor: cs(btn).color,
        boxRadiusTL: cs(box).borderTopLeftRadius, boxRadiusBR: cs(box).borderBottomRightRadius,
        minusRadiusTL: cs(minus).borderTopLeftRadius, minusRadiusTR: cs(minus).borderTopRightRadius,
        plusRadiusTR: cs(plus).borderTopRightRadius, plusRadiusBR: cs(plus).borderBottomRightRadius,
        boxOverflow: cs(box).overflowX,
    };
});

const setVars = (page, vars) => page.evaluate((v) => {
    for (const [k, val] of Object.entries(v)) {
        val === null ? document.documentElement.style.removeProperty(k) : document.documentElement.style.setProperty(k, val);
    }
}, vars);

try {
    for (const [width, mobile] of [[390, true], [1440, false]]) {
        const c = await browser.newContext({ viewport: { width, height: 900 }, ignoreHTTPSErrors: true, ...(mobile ? { hasTouch: true, isMobile: true } : {}) });
        const page = await c.newPage();
        page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        page.on('response', (r) => { r.status() >= 500 && consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 120)); });
        await page.goto(BASE + '/product/' + PRODUCT, { waitUntil: 'networkidle' });
        await page.locator('.sf-buy-row').scrollIntoViewIfNeeded();
        await sleep(300);
        const m = await measure(page);
        const tag = `${width}px`;
        report['m_' + width] = m;
        check(`${tag}: the counter is shown next to the add-to-cart button`, m !== null && m.boxW > 0, JSON.stringify(m));
        if (m === null) { await c.close(); continue; }

        check(`${tag}: no horizontal scroll (scrollWidth <= innerWidth)`, m.scrollW <= m.innerW, `${m.scrollW} vs ${m.innerW}`);
        check(`${tag}: counter height = button height`, m.boxH === m.btnH, `${m.boxH} vs ${m.btnH}`);

        if (width === 390) {
            check('390: the counter and the button are TWO rows (the counter above the button)', m.boxTop < m.btnTop, `${m.boxTop} vs ${m.btnTop}`);
            check('390: the counter is the full row width (350px)', Math.abs(m.boxW - m.rowW) <= 2 && m.rowW >= 340, `box ${m.boxW}, row ${m.rowW}`);
            check('390: the button is the full row width', Math.abs(m.btnW - m.rowW) <= 2, `btn ${m.btnW}, row ${m.rowW}`);
            check('390: the −/+ are 48px wide (3rem)', m.minusW === 48 && m.plusW === 48, `${m.minusW} / ${m.plusW}`);
        } else {
            check('1440: the counter and the button are ONE line (same top)', Math.abs(m.boxTop - m.btnTop) <= 2, `${m.boxTop} vs ${m.btnTop}`);
            check('1440: the −/+ are 32px wide (2rem)', m.minusW === 32 && m.plusW === 32, `${m.minusW} / ${m.plusW}`);
        }

        // the −/+ take the add-to-cart button's colours as they are (background = its background, sign = its text); no swap
        check(`${tag}: −/+ are the button's colours (background = the button's background, sign = its text)`, m.minusBg === m.btnBg && m.plusBg === m.btnBg && m.minusColor === m.btnColor && m.plusColor === m.btnColor, JSON.stringify({ btnBg: m.btnBg, btnColor: m.btnColor, minusBg: m.minusBg, minusColor: m.minusColor }));
        // no hover change on the −/+ (the add-to-cart button changes on hover; the counter does not)
        {
            const before = await measure(page);
            await page.locator('[data-sf-qty-plus]').hover(); await sleep(150);
            const hov = await measure(page);
            check(`${tag}: hovering + does not change its colours`, hov.plusBg === before.plusBg && hov.plusColor === before.plusColor, JSON.stringify({ before: [before.plusBg, before.plusColor], hover: [hov.plusBg, hov.plusColor] }));
            await page.mouse.move(0, 0);
        }

        // light and dark button (set on the page only, never saved): the −/+ follow the button
        // the browser reports colours as rgb(): the expected values are written the same way
        for (const [label, vars, bgRgb, colorRgb] of [['light button', { '--sf-buy-bg': '#ffffff', '--sf-buy-color': '#111111' }, 'rgb(255, 255, 255)', 'rgb(17, 17, 17)'], ['dark button', { '--sf-buy-bg': '#111111', '--sf-buy-color': '#ffffff' }, 'rgb(17, 17, 17)', 'rgb(255, 255, 255)']]) {
            await setVars(page, vars);
            await sleep(150);
            const v = await measure(page);
            check(`${tag}, ${label}: the button is ${bgRgb} on ${colorRgb}, the −/+ match it`, v.btnBg === bgRgb && v.btnColor === colorRgb && v.minusBg === bgRgb && v.minusColor === colorRgb && v.plusBg === bgRgb && v.plusColor === colorRgb, JSON.stringify({ btnBg: v.btnBg, btnColor: v.btnColor, minusBg: v.minusBg, minusColor: v.minusColor, plusBg: v.plusBg, plusColor: v.plusColor }));
            if (width === 390) {
                await page.locator('.sf-buy-row').scrollIntoViewIfNeeded();
                const shot = join(OUT, `dribni6-${label.replace(' ', '-')}-${tag}.png`); shots.push(shot); await page.screenshot({ path: shot });
            }
        }
        await setVars(page, { '--sf-buy-bg': null, '--sf-buy-color': null });

        // a radius (on the page only): the box and the −/+ corners follow it, the inner edges stay square
        await setVars(page, { '--sf-buy-radius': '12px' });
        await sleep(150);
        const rr = await measure(page);
        check(`${tag}: the box's left and right corners follow --sf-buy-radius`, rr.boxRadiusTL === '12px' && rr.boxRadiusBR === '12px', `${rr.boxRadiusTL} / ${rr.boxRadiusBR}`);
        check(`${tag}: the −'s own corners are square (the box clips its outer corners)`, rr.minusRadiusTL === '0px' && rr.minusRadiusTR === '0px', `${rr.minusRadiusTL} / ${rr.minusRadiusTR}`);
        check(`${tag}: the box clips its contents (overflow hidden)`, rr.boxOverflow === 'hidden', rr.boxOverflow);
        if (width === 390) {
            await page.locator('.sf-buy-row').scrollIntoViewIfNeeded();
            const shotR = join(OUT, 'dribni6-radius-390.png'); shots.push(shotR); await page.screenshot({ path: shotR });
        }
        await setVars(page, { '--sf-buy-radius': null });

        const shot = join(OUT, `dribni6-buy-${tag}.png`); shots.push(shot);
        await page.locator('.sf-buy-row').scrollIntoViewIfNeeded(); await sleep(200);
        await page.screenshot({ path: shot });
        await c.close();
    }

    // ---- the listing cloud (DRIBNI-6 addition): hover and keyboard focus SWAP the two colours, under the cloud only
    {
        const c = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
        const page = await c.newPage();
        page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        await page.goto(BASE + '/product-category/clothing', { waitUntil: 'networkidle' });
        const cloud = (p) => p.evaluate(() => {
            const nav = document.querySelector('.sf-cloud');
            const a = nav && nav.querySelector('a');
            if (! a) return null;
            const s = getComputedStyle(a);

            return { shape: (nav.className.match(/sf-cloud-(site|pill)/) || [])[1] ?? null, bg: s.backgroundColor, color: s.color, links: nav.querySelectorAll('a').length };
        });
        // a chip's state with the pointer on it, and with the keyboard focus on it (Tab from the top of the page)
        const hoverOf = async (p) => { const a = p.locator('.sf-cloud a').first(); await a.hover(); await sleep(120); const v = await cloud(p); await p.mouse.move(0, 0); await sleep(60); return v; };
        const focusOf = async (p) => {
            await p.evaluate(() => { document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0); });
            for (let i = 0; i < 150; i++) {
                await p.keyboard.press('Tab');
                const inside = await p.evaluate(() => !! (document.activeElement && document.activeElement.closest('.sf-cloud a') && document.activeElement.matches(':focus-visible')));
                if (inside) {
                    await sleep(80);
                    // the state of the chip that HAS the focus (not the first link)
                    const v = await p.evaluate(() => {
                        const s = getComputedStyle(document.activeElement);

                        return { shape: (document.querySelector('.sf-cloud').className.match(/sf-cloud-(site|pill)/) || [])[1] ?? null, bg: s.backgroundColor, color: s.color, links: document.querySelectorAll('.sf-cloud a').length, focused: document.activeElement.textContent.trim() };
                    });
                    // the focus is taken off again: the next measurement must not see a focused chip
                    await p.evaluate(() => document.activeElement && document.activeElement.blur());
                    await sleep(60);
                    return v;
                }
            }
            return null;
        };
        const shapeOf = async (p) => p.evaluate(() => document.querySelector('.sf-cloud')?.className ?? '');

        // "default" = no merchant colour: the variables are `initial` on the page (the fallbacks of the rule apply; the shop's saved colours are not touched)
        const defaults = { '--sf-cloud-bg': 'initial', '--sf-cloud-color': 'initial' };
        await setVars(page, defaults);
        await sleep(120);
        const normal = await cloud(page);
        report.cloud_normal_site = normal;
        check('the cloud is on the page with its links (site shape)', normal !== null && normal.shape === 'site' && normal.links >= 2, JSON.stringify(normal));
        if (normal) {
            const h = await hoverOf(page);
            check('site, default colours: the chip is white (the shop\'s look)', normal.bg === 'rgb(255, 255, 255)', JSON.stringify(normal));
            check('site, default colours: hover swaps the background and the text colour', h && h.bg === normal.color && h.color === normal.bg, JSON.stringify({ normal, hover: h }));
            const f = await focusOf(page);
            check('site, default colours: keyboard focus swaps them too', f && f.bg === normal.color && f.color === normal.bg, JSON.stringify({ normal, focus: f }));
            const flt = await page.evaluate(() => [...document.querySelectorAll('.sf-bv-chip')].filter((e) => ! e.closest('.sf-cloud')).length);
            report.filter_chips_outside_cloud = flt;
        }

        // the merchant's two colours, set on the page only (not saved)
        await page.evaluate(() => { document.documentElement.style.setProperty('--sf-cloud-bg', '#1d3b5c'); document.documentElement.style.setProperty('--sf-cloud-color', '#ffffff'); });
        await sleep(120);
        const mNormal = await cloud(page);
        const mHover = await hoverOf(page);
        check('site, merchant colours: normal = blue on white, hover = white on blue', mNormal.bg === 'rgb(29, 59, 92)' && mNormal.color === 'rgb(255, 255, 255)' && mHover.bg === 'rgb(255, 255, 255)' && mHover.color === 'rgb(29, 59, 92)', JSON.stringify({ mNormal, mHover }));
        const mFocus = await focusOf(page);
        check('site, merchant colours: keyboard focus swaps them too', mFocus && mFocus.bg === 'rgb(255, 255, 255)' && mFocus.color === 'rgb(29, 59, 92)', JSON.stringify(mFocus));
        await page.locator('.sf-cloud').first().scrollIntoViewIfNeeded();
        await page.locator('.sf-cloud a').nth(1).hover(); await sleep(120);
        const shotH = join(OUT, 'dribni6-cloud-site-hover-merchant-1440.png'); shots.push(shotH); await page.screenshot({ path: shotH, clip: { x: 0, y: 150, width: 1440, height: 200 } });
        await page.mouse.move(0, 0);

        // the rounded shape: the same links in the rounded markup (set on the page, the saved shape is not changed)
        await page.evaluate(() => {
            const nav = document.querySelector('.sf-cloud');
            const links = [...nav.querySelectorAll('a')].map((a) => [a.getAttribute('href'), a.textContent]);
            nav.className = 'sf-subcategories sf-cloud sf-cloud-pill';
            const ul = nav.querySelector('ul');
            ul.className = 'sf-subcategory-list';
            ul.innerHTML = '';
            for (const [href, text] of links) {
                const li = document.createElement('li'); li.className = 'sf-subcategory';
                const a = document.createElement('a'); a.setAttribute('href', href); a.textContent = text;
                li.appendChild(a); ul.appendChild(li);
            }
        });
        await sleep(120);
        check('rounded shape (page markup): the nav carries sf-cloud-pill', (await shapeOf(page)).includes('sf-cloud-pill'));
        const pMerchantHover = await hoverOf(page);
        check('rounded, merchant colours: hover = white on blue', pMerchantHover.bg === 'rgb(255, 255, 255)' && pMerchantHover.color === 'rgb(29, 59, 92)', JSON.stringify(pMerchantHover));
        const pMerchantFocus = await focusOf(page);
        check('rounded, merchant colours: keyboard focus = white on blue', pMerchantFocus && pMerchantFocus.bg === 'rgb(255, 255, 255)' && pMerchantFocus.color === 'rgb(29, 59, 92)', JSON.stringify(pMerchantFocus));

        // back to the shop's own colours (the variables removed): the rounded chips swap their defaults
        await setVars(page, defaults);
        await sleep(120);
        const pNormal = await cloud(page);
        const pHover = await hoverOf(page);
        check('rounded, default colours: normal = white chip, hover = the swapped pair', pNormal.bg === 'rgb(255, 255, 255)' && pHover.bg === pNormal.color && pHover.color === pNormal.bg, JSON.stringify({ pNormal, pHover }));
        const pFocus = await focusOf(page);
        check('rounded, default colours: keyboard focus = the swapped pair', pFocus && pFocus.bg === pNormal.color && pFocus.color === pNormal.bg, JSON.stringify({ pNormal, pFocus }));
        await c.close();
    }

    check('no page error and no 5xx during the run', consoleErrors.length === 0, JSON.stringify(consoleErrors.slice(0, 5)));
} catch (e) {
    check('the run finished without an exception', false, String(e).slice(0, 900));
} finally {
    await browser.close();
    const failed = steps.filter((s) => ! s.ok);
    console.log(JSON.stringify({ base: BASE, product: PRODUCT, report, shots, console_errors: consoleErrors, steps, failed: failed.length }, null, 2));
    process.exit(failed.length === 0 ? 0 : 1);
}
