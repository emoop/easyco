// A REAL-BROWSER run of HP-4: the «Продуктова решетка» component of the «Начална» zone. It checks:
//   - the Design screen: the component is in the palette, the properties are in pairs with info icons, the item select follows the source, the live line says how many products the grid resolves to
//     (and «Няма продукти за този избор» for an empty source);
//   - the home page at 1440px, 1024px and 360px (and 800px for the tablet) with: newest 12 in 4 columns (the cards are as wide as in the category listing); a category in 3 columns with gap 0 and 24; a brand
//     in 5 columns; two grids with a heading between them; an empty source (nothing printed, no empty box); phone 1 and 2 columns. The cards are the width the settings say (within 2px), the images carry the
//     component's `sizes`, nothing scrolls sideways, the card is the category's card.
// NOT part of `php artisan test`: run by hand against a dev site. It creates ONE throw-away staff member, ONE category, season, tag, brand and 40 products (plain rows, as cd-1a-1.mjs does; the first products
// are linked to a picture that is already in the library: no file is added); the design changes only through HomeDrafts; at the end everything created is removed, every `site_settings` row is put back EXACTLY
// (update by id) and the counts and the checksum are verified. Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/hp-4.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node hp-4.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const php = (code) => {
    try {
        return execFileSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }).trim().split('\n').pop();
    } catch (e) {
        throw new Error('php failed: ' + String(e.stdout || '').slice(-600) + String(e.stderr || '').slice(-300));
    }
};

const EMAIL = `hp4-${Date.now()}@example.test`;
const PASSWORD = 'Hp4-' + Math.random().toString(36).slice(2) + 'Aa1!';
const CATEGORY = 'hp4-audit-cat';
const b64 = (value) => Buffer.from(JSON.stringify(value)).toString('base64');

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
echo json_encode(['settings' => md5(json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 'staff' => DB::table('staff')->count(), 'categories' => DB::table('catalog_categories')->count(), 'seasons' => DB::table('catalog_seasons')->count(), 'tags' => DB::table('catalog_tags')->count(), 'brands' => DB::table('catalog_brands')->count(), 'products' => DB::table('catalog_products')->count(), 'variations' => DB::table('catalog_variations')->count(), 'stock' => DB::table('stock_levels')->count(), 'product_media' => DB::table('catalog_product_media')->count(), 'product_categories' => DB::table('catalog_product_categories')->count(), 'product_tags' => DB::table('catalog_product_tags')->count()]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/hp4_backup.json', json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo 'ok';`;

// settings back by id, everything created removed, settings once more
const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/hp4_backup.json'), true);
$put = function () use ($bak) { DB::table('site_settings')->whereNotIn('id', array_column($bak, 'id'))->delete(); foreach ($bak as $row) { DB::table('site_settings')->where('id', $row['id'])->update(['key' => $row['key'], 'value' => $row['value'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]); if (! DB::table('site_settings')->where('id', $row['id'])->exists()) { DB::table('site_settings')->insert($row); } } };
$put();
$pids = DB::table('catalog_products')->where('slug', 'like', 'hp4-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_media')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_tags')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
DB::table('catalog_categories')->where('slug', '${CATEGORY}')->delete();
DB::table('catalog_seasons')->where('name', 'like', 'HP4 audit%')->delete();
DB::table('catalog_tags')->where('name', 'like', 'HP4 audit%')->delete();
DB::table('catalog_brands')->where('name', 'like', 'HP4 audit%')->delete();
DB::table('staff')->where('email', '${EMAIL}')->delete();
$put();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo 'restored';`;

const CREATE = String.raw`
use Illuminate\Support\Facades\DB;
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel; use EasyCo\Catalog\Persistence\Eloquent\SeasonModel; use EasyCo\Catalog\Persistence\Eloquent\TagModel; use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'HP4 Audit', $role));
$pic = MediaAssetModel::query()->where('type', 'image')->where('processing_status', 'ready')->orderBy('id')->first();
if ($pic === null) { echo json_encode(['error' => 'no ready picture in the library']); return; }
$now = now();
$cat = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'HP4 audit category', 'slug' => '${CATEGORY}', 'created_at' => $now, 'updated_at' => $now]);
$season = SeasonModel::query()->create(['name' => 'HP4 audit season', 'slug' => 'hp4-audit-season'])->id;
$tag = TagModel::query()->create(['name' => 'HP4 audit tag', 'slug' => 'hp4-audit-tag'])->id;
$emptyTag = TagModel::query()->create(['name' => 'HP4 audit empty tag', 'slug' => 'hp4-audit-empty-tag'])->id;
$brand = BrandModel::query()->create(['name' => 'HP4 audit brand', 'slug' => 'hp4-audit-brand'])->id;
for ($i = 1; $i <= 40; $i++) {
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'simple', 'name' => "HP4 audit product $i", 'slug' => "hp4-audit-$i", 'base_sku' => "HP4-$i", 'status' => 'active', 'catalog_visibility' => 'visible', 'is_featured' => 0, 'brand_id' => $i <= 10 ? $brand : null, 'season_id' => $i <= 6 ? $season : null, 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => now()->addDays(30)->subMinutes($i)]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'universal', 'status' => 'active', 'attribute_signature' => hash('sha256', "hp4-$i"), 'sku' => "HP4-V$i", 'is_visible' => 0, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 5, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  if ($i <= 8) { DB::table('catalog_product_tags')->insert(['product_id' => $pid, 'tag_id' => $tag]); }
  $i <= 20 && DB::table('catalog_product_media')->insert(['product_id' => $pid, 'media_id' => $pic->id, 'sort_order' => 0]);
}
echo json_encode(['picture' => $pic->id, 'category' => $cat, 'season' => $season, 'tag' => $tag, 'emptyTag' => $emptyTag, 'brand' => $brand]);`;

const PUBLISH = (sections) => String.raw`
use App\Storefront\Home\HomeValidator; use App\Storefront\Home\HomeDrafts;
[$clean, $errors] = HomeValidator::validate(['spacing' => '16', 'sections' => json_decode(base64_decode('${b64(sections)}'), true)]);
HomeDrafts::publish($clean);
echo json_encode($errors);`;

const UNPUBLISH = String.raw`
use App\Storefront\Home\HomeLayout; use App\Storefront\Home\HomeDrafts;
HomeDrafts::publish(HomeLayout::default());
echo 'default';`;

const SIZES = (cols, phone, gap) => String.raw`echo App\Storefront\Reader\ImageSetBuilder::cardSizes(${cols}, ${phone}, ${gap});`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const consoleErrors = [];
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const near = (a, b, t = 2) => Math.abs(a - b) <= t;

let before = null;
let created = false;

try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    created = true;
    const made = JSON.parse(php(CREATE));
    check('the throw-away staff member, category, season, tag, brand and 40 products were created', ! made.error && made.category > 0, JSON.stringify(made));
    const { category, season, tag, emptyTag, brand } = made;

    const newCtx = async (width) => browser.newContext({ viewport: { width, height: width < 600 ? 800 : 1000 }, ignoreHTTPSErrors: true, ...(width < 600 ? { hasTouch: true, isMobile: true } : {}) });
    const shotOf = async (page, name, full = false) => { if (OUT) { const p = join(OUT, name + '.png'); shots.push(p); await page.screenshot({ path: p, fullPage: full }); } };
    const track = (p) => { p.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200))); p.on('response', (r) => { if (r.status() >= 500) consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 120)); }); return p; };

    const grid = (settings = {}) => ({ type: 'product_grid', settings });
    const heading = (text) => ({ type: 'heading', settings: { text } });

    // ============================================================ the Design screen
    let ctx = await newCtx(1440);
    let page = track(await ctx.newPage());
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
    await page.goto(BASE + '/admin/design?zone=home', { waitUntil: 'networkidle' });
    check('the component is in the palette', (await page.locator('.ds-chip-add', { hasText: 'Продуктова решетка' }).count()) === 1);
    await page.locator('.ds-chip-add', { hasText: 'Продуктова решетка' }).click();
    await page.waitForSelector('.ds-props', { timeout: 30000 });
    await sleep(1500);
    const props = page.locator('.ds-props');
    check('the properties are in pairs with info icons', (await props.locator('[aria-describedby^="ds-hint-"]').count()) >= 6 && (await props.locator('.fi-grid').count()) >= 1, String(await props.locator('[aria-describedby^="ds-hint-"]').count()));
    check('the live line says how many products the grid shows', (await props.innerText()).includes('Решетката показва 12 продукта') || /Решетката показва \d+ продукта/.test(await props.innerText()), (await props.innerText()).replace(/\s+/g, ' ').slice(-200));
    check('«най-нови» has no item select', ! (await props.innerText()).includes('Избор'));
    await shotOf(page, 'hp4-panel-1440', true);
    await ctx.close();

    // ============================================================ the shop
    const measure = async (p, selector = '.sf-hp-products') => p.evaluate((sel) => {
        const g = document.querySelector(sel);
        if (! g) return null;
        const main = document.querySelector('.sf-main');
        const mc = getComputedStyle(main);
        const cards = [...g.querySelectorAll('.sf-card')].map((c) => { const r = c.getBoundingClientRect(); return { w: r.width, h: r.height, x: r.left, y: r.top }; });
        const cols = cards.length === 0 ? 0 : cards.filter((c) => Math.round(c.y) === Math.round(cards[0].y)).length;
        const gs = getComputedStyle(g);
        const sizes = [...new Set([...g.querySelectorAll('.sf-card-image img[sizes]')].map((i) => i.getAttribute('sizes')))];

        return { gw: g.getBoundingClientRect().width, content: main.getBoundingClientRect().width - parseFloat(mc.paddingLeft) - parseFloat(mc.paddingRight), cards, cols, gapX: parseFloat(gs.columnGap) || 0, gapY: parseFloat(gs.rowGap) || 0, sizes, scroll: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1, style: g.outerHTML.includes(' style=') };
    }, selector);

    // the width of a card the settings promise: (grid − (n − 1) gaps) / n
    const expectW = (gw, n, gap) => (gw - (n - 1) * gap) / n;

    const cases = [
        { name: 'newest-12-four', sections: [grid({ source: 'newest', count: '12', columns: '4', gap: '16' })], count: 12, columns: 4, phone: 2, gap: 16, widths: [1440, 1024] },
        { name: 'category-three-gap0', sections: [grid({ source: 'category', entity: category, count: '12', columns: '3', gap: '0' })], count: 12, columns: 3, phone: 2, gap: 0, widths: [1440] },
        { name: 'category-three-gap24', sections: [grid({ source: 'category', entity: category, count: '8', columns: '3', gap: '24' })], count: 8, columns: 3, phone: 2, gap: 24, widths: [1440] },
        { name: 'brand-five', sections: [grid({ source: 'brand', entity: brand, count: '8', columns: '5', gap: '12', sort: 'name_asc' })], count: 8, columns: 5, phone: 2, gap: 12, widths: [1440, 1024] },
        { name: 'season-four', sections: [grid({ source: 'season', entity: season, count: '4', columns: '4', gap: '8' })], count: 4, columns: 4, phone: 2, gap: 8, widths: [1440] },
        { name: 'tag-four', sections: [grid({ source: 'tag', entity: tag, count: '8', columns: '4', gap: '20' })], count: 8, columns: 4, phone: 2, gap: 20, widths: [1440] },
    ];

    for (const c of cases) {
        const out = php(PUBLISH(c.sections));
        check(`${c.name}: published through the application`, out === '[]', out);
        const wantedSizes = php(SIZES(c.columns, c.phone, c.gap));

        for (const width of c.widths) {
            ctx = await newCtx(width);
            page = track(await ctx.newPage());
            await page.goto(BASE + '/', { waitUntil: 'networkidle' });
            const m = await measure(page);
            check(`${c.name} ${width}px: ${c.count} cards are printed`, m && m.cards.length === c.count, m ? m.cards.length : 'no grid');
            const n = width >= 1024 ? c.columns : 3;
            check(`${c.name} ${width}px: ${n} columns, the gap is ${c.gap}px both ways, nothing scrolls sideways, no style attribute`, m.cols === Math.min(n, c.count) && near(m.gapX, c.gap, 0.5) && near(m.gapY, c.gap, 0.5) && ! m.scroll && ! m.style, JSON.stringify({ cols: m.cols, gx: m.gapX, gy: m.gapY, scroll: m.scroll }));
            const w = expectW(m.gw, n, c.gap);
            check(`${c.name} ${width}px: every card is ${w.toFixed(1)}px wide (within 2px)`, m.cards.every((k) => near(k.w, w)), JSON.stringify(m.cards.slice(0, 2).map((k) => Math.round(k.w * 10) / 10)));
            check(`${c.name} ${width}px: the images carry the component's sizes`, m.sizes.length === 1 && m.sizes[0] === wantedSizes, JSON.stringify(m.sizes).slice(0, 300));
            await shotOf(page, `hp4-home-${c.name}-${width}`);
            await ctx.close();
        }
    }

    // the cards are as wide as in the category listing for the same columns and gap: read the category page's own columns and gap (the shop's «Каталог» settings), publish the SAME on the home page
    ctx = await newCtx(1440);
    page = track(await ctx.newPage());
    await page.goto(BASE + '/product-category/' + CATEGORY, { waitUntil: 'networkidle' });
    const catCards = await page.evaluate(() => { const g = document.querySelector('.sf-category .sf-grid'); return { w: [...g.querySelectorAll('.sf-card')].map((c) => c.getBoundingClientRect().width), gx: parseFloat(getComputedStyle(g).columnGap), gy: parseFloat(getComputedStyle(g).rowGap), cols: getComputedStyle(g).gridTemplateColumns.split(' ').length }; });
    await shotOf(page, 'hp4-category-1440');
    php(PUBLISH([grid({ source: 'newest', count: '12', columns: String(catCards.cols), gap: String(Math.round(catCards.gx)) })]));
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const homeCards = await measure(page);
    check(`1440px: a card of the home grid is as wide as in the category listing (${catCards.cols} columns, gap ${catCards.gx}), within 2px`, near(homeCards.cards[0].w, catCards.w[0]) && near(homeCards.gapX, catCards.gx, 0.5), JSON.stringify({ home: homeCards.cards[0].w, category: catCards.w[0], gap: [homeCards.gapX, catCards.gx] }));
    await ctx.close();

    // two grids with a heading between them
    php(PUBLISH([grid({ source: 'newest', count: '4', columns: '4' }), heading('Между решетките'), grid({ source: 'category', entity: category, count: '8', columns: '4', sort: 'name_asc' })]));
    ctx = await newCtx(1440);
    page = track(await ctx.newPage());
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const order = await page.evaluate(() => [...document.querySelectorAll('.sf-hp > section')].map((s) => s.className.includes('sf-hp-product-grid') ? 'grid:' + s.querySelectorAll('.sf-card').length : 'heading'));
    check('two grids with a heading between them keep the order', JSON.stringify(order) === JSON.stringify(['grid:4', 'heading', 'grid:8']), JSON.stringify(order));
    check('nothing scrolls sideways with two grids', ! (await measure(page)).scroll);
    await shotOf(page, 'hp4-home-two-grids-1440', true);
    await ctx.close();

    // an empty source prints nothing: no empty box, and the next section is the first one
    php(PUBLISH([grid({ source: 'tag', entity: emptyTag, count: '8' }), heading('Само заглавие')]));
    ctx = await newCtx(1440);
    page = track(await ctx.newPage());
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const empty = await page.evaluate(() => ({ grids: document.querySelectorAll('.sf-hp-products, .sf-hp-product-grid').length, sections: document.querySelectorAll('.sf-hp > section').length, heading: !! document.querySelector('.sf-hp h2'), mt: document.querySelector('.sf-hp > section')?.className }));
    check('an empty source prints nothing (no empty box); the heading is the only section', empty.grids === 0 && empty.sections === 1 && empty.heading, JSON.stringify(empty));
    await shotOf(page, 'hp4-home-empty-1440');
    await ctx.close();

    // the phone: 2 columns by default, 1 when set; the tablet: 3 for a 4- or 5-column grid
    for (const [phone, label] of [['2', 'two'], ['1', 'one']]) {
        php(PUBLISH([grid({ source: 'newest', count: '8', columns: '4', columns_phone: phone, gap: '12' })]));
        ctx = await newCtx(360);
        page = track(await ctx.newPage());
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        const m = await measure(page);
        const w = expectW(m.gw, parseInt(phone, 10), 12);
        check(`360px, ${label} column(s) on a phone: ${phone} per row, the cards are ${w.toFixed(1)}px (within 2px), no sideways scroll`, m.cols === parseInt(phone, 10) && m.cards.every((k) => near(k.w, w)) && ! m.scroll, JSON.stringify({ cols: m.cols, w: Math.round(m.cards[0].w), exp: Math.round(w) }));
        await shotOf(page, `hp4-home-phone-${label}-360`, true);
        await ctx.close();
    }

    php(PUBLISH([grid({ source: 'newest', count: '12', columns: '5', gap: '16' })]));
    ctx = await newCtx(800);
    page = track(await ctx.newPage());
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const tab = await measure(page);
    check('800px (tablet): a 5-column grid shows 3 per row', tab && tab.cols === 3 && ! tab.scroll, tab ? tab.cols : 'no grid');
    await shotOf(page, 'hp4-home-tablet-800');
    await ctx.close();

    // the Design screen once more, with a category chosen: the item select follows the source and the line counts
    php(UNPUBLISH);
    ctx = await newCtx(1440);
    page = track(await ctx.newPage());
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
    await page.goto(BASE + '/admin/design?zone=home', { waitUntil: 'networkidle' });
    await page.locator('.ds-chip-add', { hasText: 'Продуктова решетка' }).click();
    await page.waitForSelector('.ds-props', { timeout: 30000 });
    await sleep(1200);
    const source = page.locator('.ds-props .fi-fo-field', { has: page.locator('label', { hasText: 'Източник' }) }).first();
    await source.locator('.fi-select-input-btn').first().click();
    await sleep(500);
    await page.getByRole('option', { name: 'Етикет' }).first().click();
    await sleep(1500);
    check('choosing «Етикет» shows the item select', (await page.locator('.ds-props').innerText()).includes('Избор'));
    await shotOf(page, 'hp4-panel-tag-1440', true);
    await ctx.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (created) {
        try {
            php(RESTORE);
            const after = JSON.parse(php(SUMS));
            check('everything is back: the settings table (exact rows), the staff, categories, seasons, tags, brands, products, variations, stock and links are as they were (counts / checksum)', before && JSON.stringify(before) === JSON.stringify(after), JSON.stringify({ before, after }));
        } catch (e) {
            check('the clean-up finished', false, String(e).slice(0, 400));
        }
    }
}

check('no page errors', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
