// A REAL-BROWSER run of HP-2: the «Решетка от снимки» component of the «Начална» zone. It checks:
//   - the Design screen: the component is in the palette, the scheme cards and the size diagram (the numbers are ImageGridGeometry's), the cell selectors follow the scheme, the phone list and its warning;
//   - the home page at 1440px, 1024px and 360px with `three`, `four` and `uneven-a` (gap 0 and 24, radius 0 and 16), every cell filled by a «Снимка с текст» block (HB-5) with a picture and text, one empty cell, the
//     phone list with 1 and 4 blocks and with none (nothing shown): the cells match ImageGridGeometry (computed for the REAL content width) within 2px at 1440px, the picture covers every cell, the text keeps its place,
//     the block's own border and radius are kept, its own `min(Npx,70vw)` height does not enlarge a cell, nothing scrolls sideways, and an OLD text block with a background picture fills its cell too.
// NOT part of `php artisan test`: run by hand against a dev site. It creates ONE throw-away staff member and the throw-away HTML blocks («HP2 audit …», through the application's own model, with a picture that is
// ALREADY in the library); the design changes only through HomeDrafts; at the end the blocks and the staff member are removed, every `site_settings` row is put back EXACTLY (update by id) and the counts and the
// checksum are verified. Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/hp-2.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node hp-2.mjs');
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

const EMAIL = `hp2-${Date.now()}@example.test`;
const PASSWORD = 'Hp2-' + Math.random().toString(36).slice(2) + 'Aa1!';
const b64 = (value) => Buffer.from(JSON.stringify(value)).toString('base64');

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
echo json_encode(['settings' => md5(json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 'staff' => DB::table('staff')->count(), 'blocks' => DB::table('html_blocks')->count()]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/hp2_backup.json', json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo 'ok';`;

// settings back by id FIRST (the layout no longer holds the blocks), then the blocks and the staff member go, then the settings once more (a block's deletion rebuilds the stylesheet)
const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/hp2_backup.json'), true);
$put = function () use ($bak) { DB::table('site_settings')->whereNotIn('id', array_column($bak, 'id'))->delete(); foreach ($bak as $row) { DB::table('site_settings')->where('id', $row['id'])->update(['key' => $row['key'], 'value' => $row['value'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]); if (! DB::table('site_settings')->where('id', $row['id'])->exists()) { DB::table('site_settings')->insert($row); } } };
$put();
foreach (App\Content\HtmlBlocks\HtmlBlockModel::query()->where('title', 'like', 'HP2 audit%')->get() as $b) { $b->delete(); }
DB::table('staff')->where('email', '${EMAIL}')->delete();
$put();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo 'restored';`;

const CREATE = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
use App\Content\HtmlBlocks\HtmlBlockModel; use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'HP2 Audit', $role));
$pic = MediaAssetModel::query()->where('type', 'image')->where('processing_status', 'ready')->orderBy('id')->first();
if ($pic === null) { echo json_encode(['error' => 'no ready picture in the library']); return; }
$ids = [];
foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $i => $n) {
  $ids[] = HtmlBlockModel::query()->create(['title' => 'HP2 audit '.$n, 'kind' => 'card', 'card' => ['picture' => $pic->id, 'title' => 'Клетка '.strtoupper($n), 'subtitle' => 'Подзаглавие', 'link' => '/', 'height' => [160, 240, 320, 400, 320, 240][$i], 'border_w' => 2, 'border_color' => 'light', 'radius' => 8, 'halign' => ['left', 'center', 'right', 'center', 'left', 'right'][$i], 'valign' => ['top', 'middle', 'bottom', 'middle', 'top', 'bottom'][$i]]])->id;
}
$old = HtmlBlockModel::query()->create(['title' => 'HP2 audit old', 'body' => '<p>Стар блок</p>', 'style' => ['bg_image' => $pic->id, 'overlay' => 40, 'min_height' => 400, 'color' => '#ffffff', 'pad_v' => 16, 'pad_h' => 16]])->id;
echo json_encode(['picture' => $pic->id, 'ids' => $ids, 'old' => $old]);`;

const PUBLISH = (sections) => String.raw`
use App\Storefront\Home\HomeValidator; use App\Storefront\Home\HomeDrafts;
[$clean, $errors] = HomeValidator::validate(['spacing' => '16', 'sections' => json_decode(base64_decode('${b64(sections)}'), true)]);
HomeDrafts::publish($clean);
echo json_encode($errors);`;

const UNPUBLISH = String.raw`
use App\Storefront\Home\HomeLayout; use App\Storefront\Home\HomeDrafts;
HomeDrafts::publish(HomeLayout::default());
echo 'default';`;

// the expected cells of ImageGridGeometry for the real content width
const EXPECT = (scheme, ratio, gap, width) => String.raw`
echo json_encode(array_map(fn ($c) => ['n' => $c['name'], 'w' => $c['width'], 'h' => $c['height']], App\Storefront\Home\ImageGridGeometry::cells('${scheme}', '${ratio}', ${gap}, (int) round(${width}))));`;

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
    check('the throw-away blocks and staff member were created', ! made.error && made.ids?.length === 6, JSON.stringify(made));
    const { ids, old } = made;

    const newCtx = async (width) => browser.newContext({ viewport: { width, height: width < 600 ? 800 : 1000 }, ignoreHTTPSErrors: true, ...(width < 600 ? { hasTouch: true, isMobile: true } : {}) });
    const shotOf = async (page, name, full = false) => { if (OUT) { const p = join(OUT, name + '.png'); shots.push(p); await page.screenshot({ path: p, fullPage: full }); } };
    const track = (p) => { p.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200))); return p; };
    const grid = (scheme, cells, extra = {}) => [{ type: 'image_grid', settings: { scheme, cells, ...extra } }];
    const cellMap = (scheme, list) => Object.fromEntries(list.map((id, i) => ['abcdef'[i], id]).filter(([, id]) => id));

    // ============================================================ the Design screen
    let ctx = await newCtx(1440);
    let page = track(await ctx.newPage());
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
    await page.goto(BASE + '/admin/design?zone=home', { waitUntil: 'networkidle' });
    check('the component is in the palette', (await page.locator('.ds-chip-add', { hasText: 'Решетка от снимки' }).count()) === 1);
    await page.locator('.ds-chip-add', { hasText: 'Решетка от снимки' }).click();
    await page.waitForSelector('.ds-gd-box', { timeout: 30000 });
    await sleep(800);
    check('the scheme cards (small pictures), the diagram and the explanations are in the panel', (await page.locator('.ds-gt').count()) === 8 && (await page.locator('.ds-gd-cell').count()) === 3 && (await page.locator('[aria-describedby^="ds-hint-"]').count()) >= 6);
    check('the empty phone list shows its warning', (await page.locator('text=На телефон няма да се показва нищо').count()) > 0);
    for (const [scheme, label, count] of [['one', '1 голяма', 1], ['four', '4 равни', 4], ['uneven-a', 'Неравна А', 6], ['uneven-b', 'Неравна Б', 3]]) {
        await page.locator('label:has(.ds-gt-' + scheme + ')').first().click();
        await sleep(1500);
        const cellsShown = await page.locator('.ds-gd-cell').count();
        const text = await page.locator('.ds-gd-box').first().innerText();
        check(`scheme ${scheme}: the diagram has ${count} labelled cells with sizes`, cellsShown === count && /\d+ × \d+/.test(text), `${cellsShown} ${text.replace(/\s+/g, ' ').slice(0, 80)}`);
        await shotOf(page, `hp2-panel-${scheme}-1440`, true);
    }
    await ctx.close();

    // ============================================================ the shop
    const measure = async (p) => p.evaluate(() => {
        const g = document.querySelector('.sf-hp-grid');
        if (! g) return null;
        const gr = g.getBoundingClientRect();
        const cells = [...g.querySelectorAll(':scope > .sf-hp-cell')].map((c) => {
            const r = c.getBoundingClientRect();
            const b = c.querySelector(':scope > .sf-hb');
            const bs = b ? getComputedStyle(b) : null;
            const br = b ? b.getBoundingClientRect() : null;
            const text = c.querySelector('.sf-pc-text');
            return { n: [...c.classList].find((k) => k.startsWith('sf-hp-a-')).slice(8), w: r.width, h: r.height, x: r.left - gr.left, y: r.top - gr.top, bw: br ? br.width : null, bh: br ? br.height : null, bg: bs ? bs.backgroundSize : null, bgi: bs ? bs.backgroundImage.includes('url(') : null, border: bs ? bs.borderTopWidth : null, radius: bs ? bs.borderTopLeftRadius : null, cellRadius: getComputedStyle(c).borderTopLeftRadius, textInside: text ? (text.getBoundingClientRect().bottom <= r.bottom + 1 && text.getBoundingClientRect().top >= r.top - 1) : null };
        });
        const main = document.querySelector('.sf-main');
        const mc = getComputedStyle(main);
        const phone = document.querySelector('.sf-hp-phone');

        return { gw: gr.width, gh: gr.height, cells, content: main.getBoundingClientRect().width - parseFloat(mc.paddingLeft) - parseFloat(mc.paddingRight), scroll: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1, gridShown: getComputedStyle(g).display !== 'none', phoneShown: phone ? getComputedStyle(phone).display !== 'none' : false, phoneBlocks: phone ? phone.querySelectorAll('.sf-hp-pcell').length : 0, phoneW: phone ? [...phone.querySelectorAll('.sf-hp-pcell')].map((c) => Math.round(c.getBoundingClientRect().width)) : [], phoneInner: phone ? [...phone.querySelectorAll('.sf-hp-pcell > .sf-hb')].map((b) => Math.round(b.getBoundingClientRect().width)) : [] };
    });

    const cases = [
        ['three', { ratio: '3:2', gap: '16', radius: '0' }, 1440], ['four', { ratio: '16:9', gap: '0', radius: '16' }, 1440], ['uneven-a', { ratio: '3:2', gap: '24', radius: '16' }, 1440],
        ['uneven-a', { ratio: '4:3', gap: '0', radius: '0' }, 1024], ['three', { ratio: '3:2', gap: '16', radius: '0' }, 1024],
    ];

    for (const [scheme, opts, width] of cases) {
        const n = { three: 3, four: 4, 'uneven-a': 6 }[scheme];
        const out = php(PUBLISH(grid(scheme, cellMap(scheme, ids.slice(0, n)), opts)));
        check(`${scheme} ${opts.ratio} gap ${opts.gap} radius ${opts.radius}: published through the application`, out === '[]', out);
        ctx = await newCtx(width);
        page = track(await ctx.newPage());
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        const m = await measure(page);
        const exp = JSON.parse(php(EXPECT(scheme, opts.ratio, opts.gap, m.content)));
        const cellsOk = exp.every((e) => { const c = m.cells.find((x) => x.n === e.n); return c && near(c.w, e.w) && near(c.h, e.h); });
        check(`${width}px ${scheme} gap ${opts.gap}: every cell matches ImageGridGeometry within 2px (content ${m.content.toFixed(1)}px)`, cellsOk && m.cells.length === n, JSON.stringify({ got: m.cells.map((c) => [c.n, Math.round(c.w), Math.round(c.h)]), exp: exp.map((e) => [e.n, Math.round(e.w), Math.round(e.h)]) }));
        check(`${width}px ${scheme}: the grid is the full width and the box has its proportion`, near(m.gw, m.content, 1.5) && near(m.gh, m.gw * { '3:2': 2 / 3, '16:9': 9 / 16, '4:3': 3 / 4 }[opts.ratio], 1.5), JSON.stringify({ gw: m.gw, gh: m.gh }));
        check(`${width}px ${scheme}: every block fills its cell, the picture covers it, the text stays inside, the block's own border (2px) is kept, no cell is enlarged by the block's height`, m.cells.every((c) => near(c.bw, c.w - 0, 1.5) && near(c.bh, c.h, 1.5) && c.bg === 'cover' && c.bgi === true && c.border === '2px' && c.textInside === true), JSON.stringify(m.cells.map((c) => [c.n, Math.round(c.bw), Math.round(c.bh), c.bg, c.border, c.textInside])));
        check(`${width}px ${scheme}: the cell clips with radius ${opts.radius}px and nothing scrolls sideways; the grid is shown (not the phone list)`, m.cells.every((c) => c.cellRadius === opts.radius + 'px') && ! m.scroll && m.gridShown && ! m.phoneShown, JSON.stringify({ r: m.cells[0].cellRadius, scroll: m.scroll }));
        await shotOf(page, `hp2-home-${scheme}-gap${opts.gap}-r${opts.radius}-${width}`);
        await ctx.close();
    }

    // an empty cell and an OLD text block in a cell
    php(PUBLISH(grid('three', { a: ids[0], c: old })));
    ctx = await newCtx(1440);
    page = track(await ctx.newPage());
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    let m = await measure(page);
    check('1440px: an empty cell is not printed (its area stays empty) and an old text block with a picture fills its cell', m.cells.length === 2 && m.cells.map((c) => c.n).join() === 'a,c' && near(m.cells[1].bw, m.cells[1].w, 1.5) && near(m.cells[1].bh, m.cells[1].h, 1.5) && m.cells[1].bgi === true, JSON.stringify(m.cells.map((c) => [c.n, Math.round(c.w), Math.round(c.bw), Math.round(c.h), Math.round(c.bh)])));
    await shotOf(page, 'hp2-home-empty-cell-1440');
    await ctx.close();

    // the phone list: 1 block, 4 blocks, none
    for (const [count, label] of [[1, 'one'], [4, 'four'], [0, 'none']]) {
        php(PUBLISH(grid('three', cellMap('three', ids.slice(0, 3)), { phone_blocks: ids.slice(0, count) })));
        ctx = await newCtx(360);
        page = track(await ctx.newPage());
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        m = await measure(page);
        const g = m && m.gridShown;
        if (count > 0) {
            check(`360px, ${count} phone block(s): the desktop grid is hidden, the list is shown, ${count} block(s) one under another, each the full width, no sideways scroll`, m && ! m.gridShown && m.phoneShown && m.phoneBlocks === count && m.phoneW.every((w) => w >= 300) && m.phoneInner.every((w, i) => near(w, m.phoneW[i], 1.5)) && ! m.scroll, JSON.stringify({ shown: m?.phoneShown, n: m?.phoneBlocks, w: m?.phoneW, inner: m?.phoneInner }));
        } else {
            const html = await page.content();
            check('360px, no phone block: NOTHING of the component is shown on a phone (the grid is hidden, no phone element)', m && ! m.gridShown && ! html.includes('sf-hp-phone'), JSON.stringify({ gridShown: g }));
        }
        await shotOf(page, `hp2-home-phone-${label}-360`, true);
        await ctx.close();
    }

    // the tablet (48rem and up) shows the desktop grid
    php(PUBLISH(grid('four', cellMap('four', ids.slice(0, 4)), { phone_blocks: ids.slice(0, 2) })));
    ctx = await newCtx(800);
    page = track(await ctx.newPage());
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    m = await measure(page);
    check('800px (tablet): the desktop grid is shown, the phone list is not', m && m.gridShown && ! m.phoneShown && m.cells.length === 4 && ! m.scroll, JSON.stringify({ grid: m?.gridShown, phone: m?.phoneShown }));
    await shotOf(page, 'hp2-home-tablet-800');
    await ctx.close();

    php(UNPUBLISH);
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (created) {
        try {
            php(RESTORE);
            const after = JSON.parse(php(SUMS));
            check('everything is back: the settings table (exact rows), the staff and the HTML blocks are as they were (counts / checksum)', before && JSON.stringify(before) === JSON.stringify(after), JSON.stringify({ before, after }));
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
