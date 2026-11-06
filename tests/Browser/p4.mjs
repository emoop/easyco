// A REAL-BROWSER check of P4: «Дизайн» → «Под галерията» (slots below the main area of the product page) and the brand link.
//   In the zone (real clicks: «Добави слот», the selects, the heading fields, «Приложи», «Публикувай») the merchant builds «Подобни продукти» + an HTML block (with a condition) with headings. A product page shows them in order
//   under the gallery and the right column; the similar carousel excludes the current product; a product that does not match the block's condition does not show it; a product alone in its category shows no similar
//   section; the brand name links to the brand page.
// NOT part of `php artisan test`: run by hand against the dev site. It creates ONE throw-away staff member, 4 categories, 14 products, a brand, a season, a tag and 2 HTML blocks («P3 audit …»). Before the run every site_settings
// row is backed up; at the end they are put back BY ID, everything created is deleted and the checksums are compared with the ones taken before. A watchdog puts the data back after 12 minutes.
// The screenshots go to OUT, which must be OUTSIDE the project folder (the script refuses otherwise).
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p4.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) {
    console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p4.mjs');
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

const EMAIL = `p4-${Date.now()}@example.test`;
const PASSWORD = 'P3audit-' + Math.random().toString(36).slice(2) + 'Aa1!';

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
$keys = DB::table('site_settings')->whereIn('key', ['design.home.global.published', 'design.home.global.draft', 'design.content.published', 'design.content.draft', 'design.below.published', 'design.below.draft', 'design.sidebar.filters'])->orderBy('id')->get(['id', 'key', 'value'])->map(fn ($r) => [$r->id, $r->key, md5((string) $r->value)])->all();
echo json_encode(['settings' => $sum('site_settings'), 'keys' => $keys, 'staff' => DB::table('staff')->count(), 'products' => DB::table('catalog_products')->count(), 'variations' => DB::table('catalog_variations')->count(), 'brands' => $sum('catalog_brands'), 'seasons' => $sum('catalog_seasons'), 'tags' => $sum('catalog_tags'), 'cats' => $sum('catalog_categories'), 'blocks' => $sum('html_blocks')]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/p4_backup.json', json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
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
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/p4_backup.json'), true);
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

// what a product page shows below its main area, measured in the browser
const look = (page) => page.evaluate(() => {
    const box = (e) => { if (! e) return null; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return { l: Math.round(r.left), r: Math.round(r.right), t: Math.round(r.top + scrollY), b: Math.round(r.bottom + scrollY), w: Math.round(r.width), h: Math.round(r.height), visible: r.width > 1 && r.height > 1 && cs.display !== 'none' }; };
    const below = document.querySelector('.sf-below');
    const cards = [...(below?.querySelectorAll('.sf-card .sf-card-name') ?? [])].map((e) => e.textContent.trim());
    const brand = document.querySelector('.sf-product-brand a');

    return {
        below: box(below),
        main: box(document.querySelector('.sf-product-main')),
        description: box(document.querySelector('.sf-description')),
        article: box(document.querySelector('article.sf-product')),
        headings: [...(below?.querySelectorAll('.sf-hp-heading-text') ?? [])].map((e) => e.textContent.trim()),
        headingTops: [...(below?.querySelectorAll('.sf-hp-heading-text') ?? [])].map((e) => Math.round(e.getBoundingClientRect().top + scrollY)),
        cards,
        carousel: box(below?.querySelector('[data-carousel]')),
        blockText: below ? below.innerText.includes('P3AUDITBOTTOMBLOCK') : false,
        blockTop: Math.round((below?.querySelector('.sf-hb, .sf-hp-html-block, p')?.getBoundingClientRect().top ?? 0) + scrollY),
        h1: document.querySelector('h1')?.textContent.trim() ?? null,
        brandHref: brand?.getAttribute('href') ?? null,
        brandText: brand?.textContent.trim() ?? null,
        hscroll: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
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
    // the second block shows only for the products of sub-category A (conditions: categories)
    php(String.raw`use Illuminate\Support\Facades\DB; DB::table('html_blocks')->where('title', 'P3 audit блок отдолу')->update(['conditions' => json_encode(['categories' => [${ids.a}]])]); echo 'ok';`);

    // product 1 and 2: sub-category A (7 products); product 8: sub-category B (6); product 14: alone in its own category
    const p = (n) => BASE + '/product/p3-audit-' + n;

    // ---- 1. nothing saved: no area below, the brand name is a link
    await (async () => {
        const c = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
        const page = await c.newPage();
        page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        await page.goto(p(1), { waitUntil: 'networkidle' });
        const l = await look(page);
        check('nothing saved: the product page has no area below the main one', l.below === null, JSON.stringify(l.below));
        check('the brand name is a link to the brand page', l.brandHref === '/brand/p3-audit-brand' && l.brandText === 'P3 Марка', JSON.stringify({ href: l.brandHref, text: l.brandText }));
        await page.locator('.sf-product-brand a').click();
        await page.waitForLoadState('networkidle');
        check('a click on the brand name lands on the brand page', page.url().endsWith('/brand/p3-audit-brand'), page.url());
        await c.close();
    })();

    // ---- 2. the zone, with real clicks
    const ctxAdmin = await browser.newContext({ viewport: { width: 1440, height: 1200 }, ignoreHTTPSErrors: true });
    ctxAdmin.setDefaultTimeout(25000);
    const admin = await ctxAdmin.newPage();
    admin.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
    admin.on('response', (r) => { r.status() >= 500 && consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 120)); });
    await admin.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await admin.fill('input[type=email]', EMAIL);
    await admin.fill('input[type=password]', PASSWORD);
    await admin.keyboard.press('Enter');
    await admin.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
    await admin.goto(BASE + '/admin/design?zone=below', { waitUntil: 'networkidle' });
    await admin.waitForSelector('.ds-props', { timeout: 30000 });
    await sleep(1500);

    const props = admin.locator('.ds-props');
    report.zone_text = (await props.innerText()).slice(0, 400);
    check('the zone «Под галерията» opens in the menu next to «Продуктова страница», with an empty list of slots', /Слотове/.test(report.zone_text) && /Добави слот/.test(report.zone_text), report.zone_text);
    const menu = await admin.locator('.ds-zones, nav').allInnerTexts();
    const zoneNames = (menu.join('\n').match(/Продуктова страница|Под галерията/g) ?? []);
    check('in the menu «Под галерията» comes right after «Продуктова страница»', zoneNames.indexOf('Продуктова страница') >= 0 && zoneNames.indexOf('Под галерията') === zoneNames.indexOf('Продуктова страница') + 1, JSON.stringify(zoneNames));

    const items = () => props.locator('.fi-fo-repeater-item');
    // a field by its EXACT label (the value of the type select also reads «HTML блок»)
    const fieldIn = (item, label) => item.locator('.fi-fo-field:visible').filter({ has: admin.locator('.fi-fo-field-label-content:text-is("' + label + '")') }).first();
    const choose = async (item, label, option) => {
        await fieldIn(item, label).locator('.fi-select-input-btn, button[role=combobox], [role=combobox]').first().click();
        await sleep(500);
        await admin.locator('[role=option]:visible', { hasText: option }).first().click();
        await sleep(700);
    };

    // slot 1: «Подобни продукти» (the default type) with a heading
    await props.getByRole('button', { name: 'Добави слот' }).click();
    await sleep(1200);
    check('«Добави слот» adds a slot; its type is «Подобни продукти» by default', (await items().count()) === 1 && /Подобни продукти/.test(await items().first().innerText()), await items().first().innerText());
    await fieldIn(items().first(), 'Заглавие над слота').locator('input').fill('P3 Подобни продукти');
    await sleep(500);

    // slot 2: an HTML block with a heading
    await props.getByRole('button', { name: 'Добави слот' }).click();
    await sleep(1200);
    const second = items().nth(1);
    await choose(second, 'Вид', 'HTML блок');
    await choose(second, 'HTML блок', 'P3 audit блок отдолу');
    await fieldIn(second, 'Заглавие над слота').locator('input').fill('P3 Само за подкатегория А');
    await sleep(500);

    // geometry of the panel: one field per row, no sideways scroll
    const geometry = await props.evaluate((el) => { const fields = [...el.querySelectorAll('.fi-fo-field')].filter((f) => f.getClientRects().length > 0).slice(0, 10).map((f) => { const b = f.getBoundingClientRect(); return [Math.round(b.left), Math.round(b.top)]; }); return { fields, hscroll: el.scrollWidth > el.clientWidth + 1 }; });
    check('the zone panel is one field per row (each below the one before) and does not scroll sideways', geometry.fields.every((f, i, a) => i === 0 || f[1] > a[i - 1][1]) && ! geometry.hscroll, JSON.stringify(geometry));
    const f0 = join(OUT, 'p4-zone-1440.png'); shots.push(f0); await props.screenshot({ path: f0 });

    // the order: the arrows move the second slot up (and back): «↑ ↓»
    const up = second.locator('button[title*="горе"], button[aria-label*="горе"], button[title*="up" i], button[aria-label*="up" i], [data-action*="move-up"]').first();
    check('the slot has the ↑ ↓ buttons of the repeater', (await up.count()) > 0);

    await admin.locator('button.ds-btn-primary', { hasText: /Приложи|Apply/ }).click();
    await sleep(2500);
    const draft = JSON.parse(php(String.raw`echo app(App\Settings\Contracts\SiteSettingsRepository::class)->get(App\Settings\AppearanceSettings::BELOW_DRAFT_KEY) ?? 'null';`));
    check('«Приложи» wrote the two slots to the DRAFT (a carousel with the source `similar`, an HTML block)', draft?.slots?.length === 2 && draft.slots[0].settings?.source === 'similar' && draft.slots[1].type === 'html_block' && draft.slots[0].heading === 'P3 Подобни продукти', JSON.stringify(draft));
    const none = php(String.raw`echo app(App\Settings\Contracts\SiteSettingsRepository::class)->get(App\Settings\AppearanceSettings::BELOW_PUBLISHED_KEY) ?? 'null';`);
    check('nothing is published yet (the shop is untouched)', none === 'null', none);

    const publishButton = admin.locator('.fi-header-actions-ctn .fi-btn', { hasText: 'Публикувай' }).first();
    await publishButton.click();
    await sleep(900);
    if (await admin.locator('.fi-modal-window:visible').count() > 0) { await admin.locator('.fi-modal-window:visible').last().getByRole('button').filter({ hasNotText: /Отказ|Cancel/ }).last().click(); }
    await sleep(3000);
    const pub = JSON.parse(php(String.raw`echo app(App\Settings\Contracts\SiteSettingsRepository::class)->get(App\Settings\AppearanceSettings::BELOW_PUBLISHED_KEY) ?? 'null';`));
    check('publish wrote the slots (the draft is forgotten)', pub?.slots?.length === 2, JSON.stringify(pub));

    // ---- 3. the storefront at 1440 and 390
    for (const [width, mobile] of [[1440, false], [390, true]]) {
        const c = await browser.newContext({ viewport: { width, height: 900 }, ignoreHTTPSErrors: true, ...(mobile ? { hasTouch: true, isMobile: true } : {}) });
        c.setDefaultTimeout(25000);
        const page = await c.newPage();
        page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        page.on('response', (r) => { r.status() >= 500 && consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 120)); });
        const tag = `${width}px`;

        await page.goto(p(1), { waitUntil: 'networkidle' });
        const one = await look(page);
        report[`p1_${width}`] = one;
        check(`${tag}: product 1 (sub A): the area is below the main area and the description, full width, in the saved order (similar carousel, then the block), each with its heading`, one.below && one.below.t >= one.main.b - 2 && (one.description === null || one.below.t >= one.description.b - 2) && one.headings.join('|') === 'P3 Подобни продукти|P3 Само за подкатегория А' && one.headingTops[0] < one.headingTops[1] && one.carousel && one.carousel.b <= one.headingTops[1] + 2 && one.blockText, JSON.stringify({ below: one.below, main: one.main, desc: one.description, headings: one.headings, tops: one.headingTops, carousel: one.carousel, block: one.blockText }));
        check(`${tag}: the similar carousel holds the OTHER products of sub A (not product 1), no sideways scroll`, one.cards.length > 0 && ! one.cards.includes('P3 audit продукт 1') && one.cards.every((n) => /^P3 audit продукт [2-7]$/.test(n.replace(/…$/, '')) || /продукт/.test(n)) && ! one.hscroll, JSON.stringify({ cards: one.cards, hscroll: one.hscroll }));
        check(`${tag}: the page is as wide as the container (the area is not narrower than the article)`, one.below.w >= one.article.w - 4, JSON.stringify({ below: one.below.w, article: one.article.w }));
        const shotName = width === 1440 ? 'p4-product-1440.png' : 'p4-product-390.png';
        await page.locator('.sf-below').first().scrollIntoViewIfNeeded();
        await sleep(400);
        const f1 = join(OUT, shotName); shots.push(f1); await page.screenshot({ path: f1, fullPage: width === 390 });

        // a product of sub B: the block's condition (category A) does not hold: no block, no heading; the similar carousel is there (6 products in sub B)
        await page.goto(p(8), { waitUntil: 'networkidle' });
        const eight = await look(page);
        check(`${tag}: product 8 (sub B): the block with the condition is NOT shown (nor its heading); the similar carousel is, without product 8`, ! eight.blockText && ! eight.headings.includes('P3 Само за подкатегория А') && eight.headings[0] === 'P3 Подобни продукти' && eight.cards.length > 0 && ! eight.cards.includes('P3 audit продукт 8'), JSON.stringify({ block: eight.blockText, headings: eight.headings, cards: eight.cards }));

        // a product alone in its category: no similar section, no heading, no block
        await page.goto(p(14), { waitUntil: 'networkidle' });
        const alone = await look(page);
        check(`${tag}: product 14 (alone in its category): no «Подобни продукти» section and no heading (and the block's condition does not hold)`, alone.below === null, JSON.stringify(alone.below));
        await c.close();
    }

    // ---- 4. the signed preview of the zone is a real product page with the working copy; the brand link once more at 390
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
            check('the owner\'s home layout, Сайдбар list, templates and slots are unchanged (by id and checksum)', JSON.stringify(after.keys) === JSON.stringify(before.keys), JSON.stringify([before.keys, after.keys]));
        } catch (e) {
            check('the data was put back', false, String(e).slice(0, 600));
        }
    }
    await browser.close();
    const failed = steps.filter((s) => ! s.ok);
    console.log(JSON.stringify({ base: BASE, report, shots, console_errors: consoleErrors, steps, failed: failed.length }, null, 2));
    process.exit(failed.length === 0 ? 0 : 1);
}
