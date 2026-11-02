// A REAL-BROWSER run of CT-4: the built-in category filters — brand, season and tag — in the desktop bar (a list, links, and the tags as BUTTONS = chips), the side panel and the phone drawer; the canonical
// URL (brand, season, the attributes, tag), the 404 / 301 answers and a switched-off filter. NOT part of `php artisan test`: run by hand against a dev site. It creates a throw-away category («cd1c-cat», 60
// products, three filterable attributes) plus throw-away brands, seasons and tags (slugs «ct4-…») and changes the published «Каталог» design ONLY through the application's own publish path (PageDesignStore);
// at the end it puts every `site_settings` row back EXACTLY (update by id: value and timestamps; no row is deleted and re-inserted), removes what it created and verifies checksums. Prints a JSON report;
// exit code 1 when a check failed.
//
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/ct-4.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node ct-4.mjs');
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

// the published «Каталог» design through the application's own save path (the three built-in filter choices are the last three arguments)
const DESIGN = (layout, brand, season, tag) => String.raw`
use App\Storefront\PageDesign\PageDesign; use App\Storefront\PageDesign\PageDesignStore;
[$clean, $errors] = PageDesign::validate(PageDesign::CATALOG, ['layout' => '${layout}', 'filterBrand' => '${brand}', 'filterSeason' => '${season}', 'filterTag' => '${tag}'] + PageDesign::defaults(PageDesign::CATALOG), false);
PageDesignStore::publish(PageDesign::CATALOG, $clean);
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo json_encode($errors);`;

// throw-away brands, seasons and tags (slugs «ct4-…») given to the 60 products of «cd1c-cat»: brand k%3, season k%2, tags k%4 and (k+1)%4
const SETUP2 = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$b = []; foreach (['Алфа', 'Бета', 'Гама'] as $i => $n) { $b[] = DB::table('catalog_brands')->insertGetId(['name' => "CT4 $n", 'slug' => "ct4-brand-$i", 'created_at' => $now, 'updated_at' => $now]); }
$s = []; foreach (['Пролет', 'Лято'] as $i => $n) { $s[] = DB::table('catalog_seasons')->insertGetId(['name' => "CT4 $n", 'slug' => "ct4-season-$i", 'created_at' => $now, 'updated_at' => $now]); }
$t = []; foreach (['Нови', 'Разпродажба', 'Бестселър', 'Подарък'] as $i => $n) { $t[] = DB::table('catalog_tags')->insertGetId(['name' => "CT4 $n", 'slug' => "ct4-tag-$i", 'created_at' => $now, 'updated_at' => $now]); }
foreach (DB::table('catalog_products')->where('slug', 'like', 'cd1c-%')->orderBy('id')->pluck('id')->all() as $k => $pid) {
  DB::table('catalog_products')->where('id', $pid)->update(['brand_id' => $b[$k % 3], 'season_id' => $s[$k % 2]]);
  foreach ([$k % 4, ($k + 1) % 4] as $ti) { DB::table('catalog_product_tags')->insert(['product_id' => $pid, 'tag_id' => $t[$ti]]); }
}
App\Storefront\Filters\FilterCatalog::forget();
echo json_encode(['b' => $b, 's' => $s, 't' => $t]);`;

// runs AFTER the cd1c products are gone (their tag rows go with them)
const RESTORE_X = String.raw`
use Illuminate\Support\Facades\DB;
DB::table('catalog_tags')->where('slug', 'like', 'ct4-%')->delete();
DB::table('catalog_brands')->where('slug', 'like', 'ct4-%')->delete();
DB::table('catalog_seasons')->where('slug', 'like', 'ct4-%')->delete();
App\Storefront\Filters\FilterCatalog::forget();
echo 'restored-x';`;

const SUMSX = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode(['brands' => $sum('catalog_brands'), 'seasons' => $sum('catalog_seasons'), 'tags' => $sum('catalog_tags'), 'pt' => DB::table('catalog_product_tags')->count()]);`;

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

const sums = () => ({ ...JSON.parse(php(SUMS)), ...JSON.parse(php(SUMSX)) });
let before = null;
let backedUp = false;

try {
    before = sums();
    php(BACKUP);
    backedUp = true;
    const ids = JSON.parse(php(SETUP));
    const x = JSON.parse(php(SETUP2));
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
    const qs = (page) => decodeURIComponent(new globalThis.URL(page.url()).search);
    const noOverflow = (page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
    const tok = (page, v, parent) => page.evaluate(([value, sel]) => { const p = document.createElement('i'); p.style.borderTopColor = value; document.querySelector(sel).append(p); const c = getComputedStyle(p).borderTopColor; p.remove(); return c; }, [v, parent]);
    const setDesign = (layout, brand, season, tag) => { const errors = php(DESIGN(layout, brand, season, tag)); check(`the design «${layout} / brand ${brand} / season ${season} / tag ${tag}» was published through the application (no validation errors)`, errors === '[]', errors); };
    const chipMetrics = (page, sel) => page.$$eval(sel, (els) => els.map((e) => { const cs = getComputedStyle(e); const r = e.getBoundingClientRect(); return { x: Math.round(r.x * 10) / 10, y: Math.round(r.y), w: Math.round(r.width * 10) / 10, h: Math.round(r.height), pl: cs.paddingLeft, pr: cs.paddingRight, fs: cs.fontSize, bw: cs.borderTopWidth, bc: cs.borderTopColor, bg: cs.backgroundColor, rad: cs.borderTopLeftRadius, fw: cs.fontWeight, sh: cs.boxShadow }; }));

    // ============================================================ 1280, the bar: brand = list, season = links, tag = buttons
    setDesign('full', 'list', 'links', 'buttons');
    let { ctx, page, shot } = await open(1280, URL, 'ct4-bar-closed-1280');
    const names = await page.$$eval('.sf-bctl', (els) => els.map((e) => e.querySelector('.sf-bctl-text').textContent.trim().replace(/\s+/g, ' ')));
    check('1280: the controls are the sort, Марка, Сезон, the attributes (Цвят, Размер, Материя) and Етикет LAST', names.slice(1).join('|') === 'Марка|Сезон|Цвят|Размер|Материя|Етикет', JSON.stringify(names));
    check('1280: no horizontal scroll', await noOverflow(page));

    await page.locator('.sf-bctl-sum', { hasText: 'Марка' }).click();
    const brandVals = await page.$$eval('.sf-bctl[open] .sf-bv', (els) => els.map((e) => ({ t: e.textContent.trim(), c: e.className })));
    check('1280: brand (list): the three brands, each a list row', brandVals.length === 3 && brandVals.every((v) => v.c.includes('sf-bv-list')) && brandVals.map((v) => v.t).join('|') === 'CT4 Алфа|CT4 Бета|CT4 Гама', JSON.stringify(brandVals));
    await page.locator('.sf-bctl-sum', { hasText: 'Сезон' }).click();
    check('1280: season (links): two text links', (await page.$$eval('.sf-bctl[open] .sf-bv-link', (els) => els.length)) === 2);

    // the tag as buttons
    await page.locator('.sf-bctl-sum', { hasText: 'Етикет' }).click();
    const chips = await chipMetrics(page, '.sf-bctl[open] .sf-bv-chip');
    const muted = await tok(page, 'var(--sf-pdp-muted)', '.sf-cbar');
    const ink = await tok(page, 'var(--sf-pdp-text)', '.sf-cbar');
    const cardRadius = await page.$eval('.sf-card', (e) => getComputedStyle(e).borderTopLeftRadius);
    check('1280: the tag shows 4 chips: ≥ 36px high, padding 0 14px, 13px text, 1px muted-token border, white, the radius of the product cards', chips.length === 4 && chips.every((c) => c.h >= 36 && c.pl === '14px' && c.pr === '14px' && c.fs === '13px' && c.bw === '1px' && c.bc === muted && c.bg === 'rgb(255, 255, 255)' && c.rad === cardRadius), JSON.stringify({ muted, cardRadius, first: chips[0] }));
    const sameRow = chips.filter((c) => c.y === chips[0].y);
    const gap = sameRow.length >= 2 ? Math.round((sameRow[1].x - (sameRow[0].x + sameRow[0].w)) * 10) / 10 : null;
    check('1280: the chips sit side by side (several in a row) with an 8px gap, and the panel does not overflow', sameRow.length >= 2 && near(gap, 8, 1) && await page.$eval('.sf-bctl[open] .sf-bctl-panel', (e) => e.scrollWidth <= e.clientWidth + 1), JSON.stringify({ gap, rows: [...new Set(chips.map((c) => c.y))].length }));
    await shot('ct4-bar-tags-open-1280');

    // keyboard focus ring on a chip
    await page.keyboard.press('Tab');
    const ring = await page.evaluate(() => { const el = document.querySelector('.sf-bctl[open] .sf-bv-chip'); el.focus(); const cs = getComputedStyle(el); return { o: cs.outlineWidth + ' ' + cs.outlineStyle, off: cs.outlineOffset }; });
    check('1280: keyboard focus on a chip is a 2px solid ring with a 2px offset', /^2px solid/.test(ring.o) && ring.off === '2px', JSON.stringify(ring));

    // choose a tag: the value link applies at once, the control says «Етикет: Име», ONE «Изчисти»
    await nav(page, page.locator('.sf-bctl[open] .sf-bv-chip', { hasText: 'CT4 Нови' }));
    check('1280: choosing a chip applies at once: filter[tag]=id (canonical)', qs(page) === `?filter[tag]=${x.t[0]}`, qs(page));
    const tagCtl = await page.locator('.sf-bctl-sum', { hasText: 'Етикет' }).evaluate((e) => ({ t: e.textContent.trim().replace(/\s+/g, ' '), w: getComputedStyle(e.querySelector('strong')).fontWeight }));
    check('1280: the tag control says «Етикет: CT4 Нови» with the name in weight 500', tagCtl.t === 'Етикет: CT4 Нови' && tagCtl.w === '500', JSON.stringify(tagCtl));
    check('1280: ONE «Изчисти» after the last control', (await page.locator('.sf-bar-clear').count()) === 1 && await page.$eval('.sf-cbar', (e) => e.lastElementChild.classList.contains('sf-bar-clear')));
    await page.locator('.sf-bctl-sum', { hasText: 'Етикет' }).click();
    const on = (await chipMetrics(page, '.sf-bctl[open] .sf-bv-chip.sf-bv-on'))[0];
    check('1280: the selected chip: border in the text colour, 1px border + 1px inner ring (2px in all), no fill, weight 500', on && on.bc === ink && /0px 0px 0px 1px/.test(on.sh) && on.sh.includes('inset') && on.bg === 'rgb(255, 255, 255)' && on.fw === '500', JSON.stringify(on));
    await shot('ct4-bar-tag-selected-1280');

    // a combined selection: brand + colour + tag, the canonical order brand, attributes, tag
    await page.locator('.sf-bctl-sum', { hasText: 'Марка' }).click();
    await nav(page, page.locator('.sf-bctl[open] .sf-bv', { hasText: 'CT4 Бета' }));
    await page.locator('.sf-bctl-sum', { hasText: 'Цвят' }).click();
    await nav(page, page.locator('.sf-bctl[open] .sf-bv-dot[title="Червен"]'));
    const q = qs(page);
    check('1280: the combined URL is canonical: brand, then the attribute, then the tag', q === `?filter[brand]=${x.b[1]}&filter[${ids.colour}]=${ids.c['Червен']}&filter[tag]=${x.t[0]}`, q);
    const total = await page.locator('.sf-count').innerText();
    check('1280: «Брой продукти» follows the combined result and the page has products', /^Брой продукти: \d+$/.test(total) && parseInt(total.split(': ')[1], 10) > 0 && parseInt(total.split(': ')[1], 10) < 60, total);
    const sumTexts = await page.$$eval('.sf-bctl-sum', (els) => els.map((e) => e.textContent.trim().replace(/\s+/g, ' ')));
    check('1280: every chosen filter shows «Име: избраните»', sumTexts.includes('Марка: CT4 Бета') && sumTexts.includes('Цвят: Червен') && sumTexts.includes('Етикет: CT4 Нови'), JSON.stringify(sumTexts));
    await shot('ct4-bar-combined-1280');
    await nav(page, page.locator('.sf-bar-clear'));
    check('1280: «Изчисти» clears every filter', ! qs(page).includes('filter['), page.url());

    // 404 and 301 (no redirects followed, so the status is the server's)
    const get = (path) => page.request.get(BASE + path, { maxRedirects: 0 });
    const unknown = await get(`${URL}?filter[brand]=999999`);
    check('URL: an unknown brand id is the storefront 404', unknown.status() === 404, String(unknown.status()));
    const messy = await get(`${URL}?filter[tag]=${x.t[1]},${x.t[0]}`);
    check('URL: the same tags in another order are a 301 to the canonical URL', messy.status() === 301 && decodeURIComponent(messy.headers()['location'] ?? '').endsWith(`filter[tag]=${x.t[0]},${x.t[1]}`), `${messy.status()} ${messy.headers()['location']}`);
    const array = await get(`${URL}?filter[brand][]=${x.b[1]}&filter[brand][]=${x.b[0]}`);
    check('URL: the form\'s array form is a 301 to the canonical comma form', array.status() === 301 && decodeURIComponent(array.headers()['location'] ?? '').includes(`filter[brand]=${x.b[0]},${x.b[1]}`), `${array.status()} ${array.headers()['location']}`);
    const ok = await get(`${URL}?filter[tag]=${x.t[0]}`);
    check('URL: a canonical filtered page is 200, noindex, follow', ok.status() === 200 && ok.headers()['x-robots-tag'] === 'noindex, follow', `${ok.status()} ${ok.headers()['x-robots-tag']}`);
    await ctx.close();

    // a switched-off built-in is not in the whitelist
    setDesign('full', 'list', 'links', 'off');
    ({ ctx, page } = await open(1280, URL));
    const off = await page.request.get(BASE + `${URL}?filter[tag]=${x.t[0]}`, { maxRedirects: 0 });
    check('URL: the tag is switched off: its key is a 404 and its control is gone', off.status() === 404 && ! (await page.locator('.sf-bctl-sum', { hasText: 'Етикет' }).count()), String(off.status()));
    await ctx.close();

    // ============================================================ 1280, the side panel
    setDesign('sidebar', 'list', 'links', 'buttons');
    ({ ctx, page, shot } = await open(1280, URL, 'ct4-sidebar-1280'));
    const sideNames = await page.$$eval('.sf-cat-layout-sidebar .sf-fgroup:not(.sf-fgroup-sort) .sf-fgroup-name', (els) => els.map((e) => e.textContent.trim()));
    check('1280 side panel: the groups are Марка, Сезон, the attributes, Етикет', sideNames.join('|') === 'МАРКА|СЕЗОН|ЦВЯТ|РАЗМЕР|МАТЕРИЯ|ЕТИКЕТ' || sideNames.map((n) => n.toLowerCase()).join('|') === 'марка|сезон|цвят|размер|материя|етикет', JSON.stringify(sideNames));
    const sideChips = await chipMetrics(page, '.sf-cat-layout-sidebar .sf-fv-chip .sf-fv-text');
    check('1280 side panel: the tag chips are labels with the same look (36px, 13px, 1px muted border, white, card radius) and the real checkboxes are in the DOM', sideChips.length === 4 && sideChips.every((c) => c.h >= 36 && c.fs === '13px' && c.bw === '1px' && c.bc === muted && c.rad === cardRadius) && (await page.locator('.sf-cat-layout-sidebar .sf-fv-chip .sf-fv-input').count()) === 4, JSON.stringify(sideChips[0]));
    await nav(page, page.locator('.sf-cat-layout-sidebar .sf-fv-chip', { hasText: 'CT4 Разпродажба' }));
    check('1280 side panel: a chip applies at once (script on)', qs(page) === `?filter[tag]=${x.t[1]}`, qs(page));
    const chosen = (await chipMetrics(page, '.sf-cat-layout-sidebar .sf-fv-input:checked + .sf-fv-text'))[0];
    check('1280 side panel: the chosen chip is drawn selected (border in the text colour, inner ring, weight 500)', chosen && chosen.bc === ink && chosen.sh.includes('inset') && chosen.fw === '500', JSON.stringify(chosen));
    await shot('ct4-sidebar-selected-1280');
    await ctx.close();

    // ============================================================ 360 / 320, the phone drawer
    setDesign('full', 'list', 'links', 'buttons');
    ({ ctx, page, shot } = await open(360, URL, 'ct4-phone-closed-360'));
    await page.locator('.sf-ftrigger').click();
    await sleep(500);
    const rows = await page.$$eval('.sf-fgroup-summary .sf-fgroup-name', (els) => els.map((e) => e.textContent.trim()));
    check('360: the drawer rows: the sort, Марка, Сезон, the attributes, Етикет last', rows.slice(1).map((r) => r.toLowerCase()).join('|') === 'марка|сезон|цвят|размер|материя|етикет', JSON.stringify(rows));
    await shot('ct4-phone-level1-360');
    await page.locator('.sf-fgroup-summary', { hasText: 'Етикет' }).click();
    await sleep(400);
    const phoneChips = await chipMetrics(page, '.sf-fgroup[open] .sf-fv-chip .sf-fv-text');
    const phoneCardRadius = await page.$eval('.sf-card', (e) => getComputedStyle(e).borderTopLeftRadius);
    check('360: the chips are ≥ 44px high with the phone\'s card radius, the real checkboxes are kept, nothing overflows', phoneChips.length === 4 && phoneChips.every((c) => c.h >= 44 && c.rad === phoneCardRadius && c.fs === '13px') && (await page.locator('.sf-fgroup[open] .sf-fv-chip .sf-fv-input').count()) === 4 && await noOverflow(page), JSON.stringify({ phoneCardRadius, first: phoneChips[0] }));
    await page.locator('.sf-fgroup[open] .sf-fv-chip', { hasText: 'CT4 Подарък' }).click();
    const phoneOn = (await chipMetrics(page, '.sf-fgroup[open] .sf-fv-input:checked + .sf-fv-text'))[0];
    check('360: a chosen chip in the drawer is drawn selected from input:checked (no script), and nothing is applied before «Филтрирай»', phoneOn && phoneOn.bc === ink && phoneOn.sh.includes('inset') && phoneOn.fw === '500' && page.url().endsWith(URL), JSON.stringify(phoneOn));
    await shot('ct4-phone-level2-tags-360');
    await nav(page, page.locator('.sf-fbtn-outline'));
    check('360: «Филтрирай» answers with the canonical URL', qs(page) === `?filter[tag]=${x.t[3]}`, qs(page));
    await ctx.close();

    ({ ctx, page, shot } = await open(320, URL + `?filter[brand]=${x.b[0]}`, 'ct4-phone-320'));
    await page.locator('.sf-ftrigger').click();
    await sleep(500);
    check('320: the page and the open drawer have no horizontal scroll', await noOverflow(page));
    await page.locator('.sf-fgroup-summary', { hasText: 'Етикет' }).click();
    await sleep(300);
    check('320: the tag screen has no horizontal scroll either', await noOverflow(page) && (await page.$eval('.sf-fgroup[open]', (e) => e.scrollWidth <= e.clientWidth + 1)));
    await shot('ct4-phone-tags-320');
    await ctx.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (backedUp) {
        try {
            php(RESTORE);
            php(RESTORE_X);
            const after = sums();
            check('everything is back: the settings table (exact rows), the catalog tables, the brands, seasons, tags and their links are as they were (checksums / counts)', before && JSON.stringify(before) === JSON.stringify(after), JSON.stringify({ before, after }));
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
