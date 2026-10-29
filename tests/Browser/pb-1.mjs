// A REAL-BROWSER run of PB-1: the «Покупка» block of the product page (pickers: colour circles and size buttons; the quantity counter; the add-to-cart button with its own states and settings) and its editor in the
// «Дясна колона» zone of the «Дизайн» screen. NOT part of `php artisan test`: run by hand against a dev site whose data may be changed. It creates three throw-away products (slugs pb1-probe, pb1-simple, pb1-gone) with two
// throw-away attributes (a colour one shown as circles, a size one shown as buttons), a throw-away admin, and BACKS UP the `site_settings` table and the `appearance/` files first. At the end it restores them and removes
// everything it made (and the carts it filled), and verifies one checksum of the catalog tables it touched and one of `site_settings`. Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/pb-1.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { randomBytes } from 'node:crypto';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const EMAIL = 'pb1-audit@example.test';
const PASSWORD = 'Pb1-' + randomBytes(6).toString('hex');
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node pb-1.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const php = (code) => {
    try {
        return execFileSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8' }).trim().split('\n').pop();
    } catch (e) {
        throw new Error('php failed: ' + String(e.stdout || '').slice(-600) + String(e.stderr || '').slice(-300));
    }
};

const SUM = String.raw`md5(json_encode([DB::table('catalog_attribute_definitions')->orderBy('id')->get(), DB::table('catalog_attribute_values')->orderBy('id')->get(), DB::table('catalog_products')->orderBy('id')->get(), DB::table('catalog_variations')->orderBy('id')->get(), DB::table('stock_levels')->orderBy('variation_id')->get(), DB::table('pricing_price_list_items')->orderBy('id')->get(), DB::table('carts')->orderBy('id')->get()]))`;

const SETUP = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage; use App\Services\ProductPricingAndStock; use EasyCo\Staff\Contracts\{PasswordHasher,RoleRepository,StaffRepository};
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
$files = Storage::disk('public')->allFiles('appearance');
file_put_contents(sys_get_temp_dir().'/pb1_backup.json', json_encode(['rows' => $rows, 'files' => $files, 'contents' => array_combine($files, array_map(fn ($f) => base64_encode(Storage::disk('public')->get($f)), $files)), 'sum' => md5(json_encode($rows)), 'catalog' => ${SUM}]));
$S = '2026-01-01 10:00:00';
DB::table('catalog_attribute_definitions')->insert([['id' => 9101, 'code' => 'pb1-colour', 'name' => 'PB1 Цвят', 'type' => 'select', 'display_type' => 'swatch', 'created_at' => $S, 'updated_at' => $S], ['id' => 9102, 'code' => 'pb1-size', 'name' => 'PB1 Размер', 'type' => 'select', 'display_type' => 'buttons', 'created_at' => $S, 'updated_at' => $S]]);
foreach ([[91011, 9101, 'Червен', 0, '#c62828'], [91012, 9101, 'Син', 1, '#1565c0'], [91013, 9101, 'Обикновен', 2, null], [91021, 9102, 'S', 0, null], [91022, 9102, 'M', 1, null], [91023, 9102, 'L', 2, null]] as [$id, $def, $name, $sort, $colour]) { DB::table('catalog_attribute_values')->insert(['id' => $id, 'attribute_definition_id' => $def, 'value' => $name, 'sort_order' => $sort, 'colour' => $colour, 'created_at' => $S, 'updated_at' => $S]); }
$product = function (int $id, string $slug, string $name, string $type) use ($S) { DB::table('catalog_products')->insert(['id' => $id, 'type' => $type, 'name' => $name, 'slug' => $slug, 'base_sku' => "PB1-$id", 'status' => 'active', 'catalog_visibility' => 'visible', 'is_featured' => false, 'created_at' => $S, 'updated_at' => $S, 'timeline_at' => $S]); };
$variation = function (int $pid, int $vid, array $attrs, int $stock, string $type = 'standard', string $price = '40.00') use ($S) { DB::table('catalog_variations')->insert(['id' => $vid, 'product_id' => $pid, 'sort_order' => $vid, 'type' => $type, 'status' => 'active', 'attribute_signature' => hash('sha256', "pb1-$vid"), 'sku' => "PB1-V$vid", 'is_visible' => $type === 'standard', 'is_purchasable' => true, 'created_at' => $S, 'updated_at' => $S]); foreach ($attrs as $def => $valueId) { DB::table('catalog_variation_attribute_values')->insert(['variation_id' => $vid, 'attribute_definition_id' => $def, 'attribute_value_id' => $valueId, 'created_at' => $S, 'updated_at' => $S]); DB::table('catalog_product_axis_values')->insertOrIgnore(['product_id' => $pid, 'attribute_definition_id' => $def, 'attribute_value_id' => $valueId, 'created_at' => $S, 'updated_at' => $S]); } $stock > 0 && DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => $stock, 'created_at' => $S, 'updated_at' => $S]); app(ProductPricingAndStock::class)->writeRegularPrice((string) $vid, $price); };
$product(91001, 'pb1-probe', 'PB1 тест', 'variable');
$variation(91001, 91101, [9101 => 91011, 9102 => 91021], 3);
$variation(91001, 91102, [9101 => 91011, 9102 => 91022], 0);
$variation(91001, 91103, [9101 => 91011, 9102 => 91023], 120);
$variation(91001, 91104, [9101 => 91012, 9102 => 91021], 2);
$variation(91001, 91105, [9101 => 91012, 9102 => 91022], 5);
$variation(91001, 91106, [9101 => 91012, 9102 => 91023], 0);
$variation(91001, 91107, [9101 => 91013, 9102 => 91022], 4);
$product(91002, 'pb1-simple', 'PB1 прост', 'simple');
$variation(91002, 91201, [], 10, 'universal', '15.00');
$product(91003, 'pb1-gone', 'PB1 изчерпан', 'variable');
$variation(91003, 91301, [9101 => 91011, 9102 => 91021], 0);
$variation(91003, 91302, [9101 => 91012, 9102 => 91022], 0);
app(App\Storefront\ProductSummary\ProductSummaryCompiler::class)->publish(App\Storefront\ProductSummary\ProductSummaryLayout::default(), app(App\Settings\Contracts\SiteSettingsRepository::class));
DB::table('staff')->where('email','${EMAIL}')->delete();
$role = app(RoleRepository::class)->findSystemRoleByName('Administrator');
$staff = EasyCo\Staff\Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'PB1 Audit', $role);
app(StaffRepository::class)->save($staff);
echo json_encode(['rows' => count($rows), 'sum' => md5(json_encode($rows))]);`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/pb1_backup.json'), true);
$vids = DB::table('catalog_variations')->whereIn('product_id', [91001, 91002, 91003])->pluck('id')->map(fn ($i) => (string) $i)->all();
$carts = DB::table('cart_lines')->whereIn('variation_id', $vids)->pluck('cart_id')->unique()->all();
DB::table('cart_lines')->whereIn('variation_id', $vids)->delete();
foreach ($carts as $cart) { DB::table('cart_lines')->where('cart_id', $cart)->exists() || DB::table('carts')->where('id', $cart)->delete(); }
DB::table('pricing_price_list_items')->whereIn('target_id', $vids)->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_axis_values')->whereIn('product_id', [91001, 91002, 91003])->delete();
DB::table('catalog_variations')->whereIn('product_id', [91001, 91002, 91003])->delete();
DB::table('catalog_products')->whereIn('id', [91001, 91002, 91003])->delete();
DB::table('catalog_attribute_values')->whereIn('attribute_definition_id', [9101, 9102])->delete();
DB::table('catalog_attribute_definitions')->whereIn('id', [9101, 9102])->delete();
DB::table('site_settings')->delete();
foreach ($bak['rows'] as $row) { DB::table('site_settings')->insert($row); }
foreach (Storage::disk('public')->allFiles('appearance') as $f) { if (! in_array($f, $bak['files'], true)) { Storage::disk('public')->delete($f); } }
foreach ($bak['contents'] as $f => $content) { Storage::disk('public')->put($f, base64_decode($content)); }
DB::table('staff')->where('email','${EMAIL}')->delete();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
echo json_encode(['settings' => md5(json_encode($rows)) === $bak['sum'], 'catalog' => ${SUM} === $bak['catalog'], 'staff' => DB::table('staff')->where('email','${EMAIL}')->count(), 'appearanceFiles' => count(Storage::disk('public')->allFiles('appearance')), 'appearanceWas' => count($bak['files'])]);`;

const sub = (code) => code.replaceAll('${EMAIL}', EMAIL).replaceAll('${PASSWORD}', PASSWORD).replaceAll('${SUM}', SUM);

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const consoleErrors = [];
const diag = []; // not a failure: every response >= 400 and every console error, with its page, printed at the end (to explain a flaky step)
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 500) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let backedUp = false;

try {
    console.error('backup: ' + php(sub(SETUP)));
    backedUp = true;

    // ---- a fresh visitor ---------------------------------------------------------------------------------------------------------------------
    const visitor = async (viewport = { width: 1400, height: 1000 }, javaScriptEnabled = true) => {
        const ctx = await browser.newContext({ viewport, ignoreHTTPSErrors: true, javaScriptEnabled });
        const page = await ctx.newPage();
        page.on('response', (r) => { r.status() >= 400 && diag.push('HTTP ' + r.status() + ' ' + r.request().method() + ' ' + r.url().slice(0, 120)); });
        page.on('console', (m) => { m.type() === 'error' && diag.push('CONSOLE ' + page.url().slice(-40) + ' ' + m.text().slice(0, 200)); });
        page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + page.url().slice(-40) + ' ' + JSON.stringify({ n: e.name, m: e.message }) + ' ' + String(e.stack || e).replace(/\s+/g, ' ').slice(0, 300)));

        return { ctx, page };
    };
    const shot = async (page, name, locator) => { if (! OUT) return; const path = join(OUT, name + '.png'); shots.push(path); await (locator ?? page).screenshot({ path }); };
    const group = (page, n) => page.locator('[data-sf-group]').nth(n);
    const radio = (page, n, label) => group(page, n).locator('[role=radio]', { hasText: label }).first();
    const radioByTitle = (page, n, title) => group(page, n).locator(`[role=radio][title="${title}"]`);
    const button = (page) => page.locator('.sf-buy');
    const buttonText = async (page) => (await button(page).evaluate((e) => e.textContent)).trim();
    const qty = (page) => page.locator('[data-sf-qty-input]');
    const checked = async (page, n, title) => (await radioByTitle(page, n, title).getAttribute('aria-checked')) === 'true';
    const unavailable = async (page, n, title) => (await radioByTitle(page, n, title).getAttribute('aria-disabled')) === 'true';
    const selectValue = (page) => page.locator('#sf-variation').evaluate((s) => ({ value: s.value, index: s.selectedIndex }));

    // ---- the variable product with colour AND size --------------------------------------------------------------------------------------------
    const { ctx: probeCtx, page: probe } = await visitor();
    await probe.goto(BASE + '/product/pb1-probe', { waitUntil: 'networkidle' });
    check('the page root has the script class', await probe.evaluate(() => document.documentElement.classList.contains('js')));
    check('two groups: colour first, size second (PB1 Цвят, PB1 Размер)', (await probe.locator('.sf-picker-label').evaluateAll((els) => els.map((e) => e.textContent.trim()))).join('|') === 'PB1 Цвят|PB1 Размер', (await probe.locator('.sf-picker-label').evaluateAll((els) => els.map((e) => e.textContent.trim()))).join('|'));
    check('the colour group has two round swatches and a text button for the value with no colour', await probe.locator('[data-sf-group="0"] .sf-swatch').count() === 2 && await probe.locator('[data-sf-group="0"] .sf-size').count() === 1);
    check('a swatch is round, filled with its colour (set by the script, never in the served HTML)', await probe.locator('.sf-swatch').first().evaluate((el) => getComputedStyle(el).borderRadius.includes('9999') || parseFloat(getComputedStyle(el).borderTopLeftRadius) > 10) && await probe.locator('.sf-swatch-dot').first().evaluate((el) => getComputedStyle(el).backgroundColor) === 'rgb(198, 40, 40)');
    check('every swatch has a text name (title and hidden text)', await probe.locator('.sf-swatch').first().getAttribute('title') === 'Червен' && (await probe.locator('.sf-swatch .sf-sr').first().innerText()) === 'Червен');
    check('the size group has three rectangular buttons S, M, L', (await probe.locator('[data-sf-group="1"] .sf-size').allInnerTexts()).map((t) => t.trim()).join('|') === 'S|M|L');
    check('every radio has role=radio and aria-checked', await probe.locator('[role=radio]:not([aria-checked])').count() === 0 && await probe.locator('[role=radiogroup]').count() === 2);
    const hidden = await probe.locator('#sf-variation').evaluate((s) => { const r = s.getBoundingClientRect(); return { w: r.width, h: r.height, clip: getComputedStyle(s).clip }; });
    check('the page\'s select is still in the page but visually hidden', hidden.w <= 2 && hidden.h <= 2, JSON.stringify(hidden));
    check('nothing is chosen at first: the select has no selection', (await selectValue(probe)).index === -1, JSON.stringify(await selectValue(probe)));
    check('the button asks for a choice and is aria-disabled', (await buttonText(probe)) === 'Избери размер' && (await button(probe).getAttribute('aria-disabled')) === 'true', await buttonText(probe));
    check('the counter starts at 1 and «−» is disabled', (await qty(probe).inputValue()) === '1' && await probe.locator('[data-sf-qty-minus]').isDisabled());
    await shot(probe, 'desktop-before-choice', probe.locator('.sf-summary'));

    // clicking the button before a choice outlines the first group that is missing one (no alert)
    probe.on('dialog', (d) => { consoleErrors.push('DIALOG ' + d.message()); d.dismiss(); });
    await button(probe).click({ force: true });
    check('a click before a choice highlights the first unselected group (a CSS class)', (await group(probe, 0).getAttribute('class')).includes('is-missing'));

    // availability: S -> «Обикновен» (only in M) is greyed, never hidden
    await radioByTitle(probe, 1, 'S').click();
    check('after S: the colour «Обикновен» is greyed and still there', await unavailable(probe, 0, 'Обикновен') && await radioByTitle(probe, 0, 'Обикновен').count() === 1);
    check('after S: Червен and Син can be chosen', ! (await unavailable(probe, 0, 'Червен')) && ! (await unavailable(probe, 0, 'Син')));
    await radioByTitle(probe, 1, 'M').click();
    check('after M: Червен (M is sold out in red) is greyed, Син and Обикновен are not', await unavailable(probe, 0, 'Червен') && ! (await unavailable(probe, 0, 'Син')) && ! (await unavailable(probe, 0, 'Обикновен')));
    await radioByTitle(probe, 0, 'Червен').click({ force: true });
    check('a greyed choice cannot be chosen', ! (await checked(probe, 0, 'Червен')) && (await selectValue(probe)).value === '');
    await radioByTitle(probe, 1, 'L').click();
    check('after L: Син (sold out in L) is greyed, Червен is not', await unavailable(probe, 0, 'Син') && ! (await unavailable(probe, 0, 'Червен')));

    // choose Червен/L -> the select gets variation 91103 and the same `change` fires; the button turns normal
    await radioByTitle(probe, 0, 'Червен').click();
    const chosen = await selectValue(probe);
    check('choosing Червен and L sets the select to that variation (91103)', chosen.value === '91103', JSON.stringify(chosen));
    check('the selected choices show aria-checked', await checked(probe, 0, 'Червен') && await checked(probe, 1, 'L'));
    check('the button shows the normal label and works', (await buttonText(probe)) === 'Добави в количката' && await button(probe).isEnabled() && (await button(probe).getAttribute('aria-disabled')) === 'false');

    // the counter: 500 clamps to 99 (120 in stock), «+» stops there, «−» is disabled at 1
    await qty(probe).fill('500');
    check('typing 500 is clamped to 99', (await qty(probe).inputValue()) === '99', await qty(probe).inputValue());
    check('«+» is disabled at the maximum', await probe.locator('[data-sf-qty-plus]').isDisabled());
    await qty(probe).fill('a1b');
    check('non-digits are ignored', (await qty(probe).inputValue()) === '1', await qty(probe).inputValue());
    check('«−» is disabled at 1', await probe.locator('[data-sf-qty-minus]').isDisabled());
    await qty(probe).fill('50');
    await radioByTitle(probe, 1, 'S').click();
    check('changing the variation re-clamps: S in red has 3 in stock', (await qty(probe).inputValue()) === '3', await qty(probe).inputValue());
    check('«+» is disabled at the stock (3) and the counter stays there', await probe.locator('[data-sf-qty-plus]').isDisabled() && (await qty(probe).inputValue()) === '3');
    await shot(probe, 'desktop-chosen', probe.locator('.sf-summary'));

    // keyboard: Tab to a group, arrows move and choose, Space/Enter choose
    await radioByTitle(probe, 1, 'S').focus();
    await probe.keyboard.press('ArrowRight');
    check('ArrowRight moves to the next size and chooses it', await checked(probe, 1, 'M') || await checked(probe, 1, 'L'));
    await probe.keyboard.press('ArrowLeft');
    await probe.keyboard.press('Space');
    check('Space keeps the focused size chosen', await checked(probe, 1, 'S') || await checked(probe, 1, 'M'));

    // add to cart with quantity 2: Червен / L
    await radioByTitle(probe, 1, 'L').click();
    await qty(probe).fill('2');
    await button(probe).click();
    await probe.locator('.sf-cart-status a').waitFor({ timeout: 15000 });
    const cart = await probe.evaluate(async () => (await fetch('/api/cart', { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json());
    const line = (cart.lines ?? cart.items ?? []).find((l) => String(l.variation_id) === '91103');
    check('the cart holds 2 of the chosen variation', line && Number(line.quantity) === 2, JSON.stringify(cart).slice(0, 300));
    await probeCtx.close();

    // ---- a sold-out product, a simple product ---------------------------------------------------------------------------------------------------
    const { ctx: goneCtx, page: gone } = await visitor();
    await gone.goto(BASE + '/product/pb1-gone', { waitUntil: 'networkidle' });
    check('a product with nothing to buy shows the sold-out label and a disabled button', (await buttonText(gone)) === 'Изчерпан' && await button(gone).isDisabled(), await buttonText(gone));
    await goneCtx.close();

    const { ctx: simpleCtx, page: simple } = await visitor();
    await simple.goto(BASE + '/product/pb1-simple', { waitUntil: 'networkidle' });
    check('a simple product has no pickers, only the counter and the button', await simple.locator('[role=radiogroup]').count() === 0 && await qty(simple).count() === 1 && (await buttonText(simple)) === 'Добави в количката');
    await qty(simple).fill('500');
    check('its maximum is the stock (10)', (await qty(simple).inputValue()) === '10', await qty(simple).inputValue());
    await qty(simple).fill('3');
    await button(simple).click();
    await simple.locator('.sf-cart-status a').waitFor({ timeout: 15000 });
    const cart2 = await simple.evaluate(async () => (await fetch('/api/cart', { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json());
    check('the cart holds 3 of the simple product', (cart2.lines ?? cart2.items ?? []).some((l) => String(l.variation_id) === '91201' && Number(l.quantity) === 3));
    await simpleCtx.close();

    // ---- JavaScript off: the select is shown as today ---------------------------------------------------------------------------------------------
    const { ctx: plainCtx, page: plain } = await visitor({ width: 1400, height: 1000 }, false);
    await plain.goto(BASE + '/product/pb1-probe', { waitUntil: 'networkidle' });
    const shown = await plain.locator('#sf-variation').boundingBox();
    check('without the script the select is visible', shown && shown.width > 50 && shown.height > 10, JSON.stringify(shown));
    check('and the pickers are not shown', await plain.locator('.sf-picker:visible').count() === 0 && await plain.locator('[data-sf-qty]:visible').count() === 0);
    await shot(plain, 'desktop-no-js', plain.locator('.sf-summary'));
    await plainCtx.close();

    // ---- a phone ------------------------------------------------------------------------------------------------------------------------------------
    const { ctx: phoneCtx, page: phone } = await visitor({ width: 390, height: 844 });
    await phone.goto(BASE + '/product/pb1-probe', { waitUntil: 'networkidle' });
    await radioByTitle(phone, 1, 'L').click();
    await radioByTitle(phone, 0, 'Червен').click();
    check('on a 390 px phone nothing scrolls sideways', await phone.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), await phone.evaluate(() => document.documentElement.scrollWidth + ' > ' + window.innerWidth));
    await shot(phone, 'phone-chosen', phone.locator('.sf-summary'));
    await phoneCtx.close();

    // ---- PB-1.1: one row for the counter and the button, sizes, the focus ring --------------------------------------------------------------------
    const rowOf = (page) => page.evaluate(() => {
        const q = document.querySelector('.sf-qty'); const b = document.querySelector('.sf-buy'); const s = document.querySelector('.sf-cart-status');
        const r = (e) => { if (! e) return null; const x = e.getBoundingClientRect(); return { top: x.top, bottom: x.bottom, left: x.left, width: x.width, height: x.height }; };
        return { qty: r(q), buy: r(b), status: r(s), label: document.querySelector('.sf-qty-label'), aria: document.querySelector('[data-sf-qty-input]')?.getAttribute('aria-label') };
    });
    const sizes = (page) => page.evaluate(() => {
        const c = (sel) => { const e = document.querySelector(sel); if (! e) return null; const s = getComputedStyle(e); return { size: s.fontSize, weight: s.fontWeight }; };
        const a = document.querySelectorAll('.sf-picker');
        const sum = getComputedStyle(document.querySelector('.sf-summary'));
        return { stock: c('.sf-stock'), price: c('.sf-summary .sf-price-current'), groupGap: a.length > 1 ? Math.round(a[1].getBoundingClientRect().top - a[0].getBoundingClientRect().bottom) : null, padL: sum.paddingLeft, padR: sum.paddingRight };
    });
    for (const [vp, name] of [[{ width: 1400, height: 1000 }, 'desktop'], [{ width: 390, height: 844 }, 'phone']]) {
        const { ctx, page: pg } = await visitor(vp);
        await pg.goto(BASE + '/product/pb1-probe', { waitUntil: 'networkidle' });
        const row = await rowOf(pg);
        check(`${name}: no visible «Количество» label, the input has an accessible name`, row.label === null && row.aria === 'Количество', JSON.stringify(row.aria));
        check(`${name}: counter and button on ONE line (same top, same height)`, Math.abs(row.qty.top - row.buy.top) < 1 && Math.abs(row.qty.height - row.buy.height) < 1, JSON.stringify({ q: row.qty, b: row.buy }));
        check(`${name}: the counter is about 6.5rem wide, the button takes the rest, the status line is under the row`, Math.abs(row.qty.width - 104) < 2 && row.buy.width > 120 && row.status.top >= row.buy.bottom - 1, `${row.qty.width} ${row.buy.width}`);
        console.error(`${name} row height ${row.buy.height}px (before PB-1.1: 48px), counter ${row.qty.width}px`);
        console.error(`${name} sizes: ` + JSON.stringify(await sizes(pg)));
        await shot(pg, `pb11-${name}-row`, pg.locator('.sf-summary'));
        if (name === 'desktop') {
            await radioByTitle(pg, 0, 'Червен').click();
            await radioByTitle(pg, 0, 'Червен').focus();
            const clip = await pg.evaluate(() => {
                const sw = document.querySelector('.sf-swatch').getBoundingClientRect(); const sum = document.querySelector('.sf-summary').getBoundingClientRect();
                return { swLeft: sw.left, sumLeft: sum.left, ring: 4, ok: sw.left - 4 >= sum.left };
            });
            check('the focus ring of the first swatch is not clipped on the left (ring 4px, the column has 6px)', clip.ok, JSON.stringify(clip));
            await shot(pg, 'pb11-focus-ring', pg.locator('.sf-summary'));
        }
        await ctx.close();
    }

    // ---- PB-1.1 3b: «Изчистване» -------------------------------------------------------------------------------------------------------------------
    {
        const { ctx, page: pg } = await visitor();
        await pg.goto(BASE + '/product/pb1-probe', { waitUntil: 'networkidle' });
        const clear = pg.locator('[data-sf-clear]');
        check('«Изчистване»: printed under the groups, hidden before any choice', await clear.count() === 1 && ! await clear.isVisible() && (await clear.innerText()) === 'Изчистване');
        await radioByTitle(pg, 0, 'Червен').click();
        check('«Изчистване»: visible once a group has a choice', await clear.isVisible());
        await button(pg).click({ force: true });
        check('the button before a full choice outlines the missing group', await pg.locator('.sf-picker.is-missing').count() === 1);
        await radioByTitle(pg, 1, 'S').click();
        await qty(pg).fill('3');
        await button(pg).click();
        await pg.locator('.sf-cart-status a').waitFor({ timeout: 15000 });
        check('before clearing: a choice, quantity 3, a status line, the button ready', (await qty(pg).inputValue()) === '3' && (await pg.locator('.sf-cart-status').innerText()).length > 0 && (await button(pg).getAttribute('data-sf-state')) === 'normal');
        await radioByTitle(pg, 1, 'S').focus();
        await pg.keyboard.press('Tab');
        check('the button is reachable with Tab and has a visible focus style', await pg.evaluate(() => { const e = document.activeElement; const s = getComputedStyle(e); return e.hasAttribute('data-sf-clear') && s.outlineStyle === 'solid' && parseFloat(s.outlineWidth) >= 2; }));
        await shot(pg, 'pb11-clear-focus', pg.locator('.sf-summary'));
        await pg.keyboard.press('Enter');
        const after = await pg.evaluate(() => ({
            checked: document.querySelectorAll('[role=radio][aria-checked=true]').length,
            index: document.querySelector('#sf-variation').selectedIndex,
            qty: document.querySelector('[data-sf-qty-input]').value,
            status: document.querySelector('.sf-cart-status').textContent,
            missing: document.querySelectorAll('.is-missing').length,
            state: document.querySelector('.sf-buy').getAttribute('data-sf-state'),
            text: document.querySelector('.sf-buy').textContent.trim(),
            variation: document.querySelector('.sf-buy').getAttribute('data-variation-id'),
            clearHidden: document.querySelector('[data-sf-clear]').hidden,
            focusIsFirstChoice: document.activeElement === document.querySelector('[role=radio]:not([aria-disabled=true])'),
        }));
        check('«Изчистване»: every choice off, the select at -1, quantity 1, status empty, no «missing» highlight', after.checked === 0 && after.index === -1 && after.qty === '1' && after.status === '' && after.missing === 0, JSON.stringify(after));
        check('«Изчистване»: the button is back to its «choose» state with no variation, the link hides again, focus is on the first choice', after.state === 'choose' && after.text === 'Избери размер' && after.variation === '' && after.clearHidden === true && after.focusIsFirstChoice, JSON.stringify(after));
        await radioByTitle(pg, 0, 'Червен').click();
        await radioByTitle(pg, 1, 'L').click();
        check('after clearing the product can be chosen again', (await button(pg).getAttribute('data-variation-id')) === '91103');
        await ctx.close();
    }

    // ---- the settings in the Design screen ------------------------------------------------------------------------------------------------------------
    const liveButton = async () => {
        const { ctx, page } = await visitor();
        await page.goto(BASE + '/product/pb1-probe', { waitUntil: 'networkidle' });
        await radioByTitle(page, 1, 'L').click();
        await radioByTitle(page, 0, 'Червен').click();
        const served = await (await page.request.get(BASE + '/product/pb1-probe')).text();
        const out = await button(page).evaluate((el) => { const s = getComputedStyle(el); return { text: el.textContent.trim(), bg: s.backgroundColor, color: s.color, radius: s.borderTopLeftRadius, shadow: s.boxShadow, inlineStyle: el.getAttribute('style') }; });
        out.servedHasStyleAttr = /\sstyle\s*=/i.test(served);
        await shot(page, 'desktop-live-' + steps.length, page.locator('.sf-summary'));
        await ctx.close();

        return out;
    };
    const defaults = await liveButton();
    check('before: the button has no inline style and the served page has no style attribute', defaults.inlineStyle === null && ! defaults.servedHasStyleAttr, JSON.stringify(defaults));

    const admin = await browser.newContext({ viewport: { width: 1500, height: 1300 }, ignoreHTTPSErrors: true });
    const page = await admin.newPage();
    page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + page.url().slice(-40) + ' ' + JSON.stringify({ n: e.name, m: e.message }) + ' ' + String(e.stack || e).replace(/\s+/g, ' ').slice(0, 300)));
    page.on('response', (r) => { if (r.status() >= 500 && ! r.url().endsWith('/api/cart')) { consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 160)); } });
    const settle = async () => { await page.waitForLoadState('networkidle'); await sleep(900); };
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
    const toolbar = () => page.locator('.fi-header-actions-ctn .fi-btn');
    const toolbarButton = (name) => toolbar().filter({ hasText: name }).first();
    const confirmModal = async () => { await sleep(700); if (await page.locator('.fi-modal-window:visible').count() > 0) { await page.locator('.fi-modal-window:visible').last().getByRole('button').filter({ hasNotText: /Отказ|Cancel/ }).last().click(); } await settle(); };

    await page.goto(BASE + '/admin/design?zone=summary', { waitUntil: 'networkidle' });
    await page.waitForSelector('.ds', { timeout: 30000 });
    await sleep(900);
    await toolbarButton('Върни публикуваното').click();
    await confirmModal();
    await page.locator('.ds-chips-list > li[data-ds-component="purchase"] .ds-chip-main').click();
    await sleep(900);
    const field = (label) => page.locator('.ds-props').getByLabel(label, { exact: false }).first();
    check('the editor shows the texts, colours, border and corners compactly', await field('Текст на бутона').count() === 1 && await field('Фон на бутона').count() === 1 && await field('Рамка (px)').count() === 1 && await field('Заоблени ъгли').count() === 1);
    check('the editor previews the button in three states', await page.locator('.ds-buy-state').count() === 3);
    await shot(page, 'design-editor-before');

    await field('Текст на бутона').fill('Купи сега');
    await field('Текст преди избор').fill('Избери размер, моля');
    await field('Фон на бутона').fill('#2E7D32');
    await field('Фон на бутона').blur();
    await field('Цвят на текста').fill('#FFFFFF');
    await field('Цвят на текста').blur();
    await field('Рамка (px)').fill('2');
    await field('Рамка (px)').blur();
    await sleep(900);
    check('the preview follows the values (a green background)', (await page.locator('.ds-buy-sample').first().getAttribute('style')).includes('background:#2e7d32'), await page.locator('.ds-buy-sample').first().getAttribute('style'));
    await page.locator('.ds-props [id="props.settings.rounded"]').click().catch(async () => { await page.getByText('Заоблени ъгли').first().click(); });
    await sleep(500);
    await shot(page, 'design-editor-filled');
    await page.locator('.ds-props button[type=submit]').click();
    await settle();
    await toolbarButton('Публикувай').click();
    await confirmModal();
    await sleep(2500);

    const styled = await liveButton();
    check('live: the label is the merchant\'s', styled.text === 'Купи сега', styled.text);
    check('live: the background is green and the text white', styled.bg === 'rgb(46, 125, 50)' && styled.color === 'rgb(255, 255, 255)', JSON.stringify(styled));
    check('live: a 2px border ring and the shop\'s rounded corners (LK-1: the toggle was off = square, now on)', styled.shadow.includes('2px') && styled.radius !== '0px', JSON.stringify(styled));
    check('live: still no inline style and no style attribute in the served HTML', styled.inlineStyle === null && ! styled.servedHasStyleAttr);
    const { ctx: choose, page: chooser } = await visitor();
    await chooser.goto(BASE + '/product/pb1-probe', { waitUntil: 'networkidle' });
    check('live: the «before a choice» text is the merchant\'s', (await buttonText(chooser)) === 'Избери размер, моля', await buttonText(chooser));
    await choose.close();

    // PB-1.1: counter off and the order of the attributes (Размер before Цвят), then the live page
    await page.goto(BASE + '/admin/design?zone=summary', { waitUntil: 'networkidle' });
    await page.waitForSelector('.ds', { timeout: 30000 });
    await sleep(900);
    await page.locator('.ds-chips-list > li[data-ds-component="purchase"] .ds-chip-main').click();
    await sleep(900);
    check('the editor has the counter toggle with its hint and the order list', await field('Показвай брояч').count() === 1 && (await page.locator('.ds-props').innerText()).includes('Когато е изключено, бройката се сменя в количката.') && (await page.locator('.ds-props').innerText()).includes('Ред на атрибутите'));
    await shot(page, 'pb11-design-editor');
    await field('Показвай брояч').click();
    await sleep(400);
    await page.locator('.ds-props').getByRole('button', { name: /Добави атрибут/ }).click();
    await sleep(500);
    await page.locator('.ds-props select').last().selectOption({ label: 'PB1 Цвят' });
    await page.locator('.ds-props').getByRole('button', { name: /Добави атрибут/ }).click();
    await sleep(500);
    await page.locator('.ds-props select').last().selectOption({ label: 'PB1 Размер' });
    await sleep(500);
    // move the second row up with its arrow: Размер, Цвят
    const up = page.locator('.ds-props').getByRole('button', { name: /нагоре|Up|up/ });
    console.error('move-up buttons: ' + await up.count());
    await up.last().click().catch((e) => console.error('move up failed ' + String(e).slice(0, 120)));
    await sleep(500);
    await shot(page, 'pb11-design-editor-filled');
    await page.locator('.ds-props button[type=submit]').click();
    await settle();
    console.error('after apply: ' + (await page.locator('.ds-props .fi-fo-field-wrp-error-message, .fi-notification').allInnerTexts()).join(' | ').slice(0, 300));
    await shot(page, 'pb11-design-editor-applied');
    await toolbarButton('Публикувай').click();
    await confirmModal();
    await sleep(2500);
    const { ctx: offCtx, page: off } = await visitor();
    await off.goto(BASE + '/product/pb1-probe', { waitUntil: 'networkidle' });
    check('live: no counter at all (no elements, nothing visible)', await off.locator('[data-sf-qty], .sf-qty-input, .sf-qty-box').count() === 0);
    check('live: the groups follow the order from the editor (Размер, Цвят)', (await off.locator('.sf-picker-label').evaluateAll((els) => els.map((e) => e.textContent.trim()))).join('|') === 'PB1 Размер|PB1 Цвят', (await off.locator('.sf-picker-label').evaluateAll((els) => els.map((e) => e.textContent.trim()))).join('|'));
    check('live: the button is the whole row', await off.locator('.sf-buy').evaluate((b) => Math.abs(b.getBoundingClientRect().width - b.parentElement.getBoundingClientRect().width) < 2));
    await shot(off, 'pb11-live-no-counter-reordered', off.locator('.sf-summary'));
    await offCtx.close();
    const { ctx: offCtx2, page: off2 } = await visitor();
    const errorsBefore = consoleErrors.length;
    await off2.goto(BASE + '/product/pb1-probe', { waitUntil: 'networkidle' });
    await radioByTitle(off2, 0, 'M').click();
    await radioByTitle(off2, 1, 'Син').click();
    await button(off2).click();
    await off2.locator('.sf-cart-status a').waitFor({ timeout: 15000 });
    const cart3 = await off2.evaluate(async () => (await fetch('/api/cart', { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json());
    const line3 = (cart3.lines ?? cart3.items ?? []).find((l) => String(l.variation_id) === '91105');
    check('after the reorder, counter off: the right variation (Син + M = 91105) with quantity 1 reached the cart', line3 && Number(line3.quantity) === 1, JSON.stringify(cart3).slice(0, 300));
    check('with the counter off no script error appears', consoleErrors.length === errorsBefore, consoleErrors.slice(-2).join('|'));
    await offCtx2.close();

    // «Начални настройки» -> publish -> today's look again
    await toolbarButton('Начални настройки').click();
    await confirmModal();
    await toolbarButton('Публикувай').click();
    await confirmModal();
    await sleep(2500);
    const back = await liveButton();
    check('«Начални настройки» + «Публикувай»: the button is back to today\'s look', back.text === defaults.text && back.bg === defaults.bg && back.color === defaults.color && back.radius === defaults.radius && back.shadow === defaults.shadow, JSON.stringify(back) + ' vs ' + JSON.stringify(defaults));
    await admin.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 600));
} finally {
    if (backedUp) {
        const restored = JSON.parse(php(sub(RESTORE)));
        check('restored: site_settings is byte-for-byte what it was', restored.settings === true, JSON.stringify(restored));
        check('restored: the catalog tables are what they were (the probe products, attributes and carts are gone)', restored.catalog === true && restored.staff === 0);
        check('restored: the appearance files are what they were', restored.appearanceFiles === restored.appearanceWas, JSON.stringify(restored));
    }
}

check('no page errors, dialogs or server errors', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
diag.length && console.error('diagnostics (' + diag.length + '): ' + diag.slice(0, 12).join(' | '));
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
