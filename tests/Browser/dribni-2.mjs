// A REAL-BROWSER check of DRIBNI-2: the panels of the home zone («Начална») on the Design screen, at 1280px.
//   - the panels of heading, HTML block, image grid, tiles, product grid and carousel: one field per row (no two fields side by side), no two labels overlap, no sideways scroll;
//   - the info icons: the tiles' `gap` and `radius` show the TILES' explanation; the image grid's `gap` and `radius` show the GRID's explanation;
//   - the scheme labels of the image grid are on one line each;
//   - the tiles' radius select offers «50% (кръг)»;
//   - an invalid tile (tile #2 is a free tile without a picture) shows its message INSIDE tile #2 and not inside tile #1;
//   - screenshots of every panel (read by the coder) in the folder OUT.
// NOT part of `php artisan test`: run by hand against the dev site. It creates ONE throw-away staff member (Administrator), sets the working copy of the home zone to an EMPTY draft for the
// run, and changes it through the Design screen. Before the run the site_settings rows (the owner's published home layout and draft included) are backed up; at the end they are put back BY ID,
// the throw-away staff member is removed, the appearance is recompiled, and the checksums (every site_settings row, the published layout's own) are compared with the ones taken before.
// A watchdog puts the data back after 8 minutes. Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/dribni-2.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node dribni-2.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

// a non-zero exit OR an empty answer is an error, with the tail of both streams. The code is sent as ONE line (newlines collapsed)
const php = (code) => {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    const out = (r.stdout ?? '').trim();
    if (r.status !== 0 || out === '') {
        throw new Error(`php failed (status ${r.status}): ` + (out + '\n' + (r.stderr ?? '')).slice(-900));
    }
    return out.split('\n').pop();
};

const EMAIL = `dribni2-${Date.now()}@example.test`;
const PASSWORD = 'Dribni2-' + Math.random().toString(36).slice(2) + 'Aa1!';

// every site_settings row and the published home layout's own checksum, and the counts of what this script creates
const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$published = DB::table('site_settings')->where('key', 'design.home.global.published')->first();
echo json_encode(['settings' => md5(json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 'published' => $published ? [$published->id, md5((string) $published->value)] : null, 'staff' => DB::table('staff')->count()]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/dribni2_backup.json', json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo 'ok';`;

// the settings back by id (a row created in the run is removed, a deleted one is inserted again with its id), the staff member removed, the appearance recompiled, the settings put back once more
const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/dribni2_backup.json'), true);
$put = function () use ($bak) { DB::table('site_settings')->whereNotIn('id', array_column($bak, 'id'))->delete(); foreach ($bak as $row) { DB::table('site_settings')->where('id', $row['id'])->update(['key' => $row['key'], 'value' => $row['value'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]); if (! DB::table('site_settings')->where('id', $row['id'])->exists()) { DB::table('site_settings')->insert($row); } } };
$put();
DB::table('staff')->where('email', '${EMAIL}')->delete();
app(App\Storefront\Header\AppearanceRecompiler::class)->run(app(App\Settings\Contracts\SiteSettingsRepository::class));
$put();
echo 'restored';`;

const CREATE = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'DRIBNI-2 Audit', $role));
echo 'ok';`;

// the working copy of the home zone: an EMPTY layout, so that the screen starts with no section of the owner's
const EMPTY_DRAFT = String.raw`
use App\Storefront\Home\HomeValidator; use App\Storefront\Home\HomeDrafts;
[$clean, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => []]);
HomeDrafts::saveDraft($clean);
echo json_encode($errors);`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });

const steps = [];
const consoleErrors = [];
const shots = [];
const report = {};
const check = (name, ok, detail = '') => { process.env.TRACE && console.error((ok ? '· ' : 'FAIL ') + name.slice(0, 90)); steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// the panel's layout measured in the page: the fields side by side, the labels that overlap, the info icons, the width of the panel, the sideways scroll
const analyse = (page) => page.evaluate(() => {
    const props = document.querySelector('.ds-props');
    if (! props) return { missing: true };
    const box = (el) => el.getBoundingClientRect();
    const fields = [...props.querySelectorAll('.fi-fo-field')].filter((el) => el.getClientRects().length > 0);
    let sideBySide = 0;
    for (let i = 0; i < fields.length; i++) {
        for (let j = i + 1; j < fields.length; j++) {
            const a = fields[i];
            const b = fields[j];
            if (a.contains(b) || b.contains(a)) {
                continue;
            }
            const ra = box(a);
            const rb = box(b);
            Math.min(ra.bottom, rb.bottom) - Math.max(ra.top, rb.top) > 4 && sideBySide++;
        }
    }
    const labels = [...props.querySelectorAll('label')].filter((el) => el.getClientRects().length > 0).map(box);
    let labelOverlaps = 0;
    for (let i = 0; i < labels.length; i++) {
        for (let j = i + 1; j < labels.length; j++) {
            const a = labels[i];
            const b = labels[j];
            Math.min(a.right, b.right) - Math.max(a.left, b.left) > 2 && Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 2 && labelOverlaps++;
        }
    }
    return {
        width: Math.round(box(props).width),
        fields: fields.length,
        sideBySide,
        labelOverlaps,
        icons: props.querySelectorAll('[aria-describedby^="ds-hint-"]').length,
        sideways: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    };
});

// the explanation of the field whose label starts with the text: the text of its bubble
const hintOf = (page, labelText) => page.evaluate((t) => {
    const label = [...document.querySelectorAll('.ds-props label')].find((l) => l.textContent.trim().startsWith(t));
    const button = label?.closest('.fi-fo-field')?.querySelector('[aria-describedby^="ds-hint-"]');
    const bubble = button && document.getElementById(button.getAttribute('aria-describedby'));

    return bubble ? bubble.textContent.trim() : null;
}, labelText);

// the scheme labels: the largest number of lines any radio label text takes (1 = every label on one line)
const radioLines = (page) => page.evaluate(() => {
    let most = 0;
    const walker = document.createTreeWalker(document.querySelector('.ds-props'), NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        if (! node.textContent.trim() || ! node.parentElement.closest('.fi-fo-radio')) {
            continue;
        }
        const range = document.createRange();
        range.selectNodeContents(node);
        most = Math.max(most, range.getClientRects().length);
    }
    return most;
});

let before = null;
let created = false;
let watchdog = null;

try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    created = true;
    // a run that hangs must not leave the owner's layout changed: after 8 minutes the data is put back and the run stops
    watchdog = setTimeout(() => { try { php(RESTORE); } catch (e) { console.error('WATCHDOG restore failed ' + e); } console.error('WATCHDOG: the run took more than 8 minutes; the data was put back'); process.exit(3); }, 8 * 60 * 1000);
    check('the throw-away staff member was created', php(CREATE) === 'ok');
    check('the working copy is an empty layout for this run', php(EMPTY_DRAFT) === '[]');

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

    // ============================================================ A. the panels, one per section type
    await page.goto(BASE + '/admin/design?zone=home', { waitUntil: 'networkidle' });
    const types = [
        ['Заглавие с линии', 'heading', 'Текст'],
        ['Свободен HTML блок', 'html_block', 'HTML блок'],
        ['Решетка от снимки', 'image_grid', 'Схема'],
        ['Плочки', 'tiles', 'Колони'],
        ['Продуктова решетка', 'product_grid', 'Източник'],
        ['Карусел', 'carousel', 'Вид'],
    ];

    for (const [chip, key] of types) {
        await page.locator('.ds-chip-add', { hasText: chip }).click();
        await page.waitForSelector('.ds-props', { timeout: 30000 });
        await sleep(1500);
        const a = await analyse(page);
        report[key] = a;
        check(`${key}: the panel is shown`, ! a.missing, JSON.stringify(a));
        check(`${key}: one field per row (no two fields side by side)`, a.sideBySide === 0, JSON.stringify(a));
        check(`${key}: no two labels overlap`, a.labelOverlaps === 0, JSON.stringify(a));
        check(`${key}: nothing scrolls the page sideways at 1280px`, a.sideways === false, JSON.stringify(a));
        if (OUT) {
            const p = join(OUT, `dribni2-${key}.png`);
            shots.push(p);
            await page.locator('.ds-props').screenshot({ path: p });
        }
    }

    // ---- the image grid: its own explanations for gap and radius, the scheme labels on one line
    await page.locator('.ds-chip-main', { hasText: 'Решетка от снимки' }).click();
    await page.waitForSelector('.ds-props');
    await sleep(1200);
    const gridGap = await hintOf(page, 'Отстояние между клетките');
    const gridRadius = await hintOf(page, 'Заоблени ъгли на клетките');
    report.grid_hints = { gap: gridGap, radius: gridRadius };
    check('image grid: «Отстояние между клетките» shows the grid\'s own explanation', (gridGap ?? '').includes('Разстоянието между клетките'), gridGap);
    check('image grid: «Заоблени ъгли на клетките» shows the grid\'s own explanation', (gridRadius ?? '').includes('Заобляне на ъглите на всяка клетка'), gridRadius);
    const lines = await radioLines(page);
    report.scheme_lines = lines;
    check('image grid: every scheme label is on one line', lines === 1, `most lines: ${lines}`);

    // ---- the tiles: their own explanations for gap and radius; the radius options
    await page.locator('.ds-chip-main', { hasText: 'Плочки' }).click();
    await page.waitForSelector('.ds-props');
    await sleep(1200);
    const tilesGap = await hintOf(page, 'Отстояние между плочките');
    const tilesRadius = await hintOf(page, 'Заобляне на снимката');
    report.tiles_hints = { gap: tilesGap, radius: tilesRadius };
    check('tiles: «Отстояние между плочките» shows the tiles\' own explanation', (tilesGap ?? '').includes('Разстоянието между плочките'), tilesGap);
    check('tiles: «Заобляне на снимката» shows the tiles\' own explanation', (tilesRadius ?? '').includes('Заобляне на снимката в проценти'), tilesRadius);
    const radiusField = page.locator('.ds-props .fi-fo-field', { has: page.locator('label', { hasText: 'Заобляне на снимката' }) }).first();
    await radiusField.locator('.fi-select-input-btn').first().click();
    await sleep(600);
    const radiusOptions = await page.getByRole('option').allInnerTexts();
    await page.keyboard.press('Escape');
    report.tiles_radius_options = radiusOptions;
    check('tiles: the radius option 50 is «50% (кръг)»', radiusOptions.includes('50% (кръг)'), JSON.stringify(radiusOptions));

    // ============================================================ B. an invalid tile shows its message at its own row
    // the working copy is the empty layout again, so the only section is this tiles one
    php(EMPTY_DRAFT);
    await page.goto(BASE + '/admin/design?zone=home', { waitUntil: 'networkidle' });
    await page.locator('.ds-chip-add', { hasText: 'Плочки' }).click();
    await page.waitForSelector('.ds-props', { timeout: 30000 });
    await sleep(1200);
    const props = page.locator('.ds-props');
    await props.locator('button', { hasText: 'Добави плочка' }).click();
    await sleep(800);
    await props.locator('button', { hasText: 'Добави плочка' }).click();
    await sleep(800);
    const item = (i) => props.locator('.fi-fo-repeater-item').nth(i);
    const fieldIn = (i, label) => item(i).locator('.fi-fo-field', { has: page.locator('label', { hasText: label }) }).first();

    // tile #1: a category with its first entity (its picture is the category's own, so no error)
    await fieldIn(0, 'Избор').locator('.fi-select-input-btn').first().click();
    await sleep(500);
    await page.getByRole('option').first().click();
    await sleep(400);
    // tile #2: a free tile with a caption and a link, and NO picture
    await fieldIn(1, 'Източник').locator('.fi-select-input-btn').first().click();
    await sleep(500);
    await page.getByRole('option', { name: 'Свободна плочка' }).click();
    await sleep(600);
    await fieldIn(1, 'Надпис').locator('input').fill('Free tile');
    await fieldIn(1, 'Връзка').locator('input').fill('/dribni2-free');
    await sleep(400);

    await page.locator('button.ds-btn-primary', { hasText: /Приложи|Apply/ }).click();
    await sleep(2500);

    const errorIn = (i) => item(i).locator('.fi-fo-field-wrp-error-message');
    const errorsTile2 = await errorIn(1).count();
    const errorsTile1 = await errorIn(0).count();
    const messageTile2 = errorsTile2 > 0 ? (await errorIn(1).first().innerText()).trim() : '';
    report.tile_errors = { tile1: errorsTile1, tile2: errorsTile2, message2: messageTile2 };
    check('an invalid tile #2 shows its message inside tile #2', errorsTile2 > 0 && messageTile2 !== '', JSON.stringify(report.tile_errors));
    check('tile #1 shows no message', errorsTile1 === 0, JSON.stringify(report.tile_errors));
    if (OUT) {
        const p = join(OUT, 'dribni2-tile-error.png');
        shots.push(p);
        await props.screenshot({ path: p });
    }
    await ctx.close();
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
            check('the throw-away staff member is removed', after.staff === before.staff, `${before.staff} vs ${after.staff}`);
        } catch (e) {
            check('the data was put back', false, String(e).slice(0, 600));
        }
    }
    await browser.close();
    const failed = steps.filter((s) => ! s.ok);
    console.log(JSON.stringify({ base: BASE, report, shots, console_errors: consoleErrors, steps, failed: failed.length }, null, 2));
    process.exit(failed.length === 0 ? 0 : 1);
}
