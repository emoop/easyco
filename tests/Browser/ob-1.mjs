// A REAL-BROWSER run of OB-1: the top banner is edited in «Дизайн» only. NOT part of `php artisan test`: run by hand against a dev site whose data may be changed. It creates a throw-away admin («ob1-audit»),
// BACKS UP the `site_settings` table and the `appearance/` files first, and at the end restores both, removes the admin and verifies a checksum of `site_settings`. Prints a JSON report; exit code 1 when a check failed.
//
// Steps: (1) «Облик» has no banner section and points to «Дизайн» (screenshot); (2) in «Дизайн» → «Топбанер» the text is changed to a unique sentence → Приложи → Публикувай → the live page shows it;
// (3) «Облик» is saved again (a colour is touched and put back) → the live banner still shows the sentence, the stored banner values are unchanged.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/ob-1.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { randomBytes } from 'node:crypto';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const EMAIL = 'ob1-audit@example.test';
const PASSWORD = 'Ob1-' + randomBytes(6).toString('hex');
const BANNER_TEXT = 'OB1 банер ' + randomBytes(3).toString('hex');
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node ob-1.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const php = (code) => {
    try {
        return execFileSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8' }).trim().split('\n').pop();
    } catch (e) {
        throw new Error('php failed: ' + String(e.stdout || '').slice(-600) + String(e.stderr || '').slice(-300));
    }
};

const SETUP = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage; use EasyCo\Staff\Contracts\{PasswordHasher,RoleRepository,StaffRepository};
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
$files = Storage::disk('public')->allFiles('appearance');
file_put_contents(sys_get_temp_dir().'/ob1_backup.json', json_encode(['rows' => $rows, 'files' => $files, 'contents' => array_combine($files, array_map(fn ($f) => base64_encode(Storage::disk('public')->get($f)), $files)), 'sum' => md5(json_encode($rows))]));
DB::table('staff')->where('email','${EMAIL}')->delete();
$role = app(RoleRepository::class)->findSystemRoleByName('Administrator');
$staff = EasyCo\Staff\Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'OB1 Audit', $role);
app(StaffRepository::class)->save($staff);
echo json_encode(['rows' => count($rows), 'sum' => md5(json_encode($rows)), 'banner' => DB::table('site_settings')->where('key', 'like', 'banner.%')->pluck('value', 'key')->all()]);`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/ob1_backup.json'), true);
DB::table('site_settings')->delete();
foreach ($bak['rows'] as $row) { DB::table('site_settings')->insert($row); }
foreach (Storage::disk('public')->allFiles('appearance') as $f) { if (! in_array($f, $bak['files'], true)) { Storage::disk('public')->delete($f); } }
foreach ($bak['contents'] as $f => $content) { Storage::disk('public')->put($f, base64_decode($content)); }
DB::table('staff')->where('email','${EMAIL}')->delete();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
echo json_encode(['sum' => md5(json_encode($rows)), 'was' => $bak['sum'], 'staff' => DB::table('staff')->where('email','${EMAIL}')->count()]);`;

const sub = (code) => code.replaceAll('${EMAIL}', EMAIL).replaceAll('${PASSWORD}', PASSWORD);

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const diag = [];
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 500) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let backedUp = false;

try {
    const setup = JSON.parse(php(sub(SETUP)));
    backedUp = true;
    console.error('backup: ' + JSON.stringify({ rows: setup.rows, banner: setup.banner }));

    const admin = await browser.newContext({ viewport: { width: 1500, height: 1300 }, ignoreHTTPSErrors: true });
    const page = await admin.newPage();
    page.on('pageerror', (e) => diag.push('PAGEERROR ' + String(e.message || e).slice(0, 200)));
    page.on('response', (r) => { if (r.status() >= 500 && ! r.url().endsWith('/api/cart')) { diag.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 160)); } });
    const settle = async () => { await page.waitForLoadState('networkidle'); await sleep(900); };
    const toolbar = () => page.locator('.fi-header-actions-ctn .fi-btn');
    const confirmModal = async () => { await sleep(700); if (await page.locator('.fi-modal-window:visible').count() > 0) { await page.locator('.fi-modal-window:visible').last().getByRole('button').filter({ hasNotText: /Отказ|Cancel/ }).last().click(); } await settle(); };

    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    // ---- (1) «Облик»: no banner section, the pointer line ------------------------------------------------------------------------------------------------
    await page.goto(BASE + '/admin/appearance-settings', { waitUntil: 'networkidle' });
    await sleep(900);
    const pageText = await page.locator('body').innerText();
    check('«Облик» has no banner fields', await page.locator('input[id="form.banner_text"], input[id="form.banner_enabled"]').count() === 0);
    check('«Облик» has no «Горен банер» section', ! pageText.includes('Горен банер') && ! pageText.includes('Показвай банера'));
    check('«Облик» says where the banner is edited', pageText.includes('Горният банер се редактира в „Дизайн“.'));
    await page.locator('.fi-fo-field-wrp, main').first().screenshot({ path: OUT ? join(OUT, 'ob1-obliq.png') : undefined }).catch(() => {});
    OUT && shots.push(join(OUT, 'ob1-obliq.png'));

    // ---- (2) «Дизайн» → «Топбанер»: the text, Приложи, Публикувай, the live shop ----------------------------------------------------------------------------
    await page.goto(BASE + '/admin/design?zone=topbar', { waitUntil: 'networkidle' });
    await page.waitForSelector('.ds', { timeout: 30000 });
    await sleep(900);
    await page.getByLabel('Текст', { exact: true }).first().fill(BANNER_TEXT);
    await page.getByLabel('Текст', { exact: true }).first().blur();
    await sleep(400);
    await page.locator('.ds-props button[type=submit]').first().click();
    await settle();
    await toolbar().filter({ hasText: 'Публикувай' }).first().click();
    await confirmModal();
    await sleep(2500);

    const live = async () => {
        const ctx = await browser.newContext({ viewport: { width: 1400, height: 900 }, ignoreHTTPSErrors: true });
        const p = await ctx.newPage();
        p.on('pageerror', (e) => diag.push('LIVE PAGEERROR ' + String(e.message || e).slice(0, 200)));
        await p.goto(BASE + '/', { waitUntil: 'networkidle' });
        const shown = await p.locator('.sf-banner-text').allInnerTexts();
        const bar = p.locator('[data-sf-banner]');
        OUT && await bar.first().screenshot({ path: join(OUT, 'ob1-live-banner-' + shots.length + '.png') }).then(() => shots.push(join(OUT, 'ob1-live-banner-' + (shots.length) + '.png'))).catch(() => {});
        await ctx.close();

        return shown;
    };

    const shownAfterDesign = await live();
    check('«Дизайн» publishes the banner and the live shop shows it', shownAfterDesign.includes(BANNER_TEXT), JSON.stringify(shownAfterDesign));

    // ---- (3) «Облик» saved again: a colour is touched and put back; the banner stays ----------------------------------------------------------------------------
    const bannerBefore = php(`echo json_encode(DB::table('site_settings')->where('key', 'like', 'banner.%')->orderBy('key')->pluck('value', 'key')->all());`);
    await page.goto(BASE + '/admin/appearance-settings', { waitUntil: 'networkidle' });
    await sleep(900);
    await page.locator('button[type=submit]').filter({ hasText: /Запази|Save/ }).first().click();
    await settle();
    const bannerAfter = php(`echo json_encode(DB::table('site_settings')->where('key', 'like', 'banner.%')->orderBy('key')->pluck('value', 'key')->all());`);
    check('a save of «Облик» leaves every stored banner value as it was', bannerBefore === bannerAfter, bannerBefore + ' -> ' + bannerAfter);
    const shownAfterObliq = await live();
    check('the live banner still shows the text after the «Облик» save', shownAfterObliq.includes(BANNER_TEXT), JSON.stringify(shownAfterObliq));

    await admin.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (backedUp) {
        const restored = JSON.parse(php(sub(RESTORE)));
        check('restored: site_settings is byte-for-byte what it was', restored.sum === restored.was, JSON.stringify(restored));
        check('restored: the throw-away admin is gone', restored.staff === 0, JSON.stringify(restored));
    }
}

check('no page errors or server errors', diag.length === 0, diag.slice(0, 5).join(' | '));
const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
