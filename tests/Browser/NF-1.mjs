// NF-1: the storefront 404 page («Дизайн» → «Страница 404») in a real browser. READ-ONLY here: the page's state (which «Съдържание отдолу», the texts) is set BEFORE a run, through the zone
// or `php artisan tinker` (NotFoundStore::publish), and the script only looks. A run takes the CONTENT KIND it is given and writes screenshots at desktop 1280 and phone 390.
//
//   KIND=<label for the file names> AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> OUT=<folder outside the project> node tests/Browser/NF-1.mjs
//   (optional) AUDIT_EMAIL + AUDIT_PASSWORD of an Administrator: also the admin zone panel («Дизайн» → «Страница 404») with the select on its current value
//
// For each width it records: the status (404), the same URL (no redirect), the h1, how many tiles / cards / block parts are in the content, a page-wide horizontal overflow check, and the CSP violations
// (expected 0); and it checks that the button spans the full width of the content on the phone.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const KIND = process.env.KIND ?? 'current';
const PATH = process.env.NF_PATH ?? '/abvgd';

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: KIND=... PLAYWRIGHT_DIR=... OUT=... node tests/Browser/NF-1.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'package.json'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });
const result = { base: BASE, kind: KIND, pages: [], admin: null, failed: 0 };

for (const [label, width, height] of [['desktop', 1280, 900], ['phone', 390, 844]]) {
    const context = await browser.newContext({ viewport: { width, height }, ignoreHTTPSErrors: true });
    const page = await context.newPage();

    await page.addInitScript(() => {
        window.__violations = [];
        document.addEventListener('securitypolicyviolation', (event) => window.__violations.push({ directive: event.effectiveDirective, blocked: event.blockedURI }));
    });

    const response = await page.goto(BASE + PATH, { waitUntil: 'networkidle' });
    const info = await page.evaluate(() => {
        const q = (selector) => document.querySelectorAll(selector).length;
        const button = document.querySelector('.sf-nf-button');
        const section = document.querySelector('.sf-nf');
        const box = (node) => (node ? node.getBoundingClientRect() : null);

        return {
            url: location.pathname,
            h1: document.querySelector('h1')?.textContent?.trim() ?? null,
            text: document.querySelector('.sf-nf-text')?.textContent?.trim() ?? null,
            search: q('.sf-nf-search'),
            tiles: q('.sf-nf .sf-hp-tile'),
            cards: q('.sf-nf .sf-card'),
            blockParts: q('.sf-nf .sf-hp-html-block, .sf-nf .sf-hp-html_block'),
            button: button ? { text: button.textContent.trim(), width: Math.round(box(button).width), sectionWidth: Math.round(box(section).width) } : null,
            overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth,
            violations: window.__violations,
        };
    });

    const entry = { width: label, status: response.status(), ...info };
    result.pages.push(entry);
    (response.status() !== 404 || info.url !== PATH || info.overflowX || info.violations.length > 0) && result.failed++;

    if (label === 'phone' && info.button !== null) {
        // «the button full width» on the phone: as wide as the content area of the section (a small tolerance for the section's own padding)
        info.button.width >= info.button.sectionWidth - 40 || result.failed++;
    }

    OUT && await page.screenshot({ path: join(OUT, `nf1-${KIND}-${label}.png`), fullPage: true });
    await context.close();
}

if (process.env.AUDIT_EMAIL && process.env.AUDIT_PASSWORD) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
    const page = await context.newPage();
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', process.env.AUDIT_EMAIL);
    await page.fill('input[type=password]', process.env.AUDIT_PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
    await page.goto(BASE + '/admin/design?zone=notfound', { waitUntil: 'networkidle' });
    result.admin = { url: page.url(), hasZone: /Страница 404|404 page/.test(await page.textContent('body')) };
    result.admin.hasZone || result.failed++;
    OUT && await page.screenshot({ path: join(OUT, `nf1-${KIND}-admin.png`), fullPage: false });
    await context.close();
}

await browser.close();
console.log(JSON.stringify(result, null, 2));
process.exit(result.failed === 0 ? 0 : 1);
