// A REAL-BROWSER check of P3.1: the corrections of the listing pages (cloud only when switched on, cloud shape, cloud hidden on phones, hideable title, title with lines, heading above the bottom slot, count on the sort row).
//   The templates are published through the same store the zone uses (ContentStore::publish); the zone itself is opened once with real clicks to check the new fields (their order, the labels, one field per row).
// NOT part of `php artisan test`: run by hand against the dev site. It creates ONE throw-away staff member, 3 categories, 14 products, a brand, a season, a tag and 2 HTML blocks («P3 audit …»). Before the run every site_settings row
// is backed up; at the end they are put back BY ID, everything created is deleted and the checksums are compared with the ones taken before. A watchdog puts the data back after 12 minutes.
// The screenshots go to OUT, which must be OUTSIDE the project folder (the script refuses otherwise).
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p3-1.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) {
    console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p3-1.mjs');
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

const EMAIL = `p31-${Date.now()}@example.test`;
const PASSWORD = 'P3audit-' + Math.random().toString(36).slice(2) + 'Aa1!';

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
$keys = DB::table('site_settings')->whereIn('key', ['design.home.global.published', 'design.home.global.draft', 'design.content.published', 'design.content.draft', 'design.sidebar.filters'])->orderBy('id')->get(['id', 'key', 'value'])->map(fn ($r) => [$r->id, $r->key, md5((string) $r->value)])->all();
echo json_encode(['settings' => $sum('site_settings'), 'keys' => $keys, 'staff' => DB::table('staff')->count(), 'products' => DB::table('catalog_products')->count(), 'variations' => DB::table('catalog_variations')->count(), 'brands' => $sum('catalog_brands'), 'seasons' => $sum('catalog_seasons'), 'tags' => $sum('catalog_tags'), 'cats' => $sum('catalog_categories'), 'blocks' => $sum('html_blocks')]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/p31_backup.json', json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
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
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/p31_backup.json'), true);
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

// publishes a set through the store the zone uses: {style, categories, brands} with block TITLES instead of ids
const PUBLISH = (set) => String.raw`
use Illuminate\Support\Facades\DB; use App\Storefront\Content\ContentSet; use App\Storefront\Content\ContentStore;
$in = json_decode('${JSON.stringify(set)}', true);
$id = fn ($t) => $t === null ? null : (int) DB::table('html_blocks')->where('title', $t)->value('id');
$set = ContentSet::blank();
$set['style'] = $in['style'] ?? $set['style'];
foreach (['categories', 'brands'] as $kind) { $d = $in[$kind] ?? null; if ($d !== null) { $set[$kind]['default'] = ['cloud' => $d['cloud'] ?? false, 'title' => $d['title'] ?? true, 'top' => $id($d['top'] ?? null), 'bottom' => $d['bottom'] ?? null, 'bottom_heading' => $d['bottom_heading'] ?? '']; } }
[$clean, $errors] = ContentSet::validate($set, ContentStore::existing());
if ($errors !== []) { echo json_encode($errors); return; }
ContentStore::publish($clean);
echo '[]';`;
const NONE = String.raw`
use App\Storefront\Content\ContentSet; use App\Storefront\Content\ContentStore;
ContentStore::publish(ContentSet::blank());
echo '[]';`;
const CAROUSEL = { type: 'carousel', settings: { kind: 'products', source: 'current', entity: null, count: '12', sort: 'newest', arrows: true, label: '' } };
const TOP = 'P3 audit блок отгоре';

// what the page shows, measured in the browser
const look = (page) => page.evaluate(() => {
    const box = (sel) => { const e = document.querySelector(sel); if (! e) return null; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return { l: Math.round(r.left), r: Math.round(r.right), t: Math.round(r.top + scrollY), b: Math.round(r.bottom + scrollY), w: Math.round(r.width), h: Math.round(r.height), display: cs.display, radius: cs.borderTopLeftRadius, border: cs.borderTopWidth + ' ' + cs.borderTopStyle, visible: r.width > 1 && r.height > 1 && cs.display !== 'none' && cs.visibility !== 'hidden' }; };
    const visibleCount = [...document.querySelectorAll('.sf-count')].filter((e) => e.getClientRects().length > 0 && e.getBoundingClientRect().width > 0);

    return {
        subcats: document.querySelectorAll('.sf-subcategories, .sf-subcategory-list').length,
        cloudNav: box('.sf-cloud'),
        chip: box('.sf-cloud .sf-bv-chip') ?? box('.sf-cloud .sf-subcategory a'),
        h1: box('h1'),
        h1Class: document.querySelector('h1')?.className ?? null,
        h1Text: document.querySelector('h1')?.textContent.trim() ?? null,
        h1Lines: document.querySelectorAll('.sf-cat-head .sf-hp-line').length,
        headText: document.querySelector('.sf-cat-head')?.innerText.trim() ?? null,
        headBox: box('.sf-cat-head'),
        topText: document.body.innerText.includes('P3AUDITTOPBLOCK'),
        bottomHeading: box('.sf-lp-slot-bottom .sf-hp-heading-text'),
        bottomHeadingText: document.querySelector('.sf-lp-slot-bottom .sf-hp-heading-text')?.textContent.trim() ?? null,
        carousel: box('.sf-lp-slot-bottom [data-carousel]'),
        counts: visibleCount.map((e) => { const r = e.getBoundingClientRect(); return { text: e.textContent.trim(), cls: e.className, l: Math.round(r.left), r: Math.round(r.right), t: Math.round(r.top + scrollY), cy: Math.round(r.top + scrollY + r.height / 2), align: getComputedStyle(e).textAlign }; }),
        // the sort control that is on screen: the bar's, the side-panel layout's drop-down, or (a phone) the trigger of the drawer
        sort: ['.sf-bctl-sort', '.sf-sortdd', '.sf-ftrigger'].map(box).find((b) => b && b.visible) ?? null,
        trigger: box('.sf-ftrigger'),
        bar: box('.sf-cbar'),
        toolbar: box('.sf-toolbar'),
        cardRadius: getComputedStyle(document.documentElement).getPropertyValue('--sf-card-radius').trim(),
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
    php(NONE);

    const rootUrl = BASE + '/product-category/p3-audit-root';
    const brandUrl = BASE + '/brand/p3-audit-brand';
    const seasonUrl = BASE + '/season/p3-audit-season';
    const tagUrl = BASE + '/product-tag/p3-audit-tag';

    const visit = async (url, width, fn) => {
        const c = await browser.newContext({ viewport: { width, height: 900 }, ignoreHTTPSErrors: true, ...(width < 600 ? { hasTouch: true, isMobile: true } : {}) });
        c.setDefaultTimeout(25000);
        const p = await c.newPage();
        p.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        p.on('response', (r) => { r.status() >= 500 && consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 120)); });
        await p.goto(url, { waitUntil: 'networkidle' });
        try { return await fn(p, await look(p)); } finally { await c.close(); }
    };
    const shot = async (p, name, full = false, clip = null) => { const f = join(OUT, name); shots.push(f); await p.screenshot({ path: f, fullPage: full, ...(clip ? { clip } : {}) }); };

    // ---- 1. no template: no sub-category links; the title with lines; the count on the sort row
    for (const w of [1440, 390]) {
        for (const [name, url] of [['category', rootUrl], ['brand', brandUrl], ['season', seasonUrl], ['tag', tagUrl]]) {
            const l = await visit(url, w, async (p, l) => { w === 1440 && name === 'category' && await shot(p, 'p31-nothing-1440.png', false, { x: 0, y: 0, width: 1440, height: 700 }); return l; });
            check(`${w}px ${name}: no template = no sub-category / cloud links`, l.subcats === 0 && l.cloudNav === null, JSON.stringify({ subcats: l.subcats }));
            check(`${w}px ${name}: the title is the home heading with lines (one h1, two lines)`, l.h1Lines === 2 && /sf-hp-heading-text/.test(l.h1Class), JSON.stringify({ cls: l.h1Class, lines: l.h1Lines }));
            check(`${w}px ${name}: ONE visible count, not in the title area`, l.counts.length === 1 && /\d/.test(l.counts[0].text) && ! /sf-count/.test(l.headText ?? ''), JSON.stringify(l.counts));
            if (name === 'category') {
                const c0 = l.counts[0];
                report[`plain_category_${w}`] = { count: c0, sort: l.sort, bar: l.bar, toolbar: l.toolbar };
                if (w === 1440) {
                    check('1440px: the count is on the SORT row (the same line as the sort control) at the far right of the bar', l.sort && Math.abs(c0.cy - (l.sort.t + l.sort.h / 2)) <= 14 && c0.l > l.sort.r && Math.abs(c0.r - (l.bar?.visible ? l.bar.r : l.toolbar.r)) <= 3, JSON.stringify({ count: c0, sort: l.sort, bar: l.bar, toolbar: l.toolbar }));
                } else {
                    check('390px: the count is at the right end of the row with the filter trigger, no sideways scroll', l.toolbar && c0.r >= l.toolbar.r - 3 && ! l.hscroll, JSON.stringify({ count: c0, toolbar: l.toolbar, trigger: l.trigger }));
                }
            }
        }
    }

    // a narrow window where the count does not fit beside the trigger: it wraps UNDER it, still on the right
    await visit(rootUrl, 390, async (p) => {
        await p.addStyleTag({ content: '.sf-ftrigger { min-width: 17rem !important; }' });
        await sleep(300);
        const l = await look(p);
        const c0 = l.counts[0];
        check('390px with a wide trigger: the count wraps UNDER it and stays right-aligned (no sideways scroll)', c0 && l.trigger && c0.t > l.trigger.b - 4 && c0.r >= l.toolbar.r - 3 && ! l.hscroll, JSON.stringify({ count: c0, trigger: l.trigger, toolbar: l.toolbar }));
        await shot(p, 'p31-count-wrap-390.png', false, { x: 0, y: Math.max(0, l.toolbar.t - 60), width: 390, height: 200 });
    });

    // ---- 2. the cloud on, the shape «Като сайта»
    const tpl = { cloud: true, top: TOP, bottom: CAROUSEL, bottom_heading: 'P3 още от нас' };
    check('published: cloud on, a top block, the carousel «Тази страница», a heading above it, shape «Като сайта»', php(PUBLISH({ style: { cloud_shape: 'site', title_style: 'lines' }, categories: tpl })) === '[]');

    await visit(rootUrl, 1440, async (p, l) => {
        report.site_1440 = { chip: l.chip, radius: l.cardRadius };
        check('1440px: the cloud is shown, the links are the sidebar-button chips (36px box, 1px border)', l.cloudNav?.visible && l.chip && l.chip.h >= 34 && /^1px/.test(l.chip.border), JSON.stringify(l.chip));
        // the cards' radius is a shop setting: show the two extremes in the browser by setting the variable the shop sets
        await p.addStyleTag({ content: ':root { --sf-card-radius: 0px !important; }' });
        const square = (await look(p)).chip.radius;
        await shot(p, 'p31-cloud-site-radius0-1440.png', false, { x: 0, y: 0, width: 1440, height: 600 });
        await p.addStyleTag({ content: ':root { --sf-card-radius: 14px !important; }' });
        const round = (await look(p)).chip.radius;
        await shot(p, 'p31-cloud-site-radius14-1440.png', false, { x: 0, y: 0, width: 1440, height: 600 });
        check('1440px: radius 0 on the cards = square chips; 14px = rounded chips', square === '0px' && round === '14px', JSON.stringify({ square, round }));
        check('1440px: the heading above the bottom carousel is printed (with lines) above it', l.bottomHeadingText === 'P3 още от нас' && l.carousel && l.bottomHeading.b <= l.carousel.t + 2, JSON.stringify({ h: l.bottomHeading, car: l.carousel }));
    });
    await visit(rootUrl, 390, async (p, l) => {
        check('390px: the cloud is NOT visible (display none)', l.cloudNav === null || ! l.cloudNav.visible, JSON.stringify(l.cloudNav));
        check('390px: the top block and the heading above the carousel are still there', l.topText && l.bottomHeadingText === 'P3 още от нас', JSON.stringify({ top: l.topText, h: l.bottomHeadingText }));
        await shot(p, 'p31-root-390.png', true);
    });
    await visit(rootUrl, 1440, async (p) => { await p.evaluate(() => document.querySelector('.sf-lp-slot-bottom')?.scrollIntoView()); await sleep(400); await shot(p, 'p31-bottom-heading-1440.png', false); });

    // ---- 3. the shape «Заоблени»; the title «Обикновено»
    check('published: shape «Заоблени», the title plain', php(PUBLISH({ style: { cloud_shape: 'pill', title_style: 'plain' }, categories: tpl })) === '[]');
    await visit(rootUrl, 1440, async (p, l) => {
        check('1440px: the pill chips are fully rounded (the P3 shape)', l.cloudNav?.visible && l.chip && parseFloat(l.chip.radius) >= 100, JSON.stringify(l.chip));
        check('1440px: the title style «Обикновено»: a plain h1, no lines', l.h1Class === 'sf-title' && l.h1Lines === 0, JSON.stringify({ cls: l.h1Class, lines: l.h1Lines }));
        await shot(p, 'p31-pill-plain-1440.png', false, { x: 0, y: 0, width: 1440, height: 600 });
    });

    // ---- 4. the title off: no visible name, the merchant's top block shows
    check('published: «Покажи заглавието» off', php(PUBLISH({ style: { cloud_shape: 'site', title_style: 'lines' }, categories: { cloud: false, title: false, top: TOP, bottom: null } })) === '[]');
    await visit(rootUrl, 1440, async (p, l) => {
        check('1440px: the h1 is in the page (screen-reader-only) and nothing visible prints the name in the title area; the merchant\'s top block shows', l.h1Text === 'P3 audit корен' && /sf-sr/.test(l.h1Class) && l.h1 && l.h1.w <= 2 && l.h1Lines === 0 && l.topText && l.headBox.h <= 2, JSON.stringify({ h1: l.h1, cls: l.h1Class, head: l.headText, top: l.topText, headBox: l.headBox }));
        await shot(p, 'p31-title-off-1440.png', false, { x: 0, y: 0, width: 1440, height: 600 });
    });

    // ---- 5. the zone, once, with real clicks: the two shop-wide choices above the kind select, the new template fields
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
    await admin.goto(BASE + '/admin/design?zone=content', { waitUntil: 'networkidle' });
    await admin.waitForSelector('.ds-props .fi-fo-field', { timeout: 30000 });
    await sleep(1500);
    const props = admin.locator('.ds-props');
    const labels = await props.locator('.fi-fo-field:visible .fi-fo-field-label-content').allInnerTexts();
    report.zone_labels = labels.map((s) => s.trim());
    const at = (t) => report.zone_labels.findIndex((s) => s.includes(t));
    check('the zone: «Форма на облака» and «Стил на заглавието» are above «Вид страници»', at('Форма на облака') >= 0 && at('Стил на заглавието') >= 0 && at('Форма на облака') < at('Вид страници') && at('Стил на заглавието') < at('Вид страници'), JSON.stringify(report.zone_labels));
    check('the zone: the template has «Покажи заглавието»', at('Покажи заглавието') >= 0, JSON.stringify(report.zone_labels));
    const geometry = await props.evaluate((el) => { const fields = [...el.querySelectorAll('.fi-fo-field')].filter((f) => f.getClientRects().length > 0).slice(0, 8).map((f) => { const b = f.getBoundingClientRect(); return [Math.round(b.left), Math.round(b.top)]; }); return { fields, hscroll: el.scrollWidth > el.clientWidth + 1 }; });
    check('the zone panel is still one field per row and does not scroll sideways', geometry.fields.every((f, i, a) => i === 0 || f[1] > a[i - 1][1]) && ! geometry.hscroll, JSON.stringify(geometry));
    // the shape select really changes the draft: pick «Заоблени», apply, and read the draft
    await props.locator('.fi-fo-field:visible', { hasText: 'Форма на облака' }).first().locator('.fi-select-input-btn, button[role=combobox], [role=combobox]').first().click();
    await sleep(500);
    await admin.locator('[role=option]:visible', { hasText: 'Заоблени' }).first().click();
    await sleep(800);
    await admin.locator('button.ds-btn-primary', { hasText: /Приложи|Apply/ }).click();
    await sleep(2500);
    const draft = JSON.parse(php(String.raw`echo app(App\Settings\Contracts\SiteSettingsRepository::class)->get(App\Settings\AppearanceSettings::CONTENT_DRAFT_KEY) ?? 'null';`));
    check('«Приложи» wrote the chosen shape to the DRAFT', draft?.style?.cloud_shape === 'pill', JSON.stringify(draft?.style));
    const f0 = join(OUT, 'p31-zone-1440.png'); shots.push(f0); await props.screenshot({ path: f0 });
    await ctxAdmin.close();

    check('no page error and no 5xx during the run', consoleErrors.length === 0, JSON.stringify(consoleErrors.slice(0, 5)));
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
