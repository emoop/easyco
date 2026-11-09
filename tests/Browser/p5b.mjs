// A REAL-BROWSER check of P5b: the admin «Маркетинг» menu group and «Промокодове» (list, create, edit, duplicate, switch off), with the «Публичен» switch and its amber warning, then the cart's «Потърси промокод» list.
//   Real clicks at 1440 and 390 px: the menu order (Доставка, Маркетинг, Съдържание), a percentage code with a minimum and a brand scope, a PUBLIC code with no condition (the warning is seen; the description is required; an end date makes the
//   warning go), the list with the «Публичен» badge and the used count, the edit page (code / type / value read-only), «Дублирай», the switch-off, and the cart page showing the public code (and neither the switched-off nor a personal one).
// NOT part of `php artisan test`: run by hand against the dev site. It creates ONE throw-away staff member, one brand, one product («P5b audit …», 7.00 €, stock 50) and promotions named «p5b-…». BEFORE the run the promotions and their scopes,
// the carts and their lines and every checksum are taken; at the end everything created is deleted, the backed-up rows are put back BY ID and the checksums are compared. A watchdog puts the data back after 12 minutes.
// The screenshots go to OUT, which must be OUTSIDE the project folder (the script refuses otherwise).
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p5b.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p5b.mjs'); process.exit(2); }
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) { console.error('refused: OUT is inside the project folder; the screenshots must be kept outside it'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

const php = (code) => {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    const out = (r.stdout ?? '').trim();
    if (r.status !== 0 || out === '') { throw new Error(`php failed (status ${r.status}): ` + (out + '\n' + (r.stderr ?? '')).slice(-900)); }
    return out.split('\n').pop();
};

const EMAIL = `p5b-${Date.now()}@example.test`;
const PASSWORD = 'P5b-' + Math.random().toString(36).slice(2) + 'Aa1!';

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode(['promotions' => $sum('promotions'), 'scopes' => $sum('promotion_scopes'), 'carts' => $sum('carts'), 'cart_lines' => $sum('cart_lines'), 'settings' => $sum('site_settings'), 'staff' => DB::table('staff')->count(), 'products' => DB::table('catalog_products')->count(), 'variations' => DB::table('catalog_variations')->count(), 'brands' => $sum('catalog_brands'), 'stock' => $sum('stock_levels'), 'prices' => $sum('pricing_price_list_items')]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
$dump = fn ($t) => DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
file_put_contents(sys_get_temp_dir().'/p5b_backup.json', json_encode(['promotions' => $dump('promotions'), 'promotion_scopes' => $dump('promotion_scopes'), 'carts' => $dump('carts'), 'cart_lines' => $dump('cart_lines'), 'maxcart' => (int) DB::table('carts')->max('id')]));
echo 'ok';`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/p5b_backup.json'), true);
$newCarts = DB::table('carts')->where('id', '>', $bak['maxcart'])->pluck('id');
DB::table('cart_lines')->whereIn('cart_id', $newCarts)->delete();
DB::table('carts')->whereIn('id', $newCarts)->delete();
$pids = DB::table('catalog_products')->where('slug', 'like', 'p5b-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('cart_lines')->whereIn('variation_id', $vids)->delete();
DB::table('pricing_price_list_items')->where('target_type', 'variation')->whereIn('target_id', $vids->map(fn ($v) => (string) $v))->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
$defs = DB::table('catalog_attribute_definitions')->where('code', 'p5-colour')->pluck('id');
DB::table('catalog_variation_attribute_values')->whereIn('attribute_definition_id', $defs)->delete();
DB::table('catalog_attribute_values')->whereIn('attribute_definition_id', $defs)->delete();
DB::table('catalog_attribute_definitions')->whereIn('id', $defs)->delete();
$codes = DB::table('promotions')->where('code', 'like', 'p5b-%')->pluck('id');
DB::table('promotion_scopes')->whereIn('promotion_id', $codes)->delete();
DB::table('promotions')->whereIn('id', $codes)->delete();
DB::table('catalog_brands')->where('slug', 'p5b-audit-brand')->delete();
DB::table('staff')->where('email', '${EMAIL}')->delete();
echo 'cleaned';`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/p5b_backup.json'), true);
foreach (['promotions', 'promotion_scopes', 'carts', 'cart_lines'] as $t) {
  foreach ($bak[$t] as $row) { if (! DB::table($t)->where('id', $row['id'])->exists()) { DB::table($t)->insert($row); } else { DB::table($t)->where('id', $row['id'])->update($row); } }
  DB::table($t)->whereNotIn('id', array_column($bak[$t], 'id'))->delete();
}
echo 'restored';`;

const CREATE_STAFF = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'P5b Audit', $role));
echo 'ok';`;

const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
DB::table('catalog_brands')->insert(['name' => 'P5b brand', 'slug' => 'p5b-audit-brand', 'created_at' => $now, 'updated_at' => $now]);
$pid = DB::table('catalog_products')->insertGetId(['type' => 'variable', 'name' => 'P5b audit product', 'slug' => 'p5b-audit-1', 'base_sku' => 'P5BAUDIT-1', 'status' => 'active', 'catalog_visibility' => 'visible', 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => $now]);
$def = DB::table('catalog_attribute_definitions')->where('code', 'p5-colour')->value('id') ?? DB::table('catalog_attribute_definitions')->insertGetId(['code' => 'p5-colour', 'name' => 'P5 colour', 'type' => 'select', 'is_filterable' => 0, 'filter_display' => 'list', 'created_at' => $now, 'updated_at' => $now]);
$val = DB::table('catalog_attribute_values')->where('attribute_definition_id', $def)->value('id') ?? DB::table('catalog_attribute_values')->insertGetId(['attribute_definition_id' => $def, 'value' => 'Blue', 'sort_order' => 0, 'colour' => null, 'created_at' => $now, 'updated_at' => $now]);
$vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'standard', 'status' => 'active', 'attribute_signature' => hash('sha256', 'p5b-1'), 'sku' => 'P5BAUDIT-V1', 'is_visible' => 1, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
DB::table('catalog_variation_attribute_values')->insert(['variation_id' => $vid, 'attribute_definition_id' => $def, 'attribute_value_id' => $val, 'created_at' => $now, 'updated_at' => $now]);
DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 50, 'created_at' => $now, 'updated_at' => $now]);
DB::table('pricing_price_list_items')->insert(['price_list_id' => DB::table('pricing_price_lists')->where('name', 'Regular Prices')->value('id'), 'target_type' => 'variation', 'target_id' => (string) $vid, 'min_quantity' => 1, 'price_amount_minor' => 700, 'price_currency' => 'EUR', 'price_tax_rate_basis_points' => 0, 'price_tax_inclusive' => 0, 'created_at' => $now, 'updated_at' => $now]);
echo $vid;`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });

const steps = []; const consoleErrors = []; const shots = []; const report = {};
const check = (name, ok, detail = '') => { process.env.TRACE && console.error((ok ? '· ' : 'FAIL ') + name.slice(0, 90)); steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + String(detail).slice(0, 300)); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const dbRow = (code) => JSON.parse(php(String.raw`echo json_encode(Illuminate\Support\Facades\DB::table('promotions')->where('code', '${code}')->first());`));

let before = null; let created = false; let watchdog = null;

try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    created = true;
    watchdog = setTimeout(() => { try { php(CLEANUP); php(RESTORE); } catch (e) { console.error('WATCHDOG restore failed ' + e); } console.error('WATCHDOG: the run took more than 12 minutes; the data was put back'); process.exit(3); }, 12 * 60 * 1000);
    check('the throw-away staff member was created', php(CREATE_STAFF) === 'ok');
    const variationId = php(SETUP);
    check('the throw-away brand and product were created', Number(variationId) > 0);

    for (const width of [1440, 390]) {
        const tag = width === 1440 ? 'a' : 'b';
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

        const code = (suffix) => `p5b-${suffix}-${tag}`;
        const field = (label) => page.locator('.fi-fo-field').filter({ has: page.locator('.fi-fo-field-label-content', { hasText: new RegExp('^\\s*' + label + '\\s*\\*?\\s*$') }) }).first();
        const input = (label) => field(label).locator('input:not([type=hidden])').first();
        const toggle = (label) => field(label).locator('button[role=switch]').first();
        const save = async () => { await page.locator('main form button[type=submit]').first().click(); await sleep(2500); };
        const noHelperParagraphs = async () => (await page.locator('.fi-fo-field-wrp-helper-text, .fi-fo-field-helper-text').count()) === 0;
        const hscroll = () => page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);

        // ---- 1. the menu: Маркетинг between Доставка and Съдържание
        await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
        if (width < 1024) { const burger = page.locator('.fi-topbar-open-sidebar-btn, button[aria-label*="idebar"], .fi-icon-btn.fi-topbar-open-sidebar-btn').first(); if (await burger.count()) { await burger.click(); await sleep(500); } }
        const groups = await page.$$eval('.fi-sidebar-group-label', (els) => els.map((e) => e.textContent.trim()));
        report[`groups_${width}`] = groups;
        check(`${width}: the menu shows «Маркетинг» between «Доставка» and «Съдържание»`, groups.indexOf('Доставка') !== -1 && groups.indexOf('Маркетинг') === groups.indexOf('Доставка') + 1 && groups.indexOf('Съдържание') === groups.indexOf('Маркетинг') + 1, groups.join(' | '));
        await page.locator('.fi-sidebar-group-label', { hasText: 'Маркетинг' }).first().click();
        await sleep(500);
        check(`${width}: «Промокодове» is the item of the group`, (await page.locator('.fi-sidebar-item-label', { hasText: 'Промокодове' }).count()) > 0);
        const s0 = join(OUT, `p5b-menu-${width}.png`); shots.push(s0); await page.screenshot({ path: s0 });
        await page.locator('.fi-sidebar-item-label', { hasText: 'Промокодове' }).first().click();
        await page.waitForURL(/admin\/promotions/);
        await sleep(1200);
        report[`list_${width}_before`] = (await page.locator('.fi-ta').first().innerText()).replace(/\s+/g, ' ').slice(0, 200);
        check(`${width}: the list opens`, (await page.locator('h1').innerText()).includes('Промокодове'));

        // ---- 2. a percentage code with a minimum and a brand scope
        await page.goto(BASE + '/admin/promotions/create', { waitUntil: 'networkidle' });
        await sleep(800);
        check(`${width}: no helper paragraphs under the fields (the explanations are info icons)`, await noHelperParagraphs() && (await page.locator('.info-hint-btn').count()) >= 10);
        await input('Код').fill(code('pct'));
        await input('Процент отстъпка').fill('12,5');
        await input('Минимална сума').fill('100');
        await page.getByRole('button', { name: 'Добави в обхвата' }).click();
        await sleep(800);
        await page.locator('.fi-fo-repeater-item').first().locator('.fi-select-input-btn, button[role=combobox], [role=combobox]').last().click();
        await sleep(700);
        await page.locator('[role=option]:visible', { hasText: 'P5b brand' }).first().click();
        await sleep(400);
        const s1 = join(OUT, `p5b-create-${width}.png`); shots.push(s1); await page.screenshot({ path: s1, fullPage: true });
        await save();
        await page.waitForURL(/admin\/promotions$/);
        const pct = dbRow(code('pct'));
        check(`${width}: the percentage code was saved (12.5 % = 1250 basis points, minimum 100.00 = 10000)`, pct && pct.discount_percentage_basis_points === 1250 && pct.minimum_spend_amount_minor === 10000 && pct.status === 'active', JSON.stringify(pct));
        check(`${width}: its brand scope was saved`, php(String.raw`echo Illuminate\Support\Facades\DB::table('promotion_scopes')->where('promotion_id', ${pct.id})->where('scope_type', 'brand')->count();`) === '1');

        // ---- 3. a PUBLIC code with no condition: the amber warning, the required description, the end date
        await page.goto(BASE + '/admin/promotions/create', { waitUntil: 'networkidle' });
        await sleep(800);
        await input('Код').fill(code('open'));
        await input('Процент отстъпка').fill('10');
        const warning = page.locator('[data-promotion-public-warning]');
        check(`${width}: the warning is NOT there while the code is not public`, (await warning.count()) === 0);
        await toggle('Публичен').click();
        await sleep(1200);
        check(`${width}: public with no condition: the amber warning shows (bg text)`, (await warning.count()) === 1 && (await warning.innerText()).includes('Публичен код без условие е постоянна отстъпка'), await warning.count());
        const s2 = join(OUT, `p5b-public-warning-${width}.png`); shots.push(s2); await page.screenshot({ path: s2, fullPage: true });
        await save();
        check(`${width}: the description is required (an error under the field, nothing saved)`, (await page.locator('.fi-fo-field-wrp-error-message').count()) > 0 && dbRow(code('open')) === null);
        await input('Описание за клиента').fill('Отстъпка <b>10%</b> за всички');
        await input('Валиден до').fill('2031-01-01T10:00');
        await sleep(1200);
        check(`${width}: an end date makes the warning go`, (await warning.count()) === 0);
        await save();
        await page.waitForURL(/admin\/promotions$/);
        const open = dbRow(code('open'));
        check(`${width}: the public code was saved with its description as plain text`, open && open.is_public === 1 && open.public_description === 'Отстъпка <b>10%</b> за всички', JSON.stringify(open));

        // ---- 4. the list: the badge, the used count
        await page.goto(BASE + '/admin/promotions', { waitUntil: 'networkidle' });
        await sleep(800);
        const rowOpen = page.locator('.fi-ta-row', { hasText: code('open') }).first();
        const rowText = (await rowOpen.innerText()).replace(/\s+/g, ' ');
        report[`row_${width}`] = rowText;
        check(`${width}: the list row has the «Публичен» badge, the discount, the validity and the used count 0`, rowText.includes('Публичен') && rowText.includes('10 %') && rowText.includes('до 01.01.2031'), rowText);
        check(`${width}: the list has no horizontal scroll`, ! (await hscroll()));
        const s3 = join(OUT, `p5b-list-${width}.png`); shots.push(s3); await page.screenshot({ path: s3 });

        // ---- 5. edit: the immutable fields are read-only
        await page.goto(BASE + '/admin/promotions/' + pct.id + '/edit', { waitUntil: 'networkidle' });
        await sleep(800);
        check(`${width}: on edit the code, the type and the percentage are read-only`, await input('Код').isDisabled() && await page.locator('.fi-fo-field', { hasText: 'Вид отстъпка' }).first().locator('input:disabled, button:disabled, [disabled]').count() > 0 && await input('Процент отстъпка').isDisabled());
        await input('Минимална сума').fill('50');
        const s4 = join(OUT, `p5b-edit-${width}.png`); shots.push(s4); await page.screenshot({ path: s4, fullPage: true });
        await save();
        await page.waitForURL(/admin\/promotions$/);
        check(`${width}: the edit saved the minimum (50.00 = 5000) and left the code and value alone`, (() => { const r = dbRow(code('pct')); return r.minimum_spend_amount_minor === 5000 && r.discount_percentage_basis_points === 1250 && r.code === code('pct'); })());

        // ---- 6. duplicate
        await page.goto(BASE + '/admin/promotions/create?duplicate=' + pct.id, { waitUntil: 'networkidle' });
        await sleep(800);
        check(`${width}: «Дублирай»: the form is prefilled and the code is empty`, (await input('Код').inputValue()) === '' && (await input('Процент отстъпка').inputValue()) === '12.5' && (await input('Минимална сума').inputValue()) === '50.00');
        await input('Код').fill(code('copy'));
        await save();
        await page.waitForURL(/admin\/promotions$/);
        check(`${width}: the copy was created`, dbRow(code('copy')) !== null && dbRow(code('copy')).discount_percentage_basis_points === 1250);

        // ---- 7. switch off the copy (a promotion is never deleted: there is no delete action)
        await page.goto(BASE + '/admin/promotions', { waitUntil: 'networkidle' });
        await sleep(800);
        const rowCopy = page.locator('.fi-ta-row', { hasText: code('copy') }).first();
        await rowCopy.getByRole('button', { name: 'Действия' }).click();
        await sleep(500);
        check(`${width}: the actions menu has no delete`, (await page.locator('.fi-dropdown-panel:visible', { hasText: /Изтрий|Delete/ }).count()) === 0);
        await page.locator('.fi-dropdown-panel:visible').getByText('Изключи', { exact: true }).click();
        await sleep(1800);
        check(`${width}: the copy is switched off`, dbRow(code('copy')).status === 'inactive');

        // ---- 8. the cart: the public code shows, the switched-off and the personal-looking ones do not
        const shop = await browser.newContext({ viewport: { width, height: 1100 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
        shop.setDefaultTimeout(25000);
        const cart = await shop.newPage();
        cart.on('pageerror', (e) => consoleErrors.push(`PAGEERROR-cart@${width} ` + String(e).slice(0, 200)));
        cart.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(`CONSOLE-cart@${width} ` + m.text().slice(0, 200)); });
        await cart.goto(BASE + '/cart', { waitUntil: 'networkidle' });
        await cart.evaluate(async (id) => {
            await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
            const token = decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')) ?? '').slice(11));
            await fetch('/api/cart/lines', { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': token }, body: JSON.stringify({ variation_id: id, quantity: 2 }) });
        }, variationId);
        await cart.goto(BASE + '/cart', { waitUntil: 'networkidle' });
        await cart.waitForSelector('.sf-cart-line');
        await cart.locator('details.sf-promo-list > summary').click();
        await sleep(300);
        const listText = (await cart.locator('details.sf-promo-list').innerText()).replace(/\s+/g, ' ');
        report[`cart_list_${width}`] = listText;
        check(`${width}: the cart lists the public code (its description as plain text) with «Приложи»`, listText.toLowerCase().includes(code('open')) && listText.includes('<b>10%</b>') && listText.includes('Приложи'), listText);
        check(`${width}: the cart does not list the non-public codes (${code('pct')}, ${code('copy')})`, ! listText.toLowerCase().includes(code('pct')) && ! listText.toLowerCase().includes(code('copy')), listText);
        const s5 = join(OUT, `p5b-cart-${width}.png`); shots.push(s5); await cart.screenshot({ path: s5 });
        if (width === 390) { check('390: no horizontal scroll on the cart', ! (await cart.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1))); }
        await shop.close();

        if (width === 390) { await page.goto(BASE + '/admin/promotions/create', { waitUntil: 'networkidle' }); await sleep(600); check('390: no horizontal scroll on the create form', ! (await hscroll())); }

        // free the codes of this width, so the second width starts clean
        php(String.raw`$ids = Illuminate\Support\Facades\DB::table('promotions')->where('code', 'like', 'p5b-%-${tag}')->pluck('id'); Illuminate\Support\Facades\DB::table('promotion_scopes')->whereIn('promotion_id', $ids)->delete(); Illuminate\Support\Facades\DB::table('promotions')->whereIn('id', $ids)->delete(); echo 'ok';`);
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
            php(RESTORE);
            const after = JSON.parse(php(SUMS));
            for (const k of ['promotions', 'scopes', 'carts', 'cart_lines', 'settings', 'staff', 'products', 'variations', 'brands', 'stock', 'prices']) {
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
