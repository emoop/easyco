// A REAL-BROWSER run of HP-3: the «Плочки» component of the «Начална» zone. It checks:
//   - the Design screen: the component is in the palette, the size hint carries TileGeometry's numbers (for the desktop and for a phone), the tile list and the look settings are there;
//   - the home page at 1440px, 1024px and 360px (and 800px for the tablet) with: 4 columns square radius 0; 4 columns portrait radius 20; 5 columns square radius 50 (circles) with long captions; 3 columns
//     landscape with gap 0 and 24; a tile without a picture (not printed); a season tile with a free link: the pictures match TileGeometry within 2px (for the REAL content width), a circle is a circle
//     (width = height, radius 50%), a long caption ends with an ellipsis and never overflows, the whole tile is the click target, a phone shows 2 and 1 columns, a tablet shows 3, nothing scrolls sideways.
// NOT part of `php artisan test`: run by hand against a dev site. It creates ONE throw-away staff member and ONE throw-away season, tag and brand (through the application's own models; the category tiles use
// categories that already exist and a picture that is already in the library: no file is added); the design changes only through HomeDrafts; at the end the three entities and the staff member are removed, every
// `site_settings` row is put back EXACTLY (update by id) and the counts and the checksum are verified. Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/hp-3.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node hp-3.mjs');
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

const EMAIL = `hp3-${Date.now()}@example.test`;
const PASSWORD = 'Hp3-' + Math.random().toString(36).slice(2) + 'Aa1!';
const b64 = (value) => Buffer.from(JSON.stringify(value)).toString('base64');

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
echo json_encode(['settings' => md5(json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 'staff' => DB::table('staff')->count(), 'seasons' => DB::table('catalog_seasons')->count(), 'tags' => DB::table('catalog_tags')->count(), 'brands' => DB::table('catalog_brands')->count()]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/hp3_backup.json', json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo 'ok';`;

// settings back by id, the throw-away entities and the staff member removed, settings once more (a deleted entity may ask for a recompile)
const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/hp3_backup.json'), true);
$put = function () use ($bak) { DB::table('site_settings')->whereNotIn('id', array_column($bak, 'id'))->delete(); foreach ($bak as $row) { DB::table('site_settings')->where('id', $row['id'])->update(['key' => $row['key'], 'value' => $row['value'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]); if (! DB::table('site_settings')->where('id', $row['id'])->exists()) { DB::table('site_settings')->insert($row); } } };
$put();
foreach (EasyCo\Catalog\Persistence\Eloquent\SeasonModel::query()->where('name', 'like', 'HP3 audit%')->get() as $m) { $m->delete(); }
foreach (EasyCo\Catalog\Persistence\Eloquent\TagModel::query()->where('name', 'like', 'HP3 audit%')->get() as $m) { $m->delete(); }
foreach (EasyCo\Catalog\Persistence\Eloquent\BrandModel::query()->where('name', 'like', 'HP3 audit%')->get() as $m) { $m->delete(); }
DB::table('staff')->where('email', '${EMAIL}')->delete();
$put();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo 'restored';`;

const CREATE = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel; use EasyCo\Catalog\Persistence\Eloquent\SeasonModel; use EasyCo\Catalog\Persistence\Eloquent\TagModel; use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'HP3 Audit', $role));
$pic = MediaAssetModel::query()->where('type', 'image')->where('processing_status', 'ready')->orderBy('id')->first();
if ($pic === null) { echo json_encode(['error' => 'no ready picture in the library']); return; }
$cats = array_map(fn ($n) => (int) $n->id, array_slice(app(App\Storefront\Reader\CategoryIndex::class)->tree(), 0, 5));
$season = SeasonModel::query()->create(['name' => 'HP3 audit season', 'slug' => 'hp3-audit-season'])->id;
$tag = TagModel::query()->create(['name' => 'HP3 audit tag', 'slug' => 'hp3-audit-tag'])->id;
$brand = BrandModel::query()->create(['name' => 'HP3 audit brand', 'slug' => 'hp3-audit-brand'])->id;
echo json_encode(['picture' => $pic->id, 'cats' => $cats, 'season' => $season, 'tag' => $tag, 'brand' => $brand]);`;

const PUBLISH = (sections) => String.raw`
use App\Storefront\Home\HomeValidator; use App\Storefront\Home\HomeDrafts;
[$clean, $errors] = HomeValidator::validate(['spacing' => '16', 'sections' => json_decode(base64_decode('${b64(sections)}'), true)]);
HomeDrafts::publish($clean);
echo json_encode($errors);`;

const UNPUBLISH = String.raw`
use App\Storefront\Home\HomeLayout; use App\Storefront\Home\HomeDrafts;
HomeDrafts::publish(HomeLayout::default());
echo 'default';`;

// the expected picture of ImageGridGeometry's sister: TileGeometry for the real content width
const EXPECT = (columns, gap, shape, width) => String.raw`
$t = App\Storefront\Home\TileGeometry::tile(${columns}, ${gap}, '${shape}', (int) round(${width}));
echo json_encode(['w' => $t['width'], 'h' => $t['height']]);`;

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
    check('the throw-away season, tag, brand and staff member were created; categories exist', ! made.error && made.cats.length >= 1, JSON.stringify(made));
    const { picture, cats, season, tag, brand } = made;
    const cat = (i) => cats[i % cats.length];

    const newCtx = async (width) => browser.newContext({ viewport: { width, height: width < 600 ? 800 : 1000 }, ignoreHTTPSErrors: true, ...(width < 600 ? { hasTouch: true, isMobile: true } : {}) });
    const shotOf = async (page, name, full = false) => { if (OUT) { const p = join(OUT, name + '.png'); shots.push(p); await page.screenshot({ path: p, fullPage: full }); } };
    const track = (p) => { p.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200))); return p; };

    const tile = (over = {}) => ({ source: 'category', entity: cat(0), label: '', picture, link: null, ...over });
    const row = (tiles, settings = {}) => [{ type: 'tiles', settings: { tiles, ...settings } }];
    const long = 'Много дълго име на плочка, което със сигурност не се събира в една колона'.slice(0, 60);

    // ============================================================ the Design screen
    let ctx = await newCtx(1440);
    let page = track(await ctx.newPage());
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
    await page.goto(BASE + '/admin/design?zone=home', { waitUntil: 'networkidle' });
    check('the component is in the palette', (await page.locator('.ds-chip-add', { hasText: 'Плочки' }).count()) === 1);
    await page.locator('.ds-chip-add', { hasText: 'Плочки' }).click();
    await page.waitForSelector('.ds-gd', { timeout: 30000 });
    await sleep(800);
    const hint = await page.locator('.ds-gd').first().innerText();
    const d = JSON.parse(php(EXPECT(4, 16, 'square', 1320)));
    const dp = JSON.parse(php(EXPECT(2, 16, 'square', 358)));
    const tens = (x) => Math.round(x / 10) * 10;
    check('the size hint has TileGeometry\'s numbers for the desktop and the phone', hint.includes(`${tens(d.w)} × ${tens(d.h)}`) && hint.includes(`${tens(dp.w)} × ${tens(dp.h)}`), hint.replace(/\s+/g, ' ').slice(0, 300));
    check('the look settings and the tile list are in the panel with info icons', (await page.locator('[aria-describedby^="ds-hint-"]').count()) >= 6 && (await page.locator('text=Добави плочка').count()) > 0);
    await shotOf(page, 'hp3-panel-1440', true);
    await ctx.close();

    // ============================================================ the shop
    const measure = async (p) => p.evaluate(() => {
        const ul = document.querySelector('.sf-hp-tiles-row');
        if (! ul) return null;
        const main = document.querySelector('.sf-main');
        const mc = getComputedStyle(main);
        const tiles = [...ul.querySelectorAll(':scope > .sf-hp-tile')].map((li) => {
            const img = li.querySelector('img'), cap = li.querySelector('.sf-hp-tile-cap'), a = li.querySelector('a');
            const ir = img.getBoundingClientRect(), lr = li.getBoundingClientRect(), ar = a.getBoundingClientRect();
            const hit = document.elementFromPoint(ir.left + ir.width / 2, ir.top + ir.height / 2);
            const capHit = document.elementFromPoint(lr.left + lr.width / 2, ar.bottom - 3);
            return { w: ir.width, h: ir.height, x: lr.left, y: lr.top, tw: lr.width, radius: getComputedStyle(img).borderTopLeftRadius, capOverflow: cap.scrollWidth > cap.clientWidth, capEllipsis: getComputedStyle(cap).textOverflow, capW: cap.getBoundingClientRect().width, capTop: cap.getBoundingClientRect().top - ir.bottom, label: cap.textContent.trim(), href: a.getAttribute('href'), hitA: hit && hit.closest('a') ? hit.closest('a').getAttribute('href') : null, capHitA: capHit && capHit.closest('a') ? capHit.closest('a').getAttribute('href') : null, alt: img.getAttribute('alt'), fs: parseFloat(getComputedStyle(cap).fontSize), lazy: img.getAttribute('loading') };
        });
        const cols = tiles.length === 0 ? 0 : tiles.filter((t) => Math.round(t.y) === Math.round(tiles[0].y)).length;

        return { gw: ul.getBoundingClientRect().width, content: main.getBoundingClientRect().width - parseFloat(mc.paddingLeft) - parseFloat(mc.paddingRight), tiles, cols, scroll: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1 };
    });

    const cases = [
        { name: 'four-square-r0', columns: 4, gap: 16, shape: 'square', radius: 0, widths: [1440, 1024], tiles: () => [tile(), tile({ entity: cat(1) }), tile({ entity: cat(2) }), tile({ source: 'season', entity: season, link: '/sale/winter', label: 'Зимен сезон' }), tile({ source: 'tag', entity: tag, picture: null, link: '/no-picture' })], settings: { columns: '4', gap: '16', shape: 'square', radius: '0' } },
        { name: 'four-portrait-r20', columns: 4, gap: 16, shape: 'portrait', radius: 20, widths: [1440], tiles: () => [tile(), tile({ entity: cat(1) }), tile({ entity: cat(2) }), tile({ entity: cat(3) })], settings: { columns: '4', gap: '16', shape: 'portrait', radius: '20' } },
        { name: 'five-circles-long', columns: 5, gap: 24, shape: 'square', radius: 50, widths: [1440, 1024], tiles: () => [0, 1, 2, 3, 4].map((i) => tile({ entity: cat(i), label: long })), settings: { columns: '5', gap: '24', shape: 'square', radius: '50', caption: 'l' } },
        { name: 'three-landscape-gap0', columns: 3, gap: 0, shape: 'landscape', radius: 0, widths: [1440], tiles: () => [tile(), tile({ entity: cat(1) }), tile({ source: 'brand', entity: brand, link: '/brands/x', label: 'Марка' })], settings: { columns: '3', gap: '0', shape: 'landscape', radius: '0' } },
        { name: 'three-landscape-gap24', columns: 3, gap: 24, shape: 'landscape', radius: 10, widths: [1440], tiles: () => [tile(), tile({ entity: cat(1) }), tile({ entity: cat(2) })], settings: { columns: '3', gap: '24', shape: 'landscape', radius: '10' } },
    ];

    for (const c of cases) {
        const out = php(PUBLISH(row(c.tiles(), c.settings)));
        check(`${c.name}: published through the application`, out === '[]', out);
        const shown = c.name === 'four-square-r0' ? 4 : c.tiles().length;

        for (const width of c.widths) {
            ctx = await newCtx(width);
            page = track(await ctx.newPage());
            await page.goto(BASE + '/', { waitUntil: 'networkidle' });
            const m = await measure(page);
            check(`${c.name} ${width}px: ${shown} tiles are printed (a tile without a picture is not)`, m && m.tiles.length === shown, m ? m.tiles.length : 'no row');
            const exp = JSON.parse(php(EXPECT(c.columns, c.gap, c.shape, m.content)));
            check(`${c.name} ${width}px: every picture matches TileGeometry within 2px (content ${m.content.toFixed(1)}px)`, m.tiles.every((t) => near(t.w, exp.w) && near(t.h, exp.h)), JSON.stringify({ got: m.tiles.slice(0, 2).map((t) => [Math.round(t.w), Math.round(t.h)]), exp: [Math.round(exp.w), Math.round(exp.h)] }));
            check(`${c.name} ${width}px: ${c.columns} columns, nothing scrolls sideways, the row is the content width`, m.cols === Math.min(c.columns, shown) && ! m.scroll && near(m.gw, m.content, 1.5), JSON.stringify({ cols: m.cols, scroll: m.scroll }));
            check(`${c.name} ${width}px: the whole tile is the click target (picture and caption), alt is empty, the caption is below the picture`, m.tiles.every((t) => t.hitA === t.href && t.capHitA === t.href && t.alt === '' && t.capTop >= 0 && t.capTop <= 12), JSON.stringify(m.tiles.slice(0, 1)));

            if (c.radius === 50) {
                check(`${c.name} ${width}px: circles: width = height, radius 50%`, m.tiles.every((t) => near(t.w, t.h, 1) && t.radius === '50%'), JSON.stringify(m.tiles.slice(0, 1).map((t) => [t.w, t.h, t.radius])));
                check(`${c.name} ${width}px: a long caption ends with an ellipsis and never overflows its tile`, m.tiles.every((t) => t.capEllipsis === 'ellipsis' && t.capW <= t.tw + 0.5) && m.tiles.some((t) => t.capOverflow), JSON.stringify(m.tiles.slice(0, 1).map((t) => [t.capW, t.tw, t.capEllipsis, t.capOverflow])));
                check(`${c.name} ${width}px: the caption is 18px (L)`, m.tiles.every((t) => t.fs === 18), m.tiles[0].fs);
            }

            if (c.radius === 20) {
                check(`${c.name} ${width}px: the radius is 20% of the picture`, m.tiles.every((t) => t.radius === '20%'), m.tiles[0].radius);
            }

            await shotOf(page, `hp3-home-${c.name}-${width}`);
            await ctx.close();
        }
    }

    // the phone: 2 columns by default, 1 when set; the tablet: 3 for a 4- or 5-column row
    for (const [phone, width, label] of [['2', 360, 'two'], ['1', 360, 'one']]) {
        php(PUBLISH(row([tile(), tile({ entity: cat(1) }), tile({ entity: cat(2) }), tile({ entity: cat(3) })], { columns: '4', columns_phone: phone, gap: '12' })));
        ctx = await newCtx(width);
        page = track(await ctx.newPage());
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        const m = await measure(page);
        const exp = JSON.parse(php(EXPECT(parseInt(phone, 10), 12, 'square', m.content)));
        check(`360px, ${label} column(s) on a phone: ${phone} per row, the pictures match TileGeometry, no sideways scroll`, m.cols === parseInt(phone, 10) && m.tiles.every((t) => near(t.w, exp.w)) && ! m.scroll, JSON.stringify({ cols: m.cols, w: Math.round(m.tiles[0].w), exp: Math.round(exp.w) }));
        await shotOf(page, `hp3-home-phone-${label}-360`, true);
        await ctx.close();
    }

    php(PUBLISH(row([0, 1, 2, 3, 4].map((i) => tile({ entity: cat(i) })), { columns: '5', gap: '16' })));
    ctx = await newCtx(800);
    page = track(await ctx.newPage());
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const tab = await measure(page);
    check('800px (tablet): a 5-column row shows 3 per row', tab && tab.cols === 3 && ! tab.scroll, tab ? tab.cols : 'no row');
    await shotOf(page, 'hp3-home-tablet-800');
    await ctx.close();

    php(UNPUBLISH);
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (created) {
        try {
            php(RESTORE);
            const after = JSON.parse(php(SUMS));
            check('everything is back: the settings table (exact rows), the staff, seasons, tags and brands are as they were (counts / checksum)', before && JSON.stringify(before) === JSON.stringify(after), JSON.stringify({ before, after }));
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
