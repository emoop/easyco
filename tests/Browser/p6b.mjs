// A REAL-BROWSER check of P6b: the admin «Ценови листове» (percent-off campaigns) and what the storefront category shows for them.
//   Admin at 1280 and 360 px: the menu item after «Промокодове»; the list with the system rows and the status badges; a −30% campaign scoped to ONE tag, with and without «Замества ръчната промо цена», on a product that has a
//   Manual Sale price (85 € of 100 €) and one that has not; the storefront category card prices (the struck regular price included) and the sort by price; −10% replacing; switching the campaign off from the list; the amber
//   warning and the confirmation of a campaign with NO scope. It MEASURES the form container and the fields' layout (two per row wide, one per row narrow), and counts helper paragraphs, horizontal scroll and console errors.
// NOT part of `php artisan test`: run by hand against the dev site. It creates ONE throw-away staff member, one category, one tag, two products («P6b audit …») with prices and stock, and campaigns named «p6b …». BEFORE the run
// the price lists, their scopes and items, the products, the activity log, the settings and the queue are checksummed; at the end everything created is deleted and the checksums are compared. A watchdog cleans up after 14 minutes.
// The screenshots go to OUT, which must be OUTSIDE the project folder (the script refuses otherwise).
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p6b.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p6b.mjs'); process.exit(2); }
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

const EMAIL = `p6b-${Date.now()}@example.test`;
const PASSWORD = 'P6b-' + Math.random().toString(36).slice(2) + 'Aa1!';

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode(['lists' => $sum('pricing_price_lists'), 'scopes' => $sum('pricing_price_list_scopes'), 'items' => $sum('pricing_price_list_items'), 'products' => $sum('catalog_products'), 'variations' => $sum('catalog_variations'), 'stock' => $sum('stock_levels'), 'tags' => $sum('catalog_tags'), 'categories' => $sum('catalog_categories'), 'pivots' => $sum('catalog_product_categories').$sum('catalog_product_tags'), 'log' => $sum('activity_log'), 'settings' => $sum('site_settings'), 'staff' => DB::table('staff')->count(), 'jobs' => DB::table('jobs')->count(), 'maxlog' => (int) DB::table('activity_log')->max('id')]);`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$lists = DB::table('pricing_price_lists')->where('is_system', 0)->where('name', 'like', 'p6b %')->pluck('id');
DB::table('pricing_price_list_scopes')->whereIn('price_list_id', $lists)->delete();
DB::table('pricing_price_lists')->whereIn('id', $lists)->delete();
$pids = DB::table('catalog_products')->where('slug', 'like', 'p6b-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('pricing_price_list_items')->where('target_type', 'variation')->whereIn('target_id', $vids->map(fn ($v) => (string) $v))->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_tags')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
DB::table('catalog_categories')->where('slug', 'p6b-audit-cat')->delete();
DB::table('catalog_tags')->where('slug', 'p6b-audit-tag')->delete();
DB::table('staff')->where('email', '${EMAIL}')->delete();
DB::table('jobs')->where('payload', 'like', '%RefreshProductSortPrices%')->delete();
echo 'cleaned';`;

// activity_log: only this run's own rows (id above the start maximum, all entity types); never with an unknown or 0 maximum (that would match the whole log)
const CLEAN_LOG = (maxId) => {
    if (! (maxId > 0)) { console.error('WARNING: activity_log not cleaned up: start maximum is ' + maxId + ' (unknown, or the log was empty at start); no rows deleted'); return null; }
    return String.raw`
use Illuminate\Support\Facades\DB;
DB::table('activity_log')->where('id', '>', ${maxId})->delete();
echo 'ok';`;
};

const CREATE_STAFF = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'P6b Audit', $role));
echo 'ok';`;

// product M: regular 100.00 + Manual Sale 85.00; product R: regular 100.00 only; both tagged and in the category
const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$cat = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'P6b audit category', 'slug' => 'p6b-audit-cat', 'created_at' => $now, 'updated_at' => $now]);
$tag = DB::table('catalog_tags')->insertGetId(['name' => 'P6b audit tag', 'slug' => 'p6b-audit-tag', 'created_at' => $now, 'updated_at' => $now]);
$regular = DB::table('pricing_price_lists')->where('name', 'Regular Prices')->value('id');
$sale = DB::table('pricing_price_lists')->where('name', 'Manual Sale')->value('id');
$ids = [];
foreach (['m' => 8500, 'r' => null] as $n => $manual) {
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'simple', 'name' => 'P6b audit '.strtoupper($n), 'slug' => 'p6b-audit-'.$n, 'base_sku' => 'P6BAUDIT-'.$n, 'status' => 'active', 'catalog_visibility' => 'visible', 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => $now]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  DB::table('catalog_product_tags')->insert(['product_id' => $pid, 'tag_id' => $tag]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'universal', 'status' => 'active', 'attribute_signature' => hash('sha256', 'p6b-'.$n), 'sku' => 'P6BAUDIT-'.$n, 'is_visible' => 1, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 5, 'created_at' => $now, 'updated_at' => $now]);
  $row = fn ($list, $minor) => ['price_list_id' => $list, 'target_type' => 'variation', 'target_id' => (string) $vid, 'min_quantity' => 1, 'price_amount_minor' => $minor, 'price_currency' => 'EUR', 'price_tax_rate_basis_points' => 0, 'price_tax_inclusive' => 0, 'created_at' => $now, 'updated_at' => $now];
  DB::table('pricing_price_list_items')->insert($row($regular, 10000));
  if ($manual !== null) { DB::table('pricing_price_list_items')->insert($row($sale, $manual)); }
  $ids[$n] = $vid;
}
echo json_encode($ids);`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });

const steps = []; const consoleErrors = []; const shots = []; const report = {};
const check = (name, ok, detail = '') => { process.env.TRACE && console.error((ok ? '· ' : 'FAIL ') + name.slice(0, 90)); steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + String(detail).slice(0, 300)); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const CAT_URL = BASE + '/product-category/p6b-audit-cat';
const listId = (name) => Number(php(String.raw`echo (int) Illuminate\Support\Facades\DB::table('pricing_price_lists')->where('name', '${name}')->value('id');`));

let before = null; let created = false; let watchdog = null;

try {
    before = JSON.parse(php(SUMS));
    created = true;
    watchdog = setTimeout(() => { try { php(CLEANUP); const cl = CLEAN_LOG(before ? before.maxlog : 0); if (cl) { php(cl); } } catch (e) { console.error('WATCHDOG cleanup failed ' + e); } console.error('WATCHDOG: the run took more than 14 minutes; the data was cleaned up'); process.exit(3); }, 14 * 60 * 1000);
    check('the throw-away staff member was created', php(CREATE_STAFF) === 'ok');
    check('the throw-away category, tag and two products were created', Object.keys(JSON.parse(php(SETUP))).length === 2);
    artisan(['prices:refresh-from']);

    // what the category page prints per card, in the page's order: [name, current price number, struck regular price number or null]
    const cards = async (page, sort = '') => {
        await page.goto(CAT_URL + sort, { waitUntil: 'networkidle' });

        return page.$$eval('.sf-card', (nodes) => nodes.map((n) => {
            const num = (el) => { const m = (el?.textContent ?? '').replace(/\s+/g, ' ').match(/\d+[.,]\d{2}/); return m ? Number(m[0].replace(',', '.')) : null; };
            const current = [...n.querySelectorAll('.sf-price-current')].at(-1);

            return [(n.querySelector('.sf-card-name')?.textContent ?? '').trim().replace('P6b audit ', ''), num(current), num(n.querySelector('.sf-price-regular, s, del'))];
        }));
    };
    const sync = () => artisan(['queue:work', '--stop-when-empty', '--tries=1', '--quiet']);

    for (const width of [1280, 360]) {
        const narrow = width < 700;
        const ctx = await browser.newContext({ viewport: { width, height: 1500 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
        ctx.setDefaultTimeout(25000);
        const page = await ctx.newPage();
        page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR@${width} ` + String(e).slice(0, 200)));
        page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(`CONSOLE@${width} ` + m.text().slice(0, 200)); });
        page.on('response', (r) => { r.status() >= 500 && consoleErrors.push(`HTTP@${width} ` + r.status() + ' ' + r.url().slice(0, 120)); });

        await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
        await page.fill('input[type=email]', EMAIL);
        await page.fill('input[type=password]', PASSWORD);
        await page.keyboard.press('Enter');
        await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

        const field = (label) => page.locator('.fi-fo-field').filter({ has: page.locator('.fi-fo-field-label-content', { hasText: new RegExp('^\\s*' + label + '\\s*\\*?\\s*$') }) }).first();
        const input = (label) => field(label).locator('input:not([type=hidden])').first();
        const toggle = (label) => field(label).locator('button[role=switch]').first();
        const save = async () => { await page.locator('main form button[type=submit]').first().click(); await sleep(2500); };
        const hscroll = () => page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
        const name = (suffix) => `p6b ${suffix} ${width}`;

        // ---- the menu
        await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
        if (narrow) { const burger = page.locator('.fi-topbar-open-sidebar-btn').first(); if (await burger.count()) { await burger.click(); await sleep(500); } }
        await page.locator('.fi-sidebar-group-label', { hasText: 'Маркетинг' }).first().click();
        await sleep(500);
        const items = await page.$$eval('.fi-sidebar-item-label', (els) => els.map((e) => e.textContent.trim()));
        const at = items.indexOf('Промокодове');
        check(`${width}: «Ценови листове» is in the group, right after «Промокодове»`, at !== -1 && items[at + 1] === 'Ценови листове', items.join(' | '));
        await page.goto(BASE + '/admin/price-lists', { waitUntil: 'networkidle' });
        await sleep(800);
        const listText = (await page.locator('.fi-ta').first().innerText()).replace(/\s+/g, ' ');
        check(`${width}: the list shows the two system lists as «Системен», read-only`, listText.includes('Regular Prices') && listText.includes('Manual Sale') && listText.includes('Системен'), listText.slice(0, 300));

        // ---- the empty scope: the amber warning and the confirmation (nothing is saved)
        await page.goto(BASE + '/admin/price-lists/create', { waitUntil: 'networkidle' });
        await sleep(800);
        check(`${width}: no scope: the amber warning is visible text and the confirmation switch is there`, (await page.locator('[data-price-list-all-products-warning]').count()) === 1 && (await toggle('Потвърждавам, че важи за всички продукти').count()) === 1);
        await input('Име').fill(name('empty'));
        await input('Отстъпка \\(%\\)').fill('10');
        await save();
        check(`${width}: saving with no scope and no confirmation is refused with an error (nothing saved)`, (await page.locator('.fi-fo-field-wrp-error-message').count()) > 0 && listId(name('empty')) === 0);
        // ---- the layout: measure the container and the rule that applied
        const layout = await page.evaluate(() => {
            const box = (el) => el?.getBoundingClientRect();
            const section = document.querySelector('.fi-sc-section, .fi-section');
            const fields = [...document.querySelectorAll('.fi-fo-field')].filter((f) => f.offsetParent !== null);
            const pct = fields.find((f) => f.textContent.includes('Отстъпка (%)'));
            const active = fields.find((f) => /Активна/.test(f.querySelector('.fi-fo-field-label-content')?.textContent ?? ''));

            return { sectionWidth: Math.round(box(section)?.width ?? 0), pctTop: Math.round(box(pct)?.top ?? 0), activeTop: Math.round(box(active)?.top ?? 0), helperParagraphs: document.querySelectorAll('.fi-fo-field-wrp-helper-text, .fi-fo-field-helper-text').length, infoIcons: document.querySelectorAll('.info-hint-btn').length };
        });
        report[`layout_${width}`] = layout;
        const twoPerRow = Math.abs(layout.pctTop - layout.activeTop) < 6;
        check(`${width}: the container is ${layout.sectionWidth}px: ${narrow ? 'ONE field per row' : 'TWO short fields per row'} (the percent and the active switch ${twoPerRow ? 'share a row' : 'are on separate rows'})`, narrow ? ! twoPerRow : twoPerRow, JSON.stringify(layout));
        check(`${width}: no helper paragraphs under the fields; the explanations are info icons`, layout.helperParagraphs === 0 && layout.infoIcons >= 6, JSON.stringify(layout));
        check(`${width}: no horizontal scroll on the create form`, ! (await hscroll()));
        const s0 = join(OUT, `p6b-create-empty-scope-${width}.png`); shots.push(s0); await page.screenshot({ path: s0, fullPage: true });

        // ---- a −30% campaign scoped to the tag, NOT replacing the manual sale price
        await page.goto(BASE + '/admin/price-lists/create', { waitUntil: 'networkidle' });
        await sleep(800);
        await input('Име').fill(name('thirty'));
        await input('Отстъпка \\(%\\)').fill('30');
        const tagBox = field('Таг').locator('.fi-select-input-btn, button[role=combobox], [role=combobox]').first();
        await tagBox.click();
        await sleep(600);
        await page.locator('[role=option]:visible', { hasText: 'P6b audit tag' }).first().click();
        await sleep(500);
        await page.keyboard.press('Escape');
        check(`${width}: with a scope chosen the amber warning and the confirmation are gone`, (await page.locator('[data-price-list-all-products-warning]').count()) === 0);
        await save();
        await page.waitForURL(/admin\/price-lists$/);
        const id = listId(name('thirty'));
        check(`${width}: the campaign was saved (priority 500, 30%, scoped to the tag)`, id > 0 && php(String.raw`$l = Illuminate\Support\Facades\DB::table('pricing_price_lists')->find(${id}); echo $l->priority.'|'.$l->percentage_basis_points.'|'.$l->replaces_manual_sale.'|'.Illuminate\Support\Facades\DB::table('pricing_price_list_scopes')->where('price_list_id', ${id})->count();`) === '500|3000|0|1');
        sync();

        let seen = await cards(page);
        report[`cards_off_${width}`] = seen;
        const byName = (list) => Object.fromEntries(list.map(([n, p, r]) => [n, { price: p, regular: r }]));
        check(`${width}: OFF: the product WITH a manual sale price keeps 85.00; the one without gets the campaign 70.00 (100.00 struck)`, byName(seen).M?.price === 85 && byName(seen).R?.price === 70 && byName(seen).R?.regular === 100, JSON.stringify(seen));
        seen = await cards(page, '?sort=price_asc');
        check(`${width}: sorted by price ascending: R 70.00 before M 85.00`, JSON.stringify(seen.map((c) => c[0])) === JSON.stringify(['R', 'M']), JSON.stringify(seen));
        const s1 = join(OUT, `p6b-storefront-off-${width}.png`); shots.push(s1); await page.screenshot({ path: s1 });

        // ---- the switch ON: the manual sale product gets the lower price, 70.00
        await page.goto(BASE + `/admin/price-lists/${id}/edit`, { waitUntil: 'networkidle' });
        await sleep(800);
        const affects = (await page.locator('[data-price-list-affects]').innerText()).trim();
        check(`${width}: the edit page says «Засяга 2 продукта»`, affects === 'Засяга 2 продукта', affects);
        await toggle('Замества ръчната промо цена').click();
        await sleep(500);
        const s2 = join(OUT, `p6b-edit-${width}.png`); shots.push(s2); await page.screenshot({ path: s2, fullPage: true });
        await save();
        await page.waitForURL(/admin\/price-lists$/);
        sync();
        seen = await cards(page);
        check(`${width}: ON: both products are 70.00 (the lower of the manual 85.00 and the campaign 70.00)`, byName(seen).M?.price === 70 && byName(seen).R?.price === 70, JSON.stringify(seen));

        // ---- −10% ON: the manual sale price 85.00 is lower than 90.00 and stays
        await page.goto(BASE + `/admin/price-lists/${id}/edit`, { waitUntil: 'networkidle' });
        await sleep(800);
        await input('Отстъпка \\(%\\)').fill('10');
        await save();
        await page.waitForURL(/admin\/price-lists$/);
        sync();
        seen = await cards(page);
        check(`${width}: −10% ON: M stays 85.00 (it can never be raised), R is 90.00`, byName(seen).M?.price === 85 && byName(seen).R?.price === 90, JSON.stringify(seen));

        // ---- the list: badges, then switch OFF from the list: the prices return
        await page.goto(BASE + '/admin/price-lists', { waitUntil: 'networkidle' });
        await sleep(800);
        const row = page.locator('.fi-ta-row', { hasText: name('thirty') }).first();
        const rowText = (await row.innerText()).replace(/\s+/g, ' ');
        report[`row_${width}`] = rowText;
        check(`${width}: the list row shows 10%, «Таг: P6b audit tag», the replace mark and «Активна»`, rowText.includes('10%') && rowText.includes('Таг: P6b audit tag') && rowText.includes('Замества ръчна промо') && rowText.includes('Активна'), rowText);
        check(`${width}: the list has no horizontal scroll`, ! (await hscroll()));
        const s3 = join(OUT, `p6b-list-${width}.png`); shots.push(s3); await page.screenshot({ path: s3 });
        await row.getByRole('button', { name: 'Действия' }).click();
        await sleep(500);
        check(`${width}: the actions menu has no delete`, (await page.locator('.fi-dropdown-panel:visible', { hasText: /Изтрий|Delete/ }).count()) === 0);
        await page.locator('.fi-dropdown-panel:visible').getByText('Изключи', { exact: true }).click();
        await sleep(2000);
        sync();
        seen = await cards(page);
        check(`${width}: switched off: M 85.00 and R 100.00 again`, byName(seen).M?.price === 85 && byName(seen).R?.price === 100, JSON.stringify(seen));

        php(String.raw`$ids = Illuminate\Support\Facades\DB::table('pricing_price_lists')->where('is_system', 0)->where('name', 'like', 'p6b % ${width}')->pluck('id'); Illuminate\Support\Facades\DB::table('pricing_price_list_scopes')->whereIn('price_list_id', $ids)->delete(); Illuminate\Support\Facades\DB::table('pricing_price_lists')->whereIn('id', $ids)->delete(); echo 'ok';`);
        await ctx.close();
    }

    check('no console errors, no page errors, no 5xx answers', consoleErrors.length === 0, consoleErrors.join(' | '));
} catch (e) {
    check('the run finished without an exception', false, String(e).slice(0, 900));
} finally {
    if (watchdog) clearTimeout(watchdog);
    if (created) {
        try {
            php(CLEANUP);
            const cl = CLEAN_LOG(before.maxlog);
            if (cl) { php(cl); }
            const after = JSON.parse(php(SUMS));
            for (const k of ['lists', 'scopes', 'items', 'products', 'variations', 'stock', 'tags', 'categories', 'pivots', 'log', 'settings', 'staff', 'jobs']) {
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
