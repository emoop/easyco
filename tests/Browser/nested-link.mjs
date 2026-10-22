// A REAL-BROWSER check of HDR-3 item 7: a link on the inner item of a bullet list inside a bullet list, in the rich-text editor of «HTML блокове». Before the fix Livewire refused the
// update with MaxNestingDepthExceededException (the path is deeper than 10 levels). It is NOT part of `php artisan test`: run by hand on dev (back up html_blocks first, delete the block after).
//
//   AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] [OUT=<folder>] node tests/Browser/nested-link.mjs
//
// Types "Outer", a nested "Inner" (Tab), puts a link on "Inner", saves, reloads and checks the link is still on the inner item. Exit code 1 when a check failed.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const EMAIL = process.env.AUDIT_EMAIL ?? 'ux-audit@example.test';
const PASSWORD = process.env.AUDIT_PASSWORD;
const OUT = process.env.OUT ?? '';

if (! PASSWORD || ! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> node nested-link.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });
const page = await (await browser.newContext({ viewport: { width: 1500, height: 1600 }, ignoreHTTPSErrors: true })).newPage();

const steps = [];
const problems = [];
page.on('console', (m) => { if (m.type() === 'error') problems.push(m.text().slice(0, 300)); });
page.on('pageerror', (e) => problems.push('PAGEERROR ' + String(e).slice(0, 300)));
page.on('response', (r) => { if (r.status() >= 500) problems.push(r.status() + ' ' + r.url().slice(0, 120)); });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const check = (name, ok, detail = '') => { steps.push({ name, ok: !!ok, detail: String(detail).slice(0, 400) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };

try {
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    await page.goto(BASE + '/admin/html-blocks/create', { waitUntil: 'networkidle' });
    await page.fill('input[id$="title"]', 'HDR-3 nested link');
    const editor = page.locator('.tiptap').first();
    await editor.click();
    await page.locator('button[aria-label="Списък с точки"]').first().click();
    await page.keyboard.type('Outer');
    await page.keyboard.press('Enter');
    await page.keyboard.press('Tab');
    await page.keyboard.type('Inner');
    await sleep(800);
    check('the inner item is a list inside a list item', (await editor.locator('ul li ul li').count()) === 1, await editor.innerHTML());

    await page.keyboard.press('Shift+Home');
    await page.locator('button[aria-label="Връзка"]').first().click();
    await sleep(600);
    await page.locator('input[type=text]:visible, input[type=url]:visible').last().fill('https://example.com/nested');
    await page.locator('.fi-modal-footer button:visible, .fi-modal-window button[type=submit]:visible').first().click();
    await sleep(1500);
    check('the link is on the inner item in the editor', (await editor.locator('ul li ul li a[href="https://example.com/nested"]').count()) === 1, await editor.innerHTML());
    check('no refused Livewire update (no 500, no console error) while editing', problems.length === 0, problems.join(' | '));

    await page.getByRole('button', { name: /^Създай$/ }).first().click();
    await page.waitForURL(/\/html-blocks\/\d+\/edit/, { timeout: 30000 });
    await sleep(1500);
    await page.reload({ waitUntil: 'networkidle' });
    const reloaded = page.locator('.tiptap').first();
    await reloaded.waitFor();
    check('after saving and reloading the link is still on the inner item', (await reloaded.locator('ul li ul li a[href="https://example.com/nested"]').count()) === 1, await reloaded.innerHTML());
    OUT && await page.screenshot({ path: join(OUT, 'nested-link-reloaded.png') });
} catch (error) {
    check('the run finished', false, String(error.stack || error).slice(0, 500));
    OUT && await page.screenshot({ path: join(OUT, 'nested-link-failure.png') }).catch(() => {});
}

check('no server error and no console error in the whole run', problems.length === 0, problems.join(' | '));
console.log(JSON.stringify({ passed: steps.filter((s) => s.ok).length, failed: steps.filter((s) => ! s.ok), total: steps.length }, null, 2));
await browser.close();
process.exit(steps.every((s) => s.ok) ? 0 : 1);
