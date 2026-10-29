// A REAL-BROWSER run of FN-1: Montserrat (text) and Cormorant Garamond (headings) chosen in «Облик», what the live page then really uses, loads and renders (Bulgarian letters included). NOT part of
// `php artisan test`: run by hand against a dev site whose settings may be changed. It creates a throw-away admin, BACKS UP the `site_settings` table and the `appearance/` files first, and at the end restores
// both, removes the admin and verifies a checksum of `site_settings`. Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/fn-1.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { randomBytes } from 'node:crypto';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const EMAIL = 'fn1-audit@example.test';
const PASSWORD = 'Fn1-' + randomBytes(6).toString('hex');
const ROOT = resolve(import.meta.dirname, '..', '..');
const HOST = new URL(BASE).host;
const SAMPLE = 'Жж Дд Зз Ии Йй Лл Пп Цц Шш Щщ Юю Яя АБВГД абвгд 0123456789 € 25 лв. ÀÉÖ ł';

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node fn-1.mjs');
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
file_put_contents(sys_get_temp_dir().'/fn1_backup.json', json_encode(['rows' => $rows, 'files' => $files, 'contents' => array_combine($files, array_map(fn ($f) => base64_encode(Storage::disk('public')->get($f)), $files)), 'sum' => md5(json_encode($rows))]));
DB::table('staff')->where('email','${EMAIL}')->delete();
$role = app(RoleRepository::class)->findSystemRoleByName('Administrator');
$staff = EasyCo\Staff\Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'FN1 Audit', $role);
app(StaffRepository::class)->save($staff);
echo json_encode(['rows' => count($rows), 'sum' => md5(json_encode($rows)), 'slug' => DB::table('catalog_products')->where('status', 'active')->where('catalog_visibility', 'visible')->orderBy('id')->value('slug')]);`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/fn1_backup.json'), true);
DB::table('site_settings')->delete();
foreach ($bak['rows'] as $row) { DB::table('site_settings')->insert($row); }
foreach (Storage::disk('public')->allFiles('appearance') as $f) { if (! in_array($f, $bak['files'], true)) { Storage::disk('public')->delete($f); } }
foreach ($bak['contents'] as $f => $content) { Storage::disk('public')->put($f, base64_decode($content)); }
DB::table('staff')->where('email','${EMAIL}')->delete();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
echo json_encode(['settings' => md5(json_encode($rows)) === $bak['sum'], 'staff' => DB::table('staff')->where('email','${EMAIL}')->count(), 'appearanceFiles' => count(Storage::disk('public')->allFiles('appearance')), 'appearanceWas' => count($bak['files'])]);`;

const sub = (code) => code.replaceAll('${EMAIL}', EMAIL).replaceAll('${PASSWORD}', PASSWORD);

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const diag = [];
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const shot = async (page, name, locator) => { if (! OUT) return; const path = join(OUT, name + '.png'); shots.push(path); await (locator ?? page).screenshot({ path }); };

let backedUp = false;

try {
    const info = JSON.parse(php(sub(SETUP)));
    backedUp = true;
    console.error('backup: ' + JSON.stringify(info));
    const slug = info.slug;

    // what a visitor's page uses and loads
    const visit = async (label) => {
        const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
        const page = await ctx.newPage();
        const fonts = [];
        const foreign = [];
        page.on('pageerror', (e) => diag.push('PAGEERROR ' + page.url().slice(-40) + ' ' + String(e.message || e).slice(0, 200)));
        page.on('response', async (r) => {
            const url = new URL(r.url());

            if (/\.(woff2?|ttf|otf)(\?|$)/i.test(url.pathname) || (r.headers()['content-type'] ?? '').includes('font')) {
                const size = (await r.body().catch(() => Buffer.alloc(0))).length;
                fonts.push({ file: url.pathname.split('/').slice(-2).join('/'), host: url.host, status: r.status(), bytes: size });
            }

            url.host !== HOST && foreign.push(url.host + url.pathname.slice(0, 40));
        });
        await page.goto(BASE + '/product/' + slug, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);
        const used = await page.evaluate(() => ({
            body: getComputedStyle(document.body).fontFamily,
            title: document.querySelector('h1') ? getComputedStyle(document.querySelector('h1')).fontFamily : null,
            titleWeight: document.querySelector('h1') ? getComputedStyle(document.querySelector('h1')).fontWeight : null,
            preload: [...document.querySelectorAll('link[rel=preload][as=font]')].map((l) => l.getAttribute('href')),
        }));
        const styles = await page.evaluate(() => [...document.styleSheets].length);
        console.error(`${label}: ${JSON.stringify(used)} fonts=${fonts.length} foreign hosts=${foreign.length} sheets=${styles}`);

        return { ctx, page, used, fonts, foreign };
    };

    const before = await visit('before');
    console.error('before (the dev shop\'s own choice): ' + JSON.stringify(before.fonts));
    check('before: the page uses a font of its own host only', before.fonts.every((f) => f.host === HOST) && before.foreign.length === 0);
    await before.ctx.close();

    // ---- «Облик»: choose the two fonts ----------------------------------------------------------------------------------------------------------
    const admin = await browser.newContext({ viewport: { width: 1500, height: 1300 }, ignoreHTTPSErrors: true });
    const page = await admin.newPage();
    page.on('pageerror', (e) => diag.push('ADMIN PAGEERROR ' + page.url().slice(-40) + ' ' + String(e.message || e).slice(0, 200)));
    const settle = async () => { await page.waitForLoadState('networkidle'); await sleep(900); };
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
    await page.goto(BASE + '/admin/appearance-settings', { waitUntil: 'networkidle' });
    await sleep(900);

    const choose = async (id, option) => {
        await page.locator('[id="form.' + id + '"]').first().click();
        await sleep(300);
        await page.getByRole('option', { name: option, exact: true }).click();
        await sleep(400);
    };
    const save = async () => { await page.locator('button[type=submit]', { hasText: /Запази|Save/ }).first().click(); await settle(); };

    await choose('font_body', 'Montserrat');
    await choose('font_heading', 'Cormorant Garamond');
    await shot(page, 'fn1-obliq-form');
    await save();
    await sleep(1500);

    const after = await visit('after');
    check('live: the body text is set in Montserrat', after.used.body.startsWith('Montserrat'), after.used.body);
    check('live: the product title (h1) is set in Cormorant Garamond with a serif fallback', after.used.title.startsWith('"Cormorant Garamond"') || after.used.title.startsWith('Cormorant Garamond'), after.used.title);
    check('live: exactly one font preload, the regular latin Montserrat', after.used.preload.length === 1 && after.used.preload[0].endsWith('montserrat/montserrat-latin-400-normal.woff2'), JSON.stringify(after.used.preload));
    check('live: the previous font (Manrope) is no longer loaded', ! after.fonts.some((f) => f.file.startsWith('manrope/')), JSON.stringify(after.fonts.map((f) => f.file)));
    check('live: every font file comes from the shop\'s own host, none from anywhere else', after.fonts.length > 0 && after.fonts.every((f) => f.host === HOST && f.status === 200) && after.foreign.length === 0, JSON.stringify({ foreign: after.foreign }));
    console.error('font files loaded: ' + after.fonts.map((f) => `${f.file} ${f.bytes}B`).join(', '));
    check('live: only needed subsets were fetched (fewer files than the 24 declared faces)', after.fonts.length < 24, after.fonts.length);

    // Bulgarian letters, digits, € and «лв.» in both fonts: the browser must find them in the Cyrillic / latin subsets
    const glyphs = await after.page.evaluate(async (sample) => {
        const out = {};

        for (const [name, font] of [['Montserrat 400', '400 20px Montserrat'], ['Montserrat 600', '600 20px Montserrat'], ['Cormorant 500', '500 28px "Cormorant Garamond"'], ['Cormorant 500 italic', 'italic 500 28px "Cormorant Garamond"'], ['Cormorant 600', '600 28px "Cormorant Garamond"']]) {
            await document.fonts.load(font, sample);
            out[name] = document.fonts.check(font, sample);
        }

        const loaded = [...document.fonts].filter((f) => f.status === 'loaded').map((f) => `${f.family} ${f.style} ${f.weight} ${f.unicodeRange.slice(0, 22)}`);

        // a rendered line in each font: measured against the generic fallback, so a silent fallback shows up as an identical width
        const width = (family, weight, style, text) => { const s = document.createElement('span'); s.textContent = text; s.style.cssText = `position:absolute;visibility:hidden;white-space:nowrap;font:${style} ${weight} 40px ${family}`; document.body.appendChild(s); const w = s.getBoundingClientRect().width; s.remove(); return w; };
        const text = 'Жж Дд Зз Ии Йй Лл Пп Цц Шш Щщ Юю Яя';

        return { out, loaded, differs: {
            montserrat: width('Montserrat, monospace', 400, 'normal', text) !== width('monospace', 400, 'normal', text),
            cormorant: width('"Cormorant Garamond", monospace', 500, 'normal', text) !== width('monospace', 500, 'normal', text),
        } };
    }, SAMPLE);
    for (const [name, ok] of Object.entries(glyphs.out)) {
        check(`document.fonts.check passes for the Bulgarian sample in ${name}`, ok);
    }
    check('the Cyrillic text renders in Montserrat and in Cormorant Garamond, not in the fallback (width differs from monospace)', glyphs.differs.montserrat && glyphs.differs.cormorant, JSON.stringify(glyphs.differs));
    console.error('loaded faces: ' + glyphs.loaded.join(' | '));
    check('a Cyrillic subset file of each family was really fetched', glyphs.loaded.some((l) => l.startsWith('Montserrat') && l.includes('U+301, U+400')) && glyphs.loaded.some((l) => l.startsWith('Cormorant Garamond') && l.includes('U+301, U+400')), glyphs.loaded.join('|'));

    // a LOOK: the sample set in both fonts, on the live page
    await after.page.evaluate((sample) => {
        const box = document.createElement('div');
        box.id = 'fn1-sample';
        box.innerHTML = '<p></p><h2></h2><h2></h2><p></p>';
        const [a, b, c, d] = box.children;
        a.textContent = 'Montserrat 300: ' + sample; a.style.cssText = 'font:300 22px Montserrat';
        b.textContent = 'Cormorant 500: ' + sample; b.style.cssText = 'font:500 34px "Cormorant Garamond"';
        c.textContent = 'Cormorant italic 500: ' + sample; c.style.cssText = 'font:italic 500 34px "Cormorant Garamond"';
        d.textContent = 'Montserrat 600: ' + sample; d.style.cssText = 'font:600 22px Montserrat';
        box.style.cssText = 'padding:16px;background:#fff';
        document.body.prepend(box);
    }, SAMPLE);
    await sleep(600);
    await shot(after.page, 'fn1-cyrillic-sample', after.page.locator('#fn1-sample'));
    await after.page.evaluate(() => document.getElementById('fn1-sample')?.remove());
    await shot(after.page, 'fn1-product-page');
    await after.ctx.close();

    // ---- back to the defaults ------------------------------------------------------------------------------------------------------------------------
    await page.goto(BASE + '/admin/appearance-settings', { waitUntil: 'networkidle' });
    await sleep(900);
    await choose('font_body', 'System');
    await choose('font_heading', 'Като шрифта на текста');
    await save();
    await sleep(1500);
    const back = await visit('back to defaults');
    check('back: no font file, no preload, the body is the system font again', back.fonts.length === 0 && back.used.preload.length === 0 && ! back.used.body.startsWith('Montserrat'), JSON.stringify(back.used));
    await back.ctx.close();
    await admin.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (backedUp) {
        const restored = JSON.parse(php(sub(RESTORE)));
        check('restored: site_settings is byte-for-byte what it was', restored.settings === true, JSON.stringify(restored));
        check('restored: the throw-away admin is gone and the appearance files are what they were', restored.staff === 0 && restored.appearanceFiles === restored.appearanceWas, JSON.stringify(restored));
    }
}

check('no page errors', diag.length === 0, diag.slice(0, 5).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
