// A REAL-BROWSER run of GL-2: the «Страница и карти» and «Продуктова страница» sections of the «Дизайн» screen. NOT part of `php artisan test`: run by hand against a dev site whose data may be changed.
// It creates a throw-away admin («gl2-audit»), ONE throw-away product with 5 generated photos (slug gl2-gallery) and BACKS UP the `site_settings` table and the `appearance/` files first; at the end it restores
// them, removes the product, its media, its files and the admin, and verifies the settings table is byte-for-byte what it was (a checksum of every row). Prints a JSON report; exit code 1 when a check failed.
//
// With real clicks: «Дизайн» → «Страница и карти»: the page padding and the cards' border and corners (desktop and phone) → «Запази» → the home page and a category page carry the computed values at 1400 and
// 390 px; → «Продуктова страница»: a gallery mode (a select), the gap and corners of the photos, the gap to the summary and the sticky switch → «Запази» → the product page carries them (position `static`
// when off) and the gallery's data attributes; → «Върни по подразбиране» + «Запази» on each section → the computed values are the defaults again.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/gl-2.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { randomBytes } from 'node:crypto';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const SLUG = 'gl2-gallery';
const EMAIL = 'gl2-audit@example.test';
const PASSWORD = 'Gl2-' + randomBytes(6).toString('hex');
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node gl-2.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

// ---- the dev database, through the project's own code (tinker); one line per call -------------------------------------------------------------------
const php = (code) => {
    try {
        return execFileSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8' }).trim().split('\n').pop();
    } catch (e) {
        throw new Error('php failed: ' + String(e.stdout || '').slice(-600) + String(e.stderr || '').slice(-300));
    }
};

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
$files = Storage::disk('public')->allFiles('appearance');
file_put_contents(sys_get_temp_dir().'/gl2_backup.json', json_encode(['rows' => $rows, 'files' => $files, 'contents' => array_combine($files, array_map(fn ($f) => base64_encode(Storage::disk('public')->get($f)), $files)), 'sum' => md5(json_encode($rows))]));
echo json_encode(['rows' => count($rows), 'sum' => md5(json_encode($rows))]);`;

const SETUP = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
use EasyCo\Staff\Contracts\{PasswordHasher,RoleRepository,StaffRepository};
$role = app(RoleRepository::class)->findSystemRoleByName('Administrator');
$staff = EasyCo\Staff\Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'GL2 Audit', $role);
app(StaffRepository::class)->save($staff);
$now = now();
$pid = DB::table('catalog_products')->insertGetId(['type'=>'simple','name'=>'GL2 gallery','slug'=>'gl2-gallery','base_sku'=>'GL2-BASE','status'=>'active','catalog_visibility'=>'visible','is_featured'=>0,'created_at'=>$now,'updated_at'=>$now,'timeline_at'=>$now]);
$vid = DB::table('catalog_variations')->insertGetId(['product_id'=>$pid,'sort_order'=>0,'type'=>'standard','status'=>'active','attribute_signature'=>hash('sha256','gl2'),'sku'=>'GL2-1','is_visible'=>1,'is_purchasable'=>1,'created_at'=>$now,'updated_at'=>$now]);
DB::table('stock_levels')->insert(['variation_id'=>$vid,'quantity'=>5,'created_at'=>$now,'updated_at'=>$now]);
$colours = [[200,60,60],[60,140,200],[80,170,90],[220,170,40],[140,80,180]];
foreach ($colours as $i => $c) {
  $n = $i + 1; $variants = [];
  foreach (['thumbnail'=>300,'medium'=>600,'large'=>900] as $tier => $w) {
    $h = (int) ($w * 4 / 3); $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, $c[0], $c[1], $c[2]));
    ob_start(); imagewebp($im, null, 80); $bytes = ob_get_clean();
    Storage::disk('public')->put("gl2/$n-$tier.webp", $bytes);
    $variants[] = ['tier'=>$tier,'width'=>$w,'height'=>$h,'quality'=>80,'path'=>"gl2/$n-$tier.webp"];
  }
  $mid = DB::table('catalog_media')->insertGetId(['type'=>'image','disk'=>'public','path'=>"gl2/$n.jpg",'processing_status'=>'ready','variants'=>json_encode($variants),'mime'=>'image/jpeg','width'=>900,'height'=>1200,'created_at'=>$now,'updated_at'=>$now]);
  DB::table('catalog_product_media')->insert(['product_id'=>$pid,'media_id'=>$mid,'sort_order'=>$n]);
}
echo json_encode(['product'=>$pid]);`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/gl2_backup.json'), true);
DB::table('site_settings')->delete();
foreach ($bak['rows'] as $row) { DB::table('site_settings')->insert($row); }
foreach (Storage::disk('public')->allFiles('appearance') as $f) { if (! in_array($f, $bak['files'], true)) { Storage::disk('public')->delete($f); } }
foreach ($bak['contents'] as $f => $content) { Storage::disk('public')->put($f, base64_decode($content)); }
$pid = DB::table('catalog_products')->where('slug','gl2-gallery')->value('id');
if ($pid) {
  $vids = DB::table('catalog_variations')->where('product_id',$pid)->pluck('id');
  DB::table('stock_levels')->whereIn('variation_id',$vids)->delete();
  DB::table('catalog_product_media')->where('product_id',$pid)->delete();
  DB::table('catalog_variations')->where('product_id',$pid)->delete();
  DB::table('catalog_products')->where('id',$pid)->delete();
}
DB::table('catalog_media')->where('path','like','gl2/%')->delete();
Storage::disk('public')->deleteDirectory('gl2');
DB::table('staff')->where('email','${EMAIL}')->delete();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
echo json_encode(['sum' => md5(json_encode($rows)), 'was' => $bak['sum'], 'rows' => count($rows), 'products' => DB::table('catalog_products')->where('slug','gl2-gallery')->count(), 'media' => DB::table('catalog_media')->where('path','like','gl2/%')->count(), 'files' => count(Storage::disk('public')->allFiles('gl2')), 'staff' => DB::table('staff')->where('email','${EMAIL}')->count(), 'appearanceFiles' => count(Storage::disk('public')->allFiles('appearance')), 'appearanceWas' => count($bak['files'])]);`;

const CATEGORY = String.raw`
use Illuminate\Support\Facades\DB;
$slug = DB::table('catalog_categories')->orderBy('id')->value('slug');
echo is_string($slug) ? $slug : '';`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const consoleErrors = [];
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 400) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const near = (a, b, tolerance = 1.2) => Math.abs(a - b) <= tolerance;

let backedUp = false;
let report = null;

try {
    console.error('backup: ' + php(BACKUP));
    backedUp = true;
    php(SETUP);
    const category = php(CATEGORY);

    // ============================================================ the admin, 1400 px
    const admin = await browser.newContext({ viewport: { width: 1500, height: 1100 }, ignoreHTTPSErrors: true });
    const page = await admin.newPage();
    page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.stack || e).replace(/\s+/g, ' ').slice(0, 300)));
    page.on('response', (r) => { if (r.status() >= 500) consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 160)); });
    const shot = async (name, locator = null) => { if (! OUT) return; const path = join(OUT, name + '.png'); shots.push(path); await (locator ?? page).screenshot({ path, ...(locator ? {} : { fullPage: true }) }); };
    const settle = async () => { await page.waitForLoadState('networkidle'); await sleep(900); };

    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    // the control of a field: the number box or the switch / select inside the field that carries this label text
    const field = (label) => page.locator('.ds-props .fi-fo-field', { has: page.locator('label', { hasText: label }) }).first();
    const fillNumber = async (label, value) => { const box = field(label).locator('input').first(); await box.fill(String(value)); await box.blur(); };
    const save = async () => { await page.locator('.ds-props button[type=submit]').click(); await settle(); };
    const open = async (zone) => { await page.goto(BASE + '/admin/design?zone=' + zone); await settle(); };

    // the dev site may hold values the owner set by hand: both sections start from their defaults (the backup above puts them back at the end)
    for (const zone of ['page', 'product']) {
        await open(zone);
        await page.getByRole('button', { name: 'Върни по подразбиране' }).first().click();
        await sleep(900);
        if (await page.locator('.fi-modal-window:visible').count() > 0) { await page.locator('.fi-modal-window:visible').last().getByRole('button').filter({ hasNotText: /Отказ|Cancel/ }).last().click(); }
        await settle();
        await save();
    }

    // ---- «Страница и карти» ----
    await open('page');
    check('the zone list has both new sections', (await page.locator('.ds-zones button', { hasText: 'Страница и карти' }).count()) === 1 && (await page.locator('.ds-zones button', { hasText: 'Продуктова страница' }).count()) === 1);

    // GL-2.1: the toolbar of a plain section is the top banner's (Запази, no «Приложи», no «Публикувай»); the reset is the one extra button
    const toolbarOf = async () => page.locator('.fi-header-actions button, .fi-ac button').evaluateAll((els) => els.filter((el) => el.offsetParent !== null).map((el) => ({ text: el.innerText.trim(), bg: getComputedStyle(el).backgroundColor })));
    const plainToolbar = await toolbarOf();
    check('GL-2.1 the plain section has no «Приложи» and no «Публикувай» in its toolbar', ! plainToolbar.some((b) => /Приложи|Публикувай|Върни публикуваното/.test(b.text)), JSON.stringify(plainToolbar));
    check('GL-2.1 the plain section panel has no «Приложи»', ! (await page.locator('.ds-props').innerText()).includes('Приложи'));
    const plainSave = plainToolbar.find((b) => b.text === 'Запази');
    await open('topbar');
    const topbarSave = (await toolbarOf()).find((b) => b.text === 'Запази');
    check('GL-2.1 the Запази of the plain section has the same background as the top banner Запази', plainSave && topbarSave && plainSave.bg === topbarSave.bg, JSON.stringify({ plainSave, topbarSave }));
    await open('page');
    check('the page section shows the defaults', (await field('Минимален отстъп').locator('input').first().inputValue()) === '16' && (await field('Максимален отстъп').locator('input').first().inputValue()) === '160');
    await shot('01-design-page-defaults');

    // a bad value is refused under its field and nothing changes
    await fillNumber('Гъвкава част', '1;color:red');
    await save();
    check('a bad value is refused with a message under the field', (await page.locator('.ds-props .fi-fo-field', { has: page.locator('label', { hasText: 'Гъвкава част' }) }).first().innerText()).includes('Число от 0 до 8'));

    await fillNumber('Гъвкава част', '6');
    await fillNumber('Рамка (px)', '2'); // the first one is the desktop pair
    await page.locator('.ds-props fieldset', { has: page.locator('legend', { hasText: 'Десктоп' }) }).locator('input').nth(1).fill('12');
    const phonePair = page.locator('.ds-props fieldset', { has: page.locator('legend', { hasText: 'Телефон' }) });
    await phonePair.locator('input').nth(0).fill('1');
    await phonePair.locator('input').nth(1).fill('6');
    await save();
    await shot('02-design-page-changed');

    // ---- the home page and a category page ----
    const computedCards = async (url, viewport) => {
        const ctx = await browser.newContext({ viewport, ignoreHTTPSErrors: true, ...(viewport.width < 600 ? { hasTouch: true, isMobile: true } : {}) });
        const p = await ctx.newPage();
        p.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        const response = await p.goto(BASE + url, { waitUntil: 'networkidle' });
        const data = await p.evaluate(() => {
            const card = document.querySelector('.sf-card');
            const main = document.querySelector('.sf-main');
            if (! card || ! main) return null;
            const c = getComputedStyle(card);
            const m = getComputedStyle(main);
            const image = getComputedStyle(card.querySelector('.sf-card-image'));
            return { width: window.innerWidth, border: c.borderTopWidth, radius: c.borderTopLeftRadius, imageRadius: image.borderTopLeftRadius, padLeft: m.paddingLeft, padRight: m.paddingRight };
        });
        const status = response ? response.status() : 0;
        await ctx.close();

        return { status, data };
    };

    const cardsDesktop = await computedCards('/', { width: 1400, height: 1000 });
    check('home 1400: the page padding is 6vw (84px), the card border 2px and the corners 12px, the photo frame follows', cardsDesktop.data && near(parseFloat(cardsDesktop.data.padLeft), 0.06 * cardsDesktop.data.width, 1) && cardsDesktop.data.border === '2px' && cardsDesktop.data.radius === '12px' && cardsDesktop.data.imageRadius === '12px', JSON.stringify(cardsDesktop));
    const cardsPhone = await computedCards('/', { width: 390, height: 844 });
    check('home 390: the card border is 1px and the corners 6px (the phone values)', cardsPhone.data && cardsPhone.data.border === '1px' && cardsPhone.data.radius === '6px' && cardsPhone.data.imageRadius === '6px', JSON.stringify(cardsPhone));

    if (category !== '') {
        const cat = await computedCards('/product-category/' + category, { width: 1400, height: 1000 });
        check('a category page: the same card values at 1400', cat.status === 200 && (cat.data === null || (cat.data.border === '2px' && cat.data.radius === '12px')), JSON.stringify(cat));
    }

    // ---- «Продуктова страница» ----
    await open('product');
    check('the product section shows the defaults', (await field('Разстояние между снимките').locator('input').first().inputValue()) === '8' && (await field('Дясната колона остава видима').locator('button[role=switch]').first().getAttribute('aria-checked')) === 'true');
    await shot('03-design-product-defaults');

    await field('Галерия на компютър').locator('button').first().click();
    await page.locator('[role=option]', { hasText: 'Малки снимки отляво' }).first().click();
    await sleep(500);
    await fillNumber('Разстояние между снимките', '12');
    await fillNumber('Заобленост на снимките', '8');
    await fillNumber('Разстояние между галерията', '48');
    await field('Дясната колона остава видима').locator('button[role=switch]').first().click();
    await save();
    await shot('04-design-product-changed');

    const productData = async (viewport) => {
        const ctx = await browser.newContext({ viewport, ignoreHTTPSErrors: true });
        const p = await ctx.newPage();
        p.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        await p.goto(BASE + '/product/' + SLUG, { waitUntil: 'networkidle' });
        await sleep(400);
        const data = await p.evaluate(() => {
            const gal = document.querySelector('[data-sf-gallery]');
            const thumbs = gal.querySelectorAll('.sf-gal-thumb');
            const item = gal.querySelector('.sf-gal-item');
            const gallery = gal.getBoundingClientRect();
            const summary = document.querySelector('.sf-summary');
            const sr = summary.getBoundingClientRect();
            const stage = gal.querySelector('.sf-gal-stage').getBoundingClientRect();
            const thumbsBox = thumbs[0] ? thumbs[0].getBoundingClientRect() : null;
            return {
                desktop: gal.getAttribute('data-gal-desktop'),
                itemRadius: getComputedStyle(item).borderTopLeftRadius,
                thumbGap: thumbs.length > 1 ? Math.round((thumbs[1].getBoundingClientRect().top - thumbs[0].getBoundingClientRect().bottom) * 10) / 10 : null,
                thumbsLeftOfStage: thumbsBox ? thumbsBox.right <= stage.left + 1 : null,
                columnGap: Math.round((sr.left - gallery.right) * 10) / 10,
                summaryPosition: getComputedStyle(summary).position,
                summaryMaxHeight: getComputedStyle(summary).maxHeight,
            };
        });
        await p.screenshot({ path: OUT ? join(OUT, `product-${viewport.width}.png`) : undefined }).catch(() => {});
        OUT && shots.push(join(OUT, `product-${viewport.width}.png`));
        await ctx.close();

        return data;
    };

    const changed = await productData({ width: 1400, height: 1000 });
    check('the product page: the gallery mode is the thumbs-left one, the thumbnails are left of the big photo', changed.desktop === 'thumbs-left' && changed.thumbsLeftOfStage === true, JSON.stringify(changed));
    check('the product page: the photo corners are 8px and the gap between the thumbnails 12px', changed.itemRadius === '8px' && near(changed.thumbGap, 12), JSON.stringify(changed));
    check('the product page: the gap to the summary is 48px and the right column is NOT sticky (static, no max-height)', near(changed.columnGap, 48, 1.5) && changed.summaryPosition === 'static' && changed.summaryMaxHeight === 'none', JSON.stringify(changed));

    // ---- reset: both sections ----
    await open('product');
    await page.getByRole('button', { name: 'Върни по подразбиране' }).first().click();
    await sleep(900);
    if (await page.locator('.fi-modal-window:visible').count() > 0) { await page.locator('.fi-modal-window:visible').last().getByRole('button').filter({ hasNotText: /Отказ|Cancel/ }).last().click(); }
    await settle();
    check('the reset puts the defaults into the panel before anything is stored', (await field('Разстояние между снимките').locator('input').first().inputValue()) === '8' && (await field('Дясната колона остава видима').locator('button[role=switch]').first().getAttribute('aria-checked')) === 'true');
    await save();

    await open('page');
    await page.getByRole('button', { name: 'Върни по подразбиране' }).first().click();
    await sleep(900);
    if (await page.locator('.fi-modal-window:visible').count() > 0) { await page.locator('.fi-modal-window:visible').last().getByRole('button').filter({ hasNotText: /Отказ|Cancel/ }).last().click(); }
    await settle();
    await save();

    const after = await productData({ width: 1400, height: 1000 });
    check('after the reset the product page is the default again (grid, 4px corners, 32px gap, sticky)', after.desktop === 'grid' && after.itemRadius === '4px' && near(after.columnGap, 32, 1.5) && after.summaryPosition === 'sticky', JSON.stringify(after));
    const cardsAfter = await computedCards('/', { width: 1400, height: 1000 });
    check('after the reset the home page is the default again (4vw padding, 1px border, 8px corners)', cardsAfter.data && near(parseFloat(cardsAfter.data.padLeft), 0.04 * cardsAfter.data.width, 1) && cardsAfter.data.border === '1px' && cardsAfter.data.radius === '8px', JSON.stringify(cardsAfter));
    const phoneAfter = await computedCards('/', { width: 390, height: 844 });
    check('after the reset a card on a phone has no border and square corners again', phoneAfter.data && phoneAfter.data.border === '0px' && phoneAfter.data.radius === '0px', JSON.stringify(phoneAfter));

    await admin.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 600));
} finally {
    if (backedUp) {
        try {
            report = JSON.parse(php(RESTORE));
            console.error('restored: ' + JSON.stringify(report));
            check('the settings table is byte-for-byte what it was (a checksum of every row)', report.sum === report.was, JSON.stringify(report));
            check('nothing of the throw-away product, its media, its files and the admin is left', report.products === 0 && report.media === 0 && report.files === 0 && report.staff === 0, JSON.stringify(report));
            check('the appearance/ files are the ones that were there', report.appearanceFiles === report.appearanceWas, JSON.stringify(report));
        } catch (e) {
            check('the restore finished', false, String(e).slice(0, 400));
        }
    }
}

check('no page errors, no 5xx', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
