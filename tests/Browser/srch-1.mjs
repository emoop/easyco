// A REAL-BROWSER check of SRCH-1: the header's search icon, the phone drawer's search row and the results page.
//   The script publishes a header with the search element on the desktop AND the phone drawer's search row switched on, creates two throw-away products («SRCH1 Рокля» with a Latin slug, «SRCH1 Риза»), then:
//   desktop 1440: a click on the icon opens the field under the header (focused, the page does not move), typing «рокля» + Enter shows the results page with the count, Esc closes the field and gives the focus back to the icon, a
//   click outside closes it too; phone 390: the menu opens with the search field as its FIRST row, a search from it works; an empty search and a query with <script> answer the friendly message and print nothing raw.
// NOT part of `php artisan test`: run by hand against the dev site, whose environment must have STOREFRONT_SEARCH=true (the script checks and refuses otherwise). BEFORE the run the checksums of the catalog, pricing, stock and
// activity-log tables and the whole `site_settings` table are taken; the settings are snapshotted to a file OUTSIDE the project folder; at the end everything the script created is deleted, the settings are put back and the
// checksums are compared (a watchdog does the same after 15 minutes). The screenshots go to OUT, outside the project folder.
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/srch-1.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node srch-1.mjs'); process.exit(2); }
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) { console.error('refused: OUT is inside the project folder; the screenshots must be kept outside it'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

const artisan = (args) => {
    const r = spawnSync('php', ['artisan', ...args], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    if (r.status !== 0) { throw new Error(`artisan ${args.join(' ')} failed (${r.status}): ` + ((r.stdout ?? '') + (r.stderr ?? '')).slice(-600)); }
    return (r.stdout ?? '').trim() || (r.stderr ?? '').trim();
};
let snippetNo = 0;
const php = (code) => {
    const file = join(OUT, '_snippet' + (++snippetNo % 2) + '.php').replace(/\\/g, '/');
    writeFileSync(file, '<?php\n' + code);
    const out = artisan(['tinker', '--execute', `include '${file}';`]);
    if (out === '') { throw new Error('php produced no output'); }
    return out.split('\n').pop();
};

if (php(String.raw`echo config('storefront.search.enabled') ? 'on' : 'off';`) !== 'on') { console.error('refused: STOREFRONT_SEARCH is not true in the environment of the site (set it in .env, then run again)'); process.exit(2); }

const TABLES = ['catalog_products', 'catalog_variations', 'catalog_product_categories', 'catalog_product_tags', 'pricing_product_costs', 'pricing_price_list_items', 'stock_levels', 'activity_log'];
const SUMS = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Schema;
$sum = function ($t) { $q = DB::table($t); if (Schema::hasColumn($t, 'id')) { $q->orderBy('id'); } return md5(json_encode($q->get()->map(fn ($r) => (array) $r)->all())); };
$out = []; foreach (${JSON.stringify(TABLES).replace(/"/g, "'")} as $t) { $out[$t] = $sum($t); }
$out['settings'] = md5(json_encode(DB::table('site_settings')->orderBy('key')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode($out);`;
const MAX_LOG = String.raw`echo (int) Illuminate\Support\Facades\DB::table('activity_log')->max('id');`;

let maxLog = 0;
// activity_log: only this run's own rows (id above the start maximum) and only the entity types the run creates; never with maxLog unread or 0 (that would match the whole log)
const logCleanup = () => {
    if (! (maxLog > 0)) { console.error('WARNING: activity_log not cleaned up: maxLog is ' + maxLog + ' (not read yet, or the log was empty at start); no rows deleted'); return ''; }
    return `DB::table('activity_log')->where('id', '>', ${maxLog})->whereIn('entity_type', ['product', 'storefront_header'])->delete();`;
};
const CLEANUP = () => String.raw`
use Illuminate\Support\Facades\DB;
DB::statement('SET FOREIGN_KEY_CHECKS=0');
$pids = DB::table('catalog_products')->where('slug', 'like', 'srch1-audit-%')->orWhere('slug', 'roklya-srch1-audit')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('pricing_price_list_items')->where(fn ($q) => $q->where('target_type', 'product')->whereIn('target_id', $pids))->orWhere(fn ($q) => $q->where('target_type', 'variation')->whereIn('target_id', $vids))->delete();
DB::table('pricing_product_costs')->whereIn('priceable_id', $vids)->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
${logCleanup()}
DB::statement('SET FOREIGN_KEY_CHECKS=1');
echo 'cleaned';`;

// two simple products, active and visible, with a price and stock; the dress has a LATIN slug (found by the Cyrillic word through the transliteration)
const SETUP = String.raw`
use EasyCo\Catalog\Contracts\ProductRepository; use EasyCo\Catalog\Product; use Illuminate\Support\Facades\DB;
$class = (string) DB::table('shipping_classes')->where('is_default', 1)->value('code');
$a = Product::createSimple('SRCH1 Рокля', 'SRCH1AUDIT-A', 'roklya-srch1-audit'); app(ProductRepository::class)->save($a);
$b = Product::createSimple('SRCH1 Риза', 'SRCH1AUDIT-B', 'srch1-audit-riza'); app(ProductRepository::class)->save($b);
$ids = [$a->id(), $b->id()];
$group = DB::table('catalog_product_groups')->orderBy('id')->value('id');
DB::table('catalog_products')->whereIn('id', $ids)->update(['status' => 'active', 'catalog_visibility' => 'visible', 'product_group_id' => $group]);
DB::table('catalog_variations')->whereIn('product_id', $ids)->update(['shipping_class' => $class, 'status' => 'active', 'is_visible' => 1]);
$pricing = app(App\Services\ProductPricingAndStock::class);
foreach (DB::table('catalog_variations')->whereIn('product_id', $ids)->pluck('id') as $vid) { $pricing->writeRegularPrice((string) $vid, '25.00'); DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 5, 'created_at' => now(), 'updated_at' => now()]); }
app(App\Services\PriceSort\ProductSortPriceRefresher::class)->refresh($ids);
echo json_encode(['dress' => '/product/roklya-srch1-audit']);`;

// the header the way the Design screen publishes it: the default layout (the search icon is in the main row on both devices) plus the phone drawer's search row switched on
const PUBLISH_HEADER = String.raw`
$layout = App\Storefront\Header\HeaderLayout::default(); $layout['mobile']['drawer_search'] = true;
app(App\Storefront\Header\HeaderCompiler::class)->publish($layout, app(App\Settings\Contracts\SiteSettingsRepository::class));
Illuminate\Support\Facades\Cache::forget(App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY);
echo 'published';`;

const SNAP_FILE = join(OUT, 'site_settings.snapshot.json').replace(/\\/g, '/');
const SNAPSHOT = String.raw`use Illuminate\Support\Facades\DB; file_put_contents('${SNAP_FILE}', json_encode(DB::table('site_settings')->get()->map(fn ($r) => (array) $r)->all())); echo 'saved';`;
const RESTORE = () => String.raw`use Illuminate\Support\Facades\DB; $rows = json_decode(file_get_contents('${SNAP_FILE}'), true); DB::table('site_settings')->delete(); foreach (array_chunk($rows, 50) as $chunk) { DB::table('site_settings')->insert($chunk); } Illuminate\Support\Facades\Cache::forget(App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY); echo 'restored';`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = []; const consoleErrors = []; const shots = []; const report = {};
const check = (name, ok, detail = '') => { process.env.TRACE && console.error((ok ? '· ' : 'FAIL ') + name.slice(0, 90)); steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + String(detail).slice(0, 300)); };

let before = null; let snapshot = false; let watchdog = null;
const putBack = () => { try { php(CLEANUP()); } catch (e) { console.error('cleanup failed ' + e); } if (snapshot) { php(RESTORE()); } };

try {
    before = JSON.parse(php(SUMS));
    maxLog = Number(php(MAX_LOG));
    snapshot = php(SNAPSHOT) === 'saved';
    watchdog = setTimeout(() => { putBack(); console.error('WATCHDOG: the run took more than 15 minutes; the data was put back'); process.exit(3); }, 15 * 60 * 1000);
    php(CLEANUP()); // a leftover of an earlier, interrupted run
    report.world = JSON.parse(php(SETUP));
    php(PUBLISH_HEADER);

    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const shot = async (page, name, full = false) => { const p = join(OUT, name + '.png'); shots.push(p); await page.screenshot({ path: p, fullPage: full }); };
    const watch = (page, tag) => {
        page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR@${tag} ` + String(e).slice(0, 200)));
        page.on('console', (m) => { if (m.type() === 'error' && ! /403|404|Failed to load resource/.test(m.text())) consoleErrors.push(`CONSOLE@${tag} ` + m.text().slice(0, 200)); });
        page.on('response', (r) => { if (r.status() >= 500) { consoleErrors.push(`HTTP@${tag} ` + r.status() + ' ' + r.url().slice(0, 120)); } });
    };
    const open = async (page, path) => { await page.goto(BASE + path, { waitUntil: 'networkidle' }); await sleep(500); };
    const rowHidden = (page) => page.evaluate(() => document.querySelector('[data-sf-search-row]').hasAttribute('hidden'));
    const focusedId = (page) => page.evaluate(() => document.activeElement && (document.activeElement.id || document.activeElement.getAttribute('data-sf-search-toggle') !== null && 'toggle' || document.activeElement.tagName));

    // ---- desktop 1440
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
    ctx.setDefaultTimeout(30000);
    const page = await ctx.newPage(); watch(page, 'desktop');
    await open(page, '/');
    const icon = page.locator('.sf-hdr-desktop [data-sf-search-toggle]');
    check('desktop: the header has the search icon with its accessible name and aria-expanded=false', (await icon.count()) === 1 && (await icon.getAttribute('aria-expanded')) === 'false' && /Търсене/.test(await icon.innerText()));
    check('desktop: the field is not shown before the click', await rowHidden(page));
    await shot(page, 'srch1-1-desktop-icon-closed-1440');
    const contentTop = () => page.evaluate(() => { const m = document.querySelector('main') || document.querySelector('.sf-main') || document.querySelector('section'); return Math.round(m.getBoundingClientRect().top + window.scrollY); });
    const topBefore = await contentTop();
    await icon.click(); await sleep(300);
    check('desktop: a click on the icon shows the field, focused, aria-expanded=true', ! (await rowHidden(page)) && (await focusedId(page)) === 'sf-search-row-input' && (await icon.getAttribute('aria-expanded')) === 'true', await focusedId(page));
    check('desktop: opening the field does not move the page content', (await contentTop()) === topBefore, `${topBefore} -> ${await contentTop()}`);
    await shot(page, 'srch1-2-desktop-field-open-1440');
    await page.keyboard.type('рокля'); await page.keyboard.press('Enter');
    await page.waitForURL(/\/search\?q=/); await sleep(600);
    const h1 = (await page.locator('h1').first().innerText()).trim();
    check('desktop: Enter opens the results page «Резултати за „рокля“»', h1 === 'Резултати за „рокля“', h1);
    const count = (await page.locator('.sf-count').first().innerText()).replace(/\s+/g, ' ');
    check('desktop: the count is on the page and the dress (found through its Latin slug) is in the grid', /Брой продукти: [1-9]/.test(count) && (await page.locator('.sf-grid a[href="/product/roklya-srch1-audit"]').count()) >= 1, count);
    check('desktop: the results page is noindex', (await page.locator('meta[name=robots]').getAttribute('content')) === 'noindex, follow');
    await shot(page, 'srch1-3-results-1440');
    await open(page, '/');
    await icon.click(); await sleep(200);
    await page.keyboard.press('Escape'); await sleep(200);
    check('desktop: Esc closes the field and the focus returns to the icon', (await rowHidden(page)) && (await focusedId(page)) === 'toggle' && (await icon.getAttribute('aria-expanded')) === 'false', await focusedId(page));
    await icon.click(); await sleep(200);
    await page.mouse.click(40, 700); await sleep(200);
    check('desktop: a click outside closes it too', await rowHidden(page));

    // ---- an empty result, an empty and a short query, a hostile query
    await open(page, '/search?q=zzzqqqxx');
    const none = (await page.locator('.sf-search-message').innerText()).trim();
    check('an unknown word: the friendly message and the field again, no error', /Няма резултати за „zzzqqqxx“\. Проверете изписването или опитайте с по-обща дума\./.test(none) && (await page.locator('form[role=search] input[name=q]').count()) >= 1, none);
    await shot(page, 'srch1-5-empty-result-1440');
    await open(page, '/search?q=');
    check('an empty query: the hint', /Въведете дума за търсене/.test(await page.locator('.sf-search-message').innerText()));
    await page.goto(BASE + '/search?q=' + encodeURIComponent('<script>window.__x=1</script>'), { waitUntil: 'networkidle' });
    check('a query with <script>: nothing runs and it is printed escaped', (await page.evaluate(() => window.__x)) === undefined && (await page.locator('h1').first().innerText()).includes('<script>window.__x=1</script>'));
    await ctx.close();

    // ---- phone 390: the search is the first row of the menu
    const phoneCtx = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true, locale: 'bg-BG', isMobile: true, hasTouch: true });
    phoneCtx.setDefaultTimeout(30000);
    const phone = await phoneCtx.newPage(); watch(phone, 'phone');
    await open(phone, '/');
    await phone.locator('.sf-hdr-mobile [data-sf-menu-toggle]').click(); await sleep(500);
    const geo = await phone.evaluate(() => {
        const field = document.querySelector('.sf-drawer-search-input').getBoundingClientRect();
        const menu = document.querySelector('.sf-drawer-body').getBoundingClientRect();
        const panel = document.querySelector('.sf-drawer-panel').getBoundingClientRect();
        return { fieldTop: field.top, fieldBottom: field.bottom, menuTop: menu.top, panelTop: panel.top, fieldWidth: field.width, panelWidth: panel.width, scroll: document.documentElement.scrollWidth, inner: window.innerWidth };
    });
    report.geo390 = geo;
    check('390 px: the search field is the first row of the drawer, above the menu, and wide', geo.fieldBottom <= geo.menuTop + 1 && geo.fieldTop - geo.panelTop < 40 && geo.fieldWidth > geo.panelWidth * 0.6, JSON.stringify(geo));
    check('390 px: the page does not scroll sideways', geo.scroll <= geo.inner + 1, JSON.stringify(geo));
    await shot(phone, 'srch1-4-drawer-with-search-390');
    await phone.locator('.sf-drawer-search-input').fill('рокля'); await phone.keyboard.press('Enter');
    await phone.waitForURL(/\/search\?q=/); await sleep(600);
    check('390 px: a search from the drawer opens the results page with the dress', (await phone.locator('.sf-grid a[href="/product/roklya-srch1-audit"]').count()) >= 1);
    await shot(phone, 'srch1-6-results-390');
    await phoneCtx.close();

    check('no console errors and no 5xx', consoleErrors.length === 0, consoleErrors.join(' | '));
} catch (e) {
    check('the script ran to the end', false, String(e));
} finally {
    clearTimeout(watchdog);
    putBack();
    const after = JSON.parse(php(SUMS));
    for (const key of Object.keys(before ?? {})) { check(`checksum restored: ${key}`, before[key] === after[key], `${before[key]} -> ${after[key]}`); }
    await browser.close();
}

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ steps: steps.length, failed: failed.length, report, shots }, null, 1));
failed.length && console.log(failed.map((s) => `FAIL ${s.name} ${s.detail}`).join('\n'));
process.exit(failed.length === 0 ? 0 : 1);
