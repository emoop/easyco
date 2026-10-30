// A REAL-BROWSER run of CD-1a: the «Каталог» zone of the «Дизайн» screen (columns on a desktop and on a phone, products per page) and the numbered pages of a category listing. NOT part of `php artisan test`:
// run by hand against a dev site whose data may be changed. It creates a throw-away admin («cd1a-audit») and ONE throw-away category («cd1a-cat») with 100 products (slugs cd1a-1 .. cd1a-100), and BACKS UP the
// `site_settings` table and the `appearance/` files first; at the end it restores them, removes everything it created and verifies the settings table is byte-for-byte what it was (a checksum of every row).
// Prints a JSON report; exit code 1 when a check failed.
//
// With real clicks: «Дизайн» → «Каталог»: 5 columns on a desktop, 1 on a phone, 24 per page → «Приложи» + «Публикувай» → the category page has 5 computed columns at 1400, 3 at 800 (the tablet), 1 at 390, 24 cards,
// numbered pages with `?page=2`; 12 per page → 9 pages with an ellipsis; 3 columns; «Начални настройки» → today's look (4 / 3 / 2 columns, 24 cards); the home page grid is unchanged throughout.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/cd-1a.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { randomBytes } from 'node:crypto';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const CATEGORY = 'cd1a-cat';
const EMAIL = 'cd1a-audit@example.test';
const PASSWORD = 'Cd1a-' + randomBytes(6).toString('hex');
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node cd-1a.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

// ---- the dev database, through the project's own code (tinker); one line per call -------------------------------------------------------------------
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
file_put_contents(sys_get_temp_dir().'/cd1a_backup.json', json_encode(['rows' => $rows, 'files' => $files, 'contents' => array_combine($files, array_map(fn ($f) => base64_encode(Storage::disk('public')->get($f)), $files)), 'sum' => md5(json_encode($rows))]));
echo json_encode(['rows' => count($rows), 'sum' => md5(json_encode($rows))]);`;

const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
use EasyCo\Staff\Contracts\{PasswordHasher,RoleRepository,StaffRepository};
$role = app(RoleRepository::class)->findSystemRoleByName('Administrator');
$staff = EasyCo\Staff\Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'CD1A Audit', $role);
app(StaffRepository::class)->save($staff);
$now = now();
$cid = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'CD1A каталог', 'slug' => '${CATEGORY}', 'created_at' => $now, 'updated_at' => $now]);
for ($i = 1; $i <= 100; $i++) {
  $at = now()->subMinutes($i);
  $pid = DB::table('catalog_products')->insertGetId(['type'=>'simple','name'=>"CD1A продукт $i",'slug'=>"cd1a-$i",'base_sku'=>"CD1A-$i",'status'=>'active','catalog_visibility'=>'visible','is_featured'=>0,'created_at'=>$now,'updated_at'=>$now,'timeline_at'=>$at]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id'=>$pid,'sort_order'=>0,'type'=>'universal','status'=>'active','attribute_signature'=>hash('sha256',"cd1a-$i"),'sku'=>"CD1A-V$i",'is_visible'=>0,'is_purchasable'=>1,'created_at'=>$now,'updated_at'=>$now]);
  DB::table('stock_levels')->insert(['variation_id'=>$vid,'quantity'=>5,'created_at'=>$now,'updated_at'=>$now]);
  DB::table('catalog_product_categories')->insert(['product_id'=>$pid,'category_id'=>$cid]);
}
echo json_encode(['category'=>$cid]);`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/cd1a_backup.json'), true);
DB::table('site_settings')->delete();
foreach ($bak['rows'] as $row) { DB::table('site_settings')->insert($row); }
foreach (Storage::disk('public')->allFiles('appearance') as $f) { if (! in_array($f, $bak['files'], true)) { Storage::disk('public')->delete($f); } }
foreach ($bak['contents'] as $f => $content) { Storage::disk('public')->put($f, base64_decode($content)); }
$pids = DB::table('catalog_products')->where('slug','like','cd1a-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id',$pids)->pluck('id');
DB::table('stock_levels')->whereIn('variation_id',$vids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id',$pids)->delete();
DB::table('catalog_variations')->whereIn('product_id',$pids)->delete();
DB::table('catalog_products')->whereIn('id',$pids)->delete();
DB::table('catalog_categories')->where('slug','${CATEGORY}')->delete();
DB::table('staff')->where('email','${EMAIL}')->delete();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
echo json_encode(['sum' => md5(json_encode($rows)), 'was' => $bak['sum'], 'rows' => count($rows), 'products' => DB::table('catalog_products')->where('slug','like','cd1a-%')->count(), 'variations' => DB::table('catalog_variations')->where('sku','like','CD1A-%')->count(), 'categories' => DB::table('catalog_categories')->where('slug','${CATEGORY}')->count(), 'staff' => DB::table('staff')->where('email','${EMAIL}')->count(), 'appearanceFiles' => count(Storage::disk('public')->allFiles('appearance')), 'appearanceWas' => count($bak['files'])]);`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const consoleErrors = [];
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 500) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let backedUp = false;
let report = null;

try {
    console.error('backup: ' + php(BACKUP));
    backedUp = true;
    php(SETUP);

    // ============================================================ the admin
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

    const field = (label) => page.locator('.ds-props .fi-fo-field', { has: page.locator('label', { hasText: label }) }).first();
    const choose = async (label, optionText) => {
        await field(label).locator('button').first().click();
        await page.locator('[role=option]:visible').filter({ hasText: new RegExp('^\\s*' + optionText + '\\s*$') }).first().click();
        await sleep(400);
    };
    const confirmModal = async () => { if (await page.locator('.fi-modal-window:visible').count() > 0) { await page.locator('.fi-modal-window:visible').last().getByRole('button').filter({ hasNotText: /Отказ|Cancel/ }).last().click(); } };
    // «Приложи» then «Публикувай» (skipped when disabled: nothing to publish)
    const save = async () => {
        await page.locator('.ds-props button[type=submit]').click();
        await settle();

        const publish = page.locator('.fi-header-actions-ctn .fi-btn', { hasText: 'Публикувай' }).first();

        if ((await publish.getAttribute('disabled')) === null && ! (await publish.getAttribute('class')).includes('fi-disabled')) {
            await publish.click();
            await sleep(700);
            await confirmModal();
            await settle();
        }
    };
    const open = async (zone) => { await page.goto(BASE + '/admin/design?zone=' + zone); await settle(); };
    const reset = async () => { await page.getByRole('button', { name: 'Начални настройки' }).first().click(); await sleep(900); await confirmModal(); await settle(); };

    // the dev site may hold values the owner set by hand: the section starts from its defaults (the backup puts them back at the end)
    await open('catalog');
    await reset();
    await save();

    // ---- the zone ----
    await open('catalog');
    check('the zone list has «Каталог»', (await page.locator('.ds-zones button', { hasText: 'Каталог' }).count()) === 1);
    check('the zone shows today\'s defaults: 4 / 2 / 24', (await field('Колони на десктоп').innerText()).includes('4 колони') && (await field('Колони на телефон').innerText()).includes('2 колони') && (await field('Продукти на страница').innerText()).includes('24'));
    const hints = await page.locator('.ds-props').innerText();
    check('a hint under each control and no layout / side-panel control', hints.includes('По подразбиране 4.') && hints.includes('По подразбиране 2.') && hints.includes('По подразбиране 24.') && ! /страничен панел/i.test(await page.locator('.ds-props').innerText()), hints.slice(0, 300));
    const sketch = await page.locator('.ds-catalog-sketch').evaluate((el) => ({ desktop: el.children[0].querySelectorAll('span').length, phone: el.children[1].querySelectorAll('span').length }));
    check('the schematic shows 4 boxes in a row (8) and 2 on the phone (4)', sketch.desktop === 8 && sketch.phone === 4, JSON.stringify(sketch));
    await shot('01-catalog-defaults');

    // a bad value cannot be typed in a select: the closed list is the only way (the server refusal is covered by the Livewire tests)
    const options = await (async () => { await field('Колони на десктоп').locator('button').first().click(); const texts = await page.locator('[role=option]:visible').allInnerTexts(); await page.keyboard.press('Escape'); return texts.map((t) => t.trim()); })();
    check('the desktop columns offer exactly 3, 4 and 5', JSON.stringify(options) === JSON.stringify(['3 колони', '4 колони', '5 колони']), JSON.stringify(options));

    // ---- the storefront, measured ----
    const catalogData = async (url, viewport, name = null) => {
        const ctx = await browser.newContext({ viewport, ignoreHTTPSErrors: true, ...(viewport.width < 600 ? { hasTouch: true, isMobile: true } : {}) });
        const p = await ctx.newPage();
        p.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        const response = await p.goto(BASE + url, { waitUntil: 'networkidle' });
        const data = await p.evaluate(() => {
            const grid = document.querySelector('.sf-grid');
            if (! grid) return null;
            const row = (el) => getComputedStyle(el).gridTemplateColumns.split(' ').length;
            const pages = document.querySelector('.sf-pagination-pages');
            return {
                cols: row(grid),
                cards: grid.querySelectorAll('.sf-card').length,
                links: pages ? [...pages.querySelectorAll('li')].map((li) => (li.classList.contains('sf-pagination-ellipsis') ? '…' : (li.querySelector('[aria-current="page"]') ? '[' + li.innerText.trim() + ']' : li.innerText.trim()))) : [],
                hrefs: pages ? [...pages.querySelectorAll('a')].map((a) => a.getAttribute('href')) : [],
                status: pages ? 'pages' : 'none',
                overflowX: document.documentElement.scrollWidth > window.innerWidth + 1,
                sticky: document.querySelector('.sf-pagination') ? document.querySelector('.sf-pagination').getBoundingClientRect().top : null,
            };
        });
        if (name && OUT) { const path = join(OUT, name + '.png'); shots.push(path); await p.screenshot({ path, fullPage: true }); }
        const status = response ? response.status() : 0;
        await ctx.close();

        return { status, data };
    };
    const URL = '/product-category/' + CATEGORY;

    // today's look first (nothing set)
    let d = await catalogData(URL, { width: 1400, height: 1000 });
    check('defaults, 1400: 4 columns, 24 cards, pages 1 2 3 4 5 (all numbers, no ellipsis)', d.status === 200 && d.data.cols === 4 && d.data.cards === 24 && d.data.links.join(' ') === '[1] 2 3 4 5', JSON.stringify(d));
    d = await catalogData(URL, { width: 800, height: 900 });
    check('defaults, 800 (tablet): 3 columns', d.data.cols === 3, JSON.stringify(d.data));
    d = await catalogData(URL, { width: 390, height: 844 });
    check('defaults, 390: 2 columns', d.data.cols === 2, JSON.stringify(d.data));
    const homeBefore = (await catalogData('/', { width: 1400, height: 1000 })).data;

    // ---- 5 desktop columns, 1 on a phone, 24 per page ----
    await open('catalog');
    await choose('Колони на десктоп', '5 колони');
    await choose('Колони на телефон', '1 колона');
    await choose('Продукти на страница', '24');
    await sleep(300);
    await save();
    await shot('02-catalog-5-1-24');
    const sketch2 = await page.locator('.ds-catalog-sketch').evaluate((el) => ({ desktop: el.children[0].querySelectorAll('span').length, phone: el.children[1].querySelectorAll('span').length }));
    check('the schematic follows the applied values: 5 boxes in a row (10) and 1 on the phone (2)', sketch2.desktop === 10 && sketch2.phone === 2, JSON.stringify(sketch2));

    d = await catalogData(URL, { width: 1400, height: 1000 }, 'category-1400-5cols');
    check('5 columns, 1400: 5 computed columns, 24 cards, numbered pages', d.data.cols === 5 && d.data.cards === 24 && d.data.links.join(' ') === '[1] 2 3 4 5', JSON.stringify(d));
    check('on the first page the links are ?page=2 .. ?page=5 (the current page is not a link)', JSON.stringify(d.data.hrefs) === JSON.stringify([2, 3, 4, 5].map((n) => URL + '?page=' + n)), JSON.stringify(d.data.hrefs));
    d = await catalogData(URL, { width: 800, height: 900 });
    check('5 columns, 800 (tablet): min(5, 3) = 3 columns', d.data.cols === 3, JSON.stringify(d.data));
    d = await catalogData(URL, { width: 390, height: 844 }, 'category-390-1col');
    check('1 column on a phone at 390, no horizontal scroll', d.data.cols === 1 && ! d.data.overflowX, JSON.stringify(d.data));
    const home5 = (await catalogData('/', { width: 1400, height: 1000 })).data;
    check('the home page is unchanged (same columns and cards)', home5 && homeBefore && home5.cols === homeBefore.cols && home5.cards === homeBefore.cards, JSON.stringify({ homeBefore, home5 }));
    d = await catalogData(URL + '?page=2', { width: 1400, height: 1000 });
    check('?page=2 is a page with 24 cards and page 2 marked', d.status === 200 && d.data.cards === 24 && d.data.links.join(' ') === '1 [2] 3 4 5', JSON.stringify(d));
    d = await catalogData(URL + '?page=6', { width: 1400, height: 1000 });
    check('a page past the last is the storefront 404', d.status === 404, JSON.stringify(d.status));

    // ---- 12 per page: 9 pages with an ellipsis ----
    await open('catalog');
    await choose('Продукти на страница', '12');
    await save();
    d = await catalogData(URL, { width: 1400, height: 1000 }, 'category-12-page1');
    check('12 per page, page 1 of 9: 12 cards and the row [1] 2 3 … 9', d.data.cards === 12 && d.data.links.join(' ') === '[1] 2 3 … 9', JSON.stringify(d));
    d = await catalogData(URL + '?page=4', { width: 1400, height: 1000 }, 'category-12-page4');
    check('page 4 of 9: the row 1 2 3 [4] 5 6 … 9 (a gap of one page shows its number)', d.data.cards === 12 && d.data.links.join(' ') === '1 2 3 [4] 5 6 … 9', JSON.stringify(d));
    check('the link of page 1 has no ?page= and no link carries per_page (12 is the published size)', d.data.hrefs[0] === URL && d.data.hrefs.every((h) => ! h.includes('per_page')), JSON.stringify(d.data.hrefs));
    d = await catalogData(URL + '?page=9', { width: 1400, height: 1000 });
    check('the last page 9: 4 cards and the row 1 … 7 8 [9]', d.data.cards === 4 && d.data.links.join(' ') === '1 … 7 8 [9]', JSON.stringify(d));
    d = await catalogData(URL + '?per_page=24', { width: 1400, height: 1000 });
    check('?per_page=24 in the URL still decides (24 cards) and the links keep it', d.status === 200 && d.data.cards === 24 && d.data.hrefs.every((h) => h === URL + '?per_page=24' || h.includes('per_page=24')), JSON.stringify(d));
    d = await catalogData(URL + '?per_page=13', { width: 1400, height: 1000 });
    check('?per_page=13 (not in the list) is the storefront 404', d.status === 404, JSON.stringify(d.status));
    d = await catalogData(URL + '?per_page=20', { width: 1400, height: 1000 });
    check('?per_page=20 (CD-1a.2) gives 20 cards and its links keep per_page=20', d.status === 200 && d.data.cards === 20 && d.data.hrefs.every((h) => h.includes('per_page=20')), JSON.stringify(d));
    d = await catalogData(URL + '?per_page=72', { width: 1400, height: 1000 });
    check('?per_page=72 (a new member of the list) gives 72 cards', d.status === 200 && d.data.cards === 72, JSON.stringify(d));

    // ---- 3 columns ----
    await open('catalog');
    await choose('Колони на десктоп', '3 колони');
    await choose('Колони на телефон', '2 колони');
    await save();
    d = await catalogData(URL, { width: 1400, height: 1000 });
    check('3 columns, 1400: 3 columns, 12 cards', d.data.cols === 3 && d.data.cards === 12, JSON.stringify(d.data));
    d = await catalogData(URL, { width: 390, height: 844 });
    check('2 columns on a phone', d.data.cols === 2, JSON.stringify(d.data));

    // ---- «Начални настройки» → today's look ----
    await open('catalog');
    await reset();
    check('the reset puts 4 / 2 / 24 into the panel', (await field('Колони на десктоп').innerText()).includes('4 колони') && (await field('Колони на телефон').innerText()).includes('2 колони') && (await field('Продукти на страница').innerText()).includes('24'));
    await save();
    d = await catalogData(URL, { width: 1400, height: 1000 }, 'category-1400-defaults-again');
    check('after the reset, 1400: 4 columns, 24 cards, no ellipsis', d.data.cols === 4 && d.data.cards === 24 && ! d.data.links.includes('…'), JSON.stringify(d.data));
    d = await catalogData(URL, { width: 390, height: 844 });
    check('after the reset, 390: 2 columns', d.data.cols === 2, JSON.stringify(d.data));
    const homeAfter = (await catalogData('/', { width: 1400, height: 1000 })).data;
    check('the home page is still the same', homeAfter.cols === homeBefore.cols && homeAfter.cards === homeBefore.cards, JSON.stringify({ homeBefore, homeAfter }));

    await admin.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 600));
} finally {
    if (backedUp) {
        try {
            report = JSON.parse(php(RESTORE));
            console.error('restored: ' + JSON.stringify(report));
            check('the settings table is byte-for-byte what it was (a checksum of every row)', report.sum === report.was, JSON.stringify(report));
            check('nothing of the throw-away category, products, variations and admin is left', report.products === 0 && report.variations === 0 && report.categories === 0 && report.staff === 0, JSON.stringify(report));
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
