// A REAL-BROWSER check of DRIBNI-4.
//   A. the Hero slides repeater: an invalid slide #2 (no desktop block, a phone block set) shows its message INSIDE slide #2 and not inside slide #1.
//   C. the product card name («Име на картата» of «Каталог»), on the home page at 1440px and 390px, for 1 ред and 2 реда: a very long name (the first card's text is replaced IN THE DOM; the server renders the
//      full name either way) is one line cut with an ellipsis for 1 ред and a two-line clamp for 2 реда; with 1 ред the price sits right under the name; the prices of the cards of one row are at the same height.
// NOT part of `php artisan test`: run by hand against the dev site. It creates ONE throw-away staff member (Administrator) and two HTML blocks («DRIBNI4 audit …»), sets the home working copy to an EMPTY draft
// for part A and publishes the card-name choice for part C. Before the run every site_settings row (the owner's published home layout, draft and catalog design included) is backed up; at the end they are put
// back BY ID, the staff member and the blocks are removed, the appearance is recompiled and the checksums are compared with the ones taken before. A watchdog puts the data back after 8 minutes.
//
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/dribni-4.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node dribni-4.mjs');
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

const EMAIL = `dribni4-${Date.now()}@example.test`;
const PASSWORD = 'Dribni4-' + Math.random().toString(36).slice(2) + 'Aa1!';

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$published = DB::table('site_settings')->where('key', 'design.home.global.published')->first();
echo json_encode(['settings' => md5(json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 'published' => $published ? [$published->id, md5((string) $published->value)] : null, 'staff' => DB::table('staff')->count(), 'html_blocks' => DB::table('html_blocks')->count()]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/dribni4_backup.json', json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo 'ok';`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/dribni4_backup.json'), true);
$put = function () use ($bak) { DB::table('site_settings')->whereNotIn('id', array_column($bak, 'id'))->delete(); foreach ($bak as $row) { DB::table('site_settings')->where('id', $row['id'])->update(['key' => $row['key'], 'value' => $row['value'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]); if (! DB::table('site_settings')->where('id', $row['id'])->exists()) { DB::table('site_settings')->insert($row); } } };
$put();
DB::table('html_blocks')->where('title', 'like', 'DRIBNI4 audit%')->delete();
DB::table('staff')->where('email', '${EMAIL}')->delete();
app(App\Storefront\Header\AppearanceRecompiler::class)->run(app(App\Settings\Contracts\SiteSettingsRepository::class));
$put();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo 'restored';`;

const CREATE = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff; use App\Content\HtmlBlocks\HtmlBlockModel;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'DRIBNI-4 Audit', $role));
HtmlBlockModel::query()->create(['title' => 'DRIBNI4 audit A', 'body' => '<p>A</p>']);
HtmlBlockModel::query()->create(['title' => 'DRIBNI4 audit B', 'body' => '<p>B</p>']);
echo 'ok';`;

const EMPTY_DRAFT = String.raw`
use App\Storefront\Home\HomeValidator; use App\Storefront\Home\HomeDrafts;
[$clean, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => []]);
HomeDrafts::saveDraft($clean);
echo json_encode($errors);`;

// publishes the card-name choice on top of the shop's own catalog values
const CARD_NAME = (value) => String.raw`
use App\Storefront\PageDesign\PageDesign; use App\Storefront\PageDesign\PageDesignStore; use App\Settings\AppearanceSettings; use App\Settings\Contracts\SiteSettingsRepository;
$current = PageDesign::catalogValues(app(SiteSettingsRepository::class)->get(AppearanceSettings::CATALOG_DESIGN_KEY));
$current['cardName'] = '${value}';
[$clean, $errors] = PageDesign::validate(PageDesign::CATALOG, $current, false);
PageDesignStore::publish(PageDesign::CATALOG, $clean);
echo json_encode($errors);`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });

const steps = [];
const consoleErrors = [];
const shots = [];
const report = {};
const check = (name, ok, detail = '') => { process.env.TRACE && console.error((ok ? '· ' : 'FAIL ') + name.slice(0, 90)); steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const LONG = 'Много дълго име на продукт, което със сигурност не се събира на един ред в тясна карта на магазина';

// the cards of the page: the first card's name is replaced with the long one (in the DOM), then the name's box, the prices and the rows are measured
const measure = (page) => page.evaluate((long) => {
    const cards = [...document.querySelectorAll('.sf-card')].filter((c) => c.getClientRects().length > 0 && c.querySelector('.sf-card-footer'));
    cards[0].querySelector('.sf-card-name').textContent = long;
    const rows = new Map();
    for (const c of cards) {
        const key = Math.round(c.getBoundingClientRect().top + window.scrollY);
        rows.set(key, [...(rows.get(key) ?? []), c]);
    }
    const nameOf = (c) => c.querySelector('.sf-card-name');
    const first = nameOf(cards[0]);
    const style = getComputedStyle(first);
    const lineHeight = parseFloat(style.lineHeight);
    const firstRow = [...rows.values()][0];
    const footerTops = firstRow.map((c) => Math.round(c.querySelector('.sf-card-footer').getBoundingClientRect().top));
    const gap = Math.round(cards[0].querySelector('.sf-card-footer').getBoundingClientRect().top - first.getBoundingClientRect().bottom);
    const other = nameOf(firstRow[1] ?? cards[1]);
    const box = firstRow.reduce((b, c) => { const r = c.getBoundingClientRect(); return { l: Math.min(b.l, r.left), t: Math.min(b.t, r.top), r: Math.max(b.r, r.right), b: Math.max(b.b, r.bottom) }; }, { l: 1e9, t: 1e9, r: 0, b: 0 });

    return {
        cards: cards.length,
        className: first.className,
        whiteSpace: style.whiteSpace,
        textOverflow: style.textOverflow,
        nameHeight: Math.round(first.getBoundingClientRect().height),
        lineHeight: Math.round(lineHeight),
        cut: first.scrollWidth > first.clientWidth || first.scrollHeight > first.clientHeight,
        otherNameHeight: Math.round(other.getBoundingClientRect().height),
        rowFooterTops: footerTops,
        gapNameToPrice: gap,
        rowBox: { x: Math.max(0, box.l - 8), y: box.t + window.scrollY - 8, width: box.r - box.l + 16, height: box.b - box.t + 16 },
        fullNameInHtml: document.body.innerHTML.includes('class="sf-card-name'),
    };
}, LONG);

let before = null;
let created = false;
let watchdog = null;

try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    created = true;
    watchdog = setTimeout(() => { try { php(RESTORE); } catch (e) { console.error('WATCHDOG restore failed ' + e); } console.error('WATCHDOG: the run took more than 8 minutes; the data was put back'); process.exit(3); }, 8 * 60 * 1000);
    check('the throw-away staff member and two HTML blocks were created', php(CREATE) === 'ok');
    check('the home working copy is an empty layout for this run', php(EMPTY_DRAFT) === '[]');

    // ============================================================ A. the hero slides
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 }, ignoreHTTPSErrors: true });
    ctx.setDefaultTimeout(20000);
    const page = await ctx.newPage();
    page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
    page.on('response', (r) => { r.status() >= 500 && consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 120)); });

    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    await page.goto(BASE + '/admin/design?zone=home', { waitUntil: 'networkidle' });
    await page.locator('.ds-chip-add', { hasText: 'Hero слайдер' }).click();
    await page.waitForSelector('.ds-props', { timeout: 30000 });
    await sleep(1500);
    const props = page.locator('.ds-props');
    await props.getByRole('button', { name: 'Добави слайд' }).click();
    await sleep(900);
    await props.getByRole('button', { name: 'Добави слайд' }).click();
    await sleep(900);
    const item = (i) => props.locator('.fi-fo-repeater-item').nth(i);
    const pick = async (scope, label, option) => {
        await scope.locator('.fi-fo-field', { has: page.locator('label', { hasText: label }) }).first().locator('.fi-select-input-btn').first().click();
        await sleep(500);
        await page.getByRole('option', { name: option }).first().click();
        await sleep(500);
    };

    await pick(item(0), 'Блок за компютър', 'DRIBNI4 audit A');
    // slide #2: only the phone block (the desktop block, which is required, stays empty)
    await item(1).getByRole('tab', { name: 'Телефон' }).click();
    await sleep(500);
    await pick(item(1), 'Блок за телефон', 'DRIBNI4 audit B');

    await page.locator('button.ds-btn-primary', { hasText: /Приложи|Apply/ }).click();
    await sleep(2500);

    const errorsIn = (i) => item(i).locator('.fi-fo-field-wrp-error-message');
    const count2 = await errorsIn(1).count();
    const count1 = await errorsIn(0).count();
    await item(1).getByRole('tab', { name: 'Компютър' }).click();
    await sleep(600);
    const message2 = count2 > 0 ? (await errorsIn(1).first().innerText()).trim() : '';
    report.slide_errors = { slide1: count1, slide2: count2, message2 };
    check('an invalid slide #2 shows its message inside slide #2 (under its desktop block)', count2 > 0 && message2 !== '', JSON.stringify(report.slide_errors));
    check('slide #1 shows no message', count1 === 0, JSON.stringify(report.slide_errors));
    if (OUT) {
        const p = join(OUT, 'dribni4-slide-error.png');
        shots.push(p);
        await props.screenshot({ path: p });
    }
    await ctx.close();

    // ============================================================ C. the card name
    for (const [width, label] of [[1440, '1440'], [390, '390']]) {
        for (const value of ['1', '2']) {
            check(`the card name ${value} is published`, php(CARD_NAME(value)) === '[]');
            const c = await browser.newContext({ viewport: { width, height: 900 }, ignoreHTTPSErrors: true, ...(width < 600 ? { hasTouch: true, isMobile: true } : {}) });
            c.setDefaultTimeout(20000);
            const p = await c.newPage();
            p.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
            await p.goto(BASE + '/', { waitUntil: 'networkidle' });
            const m = await measure(p);
            report[`card_${label}_${value}`] = m;
            const tag = `${label}px, ${value} ред`;
            check(`${tag}: there are cards with a footer`, m.cards >= 2, JSON.stringify(m));
            check(`${tag}: the class on the name matches the setting`, value === '2' ? m.className.includes('sf-card-name-2') : ! m.className.includes('sf-card-name-2'), m.className);
            check(`${tag}: the prices of the cards of the first row are at the same height`, new Set(m.rowFooterTops).size === 1, JSON.stringify(m.rowFooterTops));
            if (value === '1') {
                check(`${tag}: the long name is ONE line (nowrap) cut with an ellipsis`, m.whiteSpace === 'nowrap' && m.textOverflow === 'ellipsis' && m.nameHeight <= m.lineHeight + 2 && m.cut, JSON.stringify({ ws: m.whiteSpace, to: m.textOverflow, h: m.nameHeight, lh: m.lineHeight, cut: m.cut }));
                check(`${tag}: no second line is reserved (a short name is one line high) and the price is right under the name`, m.otherNameHeight <= m.lineHeight + 2 && m.gapNameToPrice <= 16, JSON.stringify({ other: m.otherNameHeight, gap: m.gapNameToPrice }));
            } else {
                check(`${tag}: the long name is a TWO-line clamp`, m.nameHeight >= m.lineHeight * 1.8 && m.nameHeight <= m.lineHeight * 2.2 && m.cut, JSON.stringify({ h: m.nameHeight, lh: m.lineHeight, cut: m.cut }));
                check(`${tag}: a short name still reserves two lines`, m.otherNameHeight >= m.lineHeight * 1.8, JSON.stringify({ other: m.otherNameHeight }));
            }
            if (OUT) {
                const file = join(OUT, `dribni4-card-${label}-${value}.png`);
                shots.push(file);
                await p.screenshot({ path: file, fullPage: true, clip: m.rowBox });
            }
            await c.close();
        }
    }
} catch (e) {
    check('the run finished without an exception', false, String(e).slice(0, 600));
} finally {
    if (watchdog) clearTimeout(watchdog);
    if (created) {
        try {
            php(RESTORE);
            const after = JSON.parse(php(SUMS));
            check('the site_settings rows are back EXACTLY (checksum of every row, by id)', after.settings === before.settings, `${before.settings} vs ${after.settings}`);
            check('the published home layout is unchanged (its own checksum)', JSON.stringify(after.published) === JSON.stringify(before.published), JSON.stringify([before.published, after.published]));
            check('the throw-away staff member and the HTML blocks are removed', after.staff === before.staff && after.html_blocks === before.html_blocks, JSON.stringify([before.staff, after.staff, before.html_blocks, after.html_blocks]));
        } catch (e) {
            check('the data was put back', false, String(e).slice(0, 600));
        }
    }
    await browser.close();
    const failed = steps.filter((s) => ! s.ok);
    console.log(JSON.stringify({ base: BASE, report, shots, console_errors: consoleErrors, steps, failed: failed.length }, null, 2));
    process.exit(failed.length === 0 ? 0 : 1);
}
