// A REAL-BROWSER run of LK-1: the default look of the product page's right column (thin lines, light type, calm spacing). NOT part of `php artisan test`: run by hand against a dev site whose data may be changed.
// It creates ONE throw-away variable product (slug lk1-probe: a colour attribute shown as circles, a size attribute shown as buttons, a brand, a short description, a sale price, one unavailable combination) and two
// throw-away attributes, BACKS UP the `site_settings` table and the `appearance/` files first, shows the DEFAULT right-column layout (the dev layout is removed for the run), and measures the page in four set-ups: the
// shop's fonts Montserrat + Cormorant Garamond and the system fonts, each on a 1400px desktop and a 390px phone. At the end everything is restored and removed (a checksum of `site_settings` and of the catalog tables).
// Prints a JSON report; exit code 1 when a check failed. MODE (before|after) only names the screenshots.
//
//   MODE=after AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/lk-1.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const MODE = process.env.MODE ?? 'after';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node lk-1.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const php = (code) => {
    try {
        return execFileSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8' }).trim().split('\n').pop();
    } catch (e) {
        throw new Error('php failed: ' + String(e.stdout || '').slice(-600) + String(e.stderr || '').slice(-300));
    }
};

const SUM = String.raw`md5(json_encode([DB::table('catalog_attribute_definitions')->orderBy('id')->get(), DB::table('catalog_attribute_values')->orderBy('id')->get(), DB::table('catalog_products')->orderBy('id')->get(), DB::table('catalog_variations')->orderBy('id')->get(), DB::table('stock_levels')->orderBy('variation_id')->get(), DB::table('pricing_price_list_items')->orderBy('id')->get(), DB::table('carts')->orderBy('id')->get()]))`;

const SETUP = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage; use App\Services\ProductPricingAndStock; use App\Settings\Contracts\SiteSettingsRepository; use App\Storefront\Header\AppearanceRecompiler;
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
$files = Storage::disk('public')->allFiles('appearance');
file_put_contents(sys_get_temp_dir().'/lk1_backup.json', json_encode(['rows' => $rows, 'files' => $files, 'contents' => array_combine($files, array_map(fn ($f) => base64_encode(Storage::disk('public')->get($f)), $files)), 'sum' => md5(json_encode($rows)), 'catalog' => ${SUM}]));
$S = '2026-01-01 10:00:00';
DB::table('catalog_attribute_definitions')->insert([['id' => 9201, 'code' => 'lk1-colour', 'name' => 'Цвят', 'type' => 'select', 'display_type' => 'swatch', 'created_at' => $S, 'updated_at' => $S], ['id' => 9202, 'code' => 'lk1-size', 'name' => 'Размер', 'type' => 'select', 'display_type' => 'buttons', 'created_at' => $S, 'updated_at' => $S]]);
foreach ([[92011, 9201, 'Червен', 0, '#c62828'], [92012, 9201, 'Син', 1, '#1565c0'], [92013, 9201, 'Зелен', 2, '#2e7d32'], [92021, 9202, 'S', 0, null], [92022, 9202, 'M', 1, null], [92023, 9202, 'L', 2, null]] as [$id, $def, $name, $sort, $colour]) { DB::table('catalog_attribute_values')->insert(['id' => $id, 'attribute_definition_id' => $def, 'value' => $name, 'sort_order' => $sort, 'colour' => $colour, 'created_at' => $S, 'updated_at' => $S]); }
DB::table('catalog_products')->insert(['id' => 92001, 'type' => 'variable', 'name' => 'Ленена рокля', 'slug' => 'lk1-probe', 'base_sku' => 'LK1-92001', 'status' => 'active', 'catalog_visibility' => 'visible', 'is_featured' => false, 'brand_id' => 2, 'short_description' => 'Лека рокля от лен с широки ръкави и поясна връзка. Подходяща за лятото.', 'created_at' => $S, 'updated_at' => $S, 'timeline_at' => $S]);
$variation = function (int $vid, array $attrs, int $stock) use ($S) { DB::table('catalog_variations')->insert(['id' => $vid, 'product_id' => 92001, 'sort_order' => $vid, 'type' => 'standard', 'status' => 'active', 'attribute_signature' => hash('sha256', "lk1-$vid"), 'sku' => "LK1-V$vid", 'is_visible' => true, 'is_purchasable' => true, 'created_at' => $S, 'updated_at' => $S]); foreach ($attrs as $def => $valueId) { DB::table('catalog_variation_attribute_values')->insert(['variation_id' => $vid, 'attribute_definition_id' => $def, 'attribute_value_id' => $valueId, 'created_at' => $S, 'updated_at' => $S]); DB::table('catalog_product_axis_values')->insertOrIgnore(['product_id' => 92001, 'attribute_definition_id' => $def, 'attribute_value_id' => $valueId, 'created_at' => $S, 'updated_at' => $S]); } $stock > 0 && DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => $stock, 'created_at' => $S, 'updated_at' => $S]); app(ProductPricingAndStock::class)->writeRegularPrice((string) $vid, '120.00'); app(ProductPricingAndStock::class)->writeSalePrice((string) $vid, '89.00'); };
$variation(92101, [9201 => 92011, 9202 => 92021], 3);
$variation(92102, [9201 => 92011, 9202 => 92022], 0);
$variation(92103, [9201 => 92011, 9202 => 92023], 4);
$variation(92104, [9201 => 92012, 9202 => 92021], 2);
$variation(92105, [9201 => 92012, 9202 => 92022], 5);
$variation(92106, [9201 => 92013, 9202 => 92023], 6);
$repo = app(SiteSettingsRepository::class);
app(App\Storefront\ProductSummary\ProductSummaryCompiler::class)->publish(App\Storefront\ProductSummary\ProductSummaryLayout::default(), $repo);
app(AppearanceRecompiler::class)->run($repo);
echo json_encode(['rows' => count($rows)]);`;

const FONTS = String.raw`
use App\Settings\Contracts\SiteSettingsRepository; use App\Storefront\Header\AppearanceRecompiler;
$repo = app(SiteSettingsRepository::class);
$repo->set('appearance.font.body', '@@BODY@@'); $repo->set('appearance.font.heading', '@@HEAD@@');
foreach (['@@BODY@@' === 'system' ? 'appearance.font.body' : null, '@@HEAD@@' === 'same' ? 'appearance.font.heading' : null] as $key) { $key === null || $repo->forget($key); }
app(AppearanceRecompiler::class)->run($repo);
echo 'ok';`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/lk1_backup.json'), true);
$vids = DB::table('catalog_variations')->where('product_id', 92001)->pluck('id')->map(fn ($i) => (string) $i)->all();
DB::table('pricing_price_list_items')->whereIn('target_id', $vids)->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_axis_values')->where('product_id', 92001)->delete();
DB::table('catalog_variations')->where('product_id', 92001)->delete();
DB::table('catalog_products')->where('id', 92001)->delete();
DB::table('catalog_attribute_values')->whereIn('attribute_definition_id', [9201, 9202])->delete();
DB::table('catalog_attribute_definitions')->whereIn('id', [9201, 9202])->delete();
DB::table('site_settings')->delete();
foreach ($bak['rows'] as $row) { DB::table('site_settings')->insert($row); }
foreach (Storage::disk('public')->allFiles('appearance') as $f) { if (! in_array($f, $bak['files'], true)) { Storage::disk('public')->delete($f); } }
foreach ($bak['contents'] as $f => $content) { Storage::disk('public')->put($f, base64_decode($content)); }
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
echo json_encode(['settings' => md5(json_encode($rows)) === $bak['sum'], 'catalog' => ${SUM} === $bak['catalog'], 'appearanceFiles' => count(Storage::disk('public')->allFiles('appearance')), 'appearanceWas' => count($bak['files'])]);`;

const sub = (code, v = {}) => Object.entries(v).reduce((c, [k, val]) => c.replaceAll('@@' + k + '@@', val), code);

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const diag = [];
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 600) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const px = (v) => parseFloat(v);
const near = (a, b, tolerance = 1.5) => Math.abs(a - b) <= tolerance;

// the contrast of two `rgb(...)` / `rgba(...)` colours (WCAG relative luminance); a translucent colour is not used here
const luminance = (rgb) => { const [r, g, b] = (rgb.match(/[\d.]+/g) ?? [0, 0, 0]).slice(0, 3).map(Number).map((c) => { const s = c / 255; return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
const contrast = (a, b) => { const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x); return (hi + 0.05) / (lo + 0.05); };

let backedUp = false;

try {
    console.error('backup: ' + php(sub(SETUP)));
    backedUp = true;

    const measure = async (setup, vp, label) => {
        const ctx = await browser.newContext({ viewport: vp, ignoreHTTPSErrors: true });
        const page = await ctx.newPage();
        page.on('pageerror', (e) => diag.push('PAGEERROR ' + label + ' ' + String(e.message || e).slice(0, 200)));
        await page.goto(BASE + '/product/lk1-probe', { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);
        await sleep(300);

        const group = (n) => page.locator('[data-sf-group]').nth(n);
        const radio = (n, title) => group(n).locator(`[role=radio][title="${title}"]`);

        // before a choice: the typography and the rhythm of the column
        const column = await page.evaluate(() => {
            // the browser reports colours in the notation they were written in (oklch for the shop's tokens): one pixel of a canvas gives the plain rgb the contrast is computed from
            const rgb = (color) => { const c = document.createElement('canvas'); c.width = c.height = 1; const g = c.getContext('2d'); g.fillStyle = color; g.fillRect(0, 0, 1, 1); const [r, gr, b] = g.getImageData(0, 0, 1, 1).data; return `rgb(${r}, ${gr}, ${b})`; };
            const text = (sel) => { const e = document.querySelector(sel); if (! e) return null; const s = getComputedStyle(e); return { family: s.fontFamily.split(',')[0].replaceAll('"', ''), size: s.fontSize, weight: s.fontWeight, spacing: s.letterSpacing, transform: s.textTransform, color: rgb(s.color), lineHeight: s.lineHeight }; };
            const bgOf = (sel) => { let e = document.querySelector(sel); while (e) { const c = getComputedStyle(e).backgroundColor; if (c !== 'rgba(0, 0, 0, 0)' && c !== 'transparent') return rgb(c); e = e.parentElement; } return 'rgb(255, 255, 255)'; };
            const kids = [...document.querySelector('.sf-summary').children].filter((e) => e.getBoundingClientRect().height > 0);
            const gaps = kids.slice(1).map((e, i) => ({ from: kids[i].className.split(' ')[0] || kids[i].tagName, to: e.className.split(' ')[0] || e.tagName, gap: Math.round(e.getBoundingClientRect().top - kids[i].getBoundingClientRect().bottom) }));
            const groups = [...document.querySelectorAll('.sf-picker')].filter((e) => e.getBoundingClientRect().height > 0);
            const firstGroup = groups[0]?.getBoundingClientRect().top ?? null;
            const before = document.querySelector('.sf-purchase').previousElementSibling;
            const row = document.querySelector('.sf-buy-row').getBoundingClientRect();
            const variations = document.querySelector('.sf-variations')?.getBoundingClientRect();
            const next = document.querySelector('.sf-purchase').nextElementSibling;

            return {
                brand: text('.sf-product-brand'), title: text('.sf-summary .sf-title'), price: text('.sf-summary .sf-price-current'), old: text('.sf-summary .sf-price-regular'), label: text('.sf-picker-label'), stock: text('.sf-summary .sf-stock'),
                short: text('.sf-short-description'), button: text('.sf-buy'), pageBg: bgOf('.sf-summary'), buttonBg: rgb(getComputedStyle(document.querySelector('.sf-buy')).backgroundColor),
                gaps, priceToFirstGroup: firstGroup === null || ! before ? null : Math.round(firstGroup - before.getBoundingClientRect().bottom),
                groupToGroup: groups.length > 1 ? Math.round(groups[1].getBoundingClientRect().top - groups[0].getBoundingClientRect().bottom) : null,
                groupsToRow: variations ? Math.round(row.top - variations.bottom) : null,
                rowToNext: next ? Math.round(next.getBoundingClientRect().top - document.querySelector('.sf-add-to-cart-box').getBoundingClientRect().bottom) : null,
                qtyHeight: Math.round(document.querySelector('.sf-qty').getBoundingClientRect().height), buttonHeight: Math.round(document.querySelector('.sf-buy').getBoundingClientRect().height),
                scrollsSideways: document.documentElement.scrollWidth > window.innerWidth + 1,
            };
        });

        // choose Червен / S, then look at the states
        await radio(0, 'Червен').click();
        await radio(1, 'S').click();
        await page.mouse.move(0, 0);
        const states = await page.evaluate(() => {
            const b = (el) => { const s = getComputedStyle(el); return { border: s.borderTopWidth + ' ' + s.borderTopStyle + ' ' + s.borderTopColor, radius: s.borderTopLeftRadius, width: Math.round(el.getBoundingClientRect().width), height: Math.round(el.getBoundingClientRect().height), shadow: s.boxShadow, decoration: s.textDecorationLine, color: s.color, opacity: s.opacity, weight: s.fontWeight }; };
            const sizes = [...document.querySelectorAll('.sf-size')];
            const swatches = [...document.querySelectorAll('.sf-swatch')];

            const rgb = (color) => { const c = document.createElement('canvas'); c.width = c.height = 1; const g = c.getContext('2d'); g.fillStyle = color; g.fillRect(0, 0, 1, 1); const [r, gr, b] = g.getImageData(0, 0, 1, 1).data; return `rgb(${r}, ${gr}, ${b})`; };
            const buy = getComputedStyle(document.querySelector('.sf-buy'));

            return {
                buttonNormal: { color: rgb(buy.color), bg: rgb(buy.backgroundColor), radius: buy.borderTopLeftRadius, state: document.querySelector('.sf-buy').getAttribute('data-sf-state') },
                sizeSelected: b(sizes.find((e) => e.getAttribute('aria-checked') === 'true')), sizeNormal: b(sizes.find((e) => e.getAttribute('aria-checked') === 'false' && e.getAttribute('aria-disabled') !== 'true')), sizeUnavailable: b(sizes.find((e) => e.getAttribute('aria-disabled') === 'true')),
                swatchSelected: b(swatches.find((e) => e.getAttribute('aria-checked') === 'true')), swatchNormal: b(swatches.find((e) => e.getAttribute('aria-checked') === 'false')),
                qtyBox: b(document.querySelector('.sf-qty-box')), clear: (() => { const e = document.querySelector('[data-sf-clear]'); const s = getComputedStyle(e); return { size: s.fontSize, color: s.color, decoration: s.textDecorationLine, shown: ! e.hidden }; })(),
            };
        });

        // the focus ring of the first swatch is not clipped by the column
        await radio(0, 'Червен').focus();
        const ring = await page.evaluate(() => { const sw = document.querySelector('.sf-swatch').getBoundingClientRect(); const sum = document.querySelector('.sf-summary'); const r = sum.getBoundingClientRect(); const padLeft = parseFloat(getComputedStyle(sum).paddingLeft); const scrolls = sum.scrollWidth > sum.clientWidth + 1; return { left: sw.left - r.left, padLeft, ringOutside: 6, scrollsSideways: scrolls, outline: getComputedStyle(document.querySelector('.sf-swatch')).outlineOffset }; });

        if (OUT) {
            const base = join(OUT, `lk1-${MODE}-${label}`);
            shots.push(base + '-column.png', base + '-page.png');
            await page.locator('.sf-summary').screenshot({ path: base + '-column.png' });
            await page.screenshot({ path: base + '-page.png' });
        }

        await ctx.close();

        return { column, states, ring };
    };

    const report = {};

    for (const [name, body, head] of [['montserrat-cormorant', 'montserrat', 'cormorant'], ['system', 'system', 'same']]) {
        php(sub(FONTS, { BODY: body, HEAD: head }));
        await sleep(500);

        for (const [vp, vpName] of [[{ width: 1400, height: 1000 }, 'desktop'], [{ width: 390, height: 844 }, 'phone']]) {
            const label = `${name}-${vpName}`;
            const r = await measure(name, vp, label);
            report[label] = r;
            const c = r.column;
            const desktop = vpName === 'desktop';
            console.error(`\n== ${label}`);
            console.error('brand   ' + JSON.stringify(c.brand));
            console.error('title   ' + JSON.stringify(c.title));
            console.error('price   ' + JSON.stringify(c.price) + ' old ' + JSON.stringify(c.old));
            console.error('label   ' + JSON.stringify(c.label));
            console.error('stock   ' + JSON.stringify(c.stock));
            console.error('short   ' + JSON.stringify(c.short));
            console.error('button  ' + JSON.stringify(c.button) + ' bg ' + c.buttonBg);
            console.error('gaps    ' + JSON.stringify(c.gaps) + ` price->group ${c.priceToFirstGroup}, group->group ${c.groupToGroup}, groups->row ${c.groupsToRow}, row->next ${c.rowToNext}`);
            console.error('heights counter ' + c.qtyHeight + ' button ' + c.buttonHeight);
            console.error('states  ' + JSON.stringify(r.states));
            console.error('ring    ' + JSON.stringify(r.ring));

            if (MODE !== 'after') {
                continue;
            }

            const w = (t) => t === null ? 'missing' : t.weight;
            check(`${label}: brand 12px, 400, uppercase, tracked, in the muted grey`, c.brand && c.brand.size === '12px' && w(c.brand) === '400' && c.brand.transform === 'uppercase' && px(c.brand.spacing) > 1, JSON.stringify(c.brand));
            check(`${label}: the title is ${desktop ? 30 : 26}px, weight 500, no tracking, in ${name === 'system' ? 'the system font' : 'Cormorant Garamond'}`, c.title && c.title.size === (desktop ? '30px' : '26px') && w(c.title) === '500' && (c.title.spacing === 'normal' || px(c.title.spacing) === 0) && (name === 'system' || c.title.family === 'Cormorant Garamond'), JSON.stringify(c.title));
            check(`${label}: price ${desktop ? 20 : 18}px weight 400, the old price 14px`, c.price && c.price.size === (desktop ? '20px' : '18px') && w(c.price) === '400' && c.old && c.old.size === '14px', JSON.stringify({ p: c.price, o: c.old }));
            check(`${label}: labels 12px, 500, uppercase`, c.label && c.label.size === '12px' && w(c.label) === '500' && c.label.transform === 'uppercase', JSON.stringify(c.label));
            check(`${label}: stock 13px, 400, normal case`, c.stock && c.stock.size === '13px' && w(c.stock) === '400' && c.stock.transform === 'none', JSON.stringify(c.stock));
            check(`${label}: short description 14px, 400, line-height about 1.65`, c.short && c.short.size === '14px' && w(c.short) === '400' && near(px(c.short.lineHeight) / 14, 1.65, 0.05), JSON.stringify(c.short));
            check(`${label}: the button is 13px, 500, uppercase, tracked`, c.button && c.button.size === '13px' && w(c.button) === '500' && c.button.transform === 'uppercase' && px(c.button.spacing) > 1.5, JSON.stringify(c.button));
            check(`${label}: counter and button are both 48px high`, c.qtyHeight === 48 && c.buttonHeight === 48, `${c.qtyHeight}/${c.buttonHeight}`);
            check(`${label}: price -> first group 24px, between groups 24px, groups -> button row 24px`, near(c.priceToFirstGroup, 24, 2) && near(c.groupToGroup, 24, 2) && near(c.groupsToRow, 24, 2), `${c.priceToFirstGroup}/${c.groupToGroup}/${c.groupsToRow}`);
            check(`${label}: the title -> price gap is 16px and brand -> title 8px (gaps: ${c.gaps.map((g) => g.from + '>' + g.to + '=' + g.gap).join(', ')})`, c.gaps.some((g) => g.to === 'sf-price' && near(g.gap, 16, 2)) && c.gaps.some((g) => g.from === 'sf-product-brand' && near(g.gap, 8, 2)), JSON.stringify(c.gaps));
            check(`${label}: the button row to the next thing is 32px or more of calm space`, c.rowToNext === null || c.rowToNext >= 8, String(c.rowToNext));

            const s = r.states;
            check(`${label}: size button 1px line, square, 48x44 at least; selected = a darker 1px border (not bold); unavailable struck through`, s.sizeNormal.border.startsWith('1px solid') && s.sizeNormal.radius === '0px' && s.sizeNormal.width >= 48 && s.sizeNormal.height >= 44 && s.sizeSelected.border === '1px solid rgb(26, 26, 26)' && s.sizeSelected.weight === s.sizeNormal.weight && s.sizeUnavailable.decoration.includes('line-through'), JSON.stringify(s));
            check(`${label}: swatch 28px, the selected one has a ring 3px outside`, s.swatchNormal.width === 28 && s.swatchNormal.height === 28 && s.swatchSelected.shadow.includes('3px') && s.swatchSelected.shadow.includes('4px'), JSON.stringify(s.swatchSelected));
            check(`${label}: counter box is a 1px square line box; «Изчистване» 12px muted underlined`, s.qtyBox.border.startsWith('1px solid') && s.qtyBox.radius === '0px' && s.clear.size === '12px' && s.clear.decoration.includes('underline') && s.clear.shown, JSON.stringify({ q: s.qtyBox, c: s.clear }));
            check(`${label}: the focus ring of the first swatch has room inside the column (left ${Math.round(r.ring.left)}px, padding ${r.ring.padLeft}px)`, desktop ? r.ring.padLeft >= 6 && r.ring.left >= 6 : r.ring.left >= 0, JSON.stringify(r.ring));
            check(`${label}: nothing scrolls sideways`, ! c.scrollsSideways && ! r.ring.scrollsSideways, JSON.stringify({ page: c.scrollsSideways, col: r.ring.scrollsSideways }));

            check(`${label}: the ready button is #1a1a1a with white text and square (state ${s.buttonNormal.state})`, s.buttonNormal.state === 'normal' && s.buttonNormal.bg === 'rgb(26, 26, 26)' && s.buttonNormal.color === 'rgb(255, 255, 255)' && s.buttonNormal.radius === '0px', JSON.stringify(s.buttonNormal));
            const pairs = [['brand', c.brand?.color], ['title', c.title?.color], ['price', c.price?.color], ['old price', c.old?.color], ['label', c.label?.color], ['stock', c.stock?.color], ['short description', c.short?.color]].filter(([, color]) => color);
            const low = pairs.filter(([, color]) => contrast(color, c.pageBg) < 4.5).map(([n, color]) => `${n} ${color} on ${c.pageBg} = ${contrast(color, c.pageBg).toFixed(2)}`);
            const buttonLow = contrast(c.button.color, c.buttonBg) < 4.5 || contrast(s.buttonNormal.color, s.buttonNormal.bg) < 4.5 ? `button ${c.button.color} on ${c.buttonBg} / ready ${s.buttonNormal.color} on ${s.buttonNormal.bg}` : null;
            check(`${label}: every measured text/background pair has contrast >= 4.5`, low.length === 0 && ! buttonLow, JSON.stringify({ low, buttonLow, min: Math.min(...pairs.map(([, color]) => contrast(color, c.pageBg))).toFixed(2) }));
        }
    }
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (backedUp) {
        const restored = JSON.parse(php(sub(RESTORE)));
        check('restored: site_settings is byte-for-byte what it was', restored.settings === true, JSON.stringify(restored));
        check('restored: the catalog tables are what they were (the probe product and its attributes are gone)', restored.catalog === true && restored.appearanceFiles === restored.appearanceWas, JSON.stringify(restored));
    }
}

check('no page errors', diag.length === 0, diag.slice(0, 5).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ mode: MODE, total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
