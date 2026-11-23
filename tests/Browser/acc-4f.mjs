// A REAL-BROWSER check of ACC-4f: the two items of the «Поверителност» sidebar group at 1280px: are the labels cut, and does each item carry the full page title as its tooltip?
//   Writes ONE throw-away Administrator to the DEV database and deletes it at the end (row count printed before and after). Nothing else is written.
// NOT part of `php artisan test`.
//
//   CHROMIUM=<chrome.exe> AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> OUT=<folder outside the project> node tests/Browser/acc-4f.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node acc-4f.mjs'); process.exit(2); }
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) { console.error('refused: OUT is inside the project folder'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

const php = (code) => {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, timeout: 120000 });
    const out = (r.stdout ?? '').trim();
    if (r.status !== 0 || out === '') { throw new Error(`php failed (${r.status}): ` + (out + '\n' + (r.stderr ?? '')).slice(-900)); }
    return out.split('\n').pop();
};

const TAG = `acc4f-${Date.now()}`;
const STAFF = `${TAG}-admin@example.test`;
const PASSWORD = 'Acc4f-' + Math.random().toString(36).slice(2) + 'Aa1!';

const COUNT = `echo Illuminate\\Support\\Facades\\DB::table('staff')->count();`;
const SEED = `
use EasyCo\\Staff\\Staff;
use EasyCo\\Staff\\Contracts\\{RoleRepository, StaffRepository, PasswordHasher};
$role = app(RoleRepository::class)->findSystemRoleByName('Administrator');
$staff = Staff::create('${STAFF}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'Audit Admin', $role);
app(StaffRepository::class)->save($staff);
echo 'seeded';
`;
const CLEAN = `Illuminate\\Support\\Facades\\DB::table('staff')->where('email', '${STAFF}')->delete(); echo 'cleaned';`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'package.json'))('playwright');
const result = { staffBefore: php(COUNT) };
let browser = null;

try {
    php(SEED);
    browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, headless: true });
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, ignoreHTTPSErrors: true });
    const page = await context.newPage();
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', STAFF);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
    await page.goto(BASE + '/admin/privacy-log', { waitUntil: 'networkidle' });

    // the group is collapsed by default: open it, then measure
    const group = page.locator('.fi-sidebar-group').filter({ hasText: 'Поверителност' }).first();
    if (! (await group.locator('.fi-sidebar-item-label').first().isVisible())) { await group.locator('.fi-sidebar-group-btn, .fi-sidebar-group-collapse-btn').first().click(); await page.waitForTimeout(600); }

    result.items = await page.evaluate(() => [...document.querySelectorAll('.fi-sidebar-item')]
        .filter((li) => li.querySelector('a')?.getAttribute('href')?.match(/privacy-(log|settings)$/))
        .map((li) => {
            const label = li.querySelector('.fi-sidebar-item-label');
            return { href: li.querySelector('a').getAttribute('href'), label: label.textContent.trim(), title: li.getAttribute('title'), cut: label.scrollWidth > label.clientWidth, labelPx: label.clientWidth, textPx: label.scrollWidth };
        }));
    await page.screenshot({ path: join(OUT, 'sidebar-1280.png') });
} finally {
    if (browser) { await browser.close(); }
    result.cleaned = php(CLEAN);
    result.staffAfter = php(COUNT);
}

console.log(JSON.stringify(result, null, 1));
