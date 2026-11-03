// A REAL-BROWSER run of HP-1: the «Начална» zone of «Дизайн» (the home page as a list of sections: a heading with lines, a free HTML block) and the home page it publishes. It checks:
//   - the Design screen: the zone is in the list, an empty list, a heading is added from the palette, its text is typed, «Приложи» gives the preview frame a fresh signed address, the chip says what it is;
//   - the home page at 1280px and 360px with a heading, an HTML block and a text-only heading: the real heading tags, the lines (decorative, they shrink on a phone, nothing overflows), the HTML block,
//     the SPACING between the sections measured in pixels for the general step 0 / 32 / 96 and a section's own step, a hidden section is not there, one <h1>, no horizontal scroll;
//   - the old home page comes back byte for byte when the layout is taken back.
// NOT part of `php artisan test`: run by hand against a dev site. The Design screen needs a signed-in admin: the run creates ONE throw-away staff member and ONE throw-away HTML block (title «HP1 audit») through
// the application's own code and removes both at the end. The design is changed ONLY through the application's own publish path (HomeDrafts); at the end every `site_settings` row is put back EXACTLY (update by id: value and
// timestamps; a row the run created is removed; no row is deleted and re-inserted) and the counts and the checksum are verified. Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/hp-1.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node hp-1.mjs');
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

const EMAIL = `hp1-${Date.now()}@example.test`;
const PASSWORD = 'Hp1-' + Math.random().toString(36).slice(2) + 'Aa1!';
const b64 = (value) => Buffer.from(JSON.stringify(value)).toString('base64');

// the settings table, the staff and the HTML blocks as they are (counts, and a checksum of every settings row)
const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
echo json_encode(['settings' => md5(json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 'staff' => DB::table('staff')->count(), 'blocks' => DB::table('html_blocks')->count()]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/hp1_backup.json', json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo 'ok';`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/hp1_backup.json'), true);
DB::table('site_settings')->whereNotIn('id', array_column($bak, 'id'))->delete();
foreach ($bak as $row) { DB::table('site_settings')->where('id', $row['id'])->update(['key' => $row['key'], 'value' => $row['value'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]); if (! DB::table('site_settings')->where('id', $row['id'])->exists()) { DB::table('site_settings')->insert($row); } }
DB::table('html_blocks')->where('title', 'HP1 audit')->delete();
DB::table('staff')->where('email', '${EMAIL}')->delete();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo 'restored';`;

const CREATE = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
$staff = Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'HP1 Audit', $role);
app(StaffRepository::class)->save($staff);
$block = App\Content\HtmlBlocks\HtmlBlockModel::query()->create(['title' => 'HP1 audit', 'body' => '<p>Свободен HTML блок: безплатна доставка над 80 евро.</p>']);
echo $block->id;`;

// publishes a home layout (sections as base64 JSON) through the application's own publish path
const PUBLISH = (sections, spacing) => String.raw`
use App\Storefront\Home\HomeValidator; use App\Storefront\Home\HomeDrafts;
[$clean, $errors] = HomeValidator::validate(['spacing' => '${spacing}', 'sections' => json_decode(base64_decode('${b64(sections)}'), true)]);
HomeDrafts::publish($clean);
echo json_encode($errors);`;

const UNPUBLISH = String.raw`
use App\Storefront\Home\HomeLayout; use App\Storefront\Home\HomeDrafts;
HomeDrafts::publish(HomeLayout::default());
echo 'default';`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const consoleErrors = [];
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const near = (a, b, t = 1.5) => Math.abs(a - b) <= t;

let before = null;
let created = false;

try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    created = true;
    const blockId = parseInt(php(CREATE), 10);
    check('a throw-away HTML block and staff member were created', blockId > 0, String(blockId));

    const newCtx = async (width) => browser.newContext({ viewport: { width, height: width < 600 ? 800 : 1000 }, ignoreHTTPSErrors: true, ...(width < 600 ? { hasTouch: true, isMobile: true } : {}) });
    const shotOf = async (page, name) => { if (OUT) { const p = join(OUT, name + '.png'); shots.push(p); await page.screenshot({ path: p, fullPage: false }); } };

    // the old home page as it is (no layout)
    let ctx = await newCtx(1280);
    let page = await ctx.newPage();
    page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const oldHtml = await page.content();
    check('before: the home page is the old one (categories and newest, no intro, no home sections)', oldHtml.includes('sf-category-row') && ! oldHtml.includes('sf-intro') && ! oldHtml.includes('sf-hp-'));
    await ctx.close();

    // ============================================================ the Design screen
    ctx = await newCtx(1280);
    page = await ctx.newPage();
    page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
    await page.goto(BASE + '/admin/design', { waitUntil: 'networkidle' });

    const zones = await page.$$eval('.ds-zones .ds-zone', (els) => els.map((e) => ({ t: e.textContent.trim().replace(/\s+/g, ' '), off: e.disabled })));
    check('the zone «Начална» is a working button in the list (the «Съдържание» placeholder is gone)', zones.some((z) => z.t.startsWith('Начална') && ! z.off) && ! zones.some((z) => z.t.startsWith('Съдържание')), JSON.stringify(zones));

    await page.locator('.ds-zone', { hasText: 'Начална' }).first().click();
    await page.waitForSelector('.ds-chips-list', { state: 'attached', timeout: 30000 });
    await sleep(500);
    check('the zone opens with an empty list of sections', (await page.locator('.ds-chips-list .ds-chip').count()) === 0);
    await shotOf(page, 'hp1-zone-empty-1280');

    await page.locator('.ds-chip-add', { hasText: 'Заглавие с линии' }).click();
    await page.waitForSelector('.ds-chips-list .ds-chip', { timeout: 30000 });
    await sleep(600);
    await page.locator('.ds-props input[type=text]').first().fill('Нови предложения');
    const frameBefore = await page.locator('iframe[title="Преглед на началната страница"]').getAttribute('src');
    await page.getByRole('button', { name: 'Приложи' }).click();
    await sleep(1500);
    const frameAfter = await page.locator('iframe[title="Преглед на началната страница"]').getAttribute('src');
    check('«Приложи» gives the preview frame a fresh signed address', frameBefore !== frameAfter && /signature=/.test(frameAfter), String(frameAfter).slice(0, 140));
    const chip = (await page.locator('.ds-chips-list .ds-chip').first().innerText()).replace(/\s+/g, ' ');
    check('the section is in the list with its type and its text', /Заглавие с линии/.test(chip) && /Нови предложения/.test(chip), chip);
    const infoIcons = await page.locator('.ds-props .info-hint').count();
    check('the properties show info icons (the explanations), no helper paragraphs under the fields', infoIcons >= 4 && (await page.locator('.ds-props .fi-fo-field-helper-text').count()) === 0, String(infoIcons));
    await page.locator('.ds-layout-head').click();
    await sleep(600);
    check('«Общи настройки» shows the spacing between the sections (the default 32)', (await page.locator('.ds-props').innerText()).includes('32'));
    await shotOf(page, 'hp1-zone-heading-1280');
    await ctx.close();

    // ============================================================ the home page with sections
    const sections = [
        { type: 'heading', settings: { text: 'Нови предложения за сезона', level: 'h2' } },
        { type: 'html_block', settings: { html_block: blockId, spacing: '96' } },
        { type: 'heading', settings: { text: 'Скрито заглавие', show: false } },
        { type: 'heading', settings: { text: 'Само текст', level: 'h3', lines: 'none', spacing: '24' } },
    ];
    const measure = (p) => p.evaluate(() => {
        const secs = [...document.querySelectorAll('.sf-hp-section')];
        const rects = secs.map((s) => { const r = s.getBoundingClientRect(); return { top: r.top, bottom: r.bottom, w: r.width, cls: s.className }; });
        const gaps = rects.slice(1).map((r, i) => Math.round((r.top - rects[i].bottom) * 10) / 10);
        const h = document.querySelector('.sf-hp-heading-row');
        const text = document.querySelector('.sf-hp-heading-text');
        const lines = [...document.querySelectorAll('.sf-hp-line')].map((l) => Math.round(l.getBoundingClientRect().width));
        const cs = text ? getComputedStyle(text) : null;

        return { gaps, count: secs.length, h1: document.querySelectorAll('h1').length, tag: text ? text.tagName : null, lines, textW: text ? Math.round(text.getBoundingClientRect().width) : 0, textH: text ? Math.round(text.getBoundingClientRect().height) : 0, fs: cs ? cs.fontSize : null, scroll: document.documentElement.scrollWidth <= window.innerWidth + 1, headOverflow: h ? h.scrollWidth > h.clientWidth + 1 : false, hidden: document.body.innerHTML.includes('Скрито заглавие'), widths: rects.map((r) => Math.round(r.w)), main: (() => { const el = document.querySelector('.sf-main'); const c = getComputedStyle(el); return Math.round(el.getBoundingClientRect().width - parseFloat(c.paddingLeft) - parseFloat(c.paddingRight)); })() };
    });

    for (const [spacing, expected] of [['0', 0], ['32', 32], ['96', 96]]) {
        const out = php(PUBLISH(sections, spacing));
        check(`the layout (general spacing ${spacing}) was published through the application (no validation errors)`, out === '[]', out);

        for (const width of [1280, 360]) {
            const c = await newCtx(width);
            const p = await c.newPage();
            p.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
            await p.goto(BASE + '/', { waitUntil: 'networkidle' });
            const m = await measure(p);
            // the sections that are printed: heading, block (own 96), text-only heading (own 24); the hidden one is not there
            const want = [spacing === '0' ? 96 : 96, 24];
            check(`${width}px, spacing ${spacing}: three sections (the hidden one is not printed), one <h1>, no sideways scroll`, m.count === 3 && ! m.hidden && m.h1 === 1 && m.scroll, JSON.stringify({ count: m.count, h1: m.h1, hidden: m.hidden, scroll: m.scroll }));
            check(`${width}px, spacing ${spacing}: a section's own spacing wins (96 and 24 px), the first has none`, near(m.gaps[0], want[0], 1.5) && near(m.gaps[1], want[1], 1.5), JSON.stringify(m.gaps));
            check(`${width}px, spacing ${spacing}: the sections are the full width of the content area`, m.widths.every((w) => near(w, m.main, 1.5)), JSON.stringify({ widths: m.widths, main: m.main }));

            if (width === 1280 && spacing === '32') {
                check('1280: the heading is a real <h2> with a line on each side (decorative, they have width)', m.tag === 'H2' && m.lines.length === 2 && m.lines.every((w) => w > 50), JSON.stringify({ tag: m.tag, lines: m.lines }));
                await shotOf(p, 'hp1-home-sections-1280');
            }

            if (width === 360 && spacing === '32') {
                check('360: the heading and its lines fit (the lines shrink, nothing overflows)', ! m.headOverflow && m.lines.length === 2, JSON.stringify({ lines: m.lines, overflow: m.headOverflow }));
                await shotOf(p, 'hp1-home-sections-360');
            }

            await c.close();
        }
    }

    // the general spacing alone (no own overrides): 0 / 32 / 96 between all the printed sections
    for (const [spacing, expected] of [['0', 0], ['32', 32], ['96', 96]]) {
        php(PUBLISH([
            { type: 'heading', settings: { text: 'Първо' } },
            { type: 'html_block', settings: { html_block: blockId } },
            { type: 'heading', settings: { text: 'Трето', level: 'h4' } },
        ], spacing));
        const c = await newCtx(1280);
        const p = await c.newPage();
        await p.goto(BASE + '/', { waitUntil: 'networkidle' });
        const m = await measure(p);
        check(`1280: the general spacing ${spacing} puts ${expected}px between every two sections`, m.gaps.length === 2 && m.gaps.every((g) => near(g, expected, 1.5)), JSON.stringify(m.gaps));
        spacing === '96' && await shotOf(p, 'hp1-home-spacing-96-1280');
        spacing === '0' && await shotOf(p, 'hp1-home-spacing-0-1280');
        await c.close();
    }

    // a long heading on a phone wraps
    php(PUBLISH([{ type: 'heading', settings: { text: 'Много дълго заглавие на началната страница, което трябва да се пренесе на няколко реда на телефон', level: 'h2' } }], '32'));
    const lc = await newCtx(360);
    const lp = await lc.newPage();
    await lp.goto(BASE + '/', { waitUntil: 'networkidle' });
    const lm = await measure(lp);
    check('360: a long heading wraps onto several lines and nothing overflows', lm.textH > 40 && ! lm.headOverflow && lm.scroll, JSON.stringify({ textH: lm.textH, overflow: lm.headOverflow, fs: lm.fs }));
    await shotOf(lp, 'hp1-home-long-heading-360');
    await lc.close();

    // taking the layout back: the old page, byte for byte
    php(UNPUBLISH);
    const bc = await newCtx(1280);
    const bp = await bc.newPage();
    await bp.goto(BASE + '/', { waitUntil: 'networkidle' });
    const backHtml = await bp.content();
    const norm = (h) => h.replace(/(name="csrf-token" content=")[^"]*/g, '$1').replace(/(name="_token" value=")[^"]*/g, '$1').replace(/nonce="[^"]*"/g, 'nonce=""');
    const [x, y] = [norm(oldHtml), norm(backHtml)];
    let at = 0; while (at < x.length && x[at] === y[at]) at++;
    check('after the layout is taken back the home page is the old one again, byte for byte (the per-request token and nonce aside)', x === y, `${x.length} vs ${y.length}, first difference at ${at}: ${JSON.stringify(x.slice(at - 40, at + 60))} / ${JSON.stringify(y.slice(at - 40, at + 60))}`);
    await bc.close();
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
