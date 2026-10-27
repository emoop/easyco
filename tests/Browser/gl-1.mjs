// A REAL-BROWSER run of GL-1: the product page gallery (desktop modes, mobile modes, hover zoom, lightbox, videos, no-JavaScript).
// NOT part of `php artisan test`: run by hand against the dev site. It creates ONE throw-away simple product «GL1 gallery» (slug gl1-gallery) with 5 generated photos
// (900x1200 with 600 and 300 renditions, files under storage/app/public/gl1/) and attaches one EXISTING dev video to it; it sets the gallery settings through the
// site-settings repository, and at the end removes everything it made and puts the five settings back (the cleanup prints what is left). Prints a JSON report;
// exit code 1 when a check failed.
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

let originalSettings = null;
let setupDone = false;

try {
    originalSettings = readSettings();
    console.error('original settings: ' + JSON.stringify(originalSettings));
    setupDone = true; // the cleanup is idempotent: it also runs when the setup stopped half way
    php(SETUP);
    video(false);

    // ============================================================ desktop, 1400 px, mouse
    const desktop = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
    const page = await desktop.newPage();
    const requested = [];
    page.on('request', (r) => requested.push(r.url()));
    page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));

    // ---- default: grid, 65 % ----
    setGallery();
    usable(5);
    await open(page);
    const gal = page.locator('[data-sf-gallery]');
    check('the defaults are printed: grid / carousel / same / light, width class 65', (await gal.getAttribute('data-gal-desktop')) === 'grid' && (await gal.getAttribute('data-gal-mobile')) === 'carousel' && (await gal.getAttribute('data-gal-odd')) === 'same' && (await gal.getAttribute('data-gal-zoom')) === 'light' && (await flag(page, '[data-sf-gallery]', 'sf-gal-w-65')));
    const productBox = (await boxes(page, 'article.sf-product'))[0];
    const galleryBox = (await boxes(page, '[data-sf-gallery]'))[0];
    const cols = productBox.w - 24; // the column gap of the product grid (gap-6)
    check('grid: the gallery takes 65 % of the two-column area', near(galleryBox.w / cols, 0.65, 0.012), (galleryBox.w / cols).toFixed(3));

    let figs = await boxes(page, '.sf-gal-item');
    check('grid, 5 photos: five figures, all visible', figs.length === 5 && figs.every((f) => f.w > 0), figs.length);
    check('grid: two equal columns (figure 1 and 2 side by side, same width)', near(figs[0].w, figs[1].w) && figs[1].x > figs[0].x + figs[0].w - 1 && near(figs[0].x, figs[2].x), JSON.stringify(figs.slice(0, 3).map((f) => [Math.round(f.x), Math.round(f.w)])));
    check('grid, odd_last=same: the 5th (odd) photo is the same size as the others, in the left column', near(figs[4].w, figs[0].w) && near(figs[4].h, figs[0].h) && near(figs[4].x, figs[0].x), JSON.stringify([figs[4].w, figs[0].w]));
    const ratio = figs[0].w / figs[0].h;
    check('every figure keeps the 3:4 ratio and the image is contained (never cropped)', near(ratio, 0.75, 0.01) && (await page.locator('.sf-gal-img').first().evaluate((el) => getComputedStyle(el).objectFit)) === 'contain', ratio.toFixed(3));
    await shot(page, '01-grid-default-1400');

    usable(4);
    await open(page);
    figs = await boxes(page, '.sf-gal-item');
    check('grid, 4 photos: all four are equal', figs.length === 4 && figs.every((f) => near(f.w, figs[0].w) && near(f.h, figs[0].h)));

    usable(1);
    await open(page);
    figs = await boxes(page, '.sf-gal-item');
    const g1 = (await boxes(page, '[data-sf-gallery]'))[0];
    check('grid, 1 photo: it is full width of the gallery', figs.length === 1 && near(figs[0].w, g1.w, 2), JSON.stringify([figs[0].w, g1.w]));

    usable(5);
    setGallery({ odd: 'full' });
    await open(page);
    figs = await boxes(page, '.sf-gal-item');
    const g5 = (await boxes(page, '[data-sf-gallery]'))[0];
    check('grid, odd_last=full: the 5th photo spans both columns', near(figs[4].w, g5.w, 2) && figs[0].w < g5.w * 0.6, JSON.stringify([figs[4].w, g5.w]));

    setGallery({ width: '80' });
    await open(page);
    const p80 = (await boxes(page, 'article.sf-product'))[0];
    const g80 = (await boxes(page, '[data-sf-gallery]'))[0];
    check('width_percent=80 is applied through the class', near(g80.w / (p80.w - 24), 0.8, 0.012), (g80.w / (p80.w - 24)).toFixed(3));

    // ---- thumbs-left ----
    setGallery({ desktop: 'thumbs-left' });
    requested.length = 0;
    await open(page);
    const bigOfNumber = (n) => requested.filter((u) => new RegExp('/gl1/' + n + '-(medium|large)\\.webp').test(u));
    check('thumbs-left: the thumbnail column is LEFT of the big image', (await boxes(page, '.sf-gal-thumbs'))[0].right <= (await boxes(page, '.sf-gal-main'))[0].x + 1);
    const visibleFigs = (await boxes(page, '.sf-gal-item')).filter((f) => f.w > 0).length;
    check('thumbs-left: only the first big image is shown', visibleFigs === 1, visibleFigs);
    check('thumbs-left, BEFORE any interaction: only the FIRST big image was downloaded', bigOfNumber(1).length >= 1 && [2, 3, 4, 5].every((n) => bigOfNumber(n).length === 0), JSON.stringify([1, 2, 3, 4, 5].map((n) => bigOfNumber(n).length)) + ' ' + requested.filter((u) => /gl1/.test(u)).map((u) => u.split('/').pop()).join(' '));
    check('thumbs-left: 5 thumbnails, the first one is current', (await page.locator('.sf-gal-thumb').count()) === 5 && (await page.locator('.sf-gal-thumb').first().getAttribute('aria-current')) === 'true');
    await shot(page, '02-thumbs-left-1400');
    await page.locator('.sf-gal-thumb').nth(2).click();
    await sleep(500);
    const activeIndex = await page.locator('.sf-gal-item').evaluateAll((els) => els.findIndex((el) => el.classList.contains('is-active')));
    check('thumbs-left: clicking the 3rd thumbnail makes the 3rd image the big one', activeIndex === 2 && (await page.locator('.sf-gal-thumb').nth(2).getAttribute('aria-current')) === 'true' && (await page.locator('.sf-gal-thumb').first().getAttribute('aria-current')) === 'false');
    await page.waitForLoadState('networkidle');
    await sleep(500);
    check('thumbs-left: the 3rd big image is downloaded only now (and the 2nd, 4th and 5th still are not)', bigOfNumber(3).length >= 1 && [2, 4, 5].every((n) => bigOfNumber(n).length === 0), JSON.stringify([2, 3, 4, 5].map((n) => bigOfNumber(n).length)) + ' ' + String(await page.locator('.sf-gal-item').nth(2).locator('img').getAttribute('src')));
    check('thumbs-left: the clicked image is visible and the first one is hidden', (await boxes(page, '.sf-gal-item'))[2].w > 0 && (await boxes(page, '.sf-gal-item'))[0].w === 0);

    // ---- thumbs-below ----
    setGallery({ desktop: 'thumbs-below' });
    await open(page);
    check('thumbs-below: the thumbnails are in a row UNDER the big image', (await boxes(page, '.sf-gal-thumbs'))[0].y >= (await boxes(page, '.sf-gal-main'))[0].bottom - 1 && (await boxes(page, '.sf-gal-thumb')).every((b, i, all) => near(b.y, all[0].y)));

    // ---- hover zoom ----
    for (const zoom of ['light', 'medium', 'off']) {
        setGallery({ zoom });
        await open(page);
        const first = page.locator('.sf-gal-item').first();
        await page.mouse.move(5, 5);
        await sleep(200);
        await first.hover();
        await sleep(1700);
        const expected = Number(await gal.evaluate((el) => getComputedStyle(el).getPropertyValue('--sf-gal-zoom')));
        const scale = await first.locator('.sf-gal-img').evaluate((el) => { const m = getComputedStyle(el).transform; return m === 'none' ? 1 : Number(m.match(/matrix\(([^,]+),/)[1]); });
        check(`hover zoom ${zoom}: the image scale equals --sf-gal-zoom (${expected})`, near(scale, expected, 0.005), scale);
        check(`hover zoom ${zoom}: the frame keeps its size`, near((await boxes(page, '.sf-gal-item'))[0].w, (await boxes(page, '.sf-gal-item'))[0].w));
        if (zoom === 'off') { check('hover zoom off: no scale at all', scale === 1, scale); }
    }

    // ---- video among the images ----
    setGallery();
    const videoId = video(true);
    requested.length = 0;
    await open(page);
    figs = await boxes(page, '.sf-gal-item');
    check('the video is the 6th item, in the grid like an image (same size)', figs.length === 6 && near(figs[5].w, figs[0].w) && near(figs[5].h, figs[0].h), JSON.stringify(figs.map((f) => Math.round(f.w))));
    const vState = await page.locator('video.sf-gal-video').evaluate((v) => ({ paused: v.paused, time: v.currentTime, autoplay: v.autoplay, loop: v.loop, controls: v.controls, preload: v.preload, attrs: v.getAttributeNames().join(',') }));
    check('the video is paused at the start (first frame at 0.1 s), controls on, no autoplay, no loop', vState.paused && vState.time <= 0.1 && ! vState.autoplay && ! vState.loop && vState.controls, JSON.stringify(vState));
    const videoRequests = requested.filter((u) => /\.mp4/.test(u));
    console.error('video requests before play (metadata preload; no poster exists for a video): ' + videoRequests.length);
    check('the video has a thumbnail among the 6 and no lightbox link', (await page.locator('.sf-gal-thumb').count()) === 6 && (await page.locator('[data-gal-kind="video"] [data-lightbox], [data-gal-kind="video"] a').count()) === 0 && (await page.locator('[data-lightbox]').count()) === 5);
    await page.locator('video.sf-gal-video').scrollIntoViewIfNeeded();
    await shot(page, '03-grid-with-video-1400');

    setGallery({ desktop: 'thumbs-left' });
    await open(page);
    await page.evaluate(() => { window.__paused = 0; const original = HTMLMediaElement.prototype.pause; HTMLMediaElement.prototype.pause = function () { window.__paused++; return original.call(this); }; });
    await page.locator('.sf-gal-thumb').nth(5).click();
    await sleep(300);
    check('thumbs-left: selecting the video thumbnail shows the video', (await boxes(page, '.sf-gal-item'))[5].w > 0 && (await boxes(page, '.sf-gal-item'))[0].w === 0);
    await page.locator('video.sf-gal-video').click({ position: { x: 20, y: 20 } }).catch(() => {});
    check('clicking the video does not open the lightbox', ! (await page.locator('dialog.sf-lightbox').evaluate((d) => d.open)));
    const pausedBefore = await page.evaluate(() => window.__paused);
    await page.locator('.sf-gal-thumb').nth(1).click();
    await sleep(300);
    check('selecting another thumbnail pauses the video (pause() was called)', (await page.evaluate(() => window.__paused)) > pausedBefore, await page.evaluate(() => window.__paused));

    // ---- lightbox (grid) ----
    setGallery();
    await open(page);
    const link0 = page.locator('a[data-lightbox="0"]');
    await link0.click();
    await sleep(400);
    const dlg = page.locator('dialog.sf-lightbox');
    check('lightbox: a click on the photo opens the dialog', await dlg.evaluate((d) => d.open));
    const lbSrc = await page.locator('.sf-lightbox-img').getAttribute('src');
    check('lightbox: it shows the LARGEST rendition (900 px), not the upload', /\/gl1\/1-large\.webp$/.test(lbSrc), lbSrc);
    check('lightbox: the counter counts the 5 photos only (the video is not in it)', (await page.locator('.sf-lightbox-count').innerText()).includes('1') && (await page.locator('.sf-lightbox-count').innerText()).includes('5'), await page.locator('.sf-lightbox-count').innerText());
    check('lightbox: the focus is on the close button; "previous" is disabled at the start', (await page.evaluate(() => document.activeElement.className)).includes('sf-lightbox-close') && await page.locator('.sf-lightbox-prev').isDisabled());
    await shot(page, '04-lightbox-1400');
    await page.keyboard.press('ArrowRight');
    await sleep(200);
    check('lightbox: ArrowRight goes to the next photo', /\/gl1\/2-large\.webp$/.test(await page.locator('.sf-lightbox-img').getAttribute('src')) && (await page.locator('.sf-lightbox-count').innerText()).includes('2'));
    await page.keyboard.press('ArrowLeft');
    await sleep(200);
    check('lightbox: ArrowLeft goes back', /\/gl1\/1-large\.webp$/.test(await page.locator('.sf-lightbox-img').getAttribute('src')));
    for (let i = 0; i < 4; i++) { await page.locator('.sf-lightbox-next').click(); }
    check('lightbox: at the last photo "next" is disabled (no wrap-around)', await page.locator('.sf-lightbox-next').isDisabled() && /\/gl1\/5-large\.webp$/.test(await page.locator('.sf-lightbox-img').getAttribute('src')));
    await page.keyboard.press('ArrowRight');
    check('lightbox: ArrowRight at the end stays', /\/gl1\/5-large\.webp$/.test(await page.locator('.sf-lightbox-img').getAttribute('src')));
    const scaleOf = () => page.locator('.sf-lightbox-img').evaluate((el) => Number(el.style.getPropertyValue('--sf-lb-scale') || 1));
    await page.mouse.move(700, 500);
    await page.mouse.wheel(0, -100);
    await sleep(200);
    check('lightbox: the wheel zooms in by 0.5 (the variable grows)', near(await scaleOf(), 1.5, 0.01), await scaleOf());
    for (let i = 0; i < 12; i++) { await page.mouse.wheel(0, -100); await sleep(30); }
    check('lightbox: the zoom is capped at 4', near(await scaleOf(), 4, 0.01), await scaleOf());
    await page.mouse.wheel(0, 100);
    await sleep(100);
    check('lightbox: the wheel back zooms out by 0.5', near(await scaleOf(), 3.5, 0.01), await scaleOf());
    const x0 = Number(await page.locator('.sf-lightbox-img').evaluate((el) => parseFloat(el.style.getPropertyValue('--sf-lb-x'))));
    await page.mouse.move(700, 500);
    await page.mouse.down();
    await page.mouse.move(640, 470, { steps: 5 });
    await page.mouse.up();
    const x1 = Number(await page.locator('.sf-lightbox-img').evaluate((el) => parseFloat(el.style.getPropertyValue('--sf-lb-x'))));
    check('lightbox: a drag pans the zoomed image', x1 !== x0 && near(x1 - x0, -60, 3), `${x0} -> ${x1}`);
    check('lightbox: a drag that ends on the dark area does not close it', await dlg.evaluate((d) => d.open));
    await page.keyboard.press('ArrowLeft');
    await sleep(100);
    check('lightbox: moving to another photo resets the zoom', (await scaleOf()) === 1);
    await page.mouse.dblclick(700, 500);
    await sleep(100);
    check('lightbox: a double click zooms to 2.5', near(await scaleOf(), 2.5, 0.01), await scaleOf());
    await page.mouse.dblclick(700, 500);
    await sleep(100);
    check('lightbox: a second double click goes back to 1', (await scaleOf()) === 1);
    await page.keyboard.press('Escape');
    await sleep(300);
    check('lightbox: Escape closes it', ! (await dlg.evaluate((d) => d.open)));
    const activeNow = await page.evaluate(() => document.activeElement.outerHTML.slice(0, 80));
    check('lightbox: the focus returns to the clicked link', (await page.evaluate(() => document.activeElement.getAttribute('data-lightbox'))) === '0', activeNow);
    await link0.click();
    await sleep(300);
    const under = await page.evaluate(() => document.elementFromPoint(20, 200).className);
    await page.mouse.click(20, 200);
    await sleep(300);
    check('lightbox: a click on the dark backdrop closes it', ! (await dlg.evaluate((d) => d.open)), under);
    await desktop.close();

    // ============================================================ phone, 390 px, touch
    const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, deviceScaleFactor: 1, ignoreHTTPSErrors: true });
    const mob = await phone.newPage();
    mob.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));

    setGallery();
    video(false);
    await open(mob);
    figs = await boxes(mob, '.sf-gal-item');
    check('carousel: the first slide is about 86 % wide and the NEXT one peeks in from the right edge', near(figs[0].w / (await boxes(mob, '.sf-gal-main'))[0].w, 0.86, 0.02) && figs[1].x > 0 && figs[1].x < 390 && figs[1].right > 390, JSON.stringify(figs.slice(0, 2).map((f) => [Math.round(f.x), Math.round(f.w)])));
    check('carousel: the thumbnail row is not shown', (await boxes(mob, '.sf-gal-thumbs'))[0].w === 0);
    const arrow = mob.locator('.sf-gal-next');
    check('carousel: the arrow is visible on the right edge', (await arrow.isVisible()) && (await boxes(mob, '.sf-gal-next'))[0].right > 340);
    await shot(mob, '05-carousel-390');
    const scrolls = async () => mob.locator('.sf-gal-main').evaluate((el) => Math.round(el.scrollLeft));
    const step = Math.round(figs[1].x - figs[0].x);
    const s0 = await scrolls();
    await arrow.click();
    await sleep(900);
    const s1 = await scrolls();
    check('carousel: the arrow moves ONE slide', near(s1 - s0, step, 6), `${s0} -> ${s1} (slide ${step})`);
    for (let i = 0; i < 6 && await arrow.isVisible(); i++) { await arrow.click(); await sleep(800); }
    check('carousel: the arrow is hidden at the last slide', ! (await arrow.isVisible()));
    check('carousel: no dots, no horizontal page overflow', (await mob.locator('.sf-gal-dots, .sf-dots').count()) === 0 && (await mob.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)) <= 0);

    setGallery({ mobile: 'thumbs-below' });
    await open(mob);
    check('mobile thumbs-below: one big image and the thumbnails in a row UNDER it', (await boxes(mob, '.sf-gal-item')).filter((f) => f.w > 0).length === 1 && (await boxes(mob, '.sf-gal-thumbs'))[0].y >= (await boxes(mob, '.sf-gal-main'))[0].bottom - 1 && ! (await arrow.isVisible()));
    await shot(mob, '06-thumbs-below-390');
    await mob.locator('.sf-gal-thumb').nth(3).click();
    await sleep(300);
    check('mobile thumbs-below: a thumbnail changes the big image', (await boxes(mob, '.sf-gal-item'))[3].w > 0 && (await boxes(mob, '.sf-gal-item'))[0].w === 0);

    // touch swipe inside the lightbox (real touch events through CDP)
    setGallery({ mobile: 'thumbs-below' });
    await open(mob);
    await mob.locator('a[data-lightbox="0"]').tap();
    await sleep(500);
    check('mobile: a tap opens the lightbox', await mob.locator('dialog.sf-lightbox').evaluate((d) => d.open));
    const cdp = await phone.newCDPSession(mob);
    const swipe = async (fromX, toX) => {
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: fromX, y: 420 }] });
        for (let i = 1; i <= 6; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: fromX + ((toX - fromX) * i) / 6, y: 420 }] }); await sleep(16); }
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        await sleep(300);
    };
    await swipe(320, 80);
    check('mobile lightbox: a swipe left goes to the next photo', /\/gl1\/2-large\.webp$/.test(await mob.locator('.sf-lightbox-img').getAttribute('src')), await mob.locator('.sf-lightbox-img').getAttribute('src'));
    await swipe(80, 320);
    check('mobile lightbox: a swipe right goes back', /\/gl1\/1-large\.webp$/.test(await mob.locator('.sf-lightbox-img').getAttribute('src')));
    check('mobile lightbox: the stage lets the browser pinch-zoom (touch-action pan-y pinch-zoom)', (await mob.locator('.sf-lightbox-stage').evaluate((el) => getComputedStyle(el).touchAction)).includes('pinch-zoom'));
    await shot(mob, '07-lightbox-390');
    await phone.close();

    // ============================================================ JavaScript off
    setGallery({ desktop: 'thumbs-left' });
    const nojs = await browser.newContext({ viewport: { width: 1400, height: 1000 }, javaScriptEnabled: false, ignoreHTTPSErrors: true });
    const bare = await nojs.newPage();
    const response = await bare.goto(BASE + '/product/' + SLUG, { waitUntil: 'load' });
    check('without JavaScript the product page answers 200', response && response.status() === 200, response && response.status());
    const bareFigs = (await boxes(bare, '.sf-gal-item')).filter((f) => f.w > 0);
    check('without JavaScript the first image is shown (thumbs mode: one figure)', bareFigs.length === 1);
    check('without JavaScript each big image is a link to its large rendition', (await bare.locator('a[data-lightbox]').evaluateAll((els) => els.every((a) => /-large\.webp$/.test(a.getAttribute('href'))))) && (await bare.locator('a[data-lightbox]').count()) === 5);
    check('without JavaScript the thumbnails and the arrow are not shown', (await boxes(bare, '.sf-gal-thumbs'))[0].w === 0);
    await nojs.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 600));
} finally {
    let left = 'not run';

    try {
        if (originalSettings) { writeSettings(originalSettings); }
        if (setupDone) { left = php(CLEANUP); }
        console.error('settings now: ' + JSON.stringify(readSettings()) + ' (were ' + JSON.stringify(originalSettings) + ')');
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
