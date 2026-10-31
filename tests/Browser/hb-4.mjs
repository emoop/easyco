// A REAL-BROWSER run of HB-4: the background picture of «Стил на блока» (a banner: text over a picture, darkened, with a minimum height and a vertical position). NOT part of `php artisan test`: run by
// hand against a dev site whose data may be changed. It creates a throw-away admin («hb4-audit»), a throw-away category («hb4-cat» with three products) and ONE block («HB4 банер») THROUGH THE REAL FORM
// (the title, a heading and a sentence, «Фонова снимка» uploaded into the media library through the picker, «Затъмняване» Средно, «Минимална височина» Средно, «Текст по вертикала» В средата, a light text
// colour, centred), then shows the block in the product page's right column and as the category's «Блок отгоре» (both set directly in the database), and measures the shop. It BACKS UP the
// `site_settings` table, the `appearance/` files and checksums of the block, media and catalog tables first; at the end it removes the block, the uploaded picture (its files too), the category, the
// products and the admin, restores the settings and verifies the checksums. Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/hb-4.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { randomBytes } from 'node:crypto';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const EMAIL = 'hb4-audit@example.test';
const PASSWORD = 'Hb4-' + randomBytes(6).toString('hex');
const TITLE = 'HB4 банер';
const ROOT = resolve(import.meta.dirname, '..', '..');
const PICTURE = join(tmpdir(), 'hb4-banner.png').replace(/\\/g, '/');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node hb-4.mjs');
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

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode(['settings' => $sum('site_settings'), 'blocks' => $sum('html_blocks'), 'media' => $sum('catalog_media'), 'cats' => $sum('catalog_categories'), 'products' => DB::table('catalog_products')->count(), 'variations' => DB::table('catalog_variations')->count(), 'stock' => DB::table('stock_levels')->count(), 'pc' => DB::table('catalog_product_categories')->count(), 'staff' => DB::table('staff')->count()]);`;

const SETUP = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
use EasyCo\Staff\Contracts\{PasswordHasher,RoleRepository,StaffRepository};
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
$files = Storage::disk('public')->allFiles('appearance');
file_put_contents(sys_get_temp_dir().'/hb4_backup.json', json_encode(['rows' => $rows, 'files' => $files, 'contents' => array_combine($files, array_map(fn ($f) => base64_encode(Storage::disk('public')->get($f)), $files))]));
$role = app(RoleRepository::class)->findSystemRoleByName('Administrator');
app(StaffRepository::class)->save(EasyCo\Staff\Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'HB4 Audit', $role));
$im = imagecreatetruecolor(1600, 700);
for ($y = 0; $y < 700; $y++) { imagefilledrectangle($im, 0, $y, 1600, $y, imagecolorallocate($im, 20 + (int) ($y / 14), 60 + (int) ($y / 10), 140 + (int) ($y / 8))); }
$stripe = imagecolorallocate($im, 255, 240, 190);
for ($x = -700; $x < 1600; $x += 90) { imagesetthickness($im, 26); imageline($im, $x, 700, $x + 700, 0, $stripe); }
imagepng($im, '${PICTURE}');
$now = now();
$cat = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'HB4 категория', 'slug' => 'hb4-cat', 'created_at' => $now, 'updated_at' => $now]);
for ($i = 1; $i <= 3; $i++) {
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'simple', 'name' => "HB4 продукт $i", 'slug' => "hb4-$i", 'base_sku' => "HB4-$i", 'status' => 'active', 'catalog_visibility' => 'visible', 'is_featured' => 0, 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => now()->addDays(3)->subMinutes($i)]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'universal', 'status' => 'active', 'attribute_signature' => hash('sha256', "hb4-$i"), 'sku' => "HB4-V$i", 'is_visible' => 0, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 5, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
}
echo json_encode(['cat' => $cat]);`;

const READY = String.raw`
use Illuminate\Support\Facades\DB;
$block = DB::table('html_blocks')->where('title', '${TITLE}')->first();
$style = $block ? json_decode($block->style, true) : null;
$media = $style && isset($style['bg_image']) ? DB::table('catalog_media')->where('id', $style['bg_image'])->first() : null;
echo json_encode(['block' => $block ? (int) $block->id : null, 'style' => $style, 'media' => $media ? ['id' => (int) $media->id, 'status' => $media->processing_status] : null]);`;

// the block in the right column of the product page and as the category's banner
const PLACE = (blockId, catId) => String.raw`
use Illuminate\Support\Facades\DB; use App\Storefront\ProductSummary\ProductSummaryCompiler; use App\Storefront\ProductSummary\ProductSummaryLayout; use App\Settings\Contracts\SiteSettingsRepository;
$layout = ProductSummaryLayout::default();
$layout['components'] = [['type' => 'title', 'settings' => []], ['type' => 'html_block', 'settings' => ['html_block' => ${blockId}]], ['type' => 'price', 'settings' => []], ['type' => 'purchase', 'settings' => []]];
app(ProductSummaryCompiler::class)->publish($layout, app(SiteSettingsRepository::class));
DB::table('catalog_categories')->where('id', ${catId})->update(['header_block_id' => ${blockId}]);
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo 'placed';`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/hb4_backup.json'), true);
DB::table('catalog_categories')->where('slug', 'hb4-cat')->update(['header_block_id' => null]);
$block = DB::table('html_blocks')->where('title', '${TITLE}')->first();
$style = $block ? json_decode($block->style, true) : null;
$mid = $style['bg_image'] ?? null;
DB::table('site_settings')->delete();
foreach ($bak['rows'] as $row) { DB::table('site_settings')->insert($row); }
foreach (Storage::disk('public')->allFiles('appearance') as $f) { if (! in_array($f, $bak['files'], true)) { Storage::disk('public')->delete($f); } }
foreach ($bak['contents'] as $f => $content) { Storage::disk('public')->put($f, base64_decode($content)); }
DB::table('html_blocks')->where('title', '${TITLE}')->delete();
if ($mid) { $m = DB::table('catalog_media')->where('id', $mid)->first(); if ($m) { foreach (array_merge([$m->path], array_map(fn ($v) => $v['path'] ?? null, (array) json_decode($m->variants, true))) as $path) { $path && Storage::disk($m->disk)->delete($path); } DB::table('catalog_media')->where('id', $mid)->delete(); } }
$pids = DB::table('catalog_products')->where('slug', 'like', 'hb4-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
DB::table('catalog_categories')->where('slug', 'hb4-cat')->delete();
DB::table('staff')->where('email', '${EMAIL}')->delete();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
@unlink('${PICTURE}');
echo 'cleaned';`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const consoleErrors = [];
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 600) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const near = (a, b, tolerance = 2) => Math.abs(a - b) <= tolerance;

let before = null;
let setupDone = false;

try {
    before = JSON.parse(php(SUMS));
    console.error('before: ' + JSON.stringify(before));
    const { cat } = JSON.parse(php(SETUP));
    setupDone = true;

    // ============================================================ the real form
    const admin = await browser.newContext({ viewport: { width: 1500, height: 1200 }, ignoreHTTPSErrors: true });
    const page = await admin.newPage();
    page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.stack || e).replace(/\s+/g, ' ').slice(0, 300)));
    page.on('response', (r) => { if (r.status() >= 500) consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 160)); });
    const settle = async () => { await page.waitForLoadState('networkidle'); await sleep(900); };
    const shotPage = async (name, locator = null) => { if (! OUT) return; const path = join(OUT, name + '.png'); shots.push(path); await (locator ?? page).screenshot({ path, ...(locator ? {} : { fullPage: true }) }); };
    const modal = () => page.locator('.fi-modal-window:visible').last();

    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    await page.goto(BASE + '/admin/html-blocks/create', { waitUntil: 'networkidle' });
    await page.fill('input[id="form.title"]', TITLE);
    await page.locator('.tiptap').first().click();
    await page.keyboard.type('Пролетна разпродажба');
    await page.keyboard.press('Enter');
    await page.keyboard.type('До 30% на избрани артикули до края на месеца.');
    await sleep(700);
    // the heading: the first line becomes a heading with the editor's own toolbar if there is one; the block's own look is what is measured, so a plain paragraph pair is fine

    const section = page.locator('.fi-section', { hasText: 'Стил на блока' }).first();
    // open the section when it is collapsed
    if (await section.locator('[id="form.style.bg"]').count() === 0 || ! (await section.locator('[id="form.style.bg"]').first().isVisible())) {
        await section.locator('.fi-section-header').first().click();
        await sleep(600);
    }

    check('before a picture is chosen the dependent choices are not shown (only «Фонова снимка»)', (await section.getByText('Фонова снимка').count()) > 0 && (await section.getByText('Затъмняване', { exact: true }).count()) === 0 && (await section.getByText('Минимална височина', { exact: true }).count()) === 0);

    // upload the picture into the library through the picker
    await section.getByRole('button', { name: 'Добави изображение', exact: true }).click();
    await sleep(900);
    await modal().getByRole('tab', { name: 'Качи файлове' }).click();
    await sleep(500);
    await modal().locator('input[type=file]').setInputFiles([PICTURE]);
    await modal().locator('.mp-card-on').first().waitFor({ timeout: 40000 });
    await modal().getByRole('button', { name: 'Избери', exact: true }).click();
    await sleep(1500);
    check('the field shows the chosen picture and the dependent choices appear', (await section.locator('.mpf').count()) >= 1 && (await section.getByText('Затъмняване', { exact: true }).count()) > 0 && (await section.getByText('Минимална височина', { exact: true }).count()) > 0 && (await section.getByText('Текст по вертикала', { exact: true }).count()) > 0);
    check('the overlay hint tells to set a light text colour', (await section.innerText()).includes('Светъл текст върху тъмна снимка: задайте цвят на текста.'));

    const choose = async (label, optionText) => {
        await section.locator('.fi-fo-field', { has: page.locator('label', { hasText: label }) }).first().locator('button, [role=combobox]').first().click();
        await sleep(400);
        await page.locator('[role=option]:visible').filter({ hasText: new RegExp('^\\s*' + optionText + '\\s*$') }).first().click();
        await sleep(700);
    };
    const setText = async (key, value) => { const f = page.locator(`[id="form.style.${key}"]`).first(); await f.fill(value); await f.blur(); await sleep(700); };
    await choose('Затъмняване', 'Средно');
    await choose('Минимална височина', 'Средно');
    await choose('Текст по вертикала', 'В средата');
    await setText('color', '#ffffff');
    await setText('pad_v', '24');
    await setText('pad_h', '32');
    await choose('Подравняване', 'Центрирано');
    await choose('Ширина', 'Цяла ширина');
    await shotPage('hb4-01-admin-form-style', section);

    await page.locator('button[type=submit]').filter({ hasText: /Създай|Create/ }).first().click();
    await page.waitForURL(/\/html-blocks\/\d+\/edit/, { timeout: 30000 });
    await settle();
    const info = JSON.parse(php(READY));
    check('the block is saved with the picture and the choices (only non-defaults)', info.block && info.style && Number.isInteger(info.style.bg_image) && info.style.overlay === 40 && info.style.min_height === 240 && info.style.valign === 'center' && info.style.color === '#ffffff' && info.style.bg_size === undefined && info.style.bg_position === undefined, JSON.stringify(info.style));

    // wait for the library to process the picture (the queue worker), then save again so the stylesheet holds the WebP rendition
    for (let i = 0; i < 40 && JSON.parse(php(READY)).media?.status !== 'ready'; i++) { await sleep(2000); }
    const ready = JSON.parse(php(READY));
    check('the library processed the picture (ready)', ready.media && ready.media.status === 'ready', JSON.stringify(ready.media));
    await page.reload({ waitUntil: 'networkidle' });
    await page.locator('button[type=submit]').filter({ hasText: /Запази|Save/ }).first().click();
    await settle();
    check('after reopening, the form shows the picture and the choices', (await page.locator('.fi-section', { hasText: 'Стил на блока' }).first().innerText()).includes('Средно'));
    php(PLACE(info.block, cat));
    await sleep(1200);

    // ============================================================ the shop, measured
    const measure = async (viewport, path, selector, shot) => {
        const ctx = await browser.newContext({ viewport, ignoreHTTPSErrors: true, ...(viewport.width < 600 ? { hasTouch: true, isMobile: true } : {}) });
        const p = await ctx.newPage();
        p.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        const response = await p.goto(BASE + path, { waitUntil: 'networkidle' });
        await sleep(600);
        const data = await p.evaluate((sel) => {
            const el = document.querySelector(sel);
            if (! el) return null;
            const cs = getComputedStyle(el);
            const r = el.getBoundingClientRect();
            const kids = [...el.children].filter((c) => c.getBoundingClientRect().height > 0);
            const first = kids[0].getBoundingClientRect();
            const last = kids[kids.length - 1].getBoundingClientRect();
            const parent = el.parentElement.getBoundingClientRect();
            const padTop = parseFloat(cs.paddingTop);
            const padBottom = parseFloat(cs.paddingBottom);

            return {
                backgroundImage: cs.backgroundImage, minHeight: cs.minHeight, display: cs.display, justify: cs.justifyContent, textAlign: cs.textAlign,
                height: Math.round(r.height), width: Math.round(r.width), color: cs.color,
                gapTop: Math.round((first.top - r.top - padTop) * 10) / 10, gapBottom: Math.round((r.bottom - last.bottom - padBottom) * 10) / 10,
                leftGap: Math.round((r.left - parent.left) * 10) / 10, rightGap: Math.round((parent.right - r.right) * 10) / 10,
                textLeft: Math.round((first.left - r.left) * 10) / 10, textRight: Math.round((r.right - first.right) * 10) / 10,
                overflowX: document.documentElement.scrollWidth > window.innerWidth + 1,
                styleAttr: el.hasAttribute('style'), inlineStyles: document.querySelectorAll('.sf-hb [style], .sf-hb[style]').length,
            };
        }, selector);
        const html = await p.content();
        shot && OUT && (shots.push(join(OUT, shot + '.png')), await p.screenshot({ path: join(OUT, shot + '.png'), fullPage: true }));
        await ctx.close();

        return { status: response.status(), data, bannerStyleInHtml: /<div class="sf-hb[^"]*"[^>]*\sstyle=/.test(html) };
    };

    const blockClass = `.sf-hb-${info.block}`;
    const product = await measure({ width: 1400, height: 1000 }, '/product/hb4-1', blockClass, 'hb4-02-product-desktop');
    const d = product.data;
    check('product page 1400: the block has the library picture and the veil in its background (computed)', product.status === 200 && d && /linear-gradient\(rgba\(0, 0, 0, 0\.4\), rgba\(0, 0, 0, 0\.4\)\), url\("?https?:\/\/[^")]+\/storage\/[^")]+\.webp"?\)/.test(d.backgroundImage), d?.backgroundImage);
    check('the minimum height is 240px and the box is at least that tall; the content is a flex column centred in it', d && d.minHeight === '240px' && d.height >= 240 && d.display === 'flex' && d.justify === 'center', JSON.stringify(d));
    check('the content is vertically centred: equal gaps above and below (within 2px)', d && near(d.gapTop, d.gapBottom, 2), `${d?.gapTop} / ${d?.gapBottom}`);
    check('HB-3.2 still holds: the text is centred horizontally inside the box', d && d.textAlign === 'center' && near(d.textLeft, d.textRight, 2), JSON.stringify({ l: d?.textLeft, r: d?.textRight }));
    check('the text colour the merchant chose is used (white)', d && d.color === 'rgb(255, 255, 255)', d?.color);
    check('no style attribute on the served block and no horizontal scroll', ! product.bannerStyleInHtml && d && ! d.styleAttr && d.inlineStyles === 0 && ! d.overflowX, JSON.stringify({ attr: product.bannerStyleInHtml, inline: d?.inlineStyles }));

    const productPhone = await measure({ width: 390, height: 844 }, '/product/hb4-1', blockClass, 'hb4-03-product-phone');
    const dp = productPhone.data;
    check('product page 390: the picture, the height and the centring hold; nothing overflows', dp && /url\(/.test(dp.backgroundImage) && dp.minHeight === '240px' && near(dp.gapTop, dp.gapBottom, 2) && ! dp.overflowX, JSON.stringify(dp));

    const category = await measure({ width: 1400, height: 1000 }, '/product-category/hb4-cat', '.sf-cat-banner ' + blockClass, 'hb4-04-category-desktop');
    check('category page 1400: the banner is shown above the list with the picture, the height and the centring', category.status === 200 && category.data && /url\(/.test(category.data.backgroundImage) && category.data.minHeight === '240px' && near(category.data.gapTop, category.data.gapBottom, 2), JSON.stringify(category.data));
    const categoryPhone = await measure({ width: 390, height: 844 }, '/product-category/hb4-cat', '.sf-cat-banner ' + blockClass, 'hb4-05-category-phone');
    check('category page 390: the banner holds and nothing overflows', categoryPhone.data && /url\(/.test(categoryPhone.data.backgroundImage) && ! categoryPhone.data.overflowX && near(categoryPhone.data.gapTop, categoryPhone.data.gapBottom, 2), JSON.stringify(categoryPhone.data));

    await admin.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (setupDone) {
        try {
            php(CLEANUP);
            const after = JSON.parse(php(SUMS));
            check('everything created is gone: the settings, the blocks, the media and the catalog tables are as they were (checksums / counts)', before && JSON.stringify(before) === JSON.stringify(after), JSON.stringify({ before, after }));
        } catch (e) {
            check('the cleanup finished', false, String(e).slice(0, 400));
        }
    }
}

check('no page errors, no 5xx', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
