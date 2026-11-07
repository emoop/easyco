// A REAL-BROWSER check of DRIBNI-5: the «Дизайн» zone list (the groups and the two dividers), the cloud's button colours («Съдържание»: real clicks, the chips' computed colours in both shapes, and back to empty),
// the info hint of «Акордеон», and the product page's counter + add-to-cart button on two full-width rows at 390px (on one line at 1440px).
// NOT part of `php artisan test`: run by hand against the dev site. It creates ONE throw-away staff member, 2 categories, 2 variable products (raw rows, «D5 audit …»). Before the run every site_settings row is backed
// up; at the end they are put back BY ID, everything created is deleted and the checksums are compared with the ones taken before. A watchdog puts the data back after 12 minutes.
// The screenshots go to OUT, which must be OUTSIDE the project folder (the script refuses otherwise).
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/dribni-5.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node dribni-5.mjs'); process.exit(2); }
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) { console.error('refused: OUT is inside the project folder'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

const php = (code) => {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    const out = (r.stdout ?? '').trim();
    if (r.status !== 0 || out === '') { throw new Error(`php failed (status ${r.status}): ` + (out + '\n' + (r.stderr ?? '')).slice(-900)); }
    return out.split('\n').pop();
};

const EMAIL = `d5-${Date.now()}@example.test`;
const PASSWORD = 'D5audit-' + Math.random().toString(36).slice(2) + 'Aa1!';

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
echo json_encode(['settings' => md5(json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 'staff' => DB::table('staff')->count(), 'products' => DB::table('catalog_products')->count(), 'cats' => DB::table('catalog_categories')->count()]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/d5_backup.json', json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo 'ok';`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$pids = DB::table('catalog_products')->where('slug', 'like', 'd5-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('product_section_texts')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
DB::table('catalog_categories')->where('slug', 'like', 'd5-audit-%')->delete();
$defs = DB::table('catalog_attribute_definitions')->where('code', 'd5-colour')->pluck('id');
DB::table('catalog_attribute_values')->whereIn('attribute_definition_id', $defs)->delete();
DB::table('catalog_attribute_definitions')->whereIn('id', $defs)->delete();
echo 'cleaned';`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/d5_backup.json'), true);
$put = function () use ($bak) { DB::table('site_settings')->whereNotIn('id', array_column($bak, 'id'))->delete(); foreach ($bak as $row) { DB::table('site_settings')->where('id', $row['id'])->update(['key' => $row['key'], 'value' => $row['value'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]); if (! DB::table('site_settings')->where('id', $row['id'])->exists()) { DB::table('site_settings')->insert($row); } } };
$put();
DB::table('staff')->where('email', '${EMAIL}')->delete();
app(App\Storefront\Header\AppearanceRecompiler::class)->run(app(App\Settings\Contracts\SiteSettingsRepository::class));
$put();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo 'restored';`;

const CREATE_STAFF = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'D5 Audit', $role));
echo 'ok';`;

// a root with one sub-category and two variable products in it (raw rows, as the P4c check does)
const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$root = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'D5 audit корен', 'slug' => 'd5-audit-root', 'created_at' => $now, 'updated_at' => $now]);
$sub = DB::table('catalog_categories')->insertGetId(['parent_id' => $root, 'name' => 'D5 audit под', 'slug' => 'd5-audit-sub', 'created_at' => $now, 'updated_at' => $now]);
$other = DB::table('catalog_categories')->insertGetId(['parent_id' => $root, 'name' => 'D5 audit друг', 'slug' => 'd5-audit-other', 'created_at' => $now, 'updated_at' => $now]);
$def = DB::table('catalog_attribute_definitions')->insertGetId(['code' => 'd5-colour', 'name' => 'D5 цвят', 'type' => 'select', 'is_filterable' => 0, 'filter_display' => 'list', 'created_at' => $now, 'updated_at' => $now]);
$val = DB::table('catalog_attribute_values')->insertGetId(['attribute_definition_id' => $def, 'value' => 'Червен', 'sort_order' => 0, 'colour' => null, 'created_at' => $now, 'updated_at' => $now]);
foreach ([1 => $sub, 2 => $sub, 3 => $other] as $n => $cat) {
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'variable', 'name' => 'D5 audit продукт '.$n, 'slug' => 'd5-audit-'.$n, 'base_sku' => 'D5AUDIT-'.$n, 'status' => 'active', 'catalog_visibility' => 'visible', 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => now()->subMinutes($n)]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'standard', 'status' => 'active', 'attribute_signature' => hash('sha256', "d5-$n"), 'sku' => "D5AUDIT-V$n", 'is_visible' => 1, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_variation_attribute_values')->insert(['variation_id' => $vid, 'attribute_definition_id' => $def, 'attribute_value_id' => $val, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 5, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
}
app(App\Storefront\Filters\FilterCatalog::class)::forget();
echo json_encode(['root' => $root, 'sub' => $sub]);`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });
const steps = []; const shots = []; const report = {}; const consoleErrors = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + String(detail).slice(0, 300)); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const setting = (key) => { const v = php(String.raw`echo app(App\Settings\Contracts\SiteSettingsRepository::class)->get('${key}') ?? 'null';`); return v === 'null' ? null : JSON.parse(v); };

let before = null; let created = false; let watchdog = null;

try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    created = true;
    watchdog = setTimeout(() => { try { php(RESTORE); php(CLEANUP); } catch (e) { console.error('WATCHDOG restore failed ' + e); } console.error('WATCHDOG: the run took more than 12 minutes; the data was put back'); process.exit(3); }, 12 * 60 * 1000);
    check('the throw-away staff member was created', php(CREATE_STAFF) === 'ok');
    check('the throw-away categories and products were created', JSON.parse(php(SETUP)).root > 0);
    const slug = 'd5-audit-1';

    const ctxAdmin = await browser.newContext({ viewport: { width: 1440, height: 1300 }, ignoreHTTPSErrors: true });
    ctxAdmin.setDefaultTimeout(25000);
    const admin = await ctxAdmin.newPage();
    admin.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
    admin.on('response', (r) => { r.status() >= 500 && consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 120)); });
    await admin.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await admin.fill('input[type=email]', EMAIL);
    await admin.fill('input[type=password]', PASSWORD);
    await admin.keyboard.press('Enter');
    await admin.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    const openZone = async (zone) => {
        await admin.goto(BASE + '/admin/design?zone=' + zone, { waitUntil: 'networkidle' });
        await admin.waitForSelector('.ds-props, .ds-palette, .ds-chip-add', { timeout: 30000 });
        await sleep(1500);
    };
    const props = admin.locator('.ds-props');
    const fieldIn = (root, label) => root.locator('.fi-fo-field:visible').filter({ has: admin.locator('.fi-fo-field-label-content:text-is("' + label + '")') }).first();
    const choose = async (root, label, optionRegex) => {
        await fieldIn(root, label).locator('.fi-select-input-btn, button[role=combobox], [role=combobox]').first().click();
        await sleep(500);
        await admin.locator('[role=option]:visible').filter({ hasText: optionRegex }).first().click();
        await sleep(700);
    };
    const apply = async () => { await admin.locator('button.ds-btn-primary', { hasText: /Приложи|Apply/ }).click(); await sleep(2500); };
    const publish = async () => {
        await admin.locator('.fi-header-actions-ctn .fi-btn', { hasText: 'Публикувай' }).first().click();
        await sleep(900);
        if (await admin.locator('.fi-modal-window:visible').count() > 0) { await admin.locator('.fi-modal-window:visible').last().getByRole('button').filter({ hasNotText: /Отказ|Cancel/ }).last().click(); }
        await sleep(3000);
    };

    // ---- 1. the zone list: the groups in the new order, two dividers
    await openZone('header');
    const menu = await admin.evaluate(() => [...document.querySelectorAll('.ds-zones li')].map((li) => li.classList.contains('ds-zone-divider') ? '|' : li.textContent.trim().replace(/\s+/g, ' ')));
    const want = ['Топбанер', 'Хедър', 'Футър', 'Количка и поръчка', '|', 'Начална', 'Страница и карти', 'Съдържание', 'Каталог', 'Сайдбар', '|', 'Продуктова страница', 'Секции на продукта', 'Дясна колона', 'Под галерията'];
    check('the zone list has the new groups in order, with a divider after «Количка и поръчка» and after «Сайдбар»', JSON.stringify(menu) === JSON.stringify(want), JSON.stringify(menu));
    const dividerLine = await admin.locator('.ds-zone-divider').first().evaluate((el) => { const s = getComputedStyle(el); return [s.borderTopWidth, s.borderTopStyle, el.offsetHeight]; });
    check('a divider is a thin line (1px solid, at most 2px tall)', dividerLine[0] === '1px' && dividerLine[1] === 'solid' && dividerLine[2] <= 2, JSON.stringify(dividerLine));
    const menuShot = join(OUT, 'd5-menu-1440.png'); shots.push(menuShot); await admin.locator('.ds-zones').screenshot({ path: menuShot });

    // ---- 2. «Съдържание»: a cloud on the categories' default, the colours with real typing, publish
    await openZone('content');
    // the cloud switch of the default template (Filament draws the toggle's text beside it, not in a label)
    // the shop's own data may have the cloud on already: click only when it is off (the run restores everything anyway)
    const cloudSwitch = admin.locator('.fi-fo-field:visible', { hasText: 'Облак с връзки' }).first().locator('button[role=switch]').first();
    if ((await cloudSwitch.getAttribute('aria-checked')) !== 'true') {
        await cloudSwitch.click();
        await sleep(500);
    }
    check('the cloud switch of the categories default is ON', (await cloudSwitch.getAttribute('aria-checked')) === 'true', 'aria-checked');
    await fieldIn(props, 'Цвят на фона на бутоните').locator('input').first().fill('#1d3b5c');
    await sleep(500);
    await fieldIn(props, 'Цвят на текста на бутоните').locator('input').first().fill('#ffffff');
    await sleep(500);
    await apply();
    await publish();
    const style = setting('design.content.published')?.style ?? {};
    check('publish stored the two colours (lower case)', style.cloud_bg === '#1d3b5c' && style.cloud_color === '#ffffff', JSON.stringify(style));

    // the storefront: the category page with its cloud (the parent category lists its sub-categories)
    const cloudColours = async (page) => page.evaluate(() => {
        const chip = document.querySelector('.sf-cloud .sf-bv-chip, .sf-cloud .sf-subcategory > a');
        if (! chip) return null;
        const s = getComputedStyle(chip);

        return { bg: s.backgroundColor, color: s.color, shape: document.querySelector('.sf-cloud')?.className.match(/sf-cloud-(site|pill)/)?.[1] ?? null };
    });
    const c1 = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
    const shop = await c1.newPage();
    shop.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
    await shop.goto(BASE + '/product-category/d5-audit-root', { waitUntil: 'networkidle' });
    const siteChip = await cloudColours(shop);
    report.cloud_site = siteChip;
    check('shape «Като сайта»: the chips have the chosen background and text colour', siteChip?.shape === 'site' && siteChip.bg === 'rgb(29, 59, 92)' && siteChip.color === 'rgb(255, 255, 255)', JSON.stringify(siteChip));
    const shotSite = join(OUT, 'd5-cloud-site-1440.png'); shots.push(shotSite); await shop.screenshot({ path: shotSite });

    // the rounded shape: the same two colours on the rounded chips
    await openZone('content');
    await choose(props, 'Форма на облака', /Заоблени/);
    await apply();
    await publish();
    await shop.reload({ waitUntil: 'networkidle' });
    const pillChip = await cloudColours(shop);
    report.cloud_pill = pillChip;
    check('shape «Заоблени»: the rounded chips take the same colours', pillChip?.shape === 'pill' && pillChip.bg === 'rgb(29, 59, 92)' && pillChip.color === 'rgb(255, 255, 255)', JSON.stringify(pillChip));

    // empty again: the look the shop always had (site chips white with the dark text)
    await openZone('content');
    await choose(props, 'Форма на облака', /Като сайта/);
    await fieldIn(props, 'Цвят на фона на бутоните').locator('input').first().fill('');
    await fieldIn(props, 'Цвят на текста на бутоните').locator('input').first().fill('');
    await sleep(400);
    await apply();
    await publish();
    await shop.reload({ waitUntil: 'networkidle' });
    const back = await cloudColours(shop);
    check('empty colours: the chips are white again (no colour is printed)', back?.bg === 'rgb(255, 255, 255)' && back.shape === 'site', JSON.stringify(back));
    await c1.close();

    // ---- 3. «Акордеон»: the info hint in its properties
    await openZone('summary');
    // the right column may hold the accordion already (max one): then its own row is selected, otherwise it is added
    if (await admin.locator('button.ds-chip-add', { hasText: 'Акордеон' }).count() > 0) {
        await admin.locator('button.ds-chip-add', { hasText: 'Акордеон' }).first().click();
    } else {
        await admin.locator('button:not(.ds-chip-add)', { hasText: 'Акордеон' }).first().click();
    }
    await sleep(1800);
    const hintText = 'В акордеона се показват секциите, създадени в зоната „Секции на продукта“.';
    const hintFound = await admin.evaluate((t) => document.body.innerHTML.includes(t), hintText);
    check('the «Акордеон» properties have the info hint', hintFound);
    const hintShot = join(OUT, 'd5-accordion-hint-1440.png'); shots.push(hintShot); await props.screenshot({ path: hintShot });

    // ---- 4. the product page: the counter and the button, 1440 (one line) and 390 (two rows)
    for (const [width, mobile, lineExpected] of [[1440, false, true], [390, true, false]]) {
        const c = await browser.newContext({ viewport: { width, height: 900 }, ignoreHTTPSErrors: true, ...(mobile ? { hasTouch: true, isMobile: true } : {}) });
        const page = await c.newPage();
        page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        await page.goto(BASE + '/product/' + slug, { waitUntil: 'networkidle' });
        const geo = await page.evaluate(() => {
            const row = document.querySelector('.sf-buy-row');
            const qty = document.querySelector('.sf-buy-row > .sf-qty');
            const btn = document.querySelector('.sf-buy-row .sf-add-to-cart');
            if (! row || ! btn) return null;
            const r = row.getBoundingClientRect(); const b = btn.getBoundingClientRect(); const q = qty?.getBoundingClientRect();

            return { rowW: Math.round(r.width), btnW: Math.round(b.width), btnTop: Math.round(b.top), qtyTop: q ? Math.round(q.top) : null, qtyW: q ? Math.round(q.width) : null, qtyVisible: !! q && q.height > 0 };
        });
        report['buy_' + width] = geo;
        const tag = `${width}px`;
        if (lineExpected) {
            check(`${tag}: the counter and the button are on ONE line (as before)`, geo?.qtyVisible && Math.abs(geo.qtyTop - geo.btnTop) <= 2, JSON.stringify(geo));
        } else {
            check(`${tag}: the counter and the button are TWO rows, each the full width of the row`, geo?.qtyVisible && geo.qtyTop < geo.btnTop && Math.abs(geo.qtyW - geo.rowW) <= 2 && Math.abs(geo.btnW - geo.rowW) <= 2, JSON.stringify(geo));
            const hs = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
            check(`${tag}: no horizontal scroll`, ! hs);
            const shot = join(OUT, 'd5-buy-390.png'); shots.push(shot);
            await page.locator('.sf-buy-row').scrollIntoViewIfNeeded(); await sleep(300); await page.screenshot({ path: shot });
        }
        await c.close();
    }

    check('no page error and no 5xx during the run', consoleErrors.length === 0, JSON.stringify(consoleErrors.slice(0, 5)));
    await ctxAdmin.close();
} catch (e) {
    check('the run finished without an exception', false, String(e).slice(0, 900));
} finally {
    if (watchdog) clearTimeout(watchdog);
    if (created) {
        try {
            php(CLEANUP);
            php(RESTORE);
            const after = JSON.parse(php(SUMS));
            for (const k of ['settings', 'staff', 'products', 'cats']) {
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
