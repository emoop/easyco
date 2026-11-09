// A REAL-BROWSER check of P5c: the activity log of the promo codes, the read-only «restricted to N customers» line and the money format of the list.
//   At 1440 and 390 px: edit a promotion and change its minimum → the journal («Дневник») shows ONE entry «Промокод … minimum_spend» with the old and the new value; a promotion with two ACCOUNT scopes (made in the database for the test) shows the
//   line «Ограничен до 2 клиента (задава се само през API)» and keeps both scopes after a save; the list shows the fixed amount and the minimum in the shop's own money format (PriceDisplayFormatter, what the cart and the orders use).
// NOT part of `php artisan test`: run by hand against the dev site. It creates ONE throw-away staff member and three promotions named «p5c-…» and switches the activity log ON for the run. BEFORE the run the promotions, their scopes, the activity-log rows,
// every site setting and the staff are backed up (by id) and checksummed; at the end everything created is deleted, the backed-up rows are put back BY ID and the checksums are compared. A watchdog puts the data back after 12 minutes.
// The screenshots go to OUT, which must be OUTSIDE the project folder (the script refuses otherwise).
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p5c.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p5c.mjs'); process.exit(2); }
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) { console.error('refused: OUT is inside the project folder; the screenshots must be kept outside it'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

const php = (code) => {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    const out = (r.stdout ?? '').trim();
    if (r.status !== 0 || out === '') { throw new Error(`php failed (status ${r.status}): ` + (out + '\n' + (r.stderr ?? '')).slice(-900)); }
    return out.split('\n').pop();
};

const EMAIL = `p5c-${Date.now()}@example.test`;
const PASSWORD = 'P5c-' + Math.random().toString(36).slice(2) + 'Aa1!';

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode(['promotions' => $sum('promotions'), 'scopes' => $sum('promotion_scopes'), 'log' => $sum('activity_log'), 'settings' => $sum('site_settings'), 'staff' => DB::table('staff')->count()]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
$dump = fn ($t) => DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
file_put_contents(sys_get_temp_dir().'/p5c_backup.json', json_encode(['promotions' => $dump('promotions'), 'promotion_scopes' => $dump('promotion_scopes'), 'activity_log' => $dump('activity_log'), 'site_settings' => $dump('site_settings')]));
echo 'ok';`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$ids = DB::table('promotions')->where('code', 'like', 'p5c-%')->pluck('id');
DB::table('promotion_scopes')->whereIn('promotion_id', $ids)->delete();
DB::table('promotions')->whereIn('id', $ids)->delete();
DB::table('staff')->where('email', '${EMAIL}')->delete();
echo 'cleaned';`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/p5c_backup.json'), true);
foreach (['promotions', 'promotion_scopes', 'activity_log', 'site_settings'] as $t) {
  foreach ($bak[$t] as $row) { if (! DB::table($t)->where('id', $row['id'])->exists()) { DB::table($t)->insert($row); } else { DB::table($t)->where('id', $row['id'])->update($row); } }
  DB::table($t)->whereNotIn('id', array_column($bak[$t], 'id'))->delete();
}
echo 'restored';`;

const CREATE_STAFF = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'P5c Audit', $role));
echo 'ok';`;

// the activity log ON, an editable promotion, a fixed-amount one with a minimum, and one with two ACCOUNT scopes (made in the database: the form never makes them)
const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
app(App\Settings\Contracts\SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
$now = now();
$base = ['individual_use_only' => 0, 'exclude_sale_items' => 0, 'new_customers_only' => 0, 'status' => 'active', 'is_public' => 0, 'created_at' => $now, 'updated_at' => $now];
DB::table('promotions')->insert($base + ['code' => 'p5c-edit', 'discount_type' => 'percentage', 'discount_percentage_basis_points' => 1000]);
DB::table('promotions')->insert($base + ['code' => 'p5c-fixed', 'discount_type' => 'fixed_amount', 'discount_amount_minor' => 5000, 'discount_amount_currency' => 'EUR', 'minimum_spend_amount_minor' => 7999, 'minimum_spend_amount_currency' => 'EUR']);
$acct = DB::table('promotions')->insertGetId($base + ['code' => 'p5c-acct', 'discount_type' => 'percentage', 'discount_percentage_basis_points' => 500]);
foreach ([['acct-one', 'include'], ['acct-two', 'exclude']] as [$ref, $mode]) { DB::table('promotion_scopes')->insert(['promotion_id' => $acct, 'scope_type' => 'account', 'scope_reference_id' => $ref, 'mode' => $mode, 'created_at' => $now, 'updated_at' => $now]); }
echo 'ok';`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });

const steps = []; const consoleErrors = []; const shots = []; const report = {};
const check = (name, ok, detail = '') => { process.env.TRACE && console.error((ok ? '· ' : 'FAIL ') + name.slice(0, 90)); steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + String(detail).slice(0, 300)); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const row = (code) => JSON.parse(php(String.raw`echo json_encode(Illuminate\Support\Facades\DB::table('promotions')->where('code', '${code}')->first());`));
const logOf = (id) => JSON.parse(php(String.raw`echo json_encode(Illuminate\Support\Facades\DB::table('activity_log')->where('entity_type', 'promotion')->where('entity_id', '${id}')->orderBy('id')->get(['action', 'field', 'old_value', 'new_value']));`));
const money = (minor) => php(String.raw`echo app(App\Services\PriceDisplayFormatter::class)->format(EasyCo\Pricing\Money::fromMinorUnits(${minor}, 'EUR')->decimalValue(), EasyCo\Pricing\Currency::of('EUR'));`);

let before = null; let created = false; let watchdog = null;

try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    created = true;
    watchdog = setTimeout(() => { try { php(CLEANUP); php(RESTORE); } catch (e) { console.error('WATCHDOG restore failed ' + e); } console.error('WATCHDOG: the run took more than 12 minutes; the data was put back'); process.exit(3); }, 12 * 60 * 1000);
    check('the throw-away staff member was created', php(CREATE_STAFF) === 'ok');
    check('the throw-away promotions were created and the activity log is on', php(SETUP) === 'ok');
    const edit = row('p5c-edit'); const fixed = row('p5c-fixed'); const acct = row('p5c-acct');

    for (const width of [1440, 390]) {
        const ctx = await browser.newContext({ viewport: { width, height: 1300 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
        ctx.setDefaultTimeout(25000);
        const page = await ctx.newPage();
        page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR@${width} ` + String(e).slice(0, 200)));
        page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(`CONSOLE@${width} ` + m.text().slice(0, 200)); });
        page.on('response', (r) => { r.status() >= 500 && consoleErrors.push(`HTTP@${width} ` + r.status() + ' ' + r.url().slice(0, 120)); });

        await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
        await page.fill('input[type=email]', EMAIL);
        await page.fill('input[type=password]', PASSWORD);
        await page.keyboard.press('Enter');
        await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

        const field = (label) => page.locator('.fi-fo-field').filter({ has: page.locator('.fi-fo-field-label-content', { hasText: new RegExp('^\\s*' + label + '\\s*\\*?\\s*$') }) }).first();
        const input = (label) => field(label).locator('input:not([type=hidden])').first();
        const save = async () => { await page.locator('main form button[type=submit]').first().click(); await sleep(2500); };
        const hscroll = () => page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);

        // ---- 1. edit the minimum: ONE journal entry
        const entriesBefore = logOf(edit.id).length;
        await page.goto(BASE + '/admin/promotions/' + edit.id + '/edit', { waitUntil: 'networkidle' });
        await sleep(800);
        const newMinimum = width === 1440 ? '45.5' : '46';
        await input('Минимална сума').fill(newMinimum);
        await save();
        await page.waitForURL(/admin\/promotions$/);
        const entries = logOf(edit.id).slice(entriesBefore);
        report[`entries_${width}`] = entries;
        check(`${width}: the edit wrote exactly ONE entry, for minimum_spend, with the old and the new value`, entries.length === 1 && entries[0].action === 'updated' && entries[0].field === 'minimum_spend' && entries[0].new_value === (width === 1440 ? '45.50' : '46.00') && entries[0].old_value === (width === 1440 ? '' : '45.50'), JSON.stringify(entries));

        // saving the same value again writes nothing
        await page.goto(BASE + '/admin/promotions/' + edit.id + '/edit', { waitUntil: 'networkidle' });
        await sleep(800);
        await save();
        check(`${width}: saving without a change writes no entry`, logOf(edit.id).length === entriesBefore + 1);

        // ---- 2. the journal shows it under «Промокод»
        await page.goto(BASE + '/admin/activity-log-journal', { waitUntil: 'networkidle' });
        await sleep(1000);
        const journal = (await page.locator('.fi-ta').first().innerText()).replace(/\s+/g, ' ');
        report[`journal_${width}`] = journal.slice(0, 400);
        check(`${width}: the journal shows the entry as «Промокод» with minimum_spend`, journal.includes('Промокод') && journal.includes('minimum_spend'), journal.slice(0, 300));
        check(`${width}: the journal has no horizontal scroll of the page`, ! (await hscroll()));
        const s1 = join(OUT, `p5c-journal-${width}.png`); shots.push(s1); await page.screenshot({ path: s1 });

        // ---- 3. the read-only customer restriction
        await page.goto(BASE + '/admin/promotions/' + acct.id + '/edit', { waitUntil: 'networkidle' });
        await sleep(800);
        const line = page.locator('[data-promotion-account-scopes]');
        check(`${width}: the line «Ограничен до 2 клиента (задава се само през API)» is shown`, (await line.count()) === 1 && (await line.innerText()).includes('Ограничен до 2 клиента (задава се само през API)'), await line.count());
        check(`${width}: it is not a field (no input in it)`, (await line.locator('input, textarea, select').count()) === 0);
        const s2 = join(OUT, `p5c-account-scopes-${width}.png`); shots.push(s2); await page.screenshot({ path: s2, fullPage: true });
        await input('Минимална сума').fill('5');
        await save();
        check(`${width}: saving kept both ACCOUNT scopes`, php(String.raw`echo Illuminate\Support\Facades\DB::table('promotion_scopes')->where('promotion_id', ${acct.id})->where('scope_type', 'account')->count();`) === '2');
        await page.goto(BASE + '/admin/promotions/' + edit.id + '/edit', { waitUntil: 'networkidle' });
        await sleep(600);
        check(`${width}: a promotion with no ACCOUNT scope shows no such line`, (await page.locator('[data-promotion-account-scopes]').count()) === 0);

        // ---- 4. the money format of the list
        await page.goto(BASE + '/admin/promotions', { waitUntil: 'networkidle' });
        await sleep(800);
        const listRow = (await page.locator('.fi-ta-row', { hasText: 'p5c-fixed' }).first().innerText()).replace(/\s+/g, ' ');
        report[`list_row_${width}`] = listRow;
        check(`${width}: the list shows the discount (${money(5000)}) and the minimum (${money(7999)}) in the shop's own format`, listRow.includes(money(5000)) && listRow.includes('мин. ' + money(7999)), listRow);
        check(`${width}: the list has no horizontal scroll`, ! (await hscroll()));
        const s3 = join(OUT, `p5c-list-${width}.png`); shots.push(s3); await page.screenshot({ path: s3 });

        // second width starts from the same minimum
        php(String.raw`Illuminate\Support\Facades\DB::table('promotions')->where('id', ${acct.id})->update(['minimum_spend_amount_minor' => null, 'minimum_spend_amount_currency' => null]); echo 'ok';`);
        await ctx.close();
        if (width === 1440) { php(String.raw`Illuminate\Support\Facades\DB::table('activity_log')->where('entity_type', 'promotion')->where('entity_id', '${acct.id}')->delete(); echo 'ok';`); }
    }

    check('no console errors, no page errors, no 5xx answers', consoleErrors.length === 0, consoleErrors.join(' | '));
} catch (e) {
    check('the run finished without an exception', false, String(e).slice(0, 900));
} finally {
    if (watchdog) clearTimeout(watchdog);
    if (created) {
        try {
            php(CLEANUP);
            php(RESTORE);
            const after = JSON.parse(php(SUMS));
            for (const k of ['promotions', 'scopes', 'log', 'settings', 'staff']) {
                check(`${k}: back EXACTLY as before the run`, after[k] === before[k], `${before[k]} vs ${after[k]}`);
            }
        } catch (e) {
            check('the data was put back', false, String(e).slice(0, 600));
        }
    }
    await browser.close();
    const failed = steps.filter((s) => ! s.ok);
    console.log(JSON.stringify({ base: BASE, report, shots, console_errors: consoleErrors, steps, failed: failed.length }, null, 2));
    process.exit(failed.length === 0 ? 0 : 1);
}
