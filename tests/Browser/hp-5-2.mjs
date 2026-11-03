// A REAL-BROWSER check of HP-5.2: the carousel arrows are bare chevrons at the very edges of the row, the same at every screen size (no button look). Two carousels on the home page (a product carousel
// and a brand-logo carousel), measured at 1280px and 390px:
//   - each arrow has no border, a transparent background and no shadow of its own (the chevron is the only visible thing);
//   - each arrow is 32–44px wide and at least 44px high (the touch target);
//   - the «previous» arrow sits within 1px of the carousel's left edge, the «next» arrow within 1px of its right edge;
//   - each arrow is vertically centred on the row (within 1px); the disabled «previous» arrow is at 0.35 opacity;
//   - nothing scrolls the page sideways; with JavaScript disabled both arrows stay hidden (the `[hidden]` rule still works).
// Screenshots: each carousel at both widths, and a 3× zoom of the two arrows of the product carousel (read by hand).
// NOT part of `php artisan test`: run by hand against a dev site. It creates ONE throw-away staff member, ONE category, 12 products and 3 brands with generated logos (files in
// storage/app/public/uploads/2026/11, hp52-audit-*). The published home layout is put back EXACTLY (every `site_settings` row, the published layout included, by id; checksum verified), and everything created is removed.
// Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/hp-5-2.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node hp-5-2.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

// a non-zero exit OR an empty answer is an error, with the tail of both streams (an empty answer used to reach JSON.parse unexplained)
const php = (code) => {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    const out = (r.stdout ?? '').trim();
    if (r.status !== 0 || out === '') {
        throw new Error(`php failed (status ${r.status}): ` + (out + '\n' + (r.stderr ?? '')).slice(-900));
    }
    return out.split('\n').pop();
};

const EMAIL = `hp52-${Date.now()}@example.test`;
const PASSWORD = 'Hp52-' + Math.random().toString(36).slice(2) + 'Aa1!';
const CATEGORY = 'hp52-audit-cat';
const b64 = (value) => Buffer.from(JSON.stringify(value)).toString('base64');

// every site_settings row (the published home layout among them) and the counts of everything this script creates
const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$published = DB::table('site_settings')->where('key', 'design.home.global.published')->first();
echo json_encode(['settings' => md5(json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 'published' => $published ? [$published->id, md5((string) $published->value)] : null, 'staff' => DB::table('staff')->count(), 'categories' => DB::table('catalog_categories')->count(), 'brands' => DB::table('catalog_brands')->count(), 'products' => DB::table('catalog_products')->count(), 'variations' => DB::table('catalog_variations')->count(), 'stock' => DB::table('stock_levels')->count(), 'product_media' => DB::table('catalog_product_media')->count(), 'product_categories' => DB::table('catalog_product_categories')->count(), 'catalog_media' => DB::table('catalog_media')->count(), 'files' => count(glob(storage_path('app/public/uploads/2026/11/hp52-audit-*')))]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/hp52_backup.json', json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo 'ok';`;

// settings back by id, everything created removed (rows and files), settings once more
const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/hp52_backup.json'), true);
$put = function () use ($bak) { DB::table('site_settings')->whereNotIn('id', array_column($bak, 'id'))->delete(); foreach ($bak as $row) { DB::table('site_settings')->where('id', $row['id'])->update(['key' => $row['key'], 'value' => $row['value'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]); if (! DB::table('site_settings')->where('id', $row['id'])->exists()) { DB::table('site_settings')->insert($row); } } };
$put();
$pids = DB::table('catalog_products')->where('slug', 'like', 'hp52-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_media')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
DB::table('catalog_categories')->where('slug', '${CATEGORY}')->delete();
DB::table('catalog_brands')->where('name', 'like', 'HP52 audit%')->delete();
DB::table('catalog_media')->where('original_name', 'like', 'hp52-audit-%')->delete();
foreach (glob(storage_path('app/public/uploads/2026/11/hp52-audit-*')) as $f) { unlink($f); }
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
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'HP52 Audit', $role));
$pic = MediaAssetModel::query()->where('type', 'image')->where('processing_status', 'ready')->orderBy('id')->first();
if ($pic === null) { echo json_encode(['error' => 'no ready picture in the library']); return; }
$now = now();
$cat = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'HP52 audit category', 'slug' => '${CATEGORY}', 'created_at' => $now, 'updated_at' => $now]);
for ($i = 1; $i <= 12; $i++) {
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'simple', 'name' => "HP52 audit product $i", 'slug' => "hp52-audit-$i", 'base_sku' => "HP52-$i", 'status' => 'active', 'catalog_visibility' => 'visible', 'is_featured' => 0, 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => now()->addDays(30)->subMinutes($i)]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'universal', 'status' => 'active', 'attribute_signature' => hash('sha256', "hp52-$i"), 'sku' => "HP52-V$i", 'is_visible' => 0, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 5, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  DB::table('catalog_product_media')->insert(['product_id' => $pid, 'media_id' => $pic->id, 'sort_order' => 0]);
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
    imagewebp($img, "$dir/hp52-audit-$name-$tier.webp", 80);
    $variants[] = ['tier' => $tier, 'width' => $tw, 'height' => $th, 'quality' => 80, 'path' => "uploads/2026/11/hp52-audit-$name-$tier.webp"];
  }
  $asset = MediaAssetModel::query()->create(['type' => 'image', 'disk' => 'public', 'path' => "uploads/2026/11/hp52-audit-$name.png", 'processing_status' => 'ready', 'variants' => $variants, 'original_name' => "hp52-audit-$name.png", 'size_bytes' => 2048, 'width' => $w, 'height' => $h]);
  BrandModel::query()->create(['name' => 'HP52 audit ' . ucfirst($name), 'slug' => "hp52-audit-$name", 'logo_media_asset_id' => $asset->id]);
}
/* nine more brands with the wide logo: the brand row must overflow at 1280px too, or its arrows are (correctly) hidden */
$wideLogo = MediaAssetModel::query()->where('original_name', 'hp52-audit-wide.png')->value('id');
for ($k = 1; $k <= 9; $k++) { BrandModel::query()->create(['name' => "HP52 audit Extra $k", 'slug' => "hp52-audit-extra-$k", 'logo_media_asset_id' => $wideLogo]); }
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
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let before = null;
let created = false;

try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    created = true;
    const made = JSON.parse(php(CREATE));
    check('the throw-away staff member, category, 12 products and 3 brands with logos were created', ! made.error && made.category > 0 && made.brandsWithLogo >= 3, JSON.stringify(made));

    const newCtx = async (width, extra = {}) => browser.newContext({ viewport: { width, height: width < 600 ? 800 : 1000 }, ignoreHTTPSErrors: true, ...(width < 600 ? { hasTouch: true, isMobile: true } : {}), ...extra });
    const shotOf = async (page, name) => { if (OUT) { const p = join(OUT, name + '.png'); shots.push(p); await page.screenshot({ path: p }); } };
    const track = (p) => { p.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200))); p.on('response', (r) => { if (r.status() >= 500) consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 120)); }); return p; };
    const car = (kind, settings = {}) => ({ type: 'carousel', settings: { kind, ...settings } });
    const sideways = (p) => p.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);

    // the home layout: the product carousel (4 / 3 / 2 columns) and the brand-logo carousel (120px, 3:2), both on the home page
    check('the two carousels published through the application', php(PUBLISH([
        car('products', { source: 'category', entity: made.category, count: '24' }),
        car('brands', { height: '120', height_phone: '100', ratio: '3:2', gap: '16' }),
    ])) === '[]');

    const measure = (p) => p.evaluate(() => [...document.querySelectorAll('section.sf-car')].map((s) => {
        const sr = s.getBoundingClientRect();
        return ['prev', 'next'].map((side) => {
            const a = s.querySelector('.sf-car-' + side);
            const r = a.getBoundingClientRect();
            const cs = getComputedStyle(a);
            return {
                side,
                display: cs.display,
                hidden: a.hidden,
                disabled: a.disabled,
                borderTop: cs.borderTopWidth,
                border: cs.borderStyle,
                bg: cs.backgroundColor,
                shadow: cs.boxShadow,
                opacity: cs.opacity,
                w: r.width,
                h: r.height,
                edge: side === 'prev' ? r.left - sr.left : sr.right - r.right,
                centre: (r.top + r.height / 2) - (sr.top + sr.height / 2),
            };
        });
    }));

    // ============================================================ 1280px and 390px
    for (const width of [1280, 390]) {
        const ctx = await newCtx(width);
        const page = track(await ctx.newPage());
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        await sleep(800);
        const sections = await measure(page);
        const names = ['the product carousel', 'the brand-logo carousel'];
        check(`${width}px: two carousels on the page`, sections.length === 2, `sections ${sections.length}`);

        sections.forEach((arrows, i) => {
            const [prev, next] = arrows;
            const tag = `${width}px, ${names[i]}`;
            check(`${tag}: both arrows are shown by the script`, [prev, next].every((a) => ! a.hidden && a.display !== 'none'), JSON.stringify(arrows.map((a) => a.display)));
            check(`${tag}: no border (border-top 0px), no background (transparent), no shadow`, [prev, next].every((a) => a.borderTop === '0px' && a.bg === 'rgba(0, 0, 0, 0)' && a.shadow === 'none'), JSON.stringify(arrows.map((a) => [a.borderTop, a.bg, a.shadow])));
            check(`${tag}: the touch target is 32–44px wide and at least 44px high`, [prev, next].every((a) => a.w >= 32 && a.w <= 44.5 && a.h >= 44), JSON.stringify(arrows.map((a) => [Math.round(a.w * 10) / 10, a.h])));
            check(`${tag}: «previous» sits at the left edge of the row (within 1px), «next» at the right edge (within 1px)`, Math.abs(prev.edge) <= 1 && Math.abs(next.edge) <= 1, JSON.stringify(arrows.map((a) => Math.round(a.edge * 10) / 10)));
            check(`${tag}: both arrows are vertically centred on the row (within 1px)`, [prev, next].every((a) => Math.abs(a.centre) <= 1), JSON.stringify(arrows.map((a) => Math.round(a.centre * 10) / 10)));
            check(`${tag}: «previous» is disabled at the start (0.35 opacity), «next» is not`, prev.disabled && prev.opacity === '0.35' && ! next.disabled && next.opacity === '1', JSON.stringify(arrows.map((a) => [a.disabled, a.opacity])));
        });
        check(`${width}px: nothing scrolls the page sideways`, ! (await sideways(page)));

        const sec = page.locator('section.sf-car');
        await shotOf(page, `hp52-product-${width}`);
        if (OUT) { const p = join(OUT, `hp52-product-section-${width}.png`); shots.push(p); await sec.nth(0).screenshot({ path: p }); }
        if (OUT) { const p = join(OUT, `hp52-brands-section-${width}.png`); shots.push(p); await sec.nth(1).screenshot({ path: p }); }
        await ctx.close();

        // a 3× zoom of the two arrows of the product carousel, to read the chevrons
        const zc = await newCtx(width, { deviceScaleFactor: 3 });
        const zp = track(await zc.newPage());
        await zp.goto(BASE + '/', { waitUntil: 'networkidle' });
        await sleep(800);
        const rects = await zp.evaluate(() => { const s = document.querySelector('section.sf-car'); const sr = s.getBoundingClientRect(); return ['prev', 'next'].map((side) => { const r = s.querySelector('.sf-car-' + side).getBoundingClientRect(); return { x: r.left - 14, y: r.top - 14, width: r.width + 28, height: r.height + 28 }; }); });
        if (OUT) {
            for (const [side, clip] of [['prev', rects[0]], ['next', rects[1]]]) {
                const p = join(OUT, `hp52-zoom-${side}-${width}.png`);
                shots.push(p);
                await zp.screenshot({ path: p, clip });
            }
        }
        await zc.close();
    }

    // JavaScript disabled: both arrows stay hidden and the page does not scroll sideways (the `[hidden]` rule still wins over the flex display)
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
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
