// P8b: what the page cache will see. READ-ONLY (GET requests only; nothing is written to the dev site).
//
// For home, a category, a product, a brand, an info page, /robots.txt, a filtered listing and a 404 it records the status, `cache-control`, `xkey` and `set-cookie`
// (plain HTTP requests, so no browser state can add a cookie), and it takes screenshots of home, a category and a product in a real browser (the HTML did not change in
// this task: the screenshots are for a human to compare). Exit code 1 when a check failed.
//
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> OUT=<folder outside the project> node tests/Browser/P8b.mjs
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... OUT=... node tests/Browser/P8b.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const CACHEABLE = 'max-age=0, public, s-maxage=604800';
// [label, path, expectation]: `cache` = the page cache may keep it; `pass` = it must not
const PAGES = [
    ['home', '/', 'cache'],
    ['category', '/product-category/clothing', 'cache'],
    ['product', '/product/pantaloni-rips', 'cache'],
    ['brand', '/brand/nike', 'cache'],
    ['info page', '/info/obshti-usloviya', 'cache'],
    ['robots.txt', '/robots.txt', 'cache'],
    ['sitemap.xml', '/sitemap.xml', 'cache'],
    ['filtered listing', '/product-category/clothing?filter[brand]=2', 'pass'],
    ['search', '/search?q=test', 'pass'],
    ['404', '/p8b-missing-page', 'pass'],
];

const rows = [];
let failed = 0;

for (const [label, path, expect] of PAGES) {
    const response = await fetch(BASE + path, { redirect: 'manual' });
    const cacheControl = response.headers.get('cache-control') ?? '';
    const xkey = response.headers.get('xkey') ?? '';
    const cookies = response.headers.getSetCookie().map((c) => c.split('=')[0]);
    const ok = expect === 'cache'
        ? response.status === 200 && cacheControl === CACHEABLE && xkey.split(' ').includes('all') && cookies.length === 0
        : xkey === '' && ! cacheControl.includes('s-maxage=604800') && cookies.length === 0;

    ok || failed++;
    rows.push({ label, path, status: response.status, 'cache-control': cacheControl, xkey, 'set-cookie': cookies.join(',') || '-', ok });
}

if (OUT) {
    const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'package.json'))('playwright');
    const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });

    for (const [name, path] of [['home', '/'], ['category', '/product-category/clothing'], ['product', '/product/pantaloni-rips']]) {
        await page.goto(BASE + path, { waitUntil: 'networkidle' });
        await page.screenshot({ path: join(OUT, `p8b-${name}.png`), fullPage: false });
    }

    await browser.close();
}

console.log(JSON.stringify({ base: BASE, failed, rows }, null, 2));
process.exit(failed === 0 ? 0 : 1);
