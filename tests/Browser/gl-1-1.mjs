// A REAL-BROWSER run of GL-1.1: the SPACING of the product page gallery (tile gap 8px, gallery/summary column gap 32px, tile radius 4px, no border or padding on a tile).
// NOT part of `php artisan test`: run by hand against the dev site. Same throw-away product, settings handling and cleanup as gl-1.mjs (4 photos here); prints the
// measurements and a JSON report; exit code 1 when a check failed. MODE=before only prints (no checks fail the run).
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/gl-1.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const SLUG = 'gl1-gallery';
const ROOT = resolve(import.meta.dirname, '..', '..');
const KEYS = ['desktop_mode', 'mobile_mode', 'width_percent', 'odd_last', 'hover_zoom'].map((k) => 'storefront.gallery.' + k);

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node gl-1.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

// ---- the dev database, through the project's own code (tinker); the PHP is one line per call ---------------------------------------------------------
const php = (code) => {
    try {
        return execFileSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8' }).trim().split('\n').pop();
    } catch (e) {
        throw new Error('php failed: ' + String(e.stdout || '').slice(-600) + String(e.stderr || '').slice(-300));
    }
};

const SETUP = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$now = now();
$pid = DB::table('catalog_products')->insertGetId(['type'=>'simple','name'=>'GL1 gallery','slug'=>'gl1-gallery','base_sku'=>'GL1-BASE','status'=>'active','catalog_visibility'=>'visible','is_featured'=>0,'created_at'=>$now,'updated_at'=>$now,'timeline_at'=>$now]);
$vid = DB::table('catalog_variations')->insertGetId(['product_id'=>$pid,'sort_order'=>0,'type'=>'standard','status'=>'active','attribute_signature'=>hash('sha256','gl1'),'sku'=>'GL1-1','is_visible'=>1,'is_purchasable'=>1,'created_at'=>$now,'updated_at'=>$now]);
DB::table('stock_levels')->insert(['variation_id'=>$vid,'quantity'=>5,'created_at'=>$now,'updated_at'=>$now]);
$colours = [[200,60,60],[60,140,200],[80,170,90],[220,170,40],[140,80,180]];
foreach ($colours as $i => $c) {
  $n = $i + 1; $variants = [];
  foreach (['thumbnail'=>300,'medium'=>600,'large'=>900] as $tier => $w) {
    $h = (int) ($w * 4 / 3); $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, $c[0], $c[1], $c[2]));
    imagefilledrectangle($im, (int) ($w*0.1), (int) ($h*0.1), (int) ($w*0.9), (int) ($h*0.9), imagecolorallocate($im, $c[0]-30, $c[1]-30, $c[2]-30));
    $small = imagecreatetruecolor(16, 16); imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255)); imagestring($small, 5, 4, 0, (string) $n, imagecolorallocate($small, 0, 0, 0));
    imagecopyresized($im, $small, (int) ($w*0.3), (int) ($h*0.4), 0, 0, (int) ($w*0.4), (int) ($w*0.4), 16, 16);
    ob_start(); imagewebp($im, null, 80); $bytes = ob_get_clean();
    Storage::disk('public')->put("gl1/$n-$tier.webp", $bytes);
    $variants[] = ['tier'=>$tier,'width'=>$w,'height'=>$h,'quality'=>80,'path'=>"gl1/$n-$tier.webp"];
  }
  $mid = DB::table('catalog_media')->insertGetId(['type'=>'image','disk'=>'public','path'=>"gl1/$n.jpg",'processing_status'=>'ready','variants'=>json_encode($variants),'mime'=>'image/jpeg','width'=>900,'height'=>1200,'created_at'=>$now,'updated_at'=>$now]);
  DB::table('catalog_product_media')->insert(['product_id'=>$pid,'media_id'=>$mid,'sort_order'=>$n]);
}
echo json_encode(['product'=>$pid]);`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$pid = DB::table('catalog_products')->where('slug','gl1-gallery')->value('id');
if ($pid) {
  $vids = DB::table('catalog_variations')->where('product_id',$pid)->pluck('id');
  DB::table('stock_levels')->whereIn('variation_id',$vids)->delete();
  DB::table('catalog_product_media')->where('product_id',$pid)->delete();
  DB::table('catalog_variations')->where('product_id',$pid)->delete();
  DB::table('catalog_products')->where('id',$pid)->delete();
}
DB::table('catalog_media')->where('path','like','gl1/%')->delete();
Storage::disk('public')->deleteDirectory('gl1');
echo json_encode(['products'=>DB::table('catalog_products')->where('slug','gl1-gallery')->count(),'media'=>DB::table('catalog_media')->where('path','like','gl1/%')->count(),'files'=>count(Storage::disk('public')->allFiles('gl1')),'variations'=>DB::table('catalog_variations')->where('sku','GL1-1')->count()]);`;

const readSettings = () => JSON.parse(php(`$r = app(App\\Settings\\Contracts\\SiteSettingsRepository::class); echo json_encode(array_map(fn ($k) => $r->get($k), json_decode('${JSON.stringify(KEYS)}', true)));`));
const writeSettings = (values) => php(`$r = app(App\\Settings\\Contracts\\SiteSettingsRepository::class); foreach (json_decode('${JSON.stringify(Object.fromEntries(KEYS.map((k, i) => [k, values[i]])))}', true) as $k => $v) { $v === null ? $r->forget($k) : $r->set($k, $v); } echo 'ok';`);
const setGallery = ({ desktop = 'grid', mobile = 'carousel', width = '65', odd = 'same', zoom = 'light' } = {}) => writeSettings([desktop, mobile, width, odd, zoom]);
// how many of the throw-away photos are usable (the rest get missing_at); the video is attached or detached
const usable = (n) => php(`$ids = DB::table('catalog_media')->where('path','like','gl1/%')->orderBy('id')->pluck('id'); DB::table('catalog_media')->whereIn('id',$ids)->update(['missing_at'=>null]); if ($ids->slice(${n})->isNotEmpty()) { DB::table('catalog_media')->whereIn('id',$ids->slice(${n})->values())->update(['missing_at'=>now()]); } echo 'ok';`);
const video = (on) => php(`$pid = DB::table('catalog_products')->where('slug','gl1-gallery')->value('id'); DB::table('catalog_product_media')->where('product_id',$pid)->where('sort_order',9)->delete(); if (${on ? 'true' : 'false'}) { $vid = DB::table('catalog_media')->where('type','video')->where('processing_status','ready')->whereNull('missing_at')->orderBy('size_bytes')->value('id'); DB::table('catalog_product_media')->insert(['product_id'=>$pid,'media_id'=>$vid,'sort_order'=>9]); echo $vid; } else { echo 'detached'; }`);

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const consoleErrors = [];
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 300) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const shot = async (page, name, locator = null) => { if (! OUT) return; const path = join(OUT, name + '.png'); shots.push(path); await (locator ?? page).screenshot({ path }); };
const near = (a, b, tolerance = 1.5) => Math.abs(a - b) <= tolerance;
// the boxes of every element matching `selector` on the page ({w: 0} for one that is not rendered)
const boxes = (page, selector) => page.locator(selector).evaluateAll((els) => els.map((el) => { const r = el.getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height, right: r.right, bottom: r.bottom }; }));
const open = async (page) => {
    await page.goto(BASE + '/product/' + SLUG, { waitUntil: 'networkidle' });
    await sleep(300);
};
const flag = (page, selector, name) => page.locator(selector).evaluate((el, n) => el.classList.contains(n), name);

const BEFORE = process.env.MODE === 'before';
let originalSettings = null;
let setupDone = false;
const measures = {};

// every number is in CSS px; `gap` of a pair is the free space between the two boxes
const m = async (page, selector) => page.locator(selector).evaluateAll((els) => els.map((el) => { const r = el.getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height, right: r.right, bottom: r.bottom }; }));
const gapX = (a, b) => Math.round((b.x - a.right) * 10) / 10;
const gapY = (a, b) => Math.round((b.y - a.bottom) * 10) / 10;
const rule = (name, ok, detail) => { if (BEFORE) { console.error('MEASURE ' + name + ': ' + detail); } else { check(name, ok, detail); } };

const columns = async (page) => {
    const [gal] = await m(page, '[data-sf-gallery]');
    const [sum] = await m(page, '.sf-summary');
    return { gallery: Math.round(gal.w * 10) / 10, summary: Math.round(sum.w * 10) / 10, gap: gapX(gal, sum) };
};
const tileProps = (page) => page.locator('.sf-gal-item').first().evaluate((el) => { const c = getComputedStyle(el); const link = getComputedStyle(el.querySelector('.sf-gal-link')); return { radius: c.borderTopLeftRadius, border: c.borderTopWidth, padding: c.paddingTop + ' ' + c.paddingLeft, linkBorder: link.borderTopWidth, linkPadding: link.paddingTop, overflow: c.overflow }; });
const hOverflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);

try {
    originalSettings = readSettings();
    setupDone = true;
    php(SETUP);
    video(false);
    usable(4);

    const desktop = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
    const page = await desktop.newPage();
    page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
    const tag = BEFORE ? 'before' : 'after';

    // ---- grid ----
    setGallery();
    await open(page);
    const figs = await m(page, '.sf-gal-item');
    measures.gridGapX = gapX(figs[0], figs[1]);
    measures.gridGapY = gapY(figs[0], figs[2]);
    measures.columns = await columns(page);
    measures.tile = await tileProps(page);
    measures.container = await page.locator('article.sf-product').evaluate((el) => {
        const chain = [];
        for (let node = el.parentElement; node && node !== document.documentElement; node = node.parentElement) {
            const c = getComputedStyle(node);
            const r = node.getBoundingClientRect();
            chain.push({ node: node.tagName.toLowerCase() + (node.className ? '.' + String(node.className).trim().split(/\s+/).join('.') : ''), width: Math.round(r.width), maxWidth: c.maxWidth, paddingLeft: c.paddingLeft, paddingRight: c.paddingRight, marginLeft: c.marginLeft });
        }
        const c = getComputedStyle(el);
        return { product: { columnGap: c.columnGap, rowGap: c.rowGap, template: c.gridTemplateColumns }, summary: { padding: getComputedStyle(document.querySelector('.sf-summary')).padding, margin: getComputedStyle(document.querySelector('.sf-summary')).margin }, chain };
    });
    rule('grid: the gap between two tiles is 8px (horizontal and vertical)', near(measures.gridGapX, 8, 1) && near(measures.gridGapY, 8, 1), JSON.stringify([measures.gridGapX, measures.gridGapY]));
    rule('grid: the gap between the gallery column and the summary column is 32px', near(measures.columns.gap, 32, 1), JSON.stringify(measures.columns));
    rule('a tile has a 4px radius, no border and no padding (frame and link)', measures.tile.radius === '4px' && measures.tile.border === '0px' && measures.tile.padding === '0px 0px' && measures.tile.linkBorder === '0px' && measures.tile.linkPadding === '0px', JSON.stringify(measures.tile));
    rule('grid: no horizontal scroll at 1400', (await hOverflow(page)) <= 0, await hOverflow(page));
    await shot(page, `${tag}-grid-1400`);

    // hover: the zoomed photo stays inside the rounded frame
    await page.mouse.move(5, 5);
    await page.locator('.sf-gal-item').first().hover();
    await sleep(1700);
    const inside = await page.locator('.sf-gal-item').first().evaluate((el) => { const c = getComputedStyle(el); return c.overflow === 'hidden'; });
    rule('hover: the frame still clips (overflow hidden) so the zoom stays inside the corners', inside, inside);
    await shot(page, `${tag}-grid-hover-1400`, page.locator('.sf-gal-item').first());

    // ---- thumbs-left ----
    setGallery({ desktop: 'thumbs-left' });
    await open(page);
    const tl = await m(page, '.sf-gal-thumb');
    const [tlThumbs] = await m(page, '.sf-gal-thumbs');
    const [tlMain] = await m(page, '.sf-gal-main');
    measures.thumbsLeft = { thumbGap: gapY(tl[0], tl[1]), stageGap: gapX(tlThumbs, tlMain), columns: await columns(page) };
    rule('thumbs-left: the gap between thumbnails and between them and the big image is 8px', near(measures.thumbsLeft.thumbGap, 8, 1) && near(measures.thumbsLeft.stageGap, 8, 1), JSON.stringify(measures.thumbsLeft));
    rule('thumbs-left: the column gap is 32px', near(measures.thumbsLeft.columns.gap, 32, 1), measures.thumbsLeft.columns.gap);
    rule('thumbs-left: a thumbnail has a 4px radius', (await page.locator('.sf-gal-thumb').first().evaluate((el) => getComputedStyle(el).borderTopLeftRadius)) === '4px');
    await shot(page, `${tag}-thumbs-left-1400`);

    // ---- thumbs-below ----
    setGallery({ desktop: 'thumbs-below' });
    await open(page);
    const tb = await m(page, '.sf-gal-thumb');
    const [tbThumbs] = await m(page, '.sf-gal-thumbs');
    const [tbMain] = await m(page, '.sf-gal-main');
    measures.thumbsBelow = { thumbGap: gapX(tb[0], tb[1]), stageGap: gapY(tbMain, tbThumbs), columns: await columns(page) };
    rule('thumbs-below: the gap between thumbnails and between them and the big image is 8px', near(measures.thumbsBelow.thumbGap, 8, 1) && near(measures.thumbsBelow.stageGap, 8, 1), JSON.stringify(measures.thumbsBelow));
    rule('thumbs-below: the column gap is 32px', near(measures.thumbsBelow.columns.gap, 32, 1), measures.thumbsBelow.columns.gap);
    rule('thumbs-below: no horizontal scroll at 1400', (await hOverflow(page)) <= 0);
    await shot(page, `${tag}-thumbs-below-1400`);
    await desktop.close();

    // ---- phone: carousel slides and the mobile thumbs ----
    const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, deviceScaleFactor: 1, ignoreHTTPSErrors: true });
    const mob = await phone.newPage();
    mob.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
    setGallery();
    await open(mob);
    const slides = await m(mob, '.sf-gal-item');
    measures.carouselGap = gapX(slides[0], slides[1]);
    rule('carousel at 390: the gap between slides is 8px', near(measures.carouselGap, 8, 1), measures.carouselGap);
    const [mobGal] = await m(mob, '[data-sf-gallery]');
    const [mobSum] = await m(mob, '.sf-summary');
    measures.mobileRowGap = gapY(mobGal, mobSum);
    rule('carousel at 390: no horizontal scroll', (await hOverflow(mob)) <= 0, await hOverflow(mob));
    await shot(mob, `${tag}-carousel-390`);
    setGallery({ mobile: 'thumbs-below' });
    await open(mob);
    const mt = await m(mob, '.sf-gal-thumb');
    const [mtThumbs] = await m(mob, '.sf-gal-thumbs');
    const [mtMain] = await m(mob, '.sf-gal-main');
    measures.mobileThumbs = { thumbGap: gapX(mt[0], mt[1]), stageGap: gapY(mtMain, mtThumbs) };
    rule('mobile thumbs-below: 8px between thumbnails and under the big image', near(measures.mobileThumbs.thumbGap, 8, 1) && near(measures.mobileThumbs.stageGap, 8, 1), JSON.stringify(measures.mobileThumbs));
    rule('mobile thumbs-below: no horizontal scroll', (await hOverflow(mob)) <= 0);
    await shot(mob, `${tag}-thumbs-below-390`);
    await phone.close();

    console.error('MEASURES ' + JSON.stringify(measures));
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 600));
} finally {
    let left = 'not run';

    try {
        if (originalSettings) { writeSettings(originalSettings); }
        if (setupDone) { left = php(CLEANUP); }
        check('the settings are back as they were', JSON.stringify(readSettings()) === JSON.stringify(originalSettings));
    } catch (e) {
        check('the cleanup finished', false, String(e).slice(0, 300));
    }

    console.error('cleanup left: ' + left);
    check('nothing of the throw-away product is left (product, media rows, files, variations)', left === '{"products":0,"media":0,"files":0,"variations":0}', left);
}

check('no page errors', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
