// A REAL-BROWSER check of P1.
//   A. «Сайдбар»: in the «Дизайн» screen the merchant hides ONE filter (Сезон) and moves ANOTHER (the attribute «P1 цвят») to the top, applies and publishes. The category page then shows the new order and no
//      hidden filter: in the TOP BAR (layout «full») and in the SIDE PANEL (layout «sidebar»), at 1440px; at 390px the same list is in the phone drawer. The hidden filter's URL parameter is a 404 (it filters nothing).
//   B. «Препоръчани»: a home product grid with the source «Препоръчани» shows only the flagged products (3 of 8), newest first; the product form has the toggle «Препоръчан».
// NOT part of `php artisan test`: run by hand against the dev site. It creates ONE throw-away staff member (Administrator), ONE category, one attribute, 2 brands, 2 seasons, 2 tags and 8 products («P1 audit …»).
// Before the run every site_settings row (the owner's published + draft home layout and catalog design included) is backed up; at the end they are put back BY ID, everything created is deleted, the appearance is
// recompiled and the checksums are compared with the ones taken before. A watchdog puts the data back after 8 minutes.
//
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/p1.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node p1.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const php = (code) => {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    const out = (r.stdout ?? '').trim();
    if (r.status !== 0 || out === '') {
        throw new Error(`php failed (status ${r.status}): ` + (out + '\n' + (r.stderr ?? '')).slice(-900));
    }
    return out.split('\n').pop();
};

const EMAIL = `p1-${Date.now()}@example.test`;
const PASSWORD = 'P1audit-' + Math.random().toString(36).slice(2) + 'Aa1!';

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
$home = DB::table('site_settings')->whereIn('key', ['design.home.global.published', 'design.home.global.draft'])->orderBy('id')->get(['id', 'key', 'value'])->map(fn ($r) => [$r->id, $r->key, md5((string) $r->value)])->all();
echo json_encode(['settings' => $sum('site_settings'), 'home' => $home, 'staff' => DB::table('staff')->count(), 'products' => DB::table('catalog_products')->count(), 'variations' => DB::table('catalog_variations')->count(), 'defs' => $sum('catalog_attribute_definitions'), 'brands' => $sum('catalog_brands'), 'seasons' => $sum('catalog_seasons'), 'tags' => $sum('catalog_tags'), 'cats' => $sum('catalog_categories')]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/p1_backup.json', json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo 'ok';`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$pids = DB::table('catalog_products')->where('slug', 'like', 'p1-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_tags')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
$defs = DB::table('catalog_attribute_definitions')->where('code', 'p1-colour')->pluck('id');
DB::table('catalog_attribute_values')->whereIn('attribute_definition_id', $defs)->delete();
DB::table('catalog_attribute_definitions')->whereIn('id', $defs)->delete();
DB::table('catalog_brands')->where('slug', 'like', 'p1-audit-%')->delete();
DB::table('catalog_seasons')->where('slug', 'like', 'p1-audit-%')->delete();
DB::table('catalog_tags')->where('slug', 'like', 'p1-audit-%')->delete();
DB::table('catalog_categories')->where('slug', 'p1-audit-cat')->delete();
app(App\Storefront\Filters\FilterCatalog::class)::forget();
echo 'cleaned';`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/p1_backup.json'), true);
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
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'P1 Audit', $role));
echo 'ok';`;

// 8 products in ONE category: brand A/B, season A/B, tag A/B, a colour attribute (variation level); the ones numbered 2, 5 and 7 are «Препоръчан»
const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$cat = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'P1 audit категория', 'slug' => 'p1-audit-cat', 'created_at' => $now, 'updated_at' => $now]);
$brand = [];
foreach (['A', 'B'] as $x) { $brand[$x] = DB::table('catalog_brands')->insertGetId(['name' => 'P1 Марка '.$x, 'slug' => 'p1-audit-brand-'.strtolower($x), 'created_at' => $now, 'updated_at' => $now]); }
$season = [];
foreach (['A', 'B'] as $x) { $season[$x] = DB::table('catalog_seasons')->insertGetId(['name' => 'P1 Сезон '.$x, 'slug' => 'p1-audit-season-'.strtolower($x), 'created_at' => $now, 'updated_at' => $now]); }
$tag = [];
foreach (['A', 'B'] as $x) { $tag[$x] = DB::table('catalog_tags')->insertGetId(['name' => 'P1 Етикет '.$x, 'slug' => 'p1-audit-tag-'.strtolower($x), 'created_at' => $now, 'updated_at' => $now]); }
$def = DB::table('catalog_attribute_definitions')->insertGetId(['code' => 'p1-colour', 'name' => 'P1 цвят', 'type' => 'select', 'is_filterable' => 1, 'filter_display' => 'list', 'created_at' => $now, 'updated_at' => $now]);
$val = [];
foreach (['Червен', 'Син'] as $i => $label) { $val[$label] = DB::table('catalog_attribute_values')->insertGetId(['attribute_definition_id' => $def, 'value' => $label, 'sort_order' => $i, 'colour' => null, 'created_at' => $now, 'updated_at' => $now]); }
for ($n = 1; $n <= 8; $n++) {
  $x = $n % 2 === 0 ? 'B' : 'A'; $y = $n % 4 < 2 ? 'A' : 'B';
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'variable', 'name' => 'P1 audit продукт '.$n, 'slug' => 'p1-audit-'.$n, 'base_sku' => 'P1AUDIT-'.$n, 'status' => 'active', 'catalog_visibility' => 'visible', 'is_featured' => in_array($n, [2, 5, 7]) ? 1 : 0, 'brand_id' => $brand[$x], 'season_id' => $season[$y], 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => now()->addDays(3)->subMinutes($n)]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'standard', 'status' => 'active', 'attribute_signature' => hash('sha256', "p1-$n"), 'sku' => "P1AUDIT-V$n", 'is_visible' => 1, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_variation_attribute_values')->insert(['variation_id' => $vid, 'attribute_definition_id' => $def, 'attribute_value_id' => $val[$n % 3 === 0 ? 'Син' : 'Червен'], 'created_at' => $now, 'updated_at' => $now]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 5, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  DB::table('catalog_product_tags')->insert(['product_id' => $pid, 'tag_id' => $tag[$n % 3 === 0 ? 'B' : 'A']]);
}
app(App\Storefront\Filters\FilterCatalog::class)::forget();
echo json_encode(['cat' => $cat, 'def' => $def, 'season' => $season, 'brand' => $brand]);`;

// the catalog layout: 'full' (the top bar) or 'sidebar' (the side panel), published on top of the shop's own values
const LAYOUT = (value) => String.raw`
use App\Storefront\PageDesign\PageDesign; use App\Storefront\PageDesign\PageDesignStore;
$current = PageDesignStore::values(PageDesign::CATALOG);
$current['layout'] = '${value}';
[$clean, $errors] = PageDesign::validate(PageDesign::CATALOG, $current, false);
PageDesignStore::publish(PageDesign::CATALOG, $clean);
echo json_encode($errors);`;

// the home page: ONE product grid with the source «Препоръчани» (published)
const HOME_FEATURED = String.raw`
use App\Storefront\Home\HomeValidator; use App\Storefront\Home\HomeDrafts;
[$clean, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => [['type' => 'product_grid', 'settings' => ['source' => 'featured', 'count' => '12']]]]);
HomeDrafts::publish($clean);
echo json_encode($errors);`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });

const steps = [];
const consoleErrors = [];
const shots = [];
const report = {};
const check = (name, ok, detail = '') => { process.env.TRACE && console.error((ok ? '· ' : 'FAIL ') + name.slice(0, 90)); steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

// the filters of the page, in order: the TOP BAR (details.sf-bctl) and the FORM (the side panel / the phone drawer, details.sf-fgroup without the sort)
const filters = (page) => page.evaluate(() => {
    const visible = (el) => el.getClientRects().length > 0;
    const bar = [...document.querySelectorAll('.sf-cbar details.sf-bctl:not(.sf-bctl-sort) .sf-bctl-text')].filter(visible).map((e) => e.textContent.split(':')[0].trim());
    const form = [...document.querySelectorAll('.sf-filters-form details.sf-fgroup:not(.sf-fgroup-sort) .sf-fgroup-name')].map((e) => e.textContent.trim());
    const side = [...document.querySelectorAll('.sf-filters-form details.sf-fgroup:not(.sf-fgroup-sort) .sf-fgroup-name')].filter(visible).map((e) => e.textContent.trim());
    return { bar, form, side, cards: document.querySelectorAll('.sf-card').length };
});

let before = null;
let created = false;
let watchdog = null;
let ids = null;

try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    created = true;
    watchdog = setTimeout(() => { try { php(RESTORE); php(CLEANUP); } catch (e) { console.error('WATCHDOG restore failed ' + e); } console.error('WATCHDOG: the run took more than 8 minutes; the data was put back'); process.exit(3); }, 8 * 60 * 1000);
    check('the throw-away staff member was created', php(CREATE_STAFF) === 'ok');
    ids = JSON.parse(php(SETUP));
    check('the throw-away category, filters and 8 products were created', ids.cat > 0);
    php(LAYOUT('full'));

    const url = BASE + '/product-category/p1-audit-cat';

    // ---- the default: the list was never saved, the filters are what they always were
    const d = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
    const dp = await d.newPage();
    await dp.goto(url, { waitUntil: 'networkidle' });
    report.default_bar = (await filters(dp)).bar;
    check('default: the top bar holds the shop\'s own default order (Марка, Сезон, then the attribute; the tag is off)', report.default_bar.slice(0, 3).join('|') === 'Марка|Сезон|P1 цвят', JSON.stringify(report.default_bar));
    await d.close();

    // ---- A: the merchant works in «Сайдбар»
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, ignoreHTTPSErrors: true });
    ctx.setDefaultTimeout(20000);
    const page = await ctx.newPage();
    page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
    page.on('response', (r) => { r.status() >= 500 && consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 120)); });

    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    await page.goto(BASE + '/admin/design?zone=sidebar', { waitUntil: 'networkidle' });
    await page.waitForSelector('.ds-props .fi-fo-repeater-item', { timeout: 30000 });
    await sleep(1500);
    const props = page.locator('.ds-props');
    const items = props.locator('.fi-fo-repeater-item');
    const labels = async () => (await items.locator('.fi-fo-repeater-item-header-label, .fi-fo-repeater-item-label').allInnerTexts()).map((t) => t.trim());
    report.panel_rows_before = await labels();
    check('the panel lists the shop\'s filters, one row each, with the attribute among them', report.panel_rows_before.length >= 4 && report.panel_rows_before.includes('P1 цвят'), JSON.stringify(report.panel_rows_before));

    // one field per row at the panel's real width: the switch and the kind select never sit side by side
    const geometry = await props.evaluate((el) => {
        const item = el.querySelector('.fi-fo-repeater-item');
        const fields = [...item.querySelectorAll('.fi-fo-field')].filter((f) => f.getClientRects().length > 0).map((f) => f.getBoundingClientRect());
        return { panel: Math.round(el.getBoundingClientRect().width), fields: fields.map((r) => [Math.round(r.left), Math.round(r.top), Math.round(r.width)]), horizontalScroll: el.scrollWidth > el.clientWidth + 1 };
    });
    report.geometry = geometry;
    check('one field per row: the fields of a row are stacked (same left edge, increasing top) and nothing scrolls sideways', geometry.fields.length >= 1 && new Set(geometry.fields.map((f) => f[0])).size === 1 && ! geometry.horizontalScroll, JSON.stringify(geometry));

    const row = (label) => items.filter({ has: page.locator('.fi-fo-repeater-item-header', { hasText: label }) }).first();
    // hide «Сезон»
    const season = row('Сезон');
    await season.locator('button[role=switch]').first().click();
    await sleep(500);
    // move «P1 цвят» to the top with the arrow
    for (let i = 0; i < 6; i++) {
        const up = row('P1 цвят').locator('.fi-fo-repeater-item-move-up-action, [data-move-up], button[title*="up" i], button[aria-label*="up" i], button[aria-label*="нагоре" i]').first();
        if (await up.count() === 0) break;
        const first = (await labels())[0];
        if (first === 'P1 цвят') break;
        await up.click();
        await sleep(700);
    }
    report.panel_rows_after = await labels();
    check('«P1 цвят» is the first row after the arrows', report.panel_rows_after[0] === 'P1 цвят', JSON.stringify(report.panel_rows_after));
    if (OUT) {
        const p = join(OUT, 'p1-sidebar-panel.png');
        shots.push(p);
        await props.screenshot({ path: p });
    }

    await page.locator('button.ds-btn-primary', { hasText: /Приложи|Apply/ }).click();
    await sleep(2500);
    report.after_apply = { notices: await page.locator('.fi-no-notification').allInnerTexts(), errors: await props.locator('.fi-fo-field-wrp-error-message').allInnerTexts(), draft: php(String.raw`echo app(App\Settings\Contracts\SiteSettingsRepository::class)->get(App\Settings\AppearanceSettings::SIDEBAR_FILTERS_DRAFT_KEY) ?? 'null';`) };
    const publishButton = page.locator('.fi-header-actions-ctn .fi-btn', { hasText: 'Публикувай' }).first();
    report.publish_button = { count: await publishButton.count(), disabled: await publishButton.getAttribute('disabled'), cls: await publishButton.getAttribute('class') };
    await publishButton.click();
    await sleep(900);
    if (await page.locator('.fi-modal-window:visible').count() > 0) { await page.locator('.fi-modal-window:visible').last().getByRole('button').filter({ hasNotText: /Отказ|Cancel/ }).last().click(); }
    await sleep(3000);
    report.after_publish_notices = await page.locator('.fi-no-notification').allInnerTexts();

    const pub = JSON.parse(php(String.raw`echo app(App\Settings\Contracts\SiteSettingsRepository::class)->get(App\Settings\AppearanceSettings::SIDEBAR_FILTERS_KEY) ?? 'null';`));
    report.published = pub;
    check('the list was published with «P1 цвят» first and «Сезон» hidden', pub !== null && pub.filters[0].key === String(ids.def) && pub.filters.find((r) => r.key === 'season')?.show === false, JSON.stringify(pub));
    await ctx.close();

    // ---- the category page: top bar and side panel, 1440 and 390
    const shop = async (width, layout) => {
        php(LAYOUT(layout));
        const c = await browser.newContext({ viewport: { width, height: 900 }, ignoreHTTPSErrors: true, ...(width < 600 ? { hasTouch: true, isMobile: true } : {}) });
        c.setDefaultTimeout(20000);
        const p = await c.newPage();
        p.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        await p.goto(url, { waitUntil: 'networkidle' });
        const f = await filters(p);
        if (OUT) {
            const file = join(OUT, `p1-category-${width}-${layout}.png`);
            shots.push(file);
            await p.screenshot({ path: file, fullPage: false });
        }
        const hidden = await p.goto(`${url}?filter[season]=${Object.values(ids.season)[0]}`, { waitUntil: 'networkidle' });
        const status = hidden.status();
        const own = await p.goto(`${url}?filter[brand]=${Object.values(ids.brand)[0]}`, { waitUntil: 'networkidle' });
        const brandStatus = own.status();
        const brandCards = (await filters(p)).cards;
        await c.close();

        return { f, status, brandStatus, brandCards };
    };

    const bar = await shop(1440, 'full');
    report.bar_1440 = bar;
    check('1440px, top bar: the new order, «P1 цвят» first, no «Сезон»', same(bar.f.bar.slice(0, 2), ['P1 цвят', 'Марка']) && ! bar.f.bar.includes('Сезон'), JSON.stringify(bar.f.bar));
    check('1440px: the hidden filter\'s URL parameter is the storefront\'s 404 (it filters nothing), a shown one works', bar.status === 404 && bar.brandStatus === 200 && bar.brandCards > 0, JSON.stringify([bar.status, bar.brandStatus, bar.brandCards]));

    const side = await shop(1440, 'sidebar');
    report.side_1440 = side;
    check('1440px, side panel: the same order, no «Сезон»', same(side.f.side.slice(0, 2), ['P1 цвят', 'Марка']) && ! side.f.side.includes('Сезон'), JSON.stringify(side.f));

    const phone = await shop(390, 'full');
    report.phone_390 = phone;
    check('390px, phone drawer: the same list, no «Сезон»', same(phone.f.form.slice(0, 2), ['P1 цвят', 'Марка']) && ! phone.f.form.includes('Сезон'), JSON.stringify(phone.f));

    // ---- B: the home grid «Препоръчани»
    check('the home layout is one grid with the source «Препоръчани»', php(HOME_FEATURED) === '[]');
    for (const width of [1440, 390]) {
        const c = await browser.newContext({ viewport: { width, height: 900 }, ignoreHTTPSErrors: true, ...(width < 600 ? { hasTouch: true, isMobile: true } : {}) });
        const p = await c.newPage();
        await p.goto(BASE + '/', { waitUntil: 'networkidle' });
        const names = await p.evaluate(() => [...document.querySelectorAll('.sf-card .sf-card-name')].map((e) => e.textContent.trim()));
        report[`featured_${width}`] = names;
        const mine = names.filter((n) => n.startsWith('P1 audit'));
        check(`${width}px: the grid shows only the flagged P1 products (2, 5, 7), newest first, and no unflagged P1 product`, same(mine, ['P1 audit продукт 2', 'P1 audit продукт 5', 'P1 audit продукт 7']), JSON.stringify(names));
        if (OUT) {
            const file = join(OUT, `p1-home-featured-${width}.png`);
            shots.push(file);
            await p.screenshot({ path: file, fullPage: false });
        }
        await c.close();
    }

    // ---- B: the toggle in the product form
    const c2 = await browser.newContext({ viewport: { width: 1280, height: 1000 }, ignoreHTTPSErrors: true });
    const p2 = await c2.newPage();
    await p2.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await p2.fill('input[type=email]', EMAIL);
    await p2.fill('input[type=password]', PASSWORD);
    await p2.keyboard.press('Enter');
    await p2.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
    const pid = php(String.raw`echo DB::table('catalog_products')->where('slug', 'p1-audit-2')->value('id');`);
    await p2.goto(`${BASE}/admin/products/${pid}/edit-variable`, { waitUntil: 'networkidle' });
    const toggle = p2.locator('.fi-fo-field', { hasText: 'Препоръчан' }).first();
    check('the product form has the «Препоръчан» toggle with an info icon, and it is ON for a flagged product', await toggle.count() > 0 && await toggle.locator('[aria-describedby^="ds-hint-"]').count() > 0 && (await toggle.locator('button[role=switch]').first().getAttribute('aria-checked')) === 'true');
    if (OUT) {
        const file = join(OUT, 'p1-product-form.png');
        shots.push(file);
        await toggle.screenshot({ path: file });
    }
    await c2.close();
} catch (e) {
    check('the run finished without an exception', false, String(e).slice(0, 600));
} finally {
    if (watchdog) clearTimeout(watchdog);
    if (created) {
        try {
            php(CLEANUP);
            php(RESTORE);
            const after = JSON.parse(php(SUMS));
            for (const k of ['settings', 'staff', 'products', 'variations', 'defs', 'brands', 'seasons', 'tags', 'cats']) {
                check(`${k}: back EXACTLY as before the run`, after[k] === before[k], `${before[k]} vs ${after[k]}`);
            }
            check('the owner\'s published + draft home layout is unchanged (by id and checksum)', JSON.stringify(after.home) === JSON.stringify(before.home), JSON.stringify([before.home, after.home]));
        } catch (e) {
            check('the data was put back', false, String(e).slice(0, 600));
        }
    }
    await browser.close();
    const failed = steps.filter((s) => ! s.ok);
    console.log(JSON.stringify({ base: BASE, report, shots, console_errors: consoleErrors, steps, failed: failed.length }, null, 2));
    process.exit(failed.length === 0 ? 0 : 1);
}
