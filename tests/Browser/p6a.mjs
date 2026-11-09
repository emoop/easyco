// A REAL-BROWSER check of P6a: the «Цена» sort of the category listing, ordered by the stored `price_from_minor`.
//   At 1440 and 390 px, on a throw-away category: the listing offers EXACTLY the three sorts (the dropdown at 1440, the phone drawer's radios at 390); price ascending and descending show the prices ON THE CARDS in the right order,
//   a product with a SALE price is ordered by its sale price, a product with no price is last in both directions, the sort by name is a 404; then the cheapest size of a variable product is made sold out (through the app's own
//   stock repository, which queues the refresh; the queue is run) and the product moves; no console errors, no horizontal scroll at 390.
// NOT part of `php artisan test`: run by hand against the dev site. It creates ONE category, five products («P6a audit …») with their variations, prices (the regular and the manual-sale list) and stock. BEFORE the run every
// checksum is taken; at the end everything created is deleted and the checksums are compared. A watchdog puts the data back after 12 minutes.
// The screenshots go to OUT, which must be OUTSIDE the project folder (the script refuses otherwise).
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p6a.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p6a.mjs'); process.exit(2); }
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) { console.error('refused: OUT is inside the project folder; the screenshots must be kept outside it'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

const artisan = (args) => {
    const r = spawnSync('php', ['artisan', ...args], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    if (r.status !== 0) { throw new Error(`artisan ${args.join(' ')} failed (${r.status}): ` + ((r.stdout ?? '') + (r.stderr ?? '')).slice(-600)); }
    return (r.stdout ?? '').trim();
};
const php = (code) => {
    const out = artisan(['tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')]);
    if (out === '') { throw new Error('php produced no output'); }
    return out.split('\n').pop();
};

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode(['products' => $sum('catalog_products'), 'variations' => $sum('catalog_variations'), 'stock' => $sum('stock_levels'), 'prices' => $sum('pricing_price_list_items'), 'categories' => $sum('catalog_categories'), 'pivots' => $sum('catalog_product_categories'), 'attrs' => DB::table('catalog_attribute_definitions')->count(), 'jobs' => DB::table('jobs')->count()]);`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$pids = DB::table('catalog_products')->where('slug', 'like', 'p6a-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('pricing_price_list_items')->where('target_type', 'variation')->whereIn('target_id', $vids->map(fn ($v) => (string) $v))->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
DB::table('catalog_categories')->where('slug', 'p6a-audit-cat')->delete();
$defs = DB::table('catalog_attribute_definitions')->where('code', 'p6a-size')->pluck('id');
DB::table('catalog_attribute_values')->whereIn('attribute_definition_id', $defs)->delete();
DB::table('catalog_attribute_definitions')->whereIn('id', $defs)->delete();
DB::table('jobs')->where('payload', 'like', '%RefreshProductSortPrices%')->delete();
echo 'cleaned';`;

// five products: A simple 30.00; B simple 10.00; C simple 20.00 with a SALE price 15.00; D variable (S 5.00 stock 2, M 25.00 stock 3); E simple with no price
const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$cat = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'P6a audit category', 'slug' => 'p6a-audit-cat', 'created_at' => $now, 'updated_at' => $now]);
$def = DB::table('catalog_attribute_definitions')->insertGetId(['code' => 'p6a-size', 'name' => 'P6a size', 'type' => 'select', 'is_filterable' => 0, 'filter_display' => 'list', 'created_at' => $now, 'updated_at' => $now]);
$vals = ['S' => DB::table('catalog_attribute_values')->insertGetId(['attribute_definition_id' => $def, 'value' => 'S', 'sort_order' => 0, 'colour' => null, 'created_at' => $now, 'updated_at' => $now]), 'M' => DB::table('catalog_attribute_values')->insertGetId(['attribute_definition_id' => $def, 'value' => 'M', 'sort_order' => 1, 'colour' => null, 'created_at' => $now, 'updated_at' => $now])];
$regular = DB::table('pricing_price_lists')->where('name', 'Regular Prices')->value('id');
$sale = DB::table('pricing_price_lists')->where('name', 'Manual Sale')->value('id');
$price = function ($list, $vid, $minor) use ($now) { DB::table('pricing_price_list_items')->insert(['price_list_id' => $list, 'target_type' => 'variation', 'target_id' => (string) $vid, 'min_quantity' => 1, 'price_amount_minor' => $minor, 'price_currency' => 'EUR', 'price_tax_rate_basis_points' => 0, 'price_tax_inclusive' => 0, 'created_at' => $now, 'updated_at' => $now]); };
$ids = [];
$make = function ($n, $name, $type, $variations) use ($now, $cat, $def, $vals, $regular, $sale, $price, &$ids) {
  $pid = DB::table('catalog_products')->insertGetId(['type' => $type, 'name' => 'P6a audit '.$name, 'slug' => 'p6a-audit-'.$n, 'base_sku' => 'P6AUDIT-'.$n, 'status' => 'active', 'catalog_visibility' => 'visible', 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => now()->subMinutes(ord($n))]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  foreach ($variations as $i => [$size, $regularMinor, $saleMinor, $stock]) {
    $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => $i, 'type' => $type === 'simple' ? 'universal' : 'standard', 'status' => 'active', 'attribute_signature' => hash('sha256', 'p6a-'.$n.'-'.$i), 'sku' => 'P6AUDIT-'.$n.'-'.$i, 'is_visible' => 1, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
    if ($size !== null) { DB::table('catalog_variation_attribute_values')->insert(['variation_id' => $vid, 'attribute_definition_id' => $def, 'attribute_value_id' => $vals[$size], 'created_at' => $now, 'updated_at' => $now]); }
    if ($stock > 0) { DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => $stock, 'created_at' => $now, 'updated_at' => $now]); }
    if ($regularMinor !== null) { $price($regular, $vid, $regularMinor); }
    if ($saleMinor !== null) { $price($sale, $vid, $saleMinor); }
    $ids[$n][] = $vid;
  }
};
$make('a', 'A', 'simple', [[null, 3000, null, 5]]);
$make('b', 'B', 'simple', [[null, 1000, null, 5]]);
$make('c', 'C', 'simple', [[null, 2000, 1500, 5]]);
$make('d', 'D', 'variable', [['S', 500, null, 2], ['M', 2500, null, 3]]);
$make('e', 'E', 'simple', [[null, null, null, 5]]);
echo json_encode($ids);`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });

const steps = []; const consoleErrors = []; const shots = []; const report = {};
const check = (name, ok, detail = '') => { process.env.TRACE && console.error((ok ? '· ' : 'FAIL ') + name.slice(0, 90)); steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + String(detail).slice(0, 300)); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const URL = BASE + '/product-category/p6a-audit-cat';

let before = null; let created = false; let watchdog = null;

try {
    before = JSON.parse(php(SUMS));
    created = true;
    watchdog = setTimeout(() => { try { php(CLEANUP); } catch (e) { console.error('WATCHDOG cleanup failed ' + e); } console.error('WATCHDOG: the run took more than 12 minutes; the data was put back'); process.exit(3); }, 12 * 60 * 1000);
    const variationIds = JSON.parse(php(SETUP));
    check('the throw-away category, five products, their prices and stock were created', Object.keys(variationIds).length === 5);
    artisan(['prices:refresh-from']);

    // what the cards print, in the page's order: [slug of the product, the first number of its price text or null]
    const cards = (page) => page.$$eval('.sf-card', (nodes) => nodes.map((n) => {
        const name = (n.querySelector('.sf-card-name')?.textContent ?? '').trim();
        const price = n.querySelector('.sf-price-current')?.textContent ?? '';
        const m = price.replace(/\s+/g, ' ').match(/\d+[.,]\d{2}/);

        return [name.replace('P6a audit ', ''), m ? Number(m[0].replace(',', '.')) : null];
    }));

    for (const width of [1440, 390]) {
        const ctx = await browser.newContext({ viewport: { width, height: 1500 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
        ctx.setDefaultTimeout(25000);
        const page = await ctx.newPage();
        page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR@${width} ` + String(e).slice(0, 200)));
        let expectedNotFound = false; // the one deliberate 404 (the sort by name) logs a failed-resource line of its own
        page.on('console', (m) => { if (m.type() === 'error' && ! expectedNotFound) consoleErrors.push(`CONSOLE@${width} ` + m.text().slice(0, 200)); });
        page.on('response', (r) => { r.status() >= 500 && consoleErrors.push(`HTTP@${width} ` + r.status() + ' ' + r.url().slice(0, 120)); });

        // ---- the three options
        await page.goto(URL, { waitUntil: 'networkidle' });
        const radios = await page.$$eval('input[name="sort"]', (els) => els.map((e) => e.value));
        // the page prints the sort twice (the bar's panel and the dropdown; the stylesheet shows one per width): each set must be exactly the three
        const bar = await page.$$eval('.sf-bctl-sort a.sf-bv-link', (els) => els.map((e) => e.textContent.trim()));
        const dropdown = await page.$$eval('.sf-sortdd-link', (els) => els.map((e) => e.textContent.trim()));
        const links = [bar, dropdown].find((set) => set.length > 0) ?? [];
        report[`sort_options_${width}`] = { radios, bar, dropdown };
        check(`${width}: the drawer's radios are exactly the three sorts (default, price up, price down)`, radios.length === 3 && radios[0] === '' && radios[1] === 'price_asc' && radios[2] === 'price_desc', JSON.stringify(radios));
        check(`${width}: the sort links are exactly three, with the price labels and no sort by name`, bar.length + dropdown.length > 0 && [bar, dropdown].every((set) => set.length === 0 || set.length === 3) && links.some((t) => /Цена: от ниска към висока|Price: low to high/.test(t)) && links.some((t) => /Цена: от висока към ниска|Price: high to low/.test(t)) && ! links.some((t) => /По име|Name/.test(t)), JSON.stringify(links));
        const sA = join(OUT, `p6a-default-${width}.png`); shots.push(sA); await page.screenshot({ path: sA });

        // ---- price ascending: B 10, C 15 (the SALE price), D 5 (its cheapest size), A 30, E (no price) last
        await page.goto(URL + '?sort=price_asc', { waitUntil: 'networkidle' });
        const asc = await cards(page);
        report[`asc_${width}`] = asc;
        check(`${width}: price ascending: D 5.00, B 10.00, C 15.00 (the sale price), A 30.00, the unpriced E last`, JSON.stringify(asc) === JSON.stringify([['D', 5], ['B', 10], ['C', 15], ['A', 30], ['E', null]]), JSON.stringify(asc));
        const sB = join(OUT, `p6a-price-asc-${width}.png`); shots.push(sB); await page.screenshot({ path: sB, fullPage: true });

        // ---- price descending: A 30, C 15, B 10, D 5, E last (a product with no price is last in BOTH)
        await page.goto(URL + '?sort=price_desc', { waitUntil: 'networkidle' });
        const desc = await cards(page);
        report[`desc_${width}`] = desc;
        check(`${width}: price descending: A 30.00, C 15.00, B 10.00, D 5.00, the unpriced E still last`, JSON.stringify(desc) === JSON.stringify([['A', 30], ['C', 15], ['B', 10], ['D', 5], ['E', null]]), JSON.stringify(desc));

        // ---- the sort by name is gone
        expectedNotFound = true;
        const byName = await page.goto(URL + '?sort=name_asc');
        expectedNotFound = false;
        check(`${width}: the sort by name is a 404`, byName.status() === 404, byName.status());

        // ---- D's cheapest size (S, 5.00) is sold out: through the app's stock repository (it queues the refresh), the queue is run
        php(String.raw`use EasyCo\Inventory\Contracts\StockLevelRepository; use EasyCo\Inventory\StockLevel; app(StockLevelRepository::class)->save(StockLevel::forVariation('${variationIds.d[0]}', 0)); echo 'ok';`);
        artisan(['queue:work', '--stop-when-empty', '--tries=1', '--quiet']);
        await page.goto(URL + '?sort=price_asc', { waitUntil: 'networkidle' });
        const asc2 = await cards(page);
        report[`asc_after_sold_out_${width}`] = asc2;
        check(`${width}: the cheapest size sold out: D now sorts and shows 25.00 (the available size): B, C, D, A, E`, JSON.stringify(asc2) === JSON.stringify([['B', 10], ['C', 15], ['D', 25], ['A', 30], ['E', null]]), JSON.stringify(asc2));
        const sC = join(OUT, `p6a-after-sold-out-${width}.png`); shots.push(sC); await page.screenshot({ path: sC, fullPage: true });

        if (width === 390) {
            check('390: no horizontal scroll', ! (await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)));
        }

        // put the size back for the second width
        php(String.raw`use EasyCo\Inventory\Contracts\StockLevelRepository; use EasyCo\Inventory\StockLevel; app(StockLevelRepository::class)->save(StockLevel::forVariation('${variationIds.d[0]}', 2)); echo 'ok';`);
        artisan(['queue:work', '--stop-when-empty', '--tries=1', '--quiet']);
        await ctx.close();
    }

    check('no console errors, no page errors, no 5xx answers', consoleErrors.length === 0, consoleErrors.join(' | '));
} catch (e) {
    check('the run finished without an exception', false, String(e).slice(0, 900));
} finally {
    if (watchdog) clearTimeout(watchdog);
    if (created) {
        try {
            php(CLEANUP);
            const after = JSON.parse(php(SUMS));
            for (const k of ['products', 'variations', 'stock', 'prices', 'categories', 'pivots', 'attrs', 'jobs']) {
                check(`${k}: back EXACTLY as before the run`, after[k] === before[k], `${before[k]} vs ${after[k]}`);
            }
        } catch (e) {
            check('the data was put back', false, String(e).slice(0, 600));
        }
    }
    await browser.close();
    const failed = steps.filter((s) => ! s.ok);
    console.log(JSON.stringify({ base: BASE, report, shots, console_errors: consoleErrors, steps, failed: failed.length }, null, 2));
    process.exit(failed.length === 0 ? 0 : 1);
}
