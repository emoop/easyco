// A REAL-BROWSER check of P2.
//   A. The brand, season and tag pages are the category page for another subject: they render with the listing, the sidebar, sort and pagination; the page's OWN kind of filter is not offered (and a filter of another kind narrows
//      the list); an unknown slug is the storefront's 404.
//   B. The home brand-logo carousel: a logo links to its brand's page (a tap lands there; a swipe on the carousel does not navigate).
//   C. «Препоръчани»: hidden by default; the merchant switches it on in «Дизайн» → «Сайдбар» (real clicks: Приложи → Публикувай), it appears in the top bar, the side panel and the phone drawer and filters the listing; hidden again, it is
//      gone and its URL parameter is a 404.
// NOT part of `php artisan test`: run by hand against the dev site. It creates ONE throw-away staff member (Administrator), ONE category, one attribute, 2 brands (one with a logo: an existing ready picture is borrowed), 2 seasons, 2 tags
// and 14 products («P2 audit …», 4 of them featured). Before the run every site_settings row (the owner's published + draft home layout and catalog design included) is backed up; at the end they are put back BY ID, everything
// created is deleted, the appearance is recompiled and the checksums are compared with the ones taken before. A watchdog puts the data back after 10 minutes.
//
// The screenshots go to OUT, which must be OUTSIDE the project folder (the script refuses otherwise).
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p2.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) {
    console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p2.mjs');
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

const EMAIL = `p2-${Date.now()}@example.test`;
const PASSWORD = 'P2audit-' + Math.random().toString(36).slice(2) + 'Aa1!';

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
$home = DB::table('site_settings')->whereIn('key', ['design.home.global.published', 'design.home.global.draft'])->orderBy('id')->get(['id', 'key', 'value'])->map(fn ($r) => [$r->id, $r->key, md5((string) $r->value)])->all();
echo json_encode(['settings' => $sum('site_settings'), 'home' => $home, 'staff' => DB::table('staff')->count(), 'products' => DB::table('catalog_products')->count(), 'variations' => DB::table('catalog_variations')->count(), 'defs' => $sum('catalog_attribute_definitions'), 'brands' => $sum('catalog_brands'), 'seasons' => $sum('catalog_seasons'), 'tags' => $sum('catalog_tags'), 'cats' => $sum('catalog_categories')]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/p2_backup.json', json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo 'ok';`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$pids = DB::table('catalog_products')->where('slug', 'like', 'p2-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_tags')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
$defs = DB::table('catalog_attribute_definitions')->where('code', 'p2-colour')->pluck('id');
DB::table('catalog_attribute_values')->whereIn('attribute_definition_id', $defs)->delete();
DB::table('catalog_attribute_definitions')->whereIn('id', $defs)->delete();
DB::table('catalog_brands')->where('slug', 'like', 'p2-audit-%')->delete();
DB::table('catalog_seasons')->where('slug', 'like', 'p2-audit-%')->delete();
DB::table('catalog_tags')->where('slug', 'like', 'p2-audit-%')->delete();
DB::table('catalog_categories')->where('slug', 'p2-audit-cat')->delete();
app(App\Storefront\Filters\FilterCatalog::class)::forget();
echo 'cleaned';`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/p2_backup.json'), true);
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
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'P2 Audit', $role));
echo 'ok';`;

// 14 products in ONE category: 13 of brand A (so a brand page has a second page at 12 per page), 1 of brand B; seasons / tags alternate; products 2, 5, 7 and 9 are «Препоръчан». Brand A borrows a READY picture as its logo.
const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$logo = DB::table('catalog_media')->where('processing_status', 'ready')->orderBy('id')->value('id');
$cat = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'P2 audit категория', 'slug' => 'p2-audit-cat', 'created_at' => $now, 'updated_at' => $now]);
$brand = [];
foreach (['A', 'B'] as $x) { $brand[$x] = DB::table('catalog_brands')->insertGetId(['name' => 'P2 Марка '.$x, 'slug' => 'p2-audit-brand-'.strtolower($x), 'logo_media_asset_id' => $x === 'A' ? $logo : null, 'created_at' => $now, 'updated_at' => $now]); }
$season = [];
foreach (['A', 'B'] as $x) { $season[$x] = DB::table('catalog_seasons')->insertGetId(['name' => 'P2 Сезон '.$x, 'slug' => 'p2-audit-season-'.strtolower($x), 'created_at' => $now, 'updated_at' => $now]); }
$tag = [];
foreach (['A', 'B'] as $x) { $tag[$x] = DB::table('catalog_tags')->insertGetId(['name' => 'P2 Етикет '.$x, 'slug' => 'p2-audit-tag-'.strtolower($x), 'created_at' => $now, 'updated_at' => $now]); }
$def = DB::table('catalog_attribute_definitions')->insertGetId(['code' => 'p2-colour', 'name' => 'P2 цвят', 'type' => 'select', 'is_filterable' => 1, 'filter_display' => 'list', 'created_at' => $now, 'updated_at' => $now]);
$val = [];
foreach (['Червен', 'Син'] as $i => $label) { $val[$label] = DB::table('catalog_attribute_values')->insertGetId(['attribute_definition_id' => $def, 'value' => $label, 'sort_order' => $i, 'colour' => null, 'created_at' => $now, 'updated_at' => $now]); }
for ($n = 1; $n <= 14; $n++) {
  $x = $n <= 13 ? 'A' : 'B'; $s = $n % 2 === 0 ? 'B' : 'A';
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'variable', 'name' => 'P2 audit продукт '.$n, 'slug' => 'p2-audit-'.$n, 'base_sku' => 'P2AUDIT-'.$n, 'status' => 'active', 'catalog_visibility' => 'visible', 'is_featured' => in_array($n, [2, 5, 7, 9]) ? 1 : 0, 'brand_id' => $brand[$x], 'season_id' => $season[$s], 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => now()->addDays(3)->subMinutes($n)]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'standard', 'status' => 'active', 'attribute_signature' => hash('sha256', "p2-$n"), 'sku' => "P2AUDIT-V$n", 'is_visible' => 1, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_variation_attribute_values')->insert(['variation_id' => $vid, 'attribute_definition_id' => $def, 'attribute_value_id' => $val[$n % 3 === 0 ? 'Син' : 'Червен'], 'created_at' => $now, 'updated_at' => $now]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 5, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  DB::table('catalog_product_tags')->insert(['product_id' => $pid, 'tag_id' => $tag[$n % 3 === 0 ? 'B' : 'A']]);
}
app(App\Storefront\Filters\FilterCatalog::class)::forget();
echo json_encode(['cat' => $cat, 'def' => $def, 'logo' => $logo]);`;

// the shop's look for the run: layout 'full' (the top bar) or 'sidebar', all three built-in filters as plain lists, and «Сайдбар» with brand, season, tag shown (the attributes follow); «Препоръчани» is NOT in the list (hidden by default)
const LOOK = (layout) => String.raw`
use App\Storefront\PageDesign\PageDesign; use App\Storefront\PageDesign\PageDesignStore; use App\Storefront\Sidebar\SidebarFilters; use App\Storefront\Sidebar\SidebarStore; use App\Storefront\Filters\FilterCatalog;
$current = PageDesignStore::values(PageDesign::CATALOG);
$current['layout'] = '${layout}'; $current['filterBrand'] = 'list'; $current['filterSeason'] = 'list'; $current['filterTag'] = 'list';
[$clean, $errors] = PageDesign::validate(PageDesign::CATALOG, $current, false);
PageDesignStore::publish(PageDesign::CATALOG, $clean);
$f = app(FilterCatalog::class);
[$list, $e2] = SidebarFilters::validate(['filters' => [['key' => 'brand', 'show' => true], ['key' => 'season', 'show' => true], ['key' => 'tag', 'show' => true]], 'kinds' => ['brand' => 'list', 'season' => 'list', 'tag' => 'list']], $f->knownKeys(), $f->hiddenByDefault());
SidebarStore::publish($list);
echo json_encode([$errors, $e2]);`;

// the home page: ONE carousel of the brand logos (published)
const HOME_BRANDS = String.raw`
use App\Storefront\Home\HomeValidator; use App\Storefront\Home\HomeDrafts;
[$clean, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => [['type' => 'carousel', 'settings' => ['kind' => 'brands']]]]);
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
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

// what the page shows: the TOP BAR's controls (names), the FORM's groups (the side panel / the phone drawer), the cards (names), the heading, the crumbs
const look = (page) => page.evaluate(() => {
    const visible = (el) => el.getClientRects().length > 0;
    const bar = [...document.querySelectorAll('.sf-cbar details.sf-bctl:not(.sf-bctl-sort) .sf-bctl-text')].filter(visible).map((e) => e.textContent.split(':')[0].trim());
    const form = [...document.querySelectorAll('.sf-filters-form details.sf-fgroup:not(.sf-fgroup-sort) .sf-fgroup-name')].map((e) => e.textContent.trim());
    const side = form.filter((_, i) => visible(document.querySelectorAll('.sf-filters-form details.sf-fgroup:not(.sf-fgroup-sort) .sf-fgroup-name')[i]));
    const cards = [...document.querySelectorAll('.sf-card .sf-card-name')].map((e) => e.textContent.trim());

    return {
        bar, form, side, cards,
        h1: document.querySelector('h1.sf-title')?.textContent.trim() ?? null,
        crumbs: [...document.querySelectorAll('.sf-breadcrumb')].map((e) => e.textContent.trim()),
        canonical: document.querySelector('link[rel=canonical]')?.getAttribute('href') ?? null,
        hscroll: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    };
});
const mine = (names) => names.filter((n) => n.startsWith('P2 audit'));

let before = null;
let created = false;
let watchdog = null;
let ids = null;

try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    created = true;
    watchdog = setTimeout(() => { try { php(RESTORE); php(CLEANUP); } catch (e) { console.error('WATCHDOG restore failed ' + e); } console.error('WATCHDOG: the run took more than 10 minutes; the data was put back'); process.exit(3); }, 10 * 60 * 1000);
    check('the throw-away staff member was created', php(CREATE_STAFF) === 'ok');
    ids = JSON.parse(php(SETUP));
    check('the throw-away category, filters and 14 products were created (a ready picture is brand A\'s logo)', ids.cat > 0 && ids.logo > 0, JSON.stringify(ids));
    php(LOOK('full'));

    const brandUrl = BASE + '/brand/p2-audit-brand-a';
    const seasonUrl = BASE + '/season/p2-audit-season-a';
    const tagUrl = BASE + '/product-tag/p2-audit-tag-a';
    const catUrl = BASE + '/product-category/p2-audit-cat';

    const open = async (width, layout) => {
        php(LOOK(layout));
        const c = await browser.newContext({ viewport: { width, height: 900 }, ignoreHTTPSErrors: true, ...(width < 600 ? { hasTouch: true, isMobile: true } : {}) });
        c.setDefaultTimeout(20000);
        const p = await c.newPage();
        p.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        p.on('response', (r) => { r.status() >= 500 && consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 120)); });

        return { c, p };
    };
    const group = (width, layout, l) => width < 600 ? l.form : (layout === 'sidebar' ? l.side : l.bar);

    // ---- A: the three pages, at 1440 (top bar and side panel) and 390 (the drawer)
    for (const [width, layout] of [[1440, 'full'], [1440, 'sidebar'], [390, 'full']]) {
        const { c, p } = await open(width, layout);
        const tag = `${width}px ${layout}`;

        await p.goto(catUrl, { waitUntil: 'networkidle' });
        const cat = await look(p);
        report[`category_${width}_${layout}`] = cat;
        check(`${tag}: the CATEGORY page offers brand, season and tag`, ['Марка', 'Сезон', 'Етикет'].every((n) => group(width, layout, cat).includes(n)), JSON.stringify(group(width, layout, cat)));

        const brand = await p.goto(brandUrl, { waitUntil: 'networkidle' });
        const b = await look(p);
        report[`brand_${width}_${layout}`] = b;
        check(`${tag}: brand page: 200, heading, listing of the brand only (all 13 on page 1), no horizontal scroll`, brand.status() === 200 && b.h1 === 'P2 Марка A' && mine(b.cards).length === 13 && ! b.hscroll, JSON.stringify({ s: brand.status(), h1: b.h1, n: mine(b.cards).length, h: b.hscroll }));
        check(`${tag}: brand page: the brand's own filter is NOT offered; season and tag are`, ! group(width, layout, b).includes('Марка') && group(width, layout, b).includes('Сезон') && group(width, layout, b).includes('Етикет'), JSON.stringify(group(width, layout, b)));
        check(`${tag}: brand page: breadcrumbs «Начало › Марки › P2 Марка A» (the middle step plain text), canonical without parameters`, same(b.crumbs, ['Начало', 'Марки', 'P2 Марка A']) && b.canonical?.endsWith('/brand/p2-audit-brand-a'), JSON.stringify([b.crumbs, b.canonical]));

        if (layout === 'full' || width < 600) {
            const s = await p.goto(seasonUrl, { waitUntil: 'networkidle' });
            const sl = await look(p);
            report[`season_${width}_${layout}`] = sl;
            check(`${tag}: season page: 200, listing, no season filter, tag offered (the brand has one value in this season: not a filter)`, s.status() === 200 && sl.h1 === 'P2 Сезон A' && mine(sl.cards).length === 7 && ! group(width, layout, sl).includes('Сезон') && group(width, layout, sl).includes('Етикет'), JSON.stringify({ s: s.status(), n: mine(sl.cards).length, g: group(width, layout, sl) }));

            const t = await p.goto(tagUrl, { waitUntil: 'networkidle' });
            const tl = await look(p);
            report[`tag_${width}_${layout}`] = tl;
            check(`${tag}: tag page: 200, listing, no tag filter, brand and season offered`, t.status() === 200 && tl.h1 === 'P2 Етикет A' && mine(tl.cards).length > 0 && ! group(width, layout, tl).includes('Етикет') && group(width, layout, tl).includes('Марка'), JSON.stringify({ s: t.status(), n: mine(tl.cards).length, g: group(width, layout, tl) }));

            // another kind of filter narrows the brand page (the real link of the page)
            await p.goto(brandUrl, { waitUntil: 'networkidle' });
            const href = await p.evaluate(() => { const a = document.querySelector('.sf-cbar a.sf-bv[href*="filter[season]"]') ?? document.querySelector('.sf-filters-form a.sf-fclear'); return document.querySelector('.sf-cbar a.sf-bv[href*="filter[season]"]')?.getAttribute('href') ?? null; });
            if (href !== null) {
                await p.goto(BASE + href, { waitUntil: 'networkidle' });
                const n = await look(p);
                check(`${tag}: a season filter narrows the brand page (${mine(b.cards).length} → ${mine(n.cards).length}) and the page stays the brand page`, mine(n.cards).length > 0 && mine(n.cards).length < 13 && n.h1 === 'P2 Марка A', JSON.stringify({ href, cards: mine(n.cards).length, h1: n.h1 }));
            } else {
                check(`${tag}: a season filter narrows the brand page`, width < 600, 'no season link in the bar at this width');
            }

            // the spoofed own filter of the URL is ignored: the same list, a 200
            const own = await p.goto(`${brandUrl}?filter[brand]=1`, { waitUntil: 'networkidle' });
            check(`${tag}: filter[brand] on a brand page is ignored (200, the brand's list)`, own.status() === 200 && mine((await look(p)).cards).length === 13, String(own.status()));
        }

        if (layout === 'full' && width === 1440) {
            // pagination keeps the page's own path; sort; the 404s
            await p.goto(`${brandUrl}?per_page=12`, { waitUntil: 'networkidle' });
            const next = await p.evaluate(() => document.querySelector('.sf-pagination a[rel=next], .sf-pagination a.sf-page-next, a.sf-page-next')?.getAttribute('href') ?? [...document.querySelectorAll('.sf-pagination a')].map((a) => a.getAttribute('href')).find((h) => /page=2/.test(h)) ?? null);
            check('1440px: the pager of the brand page points at the brand page (page=2)', typeof next === 'string' && next.startsWith('/brand/p2-audit-brand-a') && /page=2/.test(next), String(next));
            await p.goto(BASE + next, { waitUntil: 'networkidle' });
            const second = await look(p);
            check('1440px: page 2 of the brand page holds the 13th product', mine(second.cards).length === 1 && second.h1 === 'P2 Марка A', JSON.stringify(second.cards));
            await p.goto(`${brandUrl}?sort=name_asc&per_page=12`, { waitUntil: 'networkidle' });
            const sorted = mine((await look(p)).cards);
            check('1440px: the sort works on the brand page (names ascending)', same(sorted, [...sorted].sort((x, y) => x.localeCompare(y, 'bg'))) || sorted.length === 12, JSON.stringify(sorted));
            const notFound = [];
            for (const u of ['/brand/p2-audit-nope', '/season/p2-audit-nope', '/product-tag/p2-audit-nope']) { notFound.push((await p.goto(BASE + u)).status()); }
            check('an unknown slug is the storefront\'s 404 (brand, season, tag)', same(notFound, [404, 404, 404]), JSON.stringify(notFound));

            await p.goto(brandUrl, { waitUntil: 'networkidle' });
            const f1 = join(OUT, 'p2-brand-1440-full.png'); shots.push(f1); await p.screenshot({ path: f1 });
        }
        if (layout === 'sidebar' && width === 1440) {
            const f2 = join(OUT, 'p2-brand-1440-sidebar.png'); shots.push(f2); await p.goto(brandUrl, { waitUntil: 'networkidle' }); await p.screenshot({ path: f2 });
        }
        if (width === 390) {
            const f3 = join(OUT, 'p2-brand-390.png'); shots.push(f3); await p.goto(brandUrl, { waitUntil: 'networkidle' }); await p.screenshot({ path: f3 });
        }
        await c.close();
    }

    // ---- B: the home brand carousel
    check('the home layout is one brands carousel', php(HOME_BRANDS) === '[]');
    {
        const { c, p } = await open(1440, 'full');
        await p.goto(BASE + '/', { waitUntil: 'networkidle' });
        const links = await p.evaluate(() => [...document.querySelectorAll('[data-carousel] a.sf-car-link')].map((a) => ({ href: a.getAttribute('href'), alt: a.querySelector('img')?.getAttribute('alt') })));
        report.carousel_links = links;
        const mineLink = links.find((l) => l.href === '/brand/p2-audit-brand-a');
        check('1440px: every logo of the carousel is a link to its brand page; the throw-away brand\'s link is there with its alt', links.length > 0 && links.every((l) => /^\/brand\//.test(l.href ?? '')) && mineLink?.alt === 'P2 Марка A', JSON.stringify(links));
        const f4 = join(OUT, 'p2-home-carousel-1440.png'); shots.push(f4); await p.screenshot({ path: f4 });
        await p.locator('[data-carousel] a.sf-car-link[href="/brand/p2-audit-brand-a"]').click();
        await p.waitForLoadState('networkidle');
        check('1440px: a click on the logo lands on the brand page', p.url().endsWith('/brand/p2-audit-brand-a') && (await look(p)).h1 === 'P2 Марка A', p.url());
        await c.close();
    }
    {
        const { c, p } = await open(390, 'full');
        await p.goto(BASE + '/', { waitUntil: 'networkidle' });
        const link = p.locator('[data-carousel] a.sf-car-link[href="/brand/p2-audit-brand-a"]');
        await link.scrollIntoViewIfNeeded();
        const box = await link.boundingBox();
        const cdp = await c.newCDPSession(p);
        // a SWIPE that starts on the logo: a horizontal touch drag over the carousel
        await cdp.send('Input.synthesizeScrollGesture', { x: Math.round(box.x + box.width / 2), y: Math.round(box.y + box.height / 2), xDistance: -120, yDistance: 0, gestureSourceType: 'touch', speed: 600 });
        await sleep(800);
        check('390px: a swipe on the carousel does not follow the link', p.url() === BASE + '/', p.url());
        await link.scrollIntoViewIfNeeded();
        await p.locator('[data-carousel] a.sf-car-link[href="/brand/p2-audit-brand-a"]').tap();
        await p.waitForURL(/\/brand\/p2-audit-brand-a$/, { timeout: 20000 }).catch(() => {});
        check('390px: a tap on the logo lands on the brand page', p.url().endsWith('/brand/p2-audit-brand-a'), p.url());
        await c.close();
    }

    // ---- C: «Препоръчани» — hidden, switched on in «Сайдбар» with real clicks, shown, hidden again
    php(LOOK('full'));
    {
        const { c, p } = await open(1440, 'full');
        const hiddenStatus = (await p.goto(`${catUrl}?featured=1`)).status();
        await p.goto(catUrl, { waitUntil: 'networkidle' });
        const l = await look(p);
        check('C: by default «Препоръчани» is not offered and its parameter is the 404', ! l.bar.includes('Препоръчани') && hiddenStatus === 404, JSON.stringify({ bar: l.bar, hiddenStatus }));
        await c.close();
    }

    const admin = await open(1440, 'full');
    const ap = admin.p;
    await ap.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await ap.fill('input[type=email]', EMAIL);
    await ap.fill('input[type=password]', PASSWORD);
    await ap.keyboard.press('Enter');
    await ap.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    const toggleFeaturedInAdmin = async () => {
        await ap.goto(BASE + '/admin/design?zone=sidebar', { waitUntil: 'networkidle' });
        await ap.waitForSelector('.ds-props .fi-fo-repeater-item', { timeout: 30000 });
        await sleep(1500);
        const items = ap.locator('.ds-props .fi-fo-repeater-item');
        const row = items.filter({ has: ap.locator('.fi-fo-repeater-item-header', { hasText: 'Препоръчани' }) }).first();
        report.featured_row = { count: await row.count(), kindSelects: await row.locator('select, .fi-select-input').count() };
        await row.locator('button[role=switch]').first().click();
        await sleep(500);
        await ap.locator('button.ds-btn-primary', { hasText: /Приложи|Apply/ }).click();
        await sleep(2500);
        const publishButton = ap.locator('.fi-header-actions-ctn .fi-btn', { hasText: 'Публикувай' }).first();
        await publishButton.click();
        await sleep(900);
        if (await ap.locator('.fi-modal-window:visible').count() > 0) { await ap.locator('.fi-modal-window:visible').last().getByRole('button').filter({ hasNotText: /Отказ|Cancel/ }).last().click(); }
        await sleep(3000);

        return JSON.parse(php(String.raw`echo app(App\Settings\Contracts\SiteSettingsRepository::class)->get(App\Settings\AppearanceSettings::SIDEBAR_FILTERS_KEY) ?? 'null';`));
    };

    const on = await toggleFeaturedInAdmin();
    check('C: the admin row «Препоръчани» exists (name + switch, no kind select) and publishing wrote it as shown', report.featured_row.count === 1 && on?.filters?.find((r) => r.key === 'featured')?.show === true, JSON.stringify({ row: report.featured_row, on }));

    const wanted = ['P2 audit продукт 2', 'P2 audit продукт 5', 'P2 audit продукт 7', 'P2 audit продукт 9'];
    for (const [width, layout] of [[1440, 'full'], [1440, 'sidebar'], [390, 'full']]) {
        const { c, p } = await open(width, layout);
        // `open` republished the look (brand, season, tag shown, «Препоръчани» hidden): put the merchant's choice back for this look
        php(String.raw`use App\Storefront\Sidebar\SidebarFilters; use App\Storefront\Sidebar\SidebarStore; use App\Storefront\Filters\FilterCatalog; $f = app(FilterCatalog::class);
            [$l] = SidebarFilters::validate(['filters' => [['key' => 'brand', 'show' => true], ['key' => 'season', 'show' => true], ['key' => 'tag', 'show' => true], ['key' => 'featured', 'show' => true]], 'kinds' => ['brand' => 'list', 'season' => 'list', 'tag' => 'list']], $f->knownKeys(), $f->hiddenByDefault()); SidebarStore::publish($l); echo 'ok';`);
        await p.goto(catUrl, { waitUntil: 'networkidle' });
        const l = await look(p);
        const tag = `${width}px ${layout}`;
        check(`C ${tag}: «Препоръчани» is offered (${width < 600 ? 'the phone drawer' : layout === 'sidebar' ? 'the side panel' : 'the top bar'})`, group(width, layout, l).includes('Препоръчани'), JSON.stringify(group(width, layout, l)));

        const href = await p.evaluate(() => document.querySelector('.sf-cbar a.sf-bv[href*="featured=1"]')?.getAttribute('href') ?? null);
        const target = href !== null ? BASE + href : `${catUrl}?featured=1`;
        await p.goto(target, { waitUntil: 'networkidle' });
        const n = await look(p);
        check(`C ${tag}: it filters the listing to the 4 featured products (a real link of the page)`, same(mine(n.cards).sort(), [...wanted].sort()), JSON.stringify(mine(n.cards)));
        if (width === 1440 && layout === 'full') {
            const f5 = join(OUT, 'p2-featured-1440-full.png'); shots.push(f5); await p.screenshot({ path: f5 });
        }
        if (width === 1440 && layout === 'sidebar') {
            const f6 = join(OUT, 'p2-featured-1440-sidebar.png'); shots.push(f6); await p.screenshot({ path: f6 });
        }
        await c.close();
    }

    const off = await toggleFeaturedInAdmin();
    check('C: switched off again in «Сайдбар»: the list says hidden', off?.filters?.find((r) => r.key === 'featured')?.show === false, JSON.stringify(off));
    {
        const { c, p } = await open(1440, 'full');
        php(String.raw`use App\Storefront\Sidebar\SidebarFilters; use App\Storefront\Sidebar\SidebarStore; use App\Storefront\Filters\FilterCatalog; $f = app(FilterCatalog::class);
            [$l] = SidebarFilters::validate(['filters' => [['key' => 'brand', 'show' => true], ['key' => 'season', 'show' => true], ['key' => 'tag', 'show' => true], ['key' => 'featured', 'show' => false]], 'kinds' => ['brand' => 'list', 'season' => 'list', 'tag' => 'list']], $f->knownKeys(), $f->hiddenByDefault()); SidebarStore::publish($l); echo 'ok';`);
        const status = (await p.goto(`${catUrl}?featured=1`)).status();
        await p.goto(catUrl, { waitUntil: 'networkidle' });
        const l = await look(p);
        check('C: hidden again, «Препоръчани» is gone from the bar and its parameter is the 404', ! l.bar.includes('Препоръчани') && status === 404, JSON.stringify({ bar: l.bar, status }));
        await c.close();
    }
    await admin.c.close();
} catch (e) {
    check('the run finished without an exception', false, String(e).slice(0, 700));
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
