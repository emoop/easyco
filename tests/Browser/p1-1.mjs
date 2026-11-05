// P1.1 — a REAL-BROWSER check of the «Препоръчан» row on the three product forms, at 1280px and 360px.
//   Layout: «Препоръчан» has its own row ABOVE the name, aligned to the right; the name is full width below it. On the SIMPLE product «Продаваем» sits in that row too, to the right of «Препоръчан».
//   On a phone (360px) the switches stack one above the other, above the name. «Препоръчан» is on the PARENT product only: no variation row has it.
//   The forms are only OPENED, never saved. Dev data: one throw-away staff member for the login (deleted at the end); every site_settings row is checksummed before and after.
//
// NOT part of `php artisan test`: run by hand against the dev site. The screenshots go to OUT, which must be OUTSIDE the project folder (the script refuses otherwise).
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p1-1.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) {
    console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p1-1.mjs');
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

const EMAIL = `p11-${Date.now()}@example.test`;
const PASSWORD = 'P11audit-' + Math.random().toString(36).slice(2) + 'Aa1!';

// checksum of every site_settings row (by id) and of the staff count: the same before and after the run
const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => [$r->id, $r->key, md5((string) $r->value)])->all();
echo json_encode(['settings' => md5(json_encode($rows)), 'count' => count($rows), 'staff' => DB::table('staff')->count()]);`;

const CREATE_STAFF = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'P11 Audit', $role));
echo 'ok';`;

const DROP_STAFF = String.raw`
use Illuminate\Support\Facades\DB;
DB::table('staff')->where('email', '${EMAIL}')->delete();
echo 'dropped';`;

// The three forms. The simple product (id 3) and the variable product (id 4) are the dev shop's own; they are only opened.
const FORMS = [
    { key: 'simple_edit', url: '/admin/products/3/edit', simple: true, variation: false },
    { key: 'variable_create', url: '/admin/products/create-variable', simple: false, variation: false },
    { key: 'variable_edit', url: '/admin/products/4/edit-variable', simple: false, variation: true },
];

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });

const steps = [];
const consoleErrors = [];
const report = {};
const check = (name, ok, detail = '') => {
    steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) });
    if (! ok) console.error('FAIL ' + name + ' ' + String(detail).slice(0, 300));
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Measures the top of one form: the «Препоръчан» row (the flex row holding the switches), the name field and its label.
const measure = (page) => page.evaluate(() => {
    const vis = (el) => el && el.getClientRects().length > 0 && el.getBoundingClientRect().width > 0;
    const clean = (t) => t.replace(/\*/g, '').trim();
    const labelOf = (text) => [...document.querySelectorAll('label')].find((l) => clean(l.textContent) === text && vis(l));
    const box = (el) => { const b = el.getBoundingClientRect(); return { x: Math.round(b.left), y: Math.round(b.top + scrollY), w: Math.round(b.width), right: Math.round(b.right), bottom: Math.round(b.bottom + scrollY) }; };

    const featuredLabel = labelOf('Препоръчан');
    const purchasableLabel = labelOf('Продаваем');
    const nameInput = document.getElementById('form.name');
    const nameLabel = labelOf('Име');
    const row = featuredLabel?.closest('.fi-sc-flex') ?? null;

    // the switches of the row: the VISIBLE content only (label text, switch, info icon). Plain wrapper divs are left out:
    // they can be stretched to the row's full width and would say nothing about where the switch itself sits.
    const inRow = row ? [...row.querySelectorAll('label, input, button, svg')].filter(vis) : [];
    const rowInner = inRow.length ? {
        right: Math.max(...inRow.map((e) => e.getBoundingClientRect().right)),
        bottom: Math.max(...inRow.map((e) => e.getBoundingClientRect().bottom + scrollY)),
        top: Math.min(...inRow.map((e) => e.getBoundingClientRect().top + scrollY)),
    } : null;

    // diagnostics: the direct children of the row (what each one is and how wide it renders)
    const kids = row ? [...row.children].map((k) => { const b = k.getBoundingClientRect(); const cs = getComputedStyle(k); return { tag: k.tagName, cls: String(k.className).slice(0, 140), x: Math.round(b.left), right: Math.round(b.right), w: Math.round(b.width), width: cs.width, grow: cs.flexGrow, shrink: cs.flexShrink }; }) : [];

    const variationFeatured = [...document.querySelectorAll('[id*="existing_variations"][id*="is_featured"], [id*="new_variations"][id*="is_featured"], [id*="archived_variations"][id*="is_featured"]')].length;

    return {
        row: row ? box(row) : null,
        row_inner: rowInner ? { right: Math.round(rowInner.right), bottom: Math.round(rowInner.bottom), top: Math.round(rowInner.top) } : null,
        featured: featuredLabel ? box(featuredLabel) : null,
        purchasable: purchasableLabel ? box(purchasableLabel) : null,
        name_input: nameInput ? box(nameInput) : null,
        name_label: nameLabel ? box(nameLabel) : null,
        row_children: kids,
        featured_labels_on_page: [...document.querySelectorAll('label')].filter((l) => clean(l.textContent) === 'Препоръчан' && vis(l)).length,
        featured_in_variation_rows: variationFeatured,
        hscroll: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    };
});

let created = false;
let before = null;
let watchdog = null;

try {
    before = JSON.parse(php(SUMS));
    created = true;
    watchdog = setTimeout(() => { try { php(DROP_STAFF); } catch (e) { console.error('WATCHDOG drop failed ' + e); } console.error('WATCHDOG: the run took more than 8 minutes; the staff member was deleted'); process.exit(3); }, 8 * 60 * 1000);
    check('the throw-away staff member was created', php(CREATE_STAFF) === 'ok');

    const login = async (ctx) => {
        const page = await ctx.newPage();
        page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        page.on('response', (r) => { r.status() >= 500 && consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 120)); });
        await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
        await page.fill('input[type=email]', EMAIL);
        await page.fill('input[type=password]', PASSWORD);
        await page.keyboard.press('Enter');
        await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
        return page;
    };

    for (const width of [1280, 360]) {
        const ctx = await browser.newContext({ viewport: { width, height: width === 1280 ? 900 : 800 }, ignoreHTTPSErrors: true });
        ctx.setDefaultTimeout(30000);
        const page = await login(ctx);

        for (const form of FORMS) {
            await page.goto(BASE + form.url, { waitUntil: 'networkidle' });
            await sleep(2000);
            const m = await measure(page);
            report[`${form.key}_${width}`] = m;
            const tag = `${form.key} ${width}px`;

            check(`${tag}: the page loads with the name field`, m.name_input !== null, JSON.stringify(m.name_input));
            check(`${tag}: «Препоръчан» has its own row`, m.row !== null && m.featured !== null, JSON.stringify(m.row));
            check(`${tag}: exactly one «Препоръчан» on the page (the parent's)`, m.featured_labels_on_page === 1, m.featured_labels_on_page);
            check(`${tag}: no variation row has an is_featured field`, m.featured_in_variation_rows === 0, m.featured_in_variation_rows);
            check(`${tag}: no horizontal scroll`, ! m.hscroll, m.hscroll);

            if (width === 1280) {
                // the row sits ABOVE the name and its switches end at the right edge of the name's column
                check(`${tag}: the row is above the name`, m.row_inner && m.name_label && m.row_inner.bottom <= m.name_label.y, JSON.stringify({ row: m.row_inner, name: m.name_label }));
                check(`${tag}: the row's right edge is within 4px of the name field's right edge`, m.row_inner && m.name_input && Math.abs(m.row_inner.right - m.name_input.right) <= 4, JSON.stringify({ row: m.row_inner?.right, name: m.name_input?.right }));
                check(`${tag}: the name field is full width of the row`, m.row && m.name_input && Math.abs(m.name_input.w - m.row.w) <= 4 && Math.abs(m.name_input.x - m.row.x) <= 4, JSON.stringify({ name: m.name_input, row: m.row }));
                if (form.simple) {
                    check(`${tag}: «Продаваем» is in the same row, right of «Препоръчан»`, m.purchasable && m.featured && Math.abs(m.purchasable.y - m.featured.y) <= 4 && m.purchasable.x > m.featured.x, JSON.stringify({ f: m.featured, p: m.purchasable }));
                    check(`${tag}: «Продаваем» ends at the right edge`, m.purchasable && m.name_input && Math.abs(m.purchasable.right - m.name_input.right) <= 4, JSON.stringify({ p: m.purchasable?.right, name: m.name_input?.right }));
                } else {
                    check(`${tag}: no «Продаваем» (the variable product has none)`, m.purchasable === null, JSON.stringify(m.purchasable));
                }
            } else {
                // a phone: the switches are one above the other, the whole row is above the name, the name is full width
                check(`${tag}: the row is above the name`, m.row_inner && m.name_label && m.row_inner.bottom <= m.name_label.y, JSON.stringify({ row: m.row_inner, name: m.name_label }));
                if (form.simple) {
                    check(`${tag}: «Препоръчан» and «Продаваем» are stacked, «Препоръчан» first`, m.purchasable && m.featured && m.featured.y < m.purchasable.y && Math.abs(m.featured.x - m.purchasable.x) <= 4, JSON.stringify({ f: m.featured, p: m.purchasable }));
                }
                check(`${tag}: the name field is full width`, m.row && m.name_input && Math.abs(m.name_input.w - m.row.w) <= 4, JSON.stringify({ name: m.name_input?.w, row: m.row?.w }));
            }

            await page.screenshot({ path: join(OUT, `p1-1-${form.key}-${width}.png`), clip: { x: 0, y: 0, width, height: width === 1280 ? 900 : 800 } });
        }
        await ctx.close();
    }

    check('no page error and no 5xx during the run', consoleErrors.length === 0, JSON.stringify(consoleErrors.slice(0, 5)));
} finally {
    let dropped = null;
    if (created) {
        try { dropped = php(DROP_STAFF); } catch (e) { console.error('drop failed ' + e); }
    }
    let after = null;
    try { after = JSON.parse(php(SUMS)); } catch (e) { console.error('checksum after failed ' + e); }
    if (watchdog) clearTimeout(watchdog);
    await browser.close();

    const settingsSame = before && after && before.settings === after.settings && before.count === after.count;
    check('site_settings: every row unchanged (checksum by id)', settingsSame, JSON.stringify({ before, after }));
    check('the throw-away staff member is gone', after && before && after.staff === before.staff, JSON.stringify({ before: before?.staff, after: after?.staff }));

    const failed = steps.filter((s) => ! s.ok);
    console.log(JSON.stringify({ failed: failed.length, total: steps.length, dropped, steps, report, consoleErrors }, null, 1));
    process.exit(failed.length === 0 ? 0 : 1);
}
