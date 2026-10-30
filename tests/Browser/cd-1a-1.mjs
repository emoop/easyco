// A REAL-BROWSER run of CD-1a.1: the preview frame of «Каталог» shows the APPLIED draft (after «Приложи», before «Публикувай») while the public category page keeps the published look. NOT part of
// `php artisan test`: run by hand against a dev site whose data may be changed. It creates a throw-away admin («cd1a1-audit») and ONE throw-away category («cd1a1-cat») with 40 products, and BACKS UP the
// `site_settings` table and the `appearance/` files first; at the end it restores them, removes everything it created (the preview stylesheets included) and verifies the settings table is
// byte-for-byte what it was. Prints a JSON report; exit code 1 when a check failed.
//
// With real clicks: «Дизайн» → «Каталог»: 5 columns, 12 per page → «Приложи» → the frame shows 5 columns and 12 cards, the public category url (a context with no session) still 4 columns / 24 cards →
// «Публикувай» → the public page is 5 / 12 → «Начални настройки» + «Приложи» → the frame is back at 4 / 24 while the public page is still 5 / 12 → «Публикувай» → public 4 / 24.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/cd-1a-1.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { randomBytes } from 'node:crypto';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const CATEGORY = 'cd1a1-cat';
const EMAIL = 'cd1a1-audit@example.test';
const PASSWORD = 'Cd1a1-' + randomBytes(6).toString('hex');
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node cd-1a-1.mjs');
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
file_put_contents(sys_get_temp_dir().'/cd1a1_backup.json', json_encode(['rows' => $rows, 'files' => $files, 'contents' => array_combine($files, array_map(fn ($f) => base64_encode(Storage::disk('public')->get($f)), $files)), 'sum' => md5(json_encode($rows))]));
echo json_encode(['rows' => count($rows), 'sum' => md5(json_encode($rows))]);`;

const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
use EasyCo\Staff\Contracts\{PasswordHasher,RoleRepository,StaffRepository};
$role = app(RoleRepository::class)->findSystemRoleByName('Administrator');
$staff = EasyCo\Staff\Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'CD1A1 Audit', $role);
app(StaffRepository::class)->save($staff);
$now = now();
$cid = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'CD1A1 каталог', 'slug' => '${CATEGORY}', 'created_at' => $now, 'updated_at' => $now]);
for ($i = 1; $i <= 40; $i++) {
  $pid = DB::table('catalog_products')->insertGetId(['type'=>'simple','name'=>"CD1A1 продукт $i",'slug'=>"cd1a1-$i",'base_sku'=>"CD1A1-$i",'status'=>'active','catalog_visibility'=>'visible','is_featured'=>0,'created_at'=>$now,'updated_at'=>$now,'timeline_at'=>now()->addDays(2)->subMinutes($i)]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id'=>$pid,'sort_order'=>0,'type'=>'universal','status'=>'active','attribute_signature'=>hash('sha256',"cd1a1-$i"),'sku'=>"CD1A1-V$i",'is_visible'=>0,'is_purchasable'=>1,'created_at'=>$now,'updated_at'=>$now]);
  DB::table('stock_levels')->insert(['variation_id'=>$vid,'quantity'=>5,'created_at'=>$now,'updated_at'=>$now]);
  DB::table('catalog_product_categories')->insert(['product_id'=>$pid,'category_id'=>$cid]);
}
echo json_encode(['category'=>$cid]);`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/cd1a1_backup.json'), true);
DB::table('site_settings')->delete();
foreach ($bak['rows'] as $row) { DB::table('site_settings')->insert($row); }
foreach (Storage::disk('public')->allFiles('appearance') as $f) { if (! in_array($f, $bak['files'], true)) { Storage::disk('public')->delete($f); } }
foreach ($bak['contents'] as $f => $content) { Storage::disk('public')->put($f, base64_decode($content)); }
$pids = DB::table('catalog_products')->where('slug','like','cd1a1-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id',$pids)->pluck('id');
DB::table('stock_levels')->whereIn('variation_id',$vids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id',$pids)->delete();
DB::table('catalog_variations')->whereIn('product_id',$pids)->delete();
DB::table('catalog_products')->whereIn('id',$pids)->delete();
DB::table('catalog_categories')->where('slug','${CATEGORY}')->delete();
DB::table('staff')->where('email','${EMAIL}')->delete();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
echo json_encode(['sum' => md5(json_encode($rows)), 'was' => $bak['sum'], 'rows' => count($rows), 'products' => DB::table('catalog_products')->where('slug','like','cd1a1-%')->count(), 'variations' => DB::table('catalog_variations')->where('sku','like','CD1A1-%')->count(), 'categories' => DB::table('catalog_categories')->where('slug','${CATEGORY}')->count(), 'staff' => DB::table('staff')->where('email','${EMAIL}')->count(), 'appearanceFiles' => count(Storage::disk('public')->allFiles('appearance')), 'appearanceWas' => count($bak['files'])]);`;

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

    const admin = await browser.newContext({ viewport: { width: 1700, height: 1200 }, ignoreHTTPSErrors: true });
    const page = await admin.newPage();
    page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.stack || e).replace(/\s+/g, ' ').slice(0, 300)));
    page.on('response', (r) => { if (r.status() >= 500) consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 160)); });
    const shot = async (name) => { if (! OUT) return; const path = join(OUT, name + '.png'); shots.push(path); await page.screenshot({ path, fullPage: true }); };
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
    const apply = async () => { await page.locator('.ds-props button[type=submit]').click(); await settle(); };
    const publish = async () => {
        const button = page.locator('.fi-header-actions-ctn .fi-btn', { hasText: 'Публикувай' }).first();
        await button.click();
        await sleep(700);
        await confirmModal();
        await settle();
    };
    const open = async (zone) => { await page.goto(BASE + '/admin/design?zone=' + zone); await settle(); };
    const reset = async () => { await page.getByRole('button', { name: 'Начални настройки' }).first().click(); await sleep(900); await confirmModal(); await settle(); };

    // what the frame shows: its own grid columns, cards and width
    const frameData = async () => {
        const handle = await page.locator('.ds-preview iframe, iframe[title]').first().elementHandle();
        const frame = await handle.contentFrame();
        await frame.waitForLoadState('networkidle');
        await sleep(500);

        return frame.evaluate(() => {
            const grid = document.querySelector('.sf-grid');

            return grid ? { width: window.innerWidth, cols: getComputedStyle(grid).gridTemplateColumns.split(' ').length, cards: grid.querySelectorAll('.sf-card').length, category: !! document.querySelector('.sf-category') } : null;
        });
    };
    // what a visitor sees: a fresh context, no session
    const publicData = async (width = 1400) => {
        const ctx = await browser.newContext({ viewport: { width, height: 1000 }, ignoreHTTPSErrors: true });
        const p = await ctx.newPage();
        const response = await p.goto(BASE + '/product-category/' + CATEGORY, { waitUntil: 'networkidle' });
        const data = await p.evaluate(() => {
            const grid = document.querySelector('.sf-grid');

            return { cols: getComputedStyle(grid).gridTemplateColumns.split(' ').length, cards: grid.querySelectorAll('.sf-card').length, preview: !! document.querySelector('link[href*="design-preview"]') };
        });
        const cookies = await ctx.cookies();
        await ctx.close();

        return { status: response.status(), ...data, cookies: cookies.length };
    };

    // the dev site may hold values the owner set by hand: the section starts from its defaults (the backup puts them back)
    await open('catalog');
    await reset();
    await apply();
    await publish().catch(() => {});
    await open('catalog');

    check('the preview note says it shows what is applied', (await page.locator('.ds-main').innerText()).includes('Прегледът показва приложеното; в магазина влиза след „Публикувай“.'));
    const before = await frameData();
    check('the frame starts at the defaults: 4 columns, 24 cards (the first shown category)', before && before.category && before.cols === 4 && before.cards === 24 && before.width >= 1024, JSON.stringify(before));
    check('the public page starts at 4 columns / 24 cards', JSON.stringify((({ cols, cards }) => ({ cols, cards }))(await publicData())) === JSON.stringify({ cols: 4, cards: 24 }));

    // ---- apply (not published) ----
    await choose('Колони на десктоп', '5 колони');
    await choose('Продукти на страница', '12');
    await apply();
    await shot('01-applied-frame');
    const applied = await frameData();
    check('after «Приложи» the frame shows 5 columns and 12 cards', applied && applied.cols === 5 && applied.cards === 12, JSON.stringify(applied));
    const pub1 = await publicData();
    check('after «Приложи» the public page is still 4 columns / 24 cards, no preview stylesheet, no cookie', pub1.status === 200 && pub1.cols === 4 && pub1.cards === 24 && ! pub1.preview && pub1.cookies === 0, JSON.stringify(pub1));

    // ---- publish ----
    await publish();
    const pub2 = await publicData();
    check('after «Публикувай» the public page is 5 columns / 12 cards', pub2.cols === 5 && pub2.cards === 12 && ! pub2.preview, JSON.stringify(pub2));
    const framed2 = await frameData();
    check('and the frame still shows 5 / 12 (draft and published are equal)', framed2 && framed2.cols === 5 && framed2.cards === 12, JSON.stringify(framed2));

    // ---- back to the defaults: applied, not published ----
    await reset();
    await apply();
    const back = await frameData();
    check('«Начални настройки» + «Приложи»: the frame is back at 4 columns / 24 cards', back && back.cols === 4 && back.cards === 24, JSON.stringify(back));
    await shot('02-defaults-frame');
    const pub3 = await publicData();
    check('while the public page is still 5 columns / 12 cards', pub3.cols === 5 && pub3.cards === 12, JSON.stringify(pub3));

    await publish();
    const pub4 = await publicData();
    check('after «Публикувай» the public page is 4 columns / 24 cards again', pub4.cols === 4 && pub4.cards === 24, JSON.stringify(pub4));

    // ---- «Страница и карти» uses the same mechanism ----
    await open('page');
    const cardRadius = async () => { const h = await page.locator('iframe[title]').first().elementHandle(); const f = await h.contentFrame(); await f.waitForLoadState('networkidle'); await sleep(400); return f.evaluate(() => getComputedStyle(document.querySelector('.sf-card')).borderTopLeftRadius); };
    const radiusBefore = await cardRadius();
    await page.locator('.ds-props fieldset', { has: page.locator('legend', { hasText: 'Десктоп' }) }).locator('input').nth(1).fill('20');
    await apply();
    const radiusApplied = await cardRadius();
    check('«Страница и карти»: after «Приложи» the frame shows the 20px corner radius', radiusApplied === '20px' && radiusBefore !== '20px', JSON.stringify({ radiusBefore, radiusApplied }));
    const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
    const pp = await ctx.newPage();
    await pp.goto(BASE + '/', { waitUntil: 'networkidle' });
    const publicRadius = await pp.evaluate(() => getComputedStyle(document.querySelector('.sf-card')).borderTopLeftRadius);
    await ctx.close();
    check('while the public home page keeps the published corner radius', publicRadius === radiusBefore, JSON.stringify({ publicRadius, radiusBefore }));
    await page.getByRole('button', { name: 'Върни публикуваното' }).first().click();
    await sleep(700);
    await confirmModal();
    await settle();

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
            check('the appearance/ files (the preview stylesheets included) are the ones that were there', report.appearanceFiles === report.appearanceWas, JSON.stringify(report));
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
