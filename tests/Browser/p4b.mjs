// A REAL-BROWSER check of P4b: the product sections («Дизайн» → «Секции на продукта»), the «Акордеон» component of the right column, the «Табове» slot of «Под галерията» and the «Информация» tab of the product form.
//   Everything is done with real clicks: the three sections (global; product; product-or-global) with their texts, the accordion under the purchase block (and its «first open» setting), a tabs slot with two sections,
//   the product's own texts in its form. Then the product pages: the accordion (closed; the setting opens the first), the tabs (first open; mouse and keyboard switch them), the empty sections hidden, the fallback.
// NOT part of `php artisan test`: run by hand against the dev site. It creates ONE throw-away staff member, 4 categories, 14 products, a brand, a season, a tag and 2 HTML blocks («P3 audit …»). Before the run every site_settings
// row is backed up; at the end they are put back BY ID, everything created is deleted (the products' section texts go with them) and the checksums are compared with the ones taken before. A watchdog puts the data back after 12 minutes.
// The screenshots go to OUT, which must be OUTSIDE the project folder (the script refuses otherwise).
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p4b.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) {
    console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p4b.mjs');
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

const EMAIL = `p4b-${Date.now()}@example.test`;
const PASSWORD = 'P3audit-' + Math.random().toString(36).slice(2) + 'Aa1!';

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
$keys = DB::table('site_settings')->whereIn('key', ['design.home.global.published', 'design.home.global.draft', 'design.content.published', 'design.content.draft', 'design.below.published', 'design.below.draft', 'design.sections.published', 'design.sections.draft', 'design.product_summary.global.published', 'design.product_summary.global.draft', 'design.sidebar.filters'])->orderBy('id')->get(['id', 'key', 'value'])->map(fn ($r) => [$r->id, $r->key, md5((string) $r->value)])->all();
echo json_encode(['settings' => $sum('site_settings'), 'keys' => $keys, 'staff' => DB::table('staff')->count(), 'texts' => DB::table('product_section_texts')->count(), 'products' => DB::table('catalog_products')->count(), 'variations' => DB::table('catalog_variations')->count(), 'brands' => $sum('catalog_brands'), 'seasons' => $sum('catalog_seasons'), 'tags' => $sum('catalog_tags'), 'cats' => $sum('catalog_categories'), 'blocks' => $sum('html_blocks')]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/p4b_backup.json', json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
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
DB::table('product_section_texts')->whereIn('product_id', $pids)->delete();
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
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/p4b_backup.json'), true);
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
DB::table('html_blocks')->insert([['title' => 'P3 audit блок отгоре', 'body' => '<p>P3AUDITTOPBLOCK</p>', 'created_at' => $now, 'updated_at' => $now], ['title' => 'P3 audit блок отдолу', 'body' => '<p>P3AUDITBOTTOMBLOCK</p>', 'created_at' => $now, 'updated_at' => $now]]);
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
const setting = (key) => { const v = php(String.raw`echo app(App\Settings\Contracts\SiteSettingsRepository::class)->get('${key}') ?? 'null';`); return v === 'null' ? null : JSON.parse(v); };

// what a product page shows: the accordion, the tabs, measured in the browser
const look = (page) => page.evaluate(() => {
    const details = [...document.querySelectorAll('.sf-psec')];
    const labels = [...document.querySelectorAll('.sf-ptab-label')];
    const panel = (i) => document.querySelectorAll('.sf-ptab-panel')[i];

    return {
        accordion: details.map((d) => ({ title: d.querySelector('summary')?.textContent.trim(), open: d.open, body: d.querySelector('.sf-psec-body')?.textContent.trim() })),
        accordionBox: document.querySelector('.sf-psec-list')?.getBoundingClientRect().width ?? null,
        buyTop: Math.round((document.querySelector('.sf-add-to-cart')?.getBoundingClientRect().top ?? 0) + scrollY),
        accTop: Math.round((document.querySelector('.sf-psec-list')?.getBoundingClientRect().top ?? 0) + scrollY),
        tabs: labels.map((l) => l.textContent.trim()),
        checked: [...document.querySelectorAll('.sf-ptab-input')].map((i) => i.checked),
        panelsVisible: [...document.querySelectorAll('.sf-ptab-panel')].map((p) => getComputedStyle(p).display !== 'none'),
        panelText: [...document.querySelectorAll('.sf-ptab-panel')].map((p) => p.textContent.trim()),
        tabsBox: document.querySelector('.sf-ptabs')?.getBoundingClientRect().width ?? null,
        hscroll: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        text: document.body.innerText,
    };
});

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
    check('the throw-away categories, brand and 14 products were created', ids.root > 0, JSON.stringify(ids));
    check('the table product_section_texts exists (the migration was run)', php(String.raw`echo Illuminate\Support\Facades\Schema::hasTable('product_section_texts') ? 'yes' : 'no';`) === 'yes');
    // the throw-away products are raw rows: give product 1 what the product form requires to save (a product group and a shipping class)
    php(String.raw`use Illuminate\Support\Facades\DB; $id = DB::table('catalog_products')->where('slug', 'p3-audit-1')->value('id'); DB::table('catalog_products')->where('id', $id)->update(['product_group_id' => DB::table('catalog_product_groups')->value('id')]); DB::table('catalog_variations')->where('product_id', $id)->update(['shipping_class' => DB::table('catalog_variations')->whereNotNull('shipping_class')->value('shipping_class')]); echo 'ok';`);
    const productId = (n) => Number(php(String.raw`echo Illuminate\Support\Facades\DB::table('catalog_products')->where('slug', 'p3-audit-${n}')->value('id');`));
    const p = (n) => BASE + '/product/p3-audit-' + n;

    // ---- the admin
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
    // a field by its EXACT label
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
    const type = async (item, text) => { const editor = item.locator('.tiptap, [contenteditable=true]').first(); await editor.click(); await admin.keyboard.type(text, { delay: 15 }); await sleep(500); await editor.evaluate((e) => e.blur()); await sleep(1500); };

    // ---- 1. «Секции на продукта»: three sections with real clicks
    await openZone('sections');
    report.zone_text = (await props.innerText()).slice(0, 300);
    check('the zone «Секции на продукта» opens with an empty list and «Добави секция»', /Секции/.test(report.zone_text) && /Добави секция/.test(report.zone_text), report.zone_text);
    const menuText = (await admin.locator('.ds-zones, nav').allInnerTexts()).join('\n');
    const order = [...menuText.matchAll(/Продуктова страница|Под галерията|Секции на продукта/g)].map((m) => m[0]);
    check('the menu: «Продуктова страница», «Под галерията», «Секции на продукта» in this order', order.join('|') === 'Продуктова страница|Под галерията|Секции на продукта', JSON.stringify(order));

    const items = () => props.locator('.fi-fo-repeater-item');
    const addSection = async (title, sourceRegex, text) => {
        await props.getByRole('button', { name: 'Добави секция' }).click();
        await sleep(1200);
        const item = items().last();
        await fieldIn(item, 'Заглавие').locator('input').fill(title);
        await sleep(400);
        sourceRegex && await choose(item, 'Източник на текста', sourceRegex);
        text && await type(item, text);

        return item;
    };
    await addSection('P4b Доставка', /^Общ текст за всички продукти$/, 'P4BGLOBALDELIVERY');
    await addSection('P4b Размер', /^Текст на продукта$/, null);
    await addSection('P4b Състав', null, 'P4BGLOBALCOMP');
    const geometry = await props.evaluate((el) => { const fields = [...el.querySelectorAll('.fi-fo-field')].filter((f) => f.getClientRects().length > 0).slice(0, 12).map((f) => { const b = f.getBoundingClientRect(); return [Math.round(b.left), Math.round(b.top)]; }); return { fields, hscroll: el.scrollWidth > el.clientWidth + 1 }; });
    check('the sections panel is one field per row (each below the one before) and does not scroll sideways', geometry.fields.every((f, i, a) => i === 0 || f[1] > a[i - 1][1]) && ! geometry.hscroll, JSON.stringify(geometry));
    const f0 = join(OUT, 'p4b-zone-sections-1440.png'); shots.push(f0); await props.screenshot({ path: f0 });
    await apply();
    const draft = setting('design.sections.draft');
    check('«Приложи» wrote the three sections to the DRAFT with keys; the product-only section has no general text', draft?.sections?.length === 3 && draft.sections.every((s) => /^[0-9a-f-]{36}$/.test(s.key)) && draft.sections[1].source === 'product' && draft.sections[1].text === '' && /P4BGLOBALDELIVERY/.test(draft.sections[0].text), JSON.stringify(draft));
    check('nothing is published yet', setting('design.sections.published') === null);
    await publish();
    const pubSections = setting('design.sections.published');
    check('publish wrote the sections', pubSections?.sections?.length === 3 && pubSections.sections[2].source === 'product_or_global', JSON.stringify(pubSections));

    // ---- 2. «Дясна колона»: the «Акордеон» component
    await openZone('summary');
    await admin.locator('button.ds-chip-add', { hasText: 'Акордеон' }).first().click();
    await sleep(2000);
    report.summary_text = (await admin.locator('body').innerText()).slice(0, 0);
    await publish();
    const summary1 = setting('design.product_summary.global.published');
    check('the «Акордеон» component was added to the right column and published (first-open off)', JSON.stringify(summary1).includes('"accordion"'), JSON.stringify(summary1));

    // ---- 3. «Под галерията»: a tabs slot with two sections
    await openZone('below');
    await props.getByRole('button', { name: 'Добави слот' }).click();
    await sleep(1200);
    const slot = props.locator('.fi-fo-repeater-item').first();
    await choose(slot, 'Вид', /^Табове$/);
    await fieldIn(slot, 'Заглавие над слота').locator('input').fill('P4b Повече информация');
    await sleep(400);
    await fieldIn(slot, 'Секции в табовете').locator('.fi-select-input-btn, button[role=combobox], [role=combobox]').first().click();
    await sleep(500);
    await admin.locator('[role=option]:visible').filter({ hasText: /P4b Размер/ }).first().click();
    await sleep(500);
    await admin.locator('[role=option]:visible').filter({ hasText: /P4b Състав/ }).first().click().catch(async () => { await fieldIn(slot, 'Секции в табовете').locator('.fi-select-input-btn, button[role=combobox], [role=combobox]').first().click(); await sleep(400); await admin.locator('[role=option]:visible').filter({ hasText: /P4b Състав/ }).first().click(); });
    await sleep(600);
    await admin.keyboard.press('Escape');
    const f1 = join(OUT, 'p4b-zone-below-1440.png'); shots.push(f1); await props.screenshot({ path: f1 });
    await apply();
    await publish();
    const below = setting('design.below.published');
    check('the tabs slot was published with the two chosen sections and its heading', below?.slots?.[0]?.type === 'tabs' && below.slots[0].sections?.length === 2 && below.slots[0].heading === 'P4b Повече информация', JSON.stringify(below));

    // ---- 4. the product form: the «Информация» tab of product 1 (a variable product here)
    const id1 = productId(1);
    await admin.goto(BASE + '/admin/products/' + id1 + '/edit-variable', { waitUntil: 'networkidle' });
    await sleep(1500);
    const tab = admin.getByRole('tab', { name: 'Информация' });
    check('the product form has the «Информация» tab (the product-text sections only)', (await tab.count()) > 0);
    await tab.first().click();
    await sleep(800);
    const infoLabels = (await admin.locator('.fi-fo-field:visible .fi-fo-field-label-content').allInnerTexts()).map((s) => s.trim()).filter((s) => /P4b/.test(s));
    check('the tab has one editor per product-text section, in order, labelled with the titles (no editor for the global section)', JSON.stringify(infoLabels) === JSON.stringify(['P4b Размер', 'P4b Състав']), JSON.stringify(infoLabels));
    const form = admin.locator('form').first();
    const edit = async (label, text) => { const root = admin.locator('.fi-fo-field:visible').filter({ has: admin.locator('.fi-fo-field-label-content:text-is("' + label + '")') }).first(); await type(root, text); };
    await edit('P4b Размер', 'P4BP1SIZE');
    await edit('P4b Състав', 'P4BP1COMP');
    const f2 = join(OUT, 'p4b-product-info-tab-1440.png'); shots.push(f2); await admin.screenshot({ path: f2 });
    report.buttons = (await admin.locator('button:visible, a.fi-btn:visible').allInnerTexts()).map((s) => s.trim()).filter(Boolean);
    await admin.locator('button:visible, a.fi-btn:visible').filter({ hasText: /^\s*(Запази|Запиши|Save)/ }).first().click();
    await sleep(4000);
    report.after_save = { notifications: (await admin.locator('.fi-no-notification, [role=alert], .fi-fo-field-wrp-error-message').allInnerTexts()).map((s) => s.trim()).slice(0, 6), url: admin.url() };
    const fa = join(OUT, 'p4b-after-save.png'); await admin.screenshot({ path: fa });
    const rows = JSON.parse(php(String.raw`echo json_encode(Illuminate\Support\Facades\DB::table('product_section_texts')->where('product_id', ${id1})->pluck('body')->map(fn ($b) => strip_tags($b))->values()->all());`));
    check('saving the product saved its two texts', rows.length === 2 && rows.some((r) => r.includes('P4BP1SIZE')) && rows.some((r) => r.includes('P4BP1COMP')), JSON.stringify(rows));

    // ---- 5. the storefront at 1440 and 390
    for (const [width, mobile] of [[1440, false], [390, true]]) {
        const c = await browser.newContext({ viewport: { width, height: 900 }, ignoreHTTPSErrors: true, ...(mobile ? { hasTouch: true, isMobile: true } : {}) });
        c.setDefaultTimeout(25000);
        const page = await c.newPage();
        page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        page.on('response', (r) => { r.status() >= 500 && consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 120)); });
        const tag = `${width}px`;

        await page.goto(p(1), { waitUntil: 'networkidle' });
        const one = await look(page);
        report[`p1_${width}`] = { accordion: one.accordion, tabs: one.tabs };
        check(`${tag}: product 1: the accordion (under the purchase block) has the three sections in the definitions' order, all CLOSED`, one.accordion.map((a) => a.title).join('|') === 'P4b Доставка|P4b Размер|P4b Състав' && one.accordion.every((a) => a.open === false) && one.accTop > one.buyTop, JSON.stringify({ acc: one.accordion, buy: one.buyTop, accTop: one.accTop }));
        check(`${tag}: a click opens a section; opening another does not close it (independent)`, await (async () => {
            const sums = page.locator('.sf-psec > summary');
            await sums.nth(0).click(); await sums.nth(1).click(); await sleep(300);
            const l = await look(page);
            return l.accordion[0].open === true && l.accordion[1].open === true && l.accordion[2].open === false && /P4BGLOBALDELIVERY/.test(l.accordion[0].body) && /P4BP1SIZE/.test(l.accordion[1].body);
        })());
        // the product's own composition wins over the general one
        await page.locator('.sf-psec > summary').nth(2).click(); await sleep(300);
        const withComp = await look(page);
        check(`${tag}: «Състав»: the product's own text, not the general one`, /P4BP1COMP/.test(withComp.accordion[2].body) && ! /P4BGLOBALCOMP/.test(withComp.accordion[2].body), withComp.accordion[2].body);
        const shotA = join(OUT, `p4b-accordion-${width}.png`); shots.push(shotA);
        await page.locator('.sf-psec-list').scrollIntoViewIfNeeded(); await sleep(300); await page.screenshot({ path: shotA });

        // the tabs below
        check(`${tag}: the tabs slot: «Размер» and «Състав», the first open, its heading above`, one.tabs.join('|') === 'P4b Размер|P4b Състав' && one.checked.join() === 'true,false' && one.panelsVisible.join() === 'true,false' && /P4BP1SIZE/.test(one.panelText[0]) && /P4b Повече информация/.test(one.text), JSON.stringify({ tabs: one.tabs, checked: one.checked, vis: one.panelsVisible }));
        await page.locator('.sf-ptab-label').nth(1).click(); await sleep(300);
        const afterClick = await look(page);
        check(`${tag}: a click on the second tab shows its panel and hides the first`, afterClick.checked.join() === 'false,true' && afterClick.panelsVisible.join() === 'false,true' && /P4BP1COMP/.test(afterClick.panelText[1]), JSON.stringify({ checked: afterClick.checked, vis: afterClick.panelsVisible }));
        await page.locator('.sf-ptab-input').first().focus();
        await page.keyboard.press('ArrowRight'); await sleep(200);
        await page.keyboard.press('ArrowLeft'); await sleep(300);
        const afterKeys = await look(page);
        check(`${tag}: the keyboard moves between the tabs (focus on the radio, the arrow keys switch)`, afterKeys.checked.join() === 'true,false' && afterKeys.panelsVisible.join() === 'true,false', JSON.stringify({ checked: afterKeys.checked }));
        const shotT = join(OUT, `p4b-tabs-${width}.png`); shots.push(shotT);
        await page.locator('.sf-ptabs').scrollIntoViewIfNeeded(); await sleep(300); await page.screenshot({ path: shotT });
        check(`${tag}: no sideways scroll`, ! one.hscroll && ! afterKeys.hscroll);

        // product 2: no own texts: «Размер» is empty and hidden, «Състав» falls back to the general text, «Доставка» is general
        await page.goto(p(2), { waitUntil: 'networkidle' });
        const two = await look(page);
        check(`${tag}: product 2 (no own text): «Размер» is not printed (accordion nor tab); «Състав» falls back to the general text`, two.accordion.map((a) => a.title).join('|') === 'P4b Доставка|P4b Състав' && /P4BGLOBALCOMP/.test(two.accordion[1].body) && two.tabs.join('|') === 'P4b Състав' && /P4BGLOBALCOMP/.test(two.panelText[0]), JSON.stringify({ acc: two.accordion, tabs: two.tabs }));
        await c.close();
    }

    // ---- 6. the «first open» setting of the accordion, turned on with real clicks
    await openZone('summary');
    await admin.locator('.ds-row, .ds-cell, [wire\\:click*="selectNode"]').filter({ hasText: 'Акордеон' }).first().click().catch(() => {});
    await sleep(1200);
    const toggle = admin.locator('.ds-props .fi-fo-field:visible').filter({ has: admin.locator('.fi-fo-field-label-content:text-is("Първата секция е отворена")') }).first().locator('button[role=switch]');
    check('selecting the «Акордеон» component shows «Първата секция е отворена»', (await toggle.count()) > 0);
    await toggle.first().click();
    await sleep(1500);
    // the component's settings are applied with «Приложи» (the one button model of the screen)
    if (await admin.locator('button.ds-btn-primary', { hasText: /Приложи|Apply/ }).count() > 0) { await apply(); }
    await publish();
    check('the setting was published', JSON.stringify(setting('design.product_summary.global.published')).includes('"first_open":true'), JSON.stringify(setting('design.product_summary.global.published')));
    {
        const c = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
        const page = await c.newPage();
        await page.goto(p(1), { waitUntil: 'networkidle' });
        const l = await look(page);
        check('with «Първата секция е отворена» only the first section is open on load', l.accordion.map((a) => a.open).join() === 'true,false,false', JSON.stringify(l.accordion.map((a) => a.open)));
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
            for (const k of ['settings', 'staff', 'texts', 'products', 'variations', 'brands', 'seasons', 'tags', 'cats', 'blocks']) {
                check(`${k}: back EXACTLY as before the run`, after[k] === before[k], `${before[k]} vs ${after[k]}`);
            }
            check('the owner\'s home layout, Сайдбар list, templates, slots, sections and right column are unchanged (by id and checksum)', JSON.stringify(after.keys) === JSON.stringify(before.keys), JSON.stringify([before.keys, after.keys]));
        } catch (e) {
            check('the data was put back', false, String(e).slice(0, 600));
        }
    }
    await browser.close();
    const failed = steps.filter((s) => ! s.ok);
    console.log(JSON.stringify({ base: BASE, report, shots, console_errors: consoleErrors, steps, failed: failed.length }, null, 2));
    process.exit(failed.length === 0 ? 0 : 1);
}
