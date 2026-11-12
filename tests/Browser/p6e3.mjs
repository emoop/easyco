// A REAL-BROWSER check of P6e-3 (parts A–C; part D, the price-list scope, was stopped): «Колекции» in the HTML-block conditions, the home page and «Мета информация».
//   At 1440 and 390 px: a throw-away shop slice (3 products, collection A with a picture holds products 1 and 2, product 3 is outside it, product 3 has no stock); with the switch ON the home page shows a collection product grid
//   (exactly A's products) and a collection tile (the collection's picture, a link to /collection/{slug}); the product page of a collection product shows the «Колекции:» line in «Мета информация» and an HTML block conditioned on
//   the collection, which a product outside the collection does NOT show; with the switch OFF all of it disappears (and the stored choices stay). No horizontal scroll, no console errors.
// NOT part of `php artisan test`: run by hand against the dev site. BEFORE the run every checksum and the whole `site_settings` table are taken (a snapshot in a file OUTSIDE the project); at the end the settings are put back,
// everything the script created is deleted and the checksums are compared (a watchdog puts the data back after 15 minutes). The screenshots go to OUT, which must be OUTSIDE the project folder.
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p6e3.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p6e3.mjs'); process.exit(2); }
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
$pids = DB::table('catalog_products')->where('slug', 'like', 'p6e3-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('pricing_price_list_items')->where('target_type', 'variation')->whereIn('target_id', $vids->map(fn ($v) => (string) $v))->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_collections')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
$cids = DB::table('catalog_collections')->where('slug', 'like', 'p6e3-audit-%')->pluck('id');
DB::table('catalog_product_collections')->whereIn('collection_id', $cids)->delete();
DB::table('catalog_collections')->whereIn('id', $cids)->delete();
DB::table('catalog_categories')->where('slug', 'p6e3-audit-cat')->delete();
DB::table('html_blocks')->where('title', 'like', 'p6e3-audit%')->delete();
DB::table('jobs')->where('payload', 'like', '%RefreshProductSortPrices%')->delete();
echo 'cleaned';`;

// a throw-away category «P6e3 audit cat» that holds three products «P6e3 audit 1…3» (simple, regular 100.00, stock 5; the third has NO stock); collections «P6e3 audit A» (products 1 and 2) and «P6e3 audit B» (products 2 and 3)
const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$class = DB::table('catalog_variations')->whereNotNull('shipping_class')->value('shipping_class');
$group = DB::table('catalog_product_groups')->orderBy('id')->value('id');
$regular = DB::table('pricing_price_lists')->where('name', 'Regular Prices')->value('id');
$cat = DB::table('catalog_categories')->insertGetId(['name' => 'P6e3 audit cat', 'slug' => 'p6e3-audit-cat', 'created_at' => $now, 'updated_at' => $now]);
$a = DB::table('catalog_collections')->insertGetId(['name' => 'P6e3 audit A', 'slug' => 'p6e3-audit-a', 'created_at' => $now, 'updated_at' => $now]);
$b = DB::table('catalog_collections')->insertGetId(['name' => 'P6e3 audit B', 'slug' => 'p6e3-audit-b', 'created_at' => $now, 'updated_at' => $now]);
$ids = [];
for ($i = 1; $i <= 3; $i++) {
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'simple', 'name' => 'P6e3 audit '.$i, 'slug' => 'p6e3-audit-'.$i, 'base_sku' => 'P6E3AUDIT-'.$i, 'status' => 'active', 'catalog_visibility' => 'visible', 'product_group_id' => $group, 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => $now->copy()->addSeconds($i), 'is_available' => $i === 3 ? 0 : 1, 'price_from_minor' => 10000]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'universal', 'status' => 'active', 'attribute_signature' => hash('sha256', 'p6e3-'.$i), 'sku' => 'P6E3AUDIT-'.$i.'-0', 'is_visible' => 0, 'is_purchasable' => 1, 'shipping_class' => $class, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => $i === 3 ? 0 : 5, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('pricing_price_list_items')->insert(['price_list_id' => $regular, 'target_type' => 'variation', 'target_id' => (string) $vid, 'min_quantity' => 1, 'price_amount_minor' => 10000, 'price_currency' => 'EUR', 'price_tax_rate_basis_points' => 0, 'price_tax_inclusive' => 0, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  $ids[] = $pid;
}
DB::table('catalog_product_collections')->insert([['product_id' => $ids[0], 'collection_id' => $a], ['product_id' => $ids[1], 'collection_id' => $a], ['product_id' => $ids[1], 'collection_id' => $b], ['product_id' => $ids[2], 'collection_id' => $b]]);
$block = App\Content\HtmlBlocks\HtmlBlockModel::query()->create(['title' => 'p6e3-audit top', 'body' => '<p>P6E3 TOP BLOCK</p>']);
echo json_encode(['ids' => $ids, 'cat' => $cat, 'a' => $a, 'b' => $b, 'block' => $block->id]);`;

const SETTINGS = (value) => String.raw`use App\Settings\Contracts\SiteSettingsRepository; use App\Storefront\Filters\FilterCatalog; $s = app(SiteSettingsRepository::class); ${value === null ? "$s->forget('catalog.collections_enabled');" : `$s->set('catalog.collections_enabled', '${value}');`} FilterCatalog::forget(); app(App\Storefront\Home\HomeTilesRecompiler::class)->collectionsSwitch(); app(App\Storefront\Home\HomeTilesRecompiler::class)->flush(); echo 'ok';`;

// the merchant's choices in «Дизайн»: a home page with a collection product grid and a collection tile; the product sections «Мета информация» + «HTML блокове» (a block conditioned on the collection) in an accordion
const DESIGN = (world) => String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Str; use App\Content\HtmlBlocks\HtmlBlockModel; use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Home\HomeDrafts; use App\Storefront\Home\HomeValidator; use App\Storefront\Sections\SectionSet; use App\Storefront\Sections\SectionStore;
use App\Storefront\ProductSummary\ProductSummaryCompiler; use App\Storefront\ProductSummary\ProductSummaryLayout;
$a = ${world.a};
$asset = DB::table('catalog_media')->where('processing_status', 'ready')->orderBy('id')->value('id');
if ($asset === null) { throw new Exception('no ready media asset in the dev database'); }
DB::table('catalog_collections')->where('id', $a)->update(['picture_media_asset_id' => $asset]);
DB::table('catalog_product_collections')->where('product_id', ${world.ids[2]})->where('collection_id', $a)->delete();
$cond = HtmlBlockModel::query()->create(['title' => 'p6e3-audit cond', 'body' => '<p>P6E3 COND BLOCK</p>', 'conditions' => ['collections' => [$a]]]);
[$h, $e] = HomeValidator::validate(['spacing' => '32', 'sections' => [
  ['type' => 'product_grid', 'settings' => ['source' => 'collection', 'entity' => $a, 'count' => '12', 'sort' => 'newest']],
  ['type' => 'tiles', 'settings' => ['tiles' => [['source' => 'collection', 'entity' => $a, 'label' => '', 'picture' => null, 'link' => null]]]],
]]);
if ($e !== []) { throw new Exception(json_encode($e)); }
HomeDrafts::publish($h);
$layout = ProductSummaryLayout::default();
$layout['components'][] = ['type' => 'accordion', 'settings' => ['first_open' => true]];
app(ProductSummaryCompiler::class)->publish($layout, app(SiteSettingsRepository::class));
[$sec, $e2] = SectionSet::validate(['sections' => [
  ['key' => (string) Str::uuid(), 'title' => 'P6e3 meta', 'source' => 'meta'],
  ['key' => (string) Str::uuid(), 'title' => 'P6e3 cond', 'source' => 'blocks', 'blocks' => [$cond->id]],
]]);
if ($e2 !== []) { throw new Exception(json_encode($e2)); }
SectionStore::publish($sec);
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

    const ids = async (page) => page.evaluate(() => [...new Set([...document.querySelectorAll('[data-product-id]')].map((e) => Number(e.getAttribute('data-product-id'))))]);
    const noHScroll = async (page) => ! (await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1));
    const html = async (page) => page.content();
    const P = (i) => BASE + '/product/p6e3-audit-' + i;

    // the throw-away data + the merchant's choices are made ONCE (ON); each width then switches OFF / ON through the settings
    php(SETTINGS('1'));
    php(DESIGN(world));

    for (const width of [1440, 390]) {
        const ctx = await browser.newContext({ viewport: { width, height: 1400 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
        ctx.setDefaultTimeout(30000);
        const page = await ctx.newPage();
        page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR@${width} ` + String(e).slice(0, 200)));
        page.on('console', (m) => { if (m.type() === 'error' && ! /403|404|Failed to load resource/.test(m.text())) consoleErrors.push(`CONSOLE@${width} ` + m.text().slice(0, 200)); });
        page.on('response', (r) => { r.status() >= 500 && consoleErrors.push(`HTTP@${width} ` + r.status() + ' ' + r.url().slice(0, 120)); });

        // ---- ON: the home page
        php(SETTINGS('1'));
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        const homeIds = await ids(page);
        check(`${width}: ON: the home grid lists exactly collection A's products (the sold-out one is hidden)`, homeIds.length === 2 && homeIds.includes(world.ids[0]) && homeIds.includes(world.ids[1]), homeIds.join(','));
        check(`${width}: ON: the collection tile links to /collection/p6e3-audit-a`, (await html(page)).includes('href="/collection/p6e3-audit-a"'));
        const sH = join(OUT, `p6e3-home-${width}.png`); shots.push(sH); await page.screenshot({ path: sH, fullPage: true });
        check(`${width}: ON: no horizontal scroll on the home page`, await noHScroll(page));

        // ---- ON: the product page (Meta line + conditioned block)
        await page.goto(P(1), { waitUntil: 'networkidle' });
        const t1 = await page.locator('main').first().evaluate((e) => e.textContent).catch(() => '');
        check(`${width}: ON: the product of the collection shows the «Колекции:» line with a link to its page`, /Колекции:/.test(t1) && (await html(page)).includes('href="/collection/p6e3-audit-a"'), t1.replace(/\s+/g, ' ').slice(0, 200));
        check(`${width}: ON: the block conditioned on the collection shows on its product`, /P6E3 COND BLOCK/.test(t1));
        const sP = join(OUT, `p6e3-product-${width}.png`); shots.push(sP); await page.screenshot({ path: sP, fullPage: true });
        check(`${width}: ON: no horizontal scroll on the product page`, await noHScroll(page));
        await page.goto(P(3), { waitUntil: 'networkidle' });
        const t3 = await page.locator('main').first().evaluate((e) => e.textContent).catch(() => '');
        check(`${width}: ON: a product outside collection A shows no link to A and not the conditioned block (it is in B: its own line is right)`, ! (await html(page)).includes('href="/collection/p6e3-audit-a"') && ! /P6E3 COND BLOCK/.test(t3), t3.replace(/\s+/g, ' ').slice(0, 200));

        // ---- OFF: everything is gone, the stored choices stay
        php(SETTINGS(null));
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        const offHome = await ids(page);
        check(`${width}: OFF: the home grid prints no collection products and the tile is gone`, ! offHome.includes(world.ids[0]) && ! offHome.includes(world.ids[1]) && ! (await html(page)).includes('/collection/p6e3-audit-a'), offHome.join(','));
        await page.goto(P(1), { waitUntil: 'networkidle' });
        const o1 = await page.locator('main').first().evaluate((e) => e.textContent).catch(() => '');
        check(`${width}: OFF: no «Колекции:» line and the conditioned block is hidden`, ! /Колекции:/.test(o1) && ! /P6E3 COND BLOCK/.test(o1), o1.replace(/\s+/g, ' ').slice(0, 200));

        // ---- ON again: it comes back
        php(SETTINGS('1'));
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        check(`${width}: ON again: the grid and the tile are back`, (await ids(page)).includes(world.ids[0]) && (await html(page)).includes('href="/collection/p6e3-audit-a"'));
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
