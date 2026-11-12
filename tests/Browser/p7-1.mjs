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
const BADGES = (on) => String.raw`
use App\Storefront\Badges\BadgeSet; use App\Storefront\Badges\BadgeStore;
$w = BadgeSet::defaults();
${on ? "$w['badges'][0]['enabled'] = true; $w['badges'][0]['mode'] = 'percent'; $w['badges'][1]['enabled'] = true; $w['badges'][2]['enabled'] = true;" : ''}
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
        return { top: texts('.sf-badges--top'), bottom: texts('.sf-badges--bottom'), sold: !! card.querySelector('.sf-stock-out') };
    }, [String(id), scope]);
    const cardHtml = async (page, id) => page.evaluate((pid) => document.querySelector(`[data-product-id="${pid}"]`)?.outerHTML ?? null, String(id));
    const total = async (page) => page.evaluate(() => document.querySelectorAll('.sf-badges').length);

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
            const frame = r(c1.querySelector('.sf-card-image')); const top = [...c1.querySelectorAll('.sf-badges--top .sf-badge')].map(r);
            const price = r(c3.querySelector('.sf-price')); const bottom = r(c3.querySelector('.sf-badges--bottom .sf-badge'));
            const card = r(c3);
            return { frame, top, price, bottom, card };
        }, [String(world.ids[0]), String(world.ids[2])]);
        report[`geometry-${width}`] = geo;
        check(`${width}: ON: the top badges are in the upper-left corner of the picture frame`, geo.top.length === 2 && geo.top.every((t) => t.l - geo.frame.l <= 16 && t.t - geo.frame.t <= 40 && t.r <= geo.frame.r && t.b <= geo.frame.b), JSON.stringify(geo.top) + ' in ' + JSON.stringify(geo.frame));
        check(`${width}: ON: the two top badges are stacked (the second is under the first)`, geo.top[1].t >= geo.top[0].b - 1, JSON.stringify(geo.top));
        check(`${width}: ON: every badge is one line`, [...geo.top, geo.bottom].every((x) => x.h <= 26), JSON.stringify([...geo.top, geo.bottom].map((x) => x.h)));
        check(`${width}: ON: the bottom badge is under the price and inside the card`, geo.bottom.t >= geo.price.b - 1 && geo.bottom.r <= geo.card.r + 1, JSON.stringify(geo.bottom) + ' / price ' + JSON.stringify(geo.price));
        check(`${width}: ON: no horizontal scroll on the listing`, await noHScroll(page));
        const sOn = join(OUT, `p71-listing-on-${width}.png`); shots.push(sOn); await page.screenshot({ path: sOn, fullPage: true });

        // ---- ON: the home page, the grid and the carousel
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        const grid = await page.evaluate(() => { const sec = [...document.querySelectorAll('.sf-product-grid, .sf-home-grid, [class*="product-grid"]')][0]; return sec ? sec.className : null; });
        report[`grid-class-${width}`] = grid;
        const all = await page.evaluate((id) => [...document.querySelectorAll(`[data-product-id="${id}"]`)].map((card) => ({ car: !! card.closest('.sf-car-card, .sf-carousel, [class*="sf-car-"]'), top: [...card.querySelectorAll('.sf-badges--top li')].map((li) => li.textContent.trim()) })), String(world.ids[0]));
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
