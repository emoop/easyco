// A REAL-BROWSER run of DM-1: the storefront's dark-mode foundation. NOT part of `php artisan test`: run by hand against the DEV site.
//
// !! In DARK mode WHITE CARDS WITH WHITE TEXT ARE EXPECTED until DM-2 (no selector is migrated in DM-1: cards, dropdowns, the cart, the checkout still paint `bg-white` / `#fff`). What this run checks is
// !! the FOUNDATION: `data-theme="dark"` on <html>, the page background black, the text white, the dark button colour, `color-scheme: dark`, and that LIGHT mode is unchanged. Look at the screenshots
// !! knowing that. The switch is behind the dev flag: the site (and this script's PHP) must run with STOREFRONT_DARK_MODE=true.
//
// It stores the mode through the real AppearanceWriter (the same code «Облик» saves with), takes the pages in mode OFF then ON, and puts the stored mode and button colour BACK at the end (also on failure).
//
//   BASE_URL=https://dev.example PLAYWRIGHT_DIR=<dir> OUT=<folder> [PRODUCT_PATH=/product/some-slug] [CHROMIUM=<path>] [PHP=php] node tests/Browser/dm-1.mjs
// Pages: the home page, a product (PRODUCT_PATH; default: the first product link found on the home page) and the cart. Prints a JSON report; exit code 1 when a check failed.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, rmSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = (process.env.BASE_URL ?? '').replace(/\/$/, '');
const OUT = process.env.OUT;
const PHP = process.env.PHP ?? 'php';
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

if (! BASE || ! OUT || ! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: BASE_URL=... OUT=<folder> PLAYWRIGHT_DIR=... node tests/Browser/dm-1.mjs');
    process.exit(2);
}

mkdirSync(OUT, { recursive: true });

const steps = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 300) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };

// ---- the stored mode, through the application's own writer -------------------------------------------------------------------------------------
const work = mkdtempSync(join(tmpdir(), 'dm1-'));
const script = join(work, 'mode.php');
writeFileSync(script, `<?php
$root = ${JSON.stringify(ROOT.replaceAll('\\', '/'))};
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$action = $argv[1] ?? 'show';
$current = App\\Storefront\\Appearance\\AppearanceWriter::current();
if ($action === 'dark') { App\\Storefront\\Appearance\\AppearanceWriter::save(array_replace($current, ['dark_mode' => true])); }
elseif ($action === 'light') { App\\Storefront\\Appearance\\AppearanceWriter::save(array_replace($current, ['dark_mode' => false])); }
elseif ($action === 'restore') { App\\Storefront\\Appearance\\AppearanceWriter::save(array_replace($current, ['dark_mode' => $argv[2] === '1', 'dark_button' => $argv[3]])); }
$after = App\\Storefront\\Appearance\\AppearanceWriter::current();
echo json_encode(['flag' => (bool) config('storefront.dark_mode'), 'dark_mode' => $after['dark_mode'], 'dark_button' => $after['dark_button']]);
`);

const php = (...args) => JSON.parse(execFileSync(PHP, [script, ...args], { cwd: ROOT, encoding: 'utf8' }).trim().split('\n').pop());

const original = php('show');
check('the dev flag STOREFRONT_DARK_MODE is on (in the CLI environment)', original.flag === true, JSON.stringify(original));

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });
const shots = [];

const probe = () => ({
    theme: document.documentElement.getAttribute('data-theme'),
    scheme: getComputedStyle(document.documentElement).colorScheme,
    bg: getComputedStyle(document.body).backgroundColor,
    text: getComputedStyle(document.body).color,
    primary: getComputedStyle(document.documentElement).getPropertyValue('--sf-primary').trim(),
    surface: getComputedStyle(document.documentElement).getPropertyValue('--sf-surface').trim(),
});

try {
    let productPath = process.env.PRODUCT_PATH;

    for (const mode of ['light', 'dark']) {
        php(mode);
        const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, ignoreHTTPSErrors: true });
        const page = await ctx.newPage();

        for (const [name, path] of [['home', '/'], ['product', productPath ?? null], ['cart', '/cart']]) {
            if (name === 'product' && path === null) {
                await page.goto(BASE + '/', { waitUntil: 'networkidle' });
                productPath = await page.evaluate(() => document.querySelector('a[href*="/product/"]')?.getAttribute('href') ?? null);
                if (productPath === null) { check('a product link was found on the home page', false); continue; }
            }

            const url = BASE + (name === 'product' ? productPath : path);
            const response = await page.goto(url, { waitUntil: 'networkidle' });
            check(`${mode} ${name}: 200`, response?.status() === 200, response?.status());

            const state = await page.evaluate(probe);
            const html = await page.evaluate(() => document.documentElement.outerHTML.slice(0, 200));

            if (mode === 'light') {
                check(`light ${name}: no data-theme (the tag is what it always was)`, state.theme === null, html);
                check(`light ${name}: the page is not black`, state.bg !== 'rgb(0, 0, 0)', state.bg);
            } else {
                check(`dark ${name}: data-theme="dark"`, state.theme === 'dark', html);
                check(`dark ${name}: color-scheme dark`, state.scheme.includes('dark'), state.scheme);
                check(`dark ${name}: the page is black and the text white`, state.bg === 'rgb(0, 0, 0)' && state.text === 'rgb(255, 255, 255)', `${state.bg} / ${state.text}`);
                check(`dark ${name}: the button colour variable is set`, /^#[0-9a-f]{6}$/i.test(state.primary), state.primary);
            }

            const path2 = join(OUT, `${name}-${mode}.png`);
            await page.screenshot({ path: path2, fullPage: true });
            shots.push(path2);
        }

        await ctx.close();
    }
} finally {
    await browser.close();
    // put the stored values back exactly as they were
    try { php('restore', original.dark_mode ? '1' : '0', original.dark_button); } catch (e) { check('the original mode was restored', false, e.message); }
    rmSync(work, { recursive: true, force: true });
}

const failed = steps.filter((s) => ! s.ok).length;
console.log(JSON.stringify({ ok: failed === 0, failed, original, shots, steps }, null, 2));
process.exit(failed === 0 ? 0 : 1);
