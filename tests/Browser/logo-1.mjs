// A REAL-BROWSER check of LOGO-1 (the «Лого» tab: shop name, site icon, logos), at 1280px and 360px. Run by hand against a dev site that has the LOGO-1 code (and a built `public/build`).
//   WHAT IT WRITES to the DEV database / disk: the `site_settings` rows (`site.name`, `site_icon.media_id`, `logo.*`, the compiled payload and whatever the same refresh as a «Дизайн» publish rewrites), TWO
//   throw-away `catalog_media` rows with their PNG files in `uploads/logo1-*`, the generated `icon-*` / `logo-*` files in `appearance/`, and ONE throw-away Administrator.
//   BEFORE the first write the whole `site_settings` table is ARCHIVED (every row, id + checksum) to a file in OUT; at the end it is RESTORED EXACTLY from the archive and the checksum is verified (the NF-1.1
//   rule). The throw-away media rows, their files, the generated files it made and the administrator are deleted. The numbers before and after are printed. Screenshots go to OUT (outside the project).
// NOT part of `php artisan test`.
//
//   CHROMIUM=<chrome.exe> AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> OUT=<folder outside the project> node tests/Browser/logo-1.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node logo-1.mjs'); process.exit(2); }
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) { console.error('refused: OUT is inside the project folder'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

const php = (code) => {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, timeout: 120000 });
    const out = (r.stdout ?? '').trim();
    if (r.status !== 0 || out === '') { throw new Error(`php failed (${r.status}): ` + (out + '\n' + (r.stderr ?? '')).slice(-900)); }
    return out.split('\n').pop();
};

const TAG = `logo1-${Date.now()}`;
const STAFF = `${TAG}-admin@example.test`;
const PASSWORD = 'Logo1-' + Math.random().toString(36).slice(2) + 'Aa1!';
const NAME = 'Лого Тест';
const SNAP_FILE = join(OUT, 'site_settings-archive.json').replace(/\\/g, '/');

const SUMMARY = String.raw`use Illuminate\Support\Facades\DB; $rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(); echo json_encode(['count' => count($rows), 'sum' => md5(json_encode($rows))]);`;
const ARCHIVE = String.raw`use Illuminate\Support\Facades\DB; $rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(); file_put_contents('${SNAP_FILE}', json_encode($rows)); echo json_encode(['count' => count($rows), 'sum' => md5(json_encode($rows))]);`;
const RESTORE = String.raw`use Illuminate\Support\Facades\DB; $rows = json_decode(file_get_contents('${SNAP_FILE}'), true); DB::table('site_settings')->delete(); foreach (array_chunk($rows, 50) as $chunk) { DB::table('site_settings')->insert($chunk); } Illuminate\Support\Facades\Cache::forget(App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY); echo 'restored';`;
const COUNTS = String.raw`use Illuminate\Support\Facades\{DB, Storage}; echo json_encode(['media' => DB::table('catalog_media')->count(), 'staff' => DB::table('staff')->count(), 'generated' => count(array_filter(Storage::disk('public')->files('appearance'), fn ($f) => preg_match(App\Settings\SiteLogo::FILE, basename($f)) === 1)), 'appearanceFiles' => count(Storage::disk('public')->files('appearance'))]);`;
const GENERATED = String.raw`use Illuminate\Support\Facades\Storage; echo json_encode(array_values(array_filter(array_map('basename', Storage::disk('public')->files('appearance')), fn ($f) => preg_match(App\Settings\SiteLogo::FILE, $f) === 1)));`;
const SEED_STAFF = String.raw`use EasyCo\Staff\Staff; use EasyCo\Staff\Contracts\{RoleRepository, StaffRepository, PasswordHasher}; $role = app(RoleRepository::class)->findSystemRoleByName('Administrator'); $staff = Staff::create('${STAFF}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'Audit Admin', $role); app(StaffRepository::class)->save($staff); echo 'seeded';`;
const CLEAN_STAFF = String.raw`Illuminate\Support\Facades\DB::table('staff')->where('email', '${STAFF}')->delete(); echo 'cleaned';`;
/** a real PNG on the public disk and a library row for it: [w, h] -> id */
const MAKE_ASSET = (w, h) => String.raw`use Illuminate\Support\Facades\Storage; $im = imagecreatetruecolor(${w}, ${h}); imagealphablending($im, false); imagesavealpha($im, true); imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); imagefilledellipse($im, intdiv(${w}, 2), intdiv(${h}, 2), intdiv(${w}, 2), intdiv(${h}, 2), imagecolorallocatealpha($im, 200, 30, 30, 0)); ob_start(); imagepng($im); $bytes = ob_get_clean(); $path = 'uploads/${TAG}-${w}x${h}.png'; Storage::disk('public')->put($path, $bytes); $row = EasyCo\Media\Persistence\Eloquent\MediaAssetModel::query()->create(['type' => 'image', 'disk' => 'public', 'path' => $path, 'mime' => 'image/png', 'processing_status' => 'ready', 'variants' => [], 'original_name' => '${TAG}.png', 'size_bytes' => strlen($bytes), 'width' => ${w}, 'height' => ${h}]); echo $row->id;`;
const DEL_ASSETS = String.raw`use Illuminate\Support\Facades\{DB, Storage}; foreach (DB::table('catalog_media')->where('original_name', '${TAG}.png')->get() as $row) { Storage::disk('public')->delete($row->path); } DB::table('catalog_media')->where('original_name', '${TAG}.png')->delete(); echo 'deleted';`;
const MAIL_LOGO_URL = String.raw`$m = app(App\Mail\TemplateRenderer::class)->render(app(App\Mail\MailTemplates::class)->get('account.verify'), 'bg', ['shop_name' => 'x'], ['action_link' => '<p>link</p>']); echo preg_match('#<img src="([^"]+)" alt="([^"]*)" width="(\d+)" height="(\d+)"#', $m->html, $x) ? json_encode(['url' => $x[1], 'alt' => $x[2], 'w' => (int) $x[3], 'h' => (int) $x[4]]) : 'none';`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'package.json'))('playwright');
const report = { before: JSON.parse(php(SUMMARY)), countsBefore: JSON.parse(php(COUNTS)), generatedBefore: JSON.parse(php(GENERATED)), checks: [], failed: 0 };
const check = (name, ok, detail = '') => { report.checks.push({ name, ok, detail }); ok || report.failed++; };
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

let browser = null;
let archived = false;

try {
    report.archive = JSON.parse(php(ARCHIVE));
    archived = true;
    php(SEED_STAFF);
    const square = Number(php(MAKE_ASSET(640, 640)));
    const notSquare = Number(php(MAKE_ASSET(640, 480)));
    const wide = Number(php(MAKE_ASSET(800, 200)));
    const url = String.raw`echo App\Filament\Pages\Settings\LocaleSettings::getUrl();`;
    const TAB_URL = php(url);

    browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, headless: true });

    // ---- the tab, at 1280 and 360 ----------------------------------------------------------------------------------------------------
    for (const [label, width, height] of [['wide', 1280, 1000], ['phone', 360, 800]]) {
        const context = await browser.newContext({ viewport: { width, height }, ignoreHTTPSErrors: true });
        const page = await context.newPage();
        await page.goto(BASE + '/admin/login');
        await page.fill('input[type=email]', STAFF);
        await page.fill('input[type=password]', PASSWORD);
        await page.click('button[type=submit]');
        await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
        await page.goto(TAB_URL, { waitUntil: 'networkidle' });
        await page.getByRole('tab', { name: 'Лого' }).click();
        await wait(700);

        const overflow = () => page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
        const info = await page.evaluate(() => {
            const panel = [...document.querySelectorAll('[role=tabpanel]')].find((p) => p.offsetParent !== null);
            const labels = panel ? [...panel.querySelectorAll('.fi-fo-field-label-content, .fi-fo-field-wrp label')].map((l) => l.textContent.trim()).filter(Boolean) : [];

            return { labels, helper: panel ? panel.querySelectorAll('.fi-fo-field-wrp-hint, .fi-fo-field-wrp-helper-text').length : -1, hints: panel ? panel.querySelectorAll('.info-hint').length : -1, preview: !!(panel && panel.querySelector('.lg-preview')), inputs: panel ? panel.querySelectorAll('input[type=text]').length : -1 };
        });
        check(`tab ${label}: no horizontal scroll`, ! (await overflow()), JSON.stringify(info));
        check(`tab ${label}: the shop name is the FIRST field, then the three pictures`, info.labels[0] === 'Име на магазина' && info.labels.some((t) => t === 'Иконка на сайта') && info.labels.some((t) => t === 'Лого') && info.labels.some((t) => t.startsWith('Тъмно лого')), JSON.stringify(info.labels));
        check(`tab ${label}: info-icon hints (4) and no helper paragraphs under fields`, info.hints === 4 && info.helper === 0, JSON.stringify(info));
        check(`tab ${label}: the static preview and the link to «Дизайн» are there`, info.preview && await page.locator('.lg-header-link a').isVisible());
        await page.screenshot({ path: join(OUT, `tab-${label}.png`), fullPage: true });

        // an error blocks the save and is shown ON the «Лого» tab (the page was left on another tab first)
        if (label === 'wide') {
            await page.getByRole('tab', { name: 'Настройки' }).first().click().catch(() => {});
            await page.evaluate(async ({ id }) => {
                const form = document.querySelector('form#form') ?? document.querySelector('form');
                const component = window.Livewire.find(form.closest('[wire\\:id]').getAttribute('wire:id'));
                await component.$set('data.site_icon', id);
            }, { id: notSquare });
            await wait(800);
            await page.getByRole('tab').first().click();
            await page.getByRole('button', { name: 'Запази' }).click();
            await wait(1500);
            const active = await page.evaluate(() => document.querySelector('[role=tab][aria-selected=true]')?.textContent.trim());
            check('error: the save switched to the «Лого» tab', active === 'Лого', String(active));
            check('error: the plain sentence with the numbers is shown on that tab', await page.evaluate(() => document.body.innerText.includes('640×480') && document.body.innerText.includes('512×512')));
            await page.screenshot({ path: join(OUT, 'tab-error-wide.png'), fullPage: false });
        }

        await context.close();
    }

    // ---- a real save (the page's own save button) -----------------------------------------------------------------------------------------
    {
        const context = await browser.newContext({ viewport: { width: 1280, height: 1000 }, ignoreHTTPSErrors: true });
        const page = await context.newPage();
        await page.goto(BASE + '/admin/login');
        await page.fill('input[type=email]', STAFF);
        await page.fill('input[type=password]', PASSWORD);
        await page.click('button[type=submit]');
        await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
        await page.goto(TAB_URL, { waitUntil: 'networkidle' });
        await page.getByRole('tab', { name: 'Лого' }).click();
        await page.locator('input[wire\\:model\\.live\\.blur="data.site_name"]').fill(NAME);
        await page.evaluate(async ({ icon, logo }) => {
            const form = document.querySelector('form#form') ?? document.querySelector('form');
            const component = window.Livewire.find(form.closest('[wire\\:id]').getAttribute('wire:id'));
            await component.$set('data.site_icon', icon);
            await component.$set('data.logo_main', logo);
        }, { icon: square, logo: wide });
        await wait(800);
        await page.getByRole('button', { name: 'Запази' }).click();
        await wait(2500);
        const generated = JSON.parse(php(GENERATED)).filter((f) => ! report.generatedBefore.includes(f));
        check('save: the icon files (32, 180, .ico) and the logo were generated', generated.length === 4 && generated.some((f) => f.endsWith('.ico')) && generated.some((f) => /-32\.png$/.test(f)) && generated.some((f) => /-180\.png$/.test(f)), JSON.stringify(generated));
        await page.screenshot({ path: join(OUT, 'tab-saved-wide.png'), fullPage: true });
        report.generated = generated;

        // the admin itself: the tab title has the name, the sidebar and the tab icon changed
        await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
        const admin = await page.evaluate(() => ({ title: document.title, icon: document.querySelector('link[rel=icon]')?.getAttribute('href'), logos: [...document.querySelectorAll('.fi-logo, img')].map((i) => i.getAttribute('src') ?? '').filter((s) => /logo-[0-9a-f]{12}/.test(s)) }));
        check('admin: the browser tab shows the shop name', admin.title.includes(NAME), admin.title);
        check('admin: the tab icon is the generated PNG 32', /icon-[0-9a-f]{12}-32\.png/.test(admin.icon ?? ''), String(admin.icon));
        check('admin: the sidebar shows the generated logo', admin.logos.length >= 1, JSON.stringify(admin.logos));
        await page.screenshot({ path: join(OUT, 'admin-dashboard-wide.png'), fullPage: false });
        await context.close();
    }

    // ---- the login page (before auth) and the storefront --------------------------------------------------------------------------------
    {
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, ignoreHTTPSErrors: true });
        const page = await context.newPage();
        await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
        const login = await page.evaluate(() => ({ title: document.title, icon: document.querySelector('link[rel=icon]')?.getAttribute('href') ?? '', imgs: [...document.querySelectorAll('img')].map((i) => i.getAttribute('src') ?? '') }));
        check('login page: shows the logo, the icon and the name', login.imgs.some((s) => /logo-[0-9a-f]{12}/.test(s)) && /icon-[0-9a-f]{12}-32\.png/.test(login.icon) && login.title.includes(NAME), JSON.stringify(login));
        await page.screenshot({ path: join(OUT, 'admin-login.png') });

        const home = await context.request.get(BASE + '/');
        const html = await home.text();
        check('storefront head: PNG 32, /favicon.ico sizes=any and the apple touch icon', /<link rel="icon" type="image\/png" sizes="32x32" href="\/storage\/appearance\/icon-[0-9a-f]{12}-32\.png">/.test(html) && html.includes('<link rel="icon" href="/favicon.ico" sizes="any">') && /<link rel="apple-touch-icon" href="\/storage\/appearance\/icon-[0-9a-f]{12}-180\.png">/.test(html));
        check('storefront: the home title is the shop name and the logo alt follows it', new RegExp(`<title>${NAME}</title>`).test(html) && html.includes(`alt="${NAME}"`));
        check('storefront: the header logo is still the static one («Дизайн» owns it)', /<header[\s\S]*?images\/logo\.svg[\s\S]*?<\/header>/.test(html) && ! /<header[\s\S]*?\/storage\/appearance\/logo-[\s\S]*?<\/header>/.test(html));

        const search = await context.request.get(BASE + '/cart');
        check('storefront: another page type (the cart) has the name in its title', new RegExp(`<title>[^<]*— ${NAME}</title>`).test(await search.text()));

        const fav = await context.request.get(BASE + '/favicon.ico');
        const headers = fav.headersArray().map((h) => h.name.toLowerCase());
        check('/favicon.ico: 200 image/x-icon', fav.status() === 200 && (fav.headers()['content-type'] ?? '').startsWith('image/x-icon'), `${fav.status()} ${fav.headers()['content-type']}`);
        check('/favicon.ico: no Set-Cookie, the contract cache headers', ! headers.includes('set-cookie') && /public/.test(fav.headers()['cache-control'] ?? '') && /s-maxage=604800/.test(fav.headers()['cache-control'] ?? ''), JSON.stringify(fav.headers()));
        const ico = await fav.body();
        check('/favicon.ico: a real ICO (3 PNG entries)', ico.readUInt16LE(0) === 0 && ico.readUInt16LE(2) === 1 && ico.readUInt16LE(4) === 3 && ico.subarray(ico.readUInt32LE(6 + 12), ico.readUInt32LE(6 + 12) + 8).toString('hex') === '89504e470d0a1a0a');

        const touch = html.match(/rel="apple-touch-icon" href="([^"]+)"/)?.[1] ?? '';
        const touchResponse = await context.request.get(BASE + touch);
        check('apple-touch-icon: 200 image/png', touchResponse.status() === 200 && (touchResponse.headers()['content-type'] ?? '').startsWith('image/png'), `${touchResponse.status()} ${touchResponse.headers()['content-type']}`);
        await context.close();
    }

    // ---- a mail rendered by TemplateRenderer ---------------------------------------------------------------------------------------------
    {
        const mail = php(MAIL_LOGO_URL);
        check('mail: TemplateRenderer printed the logo header', mail !== 'none', mail);

        if (mail !== 'none') {
            const info = JSON.parse(mail);
            const context = await browser.newContext({ ignoreHTTPSErrors: true });
            const response = await context.request.get(info.url);
            check('mail: the logo URL is absolute, answers 200 image/png and is shown at most 200 px wide', /^https?:\/\//.test(info.url) && response.status() === 200 && (response.headers()['content-type'] ?? '').startsWith('image/png') && info.w <= 200 && info.alt === NAME, JSON.stringify({ ...info, status: response.status() }));
            await context.close();
        }
    }
} finally {
    if (browser) { await browser.close(); }

    try { php(CLEAN_STAFF); } catch (e) { report.cleanError = String(e); }

    if (archived) {
        try {
            php(RESTORE);
            report.after = JSON.parse(php(SUMMARY));
            report.restoredExactly = report.after.count === report.archive.count && report.after.sum === report.archive.sum;
        } catch (e) { report.restoreError = String(e); }
    }

    try {
        php(DEL_ASSETS);
        // the generated files this run made (none existed before them): the restored settings no longer name them
        const now = JSON.parse(php(GENERATED));
        const mine = now.filter((f) => ! report.generatedBefore.includes(f));
        mine.length > 0 && php(String.raw`use Illuminate\Support\Facades\Storage; foreach (json_decode('${JSON.stringify(mine)}', true) as $f) { if (preg_match(App\Settings\SiteLogo::FILE, $f) === 1) { Storage::disk('public')->delete('appearance/' . $f); } } echo 'removed';`);
        report.removedFiles = mine.length;
    } catch (e) { report.fileCleanError = String(e); }

    report.countsAfter = JSON.parse(php(COUNTS));
}

console.log(JSON.stringify({ before: report.before, archive: report.archive, after: report.after, restoredExactly: report.restoredExactly, countsBefore: report.countsBefore, countsAfter: report.countsAfter, removedGeneratedFiles: report.removedFiles, failed: report.failed, checks: report.checks.map((c) => `${c.ok ? 'PASS' : 'FAIL'}  ${c.name}${c.ok ? '' : '  ' + c.detail}`), cleanError: report.cleanError, restoreError: report.restoreError, fileCleanError: report.fileCleanError }, null, 1));
process.exit(report.failed === 0 && report.restoredExactly === true && JSON.stringify(report.countsBefore) === JSON.stringify(report.countsAfter) ? 0 : 1);
