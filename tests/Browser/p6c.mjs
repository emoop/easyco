// A REAL-BROWSER check of P6c: «Скрий продуктите без наличност».
//   At 1440 and 390 px, on a throw-away category with THREE products: X in stock, Y with ONE size left (a variable product), Z sold out.
//   ON (the default): the listing shows X and Y, the line «Брой продукти: 2», Z is absent; the product page of Z still answers 200 with «Изчерпан» and a DISABLED buy button;
//   the last size of Y is sold out (through the app's stock repository, the queue is run) → Y disappears and the count says 1; it is restocked → Y is back.
//   OFF (the admin page saved with the switch off): all three are shown, count 3. The settings page «Каталог» at 1280 and 360 px: the measured container, the info icon (no helper paragraph under the switch), no horizontal scroll.
// NOT part of `php artisan test`: run by hand against the dev site. It creates ONE category, three products («P6c audit …») with variations, prices and stock, and it switches the `catalog.hide_sold_out` setting; BEFORE the run
// every checksum (and the setting) is taken; at the end everything created is deleted, the setting is put back and the checksums are compared. A watchdog puts the data back after 12 minutes.
// The screenshots go to OUT, which must be OUTSIDE the project folder (the script refuses otherwise). The settings page is opened by a throw-away Administrator account that the script creates and deletes.
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p6c.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p6c.mjs'); process.exit(2); }
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

const EMAIL = `p6c-${Date.now()}@example.test`;
const PASSWORD = 'P6c-' + Math.random().toString(36).slice(2) + 'Aa1!';

const CREATE_STAFF = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'P6c Audit', $role));
echo 'ok';`;

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode(['products' => $sum('catalog_products'), 'variations' => $sum('catalog_variations'), 'stock' => $sum('stock_levels'), 'prices' => $sum('pricing_price_list_items'), 'categories' => $sum('catalog_categories'), 'pivots' => $sum('catalog_product_categories'), 'attrs' => DB::table('catalog_attribute_definitions')->count(), 'jobs' => DB::table('jobs')->count(), 'staff' => DB::table('staff')->count(), 'setting' => DB::table('site_settings')->where('key', 'catalog.hide_sold_out')->value('value')]);`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$pids = DB::table('catalog_products')->where('slug', 'like', 'p6c-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('pricing_price_list_items')->where('target_type', 'variation')->whereIn('target_id', $vids->map(fn ($v) => (string) $v))->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
DB::table('catalog_categories')->where('slug', 'p6c-audit-cat')->delete();
$defs = DB::table('catalog_attribute_definitions')->where('code', 'p6c-size')->pluck('id');
DB::table('catalog_attribute_values')->whereIn('attribute_definition_id', $defs)->delete();
DB::table('catalog_attribute_definitions')->whereIn('id', $defs)->delete();
DB::table('jobs')->where('payload', 'like', '%RefreshProductSortPrices%')->delete();
DB::table('staff')->where('email', '${EMAIL}')->delete();
echo 'cleaned';`;

// X simple 10.00 stock 5; Y variable (S 20.00 stock 0, M 20.00 stock 1); Z simple 30.00 stock 0
const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$cat = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'P6c audit category', 'slug' => 'p6c-audit-cat', 'created_at' => $now, 'updated_at' => $now]);
$def = DB::table('catalog_attribute_definitions')->insertGetId(['code' => 'p6c-size', 'name' => 'P6c size', 'type' => 'select', 'is_filterable' => 0, 'filter_display' => 'list', 'created_at' => $now, 'updated_at' => $now]);
$vals = ['S' => DB::table('catalog_attribute_values')->insertGetId(['attribute_definition_id' => $def, 'value' => 'S', 'sort_order' => 0, 'colour' => null, 'created_at' => $now, 'updated_at' => $now]), 'M' => DB::table('catalog_attribute_values')->insertGetId(['attribute_definition_id' => $def, 'value' => 'M', 'sort_order' => 1, 'colour' => null, 'created_at' => $now, 'updated_at' => $now])];
$regular = DB::table('pricing_price_lists')->where('name', 'Regular Prices')->value('id');
$price = function ($list, $vid, $minor) use ($now) { DB::table('pricing_price_list_items')->insert(['price_list_id' => $list, 'target_type' => 'variation', 'target_id' => (string) $vid, 'min_quantity' => 1, 'price_amount_minor' => $minor, 'price_currency' => 'EUR', 'price_tax_rate_basis_points' => 0, 'price_tax_inclusive' => 0, 'created_at' => $now, 'updated_at' => $now]); };
$ids = [];
$make = function ($n, $name, $type, $variations) use ($now, $cat, $def, $vals, $regular, $price, &$ids) {
  $pid = DB::table('catalog_products')->insertGetId(['type' => $type, 'name' => 'P6c audit '.$name, 'slug' => 'p6c-audit-'.$n, 'base_sku' => 'P6CAUDIT-'.$n, 'status' => 'active', 'catalog_visibility' => 'visible', 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => now()->subMinutes(ord($n))]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  foreach ($variations as $i => [$size, $regularMinor, $stock]) {
    $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => $i, 'type' => $type === 'simple' ? 'universal' : 'standard', 'status' => 'active', 'attribute_signature' => hash('sha256', 'p6c-'.$n.'-'.$i), 'sku' => 'P6CAUDIT-'.$n.'-'.$i, 'is_visible' => 1, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
    if ($size !== null) { DB::table('catalog_variation_attribute_values')->insert(['variation_id' => $vid, 'attribute_definition_id' => $def, 'attribute_value_id' => $vals[$size], 'created_at' => $now, 'updated_at' => $now]); }
    if ($stock > 0) { DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => $stock, 'created_at' => $now, 'updated_at' => $now]); }
    $price($regular, $vid, $regularMinor);
    $ids[$n][] = $vid;
  }
};
$make('x', 'X in stock', 'simple', [[null, 1000, 5]]);
$make('y', 'Y one size left', 'variable', [['S', 2000, 0], ['M', 2000, 1]]);
$make('z', 'Z sold out', 'simple', [[null, 3000, 0]]);
echo json_encode($ids);`;

const setHide = (value) => php(String.raw`use App\Settings\Contracts\SiteSettingsRepository; use App\Storefront\Appearance\StorefrontAppearance; $s = app(SiteSettingsRepository::class); ${value === null ? "$s->forget('catalog.hide_sold_out');" : `$s->set('catalog.hide_sold_out', '${value}');`} app(StorefrontAppearance::class)->refresh(); echo 'ok';`);
const stock = (variationId, qty) => {
    php(String.raw`use EasyCo\Inventory\Contracts\StockLevelRepository; use EasyCo\Inventory\StockLevel; app(StockLevelRepository::class)->save(StockLevel::forVariation('${variationId}', ${qty})); echo 'ok';`);
    artisan(['queue:work', '--stop-when-empty', '--tries=1', '--quiet']);
};

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });

const steps = []; const consoleErrors = []; const shots = []; const report = {};
const check = (name, ok, detail = '') => { process.env.TRACE && console.error((ok ? '· ' : 'FAIL ') + name.slice(0, 90)); steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + String(detail).slice(0, 300)); };
const URL = BASE + '/product-category/p6c-audit-cat';

let before = null; let created = false; let watchdog = null;

try {
    before = JSON.parse(php(SUMS));
    created = true;
    watchdog = setTimeout(() => { try { php(CLEANUP); setHide(before.setting); } catch (e) { console.error('WATCHDOG cleanup failed ' + e); } console.error('WATCHDOG: the run took more than 12 minutes; the data was put back'); process.exit(3); }, 12 * 60 * 1000);
    const ids = JSON.parse(php(SETUP));
    check('the throw-away category, three products, their prices and stock were created', Object.keys(ids).length === 3);
    artisan(['prices:refresh-from']);
    setHide('1');

    const names = (page) => page.$$eval('.sf-card .sf-card-name', (nodes) => nodes.map((n) => n.textContent.trim().replace('P6c audit ', '')).sort());
    const count = (page) => page.$$eval('.sf-count', (nodes) => nodes.map((n) => n.textContent.replace(/\D+/g, '')).filter((t) => t !== '')[0] ?? null);

    for (const width of [1440, 390]) {
        const ctx = await browser.newContext({ viewport: { width, height: 1400 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
        ctx.setDefaultTimeout(25000);
        const page = await ctx.newPage();
        page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR@${width} ` + String(e).slice(0, 200)));
        page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(`CONSOLE@${width} ` + m.text().slice(0, 200)); });
        page.on('response', (r) => { r.status() >= 500 && consoleErrors.push(`HTTP@${width} ` + r.status() + ' ' + r.url().slice(0, 120)); });

        // ---- ON: the sold-out product is absent, the count says 2
        setHide('1');
        await page.goto(URL, { waitUntil: 'networkidle' });
        const on = await names(page); const onCount = await count(page);
        report[`on_${width}`] = { on, onCount };
        check(`${width}: ON: X and Y are shown, Z (sold out) is absent`, JSON.stringify(on) === JSON.stringify(['X in stock', 'Y one size left']), JSON.stringify(on));
        check(`${width}: ON: the count line says 2`, onCount === '2', onCount);
        const s1 = join(OUT, `p6c-on-${width}.png`); shots.push(s1); await page.screenshot({ path: s1, fullPage: true });

        // ---- the direct link of Z answers 200 with «Изчерпан» and a DISABLED buy button
        const z = await page.goto(BASE + '/product/p6c-audit-z', { waitUntil: 'networkidle' });
        const zText = await page.locator('.sf-product').innerText();
        const buyEnabled = await page.$$eval('button.sf-add-to-cart:not([disabled])', (b) => b.length);
        check(`${width}: the page of the hidden product answers 200`, z.status() === 200, z.status());
        check(`${width}: it says «Изчерпан» and has no enabled buy button`, /изчерпан/i.test(zText) && buyEnabled === 0, `${buyEnabled} enabled; ${zText.slice(0, 120).replace(/\s+/g, ' ')}`);
        const s2 = join(OUT, `p6c-direct-link-${width}.png`); shots.push(s2); await page.screenshot({ path: s2 });

        // ---- the last size of Y is sold out → Y disappears (after the queue run), the count says 1; restocked → back
        stock(ids.y[1], 0);
        await page.goto(URL, { waitUntil: 'networkidle' });
        const gone = await names(page); const goneCount = await count(page);
        check(`${width}: the last size sold out: Y disappears, only X is shown, the count says 1`, JSON.stringify(gone) === JSON.stringify(['X in stock']) && goneCount === '1', JSON.stringify(gone) + ' / ' + goneCount);
        stock(ids.y[1], 1);
        await page.goto(URL, { waitUntil: 'networkidle' });
        const back = await names(page);
        check(`${width}: restocked: Y is back`, JSON.stringify(back) === JSON.stringify(['X in stock', 'Y one size left']), JSON.stringify(back));

        // ---- OFF: all three, count 3
        setHide('0');
        await page.goto(URL, { waitUntil: 'networkidle' });
        const off = await names(page); const offCount = await count(page);
        report[`off_${width}`] = { off, offCount };
        check(`${width}: OFF: all three products are shown, the count says 3`, off.length === 3 && offCount === '3', JSON.stringify(off) + ' / ' + offCount);
        const s3 = join(OUT, `p6c-off-${width}.png`); shots.push(s3); await page.screenshot({ path: s3, fullPage: true });

        if (width === 390) {
            check('390: no horizontal scroll', ! (await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)));
        }
        await ctx.close();
    }

    // ---- the settings page «Каталог» (needs a staff login)
    {
        php(CREATE_STAFF);
        for (const width of [1280, 360]) {
            const ctx = await browser.newContext({ viewport: { width, height: 1000 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
            ctx.setDefaultTimeout(25000);
            const page = await ctx.newPage();
            page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR@admin${width} ` + String(e).slice(0, 200)));
            await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
            await page.fill('input[type=email]', EMAIL);
            await page.fill('input[type=password]', PASSWORD);
            await page.keyboard.press('Enter');
            await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
            await page.goto(BASE + '/admin/catalog-settings', { waitUntil: 'networkidle' });
            const toggle = page.locator('label:has-text("Скрий продуктите без наличност")').first();
            check(`${width}: the settings page shows the switch «Скрий продуктите без наличност»`, await toggle.count() > 0);
            const container = await page.evaluate(() => { const f = document.querySelector('.fi-main, main, .fi-page'); return f ? Math.round(f.getBoundingClientRect().width) : 0; });
            const mine = await page.evaluate(() => { const w = [...document.querySelectorAll('.fi-fo-field')].find((e) => /Скрий продуктите без наличност/.test(e.textContent) && e.querySelectorAll('.fi-fo-field').length === 0); return w ? { paragraphs: w.querySelectorAll('p').length, hint: w.querySelectorAll('[aria-describedby], button, svg').length } : null; });
            const helpers = mine?.paragraphs ?? -1;
            const infoIcons = mine?.hint ?? -1;
            check(`${width}: the new switch has no helper paragraph under it and carries the info icon`, helpers === 0 && infoIcons > 0, JSON.stringify(mine));
            report[`settings_${width}`] = { container, helpers, infoIcons };
            check(`${width}: no horizontal scroll`, ! (await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)));
            const s4 = join(OUT, `p6c-settings-${width}.png`); shots.push(s4); await page.screenshot({ path: s4, fullPage: true });
            await ctx.close();
        }
    }

    check('no console errors, no page errors, no 5xx answers', consoleErrors.length === 0, consoleErrors.join(' | '));
} catch (e) {
    check('the run finished without an exception', false, String(e).slice(0, 900));
} finally {
    if (watchdog) clearTimeout(watchdog);
    if (created) {
        try {
            php(CLEANUP);
            setHide(before.setting);
            const after = JSON.parse(php(SUMS));
            for (const k of ['products', 'variations', 'stock', 'prices', 'categories', 'pivots', 'attrs', 'jobs', 'staff', 'setting']) {
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
