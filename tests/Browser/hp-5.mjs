// A REAL-BROWSER run of HP-5: the «Карусел» component of the «Начална» zone (products / HTML blocks / pictures / brand logos on ONE shared carousel piece). It checks:
//   - the Design screen: the component is in the palette, the fields the chosen kind does not use are hidden, the size hint is shown;
//   - a PRODUCT carousel is the catalog's card: the width AND the height of a card equal a card of the category grid at 4 columns (1440px), 3 (800px) and the phone's columns (390px), within 2px; the cards of one
//     carousel have one height; nothing scrolls the page sideways;
//   - the arrows: shown by the script, scroll by one item, disabled at the ends; the keyboard arrows scroll the focused track; with JavaScript disabled the arrows stay hidden and the track still scrolls;
//   - the brand-logo carousel at 120px with logos of different shapes (a wide, a tall, a square, a huge and a tiny one): none cropped, the big ones shrunk, the tiny one not enlarged; the box is height × ratio;
//   - a blocks carousel with «Снимка с текст» blocks fills the box; an images carousel prints the link only where it is set.
// NOT part of `php artisan test`: run by hand against a dev site. It creates ONE throw-away staff member, ONE category, 40 products, 6 brands with generated logos (files in storage/app/public/uploads/2026/11, hp5-audit-*)
// and 4 HTML blocks (plain rows / the application's models); the design changes only through HomeDrafts; at the end everything created is removed (rows AND files), every `site_settings` row is put back EXACTLY (update by
// id: the owner's published home layout and draft included) and the counts and the checksum are verified. Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/hp-5.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node hp-5.mjs');
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

const EMAIL = `hp5-${Date.now()}@example.test`;
const PASSWORD = 'Hp5-' + Math.random().toString(36).slice(2) + 'Aa1!';
const CATEGORY = 'hp5-audit-cat';
const b64 = (value) => Buffer.from(JSON.stringify(value)).toString('base64');

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
echo json_encode(['settings' => md5(json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 'staff' => DB::table('staff')->count(), 'categories' => DB::table('catalog_categories')->count(), 'brands' => DB::table('catalog_brands')->count(), 'products' => DB::table('catalog_products')->count(), 'variations' => DB::table('catalog_variations')->count(), 'stock' => DB::table('stock_levels')->count(), 'product_media' => DB::table('catalog_product_media')->count(), 'product_categories' => DB::table('catalog_product_categories')->count(), 'catalog_media' => DB::table('catalog_media')->count(), 'html_blocks' => DB::table('html_blocks')->count(), 'files' => count(glob(storage_path('app/public/uploads/2026/11/hp5-audit-*')))]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/hp5_backup.json', json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo 'ok';`;

// settings back by id, everything created removed (rows and files), settings once more
const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/hp5_backup.json'), true);
$put = function () use ($bak) { DB::table('site_settings')->whereNotIn('id', array_column($bak, 'id'))->delete(); foreach ($bak as $row) { DB::table('site_settings')->where('id', $row['id'])->update(['key' => $row['key'], 'value' => $row['value'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]); if (! DB::table('site_settings')->where('id', $row['id'])->exists()) { DB::table('site_settings')->insert($row); } } };
$put();
$pids = DB::table('catalog_products')->where('slug', 'like', 'hp5-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_media')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
DB::table('catalog_categories')->where('slug', '${CATEGORY}')->delete();
DB::table('catalog_brands')->where('name', 'like', 'HP5 audit%')->delete();
DB::table('html_blocks')->where('title', 'like', 'HP5 audit%')->delete();
DB::table('catalog_media')->where('original_name', 'like', 'hp5-audit-%')->delete();
foreach (glob(storage_path('app/public/uploads/2026/11/hp5-audit-*')) as $f) { unlink($f); }
DB::table('staff')->where('email', '${EMAIL}')->delete();
$put();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo 'restored';`;

const CREATE = String.raw`
use Illuminate\Support\Facades\DB;
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel; use EasyCo\Catalog\Persistence\Eloquent\BrandModel; use App\Content\HtmlBlocks\HtmlBlockModel;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'HP5 Audit', $role));
$pic = MediaAssetModel::query()->where('type', 'image')->where('processing_status', 'ready')->orderBy('id')->first();
if ($pic === null) { echo json_encode(['error' => 'no ready picture in the library']); return; }
$now = now();
$cat = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'HP5 audit category', 'slug' => '${CATEGORY}', 'created_at' => $now, 'updated_at' => $now]);
for ($i = 1; $i <= 40; $i++) {
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'simple', 'name' => "HP5 audit product $i", 'slug' => "hp5-audit-$i", 'base_sku' => "HP5-$i", 'status' => 'active', 'catalog_visibility' => 'visible', 'is_featured' => 0, 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => now()->addDays(30)->subMinutes($i)]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'universal', 'status' => 'active', 'attribute_signature' => hash('sha256', "hp5-$i"), 'sku' => "HP5-V$i", 'is_visible' => 0, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 5, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  $i <= 30 && DB::table('catalog_product_media')->insert(['product_id' => $pid, 'media_id' => $pic->id, 'sort_order' => 0]);
}
$dir = storage_path('app/public/uploads/2026/11');
if (! is_dir($dir)) { mkdir($dir, 0775, true); }
$shapes = ['wide' => [600, 150, [200, 60, 60]], 'tall' => [150, 600, [60, 120, 200]], 'square' => [300, 300, [40, 160, 90]], 'huge' => [1600, 900, [200, 140, 20]], 'tiny' => [60, 30, [150, 40, 150]], 'wide2' => [900, 200, [30, 30, 30]]];
$brands = [];
foreach ($shapes as $name => [$w, $h, $rgb]) {
  $variants = [];
  foreach (['thumbnail' => 400, 'medium' => 900, 'large' => 1600] as $tier => $max) {
    $tw = min($w, $max); $th = (int) round($h * $tw / $w);
    $img = imagecreatetruecolor($tw, $th); imagefill($img, 0, 0, imagecolorallocate($img, 245, 245, 245));
    $c = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]); imagerectangle($img, 0, 0, $tw - 1, $th - 1, $c); imagerectangle($img, 1, 1, $tw - 2, $th - 2, $c); imageline($img, 0, 0, $tw - 1, $th - 1, $c); imageline($img, 0, $th - 1, $tw - 1, 0, $c);
    imagewebp($img, "$dir/hp5-audit-$name-$tier.webp", 80);
    $variants[] = ['tier' => $tier, 'width' => $tw, 'height' => $th, 'quality' => 80, 'path' => "uploads/2026/11/hp5-audit-$name-$tier.webp"];
  }
  $asset = MediaAssetModel::query()->create(['type' => 'image', 'disk' => 'public', 'path' => "uploads/2026/11/hp5-audit-$name.png", 'processing_status' => 'ready', 'variants' => $variants, 'original_name' => "hp5-audit-$name.png", 'size_bytes' => 2048, 'width' => $w, 'height' => $h]);
  $brands[$name] = BrandModel::query()->create(['name' => 'HP5 audit ' . ucfirst($name), 'slug' => "hp5-audit-$name", 'logo_media_asset_id' => $asset->id])->id;
}
$blocks = [];
foreach ([1, 2, 3, 4] as $n) {
  $blocks[] = HtmlBlockModel::query()->create(['title' => "HP5 audit card $n", 'kind' => 'card', 'card' => ['picture' => $pic->id, 'title' => "Колекция $n", 'subtitle' => 'Ново', 'text' => '', 'link' => '/product-category/${CATEGORY}', 'alt' => '']])->id;
}
$pics = [];
foreach (['wide', 'tall', 'square'] as $name) { $pics[$name] = MediaAssetModel::query()->where('original_name', "hp5-audit-$name.png")->value('id'); }
echo json_encode(['brandsWithLogo' => DB::table('catalog_brands')->whereNotNull('logo_media_asset_id')->count(), 'picture' => $pic->id, 'category' => $cat, 'brands' => $brands, 'blocks' => $blocks, 'pics' => $pics]);`;

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
const near = (a, b, t = 2) => Math.abs(a - b) <= t;

let before = null;
let created = false;

try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    created = true;
    const made = JSON.parse(php(CREATE));
    check('the throw-away staff member, category, 40 products, 6 brands with logos, 4 card blocks were created', ! made.error && made.category > 0, JSON.stringify(made));
    const { blocks, pics } = made;

    const newCtx = async (width, extra = {}) => browser.newContext({ viewport: { width, height: width < 600 ? 800 : 1000 }, ignoreHTTPSErrors: true, ...(width < 600 ? { hasTouch: true, isMobile: true } : {}), ...extra });
    const shotOf = async (page, name, full = false) => { if (OUT) { const p = join(OUT, name + '.png'); shots.push(p); await page.screenshot({ path: p, fullPage: full }); } };
    const track = (p) => { p.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200))); p.on('response', (r) => { if (r.status() >= 500) consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 120)); }); return p; };
    const car = (kind, settings = {}) => ({ type: 'carousel', settings: { kind, ...settings } });
    const sideways = (p) => p.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);

    // ============================================================ the Design screen
    let ctx = await newCtx(1440);
    let page = track(await ctx.newPage());
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
    await page.goto(BASE + '/admin/design?zone=home', { waitUntil: 'networkidle' });
    check('the component is in the palette', (await page.locator('.ds-chip-add', { hasText: 'Карусел' }).count()) === 1);
    await page.locator('.ds-chip-add', { hasText: 'Карусел' }).click();
    await page.waitForSelector('.ds-props', { timeout: 30000 });
    await sleep(1500);
    const props = page.locator('.ds-props');
    let text = await props.innerText();
    check('products: the source and the count are shown, no height / ratio / radius', text.includes('Източник') && text.includes('Брой продукти') && ! text.includes('Височина на компютър') && ! text.includes('Пропорция на кутията') && ! text.includes('Заоблени ъгли'), text.replace(/\s+/g, ' ').slice(0, 300));
    check('the properties have info icons and pairs', (await props.locator('[aria-describedby^="ds-hint-"]').count()) >= 4 && (await props.locator('.fi-grid').count()) >= 1);
    await shotOf(page, 'hp5-panel-products-1440', true);
    const kind = props.locator('.fi-fo-field', { has: page.locator('label', { hasText: 'Какво се показва' }) }).first();
    await kind.locator('.fi-select-input-btn').first().click();
    await sleep(400);
    await page.getByRole('option', { name: 'Марки' }).first().click();
    await sleep(1800);
    text = await props.innerText();
    check('brands: the height, the phone height, the ratio, the gap and the radius are shown, no source', text.includes('Височина на компютър') && text.includes('Височина на телефон') && text.includes('Пропорция на кутията') && text.includes('Заоблени ъгли') && ! text.includes('Брой продукти'), text.replace(/\s+/g, ' ').slice(0, 300));
    check('the recommended size is shown (box and 2×)', /Кутия на елемента: \d+ × \d+ px/.test(text), text.replace(/\s+/g, ' ').slice(-300));
    await shotOf(page, 'hp5-panel-brands-1440', true);
    await ctx.close();

    // ============================================================ a PRODUCT carousel is the catalog's card
    const catalog = async (width) => {
        const c = await newCtx(width);
        const p = track(await c.newPage());
        await p.goto(BASE + '/product-category/' + CATEGORY, { waitUntil: 'networkidle' });
        const r = await p.evaluate(() => { const g = document.querySelector('.sf-category .sf-grid'); const k = g.querySelector('.sf-card').getBoundingClientRect(); return { w: k.width, h: k.height, gap: parseFloat(getComputedStyle(g).columnGap), cols: getComputedStyle(g).gridTemplateColumns.split(' ').length }; });
        await c.close();

        return r;
    };

    check('the product carousel published through the application', php(PUBLISH([car('products', { source: 'category', entity: made.category, count: '24' })])) === '[]');

    for (const width of [1440, 800, 390]) {
        const grid = await catalog(width);
        ctx = await newCtx(width);
        page = track(await ctx.newPage());
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        const m = await page.evaluate(() => {
            const t = document.querySelector('.sf-car-track');
            const cards = [...t.querySelectorAll('.sf-card')].map((c) => { const r = c.getBoundingClientRect(); return { w: r.width, h: r.height }; });
            const arrows = [...document.querySelectorAll('.sf-car-arrow')].map((a) => ({ hidden: a.hidden, disabled: a.disabled, w: a.getBoundingClientRect().width, h: a.getBoundingClientRect().height }));

            return { cards, gap: parseFloat(getComputedStyle(t).columnGap), arrows, scrollable: t.scrollWidth > t.clientWidth, style: document.querySelector('.sf-car').outerHTML.includes(' style='), tab: t.getAttribute('tabindex'), region: document.querySelector('section.sf-car').getAttribute('role') };
        });
        check(`${width}px: 24 cards, the card width = a grid cell of the category (${grid.cols} columns): ${grid.w.toFixed(1)}px ±2`, m.cards.length === 24 && m.cards.every((k) => near(k.w, grid.w)), JSON.stringify({ car: m.cards.slice(0, 2).map((k) => Math.round(k.w * 10) / 10), grid: grid.w }));
        check(`${width}px: the card height = a grid card: ${grid.h.toFixed(1)}px ±2`, m.cards.slice(0, 20).every((k) => near(k.h, grid.h)), JSON.stringify({ car: m.cards.slice(0, 2).map((k) => Math.round(k.h * 10) / 10), grid: grid.h }));
        const hs = m.cards.map((k) => Math.round(k.h));
        check(`${width}px: the cards of one carousel have one height`, Math.max(...hs) - Math.min(...hs) <= 1, JSON.stringify([...new Set(hs)]));
        check(`${width}px: the gap is the grid's (${grid.gap}px), the track scrolls, nothing scrolls the page sideways, no style attribute, focusable region`, near(m.gap, grid.gap, 0.5) && m.scrollable && ! (await sideways(page)) && ! m.style && m.tab === '0' && m.region === 'region', JSON.stringify({ gap: m.gap, scrollable: m.scrollable }));
        check(`${width}px: the arrows are shown by the script (44px), «previous» is disabled at the start`, m.arrows.length === 2 && m.arrows.every((a) => ! a.hidden && near(a.w, 44, 0.5) && near(a.h, 44, 0.5)) && m.arrows[0].disabled && ! m.arrows[1].disabled, JSON.stringify(m.arrows));
        await shotOf(page, `hp5-products-${width}`);

        if (width === 1440) {
            // the arrows scroll by one item and disable at the ends
            const step = await page.evaluate(() => { const t = document.querySelector('.sf-car-track'); const i = t.querySelectorAll('.sf-car-item'); return i[1].getBoundingClientRect().left - i[0].getBoundingClientRect().left; });
            await page.click('.sf-car-next');
            await sleep(1200);
            const s1 = await page.evaluate(() => document.querySelector('.sf-car-track').scrollLeft);
            check(`the «next» arrow scrolls by one item (${step.toFixed(1)}px)`, near(s1, step, 4), `scrollLeft ${s1}`);
            check('«previous» is enabled after that', await page.evaluate(() => ! document.querySelector('.sf-car-prev').disabled));
            await page.click('.sf-car-prev');
            await sleep(1200);
            check('«previous» goes back to the start and is disabled', await page.evaluate(() => document.querySelector('.sf-car-track').scrollLeft < 2 && document.querySelector('.sf-car-prev').disabled));
            for (let i = 0; i < 40 && ! (await page.evaluate(() => document.querySelector('.sf-car-next').disabled)); i++) { await page.click('.sf-car-next'); await sleep(450); }
            await sleep(600);
            const end = await page.evaluate(() => { const t = document.querySelector('.sf-car-track'); return { left: t.scrollLeft, max: t.scrollWidth - t.clientWidth, next: document.querySelector('.sf-car-next').disabled }; });
            check('«next» is disabled at the end of the row', end.next && near(end.left, end.max, 3), JSON.stringify(end));
            await shotOf(page, 'hp5-products-1440-end');
            // the keyboard scrolls the focused track
            await page.evaluate(() => { document.querySelector('.sf-car-track').scrollLeft = 0; });
            await sleep(500);
            await page.focus('.sf-car-track');
            await page.keyboard.press('ArrowRight');
            await sleep(900);
            check('the keyboard arrow scrolls the focused track', await page.evaluate(() => document.querySelector('.sf-car-track').scrollLeft > 10));
        }

        await ctx.close();
    }

    // JavaScript disabled: the arrows stay hidden, the track still scrolls (it is an overflow container with a width greater than the box; the wheel / finger / keyboard scroll it natively)
    ctx = await newCtx(1440, { javaScriptEnabled: false });
    page = await ctx.newPage();
    await page.goto(BASE + '/', { waitUntil: 'load' });
    await page.evaluate(() => { document.querySelector('.sf-car-track').scrollLeft = 200; });
    await sleep(1200);
    const nojs = await page.evaluate(() => { const t = document.querySelector('.sf-car-track'); const a = [...document.querySelectorAll('.sf-car-arrow')]; return { arrowsHidden: a.length === 2 && a.every((x) => getComputedStyle(x).display === 'none'), overflow: getComputedStyle(t).overflowX, scrollable: t.scrollWidth > t.clientWidth, moved: t.scrollLeft > 100, snap: getComputedStyle(t).scrollSnapType }; });
    check('with JavaScript disabled the arrows stay hidden and the track still scrolls', nojs.arrowsHidden && nojs.overflow === 'auto' && nojs.scrollable && nojs.moved && nojs.snap.startsWith('x'), JSON.stringify(nojs));
    await ctx.close();

    // ============================================================ the brand-logo carousel (height-driven)
    check('the brands carousel (120px, 3:2) published', php(PUBLISH([car('brands', { height: '120', height_phone: '100', ratio: '3:2', gap: '16' })])) === '[]');
    ctx = await newCtx(1440);
    page = track(await ctx.newPage());
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const logos = await page.evaluate(() => [...document.querySelectorAll('.sf-car-item')].map((li) => { const i = li.querySelector('img'); const b = li.getBoundingClientRect(); const r = i.getBoundingClientRect(); return { alt: i.alt, box: [b.width, b.height], img: [r.width, r.height], nat: [i.naturalWidth, i.naturalHeight], inBox: r.left >= b.left - 0.5 && r.right <= b.right + 0.5 && r.top >= b.top - 0.5 && r.bottom <= b.bottom + 0.5, link: !! li.querySelector('a') }; }));
    const names = logos.map((l) => l.alt);
    check('the brands: every brand that has a logo, by name (the shop own brands and the 6 throw-away ones), no link', logos.length === made.brandsWithLogo && JSON.stringify(names) === JSON.stringify([...names].sort((a, b) => a.localeCompare(b, 'en', { sensitivity: 'base' }))) && logos.every((l) => ! l.link) && ['Huge', 'Square', 'Tall', 'Tiny', 'Wide', 'Wide2'].every((n) => names.includes('HP5 audit ' + n)), names.join('|'));
    check('every box is 180 × 120 (height × 3:2)', logos.every((l) => near(l.box[0], 180, 0.5) && near(l.box[1], 120, 0.5)), JSON.stringify(logos.map((l) => l.box.map(Math.round))));
    check('no logo is cropped: each fits inside its box and keeps its own proportion (±1%)', logos.every((l) => l.inBox && Math.abs(l.img[0] / l.img[1] - l.nat[0] / l.nat[1]) / (l.nat[0] / l.nat[1]) < 0.02), JSON.stringify(logos.map((l) => ({ a: l.alt.slice(10), img: l.img.map(Math.round), nat: l.nat }))));
    const tiny = logos.find((l) => l.alt.endsWith('Tiny'));
    check('a logo smaller than the box is not enlarged (the tiny 60 × 30 one)', near(tiny.img[0], 60, 1.5), JSON.stringify(tiny.img));
    const huge = logos.find((l) => l.alt.endsWith('Huge'));
    check('a big logo is shrunk to the box (the 1600 × 900 one: at most 180 × 120)', huge.img[0] <= 180.5 && huge.img[1] <= 120.5, JSON.stringify(huge.img));
    await shotOf(page, 'hp5-brands-1440');
    await ctx.close();

    // the phone height
    ctx = await newCtx(390);
    page = track(await ctx.newPage());
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const ph = await page.evaluate(() => [...document.querySelectorAll('.sf-car-item')].map((li) => li.getBoundingClientRect().height));
    check('on a phone the box height is the phone height (100px)', ph.every((h) => near(h, 100, 0.5)) && ! (await sideways(page)), JSON.stringify(ph.map(Math.round)));
    await shotOf(page, 'hp5-brands-390');
    await ctx.close();

    // ============================================================ the blocks carousel (HB-5 «Снимка с текст») and the images carousel
    check('the blocks carousel (240px, 4:5, radius 12) published', php(PUBLISH([car('blocks', { blocks, height: '240', height_phone: '160', ratio: '4:5', gap: '12', radius: '12' })])) === '[]');
    ctx = await newCtx(1440);
    page = track(await ctx.newPage());
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const bl = await page.evaluate(() => [...document.querySelectorAll('.sf-car-item')].map((li) => { const b = li.getBoundingClientRect(); const k = li.querySelector('.sf-hb'); const r = k ? k.getBoundingClientRect() : null; return { box: [b.width, b.height], block: r ? [r.width, r.height] : null }; }));
    check('4 blocks, each box is 192 × 240 (240 × 4:5) and the block fills it', bl.length === 4 && bl.every((x) => near(x.box[0], 192, 0.5) && near(x.box[1], 240, 0.5) && x.block && near(x.block[0], x.box[0], 1) && near(x.block[1], x.box[1], 1)), JSON.stringify(bl));
    check('nothing scrolls the page sideways', ! (await sideways(page)));
    await shotOf(page, 'hp5-blocks-1440');
    await ctx.close();

    check('the images carousel (a link on the first picture only) published', php(PUBLISH([car('images', { images: [{ picture: pics.wide, link: '/product-category/' + CATEGORY, alt: 'Към категорията' }, { picture: pics.tall, link: null, alt: '' }, { picture: pics.square, link: null, alt: 'Квадрат' }] })])) === '[]');
    ctx = await newCtx(1440);
    page = track(await ctx.newPage());
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const im = await page.evaluate(() => ({ imgs: document.querySelectorAll('.sf-car-img').length, links: [...document.querySelectorAll('.sf-car-link')].map((a) => a.getAttribute('href')), alts: [...document.querySelectorAll('.sf-car-img')].map((i) => i.alt) }));
    check('3 pictures, the link only where it is set, the alt text printed', im.imgs === 3 && im.links.length === 1 && im.links[0] === '/product-category/' + CATEGORY && im.alts[0] === 'Към категорията' && im.alts[1] === '', JSON.stringify(im));
    await ctx.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (created) {
        try {
            php(RESTORE);
            const after = JSON.parse(php(SUMS));
            check('everything is back: the settings table (exact rows: the published home layout and the draft included), the staff, categories, brands, products, variations, stock, links, media assets, blocks and files are as they were (counts / checksum)', before && JSON.stringify(before) === JSON.stringify(after), JSON.stringify({ before, after }));
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
