// A REAL-BROWSER check of P7-2: the «Значки» zone with its sections COLLAPSED, and «Безплатна доставка» + one custom badge on a card.
//   A throw-away shop slice (a category with 5 products: 1 on sale (manual sale 80.00 of 100.00) and new, stock 5; 2 new; 3 old, stock 2; 4 old; 5 old, sold out) and a throw-away staff member.
//   ADMIN (1440): «Дизайн» → «Значки»: every section is collapsed (header: name · state · place), one is opened, a custom badge is added in the form with its condition (on sale), «Приложи», «Публикувай».
//   STOREFRONT (1440 and 390): product 1 carries ALL FOUR built-ins and the custom badge: «−20%» and «Нови» on top, the custom «Лято» at the bottom of the picture, «Последни бройки» and «Безплатна доставка» under the
//   price; one line each, no horizontal scroll. «Безплатна доставка» around the threshold: with 90.00 only the 100.00 products show it; with 80.00 (exactly the price) product 1 shows it too; with a USD threshold nothing.
//   The threshold is set in the cache directly (the shop's own zones and methods are not touched); the end of the run recomputes it from the real data.
// NOT part of `php artisan test`: run by hand against the dev site. BEFORE the run every checksum, the staff table and the whole `site_settings` table are taken (a snapshot in a file OUTSIDE the project folder); at the end
// the settings are put back, everything the script created is deleted and the checksums are compared (a watchdog puts the data back after 15 minutes). The screenshots go to OUT, outside the project folder.
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p7-2.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p7-2.mjs'); process.exit(2); }
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

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode(['products' => $sum('catalog_products'), 'variations' => $sum('catalog_variations'), 'stock' => $sum('stock_levels'), 'prices' => $sum('pricing_price_list_items'), 'pivots' => $sum('catalog_product_categories').$sum('catalog_product_tags'), 'categories' => $sum('catalog_categories'), 'settings' => md5(json_encode(DB::table('site_settings')->orderBy('key')->get()->map(fn ($r) => (array) $r)->all())), 'lists' => $sum('pricing_price_lists'), 'jobs' => DB::table('jobs')->count(), 'staff' => DB::table('staff')->count(), 'threshold' => md5(json_encode(app(App\Services\FreeShippingThreshold::class)->refresh()))]);`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$pids = DB::table('catalog_products')->where('slug', 'like', 'p72-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('pricing_price_list_items')->where('target_type', 'variation')->whereIn('target_id', $vids->map(fn ($v) => (string) $v))->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
DB::table('catalog_categories')->where('slug', 'p72-audit-cat')->delete();
DB::table('jobs')->where('payload', 'like', '%RefreshProductSortPrices%')->delete();
echo 'cleaned';`;

// a throw-away category «P72 audit cat» with five products (simple, regular 100.00): 1 manual sale 80.00, new, stock 5; 2 new, stock 5; 3 created 60 days ago, stock 2; 4 created 60 days ago, stock 9; 5 created 60 days ago, stock 0 (sold out)
const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$old = now()->subDays(60);
$class = DB::table('catalog_variations')->whereNotNull('shipping_class')->value('shipping_class');
$group = DB::table('catalog_product_groups')->orderBy('id')->value('id');
$regular = DB::table('pricing_price_lists')->where('name', 'Regular Prices')->value('id');
$sample = DB::table('pricing_price_list_items')->where('price_list_id', $regular)->first();
$basis = $sample->price_tax_rate_basis_points ?? 0; $incl = $sample->price_tax_inclusive ?? 1;
$cat = DB::table('catalog_categories')->insertGetId(['name' => 'P72 audit cat', 'slug' => 'p72-audit-cat', 'created_at' => $now, 'updated_at' => $now]);
$stock = [1 => 5, 2 => 5, 3 => 2, 4 => 9, 5 => 0];
$ids = [];
for ($i = 1; $i <= 5; $i++) {
  $at = $i <= 2 ? $now : $old;
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'simple', 'name' => 'P72 audit '.$i, 'slug' => 'p72-audit-'.$i, 'base_sku' => 'P72AUDIT-'.$i, 'status' => 'active', 'catalog_visibility' => 'visible', 'product_group_id' => $group, 'created_at' => $at, 'updated_at' => $at, 'timeline_at' => $at->copy()->addSeconds(10 - $i), 'is_available' => $stock[$i] > 0 ? 1 : 0, 'price_from_minor' => 10000]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'universal', 'status' => 'active', 'attribute_signature' => hash('sha256', 'p72-'.$i), 'sku' => 'P72AUDIT-'.$i.'-0', 'is_visible' => 0, 'is_purchasable' => 1, 'shipping_class' => $class, 'created_at' => $at, 'updated_at' => $at]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => $stock[$i], 'created_at' => $now, 'updated_at' => $now]);
  DB::table('pricing_price_list_items')->insert(['price_list_id' => $regular, 'target_type' => 'variation', 'target_id' => (string) $vid, 'min_quantity' => 1, 'price_amount_minor' => 10000, 'price_currency' => 'EUR', 'price_tax_rate_basis_points' => $basis, 'price_tax_inclusive' => $incl, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  $ids[] = $pid;
  if ($i === 1) { app(App\Services\ProductPricingAndStock::class)->writeSalePrice((string) $vid, '80.00'); }
}
echo json_encode(['ids' => $ids, 'cat' => $cat]);`;

// the shop shows sold-out products for the run (so the sold-out card is there); the settings table is put back from the snapshot
const SHOW_SOLD_OUT = String.raw`use App\Settings\Contracts\SiteSettingsRepository; app(SiteSettingsRepository::class)->set('catalog.hide_sold_out', '0'); echo 'ok';`;

// the badge set, published the way the zone publishes it (BadgeStore). `over`: rows by index merged over the defaults (0 sale, 1 new, 2 low_stock, 3 free_shipping); `custom`: custom rows appended
const BADGES = (over = {}, custom = []) => String.raw`
use App\Storefront\Badges\BadgeSet; use App\Storefront\Badges\BadgeStore;
$w = BadgeSet::defaults();
${Object.entries(over).map(([i, row]) => `$w['badges'][${i}] = array_replace($w['badges'][${i}], json_decode('${JSON.stringify(row)}', true));`).join(' ')}
${custom.map((row) => `$w['badges'][] = json_decode('${JSON.stringify(row)}', true);`).join(' ')}
[$c, $e] = BadgeSet::validate($w);
if ($e !== []) { throw new Exception(json_encode($e)); }
BadgeStore::publish($c);
echo 'ok';`;

// the cached free-shipping threshold is set directly (the shop's real zones and methods are not touched); the end of the run recomputes it from the real data
const THRESHOLD = (minor) => String.raw`Illuminate\Support\Facades\Cache::forever(App\Services\FreeShippingThreshold::KEY, ['minor' => ${minor}, 'currency' => 'EUR']); echo 'ok';`;
// the settings rows are kept in a file (a command line is too short for them)
const SNAP_FILE = join(OUT, 'site_settings.snapshot.json').replace(/\\/g, '/');
const SNAPSHOT = String.raw`use Illuminate\Support\Facades\DB; file_put_contents('${SNAP_FILE}', json_encode(DB::table('site_settings')->get()->map(fn ($r) => (array) $r)->all())); echo 'saved';`;
const RESTORE = () => String.raw`use Illuminate\Support\Facades\DB; use App\Storefront\Filters\FilterCatalog; $rows = json_decode(file_get_contents('${SNAP_FILE}'), true); DB::table('site_settings')->delete(); foreach (array_chunk($rows, 50) as $chunk) { DB::table('site_settings')->insert($chunk); } FilterCatalog::forget(); app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh(); app(App\Services\FreeShippingThreshold::class)->refresh(); echo 'restored';`;


// a throw-away staff member (the Administrator role) for the admin screen; deleted at the end
const EMAIL = 'p72-audit@example.test';
const PASSWORD = 'P72-' + Math.random().toString(36).slice(2, 12) + 'Aa1!';
const CREATE_STAFF = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'P72 Audit', $role));
echo 'ok';`;
const DELETE_STAFF = String.raw`use Illuminate\Support\Facades\DB; DB::table('staff')->where('email', '${EMAIL}')->delete(); echo 'ok';`;

// ON: the four built-ins (percent for «Промо», up to 6 for «Последни бройки») and ONE custom badge «Лято» (on sale) at the bottom of the picture
const ALL_ON = [{ 0: { enabled: true, mode: 'percent' }, 1: { enabled: true }, 2: { enabled: true, max: 6 }, 3: { enabled: true } },
    [{ kind: 'custom', id: 'p72audit001', enabled: true, text: 'Лято', position: 'image_bottom', colour: 'blue', opacity: 100, shape: 'square', priority: 15, conditions: { on_sale: true } }]];

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = []; const consoleErrors = []; const shots = []; const report = {};
const check = (name, ok, detail = '') => { process.env.TRACE && console.error((ok ? '· ' : 'FAIL ') + name.slice(0, 90)); steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + String(detail).slice(0, 300)); };

let before = null; let snapshot = false; let watchdog = null;
const putBack = () => { try { php(CLEANUP); } catch (e) { console.error('cleanup failed ' + e); } try { php(DELETE_STAFF); } catch (e) { console.error('staff cleanup failed ' + e); } if (snapshot) { php(RESTORE()); } };

try {
    before = JSON.parse(php(SUMS));
    snapshot = php(SNAPSHOT) === 'saved';
    watchdog = setTimeout(() => { putBack(); console.error('WATCHDOG: the run took more than 15 minutes; the data was put back'); process.exit(3); }, 15 * 60 * 1000);
    const world = JSON.parse(php(SETUP));
    check('the throw-away category and five products were created', world.ids.length === 5);
    php(SHOW_SOLD_OUT);
    check('the throw-away staff member was created', php(CREATE_STAFF) === 'ok');
    php(THRESHOLD(8000));
    php(BADGES(...ALL_ON));

    const watch = (page, tag) => {
        page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR@${tag} ` + String(e).slice(0, 200)));
        page.on('console', (m) => { if (m.type() === 'error' && ! /403|404|Failed to load resource/.test(m.text())) consoleErrors.push(`CONSOLE@${tag} ` + m.text().slice(0, 200)); });
        page.on('response', (r) => { r.status() >= 500 && consoleErrors.push(`HTTP@${tag} ` + r.status() + ' ' + r.url().slice(0, 120)); });
    };
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const shot = async (page, name, options = { fullPage: false }) => { const p = join(OUT, name + '.png'); shots.push(p); await page.screenshot({ path: p, ...options }); };

    // ================================================================ ADMIN: the zone with its sections collapsed
    {
        const ctx = await browser.newContext({ viewport: { width: 1440, height: 1500 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
        ctx.setDefaultTimeout(30000);
        const page = await ctx.newPage();
        watch(page, 'admin');
        await page.goto(BASE + '/admin/login');
        await page.fill('input[type=email]', EMAIL);
        await page.fill('input[type=password]', PASSWORD);
        await page.click('button[type=submit]');
        await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
        await page.goto(BASE + '/admin/design?zone=badges', { waitUntil: 'networkidle' });
        await page.waitForSelector('.ds', { timeout: 30000 });
        await sleep(1500);

        const headings = await page.locator('.ds .fi-section').evaluateAll((els) => els.map((el) => ({ collapsed: el.classList.contains('fi-collapsed'), heading: (el.querySelector('.fi-section-header-heading')?.textContent ?? '').replace(/\s+/g, ' ').trim() })));
        report.headings = headings;
        check('the zone shows five sections: the four built-ins and «Допълнителни значки»', headings.length === 5, JSON.stringify(headings));
        check('every section starts collapsed', headings.length > 0 && headings.every((h) => h.collapsed), JSON.stringify(headings.map((h) => h.collapsed)));
        check('the built-in headers say name · state · place', /Промо · включена · Горе/.test(headings[0]?.heading) && /Нови · включена · Горе/.test(headings[1]?.heading) && /Последни бройки · включена · Под цената/.test(headings[2]?.heading) && /Безплатна доставка · включена · Под цената/.test(headings[3]?.heading), JSON.stringify(headings.map((h) => h.heading)));
        await shot(page, 'p72-zone-collapsed');

        // open ONE section (the fourth): its fields appear, the others stay collapsed
        await page.locator('.ds .fi-section').nth(3).locator('.fi-section-header').first().click();
        await sleep(600);
        const after = await page.locator('.ds .fi-section').evaluateAll((els) => els.map((el) => el.classList.contains('fi-collapsed')));
        check('opening one section leaves the others collapsed', after.filter((c) => ! c).length === 1 && after[3] === false, JSON.stringify(after));
        check('the opened section shows its text field and its place', await page.locator('.ds .fi-section').nth(3).locator('input[type=text]').first().isVisible());
        await shot(page, 'p72-zone-open');

        // the custom section: «+ Добави значка» adds a collapsed item «Нова значка · изключена» (not saved: the page is left as it is)
        await page.locator('.ds .fi-section').nth(4).locator('.fi-section-header').first().click();
        await sleep(600);
        const items = await page.locator('.ds .fi-section').nth(4).locator('.fi-fo-repeater-item-header').evaluateAll((els) => els.map((el) => el.textContent.replace(/\s+/g, ' ').trim()));
        report.customItems = items;
        check('the published custom badge is in the list as a collapsed item «Лято · включена · Долу върху снимката»', items.some((t) => /Лято · включена · Долу върху снимката/.test(t)), JSON.stringify(items));
        await page.getByRole('button', { name: /Добави значка/ }).click();
        await sleep(1500);
        const itemsAfter = await page.locator('.ds .fi-section').nth(4).locator('.fi-fo-repeater-item-header').evaluateAll((els) => els.map((el) => el.textContent.replace(/\s+/g, ' ').trim()));
        check('«+ Добави значка» adds a new item', itemsAfter.length === items.length + 1 && itemsAfter.some((t) => /Нова значка · изключена/.test(t)), JSON.stringify(itemsAfter));
        await ctx.close();
    }

    // ================================================================ STOREFRONT: all four built-ins and one custom badge on a card
    for (const width of [1440, 390]) {
        const ctx = await browser.newContext({ viewport: { width, height: 1400 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
        ctx.setDefaultTimeout(30000);
        const page = await ctx.newPage();
        watch(page, String(width));
        const LIST = BASE + '/product-category/p72-audit-cat';
        const rowsOf = (id) => page.evaluate((pid) => {
            const card = document.querySelector(`[data-product-id="${pid}"]`);
            if (! card) return null;
            const texts = (selector) => [...card.querySelectorAll(selector + ' li')].map((li) => li.textContent.trim());
            return { top: texts('.sf-cbadges--top'), imageBottom: texts('.sf-card-image .sf-cbadges--image-bottom'), bottom: texts('.sf-card-footer .sf-cbadges--bottom') };
        }, String(id));
        const withFree = async () => { const out = {}; for (let i = 0; i < 5; i++) { out[i + 1] = (await rowsOf(world.ids[i]))?.bottom.includes('Безплатна доставка') === true; } return out; };

        // threshold 80.00: product 1 (exactly 80.00) shows it too
        php(THRESHOLD(8000));
        await page.goto(LIST, { waitUntil: 'networkidle' });
        const one = await rowsOf(world.ids[0]);
        report[`card-${width}`] = one;
        check(`${width}: product 1 carries the four built-ins and the custom badge, each in its place`, JSON.stringify(one) === JSON.stringify({ top: ['−20%', 'Нови'], imageBottom: ['Лято'], bottom: ['Последни бройки', 'Безплатна доставка'] }), JSON.stringify(one));
        const geo = await page.evaluate((id) => {
            const card = document.querySelector(`[data-product-id="${id}"]`);
            const r = (el) => { const x = el.getBoundingClientRect(); return { l: x.left, t: x.top, r: x.right, b: x.bottom, h: x.height, cut: el.scrollWidth > el.clientWidth }; };
            return { frame: r(card.querySelector('.sf-card-image')), card: r(card), top: [...card.querySelectorAll('.sf-cbadges--top .sf-cbadge')].map(r), ib: [...card.querySelectorAll('.sf-cbadges--image-bottom .sf-cbadge')].map(r), bottom: [...card.querySelectorAll('.sf-card-footer .sf-cbadges--bottom .sf-cbadge')].map(r), colour: getComputedStyle(card.querySelector('.sf-cbadges--image-bottom .sf-cbadge')).backgroundColor };
        }, String(world.ids[0]));
        report[`geo-${width}`] = geo;
        check(`${width}: the custom badge is at the bottom-left of the picture frame and in the blue pair`, geo.ib.length === 1 && geo.ib[0].l - geo.frame.l <= 12 && geo.frame.b - geo.ib[0].b <= 12 && geo.colour === 'rgb(30, 64, 175)', JSON.stringify(geo.ib) + ' ' + geo.colour);
        check(`${width}: «Безплатна доставка» is green, under the price, inside the card`, geo.bottom.length === 2 && geo.bottom.every((b) => b.r <= geo.card.r + 1), JSON.stringify(geo.bottom));
        check(`${width}: every badge on the picture is one line (≤ 28px); under the price a phone badge may take two lines (≤ 44px)`, [...geo.top, ...geo.ib].every((b) => b.h <= 28) && geo.bottom.every((b) => b.h <= (width <= 480 ? 44 : 28)), JSON.stringify([...geo.top, ...geo.ib, ...geo.bottom].map((b) => b.h)));
        check(`${width}: no badge text is cut by its ellipsis`, [...geo.top, ...geo.ib, ...geo.bottom].every((b) => ! b.cut), JSON.stringify([...geo.top, ...geo.ib, ...geo.bottom].map((b) => b.cut)));
        check(`${width}: no horizontal scroll`, ! (await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)));
        const free80 = await withFree();
        report[`free80-${width}`] = free80;
        check(`${width}: threshold 80.00: «Безплатна доставка» shows on every priced product (product 1 is exactly 80.00; the sold-out card still has its price)`, free80[1] && free80[2] && free80[3] && free80[4] && free80[5], JSON.stringify(free80));
        await shot(page, `p72-card-${width}`, { fullPage: false });

        // threshold 90.00: product 1 (80.00) no longer qualifies, the 100.00 products still do
        php(THRESHOLD(9000));
        await page.goto(LIST, { waitUntil: 'networkidle' });
        const free90 = await withFree();
        report[`free90-${width}`] = free90;
        check(`${width}: threshold 90.00: not on product 1 (80.00), still on the 100.00 products`, ! free90[1] && free90[2] && free90[3] && free90[4], JSON.stringify(free90));

        // another currency than the cards': nothing
        php(String.raw`Illuminate\Support\Facades\Cache::forever(App\Services\FreeShippingThreshold::KEY, ['minor' => 100, 'currency' => 'USD']); echo 'ok';`);
        await page.goto(LIST, { waitUntil: 'networkidle' });
        const freeUsd = await withFree();
        check(`${width}: a threshold in another currency shows nothing`, Object.values(freeUsd).every((v) => ! v), JSON.stringify(freeUsd));

        // «Безплатна доставка» OFF: nothing, and the other badges are as before
        php(THRESHOLD(8000));
        php(BADGES({ 0: { enabled: true, mode: 'percent' }, 1: { enabled: true }, 2: { enabled: true, max: 6 } }, ALL_ON[1]));
        await page.goto(LIST, { waitUntil: 'networkidle' });
        const off = await withFree();
        check(`${width}: with the badge switched OFF no card has it`, Object.values(off).every((v) => ! v), JSON.stringify(off));
        php(BADGES(...ALL_ON));
        await ctx.close();
    }
    check('no console errors and no 5xx', consoleErrors.length === 0, consoleErrors.join(' | '));
} catch (e) {
    check('the script ran to the end', false, String(e));
} finally {
    clearTimeout(watchdog);
    putBack();
    const after = JSON.parse(php(SUMS));
    for (const key of Object.keys(before ?? {})) { check(`checksum restored: ${key}`, before[key] === after[key], `${before[key]} -> ${after[key]}`); }
    await browser.close();
}

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ steps: steps.length, failed: failed.length, report, shots }, null, 1));
failed.length && console.log(failed.map((s) => `FAIL ${s.name} ${s.detail}`).join('\n'));
process.exit(failed.length === 0 ? 0 : 1);
