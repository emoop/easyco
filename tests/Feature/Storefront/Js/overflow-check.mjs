// A REAL-BROWSER check for sideways scroll on the storefront (stage S3-V2). The unit suite has no browser, so this script is run by hand (it is not
// part of `php artisan test`; the static guard against `overflow-x: hidden` on html/body IS):
//
//   1. dump the rendered pages (the owner's helper: any directory holding home.html, category.html, product.html, cart.html, checkout.html,
//      page.html and received.html, the bodies of GET /, /product-category/..., /product/..., /cart, /checkout, /info/..., the order-received page);
//   2. `npm run build` (the stylesheet and the script are served from public/build);
//   3. PLAYWRIGHT_DIR=<folder that contains node_modules/playwright> node tests/Feature/Storefront/Js/overflow-check.mjs <snapshot dir> [width ...]
//      (for example `npx playwright install chromium` once; PLAYWRIGHT_DIR may be the npx cache folder; CHROMIUM=<path> picks another browser).
//
// For every page and scenario at every width (default 320 and 360) it prints the ELEMENTS whose right edge is past the viewport (tag, classes, the right
// edge and the width) and the document's scrollWidth against its clientWidth. Exit code 1 when anything overflows. No dependency is added to the project:
// the browser library is the caller's.
import { createRequire } from 'node:module';
import { readFileSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '../../../..');
const snapDir = process.argv[2];
const widths = process.argv.slice(3).map(Number).filter(Boolean);

if (! snapDir || ! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=<dir with node_modules/playwright> node overflow-check.mjs <snapshot dir> [width ...]');
    process.exit(2);
}

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const manifest = JSON.parse(readFileSync(join(root, 'public/build/manifest.json'), 'utf8'));
const cssFile = manifest['resources/css/storefront.css'].file;
const jsFile = manifest['resources/js/storefront.js'].file;

const money = (minor) => ({ minor, currency: 'EUR' });
const line = (id, quantity, extra = {}) => ({
    variation_id: id, sku: `SKU-${id}`, product_name: `Summer dress with a long name ${id}`, product_url: `/product/dress-${id}`, quantity, attributes: [{ value: 'Red' }, { value: 'Medium' }],
    unit_price: money(1250), line_total: money(1250 * quantity), price_available: true, image: null, ...extra,
});
const cartOf = (lines, promo = false) => {
    const subtotal = lines.reduce((sum, l) => sum + l.line_total.minor, 0);

    return {
        cart_id: 11, lines, subtotal: money(subtotal), total: money(subtotal - (promo ? 500 : 0)),
        promotion: promo ? { code: 'SAVE5', valid: true, discount_amount: money(500) } : null,
        free_shipping_hint: { text: 'Add 5.00 € more for free delivery', destinations: ['office'] },
    };
};
const long = (id) => line(id, 2, { product_name: 'Extraordinarilylongproductnamewithoutanyspacesatalltotestwrapping'.repeat(2), attributes: [{ value: 'Supercalifragilisticexpialidocious-colour' }, { value: 'XXL' }] });
const four = [line(1, 2), line(2, 1), line(3, 5), long(4)];

// [name, snapshot, url, cart, what to do before measuring]
const cases = [
    ['home', 'home', '/', null, null],
    ['category', 'category', '/product-category/clothing', null, null],
    ['product', 'product', '/product/dress-21', null, null],
    ['content page', 'page', '/info/long', null, null],
    ['order received', 'received', '/order/received/1', null, null],
    ['cart, 1 line', 'cart', '/cart', cartOf([line(1, 1)]), null],
    ['cart, 4 lines + promo', 'cart', '/cart', cartOf(four, true), null],
    ['cart, 4 lines, no promo', 'cart', '/cart', cartOf(four), null],
    ['cart, empty', 'cart', '/cart', cartOf([]), null],
    ['checkout, summary closed', 'checkout', '/checkout', cartOf([line(1, 1)]), null],
    ['checkout, summary open, 1 item', 'checkout', '/checkout', cartOf([line(1, 1)]), 'open'],
    ['checkout, summary open, 4 items + promo', 'checkout', '/checkout', cartOf(four, true), 'open'],
];

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });
let failures = 0;

for (const width of widths.length ? widths : [320, 360]) {
    for (const [name, snapshot, url, cart, action] of cases) {
        const context = await browser.newContext({ viewport: { width, height: 640 }, deviceScaleFactor: 2 });
        await context.addCookies([{ name: 'easyco_has_cart', value: '1', domain: 'shop.test', path: '/' }, { name: 'XSRF-TOKEN', value: 'tok', domain: 'shop.test', path: '/' }]);
        await context.route('**/*', async (route) => {
            const path = new URL(route.request().url()).pathname;

            if (path.startsWith('/build/')) {
                const file = join(root, 'public', path);

                return existsSync(file) ? route.fulfill({ path: file, contentType: path.endsWith('.css') ? 'text/css' : path.endsWith('.js') ? 'text/javascript' : undefined }) : route.fulfill({ status: 404 });
            }

            if (path === '/api/cart') {
                return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(cart ?? cartOf([])) });
            }

            if (path.startsWith('/api/') || path.startsWith('/sanctum/')) {
                return route.fulfill({ status: 204 });
            }

            if (path === url || path.startsWith('/order/received')) {
                const html = readFileSync(join(snapDir, `${snapshot}.html`), 'utf8')
                    .replace('</head>', `<link rel="stylesheet" href="/build/${cssFile}"><script type="module" src="/build/${jsFile}"></script></head>`);

                return route.fulfill({ status: 200, contentType: 'text/html', body: html });
            }

            return route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>x</title>' });
        });

        const page = await context.newPage();
        await page.goto(`http://shop.test${url}`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(400);

        // (from 1024px the bar is hidden and the summary is already open as the right column)
        if (action === 'open' && await page.locator('[data-sf-summary-toggle]').isVisible()) {
            await page.click('[data-sf-summary-toggle]');
            await page.waitForTimeout(300);
        }

        const result = await page.evaluate(() => {
            const limit = document.documentElement.clientWidth;
            const bad = [];

            for (const node of document.querySelectorAll('body *')) {
                const r = node.getBoundingClientRect();

                if (r.width > 0 && r.right > limit + 0.5) {
                    bad.push({ tag: node.tagName.toLowerCase(), cls: String(node.className && node.className.baseVal !== undefined ? node.className.baseVal : node.className).slice(0, 60), right: Math.round(r.right * 10) / 10, width: Math.round(r.width * 10) / 10 });
                }
            }

            return { limit, scrollWidth: document.documentElement.scrollWidth, bodyScroll: document.body.scrollWidth, bad };
        });

        const overflows = result.scrollWidth > result.limit || result.bad.length > 0;
        failures += overflows ? 1 : 0;
        console.log(`${overflows ? 'OVERFLOW' : 'ok      '} ${width}px  ${name}  (scrollWidth ${result.scrollWidth} / client ${result.limit})`);

        for (const b of result.bad.slice(0, 12)) {
            console.log(`            ${b.tag}.${b.cls}  right=${b.right}  width=${b.width}`);
        }

        await context.close();
    }
}

await browser.close();
process.exit(failures > 0 ? 1 : 0);
