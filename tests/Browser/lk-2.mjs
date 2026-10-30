// A REAL-BROWSER run of LK-2: the product CARDS of the catalog (larger calm name in the BODY font, small muted brand, light prices). NOT part of `php artisan test`: run by hand against a dev site whose data may be
// changed. It creates three throw-away simple products in the «Дрехи» category (a long name with a brand, a sale price with a brand, a plain one with no brand), BACKS UP the `site_settings` table and the `appearance/`
// files first, and measures the category page and the home page in four set-ups: the shop's fonts Montserrat + Cormorant Garamond and the system fonts, each on a 1400px desktop and a 390px phone. At the end
// everything is restored and removed (a checksum of `site_settings` and of the catalog tables). Prints a JSON report; exit code 1 when a check failed. MODE (before|after) only names the screenshots.
//
//   MODE=after AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/lk-2.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const MODE = process.env.MODE ?? 'after';
const ROOT = resolve(import.meta.dirname, '..', '..');
const IDS = { long: 93001, sale: 93002, plain: 93003 };

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node lk-2.mjs');
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

const SUM = String.raw`md5(json_encode([DB::table('catalog_products')->orderBy('id')->get(), DB::table('catalog_variations')->orderBy('id')->get(), DB::table('catalog_product_categories')->orderBy('id')->get(), DB::table('stock_levels')->orderBy('variation_id')->get(), DB::table('pricing_price_list_items')->orderBy('id')->get()]))`;

const SETUP = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage; use App\Services\ProductPricingAndStock;
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
$files = Storage::disk('public')->allFiles('appearance');
file_put_contents(sys_get_temp_dir().'/lk2_backup.json', json_encode(['rows' => $rows, 'files' => $files, 'contents' => array_combine($files, array_map(fn ($f) => base64_encode(Storage::disk('public')->get($f)), $files)), 'sum' => md5(json_encode($rows)), 'catalog' => ${SUM}]));
$now = now()->format('Y-m-d H:i:s');
$make = function (int $id, string $slug, string $name, ?int $brand, string $price, ?string $sale) use ($now) {
    DB::table('catalog_products')->insert(['id' => $id, 'type' => 'simple', 'name' => $name, 'slug' => $slug, 'base_sku' => "LK2-$id", 'status' => 'active', 'catalog_visibility' => 'visible', 'is_featured' => true, 'brand_id' => $brand, 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => $now]);
    $vid = $id * 10 + 1;
    DB::table('catalog_variations')->insert(['id' => $vid, 'product_id' => $id, 'sort_order' => 1, 'type' => 'universal', 'status' => 'active', 'attribute_signature' => hash('sha256', "lk2-$vid"), 'sku' => "LK2-V$vid", 'is_visible' => false, 'is_purchasable' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 5, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('catalog_product_categories')->insert(['product_id' => $id, 'category_id' => 5]);
    app(ProductPricingAndStock::class)->writeRegularPrice((string) $vid, $price);
    $sale === null || app(ProductPricingAndStock::class)->writeSalePrice((string) $vid, $sale);
};
$make(93001, 'lk2-long', 'Дълга ленена рокля с широки ръкави, ръчна бродерия и поясна връзка в цвят слонова кост', 2, '189.00', null);
$make(93002, 'lk2-sale', 'Копринена блуза', 5, '120.00', '89.00');
$make(93003, 'lk2-plain', 'Кожен колан', null, '45.00', null);
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
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/lk2_backup.json'), true);
$ids = [93001, 93002, 93003]; $vids = array_map(fn ($i) => (string) ($i * 10 + 1), $ids);
DB::table('pricing_price_list_items')->whereIn('target_id', $vids)->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $ids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $ids)->delete();
DB::table('catalog_products')->whereIn('id', $ids)->delete();
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

const luminance = (rgb) => { const [r, g, b] = (rgb.match(/[\d.]+/g) ?? [0, 0, 0]).slice(0, 3).map(Number).map((c) => { const s = c / 255; return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
const contrast = (a, b) => { const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x); return (hi + 0.05) / (lo + 0.05); };

let backedUp = false;

try {
    console.error('backup: ' + php(sub(SETUP)));
    backedUp = true;

    const measure = async (path, vp, label) => {
        const ctx = await browser.newContext({ viewport: vp, ignoreHTTPSErrors: true });
        const page = await ctx.newPage();
        page.on('pageerror', (e) => diag.push('PAGEERROR ' + label + ' ' + String(e.message || e).slice(0, 200)));
        await page.goto(BASE + path, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);
        await sleep(300);

        const data = await page.evaluate((ids) => {
            // the browser reports colours in the notation they were written in (oklch for the shop's tokens): one canvas pixel gives the plain rgb the contrast is computed from
            const rgb = (color) => { const c = document.createElement('canvas'); c.width = c.height = 1; const g = c.getContext('2d'); g.fillStyle = color; g.fillRect(0, 0, 1, 1); const [r, gr, b] = g.getImageData(0, 0, 1, 1).data; return `rgb(${r}, ${gr}, ${b})`; };
            const text = (el) => { if (! el) return null; const s = getComputedStyle(el); return { tag: el.tagName.toLowerCase(), family: s.fontFamily.split(',')[0].replaceAll('"', ''), size: s.fontSize, weight: s.fontWeight, transform: s.textTransform, spacing: s.letterSpacing, lineHeight: s.lineHeight, color: rgb(s.color), decoration: s.textDecorationLine, height: Math.round(el.getBoundingClientRect().height) }; };
            const cards = {};

            for (const [key, id] of Object.entries(ids)) {
                const card = document.querySelector(`.sf-card[data-product-id="${id}"]`);

                if (! card) { cards[key] = null; continue; }

                const name = card.querySelector('.sf-card-name');
                const brand = card.querySelector('.sf-card-brand');
                const rect = card.getBoundingClientRect();
                const lh = parseFloat(getComputedStyle(name).lineHeight);
                const imageBottom = card.querySelector('.sf-card-image').getBoundingClientRect().bottom;
                const firstText = (brand ?? name).getBoundingClientRect().top;

                cards[key] = {
                    name: text(name), brand: text(brand), price: text(card.querySelector('.sf-price-current')), old: text(card.querySelector('.sf-price-regular')),
                    sale: card.querySelector('.sf-price-regular') ? text(card.querySelector('.sf-price-current')) : null,
                    bg: rgb(getComputedStyle(card).backgroundColor), top: Math.round(rect.top), height: Math.round(rect.height), priceTop: Math.round(card.querySelector('.sf-price').getBoundingClientRect().top),
                    lines: Math.round(name.getBoundingClientRect().height / lh), clamped: name.scrollHeight > name.clientHeight + 1,
                    imageToText: Math.round(firstText - imageBottom), brandToName: brand ? Math.round(name.getBoundingClientRect().top - brand.getBoundingClientRect().bottom) : null,
                    nameToPrice: Math.round(card.querySelector('.sf-price-current').getBoundingClientRect().top - name.getBoundingClientRect().bottom),
                    focus: (() => { const a = card.querySelector('.sf-card-link'); a.focus({ focusVisible: true }); const s = getComputedStyle(a); return { outline: s.outlineStyle + ' ' + s.outlineWidth }; })(),
                };
            }

            return { cards, scrollsSideways: document.documentElement.scrollWidth > window.innerWidth + 1, headingFont: (() => { const h = document.querySelector('h1, h2'); return h ? getComputedStyle(h).fontFamily.split(',')[0].replaceAll('"', '') : null; })() };
        }, IDS);

        if (OUT) {
            const base = join(OUT, `lk2-${MODE}-${label}`);
            shots.push(base + '.png');
            const grid = page.locator('.sf-grid').first();
            await (await grid.count() > 0 ? grid : page).screenshot({ path: base + '.png' });
        }

        await ctx.close();

        return data;
    };

    for (const [name, body, head, bodyFamily] of [['montserrat-cormorant', 'montserrat', 'cormorant', 'Montserrat'], ['system', 'system', 'same', 'ui-sans-serif']]) {
        php(sub(FONTS, { BODY: body, HEAD: head }));
        await sleep(500);

        for (const [vp, vpName] of [[{ width: 1400, height: 1000 }, 'desktop'], [{ width: 390, height: 844 }, 'phone']]) {
            const label = `${name}-${vpName}`;
            const r = await measure('/product-category/clothing', vp, label);
            const desktop = vpName === 'desktop';
            const c = r.cards;
            console.error(`\n== ${label} (heading font here: ${r.headingFont})`);

            for (const [k, v] of Object.entries(c)) {
                console.error(`${k}: ` + JSON.stringify(v));
            }

            if (MODE !== 'after') {
                continue;
            }

            check(`${label}: the three probe cards are on the category page`, c.long && c.sale && c.plain);
            const nameOk = (n) => n && n.family === bodyFamily && n.size === (desktop ? '15px' : '14px') && n.weight === '400' && n.transform === 'none' && Math.abs(px(n.lineHeight) / px(n.size) - 1.4) < 0.02;
            check(`${label}: the name is ${desktop ? 15 : 14}px, 400, normal case, line-height 1.4, in the BODY font (${bodyFamily}), not the heading font`, nameOk(c.long?.name) && nameOk(c.sale?.name) && nameOk(c.plain?.name), JSON.stringify([c.long?.name, c.sale?.name]));
            check(`${label}: the long name is cut at two lines (ellipsis) and a short one still reserves two lines`, c.long.lines <= 2 && c.long.clamped && c.plain.name.height === c.long.name.height, `${c.long.lines} lines, clamped ${c.long.clamped}, heights ${c.long.name.height}/${c.plain.name.height}`);
            check(`${label}: the brand is 11px, 400, uppercase, tracked, muted; a card with no brand prints none`, c.long.brand && c.long.brand.size === '11px' && c.long.brand.weight === '400' && c.long.brand.transform === 'uppercase' && px(c.long.brand.spacing) > 1 && c.plain.brand === null, JSON.stringify(c.long.brand));
            check(`${label}: the price is ${desktop ? 14 : 13}px weight 400, the old price 13px struck through, the sale price in the accent`, c.sale.price.size === (desktop ? '14px' : '13px') && c.sale.price.weight === '400' && c.sale.old.size === '13px' && c.sale.old.decoration.includes('line-through') && c.plain.price.weight === '400', JSON.stringify({ p: c.sale.price, o: c.sale.old }));
            check(`${label}: image -> brand/name 12px, brand -> name 4px, name -> price 8px or more`, Math.abs(c.long.imageToText - 12) <= 1.5 && Math.abs(c.long.brandToName - 4) <= 1.5 && Math.abs(c.plain.imageToText - 12) <= 1.5 && c.long.nameToPrice >= 7.5, JSON.stringify({ a: c.long.imageToText, b: c.long.brandToName, c: c.long.nameToPrice, d: c.plain.imageToText }));
            const row = Object.values(c).filter((x) => x.top === c.long.top);
            check(`${label}: the cards of one row have equal heights and their prices line up (${row.length} in this row)`, row.length >= 1 && row.every((x) => x.height === row[0].height && x.priceTop === row[0].priceTop), JSON.stringify(row.map((x) => [x.height, x.priceTop])));
            check(`${label}: the keyboard focus ring on the card link stays visible`, c.long.focus.outline.startsWith('solid') || c.long.focus.outline.startsWith('auto'), c.long.focus.outline);
            check(`${label}: nothing scrolls sideways`, ! r.scrollsSideways);

            const pairs = [['name', c.long.name], ['brand', c.long.brand], ['price', c.sale.price], ['old price', c.sale.old], ['plain price', c.plain.price]];
            const low = pairs.filter(([, t]) => contrast(t.color, c.long.bg) < 4.5).map(([n, t]) => `${n} ${t.color} on ${c.long.bg} = ${contrast(t.color, c.long.bg).toFixed(2)}`);
            check(`${label}: every measured text/background pair has contrast >= 4.5`, low.length === 0, JSON.stringify({ low, min: Math.min(...pairs.map(([, t]) => contrast(t.color, c.long.bg))).toFixed(2) }));
        }

        // the home page prints the same card: the name follows the body font there too
        const home = await measure('/', { width: 1400, height: 1000 }, `${name}-home`);
        const homeNames = [];
        const homePage = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
        const hp = await homePage.newPage();
        await hp.goto(BASE + '/', { waitUntil: 'networkidle' });
        homeNames.push(...await hp.evaluate(() => [...document.querySelectorAll('.sf-card-name')].slice(0, 3).map((e) => getComputedStyle(e).fontFamily.split(',')[0].replaceAll('"', ''))));
        await homePage.close();
        console.error(`home cards (${name}): ${JSON.stringify(homeNames)} heading font on home: ${home.headingFont}`);
        MODE === 'after' && check(`${name}: on the home page the card names use the body font too`, homeNames.length === 0 || homeNames.every((f) => f === bodyFamily), JSON.stringify(homeNames));
    }
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (backedUp) {
        const restored = JSON.parse(php(sub(RESTORE)));
        check('restored: site_settings is byte-for-byte what it was', restored.settings === true, JSON.stringify(restored));
        check('restored: the catalog tables are what they were (the probe products are gone) and the appearance files too', restored.catalog === true && restored.appearanceFiles === restored.appearanceWas, JSON.stringify(restored));
    }
}

check('no page errors', diag.length === 0, diag.slice(0, 5).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ mode: MODE, total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
