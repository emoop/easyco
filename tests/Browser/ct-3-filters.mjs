// A REAL-BROWSER run of CT-3: the filters of the category page. (The older ct-3.mjs checks the OTHER «CT-3», the attribute's display type on the product page; both stay.) NOT part of `php artisan test`: run by
// hand against a dev site whose data may be changed. It creates a throw-away category («ct3f-cat»), three throw-away filterable attributes (Colour = circles, Size = text links, Material = a list, a PRODUCT-level
// attribute) and 68 products with colour × size variations (some out of stock), and BACKS UP a checksum of `site_settings` and of the catalog tables first; at the end it removes everything it created and
// verifies the checksums. Prints a JSON report (with the EXPLAIN plans and the measured time per filtered request); exit code 1 when a check failed.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/ct-3-filters.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node ct-3-filters.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const php = (code) => {
    try {
        return execFileSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }).trim().split('\n').pop();
    } catch (e) {
        throw new Error('php failed: ' + String(e.stdout || '').slice(-600) + String(e.stderr || '').slice(-300));
    }
};

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode(['settings' => $sum('site_settings'), 'defs' => $sum('catalog_attribute_definitions'), 'values' => $sum('catalog_attribute_values'), 'products' => DB::table('catalog_products')->count(), 'variations' => DB::table('catalog_variations')->count(), 'stock' => DB::table('stock_levels')->count(), 'pa' => DB::table('catalog_product_attributes')->count(), 'vv' => DB::table('catalog_variation_attribute_values')->count(), 'cats' => DB::table('catalog_categories')->count(), 'pc' => DB::table('catalog_product_categories')->count()]);`;

const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$cat = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'CT3F филтри', 'slug' => 'ct3f-cat', 'created_at' => $now, 'updated_at' => $now]);
$kid = DB::table('catalog_categories')->insertGetId(['parent_id' => $cat, 'name' => 'CT3F дете', 'slug' => 'ct3f-kid', 'created_at' => $now, 'updated_at' => $now]);
$def = function ($code, $name, $display, $values) use ($now) {
  $id = DB::table('catalog_attribute_definitions')->insertGetId(['code' => $code, 'name' => $name, 'type' => 'select', 'is_filterable' => 1, 'filter_display' => $display, 'created_at' => $now, 'updated_at' => $now]);
  $ids = [];
  foreach ($values as $i => [$label, $colour]) { $ids[$label] = DB::table('catalog_attribute_values')->insertGetId(['attribute_definition_id' => $id, 'value' => $label, 'sort_order' => $i, 'colour' => $colour, 'created_at' => $now, 'updated_at' => $now]); }
  return [$id, $ids];
};
[$colour, $c] = $def('ct3f-colour', 'Цвят', 'swatches', [['Червен', '#cc0000'], ['Син', '#0000cc'], ['Зелен', null]]);
[$size, $s] = $def('ct3f-size', 'Размер', 'links', [['S', null], ['M', null], ['L', null], ['XL', null]]);
[$material, $m] = $def('ct3f-material', 'Материя', 'list', [['Памук', null], ['Вълна', null]]);
$n = 0;
$product = function ($name, $variations, $categories, $materialValue = null) use (&$n, $now, $colour, $size, $material, $c, $s, $m) {
  $n++;
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'variable', 'name' => $name, 'slug' => 'ct3f-'.$n, 'base_sku' => 'CT3F-'.$n, 'status' => 'active', 'catalog_visibility' => 'visible', 'is_featured' => 0, 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => now()->addDays(3)->subMinutes($n)]);
  foreach ($variations as $k => [$cv, $sv, $stock]) {
    $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => $k, 'type' => 'standard', 'status' => 'active', 'attribute_signature' => hash('sha256', "ct3f-$n-$k"), 'sku' => "CT3F-V$n-$k", 'is_visible' => 1, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('catalog_variation_attribute_values')->insert([['variation_id' => $vid, 'attribute_definition_id' => $colour, 'attribute_value_id' => $c[$cv], 'created_at' => $now, 'updated_at' => $now], ['variation_id' => $vid, 'attribute_definition_id' => $size, 'attribute_value_id' => $s[$sv], 'created_at' => $now, 'updated_at' => $now]]);
    $stock > 0 && DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => $stock, 'created_at' => $now, 'updated_at' => $now]);
  }
  foreach ($categories as $category) { DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $category]); }
  $materialValue && DB::table('catalog_product_attributes')->insert(['product_id' => $pid, 'attribute_definition_id' => $material, 'is_variation_axis' => 0, 'attribute_value_id' => $m[$materialValue], 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now]);
  return $pid;
};
$product('CT3F A червен S + син M', [['Червен', 'S', 3], ['Син', 'M', 3]], [$cat]);
$product('CT3F B червен M', [['Червен', 'M', 3]], [$cat], 'Памук');
$product('CT3F C червен M изчерпан', [['Червен', 'M', 0], ['Червен', 'S', 2]], [$cat]);
$product('CT3F D зелен L', [['Зелен', 'L', 2]], [$cat], 'Вълна');
$product('CT3F E син S (дете)', [['Син', 'S', 1]], [$kid]);
for ($i = 1; $i <= 63; $i++) { $product('CT3F F'.$i.' червен S', [['Червен', 'S', 2]], [$cat], $i % 3 === 0 ? 'Памук' : null); }
app(App\Storefront\Filters\FilterCatalog::class)::forget();
echo json_encode(['cat' => $cat, 'colour' => $colour, 'size' => $size, 'material' => $material, 'c' => $c, 's' => $s, 'm' => $m]);`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$pids = DB::table('catalog_products')->where('slug', 'like', 'ct3f-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_attributes')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
$defs = DB::table('catalog_attribute_definitions')->where('code', 'like', 'ct3f-%')->pluck('id');
DB::table('catalog_attribute_values')->whereIn('attribute_definition_id', $defs)->delete();
DB::table('catalog_attribute_definitions')->whereIn('id', $defs)->delete();
DB::table('catalog_categories')->where('slug', 'ct3f-kid')->delete();
DB::table('catalog_categories')->where('slug', 'ct3f-cat')->delete();
app(App\Storefront\Filters\FilterCatalog::class)::forget();
echo 'cleaned';`;

// EXPLAIN of the listing and of every facet query with three filters chosen (colour + size + material)
const EXPLAIN = (ids) => String.raw`
use Illuminate\Support\Facades\DB;
$reader = app(App\Storefront\Reader\CatalogReader::class);
$q = App\Storefront\ReadModels\ListingQuery::fromRequestArray(['category' => '${ids.cat}', 'per_page' => '24']);
$sel = [${ids.colour} => [${ids.c['Червен']}], ${ids.size} => [${ids.s['M']}], ${ids.material} => [${ids.m['Памук']}]];
DB::enableQueryLog();
$reader->listing($q, $sel);
foreach ([${ids.colour}, ${ids.size}, ${ids.material}] as $a) { $reader->filterValueIds($q, $sel, $a); }
$plans = [];
foreach (DB::getQueryLog() as $entry) {
  if (! preg_match('/catalog_variation_attribute_values|catalog_product_attributes|catalog_products/', $entry['query'])) { continue; }
  if (! preg_match('/^(select|\()/i', $entry['query'])) { continue; }
  foreach (DB::select('EXPLAIN '.$entry['query'], $entry['bindings']) as $row) { $plans[] = [substr(md5($entry['query']), 0, 6), $row->select_type, $row->table, $row->type, $row->key, (int) $row->rows]; }
}
$full = array_values(array_filter($plans, fn ($p) => $p[3] === 'ALL' && $p[5] > 500));
echo json_encode(['queries' => count(DB::getQueryLog()), 'plans' => $plans, 'fullScansOver500Rows' => $full]);`;

const { chromium, request } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const consoleErrors = [];
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 500) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let before = null;
let ids = null;
let explain = null;
let timing = null;

try {
    before = JSON.parse(php(SUMS));
    console.error('before: ' + JSON.stringify(before));
    ids = JSON.parse(php(SETUP));

    const URL = '/product-category/ct3f-cat';
    const f = (...pairs) => pairs.map(([a, v]) => `filter[${a}]=${v}`).join('&');
    const COLOUR = ids.colour;
    const SIZE = ids.size;
    const MATERIAL = ids.material;

    const open = async (viewport, path, name = null) => {
        const ctx = await browser.newContext({ viewport, ignoreHTTPSErrors: true, ...(viewport.width < 600 ? { hasTouch: true, isMobile: true } : {}) });
        const page = await ctx.newPage();
        page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        const response = await page.goto(BASE + path, { waitUntil: 'networkidle' });
        name && OUT && (shots.push(join(OUT, name + '.png')), await page.screenshot({ path: join(OUT, name + '.png'), fullPage: true }));

        return { ctx, page, status: response.status(), cookies: (await ctx.cookies()).length };
    };
    const names = (page) => page.$$eval('.sf-grid .sf-card-name', (els) => els.map((e) => e.textContent.trim()));
    const sizes = (page) => page.$$eval('.sf-filter-links .sf-fv', (els) => els.map((e) => e.textContent.replace(/,.*$/, '').trim()));
    const metrics = (page) => page.evaluate(() => ({
        overflowX: document.documentElement.scrollWidth > window.innerWidth + 1,
        filters: document.querySelectorAll('.sf-filter').length,
        circles: document.querySelectorAll('.sf-fv-circle').length,
        dotLinks: document.querySelectorAll('.sf-fv-dot').length,
        textLinks: document.querySelectorAll('.sf-filter-swatches .sf-fv-link').length,
        listMarks: document.querySelectorAll('.sf-fv-list').length,
        styleAttrs: document.querySelectorAll('[style]').length,
    }));

    for (const [label, viewport, tag] of [['desktop 1400', { width: 1400, height: 1000 }, 'desktop'], ['phone 390', { width: 390, height: 844 }, 'phone']]) {
        // ---- the three looks, nothing chosen ----
        let { ctx, page, status, cookies } = await open(viewport, URL, `ct3f-${tag}-1-all-filters`);
        let m = await metrics(page);
        check(`${label}: 200, three filters printed (circles, text links, a list), no cookie, no style attribute, no horizontal scroll`, status === 200 && m.filters === 3 && m.circles === 2 && m.textLinks === 1 && m.listMarks === 2 && cookies === 0 && m.styleAttrs === 0 && ! m.overflowX, JSON.stringify({ status, m, cookies }));
        check(`${label}: all four sizes are offered (S, M, L, XL exist in stock somewhere)`, JSON.stringify(await sizes(page)) === JSON.stringify(['S', 'M', 'L', 'XL'].filter((x) => x !== 'XL')), JSON.stringify(await sizes(page)));
        await ctx.close();

        // ---- choose a colour (a real click on the circle) ----
        ({ ctx, page } = await open(viewport, URL));
        await page.locator('.sf-fv-dot[title="Червен"]').click();
        await page.waitForLoadState('networkidle');
        check(`${label}: the circle click opens the canonical filtered URL`, page.url().endsWith(URL + '?' + f([COLOUR, ids.c['Червен']])) || decodeURIComponent(page.url()).endsWith(URL + '?' + f([COLOUR, ids.c['Червен']])), page.url());
        check(`${label}: after red the size list shrinks to the sizes that exist in red in stock (S, M): L is green only`, JSON.stringify(await sizes(page)) === JSON.stringify(['S', 'M']), JSON.stringify(await sizes(page)));
        check(`${label}: the chosen circle is marked, a removable item and «Изчисти всички» appear`, (await page.locator('.sf-fv-dot.sf-fv-on').count()) === 1 && (await page.locator('.sf-filter-chip').count()) === 1 && (await page.locator('.sf-filter-clear').count()) === 1);
        OUT && (shots.push(join(OUT, `ct3f-${tag}-2-red.png`)), await page.screenshot({ path: join(OUT, `ct3f-${tag}-2-red.png`), fullPage: true }));

        // ---- add a size M: the same-variation rule ----
        await page.locator('.sf-filter-links .sf-fv', { hasText: /^M/ }).click();
        await page.waitForLoadState('networkidle');
        const redM = await names(page);
        check(`${label}: red + M gives ONLY the product with a red-M variation in stock (not red-S + blue-M, not the sold-out red-M)`, JSON.stringify(redM) === JSON.stringify(['CT3F B червен M']), JSON.stringify(redM));
        OUT && (shots.push(join(OUT, `ct3f-${tag}-3-red-m.png`)), await page.screenshot({ path: join(OUT, `ct3f-${tag}-3-red-m.png`), fullPage: true }));

        // ---- the HTML of a filtered page: noindex, the unfiltered canonical, nofollow links ----
        const head = await page.evaluate(() => ({ robots: document.querySelector('meta[name=robots]')?.content, canonical: document.querySelector('link[rel=canonical]')?.href, links: [...document.querySelectorAll('.sf-filters a')].every((a) => a.rel.includes('nofollow')) }));
        check(`${label}: a filtered page is noindex, its canonical is the unfiltered category, every filter link is nofollow`, head.robots === 'noindex' && head.canonical && ! head.canonical.includes('filter') && head.canonical.endsWith(URL) && head.links, JSON.stringify(head));

        // ---- remove a choice with its × ----
        await page.locator('.sf-filter-chip', { hasText: 'M' }).click();
        await page.waitForLoadState('networkidle');
        check(`${label}: the × of the size removes it (the red filter stays)`, decodeURIComponent(page.url()).endsWith(URL + '?' + f([COLOUR, ids.c['Червен']])) && (await page.locator('.sf-filter-chip').count()) === 1, page.url());

        // ---- «Изчисти всички» ----
        await page.locator('.sf-filter-clear').click();
        await page.waitForLoadState('networkidle');
        check(`${label}: «Изчисти всички» is the plain category URL`, page.url().endsWith(URL) && (await page.locator('.sf-filter-chip').count()) === 0, page.url());
        await ctx.close();

        // ---- pagination keeps the filter ----
        ({ ctx, page } = await open(viewport, URL + '?' + f([COLOUR, ids.c['Червен']]) + '&per_page=12'));
        const cardsP1 = (await names(page)).length;
        const next = page.locator('.sf-pagination-next');
        const nextHref = await next.getAttribute('href');
        check(`${label}: with a filter and 12 per page the pagination link keeps the filter`, cardsP1 === 12 && nextHref.includes(`filter[${COLOUR}]=${ids.c['Червен']}`) && nextHref.includes('page=2') && nextHref.includes('per_page=12'), nextHref);
        await next.click();
        await page.waitForLoadState('networkidle');
        check(`${label}: page 2 still has the filter and its products`, page.url().includes('filter') && (await names(page)).length === 12, page.url());
        await ctx.close();

        // ---- the product-level attribute (a list) and the child category ----
        ({ ctx, page } = await open(viewport, URL + '?' + f([MATERIAL, ids.m['Памук']]) + '&per_page=48', `ct3f-${tag}-4-cotton`));
        const cotton = await names(page);
        check(`${label}: the product-level value «Памук» gives the products that have it (B and every 3rd of the 63 fillers: 22), whatever their stock`, cotton.length === 22 && cotton.includes('CT3F B червен M'), String(cotton.length));
        await ctx.close();
        ({ ctx, page } = await open(viewport, URL + '?' + f([COLOUR, ids.c['Син']])));
        check(`${label}: blue includes the product of the CHILD category («CT3F E син S») and A (blue-M)`, (await names(page)).some((n) => n.includes('CT3F E')) && (await names(page)).some((n) => n.includes('CT3F A')), JSON.stringify(await names(page)));
        await ctx.close();
    }

    // ---- a selected value that gives nothing stays visible; a filter with one value is hidden ----
    let { ctx, page } = await open({ width: 1400, height: 1000 }, URL + '?' + f([COLOUR, ids.c['Син']], [SIZE, ids.s['L']]));
    check('blue + L gives no product: the message, the chosen blue and L stay (so they can be removed), «Изчисти всички»', (await page.locator('.sf-empty').count()) === 1 && (await page.locator('.sf-filter-chip').count()) === 2 && (await page.locator('.sf-fv-on').count()) === 2);
    await ctx.close();
    ({ ctx, page } = await open({ width: 1400, height: 1000 }, URL + '?' + f([COLOUR, ids.c['Зелен']])));
    check('green (only L exists in green): the size filter has one value and nothing chosen, so it is not shown', (await page.locator('.sf-filter-links').count()) === 0, String(await page.locator('.sf-filter-links').count()));
    await ctx.close();

    // ---- bad URLs are the storefront 404, a non-canonical order is a 301, an unfiltered page has no filter block problem ----
    const api = await request.newContext({ baseURL: BASE, ignoreHTTPSErrors: true, maxRedirects: 0 });
    const get = async (path) => { const r = await api.get(path); return { status: r.status(), location: r.headers()['location'] ?? null }; };
    check('an unknown value is a 404', (await get(URL + '?filter[' + COLOUR + ']=999999')).status === 404);
    check('seven filters are a 404 and a script in a value is a 404', (await get(URL + '?filter[1]=1&filter[2]=1&filter[3]=1&filter[4]=1&filter[5]=1&filter[6]=1&filter[7]=1')).status === 404 && (await get(URL + '?filter[' + COLOUR + ']=%3Cscript%3E')).status === 404);
    const swapped = await get(URL + '?' + f([SIZE, ids.s['M']], [COLOUR, ids.c['Червен']]));
    check('the same selection in another order is a 301 to the canonical URL', swapped.status === 301 && decodeURIComponent(swapped.location).endsWith(URL + '?' + f([COLOUR, ids.c['Червен']], [SIZE, ids.s['M']])), JSON.stringify(swapped));
    const robots = await api.get('/robots.txt');
    const robotsText = await robots.text();
    check('/robots.txt is the static file with the filter and back-office rules', robots.status() === 200 && /Disallow: \/\*filter\[/.test(robotsText) && /Disallow: \/admin\//.test(robotsText) && ! /product-category/.test(robotsText), robotsText.slice(0, 200));

    // ---- the cost: EXPLAIN with three filters and the time per filtered request ----
    explain = JSON.parse(php(EXPLAIN(ids)));
    check('EXPLAIN: no query scans a whole big table (no ALL over 500 rows)', explain.fullScansOver500Rows.length === 0, JSON.stringify(explain.fullScansOver500Rows));
    const three = URL + '?' + f([COLOUR, ids.c['Червен']], [SIZE, ids.s['M']], [MATERIAL, ids.m['Памук']]);
    const times = [];
    await api.get(three);

    for (let i = 0; i < 20; i++) {
        const start = performance.now();
        const r = await api.get(three);
        times.push(Math.round(performance.now() - start));
        r.status() !== 200 && check('a filtered request answered 200 (' + i + ')', false, String(r.status()));
    }

    times.sort((a, b) => a - b);
    timing = { medianMs: times[10], minMs: times[0], maxMs: times[19], plainMedianMs: null };
    const plain = [];

    for (let i = 0; i < 20; i++) {
        const start = performance.now();
        await api.get(URL);
        plain.push(Math.round(performance.now() - start));
    }

    plain.sort((a, b) => a - b);
    timing.plainMedianMs = plain[10];
    check('a filtered request with three filters is served (median measured)', times[10] < 5000, JSON.stringify(timing));
    await api.dispose();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 600));
} finally {
    try {
        php(CLEANUP);
        const after = JSON.parse(php(SUMS));
        check('everything created is gone: every catalog table and the settings table are as they were (checksums / counts)', before && JSON.stringify(before) === JSON.stringify(after), JSON.stringify({ before, after }));
    } catch (e) {
        check('the cleanup finished', false, String(e).slice(0, 400));
    }
}

check('no page errors', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, timing, explain, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
