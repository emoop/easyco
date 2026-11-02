// A REAL-BROWSER run of HINT-1 (Design screen): a field's explanation is an info icon next to its label instead of a paragraph under the field. It checks, in the «Каталог» zone at 1280px and 360px: no helper
// paragraph is left under a field; every icon is a button with an accessible name and `type="button"`, described by its bubble (role="tooltip"); the bubble is hidden until the icon is hovered, focused (keyboard) or
// tapped, Esc hides it, a tap elsewhere hides it; the bubble looks as decided (13px, a 1px hairline, no shadow, square corners, at most 280px) and stays inside the screen; no horizontal scroll.
// NOT part of `php artisan test`: run by hand against a dev site. The Design screen needs a signed-in admin: the run creates ONE throw-away staff member (e-mail «hint1-…@example.test», role Administrator) through
// the application's own staff repository, and DELETES it at the end (and checks the staff table is as it was). It changes no design setting. Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/hint-1.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node hint-1.mjs');
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

const EMAIL = `hint1-${Date.now()}@example.test`;
const PASSWORD = 'Hint1-' + Math.random().toString(36).slice(2) + 'Aa1!';

const COUNTS = String.raw`
use Illuminate\Support\Facades\DB;
$out = ['staff' => DB::table('staff')->count()];
foreach (DB::select('show tables') as $t) { $n = array_values((array) $t)[0]; if (preg_match('/activity|audit/i', $n)) { $out[$n] = DB::table($n)->count(); } }
echo json_encode($out);`;

const CREATE = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
$staff = Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'Hint1 Audit', $role);
app(StaffRepository::class)->save($staff);
echo 'created';`;

const REMOVE = String.raw`
use Illuminate\Support\Facades\DB;
$removed = DB::table('staff')->where('email', '${EMAIL}')->delete();
echo 'removed '.$removed;`;

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
    php(CREATE);
    created = true;

    for (const width of [1280, 360]) {
        const touch = width < 600;
        const ctx = await browser.newContext({ viewport: { width, height: touch ? 800 : 1000 }, ignoreHTTPSErrors: true, ...(touch ? { hasTouch: true, isMobile: true } : {}) });
        const page = await ctx.newPage();
        page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        const shot = async (name) => { if (OUT) { const p = join(OUT, name + '.png'); shots.push(p); await page.screenshot({ path: p, fullPage: false }); } };

        await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
        await page.fill('input[type=email]', EMAIL);
        await page.fill('input[type=password]', PASSWORD);
        await page.keyboard.press('Enter');
        await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
        await page.goto(BASE + '/admin/design', { waitUntil: 'networkidle' });
        await page.locator('.ds-zone', { hasText: /Списък|Каталог|Catalog/ }).first().click();
        await page.waitForSelector('.ds-props .info-hint', { timeout: 30000 });
        await sleep(600);

        const props = '.ds-props';
        const icons = await page.locator(`${props} .info-hint`).count();
        const helper = await page.locator(`${props} .fi-fo-field-helper-text`).count();
        check(`${width}: the properties panel has info icons and no helper paragraph under a field`, icons >= 5 && helper === 0, JSON.stringify({ icons, helper }));
        check(`${width}: no horizontal scroll`, await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));

        const meta = await page.$$eval(`${props} .info-hint`, (els) => els.map((e) => { const b = e.querySelector('button'); const t = e.querySelector('[role=tooltip]'); return { type: b.getAttribute('type'), name: b.getAttribute('aria-label'), by: b.getAttribute('aria-describedby'), id: t.id, text: t.textContent.trim().length, shown: getComputedStyle(t).display }; }));
        check(`${width}: every icon is a type=button with an accessible name, described by its own bubble, and every bubble starts hidden`, meta.every((m) => m.type === 'button' && m.name && m.by === m.id && m.text > 10 && m.shown === 'none') && new Set(meta.map((m) => m.id)).size === meta.length, JSON.stringify(meta.slice(0, 2)));
        await shot(`hint1-closed-${width}`);

        const bubbleOf = (i) => page.locator(`${props} .info-hint`).nth(i).locator('[role=tooltip]');
        const rect = (i) => bubbleOf(i).evaluate((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return { l: r.left, r: r.right, t: r.top, b: r.bottom, w: r.width, fs: cs.fontSize, bw: cs.borderTopWidth, sh: cs.boxShadow, rad: cs.borderTopLeftRadius, bg: cs.backgroundColor, display: cs.display }; });
        const inside = (r) => r.l >= 0 && r.r <= width + 0.5 && r.t >= 0 && r.b <= 4000;

        for (const i of [0, icons - 1]) {
            const btn = page.locator(`${props} .info-hint`).nth(i).locator('button');
            await btn.scrollIntoViewIfNeeded();

            if (touch) {
                await btn.tap();
            } else {
                await btn.hover();
            }

            await sleep(200);
            const r = await rect(i);
            check(`${width}: the ${i === 0 ? 'first' : 'last'} icon ${touch ? 'tapped' : 'hovered'} shows its bubble: 13px, a 1px hairline, no shadow, square corners, white, ≤ 280px, inside the screen`, r.display === 'block' && r.fs === '13px' && r.bw === '1px' && r.sh === 'none' && r.rad === '0px' && r.bg === 'rgb(255, 255, 255)' && r.w <= 281 && inside(r), JSON.stringify(r));
            i === 0 && await shot(`hint1-${touch ? 'tap' : 'hover'}-first-${width}`);

            if (touch) {
                await page.locator('.ds-h').first().tap();
            } else {
                await page.mouse.move(5, 5);
            }

            await sleep(200);
            check(`${width}: a tap elsewhere / leaving the icon hides the bubble again`, (await rect(i)).display === 'none');
        }

        // keyboard: focus the icon (Tab), the bubble shows, Esc hides it
        const first = page.locator(`${props} .info-hint button`).first();
        await first.focus();
        await page.keyboard.press('Tab');
        await page.keyboard.press('Shift+Tab');
        await sleep(150);
        check(`${width}: keyboard focus on the icon shows the bubble`, (await rect(0)).display === 'block');
        await page.keyboard.press('Escape');
        await sleep(150);
        check(`${width}: Esc hides the bubble`, (await rect(0)).display === 'none');
        await shot(`hint1-after-${width}`);

        const height = await page.$eval(props, (e) => Math.round(e.getBoundingClientRect().height));
        console.error(`(info) ${width}px: the properties panel is ${height}px high with ${icons} icons`);
        await ctx.close();
    }
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (created) {
        try {
            php(REMOVE);
            const after = JSON.parse(php(COUNTS));
            check('the throw-away staff member is gone: the staff table (and any activity table) has the counts it had', before && JSON.stringify(before) === JSON.stringify(after), JSON.stringify({ before, after }));
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
