// A REAL-BROWSER check of P7-3: the badges on the PRODUCT PAGE (the same published set as the cards).
//   A throw-away shop slice (a category with 5 products, 1 on sale and new with FIVE generated pictures; 2 picture-less) and, at 1440 and 390, every gallery mode (desktop: grid, thumbnails left, thumbnails below;
//   phone: carousel, thumbnails below). Product 1 carries «−20%» and «Нови» on top, a custom «Лято» over the picture at the bottom, «Последни бройки» and «Безплатна доставка» under the price: the offsets from the
//   picture frame (≈10px desktop, ≈1.6px phone), the second top badge's left edge, nothing cut, the badges catch no click (a click through a badge opens the lightbox), the badges stay over the visible picture after a
//   thumbnail is chosen, no horizontal scroll; the picture-less product shows its top badge in the placeholder; OFF = no `.sf-cbadges` and no overlay on the page.
// NOT part of `php artisan test`: run by hand against the dev site. BEFORE the run every checksum and the whole `site_settings` table are taken (a snapshot in a file OUTSIDE the project folder); at the end the settings are
// put back, everything the script created (rows and the picture files) is deleted and the checksums are compared (a watchdog puts the data back after 15 minutes). The screenshots go to OUT, outside the project folder.
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p7-3.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p7-3.mjs'); process.exit(2); }
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
echo json_encode(['products' => $sum('catalog_products'), 'variations' => $sum('catalog_variations'), 'stock' => $sum('stock_levels'), 'prices' => $sum('pricing_price_list_items'), 'pivots' => $sum('catalog_product_categories').$sum('catalog_product_tags'), 'categories' => $sum('catalog_categories'), 'settings' => md5(json_encode(DB::table('site_settings')->orderBy('key')->get()->map(fn ($r) => (array) $r)->all())), 'lists' => $sum('pricing_price_lists'), 'jobs' => DB::table('jobs')->count(), 'staff' => DB::table('staff')->count(), 'threshold' => md5(json_encode(app(App\Services\FreeShippingThreshold::class)->refresh()))]);`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$pids = DB::table('catalog_products')->where('slug', 'like', 'p73-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('pricing_price_list_items')->where('target_type', 'variation')->whereIn('target_id', $vids->map(fn ($v) => (string) $v))->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
DB::table('catalog_categories')->where('slug', 'p73-audit-cat')->delete();
DB::table('jobs')->where('payload', 'like', '%RefreshProductSortPrices%')->delete();
echo 'cleaned';`;

// a throw-away category «P73 audit cat» with five products (simple, regular 100.00): 1 manual sale 80.00, new, stock 5; 2 new, stock 5; 3 created 60 days ago, stock 2; 4 created 60 days ago, stock 9; 5 created 60 days ago, stock 0 (sold out)
const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$old = now()->subDays(60);
$class = DB::table('catalog_variations')->whereNotNull('shipping_class')->value('shipping_class');
$group = DB::table('catalog_product_groups')->orderBy('id')->value('id');
$regular = DB::table('pricing_price_lists')->where('name', 'Regular Prices')->value('id');
$sample = DB::table('pricing_price_list_items')->where('price_list_id', $regular)->first();
$basis = $sample->price_tax_rate_basis_points ?? 0; $incl = $sample->price_tax_inclusive ?? 1;
$cat = DB::table('catalog_categories')->insertGetId(['name' => 'P73 audit cat', 'slug' => 'p73-audit-cat', 'created_at' => $now, 'updated_at' => $now]);
$stock = [1 => 5, 2 => 5, 3 => 2, 4 => 9, 5 => 0];
$ids = [];
for ($i = 1; $i <= 5; $i++) {
  $at = $i <= 2 ? $now : $old;
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'simple', 'name' => 'P73 audit '.$i, 'slug' => 'p73-audit-'.$i, 'base_sku' => 'P73AUDIT-'.$i, 'status' => 'active', 'catalog_visibility' => 'visible', 'product_group_id' => $group, 'created_at' => $at, 'updated_at' => $at, 'timeline_at' => $at->copy()->addSeconds(10 - $i), 'is_available' => $stock[$i] > 0 ? 1 : 0, 'price_from_minor' => 10000]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'universal', 'status' => 'active', 'attribute_signature' => hash('sha256', 'p73-'.$i), 'sku' => 'P73AUDIT-'.$i.'-0', 'is_visible' => 0, 'is_purchasable' => 1, 'shipping_class' => $class, 'created_at' => $at, 'updated_at' => $at]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => $stock[$i], 'created_at' => $now, 'updated_at' => $now]);
  DB::table('pricing_price_list_items')->insert(['price_list_id' => $regular, 'target_type' => 'variation', 'target_id' => (string) $vid, 'min_quantity' => 1, 'price_amount_minor' => 10000, 'price_currency' => 'EUR', 'price_tax_rate_basis_points' => $basis, 'price_tax_inclusive' => $incl, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  $ids[] = $pid;
  if ($i === 1) { app(App\Services\ProductPricingAndStock::class)->writeSalePrice((string) $vid, '80.00'); }
}
echo json_encode(['ids' => $ids, 'cat' => $cat]);`;

// the shop shows sold-out products for the run (so the sold-out card is there); the settings table is put back from the snapshot
const SHOW_SOLD_OUT = String.raw`use App\Settings\Contracts\SiteSettingsRepository; app(SiteSettingsRepository::class)->set('catalog.hide_sold_out', '0'); echo 'ok';`;

// the badge set, published the way the zone publishes it (BadgeStore). `over`: rows by index merged over the defaults (0 sale, 1 new, 2 low_stock, 3 free_shipping); `custom`: custom rows appended
const BADGES = (over = {}, custom = []) => String.raw`
use App\Storefront\Badges\BadgeSet; use App\Storefront\Badges\BadgeStore;
$w = BadgeSet::defaults();
${Object.entries(over).map(([i, row]) => `$w['badges'][${i}] = array_replace($w['badges'][${i}], json_decode('${JSON.stringify(row)}', true));`).join(' ')}
${custom.map((row) => `$w['badges'][] = json_decode('${JSON.stringify(row)}', true);`).join(' ')}
[$c, $e] = BadgeSet::validate($w);
if ($e !== []) { throw new Exception(json_encode($e)); }
BadgeStore::publish($c);
echo 'ok';`;

// the cached free-shipping threshold is set directly (the shop's real zones and methods are not touched); the end of the run recomputes it from the real data
const THRESHOLD = (minor) => String.raw`Illuminate\Support\Facades\Cache::forever(App\Services\FreeShippingThreshold::KEY, ['minor' => ${minor}, 'currency' => 'EUR']); echo 'ok';`;
// the settings rows are kept in a file (a command line is too short for them)
const SNAP_FILE = join(OUT, 'site_settings.snapshot.json').replace(/\\/g, '/');
const SNAPSHOT = String.raw`use Illuminate\Support\Facades\DB; file_put_contents('${SNAP_FILE}', json_encode(DB::table('site_settings')->get()->map(fn ($r) => (array) $r)->all())); echo 'saved';`;
const RESTORE = () => String.raw`use Illuminate\Support\Facades\DB; use App\Storefront\Filters\FilterCatalog; $rows = json_decode(file_get_contents('${SNAP_FILE}'), true); DB::table('site_settings')->delete(); foreach (array_chunk($rows, 50) as $chunk) { DB::table('site_settings')->insert($chunk); } FilterCatalog::forget(); app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh(); app(App\Services\FreeShippingThreshold::class)->refresh(); echo 'restored';`;



// product 1 gets FIVE generated pictures (files under public/p73, rows in catalog_media / catalog_product_media); product 2 stays picture-less (the placeholder)
const IMAGES = (productId) => String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$now = now();
$colours = [[200,60,60],[60,140,200],[80,170,90],[220,170,40],[140,80,180]];
foreach ($colours as $i => $c) {
  $n = $i + 1; $variants = [];
  foreach (['thumbnail'=>300,'medium'=>600,'large'=>900] as $tier => $w) {
    $h = (int) ($w * 4 / 3); $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, $c[0], $c[1], $c[2]));
    imagefilledrectangle($im, (int) ($w*0.1), (int) ($h*0.1), (int) ($w*0.9), (int) ($h*0.9), imagecolorallocate($im, $c[0]-30, $c[1]-30, $c[2]-30));
    ob_start(); imagewebp($im, null, 80); $bytes = ob_get_clean();
    Storage::disk('public')->put("p73/$n-$tier.webp", $bytes);
    $variants[] = ['tier'=>$tier,'width'=>$w,'height'=>$h,'quality'=>80,'path'=>"p73/$n-$tier.webp"];
  }
  $mid = DB::table('catalog_media')->insertGetId(['type'=>'image','disk'=>'public','path'=>"p73/$n.jpg",'processing_status'=>'ready','variants'=>json_encode($variants),'mime'=>'image/jpeg','width'=>900,'height'=>1200,'created_at'=>$now,'updated_at'=>$now]);
  DB::table('catalog_product_media')->insert(['product_id'=>${productId},'media_id'=>$mid,'sort_order'=>$n]);
}
echo 'ok';`;
const CLEAN_IMAGES = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$mids = DB::table('catalog_media')->where('path', 'like', 'p73/%')->pluck('id');
DB::table('catalog_product_media')->whereIn('media_id', $mids)->delete();
DB::table('catalog_media')->whereIn('id', $mids)->delete();
Storage::disk('public')->deleteDirectory('p73');
echo 'ok';`;
const GALLERY = (desktop, mobile) => String.raw`use App\Settings\Contracts\SiteSettingsRepository; app(SiteSettingsRepository::class)->set('storefront.gallery.desktop_mode', '${desktop}'); app(SiteSettingsRepository::class)->set('storefront.gallery.mobile_mode', '${mobile}'); echo 'ok';`;

// ON: the four built-ins and ONE custom badge «Лято» (on sale) over the picture, at the bottom; «Последни бройки» up to 6
const ALL_ON = [{ 0: { enabled: true, mode: 'percent' }, 1: { enabled: true }, 2: { enabled: true, max: 6 }, 3: { enabled: true } },
    [{ kind: 'custom', id: 'p73audit001', enabled: true, text: 'Лято', position: 'image_bottom', colour: 'blue', opacity: 100, shape: 'square', priority: 15, conditions: { on_sale: true } }]];

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = []; const consoleErrors = []; const shots = []; const report = {};
const check = (name, ok, detail = '') => { process.env.TRACE && console.error((ok ? '· ' : 'FAIL ') + name.slice(0, 90)); steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + String(detail).slice(0, 300)); };

let before = null; let snapshot = false; let watchdog = null;
const putBack = () => { try { php(CLEANUP); } catch (e) { console.error('cleanup failed ' + e); } try { php(CLEAN_IMAGES); } catch (e) { console.error('image cleanup failed ' + e); } if (snapshot) { php(RESTORE()); } };

try {
    before = JSON.parse(php(SUMS));
    snapshot = php(SNAPSHOT) === 'saved';
    watchdog = setTimeout(() => { putBack(); console.error('WATCHDOG: the run took more than 15 minutes; the data was put back'); process.exit(3); }, 15 * 60 * 1000);
    const world = JSON.parse(php(SETUP));
    check('the throw-away category and five products were created', world.ids.length === 5);
    php(SHOW_SOLD_OUT);
    php(IMAGES(world.ids[0]));
    php(THRESHOLD(8000));

    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const shot = async (page, name) => { const p = join(OUT, name + '.png'); shots.push(p); await page.screenshot({ path: p, fullPage: false }); };
    const watch = (page, tag) => {
        page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR@${tag} ` + String(e).slice(0, 200)));
        page.on('console', (m) => { if (m.type() === 'error' && ! /403|404|Failed to load resource/.test(m.text())) consoleErrors.push(`CONSOLE@${tag} ` + m.text().slice(0, 200)); });
        page.on('response', (r) => { r.status() >= 500 && consoleErrors.push(`HTTP@${tag} ` + r.status() + ' ' + r.url().slice(0, 120)); });
    };
    const noHScroll = (page) => page.evaluate(() => ! (document.documentElement.scrollWidth > document.documentElement.clientWidth + 1));

    // what the page's badges look like in numbers
    const measure = (page) => page.evaluate(() => {
        const r = (el) => { const x = el.getBoundingClientRect(); return { l: x.left, t: x.top, r: x.right, b: x.bottom, h: x.height, w: x.width, cut: el.scrollWidth > el.clientWidth }; };
        const visible = (el) => el.getClientRects().length > 0;
        const items = [...document.querySelectorAll('.sf-gal-item')].filter(visible);
        const frame = items[0] ? r(items[0]) : null;
        const price = document.querySelector('.sf-summary .sf-price');
        const bottom = document.querySelector('.sf-summary .sf-cbadges--bottom');
        return {
            frame,
            overlays: document.querySelectorAll('.sf-gal-badges').length,
            top: [...document.querySelectorAll('.sf-gal-badges .sf-cbadges--top .sf-cbadge')].map(r),
            ib: [...document.querySelectorAll('.sf-gal-badges .sf-cbadges--image-bottom .sf-cbadge')].map(r),
            bottom: [...document.querySelectorAll('.sf-summary .sf-cbadges--bottom .sf-cbadge')].map((el) => ({ ...r(el), text: el.textContent.trim() })),
            bottomList: bottom ? r(bottom) : null,
            price: price ? r(price) : null,
            texts: { top: [...document.querySelectorAll('.sf-gal-badges .sf-cbadges--top li')].map((li) => li.textContent.trim()), ib: [...document.querySelectorAll('.sf-gal-badges .sf-cbadges--image-bottom li')].map((li) => li.textContent.trim()) },
            all: document.querySelectorAll('.sf-cbadges').length,
            inLinks: document.querySelectorAll('.sf-gal-link .sf-cbadges').length,
        };
    });

    // ---- OFF: nothing on the page (the product page of the picture product and of the picture-less one)
    php(BADGES({}, []));
    {
        const ctx = await browser.newContext({ viewport: { width: 1440, height: 1400 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
        const page = await ctx.newPage(); watch(page, 'off');
        for (const id of [world.ids[0], world.ids[1]]) {
            await page.goto(BASE + '/product/p73-audit-' + (world.ids.indexOf(id) + 1), { waitUntil: 'networkidle' });
            check(`OFF: product ${world.ids.indexOf(id) + 1}: no badge list and no overlay on the page`, (await page.locator('.sf-cbadges').count()) === 0 && (await page.locator('.sf-gal-badges').count()) === 0);
        }
        await ctx.close();
    }

    php(BADGES(...ALL_ON));
    const MODES = { 1440: [['grid', 'carousel'], ['thumbs-left', 'carousel'], ['thumbs-below', 'carousel']], 390: [['grid', 'carousel'], ['grid', 'thumbs-below']] };

    for (const width of [1440, 390]) {
        const want = width <= 480 ? 1.6 : 10;
        const near = (v) => Math.abs(v - want) <= 1.5;
        const ctx = await browser.newContext({ viewport: { width, height: 1300 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
        ctx.setDefaultTimeout(30000);
        const page = await ctx.newPage(); watch(page, String(width));

        for (const [desktop, mobile] of MODES[width]) {
            const mode = width >= 1024 ? desktop : mobile;
            php(GALLERY(desktop, mobile));
            await page.goto(BASE + '/product/p73-audit-1', { waitUntil: 'networkidle' });
            await sleep(600);
            const m = await measure(page);
            report[`m-${width}-${mode}`] = m;
            const tag = `${width} ${mode}`;
            check(`${tag}: ONE overlay with «−20%» «Нови» on top, «Лято» over the picture at the bottom; the badges are not repeated per thumbnail`, m.overlays === 1 && JSON.stringify(m.texts.top) === JSON.stringify(['−20%', 'Нови']) && JSON.stringify(m.texts.ib) === JSON.stringify(['Лято']) && m.inLinks === 0, JSON.stringify(m.texts) + ' overlays ' + m.overlays);
            check(`${tag}: top badge ≈ ${want}px from the frame's upper-left corner; the second has the same left edge and is stacked under it`, m.frame && m.top.length === 2 && near(m.top[0].l - m.frame.l) && near(m.top[0].t - m.frame.t) && Math.abs(m.top[1].l - m.top[0].l) < 0.5 && m.top[1].t >= m.top[0].b - 1, JSON.stringify(m.top) + ' frame ' + JSON.stringify(m.frame));
            check(`${tag}: the over-the-picture bottom badge ≈ ${want}px from the frame's lower-left corner`, m.ib.length === 1 && near(m.ib[0].l - m.frame.l) && near(m.frame.b - m.ib[0].b), JSON.stringify(m.ib) + ' frame ' + JSON.stringify(m.frame));
            check(`${tag}: nothing is cut and every picture badge is one line`, [...m.top, ...m.ib].every((b) => ! b.cut && b.h <= 28), JSON.stringify([...m.top, ...m.ib].map((b) => [b.cut, b.h])));
            check(`${tag}: the under-the-price row has both badges, is below the price and inside the info column`, m.bottom.map((b) => b.text).join('|') === 'Последни бройки|Безплатна доставка' && m.bottomList.t >= m.price.b - 1 && m.bottom.every((b) => ! b.cut), JSON.stringify(m.bottom) + ' price ' + JSON.stringify(m.price));
            check(`${tag}: no horizontal scroll`, await noHScroll(page));

            // the badges catch no click: the element under a badge is the picture's link, and a click there opens the lightbox
            const under = await page.evaluate(([x, y]) => { const el = document.elementFromPoint(x, y); return el ? (el.closest('.sf-cbadges') ? 'badge' : (el.closest('.sf-gal-link') ? 'link' : el.className)) : null; }, [m.top[0].l + 4, m.top[0].t + 4]);
            check(`${tag}: a click on a badge reaches the picture (the badges catch no click)`, under === 'link', String(under));
            await page.mouse.click(m.top[0].l + 4, m.top[0].t + 4);
            await sleep(700);
            const opened = await page.evaluate(() => document.querySelector('dialog.sf-lightbox')?.open === true);
            check(`${tag}: the lightbox still opens from a click on the picture under a badge`, opened);
            if (opened) { await page.keyboard.press('Escape'); await sleep(400); }

            if (mode.startsWith('thumbs')) {
                // another picture selected: the badges stay over the visible picture
                await page.locator('.sf-gal-thumb').nth(2).click();
                await sleep(500);
                const m2 = await measure(page);
                check(`${tag}: after choosing the third thumbnail the badges are still over the visible picture, at the same offsets`, m2.overlays === 1 && m2.top.length === 2 && near(m2.top[0].l - m2.frame.l) && near(m2.top[0].t - m2.frame.t) && near(m2.frame.b - m2.ib[0].b), JSON.stringify(m2.top[0]) + ' frame ' + JSON.stringify(m2.frame));
            }
            await shot(page, `p73-${width}-${mode}-${desktop}`);
        }

        // a product with no picture: the placeholder holds the top badge, no longer aria-hidden
        php(GALLERY('grid', 'carousel'));
        await page.goto(BASE + '/product/p73-audit-2', { waitUntil: 'networkidle' });
        const ph = await page.evaluate(() => { const p = document.querySelector('.sf-gal-placeholder'); return p ? { hidden: p.getAttribute('aria-hidden'), top: [...p.querySelectorAll('.sf-cbadges--top li')].map((li) => li.textContent.trim()), box: p.getBoundingClientRect().toJSON(), first: p.querySelector('.sf-cbadge')?.getBoundingClientRect().toJSON() ?? null } : null; });
        report[`placeholder-${width}`] = ph;
        check(`${width}: a product with no picture shows «Нови» in the placeholder (not aria-hidden), at the upper-left corner`, ph && ph.hidden === null && ph.top.join('|') === 'Нови' && near(ph.first.left - ph.box.left) && near(ph.first.top - ph.box.top), JSON.stringify(ph));
        check(`${width}: no horizontal scroll on the picture-less page`, await noHScroll(page));
        await shot(page, `p73-${width}-no-picture`);
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
