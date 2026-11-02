// A REAL-BROWSER run of CD-1d: the side panel of the category page — a grey separator under every filter group, and the width of the panel (a closed choice of three: wide 17rem = what it always was and the
// maximum, medium 15rem, narrow 13rem) on the left and on the right; the bar layout has no width class; a phone check. NOT part of `php artisan test`: run by hand against a dev site. It creates a throw-away
// category («cd1c-cat», 60 products, three filterable attributes) and changes the published «Каталог» design ONLY through the application's own publish path (PageDesignStore); at the end it puts every
// `site_settings` row back EXACTLY (update by id: value and timestamps; no row is deleted and re-inserted) and verifies checksums. Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/cd-1d.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node cd-1d.mjs');
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

// the published «Каталог» design through the application's own save path
const DESIGN = (layout, side, width) => String.raw`
use App\Storefront\PageDesign\PageDesign; use App\Storefront\PageDesign\PageDesignStore;
[$clean, $errors] = PageDesign::validate(PageDesign::CATALOG, ['layout' => '${layout}', 'side' => '${side}', 'sideWidth' => '${width}'] + PageDesign::defaults(PageDesign::CATALOG), false);
PageDesignStore::publish(PageDesign::CATALOG, $clean);
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo json_encode($errors);`;

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
    const ids = JSON.parse(php(SETUP));
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
    const tok = (page, v, parent = '.sf-fdrawer') => page.evaluate(([value, sel]) => { const p = document.createElement('i'); p.style.borderTopColor = value; document.querySelector(sel).append(p); const c = getComputedStyle(p).borderTopColor; p.remove(); return c; }, [v, parent]);
    const setDesign = (layout, side, width) => { const errors = php(DESIGN(layout, side, width)); check(`the design «${layout} / ${side} / ${width}» was published through the application (no validation errors)`, errors === '[]', errors); };

    // ============================================================ 1280: the widths, left side
    const px = { wide: 272, medium: 240, narrow: 208 };

    for (const width of ['wide', 'medium', 'narrow']) {
        setDesign('sidebar', 'left', width);
        const { ctx, page, shot } = await open(1280, URL, `cd1d-${width}-left-1280`);
        const cls = await page.$eval('.sf-category', (e) => e.className);
        const body = await page.$eval('.sf-cat-body', (e) => ({ cols: getComputedStyle(e).gridTemplateColumns.split(' ')[0], x: e.getBoundingClientRect().x }));
        const panel = await page.$eval('.sf-fdrawer', (e) => { const r = e.getBoundingClientRect(); return { w: Math.round(r.width * 10) / 10, x: r.x }; });
        check(`1280 ${width}: the section carries sf-cat-sidew-${width}`, cls.split(' ').includes(`sf-cat-sidew-${width}`) && ! cls.includes('sf-cat-sidew-' + (width === 'wide' ? 'medium' : 'wide')), cls);
        check(`1280 ${width}: the panel column is ${px[width]}px (${px[width] / 16}rem)`, near(parseFloat(body.cols), px[width], 1) && near(panel.w, px[width], 1), JSON.stringify({ body, panel }));
        check(`1280 ${width}: the panel is on the left of the list and nothing scrolls sideways`, panel.x < 400 && await noOverflow(page));
        const overflow = await page.$$eval('.sf-fgroup', (els) => els.map((e) => e.scrollWidth > e.clientWidth + 1));
        check(`1280 ${width}: no filter group overflows its panel`, overflow.length >= 2 && overflow.every((o) => ! o), JSON.stringify(overflow));
        const names = await page.$$eval('.sf-fgroup-summary', (els) => els.map((e) => ({ t: e.textContent.trim().replace(/\s+/g, ' '), h: Math.round(e.getBoundingClientRect().height) })));
        check(`1280 ${width}: the filter names stay on one or two lines (no badly broken labels)`, names.every((n) => n.h <= 48), JSON.stringify(names));

        if (width === 'wide') {
            // the separators: a 1px muted-grey line under EVERY group, the last one too; the first has no extra top line
            const muted = await tok(page, 'var(--sf-pdp-muted)');
            const line = await tok(page, 'var(--sf-pdp-line)');
            const groups = await page.$$eval('.sf-fgroup', (els) => els.map((e) => { const cs = getComputedStyle(e); return { bw: cs.borderBottomWidth, bs: cs.borderBottomStyle, bc: cs.borderBottomColor, tw: cs.borderTopWidth, pt: cs.paddingTop, pb: cs.paddingBottom }; }));
            check('1280: every filter group (the last one included) has a 1px solid bottom line in the muted grey token, the first has no top line, the padding keeps the line off the text', groups.length >= 3 && groups.every((g) => g.bw === '1px' && g.bs === 'solid' && g.bc === muted && g.tw === '0px' && g.pt === '12px' && g.pb === '12px') && muted !== line, JSON.stringify({ muted, was: line, groups: groups.slice(0, 2), count: groups.length }));
            const sortHidden = await page.$eval('.sf-fgroup-sort', (e) => getComputedStyle(e).display === 'none').catch(() => true);
            check('1280: the sort row is not part of the side panel (it is the control above the list), so it has no separator', sortHidden);
        }

        await shot(`cd1d-${width}-left-1280-b`);
        await ctx.close();
    }

    // ============================================================ 1280: the right side with the narrowest width
    setDesign('sidebar', 'right', 'narrow');
    let { ctx, page, shot } = await open(1280, URL, 'cd1d-narrow-right-1280');
    const right = await page.evaluate(() => { const p = document.querySelector('.sf-fdrawer').getBoundingClientRect(); const m = document.querySelector('.sf-cat-main').getBoundingClientRect(); return { panelW: Math.round(p.width), panelX: Math.round(p.x), mainX: Math.round(m.x) }; });
    check('1280 narrow, right side: the 208px panel is on the right of the list', right.panelW === 208 && right.panelX > right.mainX && await noOverflow(page), JSON.stringify(right));
    await ctx.close();

    // ============================================================ 1280: the bar layout (no width class, the bar as before)
    setDesign('full', 'left', 'narrow');
    ({ ctx, page, shot } = await open(1280, URL, 'cd1d-bar-1280'));
    const barCls = await page.$eval('.sf-category', (e) => e.className);
    check('1280 bar layout: no width class and the bar is shown (the stored width has no effect)', ! barCls.includes('sf-cat-sidew') && (await page.locator('.sf-cbar').isVisible()) && ! (await page.locator('.sf-fdrawer').first().isVisible()), barCls);
    await ctx.close();

    // ============================================================ 360: the phone is not touched by the width
    setDesign('sidebar', 'left', 'narrow');
    ({ ctx, page, shot } = await open(360, URL, 'cd1d-phone-360'));
    const phone = await page.evaluate(() => ({ trigger: !! document.querySelector('.sf-ftrigger') && getComputedStyle(document.querySelector('.sf-ftrigger')).display !== 'none', bodyDisplay: getComputedStyle(document.querySelector('.sf-cat-body')).display, panelCols: getComputedStyle(document.querySelector('.sf-cat-body')).gridTemplateColumns }));
    check('360: the filters are the drawer behind the trigger, the page body is a plain block (no panel column) and nothing scrolls sideways', phone.trigger && phone.bodyDisplay === 'block' && await noOverflow(page), JSON.stringify(phone));
    await page.locator('.sf-ftrigger').click();
    await sleep(500);
    const rowRule = await page.$$eval('.sf-fgroup', (els) => els.map((e) => ({ w: getComputedStyle(e).borderBottomWidth, c: getComputedStyle(e).borderBottomColor })));
    const phoneLine = await tok(page, 'var(--sf-pdp-line)');
    check('360: the drawer rows keep their own look: a 1px rule in the light line token (CD-1d changes nothing here)', rowRule.length >= 2 && rowRule.every((r) => r.w === '1px' && r.c === phoneLine), JSON.stringify({ phoneLine, rowRule: rowRule.slice(0, 2) }));
    await shot('cd1d-phone-drawer-360');
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
