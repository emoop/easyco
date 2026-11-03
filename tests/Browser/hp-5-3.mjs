// A REAL-BROWSER check of HP-5.3: the carousel arrows of the home page have the SAME look as the arrows of the product gallery in its phone «carousel» mode (white round disc at 90%, the same
// shadow, the same chevron), at 390px and 1280px, for a product carousel and a brand-logo carousel. The gallery's arrow is measured on a product page at 390px in the same run (the reference).
// It checks, for each carousel arrow:
//   - the touch target is 32–44px wide and at least 44px high; the arrow is within 1px of the row's left / right edge and vertically centred on the row;
//   - the disc (the arrow's own background) equals the gallery arrow's background, shadow, text colour and size (content box 40px); no border; no filter;
//   - the chevron (::before) has the gallery's size and border widths; the «next» chevron has the gallery's transform;
//   - the «previous» arrow is disabled at the start (opacity 0.35), the «next» arrow is not; nothing scrolls the page sideways; with JavaScript disabled the arrows stay hidden.
// It also measures the gallery arrow (position, size, colours, chevron) and prints it in the report under `measurements`: that is the Step 0 inventory.
// Screenshots: each carousel at both widths, the product page gallery at 390px, and 3× zooms of one carousel arrow and the gallery arrow over the same dark photo.
// NOT part of `php artisan test`: run by hand against a dev site. It creates ONE throw-away staff member, ONE category, 12 products, ONE gallery product with 3 photos, and 3 + 9 brands with generated logos
// (files in storage/app/public/uploads/2026/11, hp53-audit-*). Every `site_settings` row (the published home layout included) is put back EXACTLY by id, the checksum is verified, and everything created is removed.
// Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/hp-5-3.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node hp-5-3.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

// a non-zero exit OR an empty answer is an error, with the tail of both streams
const php = (code) => {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    const out = (r.stdout ?? '').trim();
    if (r.status !== 0 || out === '') {
        throw new Error(`php failed (status ${r.status}): ` + (out + '\n' + (r.stderr ?? '')).slice(-900));
    }
    return out.split('\n').pop();
};

const EMAIL = `hp53-${Date.now()}@example.test`;
const PASSWORD = 'Hp53-' + Math.random().toString(36).slice(2) + 'Aa1!';
const CATEGORY = 'hp53-audit-cat';
const GALLERY = 'hp53-audit-gallery';
const b64 = (value) => Buffer.from(JSON.stringify(value)).toString('base64');

// every site_settings row (the published home layout among them) and the counts of everything this script creates
const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$published = DB::table('site_settings')->where('key', 'design.home.global.published')->first();
echo json_encode(['settings' => md5(json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 'published' => $published ? [$published->id, md5((string) $published->value)] : null, 'staff' => DB::table('staff')->count(), 'categories' => DB::table('catalog_categories')->count(), 'brands' => DB::table('catalog_brands')->count(), 'products' => DB::table('catalog_products')->count(), 'variations' => DB::table('catalog_variations')->count(), 'stock' => DB::table('stock_levels')->count(), 'product_media' => DB::table('catalog_product_media')->count(), 'product_categories' => DB::table('catalog_product_categories')->count(), 'catalog_media' => DB::table('catalog_media')->count(), 'files' => count(glob(storage_path('app/public/uploads/2026/11/hp53-audit-*')))]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/hp53_backup.json', json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo 'ok';`;

// settings back by id, everything created removed (rows and files), settings once more
const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/hp53_backup.json'), true);
$put = function () use ($bak) { DB::table('site_settings')->whereNotIn('id', array_column($bak, 'id'))->delete(); foreach ($bak as $row) { DB::table('site_settings')->where('id', $row['id'])->update(['key' => $row['key'], 'value' => $row['value'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]); if (! DB::table('site_settings')->where('id', $row['id'])->exists()) { DB::table('site_settings')->insert($row); } } };
$put();
$pids = DB::table('catalog_products')->where('slug', 'like', 'hp53-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_media')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
DB::table('catalog_categories')->where('slug', '${CATEGORY}')->delete();
DB::table('catalog_brands')->where('name', 'like', 'HP53 audit%')->delete();
DB::table('catalog_media')->where('original_name', 'like', 'hp53-audit-%')->delete();
foreach (glob(storage_path('app/public/uploads/2026/11/hp53-audit-*')) as $f) { unlink($f); }
DB::table('staff')->where('email', '${EMAIL}')->delete();
$put();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo 'restored';`;

const CREATE = String.raw`
use Illuminate\Support\Facades\DB;
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel; use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'HP53 Audit', $role));
$photos = MediaAssetModel::query()->where('type', 'image')->where('processing_status', 'ready')->orderBy('id')->limit(3)->pluck('id')->all();
if (count($photos) < 3) { echo json_encode(['error' => 'fewer than 3 ready pictures in the library']); return; }
$pic = MediaAssetModel::query()->find($photos[0]);
$now = now();
$cat = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'HP53 audit category', 'slug' => '${CATEGORY}', 'created_at' => $now, 'updated_at' => $now]);
$products = [];
for ($i = 1; $i <= 13; $i++) {
  $slug = $i === 13 ? '${GALLERY}' : "hp53-audit-$i";
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'simple', 'name' => $i === 13 ? 'HP53 audit gallery' : "HP53 audit product $i", 'slug' => $slug, 'base_sku' => "HP53-$i", 'status' => 'active', 'catalog_visibility' => 'visible', 'is_featured' => 0, 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => now()->addDays(30)->subMinutes($i)]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'universal', 'status' => 'active', 'attribute_signature' => hash('sha256', "hp53-$i"), 'sku' => "HP53-V$i", 'is_visible' => 0, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 5, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  if ($i === 13) { foreach ($photos as $n => $mid) { DB::table('catalog_product_media')->insert(['product_id' => $pid, 'media_id' => $mid, 'sort_order' => $n]); } }
  else { DB::table('catalog_product_media')->insert(['product_id' => $pid, 'media_id' => $pic->id, 'sort_order' => 0]); }
}
$dir = storage_path('app/public/uploads/2026/11');
if (! is_dir($dir)) { mkdir($dir, 0775, true); }
$shapes = ['wide' => [600, 150, [200, 60, 60]], 'tall' => [150, 600, [60, 120, 200]], 'square' => [300, 300, [40, 160, 90]]];
foreach ($shapes as $name => [$w, $h, $rgb]) {
  $variants = [];
  foreach (['thumbnail' => 400, 'medium' => 900] as $tier => $max) {
    $tw = min($w, $max); $th = (int) round($h * $tw / $w);
    $img = imagecreatetruecolor($tw, $th); imagefill($img, 0, 0, imagecolorallocate($img, 245, 245, 245));
    $c = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]); imagerectangle($img, 0, 0, $tw - 1, $th - 1, $c); imageline($img, 0, 0, $tw - 1, $th - 1, $c);
    imagewebp($img, "$dir/hp53-audit-$name-$tier.webp", 80);
    $variants[] = ['tier' => $tier, 'width' => $tw, 'height' => $th, 'quality' => 80, 'path' => "uploads/2026/11/hp53-audit-$name-$tier.webp"];
  }
  $asset = MediaAssetModel::query()->create(['type' => 'image', 'disk' => 'public', 'path' => "uploads/2026/11/hp53-audit-$name.png", 'processing_status' => 'ready', 'variants' => $variants, 'original_name' => "hp53-audit-$name.png", 'size_bytes' => 2048, 'width' => $w, 'height' => $h]);
  BrandModel::query()->create(['name' => 'HP53 audit ' . ucfirst($name), 'slug' => "hp53-audit-$name", 'logo_media_asset_id' => $asset->id]);
}
$wideLogo = MediaAssetModel::query()->where('original_name', 'hp53-audit-wide.png')->value('id');
for ($k = 1; $k <= 9; $k++) { BrandModel::query()->create(['name' => "HP53 audit Extra $k", 'slug' => "hp53-audit-extra-$k", 'logo_media_asset_id' => $wideLogo]); }
echo json_encode(['category' => $cat, 'brandsWithLogo' => DB::table('catalog_brands')->whereNotNull('logo_media_asset_id')->count()]);`;

const PUBLISH = (sections) => String.raw`
use App\Storefront\Home\HomeValidator; use App\Storefront\Home\HomeDrafts;
[$clean, $errors] = HomeValidator::validate(['spacing' => '16', 'sections' => json_decode(base64_decode('${b64(sections)}'), true)]);
HomeDrafts::publish($clean);
echo json_encode($errors);`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const consoleErrors = [];
const shots = [];
const measurements = {};
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let before = null;
let created = false;

try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    created = true;
    const made = JSON.parse(php(CREATE));
    check('the throw-away staff member, category, 13 products (one with 3 photos), 3 + 9 brands with logos were created', ! made.error && made.category > 0 && made.brandsWithLogo >= 12, JSON.stringify(made));

    const newCtx = async (width, extra = {}) => browser.newContext({ viewport: { width, height: width < 600 ? 800 : 1000 }, ignoreHTTPSErrors: true, ...(width < 600 ? { hasTouch: true, isMobile: true } : {}), ...extra });
    const shotOf = async (page, name) => { if (OUT) { const p = join(OUT, name + '.png'); shots.push(p); await page.screenshot({ path: p }); } };
    const track = (p) => { p.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200))); p.on('response', (r) => { if (r.status() >= 500) consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 120)); }); return p; };
    const car = (kind, settings = {}) => ({ type: 'carousel', settings: { kind, ...settings } });
    const sideways = (p) => p.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);

    check('the two carousels published through the application', php(PUBLISH([
        car('products', { source: 'category', entity: made.category, count: '24' }),
        car('brands', { height: '120', height_phone: '100', ratio: '3:2', gap: '16' }),
    ])) === '[]');

    // the look of an arrow: the box, the disc (the background of the arrow itself), the colours, the chevron (::before for the carousel, ::after for the gallery)
    const LOOK = `(el, pseudo) => {
        const cs = getComputedStyle(el);
        const pe = getComputedStyle(el, pseudo);
        const r = el.getBoundingClientRect();
        const pl = parseFloat(cs.paddingLeft), pr = parseFloat(cs.paddingRight), pt = parseFloat(cs.paddingTop), pb = parseFloat(cs.paddingBottom);
        return {
            display: cs.display, w: r.width, h: r.height, discW: r.width - pl - pr, discH: r.height - pt - pb,
            borderTop: cs.borderTopWidth, bg: cs.backgroundColor, shadow: cs.boxShadow, color: cs.color, radius: cs.borderRadius, filter: cs.filter, opacity: cs.opacity,
            chevron: { w: pe.width, h: pe.height, bt: pe.borderTopWidth, br: pe.borderRightWidth, transform: pe.transform, content: pe.content },
        };
    }`;

    const carMeasure = (p) => p.evaluate((look) => {
        const fn = eval(look);
        return [...document.querySelectorAll('section.sf-car')].map((s) => {
            const sr = s.getBoundingClientRect();
            return ['prev', 'next'].map((side) => {
                const a = s.querySelector('.sf-car-' + side);
                const r = a.getBoundingClientRect();
                return { side, ...fn(a, '::before'), disabled: a.disabled, edge: side === 'prev' ? r.left - sr.left : sr.right - r.right, centre: (r.top + r.height / 2) - (sr.top + sr.height / 2) };
            });
        });
    }, LOOK);

    const galMeasure = (p) => p.evaluate((look) => {
        const fn = eval(look);
        const a = document.querySelector('.sf-gal-next');
        if (! a) return null;
        const st = a.closest('.sf-gal-stage').getBoundingClientRect();
        const r = a.getBoundingClientRect();
        return { ...fn(a, '::after'), disabled: a.disabled, edge: st.right - r.right, centre: (r.top + r.height / 2) - (st.top + st.height / 2), isEnd: a.classList.contains('is-end') };
    }, LOOK);

    // ============================================================ the product page's gallery: the reference (390px)
    let ctx = await newCtx(390);
    let page = track(await ctx.newPage());
    await page.goto(BASE + '/product/' + GALLERY, { waitUntil: 'networkidle' });
    await sleep(800);
    const gal = await galMeasure(page);
    measurements.gallery390 = gal;
    check('the gallery of the test product shows its «next» arrow at 390px (carousel mode, 3 photos)', gal !== null && gal.display !== 'none' && ! gal.isEnd, JSON.stringify(gal && { display: gal.display, isEnd: gal.isEnd }));
    if (gal) {
        const sec = page.locator('.sf-gal-stage');
        await shotOf(page, 'hp53-gallery-390');
        if (OUT) { const p = join(OUT, 'hp53-gallery-390-stage.png'); shots.push(p); await sec.screenshot({ path: p }); }
    }
    await ctx.close();

    // the gallery's arrow over the same dark photo, 3×
    ctx = await newCtx(390, { deviceScaleFactor: 3 });
    page = track(await ctx.newPage());
    await page.goto(BASE + '/product/' + GALLERY, { waitUntil: 'networkidle' });
    await sleep(800);
    const galRect = await page.evaluate(() => { const r = document.querySelector('.sf-gal-next').getBoundingClientRect(); return { x: r.left - 14, y: r.top - 14, width: r.width + 28, height: r.height + 28 }; });
    if (OUT) { const p = join(OUT, 'hp53-zoom-gallery-next-390.png'); shots.push(p); await page.screenshot({ path: p, clip: galRect }); }
    await ctx.close();

    // ============================================================ the home page's carousels (390px and 1280px)
    for (const width of [1280, 390]) {
        ctx = await newCtx(width);
        page = track(await ctx.newPage());
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        await sleep(800);
        const sections = await carMeasure(page);
        measurements['carousels' + width] = sections;
        const names = ['the product carousel', 'the brand-logo carousel'];
        check(`${width}px: two carousels on the page`, sections.length === 2, `sections ${sections.length}`);
        sections.forEach((arrows, i) => {
            const [prev, next] = arrows;
            const tag = `${width}px, ${names[i]}`;
            check(`${tag}: both arrows are shown by the script`, [prev, next].every((a) => a.display !== 'none'), JSON.stringify(arrows.map((a) => a.display)));
            check(`${tag}: no border and no filter on the arrow`, [prev, next].every((a) => a.borderTop === '0px' && a.filter === 'none'), JSON.stringify(arrows.map((a) => [a.borderTop, a.filter])));
            check(`${tag}: the background is the gallery's (white at 90%, not transparent)`, [prev, next].every((a) => gal && a.bg === gal.bg && a.bg !== 'rgba(0, 0, 0, 0)'), JSON.stringify({ car: arrows.map((a) => a.bg), gallery: gal && gal.bg }));
            check(`${tag}: the shadow is the gallery's (its visible layers; the computed value lists the empty ones too)`, [prev, next].every((a) => gal && visibleShadow(a.shadow) === visibleShadow(gal.shadow) && visibleShadow(a.shadow) !== 'none'), JSON.stringify({ car: arrows.map((a) => visibleShadow(a.shadow)), gallery: gal && visibleShadow(gal.shadow) }));
            check(`${tag}: the text colour is the gallery's`, [prev, next].every((a) => gal && a.color === gal.color), JSON.stringify({ car: arrows.map((a) => a.color), gallery: gal && gal.color }));
            check(`${tag}: the disc is the gallery's size (content box ${gal && gal.discW}×${gal && gal.discH}px)`, [prev, next].every((a) => gal && near(a.discW, gal.discW) && near(a.discH, gal.discH)), JSON.stringify({ car: arrows.map((a) => [a.discW, a.discH]), gallery: gal && [gal.discW, gal.discH] }));
            check(`${tag}: the chevron is the gallery's (size and border widths)`, [prev, next].every((a) => gal && a.chevron.w === gal.chevron.w && a.chevron.h === gal.chevron.h && a.chevron.bt === gal.chevron.bt && a.chevron.br === gal.chevron.br), JSON.stringify({ car: arrows.map((a) => a.chevron), gallery: gal && gal.chevron }));
            check(`${tag}: the «next» chevron has the gallery's transform`, gal && next.chevron.transform === gal.chevron.transform, JSON.stringify({ car: next.chevron.transform, gallery: gal && gal.chevron.transform }));
            check(`${tag}: the touch target is 32–44px wide and at least 44px high`, [prev, next].every((a) => a.w >= 32 && a.w <= 44.5 && a.h >= 44), JSON.stringify(arrows.map((a) => [Math.round(a.w * 10) / 10, a.h])));
            check(`${tag}: «previous» sits at the left edge of the row (within 1px), «next» at the right edge (within 1px)`, Math.abs(prev.edge) <= 1 && Math.abs(next.edge) <= 1, JSON.stringify(arrows.map((a) => Math.round(a.edge * 10) / 10)));
            check(`${tag}: both arrows are vertically centred on the row (within 1px)`, [prev, next].every((a) => Math.abs(a.centre) <= 1), JSON.stringify(arrows.map((a) => Math.round(a.centre * 10) / 10)));
            check(`${tag}: «previous» is disabled at the start (0.35 opacity), «next» is not`, prev.disabled && prev.opacity === '0.35' && ! next.disabled && next.opacity === '1', JSON.stringify(arrows.map((a) => [a.disabled, a.opacity])));
        });
        check(`${width}px: nothing scrolls the page sideways`, ! (await sideways(page)));
        if (OUT) {
            const secs = page.locator('section.sf-car');
            const p1 = join(OUT, `hp53-products-${width}.png`); shots.push(p1); await secs.nth(0).screenshot({ path: p1 });
            const p2 = join(OUT, `hp53-brands-${width}.png`); shots.push(p2); await secs.nth(1).screenshot({ path: p2 });
        }
        await ctx.close();
    }

    // the carousel's «next» arrow over the same dark photo, 3× (390px): read it against the gallery's zoom
    ctx = await newCtx(390, { deviceScaleFactor: 3 });
    page = track(await ctx.newPage());
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await sleep(800);
    const carRect = await page.evaluate(() => { const r = document.querySelector('section.sf-car .sf-car-next').getBoundingClientRect(); return { x: r.left - 14, y: r.top - 14, width: r.width + 28, height: r.height + 28 }; });
    if (OUT) { const p = join(OUT, 'hp53-zoom-car-next-390.png'); shots.push(p); await page.screenshot({ path: p, clip: carRect }); }
    await ctx.close();

    // JavaScript disabled: the arrows stay hidden
    const nc = await newCtx(390, { javaScriptEnabled: false });
    const np = await nc.newPage();
    await np.goto(BASE + '/', { waitUntil: 'load' });
    const nojs = await np.evaluate(() => [...document.querySelectorAll('.sf-car-arrow')].map((a) => getComputedStyle(a).display));
    check('with JavaScript disabled both arrows stay hidden (display none) at 390px', nojs.length === 4 && nojs.every((d) => d === 'none'), JSON.stringify(nojs));
    await nc.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (created) {
        try {
            php(RESTORE);
            const after = JSON.parse(php(SUMS));
            check('everything is back: every settings row (the published home layout included, by id and checksum), the staff, categories, brands, products, variations, stock, links, media assets and files are as they were', before && JSON.stringify(before) === JSON.stringify(after), JSON.stringify({ before, after }));
        } catch (e) {
            check('the clean-up finished', false, String(e).slice(0, 400));
        }
    }
}

check('no page errors', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, measurements, steps }, null, 1));
process.exit(failed.length ? 1 : 0);

function near(a, b, t = 2) {
    return Math.abs(a - b) <= t;
}

// the layers of a computed box-shadow that paint something: the transparent ones (rgba(0, 0, 0, 0)) are dropped
function visibleShadow(value) {
    return value.split(/, (?=rgba\(|none)/).filter((layer) => ! /^rgba\(0, 0, 0, 0\) /.test(layer)).join(', ');
}
