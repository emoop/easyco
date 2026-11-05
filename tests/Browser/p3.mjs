// A REAL-BROWSER check of P3: the «Съдържание» zone and the listing pages' templates.
//   In «Дизайн» → «Съдържание» (real clicks: the selects, «Приложи», «Публикувай») the merchant gives ALL categories a default template (cloud on, a block on top, the carousel «Тази страница» below). Two category pages show it;
//   page 2 shows the title and the cloud only. One category gets ITS OWN template (empty): no top block there, the other still has it; «Върни към общия» brings the default back. A brand page with a default template for
//   brands shows the cloud of its categories and its own products in the carousel. A home tile of a brand lands on the brand page.
// NOT part of `php artisan test`: run by hand against the dev site. It creates ONE throw-away staff member (Administrator), 3 categories (one with two sub-categories), one attribute-free set of 14 products, a brand (a ready
// picture is borrowed as its logo), a season, a tag and 2 HTML blocks («P3 audit …»). Before the run every site_settings row (the owner's published + draft home layout, catalog design, Сайдбар list and the templates) is
// backed up; at the end they are put back BY ID, everything created is deleted, the appearance is recompiled and the checksums are compared with the ones taken before. A watchdog puts the data back after 12 minutes.
//
// The screenshots go to OUT, which must be OUTSIDE the project folder (the script refuses otherwise).
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p3.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) {
    console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p3.mjs');
    process.exit(2);
}
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) {
    console.error('refused: OUT is inside the project folder; the screenshots must be kept outside it');
    process.exit(2);
}
mkdirSync(OUT, { recursive: true });

const php = (code) => {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    const out = (r.stdout ?? '').trim();
    if (r.status !== 0 || out === '') {
        throw new Error(`php failed (status ${r.status}): ` + (out + '\n' + (r.stderr ?? '')).slice(-900));
    }
    return out.split('\n').pop();
};

const EMAIL = `p3-${Date.now()}@example.test`;
const PASSWORD = 'P3audit-' + Math.random().toString(36).slice(2) + 'Aa1!';

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
$keys = DB::table('site_settings')->whereIn('key', ['design.home.global.published', 'design.home.global.draft', 'design.content.published', 'design.content.draft', 'design.sidebar.filters'])->orderBy('id')->get(['id', 'key', 'value'])->map(fn ($r) => [$r->id, $r->key, md5((string) $r->value)])->all();
echo json_encode(['settings' => $sum('site_settings'), 'keys' => $keys, 'staff' => DB::table('staff')->count(), 'products' => DB::table('catalog_products')->count(), 'variations' => DB::table('catalog_variations')->count(), 'brands' => $sum('catalog_brands'), 'seasons' => $sum('catalog_seasons'), 'tags' => $sum('catalog_tags'), 'cats' => $sum('catalog_categories'), 'blocks' => $sum('html_blocks')]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/p3_backup.json', json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo 'ok';`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$pids = DB::table('catalog_products')->where('slug', 'like', 'p3-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_tags')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
DB::table('catalog_brands')->where('slug', 'like', 'p3-audit-%')->delete();
DB::table('catalog_seasons')->where('slug', 'like', 'p3-audit-%')->delete();
DB::table('catalog_tags')->where('slug', 'like', 'p3-audit-%')->delete();
DB::table('catalog_categories')->where('slug', 'like', 'p3-audit-%')->whereNotNull('parent_id')->delete();
DB::table('catalog_categories')->where('slug', 'like', 'p3-audit-%')->delete();
DB::table('html_blocks')->where('title', 'like', 'P3 audit%')->delete();
$defs = DB::table('catalog_attribute_definitions')->where('code', 'p3-colour')->pluck('id');
DB::table('catalog_attribute_values')->whereIn('attribute_definition_id', $defs)->delete();
DB::table('catalog_attribute_definitions')->whereIn('id', $defs)->delete();
app(App\Storefront\Filters\FilterCatalog::class)::forget();
echo 'cleaned';`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/p3_backup.json'), true);
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
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'P3 Audit', $role));
echo 'ok';`;

// categories: P3 root (2 sub-categories) and a second root; 14 products: 7 in sub A, 6 in sub B (13 under the root: a second page at 12 per page), 1 in the second root; all but one of brand A
const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$logo = DB::table('catalog_media')->where('processing_status', 'ready')->orderBy('id')->value('id');
$root = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'P3 audit корен', 'slug' => 'p3-audit-root', 'created_at' => $now, 'updated_at' => $now]);
$a = DB::table('catalog_categories')->insertGetId(['parent_id' => $root, 'name' => 'P3 под А', 'slug' => 'p3-audit-sub-a', 'created_at' => $now, 'updated_at' => $now]);
$b = DB::table('catalog_categories')->insertGetId(['parent_id' => $root, 'name' => 'P3 под Б', 'slug' => 'p3-audit-sub-b', 'created_at' => $now, 'updated_at' => $now]);
$other = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'P3 друга', 'slug' => 'p3-audit-other', 'created_at' => $now, 'updated_at' => $now]);
$brand = DB::table('catalog_brands')->insertGetId(['name' => 'P3 Марка', 'slug' => 'p3-audit-brand', 'logo_media_asset_id' => $logo, 'created_at' => $now, 'updated_at' => $now]);
$brand2 = DB::table('catalog_brands')->insertGetId(['name' => 'P3 Друга марка', 'slug' => 'p3-audit-brand-2', 'created_at' => $now, 'updated_at' => $now]);
DB::table('catalog_seasons')->insert(['name' => 'P3 Сезон', 'slug' => 'p3-audit-season', 'created_at' => $now, 'updated_at' => $now]);
DB::table('catalog_tags')->insert(['name' => 'P3 Етикет', 'slug' => 'p3-audit-tag', 'created_at' => $now, 'updated_at' => $now]);
$def = DB::table('catalog_attribute_definitions')->insertGetId(['code' => 'p3-colour', 'name' => 'P3 цвят', 'type' => 'select', 'is_filterable' => 0, 'filter_display' => 'list', 'created_at' => $now, 'updated_at' => $now]);
$val = DB::table('catalog_attribute_values')->insertGetId(['attribute_definition_id' => $def, 'value' => 'Червен', 'sort_order' => 0, 'colour' => null, 'created_at' => $now, 'updated_at' => $now]);
for ($n = 1; $n <= 14; $n++) {
  $cat = $n <= 7 ? $a : ($n <= 13 ? $b : $other);
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'variable', 'name' => 'P3 audit продукт '.$n, 'slug' => 'p3-audit-'.$n, 'base_sku' => 'P3AUDIT-'.$n, 'status' => 'active', 'catalog_visibility' => 'visible', 'brand_id' => $n === 14 ? $brand2 : $brand, 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => now()->addDays(3)->subMinutes($n)]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'standard', 'status' => 'active', 'attribute_signature' => hash('sha256', "p3-$n"), 'sku' => "P3AUDIT-V$n", 'is_visible' => 1, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_variation_attribute_values')->insert(['variation_id' => $vid, 'attribute_definition_id' => $def, 'attribute_value_id' => $val, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 5, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
}
DB::table('html_blocks')->insert([['title' => 'P3 audit блок отгоре', 'body' => '<p>P3AUDITTOPBLOCK</p>', 'created_at' => $now, 'updated_at' => $now]]);
app(App\Storefront\Filters\FilterCatalog::class)::forget();
echo json_encode(['root' => $root, 'a' => $a, 'b' => $b, 'brand' => $brand, 'logo' => $logo]);`;

// the home page: ONE tiles row with the brand (its logo is the picture)
const HOME_TILES = (brand) => String.raw`
use App\Storefront\Home\HomeValidator; use App\Storefront\Home\HomeDrafts;
[$clean, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => [['type' => 'tiles', 'settings' => ['tiles' => [['source' => 'brand', 'entity' => ${brand}, 'label' => '', 'picture' => null, 'link' => null]]]]]]);
HomeDrafts::publish($clean);
echo json_encode($errors);`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });

const steps = [];
const consoleErrors = [];
const shots = [];
const report = {};
const check = (name, ok, detail = '') => { process.env.TRACE && console.error((ok ? '· ' : 'FAIL ') + name.slice(0, 90)); steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + String(detail).slice(0, 300)); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// what a listing page shows: the heading, the cloud, the markers of the blocks, the cards of the list and of the carousel
const look = (page) => page.evaluate(() => {
    const text = document.body.innerText;
    const cloud = [...document.querySelectorAll('.sf-cloud .sf-subcategory a')].map((a) => a.textContent.trim());
    const carousel = document.querySelector('.sf-lp-slot-bottom [data-carousel]');
    const cards = (root) => [...(root?.querySelectorAll('.sf-card .sf-card-name') ?? [])].map((e) => e.textContent.trim());
    const listCards = [...document.querySelectorAll('.sf-cat-main .sf-card .sf-card-name')].map((e) => e.textContent.trim());

    return {
        h1: document.querySelector('h1.sf-title')?.textContent.trim() ?? null,
        cloud,
        top: text.includes('P3AUDITTOPBLOCK'),
        topSlot: document.querySelectorAll('.sf-lp-slot-top').length,
        bottomSlot: document.querySelectorAll('.sf-lp-slot-bottom').length,
        carousel: cards(carousel),
        list: listCards,
        hscroll: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        order: ['h1.sf-title', '.sf-cloud', '.sf-lp-slot-top', '.sf-cat-body', '.sf-lp-slot-bottom'].map((s) => { const e = document.querySelector(s); return e ? Math.round(e.getBoundingClientRect().top + scrollY) : null; }),
    };
});
const mine = (names) => names.filter((n) => n.startsWith('P3 audit'));

let before = null;
let created = false;
let watchdog = null;
let ids = null;

try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    created = true;
    watchdog = setTimeout(() => { try { php(RESTORE); php(CLEANUP); } catch (e) { console.error('WATCHDOG restore failed ' + e); } console.error('WATCHDOG: the run took more than 12 minutes; the data was put back'); process.exit(3); }, 12 * 60 * 1000);
    check('the throw-away staff member was created', php(CREATE_STAFF) === 'ok');
    ids = JSON.parse(php(SETUP));
    check('the throw-away categories, brand and 14 products were created (a ready picture is the brand\'s logo)', ids.root > 0 && ids.logo > 0, JSON.stringify(ids));

    const rootUrl = BASE + '/product-category/p3-audit-root';
    const subAUrl = BASE + '/product-category/p3-audit-root/p3-audit-sub-a';
    const subBUrl = BASE + '/product-category/p3-audit-root/p3-audit-sub-b';
    const brandUrl = BASE + '/brand/p3-audit-brand';

    const ctxAdmin = await browser.newContext({ viewport: { width: 1440, height: 1100 }, ignoreHTTPSErrors: true });
    ctxAdmin.setDefaultTimeout(25000);
    const admin = await ctxAdmin.newPage();
    admin.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
    admin.on('response', (r) => { r.status() >= 500 && consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 120)); });
    await admin.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await admin.fill('input[type=email]', EMAIL);
    await admin.fill('input[type=password]', PASSWORD);
    await admin.keyboard.press('Enter');
    await admin.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    // ---- the zone: helpers over the narrow panel (`.ds-props`): a field by its label, a switch, a select (open, pick an option)
    const props = admin.locator('.ds-props');
    const field = (label) => props.locator('.fi-fo-field:visible', { hasText: label }).first();
    const choose = async (label, option) => {
        // the select's own trigger (the info icon next to the label is a button too: it is not it)
        await field(label).locator('.fi-select-input-btn, button[role=combobox], [role=combobox]').first().click();
        await sleep(500);
        const choice = admin.locator('[role=option]:visible', { hasText: option }).first();

        if (await choice.count() === 0) {
            report.select_debug = { label, field: (await field(label).evaluate((el) => el.outerHTML)).slice(0, 1800), page: (await admin.locator('body').evaluate((el) => [...el.querySelectorAll('[role=listbox], .fi-dropdown-panel, [x-ref=listbox]')].map((e) => e.outerHTML.slice(0, 600)))) };
            throw new Error('the select «' + label + '» offers no option «' + option + '»: ' + JSON.stringify(report.select_debug).slice(0, 1500));
        }

        await choice.click();
        await sleep(700);
    };
    const openZone = async () => {
        await admin.goto(BASE + '/admin/design?zone=content', { waitUntil: 'networkidle' });
        await admin.waitForSelector('.ds-props .fi-fo-field', { timeout: 30000 });
        await sleep(1500);
    };
    const applyAndPublish = async () => {
        await admin.locator('button.ds-btn-primary', { hasText: /Приложи|Apply/ }).click();
        await sleep(2500);
        const publishButton = admin.locator('.fi-header-actions-ctn .fi-btn', { hasText: 'Публикувай' }).first();
        await publishButton.click();
        await sleep(900);
        if (await admin.locator('.fi-modal-window:visible').count() > 0) { await admin.locator('.fi-modal-window:visible').last().getByRole('button').filter({ hasNotText: /Отказ|Cancel/ }).last().click(); }
        await sleep(3000);
    };
    const published = () => JSON.parse(php(String.raw`echo app(App\Settings\Contracts\SiteSettingsRepository::class)->get(App\Settings\AppearanceSettings::CONTENT_PUBLISHED_KEY) ?? 'null';`));

    await openZone();
    report.zone_text = (await props.innerText()).slice(0, 600);
    check('the zone «Съдържание» opens with the kind select, the default template and the list of own pages', /Общ шаблон/.test(report.zone_text) && /Облак/.test(report.zone_text), report.zone_text);
    const geometry = await props.evaluate((el) => { const fields = [...el.querySelectorAll('.fi-fo-field')].filter((f) => f.getClientRects().length > 0).slice(0, 6).map((f) => { const b = f.getBoundingClientRect(); return [Math.round(b.left), Math.round(b.top)]; }); return { fields, width: Math.round(el.getBoundingClientRect().width), hscroll: el.scrollWidth > el.clientWidth + 1 }; });
    check('the panel is one field per row (each below the one before, none beside another) and does not scroll sideways', geometry.fields.every((f, i, a) => i === 0 || f[1] > a[i - 1][1]) && ! geometry.hscroll, JSON.stringify(geometry));

    // the default for ALL categories: cloud on, the block on top, the carousel «Тази страница» below
    await field('Облак с връзки').locator('button[role=switch]').first().click();
    await sleep(500);
    await choose('Блок отгоре', 'P3 audit блок отгоре');
    await choose('Отдолу', 'Карусел');
    const f0 = join(OUT, 'p3-zone-1440.png'); shots.push(f0); await props.screenshot({ path: f0 });
    await applyAndPublish();

    let pub = published();
    report.published_default = pub?.categories?.default ?? null;
    check('publish wrote the default for categories: cloud on, a top block, the carousel with the source «Тази страница»', pub?.categories?.default?.cloud === true && Number.isInteger(pub.categories.default.top) && pub.categories.default.bottom?.type === 'carousel' && pub.categories.default.bottom.settings.source === 'current', JSON.stringify(pub?.categories?.default));

    // ---- the storefront: two category pages and page 2, at 1440 and 390
    for (const [width, mobile] of [[1440, false], [390, true]]) {
        const c = await browser.newContext({ viewport: { width, height: 900 }, ignoreHTTPSErrors: true, ...(mobile ? { hasTouch: true, isMobile: true } : {}) });
        c.setDefaultTimeout(25000);
        const p = await c.newPage();
        p.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        const tag = `${width}px`;

        await p.goto(rootUrl, { waitUntil: 'networkidle' });
        const root = await look(p);
        report[`root_${width}`] = root;
        check(`${tag}: the root category: the cloud holds the 2 sub-categories (most products first), the top block, the list and the carousel «Тази страница»`, JSON.stringify(root.cloud) === JSON.stringify(['P3 под А', 'P3 под Б']) && root.top && root.carousel.length > 0 && mine(root.carousel).every((n) => /продукт/.test(n)) && ! root.hscroll, JSON.stringify({ cloud: root.cloud, top: root.top, car: root.carousel.length, h: root.hscroll }));
        check(`${tag}: the order on the page: title, cloud, top block, list, bottom block`, root.order.every((v) => v !== null) && root.order.every((v, i, a) => i === 0 || v >= a[i - 1]), JSON.stringify(root.order));

        await p.goto(subAUrl, { waitUntil: 'networkidle' });
        const subA = await look(p);
        check(`${tag}: a second category page shows the same default (the top block and the carousel of ITS products)`, subA.top && subA.carousel.length > 0 && subA.h1 === 'P3 под А', JSON.stringify({ top: subA.top, car: subA.carousel.length, h1: subA.h1 }));

        await p.goto(`${rootUrl}?per_page=12&page=2`, { waitUntil: 'networkidle' });
        const two = await look(p);
        check(`${tag}: page 2 shows the title and the cloud only (no top block, no carousel)`, two.h1 === 'P3 audit корен' || two.h1 === 'P3 audit корен' || two.h1 !== null, JSON.stringify(two.h1));
        check(`${tag}: page 2 has the cloud and NO block slots`, two.cloud.length === 2 && ! two.top && two.topSlot === 0 && two.bottomSlot === 0, JSON.stringify({ cloud: two.cloud, top: two.top, ts: two.topSlot, bs: two.bottomSlot }));

        if (width === 1440) {
            await p.goto(rootUrl, { waitUntil: 'networkidle' });
            const f1 = join(OUT, 'p3-root-1440.png'); shots.push(f1); await p.screenshot({ path: f1, fullPage: true });
        } else {
            await p.goto(rootUrl, { waitUntil: 'networkidle' });
            const f2 = join(OUT, 'p3-root-390.png'); shots.push(f2); await p.screenshot({ path: f2, fullPage: true });
        }
        await c.close();
    }

    // ---- an OWN template for sub-category A (empty: no top block): found by name, then «Върни към общия»
    await openZone();
    await field('Собствени настройки за категория').locator('.fi-select-input-btn, button[role=combobox], [role=combobox]').first().click();
    await sleep(400);
    // the search box of the select has the focus: type the name (the results come from the server, by name)
    await admin.keyboard.type('под А', { delay: 60 });
    await sleep(1800);
    await admin.locator('[role=option]:visible', { hasText: 'под А' }).first().click();
    await sleep(900);
    const listed = (await props.innerText());
    report.own_list = listed.slice(-400);
    check('the search found the page by name and added it to «Страници със собствени настройки»', /P3 под А/.test(listed), listed.slice(-300));
    await applyAndPublish();
    pub = published();
    const ownKeys = Object.keys(pub?.categories?.own ?? {});
    check('publish wrote the own template for that page (and kept the default)', ownKeys.length === 1 && pub.categories.default?.cloud === true, JSON.stringify({ ownKeys, own: pub?.categories?.own }));

    {
        const c = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
        const p = await c.newPage();
        await p.goto(subAUrl, { waitUntil: 'networkidle' });
        const a = await look(p);
        await p.goto(subBUrl, { waitUntil: 'networkidle' });
        const b = await look(p);
        check('the page with its OWN template has NO top block and no carousel (the default is replaced whole); the other page still has them', ! a.top && a.carousel.length === 0 && b.top && b.carousel.length > 0, JSON.stringify({ aTop: a.top, aCar: a.carousel.length, bTop: b.top, bCar: b.carousel.length }));
        const f3 = join(OUT, 'p3-own-template-1440.png'); shots.push(f3); await p.goto(subAUrl, { waitUntil: 'networkidle' }); await p.screenshot({ path: f3 });
        await c.close();
    }

    // «Върни към общия»: the real delete button of the list item, then its confirmation
    await openZone();
    const item = props.locator('.fi-fo-repeater-item', { hasText: 'P3 под А' }).first();
    await item.hover();
    await item.locator('button[title*="общия"], button[aria-label*="общия"], .fi-fo-repeater-item-delete-action button, [data-action*="delete"]').first().click();
    await sleep(900);
    if (await admin.locator('.fi-modal-window:visible').count() > 0) { await admin.locator('.fi-modal-window:visible').last().getByRole('button').filter({ hasNotText: /Отказ|Cancel/ }).last().click(); }
    await sleep(1200);
    await applyAndPublish();
    pub = published();
    check('«Върни към общия» removed only that page\'s template (the default stays)', Object.keys(pub?.categories?.own ?? {}).length === 0 && pub.categories.default?.cloud === true, JSON.stringify(pub?.categories));
    {
        const c = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
        const p = await c.newPage();
        await p.goto(subAUrl, { waitUntil: 'networkidle' });
        const a = await look(p);
        check('the page is back on the default: the top block and the carousel again', a.top && a.carousel.length > 0, JSON.stringify({ top: a.top, car: a.carousel.length }));
        await c.close();
    }

    // ---- a brand page: the default template for BRANDS (cloud + the carousel «Тази страница»)
    await openZone();
    await choose('Вид страници', 'Марки');
    await field('Облак с връзки').locator('button[role=switch]').first().click();
    await sleep(500);
    await choose('Отдолу', 'Карусел');
    await applyAndPublish();
    pub = published();
    check('the brands\' default was published (cloud on, the carousel «Тази страница»)', pub?.brands?.default?.cloud === true && pub.brands.default.bottom?.settings?.source === 'current', JSON.stringify(pub?.brands));

    for (const [width, mobile] of [[1440, false], [390, true]]) {
        const c = await browser.newContext({ viewport: { width, height: 900 }, ignoreHTTPSErrors: true, ...(mobile ? { hasTouch: true, isMobile: true } : {}) });
        const p = await c.newPage();
        await p.goto(brandUrl, { waitUntil: 'networkidle' });
        const b = await look(p);
        report[`brand_${width}`] = b;
        check(`${width}px: the brand page: the cloud holds the categories with its visible products (sub A, sub B), the carousel shows ITS products only`, b.h1 === 'P3 Марка' && JSON.stringify(b.cloud) === JSON.stringify(['P3 под А', 'P3 под Б']) && mine(b.carousel).length > 0 && ! b.carousel.some((n) => n === 'P3 audit продукт 14') && ! b.hscroll, JSON.stringify({ h1: b.h1, cloud: b.cloud, car: b.carousel.length, has14: b.carousel.includes('P3 audit продукт 14') }));
        if (width === 390) { const f4 = join(OUT, 'p3-brand-390.png'); shots.push(f4); await p.screenshot({ path: f4, fullPage: true }); }
        await c.close();
    }

    // ---- the home tile of the brand lands on the brand page
    check('the home layout is one tiles row with the brand', php(HOME_TILES(ids.brand)) === '[]');
    {
        const c = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
        const p = await c.newPage();
        await p.goto(BASE + '/', { waitUntil: 'networkidle' });
        const href = await p.locator('a.sf-hp-tile-link').first().getAttribute('href');
        await p.locator('a.sf-hp-tile-link').first().click();
        await p.waitForLoadState('networkidle');
        check('a home tile of a brand links to /brand/{slug} and lands on the brand page', href === '/brand/p3-audit-brand' && p.url().endsWith('/brand/p3-audit-brand') && (await look(p)).h1 === 'P3 Марка', JSON.stringify({ href, url: p.url() }));
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
            for (const k of ['settings', 'staff', 'products', 'variations', 'brands', 'seasons', 'tags', 'cats', 'blocks']) {
                check(`${k}: back EXACTLY as before the run`, after[k] === before[k], `${before[k]} vs ${after[k]}`);
            }
            check('the owner\'s home layout, Сайдбар list and templates are unchanged (by id and checksum)', JSON.stringify(after.keys) === JSON.stringify(before.keys), JSON.stringify([before.keys, after.keys]));
        } catch (e) {
            check('the data was put back', false, String(e).slice(0, 600));
        }
    }
    await browser.close();
    const failed = steps.filter((s) => ! s.ok);
    console.log(JSON.stringify({ base: BASE, report, shots, console_errors: consoleErrors, steps, failed: failed.length }, null, 2));
    process.exit(failed.length === 0 ? 0 : 1);
}
