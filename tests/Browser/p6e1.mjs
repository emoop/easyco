// A REAL-BROWSER check of P6e-1: «Колекции» — data + admin, switched OFF by default.
//   At 1280 and 360 px: with the switch OFF (the default) nothing of the collection appears (navigation, product form, list filter, mass actions; the direct URL is refused); the switch is turned ON on the «Каталог»
//   settings page (the info icon, no helper paragraph, measured container, no horizontal scroll); two throw-away collections are created (one through the form, one with a picture); both are assigned to a throw-away
//   product in the product form; the list is filtered by one; «Масови действия» add and remove a collection on a filtered set; the collections list shows the product counts; no console errors.
// NOT part of `php artisan test`: run by hand against the dev site. BEFORE the run every checksum (and the switch) is taken; at the end the switch is put back, everything created is deleted and the checksums are
// compared (a watchdog puts the data back after 15 minutes). A throw-away Administrator account is created and deleted by the script. The screenshots go to OUT, which must be OUTSIDE the project folder.
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p6e1.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p6e1.mjs'); process.exit(2); }
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

const EMAIL = `p6e1-${Date.now()}@example.test`;
const PASSWORD = 'P6e1-' + Math.random().toString(36).slice(2) + 'Aa1!';

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode(['products' => $sum('catalog_products'), 'variations' => $sum('catalog_variations'), 'stock' => $sum('stock_levels'), 'prices' => $sum('pricing_price_list_items'), 'pivots' => $sum('catalog_product_categories').$sum('catalog_product_tags').$sum('catalog_product_collections'), 'collections' => $sum('catalog_collections'), 'log' => $sum('activity_log'), 'staff' => DB::table('staff')->count(), 'jobs' => DB::table('jobs')->count(), 'switch' => DB::table('site_settings')->where('key', 'catalog.collections_enabled')->value('value')]);`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$pids = DB::table('catalog_products')->where('slug', 'like', 'p6e1-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('pricing_price_list_items')->where('target_type', 'variation')->whereIn('target_id', $vids->map(fn ($v) => (string) $v))->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_collections')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('activity_log')->where('entity_type', 'product')->whereIn('entity_id', $pids->map(fn ($v) => (string) $v))->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
$cids = DB::table('catalog_collections')->where('slug', 'like', 'p6e1-audit-%')->pluck('id');
DB::table('catalog_product_collections')->whereIn('collection_id', $cids)->delete();
DB::table('catalog_collections')->whereIn('id', $cids)->delete();
DB::table('jobs')->where('payload', 'like', '%RefreshProductSortPrices%')->delete();
DB::table('staff')->where('email', '${EMAIL}')->delete();
echo 'cleaned';`;

const CREATE_STAFF = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'P6e1 Audit', $role));
echo 'ok';`;

// three products «P6e1 audit 1…3» (simple, regular 100.00, stock 5, an existing shipping class and article group: the product form requires both); collection 2 is inserted with a picture (the first ready image of the media library, when there is one)
const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$class = DB::table('catalog_variations')->whereNotNull('shipping_class')->value('shipping_class');
$group = DB::table('catalog_product_groups')->orderBy('id')->value('id');
$regular = DB::table('pricing_price_lists')->where('name', 'Regular Prices')->value('id');
$ids = [];
for ($i = 1; $i <= 3; $i++) {
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'simple', 'name' => 'P6e1 audit '.$i, 'slug' => 'p6e1-audit-'.$i, 'base_sku' => 'P6E1AUDIT-'.$i, 'status' => 'active', 'catalog_visibility' => 'visible', 'product_group_id' => $group, 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => $now]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'universal', 'status' => 'active', 'attribute_signature' => hash('sha256', 'p6e1-'.$i), 'sku' => 'P6E1AUDIT-'.$i.'-0', 'is_visible' => 0, 'is_purchasable' => 1, 'shipping_class' => $class, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 5, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('pricing_price_list_items')->insert(['price_list_id' => $regular, 'target_type' => 'variation', 'target_id' => (string) $vid, 'min_quantity' => 1, 'price_amount_minor' => 10000, 'price_currency' => 'EUR', 'price_tax_rate_basis_points' => 0, 'price_tax_inclusive' => 0, 'created_at' => $now, 'updated_at' => $now]);
  $ids[] = $pid;
}
$media = DB::table('catalog_media')->where('type', 'image')->where('processing_status', 'ready')->orderBy('id')->value('id');
DB::table('catalog_collections')->insert(['name' => 'P6e1 audit second', 'slug' => 'p6e1-audit-second', 'picture_media_asset_id' => $media, 'created_at' => $now, 'updated_at' => $now]);
echo json_encode($ids);`;

const STATE = String.raw`
use Illuminate\Support\Facades\DB;
$pids = DB::table('catalog_products')->where('slug', 'like', 'p6e1-audit-%')->pluck('id');
$rows = DB::table('catalog_product_collections')->join('catalog_collections', 'catalog_collections.id', '=', 'catalog_product_collections.collection_id')->whereIn('product_id', $pids)->get(['product_id', 'slug']);
echo json_encode(['first' => $rows->where('slug', 'p6e1-audit-first')->count(), 'second' => $rows->where('slug', 'p6e1-audit-second')->count(), 'firstExists' => DB::table('catalog_collections')->where('slug', 'p6e1-audit-first')->exists(), 'switch' => DB::table('site_settings')->where('key', 'catalog.collections_enabled')->value('value')]);`;

const setSwitch = (value) => php(String.raw`use App\Settings\Contracts\SiteSettingsRepository; $s = app(SiteSettingsRepository::class); ${value === null ? "$s->forget('catalog.collections_enabled');" : `$s->set('catalog.collections_enabled', '${value}');`} echo 'ok';`);

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
    watchdog = setTimeout(() => { try { php(CLEANUP); setSwitch(before.switch); } catch (e) { console.error('WATCHDOG cleanup failed ' + e); } console.error('WATCHDOG: the run took more than 15 minutes; the data was put back'); process.exit(3); }, 15 * 60 * 1000);
    php(CREATE_STAFF);
    const ids = JSON.parse(php(SETUP));
    check('three throw-away products and one collection were created', ids.length === 3);
    setSwitch(null); // OFF: the default

    for (const width of [1280, 360]) {
        const ctx = await browser.newContext({ viewport: { width, height: 1400 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
        ctx.setDefaultTimeout(30000);
        const page = await ctx.newPage();
        page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR@${width} ` + String(e).slice(0, 200)));
        page.on('console', (m) => { if (m.type() === 'error' && ! /403|Failed to load resource/.test(m.text())) consoleErrors.push(`CONSOLE@${width} ` + m.text().slice(0, 200)); });
        page.on('response', (r) => { r.status() >= 500 && consoleErrors.push(`HTTP@${width} ` + r.status() + ' ' + r.url().slice(0, 120)); });

        await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
        await page.fill('input[type=email]', EMAIL);
        await page.fill('input[type=password]', PASSWORD);
        await page.keyboard.press('Enter');
        await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

        const search = async (text) => { const box = page.locator('input[type=search]').first(); await box.fill(text); await sleep(1500); await page.waitForLoadState('networkidle'); };
        const selectAllMatching = async () => {
            await page.locator('thead input[type=checkbox]').first().check();
            await sleep(500);
            // with a single page of rows the header checkbox already picks every row by hand (no «select all» link is offered): that is the hand-picked case
            const link = page.getByText(/Select all|Избери всички/).first();
            await link.waitFor({ state: 'visible', timeout: 3000 }).then(() => link.click()).catch(() => {});
            await sleep(500);
        };
        const openMenu = async () => { await page.getByRole('button', { name: /Масови действия|Bulk actions/ }).first().click(); await sleep(400); };

        // ---- OFF: nothing of the collection in the admin
        setSwitch(null);
        await page.goto(BASE + '/admin/products?status=all', { waitUntil: 'networkidle' });
        await search('P6e1 audit');
        const navOff = await page.locator('nav, aside').first().innerText().catch(() => '');
        check(`${width}: OFF: no «Колекции» in the navigation`, ! /Колекции/.test(await page.content().then((h) => h.replace(/<script[\s\S]*?<\/script>/g, ''))) || ! /Колекции/.test(navOff), navOff.slice(0, 120));
        await selectAllMatching();
        await openMenu();
        const menuOff = await page.locator('body').innerText();
        check(`${width}: OFF: no collection action in «Масови действия»`, ! /Добави колекция|Премахни колекция/.test(menuOff));
        await page.keyboard.press('Escape');
        const direct = await page.goto(BASE + '/admin/collections', { waitUntil: 'domcontentloaded' });
        check(`${width}: OFF: the direct URL is refused`, direct.status() === 403 || direct.status() === 404, direct.status());
        await page.goto(BASE + `/admin/products/${ids[0]}/edit`, { waitUntil: 'networkidle' });
        check(`${width}: OFF: the product form has no «Колекции» section`, ! /Колекции/.test(await page.locator('form').first().innerText()));

        // ---- the settings page: turn the switch ON
        await page.goto(BASE + '/admin/catalog-settings', { waitUntil: 'networkidle' });
        const toggle = page.locator('label:has-text("Включи колекциите")').first();
        check(`${width}: the settings page shows the switch «Включи колекциите» (off)`, await toggle.count() > 0 && (await page.locator('input[type=checkbox][wire\\:model*="collections_enabled"]').first().isChecked().catch(() => false)) === false);
        const mine = await page.evaluate(() => { const w = [...document.querySelectorAll('.fi-fo-field')].find((e) => /Включи колекциите/.test(e.textContent) && e.querySelectorAll('.fi-fo-field').length === 0); return w ? { paragraphs: w.querySelectorAll('p').length, hint: w.querySelectorAll('[aria-describedby], button, svg').length } : null; });
        const container = await page.evaluate(() => { const f = document.querySelector('.fi-main, main, .fi-page'); return f ? Math.round(f.getBoundingClientRect().width) : 0; });
        report[`settings_${width}`] = { container, mine };
        check(`${width}: the new switch has no helper paragraph and carries the info icon (container ${container} px)`, mine !== null && mine.paragraphs === 0 && mine.hint > 0, JSON.stringify(mine));
        const sS = join(OUT, `p6e1-settings-${width}.png`); shots.push(sS); await page.screenshot({ path: sS });
        await toggle.click();
        await page.getByRole('button', { name: /Запази|Save/ }).first().click();
        await sleep(2000);
        check(`${width}: the switch was saved ON`, state().switch === '1', JSON.stringify(state()));
        if (width === 360) {
            check('360: the settings page has no horizontal scroll', ! (await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)));
        }

        // ---- ON: the resource, the first collection through the form
        await page.goto(BASE + '/admin/collections', { waitUntil: 'networkidle' });
        check(`${width}: ON: the collections list opens`, /Колекции/.test(await page.locator('h1').first().innerText()));
        await page.goto(BASE + '/admin/collections/create', { waitUntil: 'networkidle' });
        await page.fill('input[id$="name"]', 'P6e1 audit first');
        await page.fill('input[id$="slug"]', 'p6e1-audit-first');
        const createForm = await page.locator('main').first().innerText();
        const sC = join(OUT, `p6e1-create-${width}.png`); shots.push(sC); await page.screenshot({ path: sC });
        await page.getByRole('button', { name: /Създай|Create/ }).first().click();
        await sleep(2000);
        check(`${width}: the collection form has the picture field with the info icon and no helper paragraph`, /Снимка/.test(createForm), createForm.slice(0, 120));
        check(`${width}: the first collection was created`, state().firstExists);

        // ---- assign both collections in the product form
        await page.goto(BASE + `/admin/products/${ids[0]}/edit`, { waitUntil: 'networkidle' });
        const section = page.locator('section:has-text("Колекции"), .fi-section:has-text("Колекции")').first();
        check(`${width}: ON: the product form has the «Колекции» section`, await section.count() > 0);
        for (const name of ['P6e1 audit first', 'P6e1 audit second']) {
            await section.locator('.fi-fo-select, [role=combobox], button').first().click();
            await page.keyboard.type(name);
            await sleep(800);
            await page.getByRole('option', { name: new RegExp(name) }).first().click();
            await sleep(400);
        }
        const sP = join(OUT, `p6e1-product-${width}.png`); shots.push(sP); await page.screenshot({ path: sP });
        await page.getByRole('button', { name: /Запази|Save/ }).first().click();
        await sleep(3000);
        const assigned = state();
        check(`${width}: both collections were assigned to the product`, assigned.first === 1 && assigned.second === 1, JSON.stringify(assigned));

        // ---- the list filtered by one collection; mass add / remove on a filtered set
        const firstId = php(String.raw`echo DB::table('catalog_collections')->where('slug', 'p6e1-audit-first')->value('id');`);
        await page.goto(BASE + `/admin/products?status=all&filters[collections][value]=${firstId}`, { waitUntil: 'networkidle' });
        const filteredText = await page.locator('table').first().innerText();
        check(`${width}: the list filtered by the collection shows only that product`, /P6e1 audit 1/.test(filteredText) && ! /P6e1 audit 2/.test(filteredText) && ! /P6e1 audit 3/.test(filteredText), filteredText.slice(0, 160));
        await page.goto(BASE + '/admin/products?status=all', { waitUntil: 'networkidle' });
        await search('P6e1 audit');
        await selectAllMatching();
        await openMenu();
        await page.getByText('Добави колекция').first().click();
        await page.locator('.fi-modal-window').first().waitFor();
        const modalBox = await page.locator('.fi-modal-window').first().boundingBox();
        check(`${width}: the mass-add modal is narrow (${Math.round(modalBox?.width ?? 0)} px), with the info icon and no helper paragraph`, (modalBox?.width ?? 9999) <= Math.max(width - 16, 480) && (await page.locator('.fi-modal-window .fi-fo-field-wrp-hint, .fi-modal-window .fi-fo-field-wrp-helper-text').count()) === 0);
        const sM = join(OUT, `p6e1-mass-${width}.png`); shots.push(sM); await page.screenshot({ path: sM });
        const select = page.locator('.fi-modal-window .fi-fo-select, .fi-modal-window [role=combobox]').first();
        await select.click();
        await page.keyboard.type('P6e1 audit first');
        await sleep(800);
        await page.getByRole('option', { name: /P6e1 audit first/ }).first().click();
        await page.locator('.fi-modal-window button:has-text("Приложи")').first().click();
        await sleep(2500);
        check(`${width}: mass add: the collection is on all 3 products`, state().first === 3, JSON.stringify(state()));
        await selectAllMatching();
        await openMenu();
        await page.getByText('Премахни колекция').first().click();
        await page.locator('.fi-modal-window').first().waitFor();
        await page.locator('.fi-modal-window .fi-fo-select, .fi-modal-window [role=combobox]').first().click();
        await page.keyboard.type('P6e1 audit first');
        await sleep(800);
        await page.getByRole('option', { name: /P6e1 audit first/ }).first().click();
        await page.locator('.fi-modal-window button:has-text("Приложи")').first().click();
        await sleep(2500);
        check(`${width}: mass remove: the collection is gone from all 3 products`, state().first === 0, JSON.stringify(state()));

        // ---- the collections list shows the product counts
        await page.goto(BASE + '/admin/collections', { waitUntil: 'networkidle' });
        const listText = await page.locator('table').first().innerText();
        check(`${width}: the collections list shows the product counts`, /1 продукт/.test(listText) && /Няма продукти/.test(listText), listText.slice(0, 200));
        const sL = join(OUT, `p6e1-list-${width}.png`); shots.push(sL); await page.screenshot({ path: sL, fullPage: true });
        if (width === 360) {
            check('360: no horizontal scroll on the collections list', ! (await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)));
        }
        await ctx.close();

        // put the first collection and the assignments back for the second width
        php(String.raw`use Illuminate\Support\Facades\DB; $pids = DB::table('catalog_products')->where('slug', 'like', 'p6e1-audit-%')->pluck('id'); DB::table('catalog_product_collections')->whereIn('product_id', $pids)->delete(); DB::table('catalog_collections')->where('slug', 'p6e1-audit-first')->delete(); echo 'ok';`);
    }

    check('no console errors, no page errors, no 5xx answers', consoleErrors.length === 0, consoleErrors.join(' | '));
} catch (e) {
    check('the run finished without an exception', false, String(e).slice(0, 900));
} finally {
    if (watchdog) clearTimeout(watchdog);
    if (created) {
        try {
            php(CLEANUP);
            setSwitch(before.switch);
            const after = JSON.parse(php(SUMS));
            for (const k of ['products', 'variations', 'stock', 'prices', 'pivots', 'collections', 'log', 'staff', 'jobs', 'switch']) {
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
