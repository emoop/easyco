// A REAL-BROWSER check of P5: the cart page with a 10-line cart (the quantity control respects `max_quantity` from the stock), and the «Потърси промокод» list of PUBLIC promo codes
//   (two public codes: one with a 100 € minimum, one with no condition; one PERSONAL code that must appear nowhere), at 1440 and 390: the hint below the minimum (no button), the «Приложи» button
//   once the cart reaches the minimum, a click on «Приложи» (the totals change, the row says «Приложен»), no console errors, no horizontal scroll at 390.
// NOT part of `php artisan test`: run by hand against the dev site. It creates 10 throw-away products («P5 audit …», prices 7.00 €, stock 2, 3, 4 and 50 for the rest) and 3 promotions. BEFORE the run the promotions, the carts
// and their lines are backed up (by id) and every checksum is taken; at the end everything created is deleted, the backed-up rows are put back BY ID and the checksums are compared. A watchdog puts the data back after 12 minutes.
// The screenshots go to OUT, which must be OUTSIDE the project folder (the script refuses otherwise).
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p5.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p5.mjs'); process.exit(2); }
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) { console.error('refused: OUT is inside the project folder; the screenshots must be kept outside it'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

const php = (code) => {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    const out = (r.stdout ?? '').trim();
    if (r.status !== 0 || out === '') { throw new Error(`php failed (status ${r.status}): ` + (out + '\n' + (r.stderr ?? '')).slice(-900)); }
    return out.split('\n').pop();
};

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode(['promotions' => $sum('promotions'), 'carts' => $sum('carts'), 'cart_lines' => $sum('cart_lines'), 'settings' => $sum('site_settings'), 'products' => DB::table('catalog_products')->count(), 'variations' => DB::table('catalog_variations')->count(), 'stock' => $sum('stock_levels'), 'prices' => $sum('pricing_price_list_items'), 'attrs' => DB::table('catalog_attribute_definitions')->count(), 'maxcart' => (int) DB::table('carts')->max('id')]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/p5_backup.json', json_encode(['promotions' => DB::table('promotions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'carts' => DB::table('carts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'cart_lines' => DB::table('cart_lines')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'maxcart' => (int) DB::table('carts')->max('id')]));
echo 'ok';`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/p5_backup.json'), true);
$newCarts = DB::table('carts')->where('id', '>', $bak['maxcart'])->pluck('id');
DB::table('cart_lines')->whereIn('cart_id', $newCarts)->delete();
DB::table('carts')->whereIn('id', $newCarts)->delete();
$pids = DB::table('catalog_products')->where('slug', 'like', 'p5-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('cart_lines')->whereIn('variation_id', $vids)->delete();
DB::table('pricing_price_list_items')->where('target_type', 'variation')->whereIn('target_id', $vids)->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
$defs = DB::table('catalog_attribute_definitions')->where('code', 'p5-colour')->pluck('id');
DB::table('catalog_attribute_values')->whereIn('attribute_definition_id', $defs)->delete();
DB::table('catalog_attribute_definitions')->whereIn('id', $defs)->delete();
DB::table('promotions')->whereIn('code', ['p5-min100', 'p5-open', 'p5-personal'])->delete();
echo 'cleaned';`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/p5_backup.json'), true);
foreach (['promotions', 'carts', 'cart_lines'] as $t) {
  foreach ($bak[$t] as $row) { if (! DB::table($t)->where('id', $row['id'])->exists()) { DB::table($t)->insert($row); } else { DB::table($t)->where('id', $row['id'])->update($row); } }
  DB::table($t)->whereNotIn('id', array_column($bak[$t], 'id'))->delete();
}
echo 'restored';`;

const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$def = DB::table('catalog_attribute_definitions')->insertGetId(['code' => 'p5-colour', 'name' => 'P5 цвят', 'type' => 'select', 'is_filterable' => 0, 'filter_display' => 'list', 'created_at' => $now, 'updated_at' => $now]);
$val = DB::table('catalog_attribute_values')->insertGetId(['attribute_definition_id' => $def, 'value' => 'Син', 'sort_order' => 0, 'colour' => null, 'created_at' => $now, 'updated_at' => $now]);
$stock = [2, 3, 4, 50, 50, 50, 50, 50, 50, 50];
$ids = [];
foreach ($stock as $i => $qty) {
  $n = $i + 1;
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'variable', 'name' => 'P5 audit продукт '.$n, 'slug' => 'p5-audit-'.$n, 'base_sku' => 'P5AUDIT-'.$n, 'status' => 'active', 'catalog_visibility' => 'visible', 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => now()->subMinutes($n)]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'standard', 'status' => 'active', 'attribute_signature' => hash('sha256', "p5-$n"), 'sku' => "P5AUDIT-V$n", 'is_visible' => 1, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_variation_attribute_values')->insert(['variation_id' => $vid, 'attribute_definition_id' => $def, 'attribute_value_id' => $val, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => $qty, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('pricing_price_list_items')->insert(['price_list_id' => DB::table('pricing_price_lists')->where('name', 'Regular Prices')->value('id'), 'target_type' => 'variation', 'target_id' => (string) $vid, 'min_quantity' => 1, 'price_amount_minor' => 700, 'price_currency' => 'EUR', 'price_tax_rate_basis_points' => 0, 'price_tax_inclusive' => 0, 'created_at' => $now, 'updated_at' => $now]);
  $ids[] = (string) $vid;
}
$base = ['discount_type' => 'percentage', 'discount_percentage_basis_points' => 1000, 'individual_use_only' => 0, 'exclude_sale_items' => 0, 'new_customers_only' => 0, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
DB::table('promotions')->insert($base + ['code' => 'p5-min100', 'minimum_spend_amount_minor' => 10000, 'minimum_spend_amount_currency' => 'EUR', 'is_public' => 1, 'public_description' => '10% при поръчка над 100 €']);
DB::table('promotions')->insert($base + ['code' => 'p5-open', 'is_public' => 1, 'public_description' => 'Отстъпка <b>10%</b> за всички']);
DB::table('promotions')->insert($base + ['code' => 'p5-personal', 'is_public' => 0]);
echo json_encode($ids);`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });

const steps = []; const consoleErrors = []; const shots = []; const report = {};
const check = (name, ok, detail = '') => { process.env.TRACE && console.error((ok ? '· ' : 'FAIL ') + name.slice(0, 90)); steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + String(detail).slice(0, 300)); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let before = null; let created = false; let watchdog = null;

try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    created = true;
    watchdog = setTimeout(() => { try { php(CLEANUP); php(RESTORE); } catch (e) { console.error('WATCHDOG restore failed ' + e); } console.error('WATCHDOG: the run took more than 12 minutes; the data was put back'); process.exit(3); }, 12 * 60 * 1000);
    const ids = JSON.parse(php(SETUP));
    check('10 throw-away products and 3 promotions were created', ids.length === 10);

    for (const width of [1440, 390]) {
        const ctx = await browser.newContext({ viewport: { width, height: 1100 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
        ctx.setDefaultTimeout(25000);
        const page = await ctx.newPage();
        page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR@${width} ` + String(e).slice(0, 200)));
        page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(`CONSOLE@${width} ` + m.text().slice(0, 200)); });
        page.on('response', (r) => { r.status() >= 500 && consoleErrors.push(`HTTP@${width} ` + r.status() + ' ' + r.url().slice(0, 120)); });

        const post = (path, method, body) => page.evaluate(async ({ path, method, body }) => {
            await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
            const token = decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')) ?? '').slice(11));
            const r = await fetch(path, { method, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': token }, body: JSON.stringify(body) });
            return r.status;
        }, { path, method, body });

        await page.goto(BASE + '/cart', { waitUntil: 'networkidle' });
        for (const id of ids) { await post('/api/cart/lines', 'POST', { variation_id: id, quantity: 1 }); }
        await page.goto(BASE + '/cart', { waitUntil: 'networkidle' });
        await page.waitForSelector('.sf-cart-line');

        const maxes = await page.$$eval('.sf-cart-qty', (inputs) => inputs.map((i) => Number(i.getAttribute('max'))));
        report[`max_quantity_${width}`] = maxes;
        check(`${width}: ten lines, the quantity control stops at the stock (2, 3, 4, then 50)`, maxes.length === 10 && maxes.slice(0, 3).sort((a, b) => a - b).join() === '2,3,4' && maxes.slice(3).every((m) => m === 50), maxes.join());

        // the list: closed link, two public codes, never the personal one
        const link = page.locator('details.sf-promo-list > summary');
        check(`${width}: the «Потърси промокод» link is there, the list is closed`, (await link.count()) === 1 && ['Потърси промокод', 'Find a promo code'].includes((await link.innerText()).trim()) && ! (await page.locator('details.sf-promo-list').evaluate((d) => d.open)));
        await link.click();
        await sleep(300);
        const rows = () => page.$$eval('li.sf-promo-list-item', (lis) => lis.map((li) => ({ text: li.innerText, button: !! li.querySelector('.sf-promo-list-apply'), cls: li.className })));
        let list = await rows();
        report[`list_${width}_70eur`] = list.map((r) => r.text.replace(/\s+/g, ' '));
        const min = list.find((r) => /P5-MIN100/i.test(r.text));
        const open = list.find((r) => /P5-OPEN/i.test(r.text));
        check(`${width}: two public codes listed, in the order open (applicable) then the one under the minimum`, list.length === 2 && /P5-OPEN/i.test(list[0].text) && /P5-MIN100/i.test(list[1].text), JSON.stringify(list.map((r) => r.text)));
        check(`${width}: below the minimum: hint «Остават ви … до този код», NO button`, !! min && /Остават ви|away from this code/.test(min.text) && /30/.test(min.text) && ! min.button, min?.text);
        check(`${width}: the unconditioned code has «Приложи»; its description shows the tag as plain text`, !! open && open.button && open.text.includes('<b>10%</b>'), open?.text);
        check(`${width}: the personal code is nowhere on the page`, ! /p5-personal/i.test(await page.innerText('body')));
        const shotA = join(OUT, `p5-list-below-minimum-${width}.png`); shots.push(shotA); await page.screenshot({ path: shotA });

        // reach the minimum: 70 € + 5 pieces of a 7 € line = 105 €
        await post('/api/cart/lines', 'POST', { variation_id: ids[9], quantity: 5 });
        await page.goto(BASE + '/cart', { waitUntil: 'networkidle' });
        await page.waitForSelector('.sf-cart-line');
        await page.locator('details.sf-promo-list > summary').click();
        await sleep(300);
        list = await rows();
        report[`list_${width}_105eur`] = list.map((r) => r.text.replace(/\s+/g, ' '));
        check(`${width}: at 105 € both codes have «Приложи»`, list.length === 2 && list.every((r) => r.button), JSON.stringify(list.map((r) => [r.text, r.button])));

        const totalBefore = await page.locator('.sf-cart-total-grand').innerText();
        await page.locator('li.sf-promo-list-item', { hasText: /P5-MIN100/i }).locator('.sf-promo-list-apply').click();
        await page.waitForFunction(() => /Отстъпка|Discount/.test(document.body.innerText), null, { timeout: 15000 });
        await sleep(600);
        const totalAfter = await page.locator('.sf-cart-total-grand').innerText();
        check(`${width}: «Приложи» applies the code: the total changes`, totalBefore !== totalAfter, `${totalBefore} → ${totalAfter}`);
        await page.locator('details.sf-promo-list > summary').click();
        await sleep(300);
        list = await rows();
        const applied = list.find((r) => /P5-MIN100/i.test(r.text));
        check(`${width}: the applied code's row says «Приложен» and has no button`, !! applied && /Приложен|Applied/.test(applied.text) && ! applied.button, applied?.text);
        const shotB = join(OUT, `p5-list-applied-${width}.png`); shots.push(shotB); await page.screenshot({ path: shotB });

        if (width === 390) {
            check('390: no horizontal scroll', ! (await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)));
        }

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
            for (const k of ['promotions', 'carts', 'cart_lines', 'settings', 'products', 'variations', 'stock', 'prices', 'attrs']) {
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
