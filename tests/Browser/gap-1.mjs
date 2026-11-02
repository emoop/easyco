// A REAL-BROWSER run of GAP-1: the gap between the product cards of the CATEGORY listing is a setting of «Дизайн» → «Страници и карти» (a column gap and a row gap, a closed list of px steps, default 16).
// It measures what the grid really draws (computed gaps and the cards' boxes at 1280 and 360px) for the default, a solid grid (0 / 0) and an airy one (32 / 0), the card images' `sizes`, and that the HOME page
// grid is untouched. NOT part of `php artisan test`: run by hand against a dev site. It creates a throw-away category («cd1c-cat», 60 products) and changes the published «Страници и карти» and «Каталог»
// design ONLY through the application's own publish path (PageDesignStore); at the end it puts every `site_settings` row back EXACTLY (update by id: value and timestamps; no row is deleted and
// re-inserted; a row the run created is removed), removes what it created and verifies checksums. Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/gap-1.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node gap-1.mjs');
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


const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/cd1d_backup.json', json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo 'ok';`;

// the published «Страници и карти» gaps and the category layout (full width, 4 columns) through the application's own save path; a gap of «default» publishes 16 (which writes no variable at all)
const DESIGN = (gapX, gapY) => String.raw`
use App\Storefront\PageDesign\PageDesign; use App\Storefront\PageDesign\PageDesignStore;
[$page, $e1] = PageDesign::validate(PageDesign::PAGE, ['gapX' => '${gapX}', 'gapY' => '${gapY}'] + PageDesign::defaults(PageDesign::PAGE), false);
PageDesignStore::publish(PageDesign::PAGE, $page);
[$catalog, $e2] = PageDesign::validate(PageDesign::CATALOG, ['layout' => 'full', 'cols' => '4', 'cols_phone' => '2'] + PageDesign::defaults(PageDesign::CATALOG), false);
PageDesignStore::publish(PageDesign::CATALOG, $catalog);
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo json_encode([$e1, $e2]);`;

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode(['settings' => $sum('site_settings'), 'defs' => $sum('catalog_attribute_definitions'), 'values' => $sum('catalog_attribute_values'), 'cats' => $sum('catalog_categories'), 'products' => DB::table('catalog_products')->count(), 'variations' => DB::table('catalog_variations')->count(), 'stock' => DB::table('stock_levels')->count(), 'pa' => DB::table('catalog_product_attributes')->count(), 'vv' => DB::table('catalog_variation_attribute_values')->count(), 'pc' => DB::table('catalog_product_categories')->count()]);`;

const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$cat = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'CD1C категория', 'slug' => 'cd1c-cat', 'created_at' => $now, 'updated_at' => $now]);
$def = function ($code, $name, $display, $values) use ($now) {
  $id = DB::table('catalog_attribute_definitions')->insertGetId(['code' => $code, 'name' => $name, 'type' => 'select', 'is_filterable' => 1, 'filter_display' => $display, 'created_at' => $now, 'updated_at' => $now]);
  $ids = [];
  foreach ($values as $i => [$label, $colour]) { $ids[$label] = DB::table('catalog_attribute_values')->insertGetId(['attribute_definition_id' => $id, 'value' => $label, 'sort_order' => $i, 'colour' => $colour, 'created_at' => $now, 'updated_at' => $now]); }
  return [$id, $ids];
};
[$colour, $c] = $def('cd1c-colour', 'Цвят', 'swatches', [['Червен', '#cc0000'], ['Син', '#0000cc'], ['Зелен', '#00aa44'], ['Жълт', '#e6c200'], ['Черен', '#111111']]);
[$size, $s] = $def('cd1c-size', 'Размер', 'links', [['XS', null], ['S', null], ['M', null], ['L', null], ['XL', null]]);
[$material, $m] = $def('cd1c-material', 'Материя', 'list', [['Памук', null], ['Вълна', null], ['Лен', null], ['Коприна', null], ['Полиестер', null], ['Вискоза', null], ['Кашмир', null], ['Лиоцел', null], ['Еластан', null]]);
$colours = ['Червен', 'Син', 'Зелен', 'Жълт', 'Черен']; $sizes = ['XS', 'S', 'M', 'L', 'XL']; $mats = array_keys($m);
for ($i = 1; $i <= 60; $i++) {
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'variable', 'name' => "CD1C продукт $i", 'slug' => "cd1c-$i", 'base_sku' => "CD1C-$i", 'status' => 'active', 'catalog_visibility' => 'visible', 'is_featured' => 0, 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => now()->addDays(3)->subMinutes($i)]);
  foreach ([[$colours[$i % 5], $sizes[$i % 5]], [$colours[($i + 1) % 5], $sizes[($i + 2) % 5]]] as $k => [$cv, $sv]) {
    $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => $k, 'type' => 'standard', 'status' => 'active', 'attribute_signature' => hash('sha256', "cd1c-$i-$k"), 'sku' => "CD1C-V$i-$k", 'is_visible' => 1, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('catalog_variation_attribute_values')->insert([['variation_id' => $vid, 'attribute_definition_id' => $colour, 'attribute_value_id' => $c[$cv], 'created_at' => $now, 'updated_at' => $now], ['variation_id' => $vid, 'attribute_definition_id' => $size, 'attribute_value_id' => $s[$sv], 'created_at' => $now, 'updated_at' => $now]]);
    DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 3, 'created_at' => $now, 'updated_at' => $now]);
  }
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  DB::table('catalog_product_attributes')->insert(['product_id' => $pid, 'attribute_definition_id' => $material, 'is_variation_axis' => 0, 'attribute_value_id' => $m[$mats[$i % 9]], 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now]);
}
app(App\Storefront\Filters\FilterCatalog::class)::forget();
echo json_encode(['cat' => $cat, 'colour' => $colour, 'size' => $size, 'material' => $material, 'c' => $c, 's' => $s, 'm' => $m]);`;


const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/cd1d_backup.json'), true);
DB::table('site_settings')->whereNotIn('id', array_column($bak, 'id'))->delete();
foreach ($bak as $row) { DB::table('site_settings')->where('id', $row['id'])->update(['key' => $row['key'], 'value' => $row['value'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]); if (! DB::table('site_settings')->where('id', $row['id'])->exists()) { DB::table('site_settings')->insert($row); } }
$pids = DB::table('catalog_products')->where('slug', 'like', 'cd1c-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_attributes')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
$defs = DB::table('catalog_attribute_definitions')->where('code', 'like', 'cd1c-%')->pluck('id');
DB::table('catalog_attribute_values')->whereIn('attribute_definition_id', $defs)->delete();
DB::table('catalog_attribute_definitions')->whereIn('id', $defs)->delete();
DB::table('catalog_categories')->where('slug', 'cd1c-cat')->delete();
app(App\Storefront\Filters\FilterCatalog::class)::forget();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo 'restored';`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const consoleErrors = [];
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const near = (a, b, t = 1.5) => Math.abs(a - b) <= t;
// a click that leaves the page: wait until the address changed, then until the new page is quiet
const nav = async (page, locator) => { const from = page.url(); await locator.click(); await page.waitForFunction((u) => location.href !== u, from, { timeout: 20000 }); await page.waitForLoadState('networkidle'); };
// the category page's layout as the published design holds it (CD-1c.1 checks the side panel); the appearance cache is refreshed in the same call

let before = null;
let backedUp = false;

try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    backedUp = true;
    JSON.parse(php(SETUP));
    const URL = '/product-category/cd1c-cat';

    const open = async (width, path, shotName = null) => {
        const ctx = await browser.newContext({ viewport: { width, height: width < 600 ? 800 : 1000 }, ignoreHTTPSErrors: true, ...(width < 600 ? { hasTouch: true, isMobile: true } : {}) });
        const page = await ctx.newPage();
        page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        await page.goto(BASE + path, { waitUntil: 'networkidle' });
        const shot = async (name) => { if (OUT) { const p = join(OUT, name + '.png'); shots.push(p); await page.screenshot({ path: p, fullPage: false }); } };
        shotName && await shot(shotName);

        return { ctx, page, shot };
    };
    const noOverflow = (page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
    const setDesign = (x, y) => { const errors = php(DESIGN(x, y)); check(`the design «column gap ${x}, row gap ${y}» was published through the application (no validation errors)`, errors === '[[],[]]', errors); };

    // what the grid really draws: the computed gaps, the cards' own boxes, the first card image's sizes
    const grid = (page, sel) => page.evaluate((s) => {
        const g = document.querySelector(s);
        const cs = getComputedStyle(g);
        const cards = [...g.querySelectorAll(':scope > .sf-card')].slice(0, 12).map((c) => { const r = c.getBoundingClientRect(); return { x: Math.round(r.x * 10) / 10, y: Math.round(r.y * 10) / 10, r: Math.round(r.right * 10) / 10, b: Math.round(r.bottom * 10) / 10 }; });
        const img = g.querySelector('img.sf-image, .sf-card-image img');

        return { cx: cs.columnGap, cy: cs.rowGap, cards, sizes: img ? img.getAttribute('sizes') : null };
    }, sel);
    // the horizontal distance between two neighbours of the same row, the vertical distance between the first two rows
    const spacing = (g) => {
        const first = g.cards[0];
        const next = g.cards.find((c) => c.y === first.y && c.x > first.x);
        const below = g.cards.find((c) => c.y > first.y);

        return { dx: next ? Math.round((next.x - first.r) * 10) / 10 : null, dy: below ? Math.round((below.y - first.b) * 10) / 10 : null, perRow: g.cards.filter((c) => c.y === first.y).length };
    };

    // ============================================================ the default (16 / 16): what it always was on a desktop; the phone goes from 12px to 16px
    setDesign(16, 16);
    let { ctx, page, shot } = await open(1280, URL, 'gap1-default-1280');
    let g = await grid(page, '.sf-category .sf-grid');
    let sp = spacing(g);
    check('1280 default: the gaps are 16px / 16px and the cards are 4 in a row, 16px apart', g.cx === '16px' && g.cy === '16px' && sp.perRow === 4 && near(sp.dx, 16, 1) && near(sp.dy, 16, 4), JSON.stringify({ g: { cx: g.cx, cy: g.cy }, sp }));
    check('1280 default: no gap variable is written (the stylesheet fallback decides)', await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--sf-grid-gap-x') === '' && getComputedStyle(document.documentElement).getPropertyValue('--sf-grid-gap-y') === ''), '');
    await ctx.close();

    ({ ctx, page, shot } = await open(360, URL, 'gap1-default-360'));
    g = await grid(page, '.sf-category .sf-grid');
    sp = spacing(g);
    check('360 default: the phone gap is now 16px (it was 12px), 2 cards a row, nothing scrolls sideways', g.cx === '16px' && g.cy === '16px' && sp.perRow === 2 && await noOverflow(page), JSON.stringify({ g: { cx: g.cx, cy: g.cy }, sp }));
    await ctx.close();

    // ============================================================ a solid grid: 0 / 0
    setDesign(0, 0);
    ({ ctx, page, shot } = await open(1280, URL, 'gap1-0-0-1280'));
    g = await grid(page, '.sf-category .sf-grid');
    sp = spacing(g);
    check('1280 gaps 0 / 0: a SOLID grid — the cards touch (no space between columns or rows), 4 in a row, nothing scrolls sideways', g.cx === '0px' && g.cy === '0px' && near(sp.dx, 0, 0.6) && near(sp.dy, 0, 0.6) && sp.perRow === 4 && await noOverflow(page), JSON.stringify({ g: { cx: g.cx, cy: g.cy }, sp }));
    await ctx.close();

    ({ ctx, page, shot } = await open(360, URL, 'gap1-0-0-360'));
    g = await grid(page, '.sf-category .sf-grid');
    sp = spacing(g);
    check('360 gaps 0 / 0: the setting applies on a phone too — the two cards touch, no sideways scroll', g.cx === '0px' && g.cy === '0px' && near(sp.dx, 0, 0.6) && near(sp.dy, 0, 0.6) && sp.perRow === 2 && await noOverflow(page), JSON.stringify({ g: { cx: g.cx, cy: g.cy }, sp }));
    await ctx.close();

    // ============================================================ airy: column gap 32, row gap 0 (the two axes are independent)
    setDesign(32, 0);
    ({ ctx, page, shot } = await open(1280, URL, 'gap1-32-0-1280'));
    g = await grid(page, '.sf-category .sf-grid');
    sp = spacing(g);
    check('1280 column gap 32 / row gap 0: 32px between columns, rows touch, 4 in a row, no overlap and no sideways scroll', g.cx === '32px' && g.cy === '0px' && near(sp.dx, 32, 1) && near(sp.dy, 0, 0.6) && sp.perRow === 4 && await noOverflow(page), JSON.stringify({ g: { cx: g.cx, cy: g.cy }, sp }));
    const real = g.cards[0].r - g.cards[0].x;
    const claimed = (1280 - 7 * 16) / 4;
    check('1280 column gap 32: the sizes value is NOT smaller than the real card (the browser never picks a blurry image)', claimed >= real - 1, JSON.stringify({ real, claimed }));
    await ctx.close();

    ({ ctx, page, shot } = await open(360, URL, 'gap1-32-0-360'));
    g = await grid(page, '.sf-category .sf-grid');
    sp = spacing(g);
    check('360 column gap 32 / row gap 0: 2 cards a row with 32px between them, nothing scrolls sideways', g.cx === '32px' && g.cy === '0px' && near(sp.dx, 32, 1) && sp.perRow === 2 && await noOverflow(page), JSON.stringify({ g: { cx: g.cx, cy: g.cy }, sp }));
    await ctx.close();

    // ============================================================ the home page keeps its own grid gap whatever the setting says
    ({ ctx, page, shot } = await open(1280, '/', 'gap1-home-1280'));
    g = await grid(page, '.sf-grid');
    check('home 1280: the home grid keeps its own 16px gap (the setting is the category listing\'s only)', g.cx === '16px' && g.cy === '16px', JSON.stringify({ cx: g.cx, cy: g.cy }));
    await ctx.close();
    ({ ctx, page, shot } = await open(360, '/', 'gap1-home-360'));
    g = await grid(page, '.sf-grid');
    check('home 360: the home grid keeps its own 12px gap', g.cx === '12px' && g.cy === '12px', JSON.stringify({ cx: g.cx, cy: g.cy }));
    await ctx.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (backedUp) {
        try {
            php(RESTORE);
            const after = JSON.parse(php(SUMS));
            check('everything is back: the settings table (exact rows) and the catalog tables are as they were (checksums / counts)', before && JSON.stringify(before) === JSON.stringify(after), JSON.stringify({ before, after }));
        } catch (e) {
            check('the restore finished', false, String(e).slice(0, 400));
        }
    }
}

check('no page errors', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
