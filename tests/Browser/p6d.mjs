// A REAL-BROWSER check of P6d: «Масови действия» on the admin product list.
//   At 1440 and 390 px, on about 12 throw-away products («P6d audit …», all in one throw-away tag, the first 8 with a manual promo price): the list is searched, «select all matching» is used, a category is added
//   (the confirmation names the count) and removed, a season is set and cleared, the article group is changed; the UNFILTERED select-all is refused (the modal says so, there is no submit button); the manual-price reset
//   on the filtered set removes the manual prices of the set and leaves the others; the modal follows admin-ui-standard (measured width, the info icon, no helper paragraph, no overflow at 390 px); no console errors.
// NOT part of `php artisan test`: run by hand against the dev site. BEFORE the run every checksum is taken; at the end everything created is deleted and the checksums are compared (a watchdog puts the data back after
// 15 minutes). A throw-away Administrator account is created and deleted by the script. The screenshots go to OUT, which must be OUTSIDE the project folder (the script refuses otherwise).
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p6d.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p6d.mjs'); process.exit(2); }
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) { console.error('refused: OUT is inside the project folder; the screenshots must be kept outside it'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

const artisan = (args) => {
    const r = spawnSync('php', ['artisan', ...args], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    if (r.status !== 0) { throw new Error(`artisan ${args.join(' ')} failed (${r.status}): ` + ((r.stdout ?? '') + (r.stderr ?? '')).slice(-600)); }
    return (r.stdout ?? '').trim();
};
const php = (code) => {
    const out = artisan(['tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')]);
    if (out === '') { throw new Error('php produced no output'); }
    return out.split('\n').pop();
};

const EMAIL = `p6d-${Date.now()}@example.test`;
const PASSWORD = 'P6d-' + Math.random().toString(36).slice(2) + 'Aa1!';

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode(['products' => $sum('catalog_products'), 'variations' => $sum('catalog_variations'), 'stock' => $sum('stock_levels'), 'prices' => $sum('pricing_price_list_items'), 'categories' => $sum('catalog_categories'), 'pivots' => $sum('catalog_product_categories').$sum('catalog_product_tags'), 'tags' => $sum('catalog_tags'), 'seasons' => $sum('catalog_seasons'), 'groups' => $sum('catalog_product_groups'), 'log' => $sum('activity_log'), 'staff' => DB::table('staff')->count(), 'jobs' => DB::table('jobs')->count()]);`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$pids = DB::table('catalog_products')->where('slug', 'like', 'p6d-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('pricing_price_list_items')->where('target_type', 'variation')->whereIn('target_id', $vids->map(fn ($v) => (string) $v))->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_tags')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('activity_log')->where('entity_type', 'product')->whereIn('entity_id', $pids->map(fn ($v) => (string) $v))->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
DB::table('catalog_categories')->where('slug', 'p6d-audit-cat')->delete();
DB::table('catalog_tags')->where('slug', 'p6d-audit-tag')->delete();
DB::table('catalog_seasons')->where('slug', 'p6d-audit-season')->delete();
DB::table('catalog_product_groups')->where('code', 'p6d-grp')->delete();
DB::table('jobs')->where('payload', 'like', '%RefreshProductSortPrices%')->delete();
DB::table('staff')->where('email', '${EMAIL}')->delete();
echo 'cleaned';`;

const CREATE_STAFF = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'P6d Audit', $role));
echo 'ok';`;

// 12 products «P6d audit 01…12», one simple variation each (regular 100.00, stock 5), tag «p6d-audit-tag»; the first 8 have a MANUAL promo price 85.00; a throw-away category, season and article group
const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
DB::table('catalog_categories')->insert(['parent_id' => null, 'name' => 'P6d audit category', 'slug' => 'p6d-audit-cat', 'created_at' => $now, 'updated_at' => $now]);
DB::table('catalog_seasons')->insert(['name' => 'P6d audit season', 'slug' => 'p6d-audit-season', 'created_at' => $now, 'updated_at' => $now]);
DB::table('catalog_product_groups')->insert(['code' => 'p6d-grp', 'name' => 'P6d audit group', 'created_at' => $now, 'updated_at' => $now]);
$tag = DB::table('catalog_tags')->insertGetId(['name' => 'P6d audit tag', 'slug' => 'p6d-audit-tag', 'created_at' => $now, 'updated_at' => $now]);
$regular = DB::table('pricing_price_lists')->where('name', 'Regular Prices')->value('id');
$sale = DB::table('pricing_price_lists')->where('name', 'Manual Sale')->value('id');
$price = function ($list, $vid, $minor) use ($now) { DB::table('pricing_price_list_items')->insert(['price_list_id' => $list, 'target_type' => 'variation', 'target_id' => (string) $vid, 'min_quantity' => 1, 'price_amount_minor' => $minor, 'price_currency' => 'EUR', 'price_tax_rate_basis_points' => 0, 'price_tax_inclusive' => 0, 'created_at' => $now, 'updated_at' => $now]); };
$ids = [];
for ($i = 1; $i <= 12; $i++) {
  $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'simple', 'name' => 'P6d audit '.$n, 'slug' => 'p6d-audit-'.$n, 'base_sku' => 'P6DAUDIT-'.$n, 'status' => 'active', 'catalog_visibility' => 'visible', 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => $now]);
  DB::table('catalog_product_tags')->insert(['product_id' => $pid, 'tag_id' => $tag]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'universal', 'status' => 'active', 'attribute_signature' => hash('sha256', 'p6d-'.$n), 'sku' => 'P6DAUDIT-'.$n.'-0', 'is_visible' => 0, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 5, 'created_at' => $now, 'updated_at' => $now]);
  $price($regular, $vid, 10000);
  if ($i <= 8) { $price($sale, $vid, 8500); }
  $ids[] = $pid;
}
echo json_encode($ids);`;

const STATE = String.raw`
use Illuminate\Support\Facades\DB;
$pids = DB::table('catalog_products')->where('slug', 'like', 'p6d-audit-%')->pluck('id');
$cat = DB::table('catalog_categories')->where('slug', 'p6d-audit-cat')->value('id');
$season = DB::table('catalog_seasons')->where('slug', 'p6d-audit-season')->value('id');
$group = DB::table('catalog_product_groups')->where('code', 'p6d-grp')->value('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id')->map(fn ($v) => (string) $v);
$sale = DB::table('pricing_price_lists')->where('name', 'Manual Sale')->value('id');
echo json_encode(['category' => DB::table('catalog_product_categories')->whereIn('product_id', $pids)->where('category_id', $cat)->count(), 'season' => DB::table('catalog_products')->whereIn('id', $pids)->where('season_id', $season)->count(), 'group' => DB::table('catalog_products')->whereIn('id', $pids)->where('product_group_id', $group)->count(), 'manual' => DB::table('pricing_price_list_items')->where('price_list_id', $sale)->whereIn('target_id', $vids)->count()]);`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });

const steps = []; const consoleErrors = []; const shots = []; const report = {};
const check = (name, ok, detail = '') => { process.env.TRACE && console.error((ok ? '· ' : 'FAIL ') + name.slice(0, 90)); steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + String(detail).slice(0, 300)); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const state = () => JSON.parse(php(STATE));

let before = null; let created = false; let watchdog = null;

try {
    before = JSON.parse(php(SUMS));
    created = true;
    watchdog = setTimeout(() => { try { php(CLEANUP); } catch (e) { console.error('WATCHDOG cleanup failed ' + e); } console.error('WATCHDOG: the run took more than 15 minutes; the data was put back'); process.exit(3); }, 15 * 60 * 1000);
    php(CREATE_STAFF);
    const ids = JSON.parse(php(SETUP));
    check('twelve throw-away products with a tag, 8 of them with a manual price, were created', ids.length === 12 && state().manual === 8);

    for (const width of [1440, 390]) {
        const ctx = await browser.newContext({ viewport: { width, height: 1400 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
        ctx.setDefaultTimeout(30000);
        const page = await ctx.newPage();
        page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR@${width} ` + String(e).slice(0, 200)));
        page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(`CONSOLE@${width} ` + m.text().slice(0, 200)); });
        page.on('response', (r) => { r.status() >= 500 && consoleErrors.push(`HTTP@${width} ` + r.status() + ' ' + r.url().slice(0, 120)); });

        await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
        await page.fill('input[type=email]', EMAIL);
        await page.fill('input[type=password]', PASSWORD);
        await page.keyboard.press('Enter');
        await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
        await page.goto(BASE + '/admin/products', { waitUntil: 'networkidle' });

        const search = async (text) => { const box = page.locator('input[type=search]').first(); await box.fill(text); await sleep(1500); await page.waitForLoadState('networkidle'); };
        const selectAllMatching = async () => {
            await page.locator('input.fi-ta-page-checkbox, .fi-ta-page-checkbox input, thead input[type=checkbox]').first().check();
            await sleep(500);
            const link = page.getByText(/Select all|Избери всички/).first();
            await link.waitFor({ timeout: 10000 });
            await link.click();
            await sleep(500);
        };
        const openAction = async (label) => {
            await page.getByRole('button', { name: /Масови действия|Bulk actions/ }).first().click();
            await page.getByText(label, { exact: false }).first().click();
            await page.locator('.fi-modal-window').first().waitFor();
        };
        const pickInModal = async (text) => {
            const select = page.locator('.fi-modal-window .fi-fo-select, .fi-modal-window [role=combobox]').first();
            await select.click();
            await page.keyboard.type(text);
            await sleep(800);
            await page.getByRole('option', { name: new RegExp(text) }).first().click();
        };
        const submit = async () => { await page.locator('.fi-modal-window .fi-modal-footer button[type=submit], .fi-modal-window button:has-text("Приложи"), .fi-modal-window button:has-text("Изтрий ръчните цени")').first().click(); await sleep(2500); await page.waitForLoadState('networkidle'); };

        // ---- the UNFILTERED select-all is refused
        await page.locator('thead input[type=checkbox]').first().check();
        await sleep(500);
        const all = page.getByText(/Select all|Избери всички/).first();
        if (await all.count()) { await all.click(); await sleep(500); }
        await openAction('Добави категория');
        const modalText = await page.locator('.fi-modal-window').first().innerText();
        const submitCount = await page.locator('.fi-modal-window button:has-text("Приложи")').count();
        check(`${width}: the unfiltered select-all is refused (the modal says so, there is no submit button)`, /не е позволено/i.test(modalText) && submitCount === 0, modalText.slice(0, 200));
        const sU = join(OUT, `p6d-unfiltered-${width}.png`); shots.push(sU); await page.screenshot({ path: sU });
        await page.keyboard.press('Escape');
        await page.goto(BASE + '/admin/products', { waitUntil: 'networkidle' });

        // ---- search «P6d audit», select all matching, add the category
        await search('P6d audit');
        await selectAllMatching();
        await openAction('Добави категория');
        const modal = page.locator('.fi-modal-window').first();
        const box = await modal.boundingBox();
        const confirmText = await modal.innerText();
        report[`modal_${width}`] = { width: Math.round(box?.width ?? 0), text: confirmText.slice(0, 160) };
        check(`${width}: the confirmation names the count (12)`, /12/.test(confirmText), confirmText.slice(0, 200));
        check(`${width}: the modal is narrow (one field per row, measured ${Math.round(box?.width ?? 0)} px) and has no helper paragraph under the field, only the info icon`, (box?.width ?? 9999) <= Math.max(width - 16, 480) && (await modal.locator('.fi-fo-field-wrp-hint, .fi-fo-field-wrp-helper-text').count()) === 0 && (await modal.locator('[aria-describedby], .fi-fo-field-wrp-label button').count()) > 0);
        const sM = join(OUT, `p6d-modal-${width}.png`); shots.push(sM); await page.screenshot({ path: sM });
        await pickInModal('P6d audit category');
        await submit();
        check(`${width}: add a category: all 12 products have it`, state().category === 12, JSON.stringify(state()));

        // the same again: «без промяна»
        await selectAllMatching();
        await openAction('Добави категория');
        await pickInModal('P6d audit category');
        await submit();
        const notice = await page.locator('.fi-no-notification').first().innerText().catch(() => '');
        check(`${width}: the second run says «без промяна» for all 12`, /без промяна 12|unchanged 12/.test(notice), notice.slice(0, 160));

        // ---- remove it again
        await selectAllMatching();
        await openAction('Премахни категория');
        await pickInModal('P6d audit category');
        await submit();
        check(`${width}: remove the category: none has it`, state().category === 0, JSON.stringify(state()));

        // ---- season set / clear, group
        await selectAllMatching();
        await openAction('Задай или изчисти сезон');
        await pickInModal('P6d audit season');
        await submit();
        check(`${width}: set the season on all 12`, state().season === 12, JSON.stringify(state()));
        await selectAllMatching();
        await openAction('Задай или изчисти сезон');
        await submit();
        check(`${width}: clear the season (the empty choice)`, state().season === 0, JSON.stringify(state()));
        await selectAllMatching();
        await openAction('Смени артикулна група');
        await pickInModal('P6d audit group');
        await submit();
        check(`${width}: change the article group on all 12`, state().group === 12, JSON.stringify(state()));

        // ---- the manual-price reset on the filtered set (search narrows to the 12; products 9–12 have none)
        await selectAllMatching();
        await openAction('Върни към регулярни цени');
        const resetText = await page.locator('.fi-modal-window').first().innerText();
        const red = await page.locator('.fi-modal-window .text-danger-600, .fi-modal-window [class*=danger]').count();
        check(`${width}: the reset modal warns in red that the manual prices of all variations will be deleted`, /не могат да бъдат възстановени/.test(resetText) && red > 0, resetText.slice(0, 220));
        const sR = join(OUT, `p6d-reset-${width}.png`); shots.push(sR); await page.screenshot({ path: sR });
        await submit();
        const after = state();
        check(`${width}: the reset removed the 8 manual prices of the set`, after.manual === 0, JSON.stringify(after));
        if (width === 390) {
            check('390: no horizontal scroll', ! (await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)));
        }
        await ctx.close();

        // put the manual prices back for the second width
        php(String.raw`use Illuminate\Support\Facades\DB; $now = now(); $sale = DB::table('pricing_price_lists')->where('name', 'Manual Sale')->value('id'); $pids = DB::table('catalog_products')->where('slug', 'like', 'p6d-audit-%')->orderBy('id')->limit(8)->pluck('id'); foreach (DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id') as $vid) { DB::table('pricing_price_list_items')->insert(['price_list_id' => $sale, 'target_type' => 'variation', 'target_id' => (string) $vid, 'min_quantity' => 1, 'price_amount_minor' => 8500, 'price_currency' => 'EUR', 'price_tax_rate_basis_points' => 0, 'price_tax_inclusive' => 0, 'created_at' => $now, 'updated_at' => $now]); } echo 'ok';`);
    }

    check('no console errors, no page errors, no 5xx answers', consoleErrors.length === 0, consoleErrors.join(' | '));
} catch (e) {
    check('the run finished without an exception', false, String(e).slice(0, 900));
} finally {
    if (watchdog) clearTimeout(watchdog);
    if (created) {
        try {
            php(CLEANUP);
            const after = JSON.parse(php(SUMS));
            for (const k of ['products', 'variations', 'stock', 'prices', 'categories', 'pivots', 'tags', 'seasons', 'groups', 'log', 'staff', 'jobs']) {
                check(`${k}: back EXACTLY as before the run`, after[k] === before[k], `${before[k]} vs ${after[k]}`);
            }
        } catch (e) {
            check('the data was put back', false, String(e).slice(0, 600));
        }
    }
    await browser.close();
    const failed = steps.filter((s) => ! s.ok);
    console.log(JSON.stringify({ base: BASE, report, shots, console_errors: consoleErrors, steps, failed: failed.length }, null, 2));
    process.exit(failed.length === 0 ? 0 : 1);
}
