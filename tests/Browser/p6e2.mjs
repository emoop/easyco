// A REAL-BROWSER check of P6e-2: «Колекции» on the storefront.
//   At 1440 and 390 px: with the switch OFF (the default) `/collection/x` is a 404 and the category listing has no «Колекция» filter; the switch is turned ON; a throw-away shop slice (3 products, two collections: one
//   product in both, one sold out) is read through the storefront: the collection page lists the right products (the sold-out one is hidden by the P6c default), the crumb, the title cloud and the «Съдържание» top block
//   of the collection kind; the sidebar filter (shown through «Сайдбар») filters a category listing; the season page is the page it was (screenshot to compare by eye); no horizontal scroll at 390; no console errors.
// NOT part of `php artisan test`: run by hand against the dev site. BEFORE the run every checksum (and the switch, the sidebar, the content set) is taken; at the end the settings are put back, everything created is
// deleted and the checksums are compared (a watchdog puts the data back after 15 minutes). The screenshots go to OUT, which must be OUTSIDE the project folder.
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p6e2.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p6e2.mjs'); process.exit(2); }
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
echo json_encode(['products' => $sum('catalog_products'), 'variations' => $sum('catalog_variations'), 'stock' => $sum('stock_levels'), 'prices' => $sum('pricing_price_list_items'), 'pivots' => $sum('catalog_product_categories').$sum('catalog_product_tags').$sum('catalog_product_collections'), 'collections' => $sum('catalog_collections'), 'settings' => md5(json_encode(DB::table('site_settings')->orderBy('key')->get()->map(fn ($r) => (array) $r)->all())), 'blocks' => DB::table('html_blocks')->count(), 'jobs' => DB::table('jobs')->count()]);`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$pids = DB::table('catalog_products')->where('slug', 'like', 'p6e2-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('pricing_price_list_items')->where('target_type', 'variation')->whereIn('target_id', $vids->map(fn ($v) => (string) $v))->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_collections')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
$cids = DB::table('catalog_collections')->where('slug', 'like', 'p6e2-audit-%')->pluck('id');
DB::table('catalog_product_collections')->whereIn('collection_id', $cids)->delete();
DB::table('catalog_collections')->whereIn('id', $cids)->delete();
DB::table('catalog_categories')->where('slug', 'p6e2-audit-cat')->delete();
DB::table('html_blocks')->where('title', 'like', 'p6e2-audit%')->delete();
DB::table('jobs')->where('payload', 'like', '%RefreshProductSortPrices%')->delete();
echo 'cleaned';`;

// a throw-away category «P6e2 audit cat» that holds three products «P6e2 audit 1…3» (simple, regular 100.00, stock 5; the third has NO stock); collections «P6e2 audit A» (products 1 and 2) and «P6e2 audit B» (products 2 and 3)
const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$class = DB::table('catalog_variations')->whereNotNull('shipping_class')->value('shipping_class');
$group = DB::table('catalog_product_groups')->orderBy('id')->value('id');
$regular = DB::table('pricing_price_lists')->where('name', 'Regular Prices')->value('id');
$cat = DB::table('catalog_categories')->insertGetId(['name' => 'P6e2 audit cat', 'slug' => 'p6e2-audit-cat', 'created_at' => $now, 'updated_at' => $now]);
$a = DB::table('catalog_collections')->insertGetId(['name' => 'P6e2 audit A', 'slug' => 'p6e2-audit-a', 'created_at' => $now, 'updated_at' => $now]);
$b = DB::table('catalog_collections')->insertGetId(['name' => 'P6e2 audit B', 'slug' => 'p6e2-audit-b', 'created_at' => $now, 'updated_at' => $now]);
$ids = [];
for ($i = 1; $i <= 3; $i++) {
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'simple', 'name' => 'P6e2 audit '.$i, 'slug' => 'p6e2-audit-'.$i, 'base_sku' => 'P6E2AUDIT-'.$i, 'status' => 'active', 'catalog_visibility' => 'visible', 'product_group_id' => $group, 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => $now->copy()->addSeconds($i), 'is_available' => $i === 3 ? 0 : 1, 'price_from_minor' => 10000]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'universal', 'status' => 'active', 'attribute_signature' => hash('sha256', 'p6e2-'.$i), 'sku' => 'P6E2AUDIT-'.$i.'-0', 'is_visible' => 0, 'is_purchasable' => 1, 'shipping_class' => $class, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => $i === 3 ? 0 : 5, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('pricing_price_list_items')->insert(['price_list_id' => $regular, 'target_type' => 'variation', 'target_id' => (string) $vid, 'min_quantity' => 1, 'price_amount_minor' => 10000, 'price_currency' => 'EUR', 'price_tax_rate_basis_points' => 0, 'price_tax_inclusive' => 0, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  $ids[] = $pid;
}
DB::table('catalog_product_collections')->insert([['product_id' => $ids[0], 'collection_id' => $a], ['product_id' => $ids[1], 'collection_id' => $a], ['product_id' => $ids[1], 'collection_id' => $b], ['product_id' => $ids[2], 'collection_id' => $b]]);
$block = App\Content\HtmlBlocks\HtmlBlockModel::query()->create(['title' => 'p6e2-audit top', 'body' => '<p>P6E2 TOP BLOCK</p>']);
echo json_encode(['ids' => $ids, 'cat' => $cat, 'a' => $a, 'b' => $b, 'block' => $block->id]);`;

const SETTINGS = (value) => String.raw`use App\Settings\Contracts\SiteSettingsRepository; use App\Storefront\Filters\FilterCatalog; $s = app(SiteSettingsRepository::class); ${value === null ? "$s->forget('catalog.collections_enabled');" : `$s->set('catalog.collections_enabled', '${value}');`} FilterCatalog::forget(); echo 'ok';`;

// the merchant's choices in «Дизайн»: «Колекция» shown in «Сайдбар»; a «Съдържание» default for collections (the cloud and a top block)
const DESIGN = (blockId) => String.raw`
use App\Storefront\Content\ContentSet; use App\Storefront\Content\ContentStore; use App\Storefront\Filters\FilterCatalog; use App\Storefront\Sidebar\SidebarFilters; use App\Storefront\Sidebar\SidebarStore;
FilterCatalog::forget();
$known = (new FilterCatalog)->knownKeys();
$stored = SidebarStore::values();
$rows = array_map(fn ($r) => ['key' => $r['key'], 'show' => $r['key'] === 'collection' ? true : $r['show']], $stored['filters']);
[$clean, $e] = SidebarFilters::validate(['filters' => $rows, 'kinds' => $stored['kinds']], $known, (new FilterCatalog)->hiddenByDefault());
if ($e !== []) { throw new Exception(json_encode($e)); }
SidebarStore::publish($clean);
$set = ContentStore::values();
$set['collections']['default'] = ['cloud' => true, 'title' => true, 'top' => ${blockId}, 'bottom' => null, 'bottom_heading' => ''];
[$c2, $e2] = ContentSet::validate($set, ContentStore::existing());
if ($e2 !== []) { throw new Exception(json_encode($e2)); }
ContentStore::publish($c2);
FilterCatalog::forget();
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

    const CAT = '/product-category/p6e2-audit-cat';
    const ids = async (page) => page.evaluate(() => [...document.querySelectorAll('[data-product-id]')].map((e) => Number(e.getAttribute('data-product-id'))));
    const filterNames = async (page) => page.evaluate(() => [...new Set([...document.querySelectorAll('.sf-fgroup-name, .sf-bctl-text')].map((e) => e.textContent.trim().split(':')[0]))]);

    for (const width of [1440, 390]) {
        const ctx = await browser.newContext({ viewport: { width, height: 1400 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
        ctx.setDefaultTimeout(30000);
        const page = await ctx.newPage();
        page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR@${width} ` + String(e).slice(0, 200)));
        page.on('console', (m) => { if (m.type() === 'error' && ! /403|404|Failed to load resource/.test(m.text())) consoleErrors.push(`CONSOLE@${width} ` + m.text().slice(0, 200)); });
        page.on('response', (r) => { r.status() >= 500 && consoleErrors.push(`HTTP@${width} ` + r.status() + ' ' + r.url().slice(0, 120)); });

        // ---- OFF (the default): the page is a 404, the listing has no collection filter
        php(SETTINGS(null));
        const off = await page.goto(BASE + '/collection/p6e2-audit-a', { waitUntil: 'domcontentloaded' });
        check(`${width}: OFF: /collection/{slug} is a 404`, off.status() === 404, off.status());
        await page.goto(BASE + CAT, { waitUntil: 'networkidle' });
        check(`${width}: OFF: the category listing has no «Колекция» filter`, ! (await filterNames(page)).includes('Колекция'), (await filterNames(page)).join('|'));
        check(`${width}: OFF: the listing shows the three products' two listed ones`, (await ids(page)).length === 2, (await ids(page)).join(','));
        const offFilter = await page.goto(BASE + CAT + '?filter[collection]=' + world.a, { waitUntil: 'domcontentloaded' });
        check(`${width}: OFF: filter[collection] in the URL is a 404`, offFilter.status() === 404, offFilter.status());

        // ---- ON
        php(SETTINGS('1'));
        php(DESIGN(world.block));
        const on = await page.goto(BASE + '/collection/p6e2-audit-a', { waitUntil: 'networkidle' });
        check(`${width}: ON: the collection page answers 200`, on.status() === 200, on.status());
        const listed = await ids(page);
        check(`${width}: ON: collection A lists its two products`, listed.length === 2 && listed.includes(world.ids[0]) && listed.includes(world.ids[1]), listed.join(','));
        const b = await page.goto(BASE + '/collection/p6e2-audit-b', { waitUntil: 'networkidle' });
        const listedB = await ids(page);
        check(`${width}: ON: collection B lists the product it shares with A, and hides the sold-out one`, b.status() === 200 && listedB.length === 1 && listedB[0] === world.ids[1], listedB.join(','));
        await page.goto(BASE + '/collection/p6e2-audit-a', { waitUntil: 'networkidle' });
        const text = await page.locator('main').first().evaluate((e) => e.textContent).catch(() => '');
        check(`${width}: ON: the title, the crumb, the top block of the «Съдържание» template and the cloud`, /P6e2 audit A/.test(text) && /Колекции/.test(text) && /P6E2 TOP BLOCK/.test(text) && /P6e2 audit cat/.test(text), text.replace(/\s+/g, ' ').slice(0, 300));
        const sA = join(OUT, `p6e2-collection-${width}.png`); shots.push(sA); await page.screenshot({ path: sA, fullPage: true });
        check(`${width}: ON: no horizontal scroll on the collection page`, ! (await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)));

        // ---- the sidebar filter
        await page.goto(BASE + CAT, { waitUntil: 'networkidle' });
        const names = await filterNames(page);
        check(`${width}: ON: the category listing offers «Колекция» (shown through «Сайдбар»)`, names.includes('Колекция'), names.join('|'));
        await page.goto(BASE + CAT + '?filter[collection]=' + world.b, { waitUntil: 'networkidle' });
        const filtered = await ids(page);
        check(`${width}: ON: filter[collection] narrows the listing to collection B (the sold-out product stays hidden)`, filtered.length === 1 && filtered[0] === world.ids[1], filtered.join(','));
        const sF = join(OUT, `p6e2-filter-${width}.png`); shots.push(sF); await page.screenshot({ path: sF, fullPage: false });

        // ---- the season page is the page it was
        const seasonSlug = php(String.raw`echo Illuminate\Support\Facades\DB::table('catalog_seasons')->orderBy('id')->value('slug') ?? '';`);
        if (seasonSlug !== '') {
            const s = await page.goto(BASE + '/season/' + seasonSlug, { waitUntil: 'networkidle' });
            check(`${width}: the season page still answers 200`, s.status() === 200, s.status());
            const sS = join(OUT, `p6e2-season-${width}.png`); shots.push(sS); await page.screenshot({ path: sS });
            check(`${width}: no horizontal scroll on the season page`, ! (await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)));
        }

        // ---- OFF again: everything is gone, the stored choices stay
        php(SETTINGS(null));
        const off2 = await page.goto(BASE + '/collection/p6e2-audit-a', { waitUntil: 'domcontentloaded' });
        check(`${width}: switched OFF again: the page is a 404`, off2.status() === 404, off2.status());
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
