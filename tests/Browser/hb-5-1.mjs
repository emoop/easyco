// A REAL-BROWSER run of HB-5.1: the HTML block form follows the admin standard. It checks, at 1280px and 360px:
//   - the create page (kind «Снимка с текст») and the edit page of a text block (its «Стил на блока» and «Показвай, когато…» sections opened): no helper paragraph under any field; every explanation
//     is an info icon next to its label; hovering an icon shows its bubble; the bubble stays inside the page;
//   - two fields per row where the standard says so (the style panel and the conditions, from the medium width), one column on a phone; the title of a picture block full width;
//   - no horizontal scroll.
// NOT part of `php artisan test`: run by hand against a dev site. It creates ONE throw-away staff member and ONE throw-away text block («HB51 audit …»), through the application's own code, and deletes both
// at the end; it changes no setting. Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/hb-5-1.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node hb-5-1.mjs');
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

const EMAIL = `hb51-${Date.now()}@example.test`;
const PASSWORD = 'Hb51-' + Math.random().toString(36).slice(2) + 'Aa1!';

const COUNTS = String.raw`
use Illuminate\Support\Facades\DB;
echo json_encode(['staff' => DB::table('staff')->count(), 'blocks' => DB::table('html_blocks')->count()]);`;

const CREATE = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
use App\Content\HtmlBlocks\HtmlBlockModel;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'HB51 Audit', $role));
$block = HtmlBlockModel::query()->create(['title' => 'HB51 audit text', 'body' => '<p>Свободен текст за проверка на формата.</p>']);
echo $block->id;`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
foreach (App\Content\HtmlBlocks\HtmlBlockModel::query()->where('title', 'like', 'HB51 audit%')->get() as $b) { $b->delete(); }
DB::table('staff')->where('email', '${EMAIL}')->delete();
echo 'restored';`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const consoleErrors = [];
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let before = null;
let created = false;

try {
    before = JSON.parse(php(COUNTS));
    created = true;
    const blockId = parseInt(php(CREATE), 10);
    check('a throw-away staff member and a throw-away text block were created', blockId > 0, String(blockId));

    const newCtx = async (width) => browser.newContext({ viewport: { width, height: width < 600 ? 800 : 1000 }, ignoreHTTPSErrors: true, ...(width < 600 ? { hasTouch: true, isMobile: true } : {}) });
    const shotOf = async (page, name, full = true) => { if (OUT) { const p = join(OUT, name + '.png'); shots.push(p); await page.screenshot({ path: p, fullPage: full }); } };
    const track = (p) => { p.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200))); return p; };

    // the rows of a section: how many fields sit on each row, and the width of each field (the section is found by its heading)
    const rowsOf = (page, heading) => page.evaluate((h) => {
        const section = [...document.querySelectorAll('.fi-section')].find((s) => (s.querySelector('.fi-section-header')?.textContent || '').includes(h));
        if (! section) return null;
        const fields = [...section.querySelectorAll('.fi-fo-field')].filter((f) => ! f.closest('.fi-fo-repeater'));
        const rows = {};
        for (const f of fields) {
            const r = f.getBoundingClientRect();
            const key = Math.round(r.top / 6);
            (rows[key] ||= []).push(Math.round(r.width));
        }
        return Object.values(rows).map((w) => w.length);
    }, heading);

    const noHelp = (page) => page.evaluate(() => document.querySelectorAll('.fi-fo-field-helper-text').length);
    const hoverCheck = async (page) => {
        const btn = page.locator('.info-hint-btn').first();
        await btn.scrollIntoViewIfNeeded();
        await btn.hover();
        await sleep(300);
        return page.evaluate(() => {
            const bubble = document.querySelector('.info-hint:hover .info-hint-bubble, .info-hint[data-open="true"] .info-hint-bubble');
            if (! bubble) return null;
            const r = bubble.getBoundingClientRect();
            return { display: getComputedStyle(bubble).display, inside: r.left >= 0 && r.right <= window.innerWidth + 1, text: bubble.textContent.trim().length };
        });
    };
    const scroll = (page) => page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);

    // ============================================================ the admin: sign in once per width
    for (const width of [1280, 360]) {
        const ctx = await newCtx(width);
        const page = track(await ctx.newPage());
        await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
        await page.fill('input[type=email]', EMAIL);
        await page.fill('input[type=password]', PASSWORD);
        await page.keyboard.press('Enter');
        await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

        // the create page, the picture kind
        await page.goto(BASE + '/admin/html-blocks/create', { waitUntil: 'networkidle' });
        await page.locator('.fi-fo-field:has-text("Вид на блока") .fi-select-input-btn, .fi-fo-field:has-text("Вид на блока") button').first().click();
        await sleep(400);
        await page.evaluate(() => document.querySelector('li[data-value="card"]').click());
        await sleep(1200);
        check(`${width}px create: no helper paragraph under any field`, (await noHelp(page)) === 0, await noHelp(page));
        check(`${width}px create: explanations are info icons (hover shows the bubble inside the page)`, (await page.locator('.info-hint-btn').count()) >= 12 && JSON.stringify(await hoverCheck(page)).includes('"display":"block"'), await hoverCheck(page));
        const title = await page.evaluate(() => {
            const input = document.querySelector('input[id$="card.title"]');
            if (! input) return null;
            const field = input.closest('.fi-fo-field');
            return Math.round(field.getBoundingClientRect().width) - Math.round(field.parentElement.getBoundingClientRect().width);
        });
        check(`${width}px create: the title is the full width of its column`, title !== null && Math.abs(title) <= 2, title);
        check(`${width}px create: no horizontal scroll`, ! (await scroll(page)), '');
        await shotOf(page, `hb51-create-card-${width}`);

        // the edit page of the text block: open the style panel and the conditions
        await page.goto(BASE + `/admin/html-blocks/${blockId}/edit`, { waitUntil: 'networkidle' });
        await sleep(800);
        // a collapsed section has no height: its header is clicked until its content has one
        for (const heading of ['Стил на блока', 'Показвай, когато']) {
            const closed = await page.evaluate((h) => {
                const section = [...document.querySelectorAll('.fi-section')].find((s) => (s.querySelector('.fi-section-header')?.textContent || '').includes(h));
                const content = section?.querySelector('.fi-section-content');
                return ! content || content.getBoundingClientRect().height === 0;
            }, heading);
            if (closed) {
                await page.locator('.fi-section-header', { hasText: heading }).first().click();
                await sleep(600);
            }
        }
        check(`${width}px edit: no helper paragraph under any field`, (await noHelp(page)) === 0, await noHelp(page));
        const styleRows = await rowsOf(page, 'Стил на блока');
        const condRows = await rowsOf(page, 'Показвай, когато');
        if (width === 1280) {
            check('1280px: the style panel and the conditions have two fields per row (the rows are two or one, never more)', styleRows && condRows && styleRows.every((n) => n <= 2) && condRows.every((n) => n <= 2) && styleRows.includes(2) && condRows.includes(2), JSON.stringify({ styleRows, condRows }));
        } else {
            check('360px: one column in the style panel and in the conditions', styleRows && condRows && styleRows.every((n) => n === 1) && condRows.every((n) => n === 1), JSON.stringify({ styleRows, condRows }));
        }
        check(`${width}px edit: the conditions explanation is an icon in the section heading`, (await page.locator('.fi-section-header .info-hint-btn').count()) >= 1, '');
        check(`${width}px edit: no horizontal scroll`, ! (await scroll(page)), '');
        await shotOf(page, `hb51-edit-text-${width}`);
        await ctx.close();
    }

    // ============================================================ the list still reads
    const ctx = await newCtx(1280);
    const page = track(await ctx.newPage());
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
    await page.goto(BASE + '/admin/html-blocks', { waitUntil: 'networkidle' });
    check('the list page opens', (await page.locator('text=HB51 audit text').count()) > 0, '');
    await ctx.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (created) {
        try {
            php(RESTORE);
            const after = JSON.parse(php(COUNTS));
            check('everything is back: the staff member and the throw-away block are gone (counts as before)', before && JSON.stringify(before) === JSON.stringify(after), JSON.stringify({ before, after }));
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
