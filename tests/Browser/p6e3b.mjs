// A REAL-BROWSER check of P6e-3b: the price-list scope COLLECTION.
//   At 1440 and 390 px: a throw-away shop slice (3 products; collection A holds products 1 and 2, product 3 is outside it); a sale price list (−30%) scoped to collection A is created as a draft and activated the way the admin does
//   (PriceListWriter). With the switch ON the sale price shows on the collection page and on the product page of A's products and NOT on the product outside; with the switch OFF the regular price shows everywhere; ON again brings it back.
// NOT part of `php artisan test`: run by hand against the dev site. BEFORE the run every checksum and the whole `site_settings` table are taken (a snapshot in a file OUTSIDE the project); at the end the settings are put back, everything the
// script created (including the price list) is deleted and the checksums are compared (a watchdog puts the data back after 15 minutes). The screenshots go to OUT, which must be OUTSIDE the project folder.
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p6e3b.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p6e3b.mjs'); process.exit(2); }
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
echo json_encode(['products' => $sum('catalog_products'), 'variations' => $sum('catalog_variations'), 'stock' => $sum('stock_levels'), 'prices' => $sum('pricing_price_list_items'), 'pivots' => $sum('catalog_product_categories').$sum('catalog_product_tags').$sum('catalog_product_collections'), 'collections' => $sum('catalog_collections'), 'settings' => md5(json_encode(DB::table('site_settings')->orderBy('key')->get()->map(fn ($r) => (array) $r)->all())), 'blocks' => DB::table('html_blocks')->count(), 'lists' => $sum('pricing_price_lists'), 'scopes' => $sum('pricing_price_list_scopes'), 'jobs' => DB::table('jobs')->count()]);`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$pids = DB::table('catalog_products')->where('slug', 'like', 'p6e3b-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('pricing_price_list_items')->where('target_type', 'variation')->whereIn('target_id', $vids->map(fn ($v) => (string) $v))->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_collections')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
$cids = DB::table('catalog_collections')->where('slug', 'like', 'p6e3b-audit-%')->pluck('id');
DB::table('catalog_product_collections')->whereIn('collection_id', $cids)->delete();
DB::table('catalog_collections')->whereIn('id', $cids)->delete();
DB::table('catalog_categories')->where('slug', 'p6e3b-audit-cat')->delete();
DB::table('html_blocks')->where('title', 'like', 'p6e3b-audit%')->delete();
$lids = DB::table('pricing_price_lists')->where('name', 'like', 'p6e3b-audit%')->pluck('id');
DB::table('pricing_price_list_items')->whereIn('price_list_id', $lids)->delete();
DB::table('pricing_price_list_scopes')->whereIn('price_list_id', $lids)->delete();
DB::table('pricing_price_lists')->whereIn('id', $lids)->delete();
DB::table('jobs')->where('payload', 'like', '%RefreshProductSortPrices%')->delete();
echo 'cleaned';`;

// a throw-away category «P6e3b audit cat» that holds three products «P6e3b audit 1…3» (simple, regular 100.00, stock 5; the third has NO stock); collections «P6e3b audit A» (products 1 and 2) and «P6e3b audit B» (products 2 and 3)
const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$class = DB::table('catalog_variations')->whereNotNull('shipping_class')->value('shipping_class');
$group = DB::table('catalog_product_groups')->orderBy('id')->value('id');
$regular = DB::table('pricing_price_lists')->where('name', 'Regular Prices')->value('id');
$cat = DB::table('catalog_categories')->insertGetId(['name' => 'P6e3b audit cat', 'slug' => 'p6e3b-audit-cat', 'created_at' => $now, 'updated_at' => $now]);
$a = DB::table('catalog_collections')->insertGetId(['name' => 'P6e3b audit A', 'slug' => 'p6e3b-audit-a', 'created_at' => $now, 'updated_at' => $now]);
$b = DB::table('catalog_collections')->insertGetId(['name' => 'P6e3b audit B', 'slug' => 'p6e3b-audit-b', 'created_at' => $now, 'updated_at' => $now]);
$ids = [];
for ($i = 1; $i <= 3; $i++) {
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'simple', 'name' => 'P6e3b audit '.$i, 'slug' => 'p6e3b-audit-'.$i, 'base_sku' => 'P6E3BAUDIT-'.$i, 'status' => 'active', 'catalog_visibility' => 'visible', 'product_group_id' => $group, 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => $now->copy()->addSeconds($i), 'is_available' => $i === 3 ? 0 : 1, 'price_from_minor' => 10000]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'universal', 'status' => 'active', 'attribute_signature' => hash('sha256', 'p6e3b-'.$i), 'sku' => 'P6E3BAUDIT-'.$i.'-0', 'is_visible' => 0, 'is_purchasable' => 1, 'shipping_class' => $class, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => $i === 3 ? 0 : 5, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('pricing_price_list_items')->insert(['price_list_id' => $regular, 'target_type' => 'variation', 'target_id' => (string) $vid, 'min_quantity' => 1, 'price_amount_minor' => 10000, 'price_currency' => 'EUR', 'price_tax_rate_basis_points' => 0, 'price_tax_inclusive' => 0, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  $ids[] = $pid;
}
DB::table('catalog_product_collections')->insert([['product_id' => $ids[0], 'collection_id' => $a], ['product_id' => $ids[1], 'collection_id' => $a], ['product_id' => $ids[1], 'collection_id' => $b], ['product_id' => $ids[2], 'collection_id' => $b]]);
$block = App\Content\HtmlBlocks\HtmlBlockModel::query()->create(['title' => 'p6e3b-audit top', 'body' => '<p>P6E3B TOP BLOCK</p>']);
echo json_encode(['ids' => $ids, 'cat' => $cat, 'a' => $a, 'b' => $b, 'block' => $block->id]);`;

const SETTINGS = (value) => String.raw`use App\Settings\Contracts\SiteSettingsRepository; use App\Storefront\Filters\FilterCatalog; $s = app(SiteSettingsRepository::class); ${value === null ? "$s->forget('catalog.collections_enabled');" : `$s->set('catalog.collections_enabled', '${value}');`} FilterCatalog::forget(); app(App\Storefront\Home\HomeTilesRecompiler::class)->collectionsSwitch(); app(App\Storefront\Home\HomeTilesRecompiler::class)->flush(); echo 'ok';`;

// the campaign: a draft price list scoped to collection A, then activated the way the admin does
const DESIGN = (world) => String.raw`
use Illuminate\Support\Facades\DB; use App\Services\PriceListWriter;
$a = ${world.a};
DB::table('catalog_product_collections')->where('product_id', ${world.ids[2]})->where('collection_id', $a)->delete();
$w = app(PriceListWriter::class);
$list = $w->create(['name' => 'p6e3b-audit sale', 'percentage' => '30', 'is_active' => false, 'replaces_manual_sale' => false, 'confirm_all_products' => false, 'scopes' => [['type' => 'collection', 'reference' => (string) $a]]]);
$w->setActive((string) $list->id(), true);
echo 'ok';`;

// the settings rows are kept in a file (a command line is too short for them)
const SNAP_FILE = join(OUT, 'site_settings.snapshot.json').replace(/\\/g, '/');
const SNAPSHOT = String.raw`use Illuminate\Support\Facades\DB; file_put_contents('${SNAP_FILE}', json_encode(DB::table('site_settings')->get()->map(fn ($r) => (array) $r)->all())); echo 'saved';`;
const RESTORE = () => String.raw`use Illuminate\Support\Facades\DB; use App\Storefront\Filters\FilterCatalog; $rows = json_decode(file_get_contents('${SNAP_FILE}'), true); DB::table('site_settings')->delete(); foreach (array_chunk($rows, 50) as $chunk) { DB::table('site_settings')->insert($chunk); } FilterCatalog::forget(); echo 'restored';`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });

const steps = []; const consoleErrors = []; const shots = []; const report = {};
const check = (name, ok, detail = '') => { process.env.TRACE && console.error((ok ? '· ' : 'FAIL ') + name.slice(0, 90)); steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + String(detail).slice(0, 300)); };

let before = null; let snapshot = false; let watchdog = null;
const putBack = () => { try { php(CLEANUP); } catch (e) { console.error('cleanup failed ' + e); } if (snapshot) { php(RESTORE()); } };

try {
    before = JSON.parse(php(SUMS));
    snapshot = php(SNAPSHOT) === 'saved';
    watchdog = setTimeout(() => { putBack(); console.error('WATCHDOG: the run took more than 15 minutes; the data was put back'); process.exit(3); }, 15 * 60 * 1000);
    const world = JSON.parse(php(SETUP));
    check('the throw-away category, three products, two collections and a block were created', world.ids.length === 3);

    const noHScroll = async (page) => ! (await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1));
    const text = async (page) => page.locator('main').first().evaluate((e) => e.textContent).catch(() => '');
    const price = async (page) => page.locator('.sf-summary .sf-price').first().textContent().catch(() => '');
    const P = (i) => BASE + '/product/p6e3b-audit-' + i;
    const SALE = /€\s?70\.00|70[.,]00\s?€/;
    const REG = /€\s?100\.00|100[.,]00\s?€/;

    php(SETTINGS('1'));
    php(DESIGN(world));

    for (const width of [1440, 390]) {
        const ctx = await browser.newContext({ viewport: { width, height: 1400 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
        ctx.setDefaultTimeout(30000);
        const page = await ctx.newPage();
        page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR@${width} ` + String(e).slice(0, 200)));
        page.on('console', (m) => { if (m.type() === 'error' && ! /403|404|Failed to load resource/.test(m.text())) consoleErrors.push(`CONSOLE@${width} ` + m.text().slice(0, 200)); });
        page.on('response', (r) => { r.status() >= 500 && consoleErrors.push(`HTTP@${width} ` + r.status() + ' ' + r.url().slice(0, 120)); });

        // ---- ON
        php(SETTINGS('1'));
        await page.goto(BASE + '/collection/p6e3b-audit-a', { waitUntil: 'networkidle' });
        const tc = await text(page);
        check(`${width}: ON: the collection page shows the sale price of A's products`, SALE.test(tc), tc.replace(/\s+/g, ' ').slice(0, 250));
        const sC = join(OUT, `p6e3b-collection-${width}.png`); shots.push(sC); await page.screenshot({ path: sC, fullPage: true });
        check(`${width}: ON: no horizontal scroll on the collection page`, await noHScroll(page));
        await page.goto(P(1), { waitUntil: 'networkidle' });
        const t1 = await price(page);
        check(`${width}: ON: the product page of a collection product shows the sale price`, SALE.test(t1), t1.replace(/\s+/g, ' ').slice(0, 250));
        const sP = join(OUT, `p6e3b-product-${width}.png`); shots.push(sP); await page.screenshot({ path: sP, fullPage: true });
        await page.goto(P(3), { waitUntil: 'networkidle' });
        const t3 = await price(page);
        check(`${width}: ON: a product outside the collection keeps the regular price`, ! SALE.test(t3) && REG.test(t3), t3.replace(/\s+/g, ' ').slice(0, 250));

        // ---- OFF: the list never applies
        php(SETTINGS(null));
        await page.goto(P(1), { waitUntil: 'networkidle' });
        const o1 = await price(page);
        check(`${width}: OFF: the regular price everywhere (the list does not apply)`, ! SALE.test(o1) && REG.test(o1), o1.replace(/\s+/g, ' ').slice(0, 250));
        const sO = join(OUT, `p6e3b-product-off-${width}.png`); shots.push(sO); await page.screenshot({ path: sO, fullPage: false });
        await page.goto(BASE + '/product-category/p6e3b-audit-cat', { waitUntil: 'networkidle' });
        const oc = await text(page);
        check(`${width}: OFF: the category listing shows no sale price`, ! SALE.test(oc), oc.replace(/\s+/g, ' ').slice(0, 250));

        // ---- ON again
        php(SETTINGS('1'));
        await page.goto(P(1), { waitUntil: 'networkidle' });
        check(`${width}: ON again: the sale price is back`, SALE.test(await price(page)));
        await ctx.close();
    }
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
