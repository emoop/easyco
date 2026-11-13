// A REAL-BROWSER check of P7-1: the card badges «Промо», «Нови», «Последни бройки» («Дизайн» → «Значки»).
//   At 1440 and 390 px: a throw-away shop slice (a category with 5 products: 1 on sale (manual sale 80.00 of 100.00) and new, 2 new, 3 old with 2 in stock, 4 old and plain, 5 old and sold out) and a home page with a product grid and a
//   carousel of that category. OFF (nothing published) → the cards carry no badge at all (the card HTML of a plain product is remembered); the set is published the way the zone does (BadgeStore): «Промо» as a percent, «Нови» 14 days, «Последни бройки»
//   up to 3. Then: the listing, the home grid and the home carousel show the right badges in the right corner (at most 2 on top, the bottom one under the price), one line each, no horizontal scroll, no console error; publishing everything
//   disabled again brings back the very same card HTML as before.
// NOT part of `php artisan test`: run by hand against the dev site (the stylesheet must be built: `npm run build`). BEFORE the run every checksum and the whole `site_settings` table are taken (a snapshot in a file OUTSIDE the project);
// at the end the settings are put back, everything the script created is deleted and the checksums are compared (a watchdog puts the data back after 15 minutes). The screenshots go to OUT, which must be OUTSIDE the project folder.
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p7-1.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p7-1.mjs'); process.exit(2); }
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
echo json_encode(['products' => $sum('catalog_products'), 'variations' => $sum('catalog_variations'), 'stock' => $sum('stock_levels'), 'prices' => $sum('pricing_price_list_items'), 'pivots' => $sum('catalog_product_categories').$sum('catalog_product_tags'), 'categories' => $sum('catalog_categories'), 'settings' => md5(json_encode(DB::table('site_settings')->orderBy('key')->get()->map(fn ($r) => (array) $r)->all())), 'lists' => $sum('pricing_price_lists'), 'jobs' => DB::table('jobs')->count()]);`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$pids = DB::table('catalog_products')->where('slug', 'like', 'p71-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('pricing_price_list_items')->where('target_type', 'variation')->whereIn('target_id', $vids->map(fn ($v) => (string) $v))->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
DB::table('catalog_categories')->where('slug', 'p71-audit-cat')->delete();
DB::table('jobs')->where('payload', 'like', '%RefreshProductSortPrices%')->delete();
echo 'cleaned';`;

// a throw-away category «P71 audit cat» with five products (simple, regular 100.00): 1 manual sale 80.00, new, stock 5; 2 new, stock 5; 3 created 60 days ago, stock 2; 4 created 60 days ago, stock 9; 5 created 60 days ago, stock 0 (sold out)
const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$old = now()->subDays(60);
$class = DB::table('catalog_variations')->whereNotNull('shipping_class')->value('shipping_class');
$group = DB::table('catalog_product_groups')->orderBy('id')->value('id');
$regular = DB::table('pricing_price_lists')->where('name', 'Regular Prices')->value('id');
$sample = DB::table('pricing_price_list_items')->where('price_list_id', $regular)->first();
$basis = $sample->price_tax_rate_basis_points ?? 0; $incl = $sample->price_tax_inclusive ?? 1;
$cat = DB::table('catalog_categories')->insertGetId(['name' => 'P71 audit cat', 'slug' => 'p71-audit-cat', 'created_at' => $now, 'updated_at' => $now]);
$stock = [1 => 5, 2 => 5, 3 => 2, 4 => 9, 5 => 0];
$ids = [];
for ($i = 1; $i <= 5; $i++) {
  $at = $i <= 2 ? $now : $old;
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'simple', 'name' => 'P71 audit '.$i, 'slug' => 'p71-audit-'.$i, 'base_sku' => 'P71AUDIT-'.$i, 'status' => 'active', 'catalog_visibility' => 'visible', 'product_group_id' => $group, 'created_at' => $at, 'updated_at' => $at, 'timeline_at' => $at->copy()->addSeconds(10 - $i), 'is_available' => $stock[$i] > 0 ? 1 : 0, 'price_from_minor' => 10000]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'universal', 'status' => 'active', 'attribute_signature' => hash('sha256', 'p71-'.$i), 'sku' => 'P71AUDIT-'.$i.'-0', 'is_visible' => 0, 'is_purchasable' => 1, 'shipping_class' => $class, 'created_at' => $at, 'updated_at' => $at]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => $stock[$i], 'created_at' => $now, 'updated_at' => $now]);
  DB::table('pricing_price_list_items')->insert(['price_list_id' => $regular, 'target_type' => 'variation', 'target_id' => (string) $vid, 'min_quantity' => 1, 'price_amount_minor' => 10000, 'price_currency' => 'EUR', 'price_tax_rate_basis_points' => $basis, 'price_tax_inclusive' => $incl, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  $ids[] = $pid;
  if ($i === 1) { app(App\Services\ProductPricingAndStock::class)->writeSalePrice((string) $vid, '80.00'); }
}
echo json_encode(['ids' => $ids, 'cat' => $cat]);`;

// the shop shows sold-out products for the run (so the sold-out card is there); the settings table is put back from the snapshot
const SHOW_SOLD_OUT = String.raw`use App\Settings\Contracts\SiteSettingsRepository; app(SiteSettingsRepository::class)->set('catalog.hide_sold_out', '0'); echo 'ok';`;

// the merchant's choices in «Дизайн»: a home page with a product grid and a carousel of the slice's category
const HOME = (world) => String.raw`
use App\Storefront\Home\HomeDrafts; use App\Storefront\Home\HomeValidator;
[$h, $e] = HomeValidator::validate(['spacing' => '32', 'sections' => [
  ['type' => 'product_grid', 'settings' => ['source' => 'category', 'entity' => ${world.cat}, 'count' => '12', 'sort' => 'newest']],
  ['type' => 'carousel', 'settings' => ['kind' => 'products', 'source' => 'category', 'entity' => ${world.cat}, 'arrows' => true]],
]]);
if ($e !== []) { throw new Exception(json_encode($e)); }
HomeDrafts::publish($h);
echo 'ok';`;

// the badge set, published the way the zone publishes it (BadgeStore): ON = the three built-ins, OFF = everything disabled (the key is forgotten)
// `over` (P7-1.1): rows by index merged over the ON set, e.g. {0: {shape: 'square', opacity: 80}}
const BADGES = (on, over = {}) => String.raw`
use App\Storefront\Badges\BadgeSet; use App\Storefront\Badges\BadgeStore;
$w = BadgeSet::defaults();
${on ? "$w['badges'][0]['enabled'] = true; $w['badges'][0]['mode'] = 'percent'; $w['badges'][1]['enabled'] = true; $w['badges'][2]['enabled'] = true;" : ''}
${Object.entries(over).map(([i, row]) => `$w['badges'][${i}] = array_replace($w['badges'][${i}], json_decode('${JSON.stringify(row)}', true));`).join(' ')}
[$c, $e] = BadgeSet::validate($w);
if ($e !== []) { throw new Exception(json_encode($e)); }
BadgeStore::publish($c);
echo 'ok';`;

// the settings rows are kept in a file (a command line is too short for them)
const SNAP_FILE = join(OUT, 'site_settings.snapshot.json').replace(/\\/g, '/');
const SNAPSHOT = String.raw`use Illuminate\Support\Facades\DB; file_put_contents('${SNAP_FILE}', json_encode(DB::table('site_settings')->get()->map(fn ($r) => (array) $r)->all())); echo 'saved';`;
const RESTORE = () => String.raw`use Illuminate\Support\Facades\DB; use App\Storefront\Filters\FilterCatalog; $rows = json_decode(file_get_contents('${SNAP_FILE}'), true); DB::table('site_settings')->delete(); foreach (array_chunk($rows, 50) as $chunk) { DB::table('site_settings')->insert($chunk); } FilterCatalog::forget(); app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh(); echo 'restored';`;

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
    check('the throw-away category and five products were created', world.ids.length === 5);
    php(SHOW_SOLD_OUT);
    php(HOME(world));
    php(BADGES(false));

    const noHScroll = async (page) => ! (await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1));
    // the badges of the card of a product on the page: {top: [texts], bottom: [texts]} (the FIRST card of that product)
    const badgesOf = async (page, id, scope = '') => page.evaluate(([pid, sc]) => {
        const card = document.querySelector(`${sc} [data-product-id="${pid}"]`);
        if (! card) return null;
        const texts = (selector) => [...card.querySelectorAll(selector + ' li')].map((li) => li.textContent.trim());
        return { top: texts('.sf-cbadges--top'), bottom: texts('.sf-cbadges--bottom'), sold: !! card.querySelector('.sf-stock-out') };
    }, [String(id), scope]);
    const cardHtml = async (page, id) => page.evaluate((pid) => document.querySelector(`[data-product-id="${pid}"]`)?.outerHTML ?? null, String(id));
    const total = async (page) => page.evaluate(() => document.querySelectorAll('.sf-cbadges').length);

    for (const width of [1440, 390]) {
        const ctx = await browser.newContext({ viewport: { width, height: 1400 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
        ctx.setDefaultTimeout(30000);
        const page = await ctx.newPage();
        page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR@${width} ` + String(e).slice(0, 200)));
        page.on('console', (m) => { if (m.type() === 'error' && ! /403|404|Failed to load resource/.test(m.text())) consoleErrors.push(`CONSOLE@${width} ` + m.text().slice(0, 200)); });
        page.on('response', (r) => { r.status() >= 500 && consoleErrors.push(`HTTP@${width} ` + r.status() + ' ' + r.url().slice(0, 120)); });
        const LIST = BASE + '/product-category/p71-audit-cat';

        // ---- OFF: nothing published, no badge anywhere; the HTML of a plain card is remembered
        php(BADGES(false));
        await page.goto(LIST, { waitUntil: 'networkidle' });
        check(`${width}: OFF: the listing prints no badge list at all`, (await total(page)) === 0);
        const plainBefore = await cardHtml(page, world.ids[3]);
        check(`${width}: OFF: the plain card was found`, plainBefore !== null && plainBefore.length > 100);
        const sOff = join(OUT, `p71-listing-off-${width}.png`); shots.push(sOff); await page.screenshot({ path: sOff, fullPage: true });

        // ---- ON: the listing
        php(BADGES(true));
        await page.goto(LIST, { waitUntil: 'networkidle' });
        const b = {}; for (let i = 0; i < 5; i++) b[i + 1] = await badgesOf(page, world.ids[i]);
        report[`listing-${width}`] = b;
        check(`${width}: ON: product 1 (on sale, new): «−20%» then «Нови» on top, at most two`, JSON.stringify(b[1]?.top) === JSON.stringify(['−20%', 'Нови']) && b[1].bottom.length === 0, JSON.stringify(b[1]));
        check(`${width}: ON: product 2 (new): «Нови» on top only`, JSON.stringify(b[2]?.top) === JSON.stringify(['Нови']) && b[2].bottom.length === 0, JSON.stringify(b[2]));
        check(`${width}: ON: product 3 (2 in stock): «Последни бройки» at the bottom only`, b[3]?.top.length === 0 && JSON.stringify(b[3]?.bottom) === JSON.stringify(['Последни бройки']), JSON.stringify(b[3]));
        check(`${width}: ON: product 4 (old, plain): no badge`, b[4]?.top.length === 0 && b[4]?.bottom.length === 0, JSON.stringify(b[4]));
        check(`${width}: ON: product 5 (sold out): no badge, «Изчерпан» stays`, b[5]?.top.length === 0 && b[5]?.bottom.length === 0 && b[5]?.sold === true, JSON.stringify(b[5]));

        // geometry: the top row sits in the upper-left corner of the picture frame; the bottom one is under the price; one line each
        const geo = await page.evaluate(([id1, id3]) => {
            const r = (el) => { const x = el.getBoundingClientRect(); return { l: x.left, t: x.top, r: x.right, b: x.bottom, h: x.height, w: x.width }; };
            const c1 = document.querySelector(`[data-product-id="${id1}"]`); const c3 = document.querySelector(`[data-product-id="${id3}"]`);
            const frame = r(c1.querySelector('.sf-card-image')); const top = [...c1.querySelectorAll('.sf-cbadges--top .sf-cbadge')].map(r);
            const price = r(c3.querySelector('.sf-price')); const bottom = r(c3.querySelector('.sf-cbadges--bottom .sf-cbadge'));
            const card = r(c3);
            return { frame, top, price, bottom, card };
        }, [String(world.ids[0]), String(world.ids[2])]);
        report[`geometry-${width}`] = geo;
        check(`${width}: ON: the top badges are in the upper-left corner of the picture frame`, geo.top.length === 2 && geo.top.every((t) => t.l - geo.frame.l <= 16 && t.t - geo.frame.t <= 40 && t.r <= geo.frame.r && t.b <= geo.frame.b), JSON.stringify(geo.top) + ' in ' + JSON.stringify(geo.frame));
        check(`${width}: ON: the two top badges are stacked (the second is under the first)`, geo.top[1].t >= geo.top[0].b - 1, JSON.stringify(geo.top));
        check(`${width}: ON: every badge is one line`, [...geo.top, geo.bottom].every((x) => x.h <= 28), JSON.stringify([...geo.top, geo.bottom].map((x) => x.h)));
        check(`${width}: ON: the bottom badge is under the price and inside the card`, geo.bottom.t >= geo.price.b - 1 && geo.bottom.r <= geo.card.r + 1, JSON.stringify(geo.bottom) + ' / price ' + JSON.stringify(geo.price));
        check(`${width}: ON: no badge text is cut (the whole word fits on its one line)`, await page.evaluate(() => [...document.querySelectorAll('.sf-cbadge')].every((el) => el.scrollWidth <= el.clientWidth)), 'a badge is truncated by its ellipsis');
        check(`${width}: ON: no horizontal scroll on the listing`, await noHScroll(page));
        const sOn = join(OUT, `p71-listing-on-${width}.png`); shots.push(sOn); await page.screenshot({ path: sOn, fullPage: true });

        // P7-1.1: the size (11px letters, 0.25rem 0.625rem padding), the default pairs and NO pill for the default shape (the menu's `.sf-badge` rule used to make every badge a pill)
        const look = await page.evaluate((id1) => {
            const cs = (el) => { const c = getComputedStyle(el); return { size: c.fontSize, pad: c.paddingTop + ' ' + c.paddingLeft, radius: c.borderTopLeftRadius, bg: c.backgroundColor, fg: c.color, transform: c.textTransform }; };
            const lis = [...document.querySelector(`[data-product-id="${id1}"]`).querySelectorAll('.sf-cbadges--top .sf-cbadge')].map(cs);
            return lis;
        }, String(world.ids[0]));
        report[`look-${width}`] = look;
        check(`${width}: ON: the badge is 11px, padded 4px 10px (4px 8px on a phone), upper case`, look.every((x) => x.size === '11px' && (x.pad === '4px 10px' || (x.pad === '4px 8px' && width <= 480)) && x.transform === 'uppercase'), JSON.stringify(look[0]));
        check(`${width}: ON: «−20%» is the default red pair (white on #be0f2c) and «Нови» the default black pair (white on #111)`, look[0].bg === 'rgb(190, 15, 44)' && look[0].fg === 'rgb(255, 255, 255)' && look[1].bg === 'rgb(17, 17, 17)' && look[1].fg === 'rgb(255, 255, 255)', JSON.stringify(look));
        check(`${width}: ON: the default shape is square, not a pill`, look.every((x) => x.radius === '0px'), JSON.stringify(look.map((x) => x.radius)));

        // the three shapes and the opacity, on the real badges: «Промо» square at 80%, «Нови» rounded, «Последни бройки» a pill
        php(BADGES(true, { 0: { shape: 'square', opacity: 80 }, 1: { shape: 'rounded' }, 2: { shape: 'pill' } }));
        await page.goto(LIST, { waitUntil: 'networkidle' });
        const shapes = await page.evaluate(([id1, id3]) => {
            const g = (el) => { const c = getComputedStyle(el); return { radius: c.borderTopLeftRadius, bg: c.backgroundColor, fg: c.color }; };
            const top = [...document.querySelector(`[data-product-id="${id1}"]`).querySelectorAll('.sf-cbadges--top .sf-cbadge')].map(g);
            const bottom = g(document.querySelector(`[data-product-id="${id3}"] .sf-cbadges--bottom .sf-cbadge`));
            return { top, bottom };
        }, [String(world.ids[0]), String(world.ids[2])]);
        report[`shapes-${width}`] = shapes;
        check(`${width}: the three shapes really differ: square 0px, rounded 4px, pill ≥ 9999px`, shapes.top[0].radius === '0px' && shapes.top[1].radius === '4px' && parseFloat(shapes.bottom.radius) >= 9999 || shapes.bottom.radius.includes('3.35544e+07'), JSON.stringify(shapes));
        check(`${width}: opacity 80 paints only the BACKGROUND (rgba alpha 0.8, the text stays opaque white)`, shapes.top[0].bg === 'rgba(190, 15, 44, 0.8)' && shapes.top[0].fg === 'rgb(255, 255, 255)', JSON.stringify(shapes.top[0]));
        check(`${width}: «Последни бройки» is the orange pair (black on #f59e0b)`, shapes.bottom.bg === 'rgb(245, 158, 11)' && shapes.bottom.fg === 'rgb(0, 0, 0)', JSON.stringify(shapes.bottom));
        const sShapes = join(OUT, `p71-shapes-${width}.png`); shots.push(sShapes); await page.screenshot({ path: sShapes, fullPage: false });

        // P7-1.2: the offsets of the picture-frame badges (10px desktop, 1.6px phone), the left edges of the stack, the third position «image_bottom» and black at 70 %
        const offsets = async (cfg) => {
            php(BADGES(true, cfg));
            await page.goto(LIST, { waitUntil: 'networkidle' });
            return page.evaluate((id1) => {
                const card = document.querySelector(`[data-product-id="${id1}"]`);
                const r = (el) => { const x = el.getBoundingClientRect(); return { l: x.left, t: x.top, r: x.right, b: x.bottom }; };
                const frame = r(card.querySelector('.sf-card-image'));
                const top = [...card.querySelectorAll('.sf-cbadges--top .sf-cbadge')].map(r);
                const ib = [...card.querySelectorAll('.sf-card-image .sf-cbadges--image-bottom .sf-cbadge')];
                const c = ib[0] ? getComputedStyle(ib[0]) : null;
                return { frame, top, ib: ib.map(r), ibBg: c?.backgroundColor ?? null, ibFg: c?.color ?? null, ibCount: card.querySelectorAll('.sf-cbadges--image-bottom').length, under: !! card.querySelector('.sf-card-footer .sf-cbadges--bottom') };
            }, String(world.ids[0]));
        };
        const want0 = width <= 480 ? 1.6 : 10;
        const near = (v) => Math.abs(v - want0) <= 1.2;
        // A: «Промо» + «Последни бройки» (max 6) both on top, «Нови» (black, 70 %) at the bottom of the picture
        const A = await offsets({ 1: { position: 'image_bottom', opacity: 70 }, 2: { max: 6, position: 'top' } });
        report[`p712-A-${width}`] = A;
        check(`${width}: P7-1.2: two top badges: offset from the frame ≈ ${want0}px`, A.top.length === 2 && near(A.top[0].l - A.frame.l) && near(A.top[0].t - A.frame.t), JSON.stringify(A.top[0]) + ' frame ' + JSON.stringify(A.frame));
        check(`${width}: P7-1.2: the second top badge has the first's left edge and sits under it`, A.top[1].l === A.top[0].l && A.top[1].t >= A.top[0].b - 1, JSON.stringify(A.top));
        check(`${width}: P7-1.2: the image_bottom badge is at the bottom-left of the frame (≈ ${want0}px)`, A.ib.length === 1 && near(A.ib[0].l - A.frame.l) && near(A.frame.b - A.ib[0].b), JSON.stringify(A.ib) + ' frame ' + JSON.stringify(A.frame));
        check(`${width}: P7-1.2: black at 70 % is rgba(17, 17, 17, 0.7) with opaque white text`, A.ibBg === 'rgba(17, 17, 17, 0.7)' && A.ibFg === 'rgb(255, 255, 255)', A.ibBg + ' ' + A.ibFg);
        // B: one card with a top badge, an image_bottom badge and an under-price badge
        const Bc = await offsets({ 1: { position: 'image_bottom', opacity: 70 }, 2: { max: 6 } });
        report[`p712-B-${width}`] = Bc;
        check(`${width}: P7-1.2: one card carries top, image_bottom and under-price badges`, Bc.top.length === 1 && Bc.ib.length === 1 && Bc.ibCount === 1 && Bc.under === true, JSON.stringify({ top: Bc.top.length, ib: Bc.ib.length, under: Bc.under }));
        const s712 = join(OUT, `p712-${width}.png`); shots.push(s712); await (await page.locator(`[data-product-id="${world.ids[0]}"]`).first()).screenshot({ path: s712 });
        await page.goto(LIST, { waitUntil: 'networkidle' });
        // all six pairs side by side, drawn with the very classes of the stylesheet (test-only DOM, the shop's pages never get it)
        const six = await page.evaluate(() => {
            const pairs = ['red', 'orange', 'blue', 'green', 'black', 'grey'];
            const ul = document.createElement('ul'); ul.className = 'sf-cbadges'; ul.id = 'p71-six'; ul.style.cssText = 'flex-wrap:wrap;padding:12px;background:#ffffff;gap:8px';
            for (const c of pairs) { const li = document.createElement('li'); li.className = `sf-cbadge sf-cbadge--${c} sf-cbadge--pill sf-cbadge--o100`; li.textContent = c; ul.appendChild(li); }
            const grey = document.createElement('ul'); grey.className = 'sf-cbadges'; grey.id = 'p71-six-b'; grey.style.cssText = 'flex-wrap:wrap;padding:12px;background:#ffffff;gap:8px';
            for (const c of pairs) { const li = document.createElement('li'); li.className = `sf-cbadge sf-cbadge--${c} sf-cbadge--square sf-cbadge--o80`; li.textContent = c + ' 80'; grey.appendChild(li); }
            const wrap = document.createElement('div'); wrap.id = 'p71-wrap'; wrap.append(ul, grey);
            document.querySelector('main').prepend(wrap);
            const out = {}; for (const li of ul.querySelectorAll('li')) { const c = getComputedStyle(li); out[li.textContent] = [c.backgroundColor, c.color]; }
            return out;
        });
        report[`six-${width}`] = six;
        const want = { red: ['rgb(190, 15, 44)', 'rgb(255, 255, 255)'], orange: ['rgb(245, 158, 11)', 'rgb(0, 0, 0)'], blue: ['rgb(30, 64, 175)', 'rgb(255, 255, 255)'], green: ['rgb(21, 97, 47)', 'rgb(255, 255, 255)'], black: ['rgb(17, 17, 17)', 'rgb(255, 255, 255)'], grey: ['rgb(229, 229, 229)', 'rgb(17, 17, 17)'] };
        check(`${width}: the six pairs paint exactly their background and text colours`, Object.keys(want).every((k) => JSON.stringify(six[k]) === JSON.stringify(want[k])), JSON.stringify(six));
        const sSix = join(OUT, `p71-six-${width}.png`); shots.push(sSix); await (await page.locator('#p71-wrap')).screenshot({ path: sSix });
        php(BADGES(true));

        // ---- ON: the home page, the grid and the carousel
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        const grid = await page.evaluate(() => { const sec = [...document.querySelectorAll('.sf-product-grid, .sf-home-grid, [class*="product-grid"]')][0]; return sec ? sec.className : null; });
        report[`grid-class-${width}`] = grid;
        const all = await page.evaluate((id) => [...document.querySelectorAll(`[data-product-id="${id}"]`)].map((card) => ({ car: !! card.closest('.sf-car-card, .sf-carousel, [class*="sf-car-"]'), top: [...card.querySelectorAll('.sf-cbadges--top li')].map((li) => li.textContent.trim()) })), String(world.ids[0]));
        report[`home-${width}`] = all;
        check(`${width}: ON: product 1 is on the home page in the grid and in the carousel, with the same badges`, all.length >= 2 && all.some((c) => c.car) && all.some((c) => ! c.car) && all.every((c) => JSON.stringify(c.top) === JSON.stringify(['−20%', 'Нови'])), JSON.stringify(all));
        check(`${width}: ON: no horizontal scroll on the home page`, await noHScroll(page));
        const sH = join(OUT, `p71-home-${width}.png`); shots.push(sH); await page.screenshot({ path: sH, fullPage: true });

        // ---- OFF again: the very same card HTML as before
        php(BADGES(false));
        await page.goto(LIST, { waitUntil: 'networkidle' });
        check(`${width}: OFF again: no badge list at all`, (await total(page)) === 0);
        check(`${width}: OFF again: the plain card is byte for byte what it was`, (await cardHtml(page, world.ids[3])) === plainBefore);
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
