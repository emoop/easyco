// P8d: what the app-side protection looks like in a real browser. READ-ONLY apart from the admin login (no order is placed, nothing is saved).
//
//  1. a `securitypolicyviolation` listener (addInitScript) on home, a category, a product, the cart and the checkout: every violation (directive + blocked URI) is listed, the expected number is 0
//  2. the honeypot on the checkout: off-screen (bounding box), not reachable with Tab, aria-hidden, no style attribute
//  3. the header table of the same pages (X-Frame-Options, Permissions-Policy, CSP report-only, P8b cache headers)
//  4. (optional: AUDIT_EMAIL + AUDIT_PASSWORD of an Administrator) the admin «Сигурност» page with the new field, a screenshot
//
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> OUT=<folder outside the project> [AUDIT_EMAIL=.. AUDIT_PASSWORD=..] node tests/Browser/P8d.mjs
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... OUT=... node tests/Browser/P8d.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'package.json'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });
const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, ignoreHTTPSErrors: true });
const page = await context.newPage();

await page.addInitScript(() => {
    window.__violations = [];
    document.addEventListener('securitypolicyviolation', (event) => {
        window.__violations.push({ directive: event.effectiveDirective, blocked: event.blockedURI, disposition: event.disposition });
    });
});

// The checkout form exists only for a non-empty cart (the script removes it for an empty one). With AUDIT_VARIATION=<id of a variation in stock> one line is added
// through the real API (a guest cart cookie in this browser context) and REMOVED again at the end.
let result_add = null;
const VARIATION = process.env.AUDIT_VARIATION ?? '';
const cartApi = async (method, path, data) => page.evaluate(async ([m, u, d]) => {
    // the storefront's own way (storefront-cart.js): the CSRF cookie first, then the write with X-XSRF-TOKEN
    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    const token = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/) ?? [])[1] ?? '');
    const response = await fetch(u, {
        method: m,
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': token },
        body: d ? JSON.stringify(d) : undefined,
    });

    return response.status;
}, [method, path, data]);

if (VARIATION) {
    await page.goto(BASE + '/cart', { waitUntil: 'networkidle' });
    result_add = await cartApi('POST', '/api/cart/lines', { variation_id: VARIATION, quantity: 1 });
}

const PAGES = [['home', '/'], ['category', '/product-category/clothing'], ['product', '/product/pantaloni-rips'], ['cart', '/cart'], ['checkout', '/checkout']];
const result = { base: BASE, headers: [], violations: [], honeypot: null, admin: null, failed: 0 };

for (const [name, path] of PAGES) {
    const response = await page.goto(BASE + path, { waitUntil: 'networkidle' });
    const headers = response.headers();

    result.headers.push({
        page: name,
        status: response.status(),
        'x-frame-options': headers['x-frame-options'] ?? null,
        'permissions-policy': headers['permissions-policy'] ?? null,
        csp: (headers['content-security-policy-report-only'] ?? '').slice(0, 60) + '…',
        cspEnforced: headers['content-security-policy'] ?? null,
        'cache-control': headers['cache-control'] ?? null,
        xkey: headers['xkey'] ? 'yes' : '-',
    });

    const found = await page.evaluate(() => window.__violations);
    found.forEach((item) => result.violations.push({ page: name, ...item }));

    if (name === 'checkout') {
        const trap = await page.$('[data-sf-hp]');

        if (! trap) {
            result.honeypot = { present: false };
            result.failed++;
        } else {
            const box = await trap.boundingBox();
            const info = await trap.evaluate((node) => ({
                tabindex: node.getAttribute('tabindex'),
                autocomplete: node.getAttribute('autocomplete'),
                wrapperAriaHidden: node.parentElement.getAttribute('aria-hidden'),
                wrapperClass: node.parentElement.className,
                wrapperStyleAttribute: node.parentElement.hasAttribute('style'),
                displayNone: getComputedStyle(node.parentElement).display === 'none',
            }));

            // Tab through the whole page: the field must never get the focus
            let reached = false;
            for (let i = 0; i < 80 && ! reached; i++) {
                await page.keyboard.press('Tab');
                reached = await page.evaluate(() => document.activeElement?.hasAttribute('data-sf-hp') === true);
            }

            result.honeypot = { present: true, boundingBox: box, offScreen: box === null || box.x + box.width <= 0 || box.x < -5000, reachableWithTab: reached, ...info };
            OUT && await page.screenshot({ path: join(OUT, 'p8d-checkout.png'), fullPage: false });
            (reached || ! (box === null || box.x < -5000) || info.displayNone || info.wrapperStyleAttribute) && result.failed++;
        }
    }
}

if (VARIATION) {
    await page.goto(BASE + '/cart', { waitUntil: 'networkidle' });
    result.add = result_add;
    result.cleanup = await cartApi('DELETE', '/api/cart/lines/' + VARIATION);
}

result.failed += result.violations.length;

if (process.env.AUDIT_EMAIL && process.env.AUDIT_PASSWORD) {
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', process.env.AUDIT_EMAIL);
    await page.fill('input[type=password]', process.env.AUDIT_PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
    const response = await page.goto(BASE + '/admin/security-settings', { waitUntil: 'networkidle' });
    const body = await page.textContent('body');

    result.admin = { status: response.status(), url: page.url(), hasField: /Усилена защита от ботове|Enhanced bot protection/.test(body), adminCsp: response.headers()['content-security-policy-report-only'] ?? null };
    result.admin.hasField || result.failed++;
    OUT && await page.screenshot({ path: join(OUT, 'p8d-admin-security.png'), fullPage: false });
}

await browser.close();
console.log(JSON.stringify(result, null, 2));
process.exit(result.failed === 0 ? 0 : 1);
