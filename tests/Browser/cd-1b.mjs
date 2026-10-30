// A REAL-BROWSER run of CD-1b: the category page's layout — the filters in a bar above the list / a side panel (left, right) / a phone drawer, the sort, the count and the «Блок отгоре» banner, with
// JavaScript and without it. NOT part of `php artisan test`: run by hand against a dev site whose data may be changed. It creates a throw-away admin («cd1b-audit»), a throw-away category («cd1b-cat») with
// 60 products (colour × size variations, some out of stock), three filterable attributes (Colour = circles, Size = text links, Material = a list), ONE HTML block used as the category's banner, and BACKS UP
// the `site_settings` table and the `appearance/` files and a checksum of the catalog tables first; at the end it restores them, removes everything it created and verifies the checksums.
// Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/cd-1b.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { randomBytes } from 'node:crypto';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const EMAIL = 'cd1b-audit@example.test';
const PASSWORD = 'Cd1b-' + randomBytes(6).toString('hex');
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node cd-1b.mjs');
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

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
$files = Storage::disk('public')->allFiles('appearance');
file_put_contents(sys_get_temp_dir().'/cd1b_backup.json', json_encode(['rows' => $rows, 'files' => $files, 'contents' => array_combine($files, array_map(fn ($f) => base64_encode(Storage::disk('public')->get($f)), $files)), 'sum' => md5(json_encode($rows))]));
echo json_encode(['rows' => count($rows), 'sum' => md5(json_encode($rows))]);`;

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode(['settings' => $sum('site_settings'), 'defs' => $sum('catalog_attribute_definitions'), 'values' => $sum('catalog_attribute_values'), 'cats' => $sum('catalog_categories'), 'blocks' => $sum('html_blocks'), 'products' => DB::table('catalog_products')->count(), 'variations' => DB::table('catalog_variations')->count(), 'stock' => DB::table('stock_levels')->count(), 'pa' => DB::table('catalog_product_attributes')->count(), 'vv' => DB::table('catalog_variation_attribute_values')->count(), 'pc' => DB::table('catalog_product_categories')->count(), 'staff' => DB::table('staff')->count()]);`;

const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
use EasyCo\Staff\Contracts\{PasswordHasher,RoleRepository,StaffRepository};
$role = app(RoleRepository::class)->findSystemRoleByName('Administrator');
app(StaffRepository::class)->save(EasyCo\Staff\Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'CD1B Audit', $role));
$now = now();
$block = DB::table('html_blocks')->insertGetId(['title' => 'CD1B банер', 'body' => '<h2>Пролетна разпродажба</h2><p>До 30% на избрани артикули.</p>', 'created_at' => $now, 'updated_at' => $now]);
$cat = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'CD1B категория', 'slug' => 'cd1b-cat', 'header_block_id' => $block, 'created_at' => $now, 'updated_at' => $now]);
$def = function ($code, $name, $display, $values) use ($now) {
  $id = DB::table('catalog_attribute_definitions')->insertGetId(['code' => $code, 'name' => $name, 'type' => 'select', 'is_filterable' => 1, 'filter_display' => $display, 'created_at' => $now, 'updated_at' => $now]);
  $ids = [];
  foreach ($values as $i => [$label, $colour]) { $ids[$label] = DB::table('catalog_attribute_values')->insertGetId(['attribute_definition_id' => $id, 'value' => $label, 'sort_order' => $i, 'colour' => $colour, 'created_at' => $now, 'updated_at' => $now]); }
  return [$id, $ids];
};
[$colour, $c] = $def('cd1b-colour', 'Цвят', 'swatches', [['Червен', '#cc0000'], ['Син', '#0000cc'], ['Зелен', '#00aa44']]);
[$size, $s] = $def('cd1b-size', 'Размер', 'links', [['S', null], ['M', null], ['L', null]]);
[$material, $m] = $def('cd1b-material', 'Материя', 'list', [['Памук', null], ['Вълна', null]]);
$colours = ['Червен', 'Син', 'Зелен']; $sizes = ['S', 'M', 'L'];
for ($i = 1; $i <= 60; $i++) {
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'variable', 'name' => "CD1B продукт $i", 'slug' => "cd1b-$i", 'base_sku' => "CD1B-$i", 'status' => 'active', 'catalog_visibility' => 'visible', 'is_featured' => 0, 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => now()->addDays(3)->subMinutes($i)]);
  foreach ([[$colours[$i % 3], $sizes[$i % 3]], [$colours[($i + 1) % 3], $sizes[($i + 2) % 3]]] as $k => [$cv, $sv]) {
    $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => $k, 'type' => 'standard', 'status' => 'active', 'attribute_signature' => hash('sha256', "cd1b-$i-$k"), 'sku' => "CD1B-V$i-$k", 'is_visible' => 1, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('catalog_variation_attribute_values')->insert([['variation_id' => $vid, 'attribute_definition_id' => $colour, 'attribute_value_id' => $c[$cv], 'created_at' => $now, 'updated_at' => $now], ['variation_id' => $vid, 'attribute_definition_id' => $size, 'attribute_value_id' => $s[$sv], 'created_at' => $now, 'updated_at' => $now]]);
    ($i + $k) % 7 !== 0 && DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 3, 'created_at' => $now, 'updated_at' => $now]);
  }
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  $i % 2 === 0 && DB::table('catalog_product_attributes')->insert(['product_id' => $pid, 'attribute_definition_id' => $material, 'is_variation_axis' => 0, 'attribute_value_id' => $m[$i % 4 === 0 ? 'Памук' : 'Вълна'], 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now]);
}
app(App\Storefront\Filters\FilterCatalog::class)::forget();
echo json_encode(['cat' => $cat, 'block' => $block, 'colour' => $colour, 'size' => $size, 'material' => $material, 'c' => $c, 's' => $s, 'm' => $m]);`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/cd1b_backup.json'), true);
DB::table('site_settings')->delete();
foreach ($bak['rows'] as $row) { DB::table('site_settings')->insert($row); }
foreach (Storage::disk('public')->allFiles('appearance') as $f) { if (! in_array($f, $bak['files'], true)) { Storage::disk('public')->delete($f); } }
foreach ($bak['contents'] as $f => $content) { Storage::disk('public')->put($f, base64_decode($content)); }
$pids = DB::table('catalog_products')->where('slug', 'like', 'cd1b-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_attributes')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
$defs = DB::table('catalog_attribute_definitions')->where('code', 'like', 'cd1b-%')->pluck('id');
DB::table('catalog_attribute_values')->whereIn('attribute_definition_id', $defs)->delete();
DB::table('catalog_attribute_definitions')->whereIn('id', $defs)->delete();
DB::table('catalog_categories')->where('slug', 'cd1b-cat')->delete();
DB::table('html_blocks')->where('title', 'CD1B банер')->delete();
DB::table('staff')->where('email', '${EMAIL}')->delete();
app(App\Storefront\Filters\FilterCatalog::class)::forget();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo 'restored';`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const consoleErrors = [];
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 500) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let before = null;
let ids = null;
let backedUp = false;

try {
    before = JSON.parse(php(SUMS));
    console.error('backup: ' + php(BACKUP));
    backedUp = true;
    ids = JSON.parse(php(SETUP));
    const URL = '/product-category/cd1b-cat';
    const RED = ids.c['Червен'];
    const BLUE = ids.c['Син'];
    const M = ids.s['M'];
    const qs = (page) => decodeURIComponent(new globalThis.URL(page.url()).search);

    // ---- a page of the shop, with or without JavaScript ----
    const openShop = async (viewport, path, { js = true, shot = null } = {}) => {
        const ctx = await browser.newContext({ viewport, ignoreHTTPSErrors: true, javaScriptEnabled: js, ...(viewport.width < 600 ? { hasTouch: true, isMobile: true } : {}) });
        const page = await ctx.newPage();
        page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        const response = await page.goto(BASE + path, { waitUntil: 'networkidle' });
        const take = async (name) => { if (OUT) { const path = join(OUT, name + '.png'); shots.push(path); await page.screenshot({ path, fullPage: true }); } };
        shot && await take(shot);

        return { ctx, page, status: response.status(), take };
    };
    const names = (page) => page.$$eval('.sf-grid .sf-card-name', (els) => els.map((e) => e.textContent.trim()));
    const box = (page, selector) => page.locator(selector).first().boundingBox();
    const visible = (page, selector) => page.locator(selector).first().isVisible();
    const colsOf = (page) => page.$eval('.sf-grid', (g) => getComputedStyle(g).gridTemplateColumns.split(' ').length);

    // ============================================================ the admin: «Каталог»
    const admin = await browser.newContext({ viewport: { width: 1500, height: 1100 }, ignoreHTTPSErrors: true });
    const apage = await admin.newPage();
    apage.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.stack || e).replace(/\s+/g, ' ').slice(0, 300)));
    apage.on('response', (r) => { if (r.status() >= 500) consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 160)); });
    const settle = async () => { await apage.waitForLoadState('networkidle'); await sleep(900); };
    await apage.goto(BASE + '/admin/login');
    await apage.fill('input[type=email]', EMAIL);
    await apage.fill('input[type=password]', PASSWORD);
    await apage.click('button[type=submit]');
    await apage.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
    const field = (label) => apage.locator('.ds-props .fi-fo-field', { has: apage.locator('label', { hasText: label }) }).first();
    const choose = async (label, optionText) => {
        await field(label).locator('button').first().click();
        await apage.locator('[role=option]:visible').filter({ hasText: new RegExp('^\\s*' + optionText + '\\s*$') }).first().click();
        await sleep(500);
    };
    const confirmModal = async () => { if (await apage.locator('.fi-modal-window:visible').count() > 0) { await apage.locator('.fi-modal-window:visible').last().getByRole('button').filter({ hasNotText: /Отказ|Cancel/ }).last().click(); } };
    const applyAndPublish = async () => {
        await apage.locator('.ds-props button[type=submit]').click();
        await settle();
        const publish = apage.locator('.fi-header-actions-ctn .fi-btn', { hasText: 'Публикувай' }).first();
        await publish.click();
        await sleep(700);
        await confirmModal();
        await settle();
    };
    const openCatalog = async () => { await apage.goto(BASE + '/admin/design?zone=catalog'); await settle(); };
    const resetCatalog = async () => { await apage.getByRole('button', { name: 'Начални настройки' }).first().click(); await sleep(900); await confirmModal(); await settle(); };

    await openCatalog();
    await resetCatalog();
    await applyAndPublish().catch(() => {});
    await openCatalog();
    check('the zone has «Разположение» (full width by default) and hides «Страничен панел» until the panel layout is chosen', (await field('Разположение').innerText()).includes('Цяла ширина') && (await apage.locator('.ds-props label', { hasText: 'Страничен панел' }).count()) === 0);
    await choose('Разположение', 'Със страничен панел');
    check('choosing «Със страничен панел» shows «Страничен панел» (left by default)', (await field('Страничен панел').innerText()).includes('Отляво'));
    await choose('Разположение', 'Цяла ширина');
    check('back to «Цяла ширина» hides the side again', (await apage.locator('.ds-props label', { hasText: 'Страничен панел' }).count()) === 0);

    // ============================================================ desktop 1400, JavaScript on, «Цяла ширина»
    const D = { width: 1400, height: 1000 };
    let { ctx, page, status, take } = await openShop(D, URL, { shot: 'cd1b-01-bar-closed' });
    check('desktop: 200; the section carries sf-cat-layout-full; ONE form; the banner is above the filters; the heading is hidden but printed', status === 200 && (await page.locator('.sf-cat-layout-full').count()) === 1 && (await page.locator('form.sf-filters-form').count()) === 1 && (await page.locator('.sf-cat-banner').count()) === 1 && (await page.locator('h1.sf-sr').count()) === 1);
    const bannerBox = await box(page, '.sf-cat-banner');
    const barBox = await box(page, '.sf-filters-form');
    check('desktop: the bar is a row above the list and below the banner; no drawer trigger, no radios', bannerBox.y + bannerBox.height <= barBox.y + 1 && ! (await visible(page, '.sf-ftrigger')) && ! (await visible(page, '.sf-fsort')), JSON.stringify({ bannerBox, barBox }));
    check('desktop: the groups are dropdowns, all closed, and «Филтрирай» / «Изчисти» are at the end of the bar', (await page.locator('.sf-fgroup[open]').count()) === 0 && (await visible(page, '.sf-fdrawer-foot .sf-fbtn-primary')) && (await visible(page, '.sf-fdrawer-foot .sf-fbtn-secondary')));
    const count0 = (await page.locator('.sf-count').innerText()).trim();
    check('desktop: the count is on the left of the toolbar and the sort on the right', /^\d+ /.test(count0) && (await box(page, '.sf-count')).x < (await box(page, '.sf-sortdd')).x, count0);

    // open the colour dropdown, close with Escape, open again, close with an outside click
    await page.locator('.sf-fgroup-summary', { hasText: 'Цвят' }).click();
    check('desktop: a click on «Цвят» opens a dropdown panel under it (absolutely positioned, above the list)', (await page.locator('.sf-fgroup[open] .sf-fgroup-panel').first().isVisible()) && (await page.$eval('.sf-fgroup[open] .sf-fgroup-panel', (el) => getComputedStyle(el).position)) === 'absolute');
    await take('cd1b-02-bar-dropdown-open');
    await page.keyboard.press('Escape');
    check('desktop: Escape closes the dropdown', (await page.locator('.sf-fgroup[open]').count()) === 0);
    await page.locator('.sf-fgroup-summary', { hasText: 'Размер' }).click();
    await page.locator('.sf-count').click();
    check('desktop: an outside click closes the dropdown', (await page.locator('.sf-fgroup[open]').count()) === 0);

    // choose two values: each applies at once (auto-submit)
    await page.locator('.sf-fgroup-summary', { hasText: 'Цвят' }).click();
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('.sf-fgroup[open] label.sf-fv-dot[title="Червен"]').click()]);
    check('desktop: a choice applies at once: the canonical URL (301 from the array form), the banner is gone, the heading is visible', qs(page).includes(`filter[${ids.colour}]=${RED}`) && ! qs(page).includes('[]') && (await page.locator('.sf-cat-banner').count()) === 0 && (await page.locator('h1.sf-sr').count()) === 0, page.url());
    const redCount = (await page.locator('.sf-count').innerText()).trim();
    await page.locator('.sf-fgroup-summary', { hasText: 'Размер' }).click();
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('.sf-fgroup[open] label.sf-fv-link', { hasText: /^\s*M\s*$/ }).click()]);
    check('desktop: the second choice keeps the first; the bar shows the counts «Цвят (1)» and «Размер (1)» and the chips', qs(page).includes(`filter[${ids.colour}]=${RED}`) && qs(page).includes(`filter[${ids.size}]=${M}`) && (await page.locator('.sf-fgroup-count').count()) === 2 && (await page.locator('.sf-filter-chip').count()) === 2, qs(page));
    await take('cd1b-03-bar-two-choices');
    const redM = await names(page);
    check('desktop: red + M gives fewer products than red', redM.length > 0 && parseInt((await page.locator('.sf-count').innerText()), 10) <= parseInt(redCount, 10), JSON.stringify({ redCount, now: await page.locator('.sf-count').innerText() }));

    // the sort dropdown (plain links) keeps the filters
    await page.locator('.sf-sortdd-summary').click();
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('.sf-sortdd-link', { hasText: 'Име' }).click()]);
    check('desktop: the sort option is a link that keeps the filters and sets sort=name_asc', qs(page).includes('sort=name_asc') && qs(page).includes(`filter[${ids.colour}]=${RED}`), qs(page));
    await page.locator('.sf-sortdd-summary').click();
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('.sf-sortdd-link', { hasText: 'Най-нови' }).click()]);
    check('desktop: «Най-нови» (the default) drops sort from the URL', ! qs(page).includes('sort='), qs(page));

    // «Изчисти»
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('.sf-fdrawer-foot .sf-fbtn-secondary').click()]);
    check('desktop: «Изчисти» is the plain category URL and the banner is back', page.url().endsWith(URL) && (await page.locator('.sf-cat-banner').count()) === 1, page.url());
    await ctx.close();

    // the banner is on page 1 only
    ({ ctx, page } = await openShop(D, URL + '?per_page=12&page=2'));
    check('the banner is NOT on page 2 and the heading is visible there', (await page.locator('.sf-cat-banner').count()) === 0 && (await page.locator('h1.sf-sr').count()) === 0);
    await ctx.close();

    // ============================================================ the side panel, left then right
    for (const [side, label] of [['left', 'Отляво'], ['right', 'Отдясно']]) {
        await openCatalog();
        await choose('Разположение', 'Със страничен панел');
        await choose('Страничен панел', label);
        await applyAndPublish();
        check(`the schematic shows the ${side} panel after «Приложи»`, (await apage.locator('.ds-catalog-sketch').innerText()).includes(label), (await apage.locator('.ds-catalog-sketch').innerText()).slice(0, 120));

        ({ ctx, page, take } = await openShop(D, URL, { shot: `cd1b-04-panel-${side}` }));
        const panel = await box(page, '.sf-fdrawer');
        const main = await box(page, '.sf-cat-main');
        check(`desktop panel ${side}: sf-cat-layout-sidebar sf-cat-side-${side}; the panel (~17rem = 272px) is on the ${side} of the list`, (await page.locator(`.sf-cat-layout-sidebar.sf-cat-side-${side}`).count()) === 1 && Math.abs(panel.width - 272) <= 2 && (side === 'left' ? panel.x + panel.width <= main.x + 1 : main.x + main.width <= panel.x + 1), JSON.stringify({ panel, main }));
        check(`desktop panel ${side}: the DOM order is still the filters first`, await page.evaluate(() => { const f = document.querySelector('.sf-fdrawer'); const m = document.querySelector('.sf-cat-main'); return !! (f.compareDocumentPosition(m) & Node.DOCUMENT_POSITION_FOLLOWING); }));
        check(`desktop panel ${side}: every group is open, «Филтрирай» / «Изчисти» at the bottom of the panel, the grid has columns`, (await page.locator('.sf-fgroup[open]').count()) === 3 && (await visible(page, '.sf-fdrawer-foot .sf-fbtn-primary')) && (await colsOf(page)) >= 3, String(await colsOf(page)));

        // check two boxes and press «Филтрирай» (a computer applies at once; the button is still there)
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('label.sf-fv-dot[title="Син"]').click()]);
        check(`desktop panel ${side}: a choice applies; the URL is canonical`, qs(page).includes(`filter[${ids.colour}]=${BLUE}`) && ! qs(page).includes('[]'), qs(page));
        await take(`cd1b-05-panel-${side}-chosen`);
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('.sf-fdrawer-foot .sf-fbtn-primary').click()]);
        check(`desktop panel ${side}: «Филтрирай» submits the form and keeps the choice`, qs(page).includes(`filter[${ids.colour}]=${BLUE}`), qs(page));
        await ctx.close();
    }

    // back to the full width for the phone and no-JS runs
    await openCatalog();
    await resetCatalog();
    await applyAndPublish().catch(() => {});

    // ============================================================ phone 390, JavaScript on
    const P = { width: 390, height: 844 };
    ({ ctx, page, take } = await openShop(P, URL, { shot: 'cd1b-06-phone-closed' }));
    check('phone: no bar and no panel (the form is in a closed drawer), the trigger shows the icon, the words and an arrow; the count is on the right', ! (await visible(page, '.sf-fdrawer-panel')) && (await visible(page, '.sf-ftrigger')) && (await page.locator('.sf-ftrigger-icon').count()) === 1 && (await page.locator('.sf-ftrigger-arrow').count()) === 1 && (await page.locator('.sf-ftrigger').innerText()).includes('Филтриране и сортиране') && (await box(page, '.sf-count')).x > (await box(page, '.sf-ftrigger')).x);
    check('phone: the banner is above the toolbar; no horizontal scroll', (await page.locator('.sf-cat-banner').count()) === 1 && ! (await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)));
    await page.locator('.sf-ftrigger').click();
    await sleep(400);
    const drawer = await box(page, '.sf-fdrawer-panel');
    check('phone: the trigger opens the drawer from the right side (a dialog, the page locked behind it, the focus inside)', (await page.locator('.sf-fdrawer-open').count()) === 1 && drawer.x + drawer.width >= 388 && drawer.width <= 360 && (await page.getAttribute('.sf-fdrawer-panel', 'role')) === 'dialog' && (await page.evaluate(() => document.documentElement.classList.contains('sf-locked'))) && (await page.evaluate(() => document.querySelector('.sf-fdrawer-panel').contains(document.activeElement))), JSON.stringify(drawer));
    check('phone: the drawer has the title, the close ×, the sort radios, the three filter groups and the two buttons at the bottom', (await page.locator('.sf-fdrawer-title').innerText()).includes('Филтриране и сортиране') && (await visible(page, '.sf-fdrawer-close')) && (await page.locator('.sf-fsort input[type=radio]').count()) === 2 && (await page.locator('.sf-fgroup').count()) === 3 && (await visible(page, '.sf-fdrawer-foot .sf-fbtn-primary')) && (await visible(page, '.sf-fdrawer-foot .sf-fbtn-secondary')));
    await take('cd1b-07-phone-drawer-open');
    // × closes without a change
    await page.locator('.sf-fdrawer-close').click();
    await sleep(400);
    check('phone: × closes the drawer and the page is unchanged', (await page.locator('.sf-fdrawer-open').count()) === 0 && page.url().endsWith(URL) && (await page.evaluate(() => document.activeElement && document.activeElement.classList.contains('sf-ftrigger'))));
    await page.locator('.sf-ftrigger').click();
    await sleep(300);
    await page.keyboard.press('Escape');
    await sleep(300);
    check('phone: Escape closes it too', (await page.locator('.sf-fdrawer-open').count()) === 0);
    // choose in the drawer: nothing applies until «Филтрирай»
    await page.locator('.sf-ftrigger').click();
    await sleep(300);
    await page.locator('.sf-fgroup-summary', { hasText: 'Цвят' }).click();
    await page.locator('.sf-fgroup label.sf-fv-dot[title="Червен"]').click();
    await page.locator('.sf-fsort label', { hasText: 'Име' }).click();
    await sleep(300);
    check('phone: a choice in the drawer does not apply by itself', page.url().endsWith(URL));
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('.sf-fdrawer-foot .sf-fbtn-primary').click()]);
    check('phone: «Филтрирай» applies the filter and the sort (the canonical URL)', qs(page).includes(`filter[${ids.colour}]=${RED}`) && qs(page).includes('sort=name_asc') && ! qs(page).includes('[]'), qs(page));
    check('phone: the trigger shows the number of groups with a choice: (1)', (await page.locator('.sf-ftrigger-count').innerText()).includes('(1)'));
    await take('cd1b-08-phone-filtered');
    await page.locator('.sf-ftrigger').click();
    await sleep(300);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('.sf-fdrawer-foot .sf-fbtn-secondary').click()]);
    check('phone: «Изчисти» goes to the plain category URL', page.url().endsWith(URL), page.url());
    // the default sort option sends an empty sort: a 301 to the URL without it
    await page.locator('.sf-ftrigger').click();
    await sleep(300);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('.sf-fdrawer-foot .sf-fbtn-primary').click()]);
    check('phone: an empty submission (nothing chosen, the default sort) is the plain URL', page.url().endsWith(URL), page.url());
    await ctx.close();

    // ============================================================ no JavaScript
    ({ ctx, page, take } = await openShop(D, URL, { js: false, shot: 'cd1b-09-nojs-desktop' }));
    check('no JS, desktop: the page renders the bar; the dropdown opens natively and «Филтрирай» applies', (await page.locator('form.sf-filters-form').count()) === 1);
    await page.locator('.sf-fgroup-summary', { hasText: 'Цвят' }).click();
    await page.locator('.sf-fgroup[open] label.sf-fv-dot[title="Червен"]').click();
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('.sf-fdrawer-foot .sf-fbtn-primary').click()]);
    check('no JS, desktop: the form submitted (301 to the canonical URL) and the products are filtered', qs(page).includes(`filter[${ids.colour}]=${RED}`) && ! qs(page).includes('[]') && (await names(page)).length > 0, qs(page));
    await ctx.close();

    ({ ctx, page, take } = await openShop(P, URL, { js: false, shot: 'cd1b-10-nojs-phone' }));
    check('no JS, phone: the trigger is not shown and the filters are a plain block above the list', ! (await visible(page, '.sf-ftrigger')) && (await visible(page, '.sf-fdrawer-panel')) && (await visible(page, '.sf-fdrawer-foot .sf-fbtn-primary')));
    await page.locator('.sf-fgroup-summary', { hasText: 'Размер' }).click();
    await page.locator('.sf-fgroup label.sf-fv-link', { hasText: /^\s*M\s*$/ }).click();
    await page.locator('.sf-fsort label', { hasText: 'Име' }).click();
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('.sf-fdrawer-foot .sf-fbtn-primary').click()]);
    check('no JS, phone: «Филтрирай» applies the filter and the sort', qs(page).includes(`filter[${ids.size}]=${M}`) && qs(page).includes('sort=name_asc'), qs(page));
    await ctx.close();

    await admin.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (backedUp) {
        try {
            php(RESTORE);
            const after = JSON.parse(php(SUMS));
            check('everything created is gone: the settings table and every catalog table are as they were (checksums / counts)', before && JSON.stringify(before) === JSON.stringify(after), JSON.stringify({ before, after }));
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
